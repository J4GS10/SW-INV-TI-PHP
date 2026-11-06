<?php

declare(strict_types=1);

namespace Migraciones;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** «Disponible» y «En Bodega» eran el mismo estado: queda solo «En Bodega». El kardex conserva su historia tal cual. */
final class Version20261106000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Une los estados Disponible y En Bodega';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE "INV_ESTADO_OPERATIVO" SET "EOP_Nombre" = 'En Bodega', "EOP_Descripcion" = 'Almacenado, listo para entregar'
             WHERE "EOP_Nombre" = 'Disponible'
               AND NOT EXISTS (SELECT 1 FROM "INV_ESTADO_OPERATIVO" WHERE "EOP_Nombre" = 'En Bodega')
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE "INV_ACTIVO" SET "EOP_Estado_Operativo" = (SELECT "EOP_Estado_Operativo" FROM "INV_ESTADO_OPERATIVO" WHERE "EOP_Nombre" = 'En Bodega')
             WHERE "EOP_Estado_Operativo" IN (SELECT "EOP_Estado_Operativo" FROM "INV_ESTADO_OPERATIVO" WHERE "EOP_Nombre" = 'Disponible')
            SQL);
        $this->addSql(<<<'SQL'
            DELETE FROM "INV_ESTADO_OPERATIVO" WHERE "EOP_Nombre" = 'Disponible'
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE "INV_ESTADO_OPERATIVO" SET "EOP_Descripcion" = 'Almacenado, listo para entregar' WHERE "EOP_Nombre" = 'En Bodega'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Los activos unidos no se pueden volver a separar: restaure el respaldo.');
    }
}
