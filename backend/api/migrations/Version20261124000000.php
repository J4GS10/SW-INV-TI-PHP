<?php

declare(strict_types=1);

namespace Migraciones;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Concurrencia optimista en la edición de registros: cada tabla editable recibe ROW_VERSION, que
 * el disparador TRG_RV_<TABLA> de la capa de seguridad (App\Esquema\DefinicionSeguridad) sube en
 * cada UPDATE. Una edición que llega con la versión que cargó la pantalla, y que ya no es la vigente,
 * se rechaza con 412 (App\Db\RowVersions): nadie deshace sin saberlo lo que otro guardó.
 *
 * ADD con DEFAULT y NOT NULL es solo de diccionario en Oracle 21c: no reescribe las filas ni bloquea la tabla.
 */
final class Version20261124000000 extends AbstractMigration
{
    private const array TABLAS = [
        'INV_ACCION_MANTENIMIENTO', 'INV_ACTIVO', 'INV_AREA', 'INV_CARGO', 'INV_CATEGORIA_CONSUMIBLE', 'INV_CONSUMIBLE',
        'INV_DEPARTAMENTO', 'INV_DESTINO_BAJA', 'INV_DETALLE_MANT', 'INV_EDIFICIO', 'INV_ESTADO', 'INV_ESTADO_OPERATIVO',
        'INV_FORMA_CONTEO', 'INV_KIT_ESTANDAR', 'INV_LICENCIA', 'INV_LICENCIA_CLAVE', 'INV_MARCA', 'INV_METODO_ACTIVACION',
        'INV_MODELO', 'INV_MODELO_COMERCIAL', 'INV_MOTIVO_BAJA', 'INV_MOTIVO_MOVIMIENTO', 'INV_MUNICIPIO', 'INV_NIVEL',
        'INV_PAIS', 'INV_PERSONA', 'INV_PROVEEDOR', 'INV_SEDE', 'INV_SOFTWARE', 'INV_TIPO_ACTIVO', 'INV_TIPO_AREA',
        'INV_TIPO_CONEXION', 'INV_TIPO_DOCUMENTO', 'INV_TIPO_ESPECIFICACION', 'INV_TIPO_EVIDENCIA', 'INV_TIPO_FALLA',
        'INV_TIPO_LICENCIA', 'INV_TIPO_MANTENIMIENTO', 'INV_UNIDAD_MEDIDA', 'INV_USUARIO',
    ];

    public function getDescription(): string
    {
        return 'Versión de fila (ROW_VERSION) en los registros editables: concurrencia optimista';
    }

    public function isTransactional(): bool
    {
        // El DDL de Oracle confirma implícitamente cada sentencia.
        return false;
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLAS as $tabla) {
            $this->addSql("ALTER TABLE \"{$tabla}\" ADD (\"ROW_VERSION\" NUMBER(12) DEFAULT 1 NOT NULL)");
            $this->addSql("COMMENT ON COLUMN \"{$tabla}\".\"ROW_VERSION\" IS 'Sube en cada cambio guardado (disparador TRG_RV_{$tabla}); una edición con una versión anterior se rechaza'");
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLAS as $tabla) {
            // El disparador depende de la columna: sin ella quedaría inválido y rechazaría cada UPDATE.
            $this->addSql("BEGIN EXECUTE IMMEDIATE 'DROP TRIGGER \"TRG_RV_{$tabla}\"'; EXCEPTION WHEN OTHERS THEN IF SQLCODE <> -4080 THEN RAISE; END IF; END;");
            $this->addSql("ALTER TABLE \"{$tabla}\" DROP COLUMN \"ROW_VERSION\"");
        }
    }
}
