<?php

declare(strict_types=1);

namespace App\Inventario\Infrastructure;

use App\Dominio\Reloj;
use App\Inventario\Activos;
use App\Seguridad\Alcance;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/** Assets a count did not find and their follow-up, as stored: all that SQL lives here. */
final readonly class FaltantesStore
{
    private const string ABIERTOS = "('PENDIENTE', 'EN_INVESTIGACION', 'BAJA_SOLICITADA')";

    public function __construct(private Connection $conexion, private Alcance $alcance)
    {
    }

    /**
     * Opens a missing item for each asset that does not have one open already; returns how many were opened.
     *
     * @param list<string> $activos at most 1000
     */
    public function registrar(int $toma, int $sede, string $fecha, array $activos): int
    {
        return (int) $this->conexion->executeStatement(
            'INSERT INTO "INV_FALTANTE" ("TOM_Toma", "ACT_Activo", "SED_Sede", "FAL_Estado", "FAL_Fecha") '
            ."SELECT :t, a.\"ACT_Activo\", :s, 'PENDIENTE', :f FROM \"INV_ACTIVO\" a WHERE a.\"ACT_Activo\" IN (:ids) "
            .'AND NOT EXISTS (SELECT 1 FROM "INV_FALTANTE" x WHERE x."ACT_Activo" = a."ACT_Activo" AND x."FAL_Estado" IN '.self::ABIERTOS.')',
            ['t' => $toma, 's' => $sede, 'f' => $fecha, 'ids' => $activos], ['ids' => ArrayParameterType::STRING],
        );
    }

    /** @return list<array<string, mixed>> FAL_Faltante and FAL_Seguimiento of the open items of an asset, locked */
    public function abiertosDeActivo(string $activo): array
    {
        return $this->conexion->fetchAllAssociative('SELECT "FAL_Faltante", "FAL_Seguimiento" FROM "INV_FALTANTE" WHERE "ACT_Activo" = :a '
            ."AND \"FAL_Estado\" IN ('PENDIENTE', 'EN_INVESTIGACION') FOR UPDATE", ['a' => $activo]);
    }

    /** @return list<array<string, mixed>> FAL_Faltante and FAL_Seguimiento of the items waiting for that disposal request, locked */
    public function deAprobacion(int $aprobacion): array
    {
        return $this->conexion->fetchAllAssociative('SELECT "FAL_Faltante", "FAL_Seguimiento" FROM "INV_FALTANTE" WHERE "APR_Aprobacion" = :a '
            ."AND \"FAL_Estado\" = 'BAJA_SOLICITADA' FOR UPDATE", ['a' => $aprobacion]);
    }

    /** @return array<string, mixed>|false the item, locked, if the current user may see it */
    public function porId(int $id): array|false
    {
        [$alcance, $parametros] = $this->alcance->lectura('INV_FALTANTE', 'f');
        $this->conexion->fetchOne('SELECT 1 FROM "INV_FALTANTE" WHERE "FAL_Faltante" = :id FOR UPDATE', ['id' => $id]);

        return $this->conexion->fetchAssociative('SELECT f.* FROM "INV_FALTANTE" f WHERE f."FAL_Faltante" = :id AND '.$alcance, [...$parametros, 'id' => $id]);
    }

    /** @return list<array<string, mixed>> up to 500 visible items with their asset, newest first */
    public function listar(?string $estado, bool $abiertos, ?int $id): array
    {
        [$sql, $parametros] = $this->alcance->lectura('INV_FALTANTE', 'f');
        if (null !== $id) {
            $sql .= ' AND f."FAL_Faltante" = :id';
            $parametros['id'] = $id;
        }
        if (null !== $estado && '' !== $estado) {
            $sql .= ' AND f."FAL_Estado" = :estado';
            $parametros['estado'] = $estado;
        } elseif ($abiertos) {
            $sql .= ' AND f."FAL_Estado" IN '.self::ABIERTOS;
        }

        return $this->conexion->fetchAllAssociative('SELECT f.*, a."ACT_Codigo_Interno", a."ACT_Serie_Fabricante", t."TAC_Nombre", m."MOD_Nombre", ar."ARE_Nombre" '
            .'FROM "INV_FALTANTE" f JOIN "INV_ACTIVO" a ON a."ACT_Activo" = f."ACT_Activo" JOIN "INV_TIPO_ACTIVO" t ON t."TAC_Tipo_Activo" = a."TAC_Tipo_Activo" '
            .'JOIN "INV_MODELO" m ON m."MOD_Modelo" = a."MOD_Modelo" LEFT JOIN "INV_AREA" ar ON ar."ARE_Area" = a."ARE_Area_Ubicacion" '
            .'WHERE '.$sql.' ORDER BY f."FAL_Fecha" DESC, f."FAL_Faltante" DESC FETCH FIRST 500 ROWS ONLY', $parametros);
    }

    /** @param list<mixed> $seguimiento */
    public function guardarSeguimiento(int $id, array $seguimiento): void
    {
        $this->conexion->executeStatement('UPDATE "INV_FALTANTE" SET "FAL_Seguimiento" = :s WHERE "FAL_Faltante" = :id',
            ['s' => json_encode($seguimiento, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), 'id' => $id], ['s' => ParameterType::LARGE_OBJECT]);
    }

    public function marcarEnInvestigacion(int $id): void
    {
        $this->conexion->executeStatement("UPDATE \"INV_FALTANTE\" SET \"FAL_Estado\" = 'EN_INVESTIGACION' WHERE \"FAL_Faltante\" = :id", ['id' => $id]);
    }

    /** Back to investigation and without its disposal request (the request was rejected or cancelled). */
    public function volverAInvestigacion(int $id): void
    {
        $this->conexion->executeStatement("UPDATE \"INV_FALTANTE\" SET \"FAL_Estado\" = 'EN_INVESTIGACION', \"APR_Aprobacion\" = NULL WHERE \"FAL_Faltante\" = :id", ['id' => $id]);
    }

    public function marcarBajaSolicitada(int $id, mixed $aprobacion): void
    {
        $this->conexion->executeStatement("UPDATE \"INV_FALTANTE\" SET \"FAL_Estado\" = 'BAJA_SOLICITADA', \"APR_Aprobacion\" = :a WHERE \"FAL_Faltante\" = :id",
            ['a' => $aprobacion, 'id' => $id]);
    }

    public function marcarLocalizado(int $id, string $resolucion, \DateTimeImmutable $ahora): void
    {
        $this->resolver($id, 'LOCALIZADO', $resolucion, $ahora);
    }

    public function marcarDadoDeBaja(int $id, string $resolucion, \DateTimeImmutable $ahora): void
    {
        $this->resolver($id, 'DADO_DE_BAJA', $resolucion, $ahora);
    }

    private function resolver(int $id, string $estado, string $resolucion, \DateTimeImmutable $ahora): void
    {
        $this->conexion->executeStatement('UPDATE "INV_FALTANTE" SET "FAL_Estado" = :e, "FAL_Fecha_Resolucion" = :ahora, "FAL_Resolucion" = :r WHERE "FAL_Faltante" = :id',
            ['e' => $estado, 'ahora' => Reloj::aBd($ahora), 'r' => $resolucion, 'id' => $id]);
    }

    public function nombreDeUsuario(string $usuario): mixed
    {
        return $this->conexion->fetchOne('SELECT "USU_Username" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $usuario]);
    }

    /** @return array<string, mixed>|false the asset row, whatever its site */
    public function activo(mixed $activo): array|false
    {
        return $this->conexion->fetchAssociative('SELECT '.Activos::select().' FROM "INV_ACTIVO" a WHERE a."ACT_Activo" = :a', ['a' => $activo]);
    }

    public function existeArea(int $area): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_AREA" WHERE "ARE_Area" = :a', ['a' => $area]);
    }

    public function ubicarActivo(mixed $activo, int $area): void
    {
        $this->conexion->executeStatement('UPDATE "INV_ACTIVO" SET "ARE_Area_Ubicacion" = :a WHERE "ACT_Activo" = :id', ['a' => $area, 'id' => $activo]);
    }
}
