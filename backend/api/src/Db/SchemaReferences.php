<?php

declare(strict_types=1);

namespace App\Db;

use Doctrine\DBAL\Connection;

/**
 * What the dictionary says about the schema that owns the data: foreign keys that
 * point to a table, its primary key, column lengths and whether it archives rows.
 *
 * It reads the ALL_* views filtered by the current schema: the API connects as
 * INVENTARIO_APP, which owns nothing, so its USER_* views are empty. The schema
 * does not change while a worker lives (a deploy restarts it), so answers are cached.
 */
final class SchemaReferences
{
    private const string OWNER = "SYS_CONTEXT('USERENV', 'CURRENT_SCHEMA')";

    /** @var array<string, list<array{table: string, column: string, rule: string}>> */
    private array $children = [];
    /** @var array<string, bool> */
    private array $archival = [];
    /** @var array<string, string|null> */
    private array $primaryKeys = [];
    /** @var array<string, int|null> */
    private array $lengths = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Foreign keys of other tables that point to `$table.$column`.
     *
     * @return list<array{table: string, column: string, rule: string}> rule: NO ACTION, CASCADE or SET NULL
     */
    public function childrenOf(string $table, string $column): array
    {
        return $this->children["{$table}.{$column}"] ??= array_map(
            static fn (array $r): array => ['table' => (string) $r['CHILD_TABLE'], 'column' => (string) $r['CHILD_COLUMN'], 'rule' => (string) $r['DELETE_RULE']],
            $this->connection->fetchAllAssociative(
                'SELECT hc.table_name AS "CHILD_TABLE", hc.column_name AS "CHILD_COLUMN", h.delete_rule AS "DELETE_RULE" FROM all_constraints h '
                .'JOIN all_cons_columns hc ON hc.owner = h.owner AND hc.constraint_name = h.constraint_name '
                .'JOIN all_constraints pa ON pa.owner = h.r_owner AND pa.constraint_name = h.r_constraint_name '
                .'JOIN all_cons_columns pc ON pc.owner = pa.owner AND pc.constraint_name = pa.constraint_name AND pc.position = hc.position '
                .'WHERE h.owner = '.self::OWNER." AND h.constraint_type = 'R' AND pa.table_name = :t AND pc.column_name = :c AND h.table_name <> :t "
                .'ORDER BY hc.table_name, hc.column_name',
                ['t' => $table, 'c' => $column],
            ),
        );
    }

    /** Does the table use Oracle In-Database Archiving (ROW ARCHIVAL)? */
    public function isArchival(string $table): bool
    {
        return $this->archival[$table] ??= false !== $this->connection->fetchOne(
            'SELECT 1 FROM all_tab_cols WHERE owner = '.self::OWNER." AND table_name = :t AND column_name = 'ORA_ARCHIVE_STATE'",
            ['t' => $table],
        );
    }

    /** Single-column primary key, or null if the table has none (or a composite one). */
    public function primaryKey(string $table): ?string
    {
        if (!\array_key_exists($table, $this->primaryKeys)) {
            $columns = $this->connection->fetchFirstColumn(
                'SELECT cc.column_name FROM all_constraints c JOIN all_cons_columns cc ON cc.owner = c.owner AND cc.constraint_name = c.constraint_name '
                .'WHERE c.owner = '.self::OWNER." AND c.table_name = :t AND c.constraint_type = 'P'",
                ['t' => $table],
            );
            $this->primaryKeys[$table] = 1 === \count($columns) ? (string) $columns[0] : null;
        }

        return $this->primaryKeys[$table];
    }

    /** Length in characters of a text column (null if it is not text or does not exist). */
    public function charLength(string $table, string $column): ?int
    {
        if (!\array_key_exists("{$table}.{$column}", $this->lengths)) {
            $length = $this->connection->fetchOne(
                'SELECT char_length FROM all_tab_columns WHERE owner = '.self::OWNER.' AND table_name = :t AND column_name = :c',
                ['t' => $table, 'c' => $column],
            );
            $this->lengths["{$table}.{$column}"] = false === $length || 0 === (int) $length ? null : (int) $length;
        }

        return $this->lengths["{$table}.{$column}"];
    }
}
