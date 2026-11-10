<?php

declare(strict_types=1);

namespace Migraciones;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Foto de perfil de cada cuenta (una por cuenta, ya recortada y reducida)';
    }

    public function isTransactional(): bool
    {
        // El DDL de Oracle confirma implícitamente cada sentencia.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE "INV_USUARIO_FOTO" (
            "USU_Usuario" CHAR(32 BYTE) NOT NULL,
            "UFO_Imagen" BLOB NOT NULL,
            "UFO_Tipo_MIME" VARCHAR2(30 CHAR) NOT NULL,
            "UFO_Fecha_Modificacion" TIMESTAMP(6) DEFAULT SYSTIMESTAMP NOT NULL,
            CONSTRAINT "PK_INV_USUARIO_FOTO_Usuario" PRIMARY KEY ("USU_Usuario"),
            CONSTRAINT "CK_INV_USUARIO_FOTO_Tipo" CHECK ("UFO_Tipo_MIME" IN (\'image/webp\'))
        )');
        // La PK es la misma columna de la FK: ya es su índice.
        $this->addSql('ALTER TABLE "INV_USUARIO_FOTO" ADD CONSTRAINT "FK_INV_USUARIO_FOTO_Usuario" FOREIGN KEY ("USU_Usuario") REFERENCES "INV_USUARIO" ("USU_Usuario") ON DELETE CASCADE');
        $this->addSql("COMMENT ON TABLE \"INV_USUARIO_FOTO\" IS 'Foto de perfil de la cuenta, cuadrada de 256 px en WebP'");
        $this->addSql("COMMENT ON COLUMN \"INV_USUARIO_FOTO\".\"UFO_Fecha_Modificacion\" IS 'Versión de la foto: cambia la URL y evita servir una copia vieja'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE "INV_USUARIO_FOTO"');
    }
}
