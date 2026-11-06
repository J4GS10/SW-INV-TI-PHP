<?php

declare(strict_types=1);

namespace Migraciones;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Unifica roles (decisión del 2026-11-04): JEFE_TI pasa a ADMIN_TI y CONTABILIDAD a COMPRAS. La restricción se
 * retira antes de actualizar las cuentas y vuelve sin los roles retirados. El kardex y la bitácora no se modifican.
 */
final class Version20261104000100 extends AbstractMigration
{
    private const string ROLES_ANTES = "'SUPER_ADMIN', 'ADMIN_SEGURIDAD', 'JEFE_TI', 'ADMIN_TI', 'TECNICO', 'AUDITOR', 'CONSULTA', 'COMPRAS', 'CONTABILIDAD'";
    private const string ROLES_AHORA = "'SUPER_ADMIN', 'ADMIN_SEGURIDAD', 'ADMIN_TI', 'TECNICO', 'AUDITOR', 'CONSULTA', 'COMPRAS'";

    public function getDescription(): string
    {
        return 'Unifica JEFE_TI en ADMIN_TI y CONTABILIDAD en COMPRAS';
    }

    public function isTransactional(): bool
    {
        // El DDL de Oracle confirma implícitamente cada sentencia.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "INV_USUARIO" DROP CONSTRAINT "CK_USUARIO_ROL_VALIDO"');
        $this->addSql("UPDATE \"INV_USUARIO\" SET \"USU_Rol\" = 'ADMIN_TI' WHERE \"USU_Rol\" = 'JEFE_TI'");
        $this->addSql("UPDATE \"INV_USUARIO\" SET \"USU_Rol\" = 'COMPRAS' WHERE \"USU_Rol\" = 'CONTABILIDAD'");
        $this->addSql('COMMIT');
        $this->addSql('ALTER TABLE "INV_USUARIO" ADD CONSTRAINT "CK_USUARIO_ROL_VALIDO" CHECK ("USU_Rol" IN ('.self::ROLES_AHORA.'))');
    }

    public function down(Schema $schema): void
    {
        // Irreversible en los datos: las cuentas unificadas no recuperan su rol anterior.
        $this->addSql('ALTER TABLE "INV_USUARIO" DROP CONSTRAINT "CK_USUARIO_ROL_VALIDO"');
        $this->addSql('ALTER TABLE "INV_USUARIO" ADD CONSTRAINT "CK_USUARIO_ROL_VALIDO" CHECK ("USU_Rol" IN ('.self::ROLES_ANTES.'))');
    }
}
