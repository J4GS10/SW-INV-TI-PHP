<?php

declare(strict_types=1);

namespace App\Db;

use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;

/**
 * Business records are never deleted: they are archived with Oracle In-Database
 * Archiving (ROW ARCHIVAL). An archived row keeps its data, its history and its
 * references, the backups keep it too, but no query sees it any more
 * (ORA_ARCHIVE_STATE <> '0'). The database refuses a physical DELETE on those
 * tables during a request (App\Esquema\DefinicionSeguridad, ERR_BORRADO_FISICO).
 *
 * Archiving does what a physical delete would have done, without losing anything:
 * - a row of another table that still points to it (NO ACTION) stops it with
 *   RecordInUse. It looks in every site: a foreign key does not ignore them either;
 * - rows that belonged to it (ON DELETE CASCADE) are archived with it when their
 *   table archives too (ticket details, license keys, attachments…); rows that are
 *   only links between records (sites of a user, compatibilities) are removed;
 * - references that a delete would have emptied (SET NULL) are kept: they are history.
 *
 * The row is locked first: two deletes (or a delete and an edit) at the same time wait for each other.
 */
final readonly class LogicalDelete
{
    public const string ARCHIVED = '1';

    /**
     * Tables with ROW ARCHIVAL (migration Version20261111000000). The database refuses a
     * physical DELETE on them while a user is set (App\Esquema\DefinicionSeguridad).
     */
    public const array TABLES = [
        'INV_ADJUNTO', 'INV_AREA', 'INV_CARGO', 'INV_CONSUMIBLE', 'INV_DEPARTAMENTO', 'INV_DETALLE_MANT', 'INV_EDIFICIO',
        'INV_ESTADO', 'INV_ESTADO_OPERATIVO', 'INV_EVIDENCIA', 'INV_KIT_ESTANDAR', 'INV_LICENCIA', 'INV_LICENCIA_CLAVE',
        'INV_MANTENIMIENTO', 'INV_MARCA', 'INV_MODELO', 'INV_MOVIMIENTO_CONSUMIBLE', 'INV_MUNICIPIO', 'INV_NIVEL', 'INV_PAIS',
        'INV_PROVEEDOR', 'INV_SEDE', 'INV_SOFTWARE', 'INV_TIPO_ACTIVO', 'INV_TIPO_CONEXION', 'INV_TIPO_ESPECIFICACION',
        'INV_TIPO_LICENCIA', 'INV_TIPO_MANTENIMIENTO', 'INV_TIPO_MOVIMIENTO',
    ];

    public function __construct(private Connection $connection, private SchemaReferences $schema, private Alcance $scope)
    {
    }

    /**
     * Must run inside the caller's transaction.
     *
     * @return bool false if the row does not exist or was already deleted
     *
     * @throws RecordInUse
     */
    public function archive(string $table, string $key, int|string $id): bool
    {
        if (!$this->schema->isArchival($table)) {
            throw new \LogicException("{$table} has no ROW ARCHIVAL: its rows cannot be deleted logically.");
        }
        if (false === $this->connection->fetchOne("SELECT 1 FROM \"{$table}\" WHERE \"{$key}\" = :id FOR UPDATE", ['id' => $id])) {
            return false;
        }
        $children = $this->schema->childrenOf($table, $key);
        foreach ($children as $child) {
            if ('NO ACTION' === $child['rule'] && $this->scope->elevado(fn (): bool => false !== $this->connection->fetchOne(
                "SELECT 1 FROM \"{$child['table']}\" WHERE \"{$child['column']}\" = :id FETCH FIRST 1 ROWS ONLY", ['id' => $id]))) {
                throw new RecordInUse($table, $child['table']);
            }
        }
        $this->connection->executeStatement("UPDATE \"{$table}\" SET ORA_ARCHIVE_STATE = '".self::ARCHIVED."' WHERE \"{$key}\" = :id", ['id' => $id]);
        foreach ($children as $child) {
            if ('CASCADE' !== $child['rule']) {
                continue;
            }
            $childKey = $this->schema->isArchival($child['table']) ? $this->schema->primaryKey($child['table']) : null;
            if (null === $childKey) {
                $this->connection->executeStatement("DELETE FROM \"{$child['table']}\" WHERE \"{$child['column']}\" = :id", ['id' => $id]);
                continue;
            }
            foreach ($this->connection->fetchFirstColumn("SELECT \"{$childKey}\" FROM \"{$child['table']}\" WHERE \"{$child['column']}\" = :id", ['id' => $id]) as $childId) {
                $this->archive($child['table'], $childKey, \is_int($childId) || \is_string($childId) ? $childId : (string) $childId);
            }
        }

        return true;
    }
}
