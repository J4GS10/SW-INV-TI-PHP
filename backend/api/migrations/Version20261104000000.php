<?php

declare(strict_types=1);

namespace Migraciones;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Interruptor de MFA obligatorio en la configuración del sistema (por defecto, obligatorio)';
    }

    public function isTransactional(): bool
    {
        // El DDL de Oracle confirma implícitamente cada sentencia.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "SYS_CONFIGURACION" ADD ("SYS_Mfa_Required" NUMBER(1) DEFAULT 1 NOT NULL '
            .'CONSTRAINT "CK_SYS_CONFIGURACION_Mfa_Req" CHECK ("SYS_Mfa_Required" IN (0, 1)))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "SYS_CONFIGURACION" DROP COLUMN "SYS_Mfa_Required"');
    }
}
