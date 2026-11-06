<?php

declare(strict_types=1);

namespace Migraciones;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** El logo se puede subir como imagen: un enlace para compartir (Drive, OneDrive) no es una imagen. */
final class Version20261104000500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Logo de la empresa guardado en la base';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "SYS_CONFIGURACION" ADD ("SYS_Logo_Image" BLOB)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "SYS_CONFIGURACION" DROP COLUMN "SYS_Logo_Image"');
    }
}
