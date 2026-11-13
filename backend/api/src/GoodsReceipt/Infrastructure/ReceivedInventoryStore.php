<?php

declare(strict_types=1);

namespace App\GoodsReceipt\Infrastructure;

use App\Db\InsercionOracle;
use App\Dominio\Reloj;
use Doctrine\DBAL\Connection;

/** What a goods receipt reads and writes in the inventory (assets, brands, models, consumables): all that SQL lives here. */
final readonly class ReceivedInventoryStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function lockAsset(string $asset): void
    {
        $this->connection->fetchOne('SELECT 1 FROM "INV_ACTIVO" WHERE "ACT_Activo" = :a FOR UPDATE', ['a' => $asset]);
    }

    /** Writes the serial number, the hostname (kept when null) and whether data is still missing. */
    public function completeAsset(string $asset, string $serial, ?string $hostname, bool $pending): void
    {
        $this->connection->executeStatement('UPDATE "INV_ACTIVO" SET "ACT_Serie_Fabricante" = :s, "ACT_Hostname" = NVL(:h, "ACT_Hostname"), "ACT_Pending_Completion" = :p WHERE "ACT_Activo" = :a',
            ['s' => $serial, 'h' => $hostname, 'p' => $pending ? 1 : 0, 'a' => $asset]);
    }

    /** Whether another asset has that serial number (run it elevated to check the whole company). */
    public function serialTaken(string $serial, ?string $except): bool
    {
        return $this->assetValueTaken('ACT_Serie_Fabricante', $serial, $except, '');
    }

    /** Whether another asset still in service has that hostname (run it elevated to check the whole company). */
    public function hostnameTaken(string $hostname, ?string $except): bool
    {
        return $this->assetValueTaken('ACT_Hostname', $hostname, $except, ' AND "ACT_Baja_Fecha" IS NULL');
    }

    private function assetValueTaken(string $column, string $value, ?string $except, string $extra): bool
    {
        return false !== $this->connection->fetchOne(
            "SELECT 1 FROM \"INV_ACTIVO\" WHERE LOWER(TRIM(\"{$column}\")) = LOWER(TRIM(:v)){$extra}".(null === $except ? '' : ' AND "ACT_Activo" <> :a').' FETCH FIRST 1 ROWS ONLY',
            ['v' => $value, ...(null === $except ? [] : ['a' => $except])],
        );
    }

    public function assetTypeExists(int $assetType): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM "INV_TIPO_ACTIVO" WHERE "TAC_Tipo_Activo" = :t', ['t' => $assetType]);
    }

    /** The code prefix of an asset type; null when it has none or the type does not exist. */
    public function prefixOfAssetType(int $assetType): ?string
    {
        $prefix = $this->connection->fetchOne('SELECT "TAC_Prefijo" FROM "INV_TIPO_ACTIVO" WHERE "TAC_Tipo_Activo" = :t', ['t' => $assetType]);

        return false === $prefix || null === $prefix || '' === $prefix ? null : (string) $prefix;
    }

    public function brandByName(string $name): ?int
    {
        $id = $this->connection->fetchOne('SELECT "MAR_Marca" FROM "INV_MARCA" WHERE LOWER(TRIM("MAR_Nombre")) = LOWER(:n) FETCH FIRST 1 ROWS ONLY', ['n' => $name]);

        return false === $id ? null : (int) $id;
    }

    public function createBrand(string $name): int
    {
        return (int) InsercionOracle::insertar($this->connection, 'INV_MARCA', ['MAR_Nombre' => $name], 'MAR_Marca');
    }

    public function modelByName(int $brand, string $name): ?int
    {
        $id = $this->connection->fetchOne('SELECT "MOD_Modelo" FROM "INV_MODELO" WHERE "MAR_Marca" = :b AND LOWER(TRIM("MOD_Nombre")) = LOWER(:n) FETCH FIRST 1 ROWS ONLY', ['b' => $brand, 'n' => $name]);

        return false === $id ? null : (int) $id;
    }

    public function createModel(string $name, int $brand, int $assetType): int
    {
        return (int) InsercionOracle::insertar($this->connection, 'INV_MODELO', ['MOD_Nombre' => $name, 'MAR_Marca' => $brand, 'TAC_Tipo_Activo' => $assetType], 'MOD_Modelo');
    }

    public function assetTypeOfModel(int $model): ?int
    {
        $type = $this->connection->fetchOne('SELECT "TAC_Tipo_Activo" FROM "INV_MODELO" WHERE "MOD_Modelo" = :m', ['m' => $model]);

        return false === $type || null === $type ? null : (int) $type;
    }

    /**
     * Replaces the factory values of a model.
     *
     * @param array<int, string> $values specification type => value
     */
    public function replaceModelDefaults(int $model, array $values): void
    {
        $this->connection->executeStatement('DELETE FROM CAT_MODEL_SPECIFICATION WHERE MSP_MOD_MODEL = :m', ['m' => $model]);
        foreach ($values as $type => $value) {
            $this->connection->executeStatement('INSERT INTO CAT_MODEL_SPECIFICATION (MSP_MOD_MODEL, MSP_TES_SPECIFICATION_TYPE, MSP_DEFAULT_VALUE) VALUES (:m, :t, :v)',
                ['m' => $model, 't' => $type, 'v' => $value]);
        }
    }

    /** @return array<string, mixed>|false GRC_PRV_SUPPLIER and GRC_CURRENCY of the last receipt that user registered */
    public function lastReceiptOf(string $user): array|false
    {
        return $this->connection->fetchAssociative('SELECT GRC_PRV_SUPPLIER, GRC_CURRENCY FROM PUR_GOODS_RECEIPT WHERE GRC_USU_CREATED_BY = :u ORDER BY GRC_GOODS_RECEIPT DESC FETCH FIRST 1 ROWS ONLY', ['u' => $user]);
    }

    public function supplierIsActive(int $supplier): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM "INV_PROVEEDOR" WHERE "PRV_Proveedor" = :p AND "PRV_Activo" = 1', ['p' => $supplier]);
    }

    /**
     * Creates an asset in storage that still waits for its serial number; the warranty ends `$warrantyMonths` after the purchase.
     *
     * @param array{id: string, code: string, bought: mixed, cost: mixed, currency: mixed, invoice: string, supplier: mixed, model: mixed, type: mixed, state: int, site: int} $asset
     */
    public function insertPendingAsset(array $asset, ?int $warrantyMonths): void
    {
        $this->connection->executeStatement('INSERT INTO "INV_ACTIVO" ("ACT_Activo", "ACT_Codigo_Interno", "ACT_Fecha_Compra", "ACT_Fin_Garantia", "ACT_Costo", "ACT_Moneda", "ACT_Factura", '
            .'"PRV_Proveedor", "MOD_Modelo", "TAC_Tipo_Activo", "EOP_Estado_Operativo", "SED_Sede", "ACT_Instalado", "ACT_Pending_Completion") VALUES '
            ."(:id, :code, TO_DATE(:bought, 'YYYY-MM-DD'), CASE WHEN :months IS NULL THEN NULL ELSE ADD_MONTHS(TO_DATE(:bought2, 'YYYY-MM-DD'), :months2) END, :cost, :currency, :invoice, "
            .':supplier, :model, :type, :state, :site, 0, 1)', [...$asset, 'bought2' => $asset['bought'], 'months' => $warrantyMonths, 'months2' => $warrantyMonths]);
    }

    public function addSpecification(string $asset, mixed $type, mixed $value): void
    {
        $this->connection->executeStatement('INSERT INTO "INV_ESPECIFICACION" ("ESP_Valor", "ACT_Activo", "TES_Tipo_Especificacion") VALUES (:v, :a, :t)',
            ['v' => $value, 'a' => $asset, 't' => $type]);
    }

    public function specificationId(string $asset, int $type): int
    {
        return (int) $this->connection->fetchOne('SELECT "ESP_Especificacion" FROM "INV_ESPECIFICACION" WHERE "ACT_Activo" = :a AND "TES_Tipo_Especificacion" = :t', ['a' => $asset, 't' => $type]);
    }

    /** @return array<string, mixed>|false name, stock, unit cost and currency of a consumable, locked */
    public function consumableForUpdate(int $consumable): array|false
    {
        return $this->connection->fetchAssociative('SELECT "CON_Consumible", "CON_Nombre", "CON_Stock_Actual", "CON_Costo_Unitario", "CON_Moneda" FROM "INV_CONSUMIBLE" WHERE "CON_Consumible" = :c FOR UPDATE', ['c' => $consumable]);
    }

    /** Writes the new balance and average cost of a consumable and the stock entry that produced them. */
    public function recordStockEntry(int $consumable, int $received, int $balance, mixed $average, mixed $currency, string $reason, string $user): void
    {
        $this->connection->executeStatement('UPDATE "INV_CONSUMIBLE" SET "CON_Stock_Actual" = :b, "CON_Costo_Unitario" = :p, "CON_Moneda" = :m WHERE "CON_Consumible" = :c',
            ['b' => $balance, 'p' => $average, 'm' => $currency, 'c' => $consumable]);
        InsercionOracle::insertar($this->connection, 'INV_MOVIMIENTO_CONSUMIBLE', ['MOC_Tipo' => 'ENTRADA', 'MOC_Cantidad' => $received, 'MOC_Stock_Resultante' => $balance, 'MOC_Motivo' => $reason,
            'MOC_Fecha' => Reloj::aBd(Reloj::ahora()), 'CON_Consumible' => $consumable, 'USU_Usuario' => $user], 'MOC_Movimiento');
    }

    public function consumableByName(int $site, mixed $name): ?int
    {
        $id = $this->connection->fetchOne('SELECT "CON_Consumible" FROM "INV_CONSUMIBLE" WHERE "SED_Sede" = :s AND LOWER(TRIM("CON_Nombre")) = LOWER(TRIM(:n)) FETCH FIRST 1 ROWS ONLY', ['s' => $site, 'n' => $name]);

        return false === $id ? null : (int) $id;
    }

    /** Creates an empty consumable in that site. */
    public function createConsumable(mixed $name, mixed $unit, int $site): int
    {
        return (int) InsercionOracle::insertar($this->connection, 'INV_CONSUMIBLE', ['CON_Nombre' => $name, 'CON_Unidad' => $unit,
            'SED_Sede' => $site, 'CON_Stock_Actual' => 0, 'CON_Stock_Minimo' => 0, 'CON_Activo' => 1], 'CON_Consumible');
    }
}
