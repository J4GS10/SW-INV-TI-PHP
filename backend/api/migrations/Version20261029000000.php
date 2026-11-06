<?php

declare(strict_types=1);

namespace Migraciones;

use App\Esquema\ScriptSql;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Línea base: el esquema de producción del 2026-10-29 tal cual (linea_base.sql).
 *
 * - Base vacía: crea las tablas, columnas de identidad, índices y restricciones.
 * - Base existente (producción, o una restaurada con Data Pump): no emite SQL;
 *   la migración solo queda registrada en SYS_MIGRACION_ESQUEMA.
 * - Esquema parcial (algunas tablas sí y otras no): se detiene sin tocar nada.
 *
 * Las migraciones posteriores escriben SQL explícito con addSql() y no usan el
 * objeto Schema (así Doctrine no introspecciona el diccionario de Oracle).
 */
final class Version20261029000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Línea base: esquema de producción del 2026-10-29 (tablas, índices y restricciones)';
    }

    public function isTransactional(): bool
    {
        // El DDL de Oracle confirma implícitamente cada sentencia.
        return false;
    }

    public function up(Schema $schema): void
    {
        $script = ScriptSql::desdeArchivo(__DIR__.'/linea_base.sql');
        $tablas = $script->tablasCreadas();
        $existentes = array_map('strval', $this->connection->fetchFirstColumn('SELECT table_name FROM user_tables'));
        $presentes = array_values(array_intersect($tablas, $existentes));

        if (\count($presentes) === \count($tablas)) {
            $this->write(\sprintf('Esquema existente (%d tablas): la línea base se registra sin cambios.', \count($tablas)));

            return;
        }
        $this->abortIf([] !== $presentes, \sprintf(
            'Esquema parcial: existen %d de %d tablas de la línea base (%s…). Revise la base antes de migrar.',
            \count($presentes), \count($tablas), implode(', ', \array_slice($presentes, 0, 5)),
        ));

        foreach ($script->sentencias() as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('La línea base no se revierte: restaure un respaldo de Data Pump.');
    }
}
