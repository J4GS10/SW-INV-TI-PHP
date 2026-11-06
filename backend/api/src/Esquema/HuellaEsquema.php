<?php

declare(strict_types=1);

namespace App\Esquema;

use Doctrine\DBAL\Connection;

/**
 * Descripción normalizada y ordenada del esquema de la conexión (una línea por
 * hecho): tablas y columnas, restricciones, índices, columnas de identidad,
 * objetos PL/SQL (por su fuente normalizada), disparadores, políticas VPD y
 * predicados. Dos bases con el mismo esquema dan la misma huella aunque los
 * nombres generados por Oracle (SYS_C…, ISEQ$$…) o los datos sean distintos.
 *
 * Sirve para comprobar que la línea base reproduce producción y que migrar una
 * base restaurada no cambia nada. Excluye las tablas de registro de migraciones
 * y las de trabajos de Data Pump.
 */
final readonly class HuellaEsquema
{
    private const string EXCLUIDAS = "('SYS_MIGRACION_ESQUEMA', 'ALEMBIC_VERSION')";

    public function __construct(private Connection $conexion)
    {
    }

    /** @return list<string> */
    public function lineas(): array
    {
        $lineas = [...$this->columnas(), ...$this->restricciones(), ...$this->indices(), ...$this->plsql(),
            ...$this->disparadores(), ...$this->politicas(), ...$this->predicados()];
        sort($lineas, \SORT_STRING);

        return $lineas;
    }

    public function resumen(): string
    {
        return hash('sha256', implode("\n", $this->lineas()));
    }

    /** @return list<string> */
    private function columnas(): array
    {
        $filas = $this->conexion->fetchAllAssociative(
            'SELECT c.table_name, c.column_name, c.data_type, c.data_length, c.data_precision, c.data_scale, c.char_used, '
            .'c.char_length, c.nullable, c.identity_column, c.data_default, '
            .'(SELECT i.generation_type FROM user_tab_identity_cols i WHERE i.table_name = c.table_name AND i.column_name = c.column_name) AS identidad '
            .'FROM user_tab_columns c JOIN user_tables t ON t.table_name = c.table_name '
            ."WHERE t.dropped = 'NO' AND c.table_name NOT IN ".self::EXCLUIDAS." AND c.table_name NOT LIKE 'SYS\\_EXPORT\\_%' ESCAPE '\\'");
        $lineas = [];
        foreach ($filas as $f) {
            // El valor por defecto de una identidad nombra su secuencia generada: no se compara.
            $defecto = 'YES' === $f['IDENTITY_COLUMN'] ? '' : self::texto($f['DATA_DEFAULT']);
            $lineas[] = \sprintf('COLUMNA %s.%s %s(%s,%s,%s,%s%s) %s identidad=%s defecto=%s', $f['TABLE_NAME'], $f['COLUMN_NAME'],
                $f['DATA_TYPE'], $f['DATA_LENGTH'], $f['DATA_PRECISION'] ?? '-', $f['DATA_SCALE'] ?? '-', $f['CHAR_USED'] ?? '-',
                null === $f['CHAR_USED'] ? '' : ':'.$f['CHAR_LENGTH'], 'Y' === $f['NULLABLE'] ? 'NULL' : 'NOT NULL',
                $f['IDENTIDAD'] ?? '-', $defecto);
        }

        return $lineas;
    }

    /** @return list<string> */
    private function restricciones(): array
    {
        $columnas = [];
        foreach ($this->conexion->fetchAllAssociative('SELECT constraint_name, column_name FROM user_cons_columns ORDER BY constraint_name, position') as $f) {
            $columnas[(string) $f['CONSTRAINT_NAME']][] = (string) $f['COLUMN_NAME'];
        }
        $filas = $this->conexion->fetchAllAssociative(
            'SELECT c.constraint_name, c.constraint_type, c.table_name, c.search_condition_vc, c.r_constraint_name, c.delete_rule, '
            .'c.status, c.deferrable, c.validated, c.generated, r.table_name AS r_tabla '
            .'FROM user_constraints c LEFT JOIN user_constraints r ON r.constraint_name = c.r_constraint_name '
            ."WHERE c.table_name NOT IN ".self::EXCLUIDAS." AND c.constraint_type IN ('P', 'U', 'R', 'C') "
            ."AND c.table_name NOT LIKE 'SYS\\_EXPORT\\_%' ESCAPE '\\' AND c.table_name NOT LIKE 'BIN$%'");
        $lineas = [];
        foreach ($filas as $f) {
            if ('C' === $f['CONSTRAINT_TYPE']) {
                // Las columnas de un CHECK no tienen posición: se ordenan.
                $propias = $columnas[(string) $f['CONSTRAINT_NAME']] ?? [];
                sort($propias, \SORT_STRING);
                $columnas[(string) $f['CONSTRAINT_NAME']] = $propias;
            }
            $nombre = 'GENERATED NAME' === $f['GENERATED'] ? '*' : (string) $f['CONSTRAINT_NAME'];
            $referencia = 'R' === $f['CONSTRAINT_TYPE']
                ? ' -> '.$f['R_TABLA'].'('.implode(',', $columnas[(string) $f['R_CONSTRAINT_NAME']] ?? []).') '.$f['DELETE_RULE'] : '';
            $lineas[] = \sprintf('RESTRICCION %s %s %s(%s)%s cond=%s %s %s %s', $f['TABLE_NAME'], $f['CONSTRAINT_TYPE'], $nombre,
                implode(',', $columnas[(string) $f['CONSTRAINT_NAME']] ?? []), $referencia, self::texto($f['SEARCH_CONDITION_VC']),
                $f['STATUS'], $f['DEFERRABLE'], $f['VALIDATED']);
        }

        return $lineas;
    }

    /** @return list<string> */
    private function indices(): array
    {
        $expresiones = [];
        foreach ($this->conexion->fetchAllAssociative('SELECT index_name, column_position, column_expression FROM user_ind_expressions') as $f) {
            $expresiones[$f['INDEX_NAME'].'|'.$f['COLUMN_POSITION']] = self::texto($f['COLUMN_EXPRESSION']);
        }
        $columnas = [];
        foreach ($this->conexion->fetchAllAssociative('SELECT index_name, column_name, column_position, descend FROM user_ind_columns ORDER BY index_name, column_position') as $f) {
            $clave = $f['INDEX_NAME'].'|'.$f['COLUMN_POSITION'];
            $columnas[(string) $f['INDEX_NAME']][] = ($expresiones[$clave] ?? (string) $f['COLUMN_NAME']).('DESC' === $f['DESCEND'] ? ' DESC' : '');
        }
        $filas = $this->conexion->fetchAllAssociative(
            "SELECT index_name, table_name, uniqueness, index_type, generated FROM user_indexes WHERE index_type <> 'LOB' "
            ."AND table_name NOT IN ".self::EXCLUIDAS." AND table_name NOT LIKE 'SYS\\_EXPORT\\_%' ESCAPE '\\'");
        $lineas = [];
        foreach ($filas as $f) {
            // Un índice de clave primaria o única sin nombre propio lo nombra Oracle (SYS_C…).
            $nombre = 'Y' === $f['GENERATED'] || str_starts_with((string) $f['INDEX_NAME'], 'SYS_C') ? '*' : (string) $f['INDEX_NAME'];
            $lineas[] = \sprintf('INDICE %s %s %s %s(%s)', $f['TABLE_NAME'], $nombre, $f['UNIQUENESS'],
                str_replace('FUNCTION-BASED ', '', (string) $f['INDEX_TYPE']), implode(',', $columnas[(string) $f['INDEX_NAME']] ?? []));
        }

        return $lineas;
    }

    /** @return list<string> */
    private function plsql(): array
    {
        $fuentes = [];
        foreach ($this->conexion->fetchAllAssociative('SELECT name, type, text FROM user_source ORDER BY name, type, line') as $f) {
            $clave = $f['TYPE'].' '.$f['NAME'];
            $fuentes[$clave] = ($fuentes[$clave] ?? '').$f['TEXT'];
        }
        $estados = [];
        foreach ($this->conexion->fetchAllAssociative("SELECT object_name, object_type, status FROM user_objects WHERE object_type IN ('PACKAGE', 'PACKAGE BODY', 'TRIGGER', 'FUNCTION', 'PROCEDURE', 'TYPE', 'VIEW')") as $f) {
            $estados[$f['OBJECT_TYPE'].' '.$f['OBJECT_NAME']] = (string) $f['STATUS'];
        }
        $lineas = [];
        foreach ($estados as $clave => $estado) {
            $lineas[] = \sprintf('PLSQL %s %s %s', $clave, $estado, hash('sha256', FuentePlsql::normalizar($fuentes[$clave] ?? '')));
        }

        return $lineas;
    }

    /** @return list<string> */
    private function disparadores(): array
    {
        $lineas = [];
        foreach ($this->conexion->fetchAllAssociative('SELECT trigger_name, trigger_type, triggering_event, base_object_type, table_name, status FROM user_triggers') as $f) {
            $lineas[] = \sprintf('DISPARADOR %s %s %s %s %s %s', $f['TRIGGER_NAME'], $f['TRIGGER_TYPE'], trim((string) $f['TRIGGERING_EVENT']),
                trim((string) $f['BASE_OBJECT_TYPE']), $f['TABLE_NAME'] ?? '-', $f['STATUS']);
        }

        return $lineas;
    }

    /** @return list<string> */
    private function politicas(): array
    {
        $lineas = [];
        foreach ($this->conexion->fetchAllAssociative('SELECT object_name, policy_name, package, function, sel, ins, upd, del, idx, chk_option, enable, policy_type FROM user_policies') as $f) {
            $lineas[] = 'POLITICA '.implode(' ', array_map(static fn (mixed $v): string => (string) $v, $f));
        }

        return $lineas;
    }

    /** @return list<string> */
    private function predicados(): array
    {
        if (false === $this->conexion->fetchOne("SELECT 1 FROM user_tables WHERE table_name = 'INV_VPD_PREDICADO'")) {
            return [];
        }
        $lineas = [];
        foreach ($this->conexion->fetchAllAssociative('SELECT "VPD_Tabla", "VPD_Operacion", "VPD_Predicado" FROM "INV_VPD_PREDICADO"') as $f) {
            $lineas[] = "PREDICADO {$f['VPD_Tabla']}/{$f['VPD_Operacion']} ".hash('sha256', (string) $f['VPD_Predicado']);
        }

        return $lineas;
    }

    private static function texto(mixed $valor): string
    {
        return null === $valor ? '' : trim((string) preg_replace('/\s+/', ' ', (string) $valor));
    }
}
