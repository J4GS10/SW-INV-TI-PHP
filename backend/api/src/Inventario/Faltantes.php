<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Inventario\Infrastructure\Kardex;
use App\Db\Transaccion;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\ErrorDominio;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\Alcance;
use App\Inventario\Infrastructure\FaltantesStore;

/**
 * Faltantes de la toma de inventario: lo que no apareció se investiga y se resuelve.
 *
 *   PENDIENTE ──nota──▶ EN_INVESTIGACION
 *       ├──localizado───────▶ LOCALIZADO (también si se lee en otra toma)
 *       └──baja por pérdida─▶ BAJA_SOLICITADA ──aprobada──▶ DADO_DE_BAJA
 *                                       └──rechazada/anulada──▶ EN_INVESTIGACION
 */
final readonly class Faltantes
{
    public const array ABIERTOS = ['PENDIENTE', 'EN_INVESTIGACION', 'BAJA_SOLICITADA'];

    public function __construct(
        private FaltantesStore $datos,
        private Kardex $kardex,
        private GobiernoRepositorio $gobierno,
        private Solicitudes $solicitudes,
        private Transaccion $transaccion,
    ) {
    }

    /**
     * Al cerrar una toma: un faltante abierto por equipo (no se duplica).
     *
     * @param list<string> $activos hex
     */
    public function registrarDeToma(int $toma, ?int $sede, array $activos): int
    {
        // En bloques de 1000 (límite de IN en Oracle) en lugar de una fila por viaje: una toma de 20 000
        // activos hacía 20 000 viajes dentro de la transacción del cierre (fase 8, F-11).
        $nuevos = 0;
        $ahora = Reloj::aBd(Reloj::ahora());
        foreach (array_chunk(array_values(array_unique($activos)), 1000) as $bloque) {
            $nuevos += $this->datos->registrar($toma, $sede, $ahora, $bloque);
        }

        return $nuevos;
    }

    /** Un faltante que aparece en otra toma queda localizado. */
    public function resolverPorLectura(string $activo, int $toma, ?string $usuario): void
    {
        foreach ($this->datos->abiertosDeActivo($activo) as $f) {
            $texto = "Leído en la toma {$toma}";
            $this->datos->marcarLocalizado((int) $f['FAL_Faltante'], $texto, Reloj::ahora());
            $this->nota((int) $f['FAL_Faltante'], $f['FAL_Seguimiento'], $usuario, $texto, 'LOCALIZADO');
        }
    }

    /** Agrega una entrada al seguimiento (quién, cuándo, qué). */
    private function nota(int $id, ?string $seguimiento, ?string $usuario, string $texto, string $accion): void
    {
        $nombre = null === $usuario ? false : $this->datos->nombreDeUsuario($usuario);
        $lista = null === $seguimiento ? [] : (json_decode($seguimiento, true) ?: []);
        $lista[] = ['fecha' => Reloj::ahora()->format('Y-m-d\TH:i:s'), 'usuario' => false === $nombre ? null : $nombre, 'accion' => $accion, 'nota' => $texto];
        $this->datos->guardarSeguimiento($id, $lista);
    }

    /** @return array<string, mixed> */
    private function fila(int $id): array
    {
        $f = $this->datos->porId($id);

        return false === $f ? throw ErrorDominio::noEncontrado('MISSING_ITEM_NOT_FOUND') : $f;
    }

    /** @return list<array<string, mixed>> */
    public function listar(?string $estado = null, bool $abiertos = false, ?int $id = null): array
    {
        $filas = $this->datos->listar($estado, $abiertos, $id);

        return array_map(static fn (array $f): array => ['FAL_Faltante' => (int) $f['FAL_Faltante'], 'TOM_Toma' => null === $f['TOM_Toma'] ? null : (int) $f['TOM_Toma'],
            'ACT_Activo' => Ident::aTexto((string) $f['ACT_Activo']), 'codigo' => $f['ACT_Codigo_Interno'], 'serie' => $f['ACT_Serie_Fabricante'],
            'tipo' => $f['TAC_Nombre'], 'modelo' => $f['MOD_Nombre'], 'area' => $f['ARE_Nombre'], 'FAL_Estado' => $f['FAL_Estado'],
            'FAL_Fecha' => Reloj::iso(Reloj::desdeBd((string) $f['FAL_Fecha'])),
            'FAL_Seguimiento' => null === $f['FAL_Seguimiento'] ? [] : (json_decode((string) $f['FAL_Seguimiento'], true) ?: []),
            'FAL_Resolucion' => $f['FAL_Resolucion'], 'FAL_Fecha_Resolucion' => Reloj::iso(Reloj::desdeBd($f['FAL_Fecha_Resolucion'])),
            'APR_Aprobacion' => null === $f['APR_Aprobacion'] ? null : (int) $f['APR_Aprobacion'], 'SED_Sede' => null === $f['SED_Sede'] ? null : (int) $f['SED_Sede']], $filas);
    }

    /** @return array<string, mixed> */
    public function uno(int $id): array
    {
        return $this->listar(id: $id)[0] ?? throw ErrorDominio::noEncontrado('MISSING_ITEM_NOT_FOUND');
    }

    /** @param array<string, mixed> $f */
    private static function exigirAbierto(array $f): void
    {
        if (!\in_array($f['FAL_Estado'], ['PENDIENTE', 'EN_INVESTIGACION'], true)) {
            throw ErrorDominio::conflicto('MISSING_ITEM_ALREADY_RESOLVED');
        }
    }

    /** @return array<string, mixed> */
    public function seguimiento(int $id, string $nota, string $usuario, ?string $ip): array
    {
        $this->transaccion->ejecutar(function () use ($id, $nota, $usuario, $ip): void {
            $f = $this->fila($id);
            self::exigirAbierto($f);
            $this->datos->marcarEnInvestigacion($id);
            $this->nota($id, $f['FAL_Seguimiento'], $usuario, $nota, 'NOTA');
            $this->gobierno->registrarAuditoria('MISSING_ITEM_NOTE', 'INV_FALTANTE', ['id' => $id, 'nota' => $nota], $usuario, $ip,
                sedeId: null === $f['SED_Sede'] ? null : (int) $f['SED_Sede']);
        });

        return $this->uno($id);
    }

    /** @return array<string, mixed> */
    public function localizar(int $id, string $nota, ?int $area, string $usuario, ?string $ip): array
    {
        $this->transaccion->ejecutar(function () use ($id, $nota, $area, $usuario, $ip): void {
            $f = $this->fila($id);
            self::exigirAbierto($f);
            $activo = $this->datos->activo($f['ACT_Activo']);
            if (null !== $area && !$this->datos->existeArea($area)) {
                throw ErrorDominio::noEncontrado('AREA_NOT_FOUND');
            }
            $origen = null === $activo['ARE_Area_Ubicacion'] ? null : (int) $activo['ARE_Area_Ubicacion'];
            if (null !== $area && $area !== $origen) {
                $this->datos->ubicarActivo($f['ACT_Activo'], $area);
            }
            $sede = null === $activo['SED_Sede'] ? null : (int) $activo['SED_Sede'];
            $this->kardex->registrar('INVENTARIO_FISICO', activo: $activo, usuario: $usuario, origen: ['sede' => $sede, 'area' => $origen],
                destino: ['sede' => $sede, 'area' => $area ?? $origen], documento: null === $f['TOM_Toma'] ? null : ['TOMA_INVENTARIO', (string) $f['TOM_Toma']],
                motivo: "Faltante localizado: {$nota}", detalle: ['resultado' => 'LOCALIZADO', 'faltante' => $id]);
            $this->datos->marcarLocalizado($id, mb_substr($nota, 0, 500), Reloj::ahora());
            $this->nota($id, $f['FAL_Seguimiento'], $usuario, $nota, 'LOCALIZADO');
            $this->gobierno->registrarAuditoria('MISSING_ITEM_FOUND', 'INV_FALTANTE', ['id' => $id, 'codigo' => $activo['ACT_Codigo_Interno'], 'nota' => $nota,
                'area' => $area], $usuario, $ip, sedeId: null === $f['SED_Sede'] ? null : (int) $f['SED_Sede']);
        });

        return $this->uno($id);
    }

    /**
     * Baja por pérdida o robo: va a aprobación como cualquier baja.
     *
     * @param array<string, mixed> $baja
     *
     * @return array<string, mixed>
     */
    public function solicitarBaja(int $id, array $baja, string $usuario, ?string $ip): array
    {
        $this->transaccion->ejecutar(function () use ($id, $baja, $usuario, $ip): void {
            $f = $this->fila($id);
            self::exigirAbierto($f);
            if (!\in_array($baja['motivo'], ['PERDIDA', 'ROBO'], true)) {
                throw ErrorDominio::noProcesable('MISSING_ITEM_DECOMMISSION_REASON');
            }
            $solicitud = $this->solicitudes->solicitarBaja((string) $f['ACT_Activo'], $baja, $usuario, $ip);
            $this->datos->marcarBajaSolicitada($id, $solicitud['APR_Aprobacion']);
            $this->nota($id, $f['FAL_Seguimiento'], $usuario, ($baja['observacion'] ?? null) ?: $baja['motivo'], 'BAJA_SOLICITADA');
        });

        return $this->uno($id);
    }

    /** La solicitud de baja de un faltante se resolvió (aprobada, rechazada o anulada). */
    public function alResolverBaja(int $aprobacion, bool $aprobada): void
    {
        foreach ($this->datos->deAprobacion($aprobacion) as $f) {
            $id = (int) $f['FAL_Faltante'];
            if ($aprobada) {
                $this->datos->marcarDadoDeBaja($id, "Baja aprobada (solicitud {$aprobacion})", Reloj::ahora());
            } else {
                $this->datos->volverAInvestigacion($id);
            }
            $this->nota($id, $f['FAL_Seguimiento'], null, "Solicitud de baja {$aprobacion} ".($aprobada ? 'aprobada' : 'no aprobada'),
                $aprobada ? 'DADO_DE_BAJA' : 'BAJA_NO_APROBADA');
        }
    }
}
