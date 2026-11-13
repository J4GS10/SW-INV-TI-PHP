<?php

declare(strict_types=1);

namespace App\Inventario\Infrastructure;

use App\Db\InsercionOracle;
use App\Dominio\Reloj;
use App\Seguridad\Alcance;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** Consumables, their stock movements and the models they fit, as stored: all that SQL lives here. */
final readonly class ConsumiblesStore
{
    private const string COLUMNAS = 'c."CON_Consumible", c."CON_Nombre", c."CON_Descripcion", c."CON_Categoria", c."CON_Unidad", '
        .'c."CCO_Categoria", c."UME_Unidad", c."CON_Stock_Actual", c."CON_Stock_Minimo", c."CON_Activo", c."SED_Sede", '
        .'c."CON_Costo_Unitario", c."CON_Moneda"';

    public function __construct(private Connection $conexion, private Alcance $alcance)
    {
    }

    /** @return array<string, mixed>|false a consumable the current user may see, locked when asked */
    public function porId(int $id, bool $bloquear = false): array|false
    {
        [$alcance, $p] = $this->alcance->lectura('INV_CONSUMIBLE', 'c');

        return $this->conexion->fetchAssociative('SELECT '.self::COLUMNAS.' FROM "INV_CONSUMIBLE" c WHERE c."CON_Consumible" = :id AND '.$alcance
            .($bloquear ? ' FOR UPDATE' : ''), [...$p, 'id' => $id]);
    }

    /** @return list<array<string, mixed>> the visible consumables by name; with `$soloBajoStock`, those at or under their minimum */
    public function visibles(bool $soloBajoStock): array
    {
        [$alcance, $p] = $this->alcance->lectura('INV_CONSUMIBLE', 'c');
        $bajo = $soloBajoStock ? ' AND c."CON_Stock_Minimo" > 0 AND c."CON_Stock_Actual" <= c."CON_Stock_Minimo"' : '';

        return $this->conexion->fetchAllAssociative('SELECT '.self::COLUMNAS.' FROM "INV_CONSUMIBLE" c WHERE '
            .$alcance.$bajo.' ORDER BY c."CON_Nombre", c."CON_Consumible"', $p);
    }

    /** Whether a consumable has that name (run it elevated to check the whole company). */
    public function existeNombre(mixed $nombre): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_CONSUMIBLE" WHERE "CON_Nombre" = :n FETCH FIRST 1 ROWS ONLY', ['n' => $nombre]);
    }

    /** @param array<string, mixed> $columnas */
    public function insertar(array $columnas): int
    {
        return (int) InsercionOracle::insertar($this->conexion, 'INV_CONSUMIBLE', $columnas, 'CON_Consumible');
    }

    /** @param array<string, mixed> $cambios column => value; the column names come from the validated form, never raw from the request */
    public function actualizar(int $id, array $cambios): void
    {
        $sets = [];
        foreach ($cambios as $columna => $valor) {
            $sets[] = "\"{$columna}\" = :{$columna}";
            $cambios[$columna] = \is_bool($valor) ? (int) $valor : $valor;
        }
        $this->conexion->executeStatement('UPDATE "INV_CONSUMIBLE" SET '.implode(', ', $sets).', "UPDATED_AT" = :upd WHERE "CON_Consumible" = :id',
            [...$cambios, 'upd' => Reloj::aBd(Reloj::ahora()), 'id' => $id]);
    }

    public function tieneMovimientos(int $id): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_MOVIMIENTO_CONSUMIBLE" WHERE "CON_Consumible" = :id FETCH FIRST 1 ROWS ONLY', ['id' => $id]);
    }

    public function sumarStock(int $id, int $cantidad): void
    {
        $this->conexion->executeStatement('UPDATE "INV_CONSUMIBLE" SET "CON_Stock_Actual" = "CON_Stock_Actual" + :n WHERE "CON_Consumible" = :id', ['n' => $cantidad, 'id' => $id]);
    }

    /** Takes stock out in one statement; false (and nothing changes) when there is not enough. */
    public function restarStock(int $id, int $cantidad): bool
    {
        return 1 === $this->conexion->executeStatement('UPDATE "INV_CONSUMIBLE" SET "CON_Stock_Actual" = "CON_Stock_Actual" - :n '
            .'WHERE "CON_Consumible" = :id AND "CON_Stock_Actual" >= :n', ['n' => $cantidad, 'id' => $id]);
    }

    public function fijarStock(int $id, int $stock): void
    {
        $this->conexion->executeStatement('UPDATE "INV_CONSUMIBLE" SET "CON_Stock_Actual" = :r WHERE "CON_Consumible" = :id', ['r' => $stock, 'id' => $id]);
    }

    public function stock(int $id): int
    {
        return (int) $this->conexion->fetchOne('SELECT "CON_Stock_Actual" FROM "INV_CONSUMIBLE" WHERE "CON_Consumible" = :id', ['id' => $id]);
    }

    public function guardarCosto(int $id, ?string $promedio, mixed $moneda): void
    {
        $this->conexion->executeStatement('UPDATE "INV_CONSUMIBLE" SET "CON_Costo_Unitario" = :p, "CON_Moneda" = :m WHERE "CON_Consumible" = :id',
            ['p' => $promedio, 'm' => $moneda, 'id' => $id]);
    }

    /** @param array<string, mixed> $columnas */
    public function insertarMovimiento(array $columnas): int
    {
        return (int) InsercionOracle::insertar($this->conexion, 'INV_MOVIMIENTO_CONSUMIBLE', $columnas, 'MOC_Movimiento');
    }

    /** @return list<array<string, mixed>> the visible stock movements of a consumable, newest first */
    public function movimientosDe(int $id): array
    {
        [$alcance, $p] = $this->alcance->lectura('INV_MOVIMIENTO_CONSUMIBLE', 'm');

        return $this->conexion->fetchAllAssociative('SELECT m."MOC_Movimiento", m."MOC_Tipo", m."MOC_Cantidad", '
            .'m."MOC_Stock_Resultante", m."MOC_Motivo", m."MOC_Fecha", m."CON_Consumible", m."PER_Persona", m."ACT_Activo", m."USU_Usuario" '
            .'FROM "INV_MOVIMIENTO_CONSUMIBLE" m WHERE m."CON_Consumible" = :id AND '.$alcance.' ORDER BY m."MOC_Fecha" DESC, m."MOC_Movimiento" DESC',
            [...$p, 'id' => $id]);
    }

    /** @return array<string, mixed>|false ACT_Activo, ACT_Codigo_Interno, ARE_Area_Ubicacion, EOP_Estado_Operativo and MOD_Modelo of an asset the current user may see */
    public function equipoVisible(string $activo): array|false
    {
        [$alcance, $p] = $this->alcance->lectura('INV_ACTIVO', 'a');

        return $this->conexion->fetchAssociative('SELECT a."ACT_Activo", a."ACT_Codigo_Interno", a."ARE_Area_Ubicacion", a."EOP_Estado_Operativo", a."MOD_Modelo" '
            .'FROM "INV_ACTIVO" a WHERE a."ACT_Activo" = :a AND '.$alcance, [...$p, 'a' => $activo]);
    }

    /** @return list<array<string, mixed>> MOD_Modelo, MOD_Nombre and MAR_Nombre of the models a consumable fits, by brand and name */
    public function modelosCompatibles(int $id): array
    {
        return $this->conexion->fetchAllAssociative('SELECT m."MOD_Modelo", m."MOD_Nombre", ma."MAR_Nombre" FROM "INV_MODELO" m '
            .'JOIN "INV_CONSUMIBLE_COMPATIBLE" cc ON cc."MOD_Modelo" = m."MOD_Modelo" JOIN "INV_MARCA" ma ON ma."MAR_Marca" = m."MAR_Marca" '
            .'WHERE cc."CON_Consumible" = :id ORDER BY ma."MAR_Nombre", m."MOD_Nombre"', ['id' => $id]);
    }

    /**
     * How many of those models exist.
     *
     * @param list<int> $modelos at most 1000
     */
    public function cuantosModelosExisten(array $modelos): int
    {
        return [] === $modelos ? 0 : (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "INV_MODELO" WHERE "MOD_Modelo" IN (:m)', ['m' => $modelos], ['m' => ArrayParameterType::INTEGER]);
    }

    /** @return list<int> the ids of the models a consumable fits, ascending */
    public function idsDeCompatibles(int $id): array
    {
        return array_map('intval', $this->conexion->fetchFirstColumn('SELECT "MOD_Modelo" FROM "INV_CONSUMIBLE_COMPATIBLE" WHERE "CON_Consumible" = :id '
            .'ORDER BY "MOD_Modelo"', ['id' => $id]));
    }

    /** @param list<int> $modelos */
    public function reemplazarCompatibles(int $id, array $modelos): void
    {
        $this->conexion->executeStatement('DELETE FROM "INV_CONSUMIBLE_COMPATIBLE" WHERE "CON_Consumible" = :id', ['id' => $id]);
        foreach ($modelos as $modelo) {
            $this->conexion->executeStatement('INSERT INTO "INV_CONSUMIBLE_COMPATIBLE" ("CON_Consumible", "MOD_Modelo") VALUES (:c, :m)', ['c' => $id, 'm' => $modelo]);
        }
    }

    /** @return list<array<string, mixed>> the last 200 consumables used on an asset, with their name, unit and who registered them */
    public function consumoDeActivo(string $activo): array
    {
        return $this->conexion->fetchAllAssociative('SELECT m."MOC_Movimiento", m."MOC_Fecha", m."CON_Consumible", c."CON_Nombre", c."CON_Unidad", '
            .'m."MOC_Cantidad", m."MOC_Motivo", u."USU_Username" FROM "INV_MOVIMIENTO_CONSUMIBLE" m JOIN "INV_CONSUMIBLE" c ON c."CON_Consumible" = m."CON_Consumible" '
            .'LEFT JOIN "INV_USUARIO" u ON u."USU_Usuario" = m."USU_Usuario" WHERE m."ACT_Activo" = :a ORDER BY m."MOC_Fecha" DESC FETCH FIRST 200 ROWS ONLY', ['a' => $activo]);
    }

    /** @return list<array<string, mixed>> the active consumables that fit a model, by name */
    public function compatiblesConModelo(int $modelo): array
    {
        return $this->conexion->fetchAllAssociative('SELECT c."CON_Consumible", c."CON_Nombre", c."CON_Stock_Actual", c."CON_Unidad", c."SED_Sede" '
            .'FROM "INV_CONSUMIBLE" c JOIN "INV_CONSUMIBLE_COMPATIBLE" cc ON cc."CON_Consumible" = c."CON_Consumible" '
            .'WHERE cc."MOD_Modelo" = :m AND c."CON_Activo" = 1 ORDER BY c."CON_Nombre"', ['m' => $modelo]);
    }
}
