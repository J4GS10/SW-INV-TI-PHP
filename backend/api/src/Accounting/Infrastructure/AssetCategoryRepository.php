<?php

declare(strict_types=1);

namespace App\Accounting\Infrastructure;

use App\Db\InsercionOracle;
use Doctrine\DBAL\Connection;

/** The accounting categories of fixed assets (FIN_ASSET_CATEGORY): all their SQL lives here. */
final readonly class AssetCategoryRepository
{
    private const string COLUMNS = 'c."FAC_ASSET_CATEGORY", c."FAC_CODE", c."FAC_NAME", c."FAC_COUNTRY", c."FAC_METHOD", c."FAC_ANNUAL_RATE", c."FAC_RESIDUAL_PERCENT",
        c."FAC_STARTS_NEXT_MONTH", c."FAC_ACCOUNT_CODE", c."FAC_LEGAL_BASIS", c."FAC_ACTIVE", c."ROW_VERSION",
        (SELECT COUNT(*) FROM "INV_TIPO_ACTIVO" t WHERE t."FAC_ASSET_CATEGORY" = c."FAC_ASSET_CATEGORY") "ASSET_TYPES"';

    public function __construct(private Connection $connection)
    {
    }

    /** @return list<array<string, mixed>> by country, then name */
    public function all(): array
    {
        return $this->connection->fetchAllAssociative('SELECT '.self::COLUMNS.' FROM "FIN_ASSET_CATEGORY" c ORDER BY c."FAC_COUNTRY", c."FAC_NAME"');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT '.self::COLUMNS.' FROM "FIN_ASSET_CATEGORY" c WHERE c."FAC_ASSET_CATEGORY" = :id', ['id' => $id]);

        return false === $row ? null : $row;
    }

    /** Locks the row for an edit: two people saving at once wait for each other. */
    public function lock(int $id): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM "FIN_ASSET_CATEGORY" WHERE "FAC_ASSET_CATEGORY" = :id FOR UPDATE', ['id' => $id]);
    }

    public function isActive(int $id): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM "FIN_ASSET_CATEGORY" WHERE "FAC_ASSET_CATEGORY" = :id AND "FAC_ACTIVE" = 1', ['id' => $id]);
    }

    public function codeTaken(string $country, string $code, ?int $except): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM "FIN_ASSET_CATEGORY" WHERE "FAC_COUNTRY" = :p AND "FAC_CODE" = :c'
            .(null === $except ? '' : ' AND "FAC_ASSET_CATEGORY" <> :id'), ['p' => $country, 'c' => $code, ...(null === $except ? [] : ['id' => $except])]);
    }

    /** @param array<string, scalar|null> $columns */
    public function insert(array $columns): int
    {
        return (int) InsercionOracle::insertar($this->connection, 'FIN_ASSET_CATEGORY', $columns, 'FAC_ASSET_CATEGORY');
    }

    /** @param array<string, scalar|null> $columns */
    public function update(int $id, array $columns): void
    {
        if ([] === $columns) {
            return;
        }
        $set = implode(', ', array_map(static fn (string $c): string => "\"{$c}\" = :{$c}", array_keys($columns)));
        $this->connection->executeStatement("UPDATE \"FIN_ASSET_CATEGORY\" SET {$set} WHERE \"FAC_ASSET_CATEGORY\" = :id", [...$columns, 'id' => $id]);
    }
}
