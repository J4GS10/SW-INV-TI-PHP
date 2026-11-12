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
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

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
        private Connection $conexion,
        private Alcance $alcance,
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
            $nuevos += (int) $this->conexion->executeStatement(
                "INSERT INTO \"INV_FALTANTE\" (\"TOM_Toma\", \"ACT_Activo\", \"SED_Sede\", \"FAL_Estado\", \"FAL_Fecha\") "
                ."SELECT :t, a.\"ACT_Activo\", :s, 'PENDIENTE', :f FROM \"INV_ACTIVO\" a WHERE a.\"ACT_Activo\" IN (:ids) "
                ."AND NOT EXISTS (SELECT 1 FROM \"INV_FALTANTE\" x WHERE x.\"ACT_Activo\" = a.\"ACT_Activo\" "
                ."AND x.\"FAL_Estado\" IN ('PENDIENTE', 'EN_INVESTIGACION', 'BAJA_SOLICITADA'))",
                ['t' => $toma, 's' => $sede, 'f' => $ahora, 'ids' => $bloque], ['ids' => ArrayParameterType::STRING],
            );
        }

        return $nuevos;
    }

    /** Un faltante que aparece en otra toma queda localizado. */
    public function resolverPorLectura(string $activo, int $toma, ?string $usuario): void
    {
        foreach ($this->conexion->fetchAllAssociative("SELECT \"FAL_Faltante\", \"FAL_Seguimiento\" FROM \"INV_FALTANTE\" WHERE \"ACT_Activo\" = :a "
            ."AND \"FAL_Estado\" IN ('PENDIENTE', 'EN_INVESTIGACION') FOR UPDATE", ['a' => $activo]) as $f) {
            $texto = "Leído en la toma {$toma}";
            $this->conexion->executeStatement("UPDATE \"INV_FALTANTE\" SET \"FAL_Estado\" = 'LOCALIZADO', \"FAL_Fecha_Resolucion\" = :ahora, "
                .'"FAL_Resolucion" = :r WHERE "FAL_Faltante" = :id', ['ahora' => Reloj::aBd(Reloj::ahora()), 'r' => $texto, 'id' => (int) $f['FAL_Faltante']]);
            $this->nota((int) $f['FAL_Faltante'], $f['FAL_Seguimiento'], $usuario, $texto, 'LOCALIZADO');
        }
    }

    /** Agrega una entrada al seguimiento (quién, cuándo, qué). */
    private function nota(int $id, ?string $seguimiento, ?string $usuario, string $texto, string $accion): void
    {
        $nombre = null === $usuario ? false : $this->conexion->fetchOne('SELECT "USU_Username" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $usuario]);
        $lista = null === $seguimiento ? [] : (json_decode($seguimiento, true) ?: []);
        $lista[] = ['fecha' => Reloj::ahora()->format('Y-m-d\TH:i:s'), 'usuario' => false === $nombre ? null : $nombre, 'accion' => $accion, 'nota' => $texto];
        $this->conexion->executeStatement('UPDATE "INV_FALTANTE" SET "FAL_Seguimiento" = :s WHERE "FAL_Faltante" = :id',
            ['s' => json_encode($lista, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), 'id' => $id], ['s' => ParameterType::LARGE_OBJECT]);
    }

    /** @return array<string, mixed> */
    private function fila(int $id): array
    {
        [$alcance, $p] = $this->alcance->lectura('INV_FALTANTE', 'f');
        $this->conexion->fetchOne('SELECT 1 FROM "INV_FALTANTE" WHERE "FAL_Faltante" = :id FOR UPDATE', ['id' => $id]);
        $f = $this->conexion->fetchAssociative('SELECT f.* FROM "INV_FALTANTE" f WHERE f."FAL_Faltante" = :id AND '.$alcance, [...$p, 'id' => $id]);

        return false === $f ? throw ErrorDominio::noEncontrado('MISSING_ITEM_NOT_FOUND') : $f;
    }

    /** @return list<array<string, mixed>> */
    public function listar(?string $estado = null, bool $abiertos = false, ?int $id = null): array
    {
        [$sql, $p] = $this->alcance->lectura('INV_FALTANTE', 'f');
        if (null !== $id) {
            $sql .= ' AND f."FAL_Faltante" = :id';
            $p['id'] = $id;
        }
        if (null !== $estado && '' !== $estado) {
            $sql .= ' AND f."FAL_Estado" = :estado';
            $p['estado'] = $estado;
        } elseif ($abiertos) {
            $sql .= " AND f.\"FAL_Estado\" IN ('PENDIENTE', 'EN_INVESTIGACION', 'BAJA_SOLICITADA')";
        }
        $filas = $this->conexion->fetchAllAssociative('SELECT f.*, a."ACT_Codigo_Interno", a."ACT_Serie_Fabricante", t."TAC_Nombre", m."MOD_Nombre", ar."ARE_Nombre" '
            .'FROM "INV_FALTANTE" f JOIN "INV_ACTIVO" a ON a."ACT_Activo" = f."ACT_Activo" JOIN "INV_TIPO_ACTIVO" t ON t."TAC_Tipo_Activo" = a."TAC_Tipo_Activo" '
            .'JOIN "INV_MODELO" m ON m."MOD_Modelo" = a."MOD_Modelo" LEFT JOIN "INV_AREA" ar ON ar."ARE_Area" = a."ARE_Area_Ubicacion" '
            .'WHERE '.$sql.' ORDER BY f."FAL_Fecha" DESC, f."FAL_Faltante" DESC FETCH FIRST 500 ROWS ONLY', $p);

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
            $this->conexion->executeStatement("UPDATE \"INV_FALTANTE\" SET \"FAL_Estado\" = 'EN_INVESTIGACION' WHERE \"FAL_Faltante\" = :id", ['id' => $id]);
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
            $activo = $this->conexion->fetchAssociative('SELECT '.Activos::select().' FROM "INV_ACTIVO" a WHERE a."ACT_Activo" = :a', ['a' => $f['ACT_Activo']]);
            if (null !== $area && false === $this->conexion->fetchOne('SELECT 1 FROM "INV_AREA" WHERE "ARE_Area" = :a', ['a' => $area])) {
                throw ErrorDominio::noEncontrado('AREA_NOT_FOUND');
            }
            $origen = null === $activo['ARE_Area_Ubicacion'] ? null : (int) $activo['ARE_Area_Ubicacion'];
            if (null !== $area && $area !== $origen) {
                $this->conexion->executeStatement('UPDATE "INV_ACTIVO" SET "ARE_Area_Ubicacion" = :a WHERE "ACT_Activo" = :id', ['a' => $area, 'id' => $f['ACT_Activo']]);
            }
            $sede = null === $activo['SED_Sede'] ? null : (int) $activo['SED_Sede'];
            $this->kardex->registrar('INVENTARIO_FISICO', activo: $activo, usuario: $usuario, origen: ['sede' => $sede, 'area' => $origen],
                destino: ['sede' => $sede, 'area' => $area ?? $origen], documento: null === $f['TOM_Toma'] ? null : ['TOMA_INVENTARIO', (string) $f['TOM_Toma']],
                motivo: "Faltante localizado: {$nota}", detalle: ['resultado' => 'LOCALIZADO', 'faltante' => $id]);
            $this->conexion->executeStatement("UPDATE \"INV_FALTANTE\" SET \"FAL_Estado\" = 'LOCALIZADO', \"FAL_Fecha_Resolucion\" = :ahora, \"FAL_Resolucion\" = :r "
                .'WHERE "FAL_Faltante" = :id', ['ahora' => Reloj::aBd(Reloj::ahora()), 'r' => mb_substr($nota, 0, 500), 'id' => $id]);
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
            $this->conexion->executeStatement("UPDATE \"INV_FALTANTE\" SET \"FAL_Estado\" = 'BAJA_SOLICITADA', \"APR_Aprobacion\" = :a WHERE \"FAL_Faltante\" = :id",
                ['a' => $solicitud['APR_Aprobacion'], 'id' => $id]);
            $this->nota($id, $f['FAL_Seguimiento'], $usuario, ($baja['observacion'] ?? null) ?: $baja['motivo'], 'BAJA_SOLICITADA');
        });

        return $this->uno($id);
    }

    /** La solicitud de baja de un faltante se resolvió (aprobada, rechazada o anulada). */
    public function alResolverBaja(int $aprobacion, bool $aprobada): void
    {
        foreach ($this->conexion->fetchAllAssociative("SELECT \"FAL_Faltante\", \"FAL_Seguimiento\" FROM \"INV_FALTANTE\" WHERE \"APR_Aprobacion\" = :a "
            ."AND \"FAL_Estado\" = 'BAJA_SOLICITADA' FOR UPDATE", ['a' => $aprobacion]) as $f) {
            $id = (int) $f['FAL_Faltante'];
            if ($aprobada) {
                $this->conexion->executeStatement("UPDATE \"INV_FALTANTE\" SET \"FAL_Estado\" = 'DADO_DE_BAJA', \"FAL_Fecha_Resolucion\" = :ahora, \"FAL_Resolucion\" = :r "
                    .'WHERE "FAL_Faltante" = :id', ['ahora' => Reloj::aBd(Reloj::ahora()), 'r' => "Baja aprobada (solicitud {$aprobacion})", 'id' => $id]);
            } else {
                $this->conexion->executeStatement("UPDATE \"INV_FALTANTE\" SET \"FAL_Estado\" = 'EN_INVESTIGACION', \"APR_Aprobacion\" = NULL WHERE \"FAL_Faltante\" = :id",
                    ['id' => $id]);
            }
            $this->nota($id, $f['FAL_Seguimiento'], null, "Solicitud de baja {$aprobacion} ".($aprobada ? 'aprobada' : 'no aprobada'),
                $aprobada ? 'DADO_DE_BAJA' : 'BAJA_NO_APROBADA');
        }
    }
}
