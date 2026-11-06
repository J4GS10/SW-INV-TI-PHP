<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Catalogo\ClasesActivo;
use App\Db\InsercionOracle;
use App\Dominio\Reloj;
use App\Http\ErrorApi;
use App\Repositorio\GobiernoRepositorio;
use Doctrine\DBAL\Connection;

/**
 * Reclasificación de insumos registrados como activos (aire comprimido, pasta
 * térmica…) a un consumible controlado por cantidad, por tipo y sede y con
 * aprobación. Al aprobarse, cada activo queda de baja (RECLASIFICACION →
 * CONSUMIBLES) y el consumible recibe una entrada por la misma cantidad,
 * valorizada al costo promedio si todos tienen costo en la misma moneda.
 */
final readonly class Reclasificacion
{
    public function __construct(
        private Connection $conexion,
        private Kardex $kardex,
        private GobiernoRepositorio $gobierno,
        private EstadosActivo $estados,
        private Consumibles $consumibles,
    ) {
    }

    /** @return list<array<string, mixed>> activos no dados de baja del tipo (y sede) */
    private function activos(int $tipo, ?int $sede): array
    {
        $p = ['t' => $tipo, 'baja' => $this->estados->idDe(EstadosActivo::BAJA) ?? -1];
        $sql = 'SELECT '.Activos::select().' FROM "INV_ACTIVO" a WHERE a."TAC_Tipo_Activo" = :t AND a."EOP_Estado_Operativo" <> :baja';
        if (null !== $sede) {
            $sql .= ' AND a."SED_Sede" = :s';
            $p['s'] = $sede;
        }

        return $this->conexion->fetchAllAssociative($sql.' ORDER BY a."ACT_Codigo_Interno"', $p);
    }

    /** @param array<string, mixed> $a */
    private function bloqueo(array $a): ?string
    {
        return match (true) {
            null !== $a['ACT_Activo_Padre'] => 'INSTALADO_EN_EQUIPO',
            1 === (int) $a['ACT_Instalado'] => 'INSTALADO_EN_AREA',
            false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_MOVIMIENTO" WHERE "ACT_Activo" = :a AND "MOV_Fecha_Devolucion" IS NULL FETCH FIRST 1 ROWS ONLY',
                ['a' => $a['ACT_Activo']]) => 'ASIGNADO',
            false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_MANTENIMIENTO" WHERE "ACT_Activo" = :a AND "MAN_Fecha_Cierre" IS NULL FETCH FIRST 1 ROWS ONLY',
                ['a' => $a['ACT_Activo']]) => 'EN_MANTENIMIENTO',
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
        $t = $this->conexion->fetchAssociative('SELECT "TAC_Nombre", "TAC_Clase" FROM "INV_TIPO_ACTIVO" WHERE "TAC_Tipo_Activo" = :t', ['t' => $tipo])
            ?: throw new ErrorApi(404, 'ASSET_TYPE_NOT_FOUND');
        if (ClasesActivo::INSUMO !== ClasesActivo::reglas($t['TAC_Clase'])['uso']) {
            throw new ErrorApi(409, 'ASSET_TYPE_IS_NOT_SUPPLY');
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
        $nombres = $this->conexion->fetchAllKeyValue('SELECT "SED_Sede", "SED_Nombre" FROM "INV_SEDE"');

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
        $tipo = (string) $this->conexion->fetchOne('SELECT "TAC_Nombre" FROM "INV_TIPO_ACTIVO" WHERE "TAC_Tipo_Activo" = :t', ['t' => $tipoId]);
        $activos = $this->activos($tipoId, $sede);
        if ([] === $activos) {
            throw new ErrorApi(409, 'RECLASSIFY_NOTHING_TO_CONVERT');
        }
        foreach ($activos as $a) {
            if (null !== $this->bloqueo($a)) {
                throw new ErrorApi(409, 'RECLASSIFY_ASSETS_IN_USE');
            }
        }
        // Consumible destino: el que ya existe con ese nombre en la sede, o uno nuevo.
        $nombre = trim((string) $payload['nombre']);
        $con = $this->conexion->fetchAssociative('SELECT "CON_Consumible", "SED_Sede", "CON_Moneda" FROM "INV_CONSUMIBLE" WHERE LOWER("CON_Nombre") = :n '
            .'FETCH FIRST 1 ROWS ONLY', ['n' => mb_strtolower($nombre)]);
        if (false !== $con && (int) $con['SED_Sede'] !== $sede) {
            throw new ErrorApi(409, 'CONSUMABLE_NAME_IN_OTHER_SEDE');
        }
        if (false === $con) {
            $id = (int) InsercionOracle::insertar($this->conexion, 'INV_CONSUMIBLE', ['CON_Nombre' => $nombre,
                'CON_Unidad' => ($payload['CON_Unidad'] ?? null) ?: 'unidad', 'CON_Stock_Actual' => 0, 'CON_Stock_Minimo' => ($payload['CON_Stock_Minimo'] ?? null) ?: 0,
                'CCO_Categoria' => $payload['CCO_Categoria'] ?? null, 'UME_Unidad' => $payload['UME_Unidad'] ?? null, 'SED_Sede' => $sede, 'CON_Activo' => 1], 'CON_Consumible');
            $this->gobierno->registrarAuditoria('CREATE', 'INV_CONSUMIBLE', ['id' => $id, 'nombre' => $nombre, 'origen' => 'RECLASIFICACION'], $usuario, $ip, sedeId: $sede);
            $con = ['CON_Consumible' => $id, 'SED_Sede' => $sede, 'CON_Moneda' => null];
        }
        // Valor de la entrada: costo promedio si todos tienen costo en la misma moneda.
        $costos = array_map(static fn (array $a): ?string => null === $a['ACT_Costo'] ? null : (string) $a['ACT_Costo'], $activos);
        $monedas = array_unique(array_map(static fn (array $a): string => (string) $a['ACT_Moneda'],
            array_filter($activos, static fn (array $a): bool => null !== $a['ACT_Costo'])));
        [$precio, $moneda] = [null, null];
        if (!\in_array(null, $costos, true) && 1 === \count($monedas)) {
            $precio = Valoracion::promedio(array_values(array_filter($costos, static fn (?string $c): bool => null !== $c)));
            $moneda = array_values($monedas)[0];
        }
        if (null !== $con['CON_Moneda'] && '' !== $con['CON_Moneda'] && null !== $moneda && $moneda !== $con['CON_Moneda']) {
            [$precio, $moneda] = [null, null];  // otra moneda: entra sin valorizar (no se convierte)
        }
        $baja = $this->estados->idObligatorio(EstadosActivo::BAJA);
        $traza = array_map(static fn (mixed $v): string => (string) $v, $aprobacion);
        foreach ($activos as $a) {
            $this->conexion->executeStatement("UPDATE \"INV_ACTIVO\" SET \"EOP_Estado_Operativo\" = :b, \"ACT_Baja_Motivo\" = 'RECLASIFICACION', "
                ."\"ACT_Baja_Destino\" = 'CONSUMIBLES', \"ACT_Baja_Fecha\" = TO_DATE(:f, 'YYYY-MM-DD'), \"ACT_Baja_Documento\" = :d, \"ACT_Baja_Observacion\" = :o "
                .'WHERE "ACT_Activo" = :id', ['b' => $baja, 'f' => Reloj::ahora()->format('Y-m-d'), 'd' => "APR-{$aprobacion['aprobacion']}",
                    'o' => "Pasa al consumible «{$nombre}»", 'id' => $a['ACT_Activo']]);
            $this->kardex->registrar('RECLASIFICACION', activo: $a, usuario: $usuario,
                origen: ['sede' => null === $a['SED_Sede'] ? null : (int) $a['SED_Sede'], 'area' => null === $a['ARE_Area_Ubicacion'] ? null : (int) $a['ARE_Area_Ubicacion']],
                costo: $a['ACT_Costo'], moneda: $a['ACT_Moneda'], documento: ['APROBACION', (string) $aprobacion['aprobacion']],
                motivo: "Reclasificado como consumible «{$nombre}»: ".($payload['motivo'] ?? ''),
                detalle: ['consumible' => (int) $con['CON_Consumible'], 'aprobacion' => $traza]);
        }
        $this->consumibles->mover((int) $con['CON_Consumible'], ['MOC_Cantidad' => \count($activos), 'MOC_Motivo' => 'Reclasificación de '.\count($activos)." activos «{$tipo}»",
            'PER_Persona' => null, 'ACT_Activo' => null, 'precio_unitario' => $precio, 'moneda' => $moneda], 'ENTRADA', $usuario, $ip);
        $this->gobierno->registrarAuditoria('RECLASSIFY_ASSETS', 'INV_ACTIVO', ['tipo' => $tipo, 'consumible' => $nombre, 'cantidad' => \count($activos),
            'codigos' => \array_slice(array_column($activos, 'ACT_Codigo_Interno'), 0, 200), 'aprobacion' => $traza], $usuario, $ip, sedeId: $sede);

        return ['consumible' => (int) $con['CON_Consumible'], 'cantidad' => \count($activos)];
    }
}
