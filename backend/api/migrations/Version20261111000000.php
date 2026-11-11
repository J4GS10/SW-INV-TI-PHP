<?php

declare(strict_types=1);

namespace Migraciones;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Borrado lógico con Oracle In-Database Archiving (App\Db\LogicalDelete) y bloqueo
 * administrativo de cuentas.
 *
 * ROW ARCHIVAL agrega la columna oculta ORA_ARCHIVE_STATE ('0' = vigente). Una fila
 * archivada no la ve ninguna consulta, pero conserva sus datos y referencias, y
 * Data Pump la respalda y restaura con su estado.
 */
final class Version20261111000000 extends AbstractMigration
{
    /** Registros de negocio que se pueden «eliminar» y los que se van con ellos (ON DELETE CASCADE). */
    private const array TABLAS = [
        'INV_ADJUNTO', 'INV_AREA', 'INV_CARGO', 'INV_CONSUMIBLE', 'INV_DEPARTAMENTO', 'INV_DETALLE_MANT', 'INV_EDIFICIO',
        'INV_ESTADO', 'INV_ESTADO_OPERATIVO', 'INV_EVIDENCIA', 'INV_KIT_ESTANDAR', 'INV_LICENCIA', 'INV_LICENCIA_CLAVE',
        'INV_MANTENIMIENTO', 'INV_MARCA', 'INV_MODELO', 'INV_MOVIMIENTO_CONSUMIBLE', 'INV_MUNICIPIO', 'INV_NIVEL', 'INV_PAIS',
        'INV_PROVEEDOR', 'INV_SEDE', 'INV_SOFTWARE', 'INV_TIPO_ACTIVO', 'INV_TIPO_CONEXION', 'INV_TIPO_ESPECIFICACION',
        'INV_TIPO_LICENCIA', 'INV_TIPO_MANTENIMIENTO', 'INV_TIPO_MOVIMIENTO',
    ];

    public function getDescription(): string
    {
        return 'Borrado lógico (ROW ARCHIVAL) en los registros de negocio y motivo del bloqueo administrativo de una cuenta';
    }

    public function isTransactional(): bool
    {
        // El DDL de Oracle confirma implícitamente cada sentencia.
        return false;
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLAS as $tabla) {
            $this->addSql("ALTER TABLE \"{$tabla}\" ROW ARCHIVAL");
        }
        // Bloqueo administrativo: aparte del bloqueo por intentos fallidos, que se levanta solo o con un cambio de contraseña.
        $this->addSql('ALTER TABLE "INV_USUARIO" ADD ("USU_Bloqueo_Admin_Motivo" VARCHAR2(500 CHAR), "USU_Bloqueo_Admin_Hasta" TIMESTAMP(6))');
        $this->addSql("COMMENT ON COLUMN \"INV_USUARIO\".\"USU_Bloqueo_Admin_Motivo\" IS 'Motivo del bloqueo puesto por el administrador del sistema; vacío = sin bloqueo administrativo'");
        $this->addSql("COMMENT ON COLUMN \"INV_USUARIO\".\"USU_Bloqueo_Admin_Hasta\" IS 'Fin del bloqueo administrativo; vacío con motivo = hasta que el administrador lo quite'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "INV_USUARIO" DROP ("USU_Bloqueo_Admin_Motivo", "USU_Bloqueo_Admin_Hasta")');
        foreach (self::TABLAS as $tabla) {
            $this->addSql("ALTER TABLE \"{$tabla}\" NO ROW ARCHIVAL");
        }
    }
}
