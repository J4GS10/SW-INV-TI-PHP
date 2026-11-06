<?php

declare(strict_types=1);

namespace App\GoodsReceipt\Infrastructure;

use App\Db\InsercionOracle;
use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;

/** SQL of the goods receipt tables. Column keys come back in upper case: the tables are not quoted. */
final readonly class GoodsReceiptRepository
{
    private const string HEADER = 'SELECT g.*, p."PRV_Nombre" AS SUPPLIER_NAME, s."SED_Nombre" AS SITE_NAME, TO_CHAR(g.GRC_RECEIVED_DATE, \'YYYY-MM-DD\') AS RECEIVED_DATE '
        .'FROM PUR_GOODS_RECEIPT g JOIN "INV_PROVEEDOR" p ON p."PRV_Proveedor" = g.GRC_PRV_SUPPLIER JOIN "INV_SEDE" s ON s."SED_Sede" = g.GRC_SED_SITE ';

    public function __construct(private Connection $connection, private Alcance $scope)
    {
    }

    /** @return array<string, mixed>|null only when the receipt is within the user's scope */
    public function find(int $id, bool $lock = false): ?array
    {
        [$where, $params] = $this->scope->lectura('PUR_GOODS_RECEIPT', 'g');
        $row = $this->connection->fetchAssociative(self::HEADER.'WHERE g.GRC_GOODS_RECEIPT = :id AND '.$where, ['id' => $id, ...$params]);
        if (false === $row) {
            return null;
        }
        if ($lock) {
            $status = $this->connection->fetchOne('SELECT GRC_STATUS FROM PUR_GOODS_RECEIPT WHERE GRC_GOODS_RECEIPT = :id FOR UPDATE', ['id' => $id]);
            $row['GRC_STATUS'] = $status;
        }

        return $row;
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $status, int $limit): array
    {
        [$where, $params] = $this->scope->lectura('PUR_GOODS_RECEIPT', 'g');
        if (null !== $status) {
            $where .= ' AND g.GRC_STATUS = :status';
            $params['status'] = $status;
        }

        return $this->connection->fetchAllAssociative(self::HEADER.'WHERE '.$where.' ORDER BY g.GRC_GOODS_RECEIPT DESC FETCH FIRST '.$limit.' ROWS ONLY', $params);
    }

    /** @return list<array<string, mixed>> */
    public function lines(int $receipt): array
    {
        return $this->connection->fetchAllAssociative('SELECT l.*, mo."MOD_Nombre" AS MODEL_NAME, ma."MAR_Nombre" AS BRAND_NAME, t."TAC_Nombre" AS TYPE_NAME, '
            .'(SELECT COUNT(*) FROM PUR_GOODS_RECEIPT_UNIT u WHERE u.GRU_GRL_LINE = l.GRL_GOODS_RECEIPT_LINE) AS UNITS_TOTAL, '
            .'(SELECT COUNT(*) FROM PUR_GOODS_RECEIPT_UNIT u JOIN "INV_ACTIVO" a ON a."ACT_Activo" = u.GRU_ACT_ASSET WHERE u.GRU_GRL_LINE = l.GRL_GOODS_RECEIPT_LINE AND a."ACT_Pending_Completion" = 0) AS UNITS_DONE '
            .'FROM PUR_GOODS_RECEIPT_LINE l LEFT JOIN "INV_MODELO" mo ON mo."MOD_Modelo" = l.GRL_MOD_MODEL LEFT JOIN "INV_MARCA" ma ON ma."MAR_Marca" = mo."MAR_Marca" '
            .'LEFT JOIN "INV_TIPO_ACTIVO" t ON t."TAC_Tipo_Activo" = l.GRL_TAC_ASSET_TYPE '
            .'WHERE l.GRL_GRC_GOODS_RECEIPT = :r ORDER BY l.GRL_LINE_NUMBER', ['r' => $receipt]);
    }

    /** @return array<string, mixed>|null the line, locking its receipt */
    public function lineForUpdate(int $line): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM PUR_GOODS_RECEIPT_LINE WHERE GRL_GOODS_RECEIPT_LINE = :l', ['l' => $line]);

        return false === $row || null === $this->find((int) $row['GRL_GRC_GOODS_RECEIPT'], true) ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function events(int $receipt): array
    {
        return $this->connection->fetchAllAssociative('SELECT e.GRE_EVENT, e.GRE_CREATED_AT, e.GRE_DETAIL, u."USU_Username" AS USERNAME FROM PUR_GOODS_RECEIPT_EVENT e '
            .'LEFT JOIN "INV_USUARIO" u ON u."USU_Usuario" = e.GRE_USU_USER WHERE e.GRE_GRC_GOODS_RECEIPT = :r ORDER BY e.GRE_GOODS_RECEIPT_EVENT', ['r' => $receipt]);
    }

    public function documentExists(int $supplier, string $document): bool
    {
        // Checked across every site: an invoice is unique for the whole company.
        return $this->scope->elevado(fn (): bool => false !== $this->connection->fetchOne(
            "SELECT 1 FROM PUR_GOODS_RECEIPT WHERE GRC_PRV_SUPPLIER = :s AND UPPER(TRIM(GRC_DOCUMENT_NUMBER)) = UPPER(TRIM(:d)) AND GRC_STATUS <> 'CANCELLED' FETCH FIRST 1 ROWS ONLY",
            ['s' => $supplier, 'd' => $document],
        ));
    }

    /** @param array<string, scalar|null> $values */
    public function insertHeader(array $values, string $receivedDate): int
    {
        $id = (int) InsercionOracle::insertar($this->connection, 'PUR_GOODS_RECEIPT', [...$values, 'GRC_RECEIVED_DATE' => "{$receivedDate} 00:00:00"], 'GRC_GOODS_RECEIPT');

        return $id;
    }

    /** @param array<string, scalar|null> $values */
    public function insertLine(array $values): int
    {
        return (int) InsercionOracle::insertar($this->connection, 'PUR_GOODS_RECEIPT_LINE', $values, 'GRL_GOODS_RECEIPT_LINE');
    }

    /** @param array<string, mixed> $detail */
    public function addEvent(int $receipt, string $event, ?string $user, array $detail = []): void
    {
        $this->connection->executeStatement('INSERT INTO PUR_GOODS_RECEIPT_EVENT (GRE_GRC_GOODS_RECEIPT, GRE_EVENT, GRE_USU_USER, GRE_DETAIL) VALUES (:r, :e, :u, :d)', [
            'r' => $receipt, 'e' => $event, 'u' => $user,
            'd' => [] === $detail ? null : mb_substr(json_encode($detail, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR), 0, 2000),
        ]);
    }

    /** @param array<string, scalar|null> $changes column => value */
    public function updateHeader(int $id, array $changes): void
    {
        $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($changes)));
        $this->connection->executeStatement("UPDATE PUR_GOODS_RECEIPT SET {$sets} WHERE GRC_GOODS_RECEIPT = :receipt_id", [...$changes, 'receipt_id' => $id]);
    }

    /** @param array<string, scalar|null> $changes column => value */
    public function updateLine(int $id, array $changes): void
    {
        $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($changes)));
        $this->connection->executeStatement("UPDATE PUR_GOODS_RECEIPT_LINE SET {$sets} WHERE GRL_GOODS_RECEIPT_LINE = :line_id", [...$changes, 'line_id' => $id]);
    }

    public function deleteLines(int $receipt): void
    {
        $this->connection->executeStatement('DELETE FROM PUR_GOODS_RECEIPT_LINE WHERE GRL_GRC_GOODS_RECEIPT = :r', ['r' => $receipt]);
    }

    public function linkUnit(int $line, string $asset): void
    {
        $this->connection->executeStatement('INSERT INTO PUR_GOODS_RECEIPT_UNIT (GRU_GRL_LINE, GRU_ACT_ASSET) VALUES (:l, :a)', ['l' => $line, 'a' => $asset]);
    }

    public function receiptOfAsset(string $asset): ?int
    {
        $id = $this->connection->fetchOne('SELECT l.GRL_GRC_GOODS_RECEIPT FROM PUR_GOODS_RECEIPT_UNIT u JOIN PUR_GOODS_RECEIPT_LINE l ON l.GRL_GOODS_RECEIPT_LINE = u.GRU_GRL_LINE WHERE u.GRU_ACT_ASSET = :a', ['a' => $asset]);

        return false === $id ? null : (int) $id;
    }

    public function hasPendingWork(int $receipt): bool
    {
        $pendingUnits = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM PUR_GOODS_RECEIPT_UNIT u JOIN PUR_GOODS_RECEIPT_LINE l ON l.GRL_GOODS_RECEIPT_LINE = u.GRU_GRL_LINE '
            .'JOIN "INV_ACTIVO" a ON a."ACT_Activo" = u.GRU_ACT_ASSET WHERE l.GRL_GRC_GOODS_RECEIPT = :r AND a."ACT_Pending_Completion" = 1', ['r' => $receipt]);
        $pendingKeys = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM PUR_GOODS_RECEIPT_LINE WHERE GRL_GRC_GOODS_RECEIPT = :r AND GRL_LINE_TYPE = 'LICENSE' AND GRL_KEYS_COMPLETED_AT IS NULL AND NVL(GRL_QUANTITY_RECEIVED, 0) > 0", ['r' => $receipt]);

        return $pendingUnits + $pendingKeys > 0;
    }

    /** @return list<array<string, mixed>> */
    public function pendingUnits(int $limit): array
    {
        [$where, $params] = $this->scope->lectura('INV_ACTIVO', 'a');

        return $this->connection->fetchAllAssociative('SELECT a."ACT_Activo", a."ACT_Codigo_Interno", a."TAC_Tipo_Activo", a."MOD_Modelo", mo."MOD_Nombre", ma."MAR_Nombre", t."TAC_Nombre", '
            .'l.GRL_ITEM_TEXT, g.GRC_CODE, g.GRC_GOODS_RECEIPT, g.GRC_DOCUMENT_NUMBER, s."SED_Nombre" FROM "INV_ACTIVO" a JOIN PUR_GOODS_RECEIPT_UNIT u ON u.GRU_ACT_ASSET = a."ACT_Activo" '
            .'JOIN PUR_GOODS_RECEIPT_LINE l ON l.GRL_GOODS_RECEIPT_LINE = u.GRU_GRL_LINE JOIN PUR_GOODS_RECEIPT g ON g.GRC_GOODS_RECEIPT = l.GRL_GRC_GOODS_RECEIPT '
            .'LEFT JOIN "INV_MODELO" mo ON mo."MOD_Modelo" = a."MOD_Modelo" LEFT JOIN "INV_MARCA" ma ON ma."MAR_Marca" = mo."MAR_Marca" '
            .'LEFT JOIN "INV_TIPO_ACTIVO" t ON t."TAC_Tipo_Activo" = a."TAC_Tipo_Activo" LEFT JOIN "INV_SEDE" s ON s."SED_Sede" = a."SED_Sede" '
            .'WHERE a."ACT_Pending_Completion" = 1 AND '.$where.' ORDER BY g.GRC_GOODS_RECEIPT, a."ACT_Codigo_Interno" FETCH FIRST '.$limit.' ROWS ONLY', $params);
    }

    /** @return list<array<string, mixed>> specification types of an asset type, with the model's factory value and the asset's current value */
    public function specificationsFor(int $assetType, ?int $model, ?string $asset): array
    {
        return $this->connection->fetchAllAssociative('SELECT te."TES_Tipo_Especificacion", te."TES_Nombre", te."TES_Unidad_Medida", te."TES_Data_Type", te."TES_Min_Value", te."TES_Max_Value", '
            .'e."ETA_Obligatoria", m.MSP_DEFAULT_VALUE AS DEFAULT_VALUE, v."ESP_Valor" AS CURRENT_VALUE FROM "INV_ESPECIFICACION_TIPO_ACTIVO" e '
            .'JOIN "INV_TIPO_ESPECIFICACION" te ON te."TES_Tipo_Especificacion" = e."TES_Tipo_Especificacion" '
            .'LEFT JOIN CAT_MODEL_SPECIFICATION m ON m.MSP_MOD_MODEL = :model AND m.MSP_TES_SPECIFICATION_TYPE = te."TES_Tipo_Especificacion" '
            .'LEFT JOIN "INV_ESPECIFICACION" v ON v."ACT_Activo" = :asset AND v."TES_Tipo_Especificacion" = te."TES_Tipo_Especificacion" '
            .'WHERE e."TAC_Tipo_Activo" = :type ORDER BY e."ETA_Orden", te."TES_Nombre"', ['type' => $assetType, 'model' => $model, 'asset' => $asset]);
    }

    /** @return array<string, mixed>|null the last classification given to the same text, to suggest it again */
    public function lastClassification(string $text): ?array
    {
        $row = $this->connection->fetchAssociative("SELECT GRL_LINE_TYPE, GRL_TAC_ASSET_TYPE, GRL_MOD_MODEL, GRL_CON_CONSUMABLE, GRL_CONSUMABLE_NAME, GRL_CONSUMABLE_UNIT, GRL_SOF_SOFTWARE, GRL_TLI_LICENSE_TYPE "
            .'FROM PUR_GOODS_RECEIPT_LINE WHERE GRL_LINE_TYPE IS NOT NULL AND UPPER(TRIM(GRL_ITEM_TEXT)) = UPPER(TRIM(:t)) ORDER BY GRL_GOODS_RECEIPT_LINE DESC FETCH FIRST 1 ROWS ONLY', ['t' => $text]);

        return false === $row ? null : $row;
    }
}
