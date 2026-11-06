<?php

declare(strict_types=1);

namespace Migraciones;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Un nombre de host no se repite entre activos vigentes (decisión 6 de la fase 9). */
final class Version20261104000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nombre de host único entre activos vigentes';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX "UIX_ACTIVO_HOSTNAME_VIGENTE" ON "INV_ACTIVO" (CASE WHEN "ACT_Baja_Fecha" IS NULL THEN LOWER(TRIM("ACT_Hostname")) END)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX "UIX_ACTIVO_HOSTNAME_VIGENTE"');
    }
}
