<?php

declare(strict_types=1);

namespace Migraciones;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Especificaciones antiguas guardaban la unidad solo como texto: se enlazan con el catálogo de unidades. */
final class Version20261105000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enlaza las especificaciones con su unidad de medida';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE "INV_TIPO_ESPECIFICACION" e
               SET e."UME_Unidad" = (
                   SELECT MIN(u."UME_Unidad") FROM "INV_UNIDAD_MEDIDA" u
                    WHERE LOWER(TRIM(e."TES_Unidad_Medida")) IN (LOWER(u."UME_Simbolo"), LOWER(u."UME_Codigo"), LOWER(u."UME_Nombre")))
             WHERE e."UME_Unidad" IS NULL AND e."TES_Unidad_Medida" IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Solo completa un dato que ya estaba como texto: no hay nada que deshacer.
    }
}
