<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Catalogo\ClasesActivo;
use App\Dominio\ErrorDominio;
use App\Dominio\Reloj;
use App\Inventario\Puertos\ReclasificacionDatos;
use App\Repositorio\GobiernoRepositorio;

/**
 * Reclasificación de insumos registrados como activos (aire comprimido, pasta
 * térmica…) a un consumible controlado por cantidad, por tipo y sede y con
 * aprobación. Al aprobarse, cada activo queda de baja (RECLASIFICACION →
 * CONSUMIBLES) y el consumible recibe una entrada por la misma cantidad,
 * valorizada al costo promedio si todos tienen costo en la misma moneda.
 *
 * Solo reglas: los datos llegan por el puerto ReclasificacionDatos.
 */
final readonly class Reclasificacion
{
    public function __construct(
        private ReclasificacionDatos $datos,
        private Kardex $kardex,
        private GobiernoRepositorio $gobierno,
        private EstadosActivo $estados,
        private Consumibles $consumibles,
    ) {
    }

    /** @return list<array<string, mixed>> activos no dados de baja del tipo (y sede) */
    private function activos(int $tipo, ?int $sede): array
    {
        return $this->datos->activosVigentes($tipo, $sede, $this->estados->idDe(EstadosActivo::BAJA) ?? -1);
    }

    /**
     * Por qué un activo no puede convertirse todavía, o null si puede.
     *
     * @param array<string, mixed> $a
     */
    private function bloqueo(array $a): ?string
    {
        return match (true) {
            null !== $a['ACT_Activo_Padre'] => 'INSTALADO_EN_EQUIPO',
            1 === (int) $a['ACT_Instalado'] => 'INSTALADO_EN_AREA',
            $this->datos->tieneCustodia((string) $a['ACT_Activo']) => 'ASIGNADO',
            $this->datos->tieneMantenimientoAbierto((string) $a['ACT_Activo']) => 'EN_MANTENIMIENTO',
            default => null,
        };
    }

    /**
     * Cuántos activos se convierten y cuáles lo impiden.
     *
     * @return array<string, mixed>
     */
    public function vistaPrevia(int $tipo, ?int $sede): array
    {
        $t = $this->datos->tipo($tipo) ?? throw ErrorDominio::noEncontrado('ASSET_TYPE_NOT_FOUND');
        if (ClasesActivo::INSUMO !== ClasesActivo::reglas($t['TAC_Clase'])['uso']) {
            throw ErrorDominio::conflicto('ASSET_TYPE_IS_NOT_SUPPLY');
        }
        $activos = $this->activos($tipo, $sede);
        $bloqueados = [];
        $porSede = [];
        foreach ($activos as $a) {
            $motivo = $this->bloqueo($a);
            if (null !== $motivo) {
                $bloqueados[] = ['codigo' => $a['ACT_Codigo_Interno'], 'motivo' => $motivo];
            }
            $clave = null === $a['SED_Sede'] ? 's' : 's'.(int) $a['SED_Sede'];
            $porSede[$clave] = ($porSede[$clave] ?? 0) + 1;
        }
        $nombres = $this->datos->nombresDeSedes();

        return ['tipo' => $t['TAC_Nombre'], 'activos' => \count($activos), 'bloqueados' => $bloqueados,
            'por_sede' => array_map(static fn (string $s, int $n): array => ['SED_Sede' => 's' === $s ? null : (int) substr($s, 1),
                'sede' => 's' === $s ? null : ($nombres[substr($s, 1)] ?? null), 'activos' => $n], array_keys($porSede), array_values($porSede))];
    }

    /**
     * Ejecuta una reclasificación APROBADA.
     *
     * @param array<string, mixed> $payload    tipo_id, SED_Sede, nombre, CON_Unidad, UME_Unidad, CCO_Categoria, CON_Stock_Minimo, motivo
     * @param array<string, mixed> $aprobacion
     *
     * @return array{consumible: int, cantidad: int}
     */
    public function ejecutar(array $payload, string $usuario, ?string $ip, array $aprobacion): array
    {
        $tipoId = (int) $payload['tipo_id'];
        $sede = (int) $payload['SED_Sede'];
        $tipo = $this->datos->tipo($tipoId)['TAC_Nombre'] ?? '';
        $nombre = trim((string) $payload['nombre']);

        $activos = $this->activosListos($tipoId, $sede);
        $consumible = $this->consumibleDestino($nombre, $sede, $payload, $usuario, $ip);
        [$precio, $moneda] = self::valorDeEntrada($activos, $consumible['CON_Moneda']);

        $baja = $this->estados->idObligatorio(EstadosActivo::BAJA);
        $traza = array_map(static fn (mixed $v): string => (string) $v, $aprobacion);
        foreach ($activos as $a) {
            $this->datos->darDeBaja((string) $a['ACT_Activo'], $baja, Reloj::ahora(), "APR-{$aprobacion['aprobacion']}", "Pasa al consumible «{$nombre}»");
            $this->kardex->registrar('RECLASIFICACION', activo: $a, usuario: $usuario,
                origen: ['sede' => null === $a['SED_Sede'] ? null : (int) $a['SED_Sede'], 'area' => null === $a['ARE_Area_Ubicacion'] ? null : (int) $a['ARE_Area_Ubicacion']],
                costo: $a['ACT_Costo'], moneda: $a['ACT_Moneda'], documento: ['APROBACION', (string) $aprobacion['aprobacion']],
                motivo: "Reclasificado como consumible «{$nombre}»: ".($payload['motivo'] ?? ''),
                detalle: ['consumible' => $consumible['CON_Consumible'], 'aprobacion' => $traza]);
        }
        $this->consumibles->mover($consumible['CON_Consumible'], ['MOC_Cantidad' => \count($activos), 'MOC_Motivo' => 'Reclasificación de '.\count($activos)." activos «{$tipo}»",
            'PER_Persona' => null, 'ACT_Activo' => null, 'precio_unitario' => $precio, 'moneda' => $moneda], 'ENTRADA', $usuario, $ip);
        $this->gobierno->registrarAuditoria('RECLASSIFY_ASSETS', 'INV_ACTIVO', ['tipo' => $tipo, 'consumible' => $nombre, 'cantidad' => \count($activos),
            'codigos' => \array_slice(array_column($activos, 'ACT_Codigo_Interno'), 0, 200), 'aprobacion' => $traza], $usuario, $ip, sedeId: $sede);

        return ['consumible' => $consumible['CON_Consumible'], 'cantidad' => \count($activos)];
    }

    /**
     * Los activos a convertir, bloqueados y releídos: mientras se aprueba, nadie los entrega, instala ni repara.
     *
     * @return non-empty-list<array<string, mixed>>
     */
    private function activosListos(int $tipo, int $sede): array
    {
        $this->datos->bloquear(array_map(static fn (array $a): string => (string) $a['ACT_Activo'], $this->activos($tipo, $sede)));
        $activos = $this->activos($tipo, $sede);
        if ([] === $activos) {
            throw ErrorDominio::conflicto('RECLASSIFY_NOTHING_TO_CONVERT');
        }
        foreach ($activos as $a) {
            if (null !== $this->bloqueo($a)) {
                throw ErrorDominio::conflicto('RECLASSIFY_ASSETS_IN_USE');
            }
        }

        return $activos;
    }

    /**
     * El consumible que recibe la entrada: el que ya existe con ese nombre en la sede, o uno nuevo.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{CON_Consumible: int, SED_Sede: int|null, CON_Moneda: string|null}
     */
    private function consumibleDestino(string $nombre, int $sede, array $payload, string $usuario, ?string $ip): array
    {
        $existente = $this->datos->consumiblePorNombre($nombre);
        if (null !== $existente) {
            return ($existente['SED_Sede'] ?? 0) === $sede ? $existente : throw ErrorDominio::conflicto('CONSUMABLE_NAME_IN_OTHER_SEDE');
        }
        $id = $this->datos->crearConsumible(['CON_Nombre' => $nombre,
            'CON_Unidad' => ($payload['CON_Unidad'] ?? null) ?: 'unidad', 'CON_Stock_Actual' => 0, 'CON_Stock_Minimo' => ($payload['CON_Stock_Minimo'] ?? null) ?: 0,
            'CCO_Categoria' => $payload['CCO_Categoria'] ?? null, 'UME_Unidad' => $payload['UME_Unidad'] ?? null, 'SED_Sede' => $sede, 'CON_Activo' => 1]);
        $this->gobierno->registrarAuditoria('CREATE', 'INV_CONSUMIBLE', ['id' => $id, 'nombre' => $nombre, 'origen' => 'RECLASIFICACION'], $usuario, $ip, sedeId: $sede);

        return ['CON_Consumible' => $id, 'SED_Sede' => $sede, 'CON_Moneda' => null];
    }

    /**
     * Regla pura. La entrada se valoriza al costo promedio solo si TODOS los activos tienen costo y en la misma
     * moneda, y esa moneda es la del consumible (si ya tiene una): no se convierte entre monedas.
     *
     * @param list<array<string, mixed>> $activos con ACT_Costo y ACT_Moneda
     *
     * @return array{0: string|null, 1: string|null} precio unitario y moneda, o [null, null] si entra sin valorizar
     */
    public static function valorDeEntrada(array $activos, ?string $monedaDelConsumible): array
    {
        $costos = [];
        $monedas = [];
        foreach ($activos as $a) {
            if (null === $a['ACT_Costo']) {
                return [null, null];
            }
            $costos[] = (string) $a['ACT_Costo'];
            $monedas[(string) $a['ACT_Moneda']] = true;
        }
        if ([] === $costos || 1 !== \count($monedas)) {
            return [null, null];
        }
        $moneda = (string) array_key_first($monedas);
        if (null !== $monedaDelConsumible && '' !== $monedaDelConsumible && $moneda !== $monedaDelConsumible) {
            return [null, null];
        }

        return [Valoracion::promedio($costos), $moneda];
    }
}
