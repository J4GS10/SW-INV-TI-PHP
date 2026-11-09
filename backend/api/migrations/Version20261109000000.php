<?php

declare(strict_types=1);

namespace Migraciones;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tamaño del logo en el menú y en el inicio de sesión (porcentaje del tamaño base)';
    }

    public function isTransactional(): bool
    {
        // El DDL de Oracle confirma implícitamente cada sentencia.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "SYS_CONFIGURACION" ADD ("SYS_Logo_Tamano" NUMBER(3) DEFAULT 100 NOT NULL '
            .'CONSTRAINT "CK_SYS_CONFIGURACION_Logo_Tam" CHECK ("SYS_Logo_Tamano" BETWEEN 50 AND 250))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "SYS_CONFIGURACION" DROP COLUMN "SYS_Logo_Tamano"');
    }
}
