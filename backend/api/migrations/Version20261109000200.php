<?php

declare(strict_types=1);

namespace Migraciones;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MFA: la persona puede omitirlo una sola vez, en su primer ingreso (fecha en que lo omitió)';
    }

    public function isTransactional(): bool
    {
        // El DDL de Oracle confirma implícitamente cada sentencia.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "INV_USUARIO" ADD ("USU_MFA_Omitido_En" TIMESTAMP)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "INV_USUARIO" DROP COLUMN "USU_MFA_Omitido_En"');
    }
}
