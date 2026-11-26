<?php

declare(strict_types=1);

namespace Migraciones;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Componentes internos obligatorios: el tipo de equipo dice cuáles de los componentes que admite
 * deben registrarse (una laptop pide su memoria, su disco y su batería) y cuántos como mínimo.
 * Las filas que ya existen quedan como opcionales: nada cambia hasta que se configure.
 */
final class Version20261126000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Componentes internos obligatorios por tipo de equipo (y cantidad mínima)';
    }

    public function isTransactional(): bool
    {
        // El DDL de Oracle confirma implícitamente cada sentencia.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "INV_TIPO_COMPONENTE" ADD ("TCP_Obligatorio" NUMBER(1) DEFAULT 0 NOT NULL, "TCP_Cantidad" NUMBER(2) DEFAULT 1 NOT NULL)');
        $this->addSql('ALTER TABLE "INV_TIPO_COMPONENTE" ADD CONSTRAINT "CK_TCP_OBLIGATORIO" CHECK ("TCP_Obligatorio" IN (0, 1))');
        $this->addSql('ALTER TABLE "INV_TIPO_COMPONENTE" ADD CONSTRAINT "CK_TCP_CANTIDAD" CHECK ("TCP_Cantidad" BETWEEN 1 AND 20)');
        $this->addSql("COMMENT ON COLUMN \"INV_TIPO_COMPONENTE\".\"TCP_Obligatorio\" IS 'El equipo de ese tipo debe registrar este componente (puede dejarse para después: queda como pendiente)'");
        $this->addSql("COMMENT ON COLUMN \"INV_TIPO_COMPONENTE\".\"TCP_Cantidad\" IS 'Cuántos componentes de ese tipo como mínimo, si es obligatorio'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "INV_TIPO_COMPONENTE" DROP CONSTRAINT "CK_TCP_CANTIDAD"');
        $this->addSql('ALTER TABLE "INV_TIPO_COMPONENTE" DROP CONSTRAINT "CK_TCP_OBLIGATORIO"');
        $this->addSql('ALTER TABLE "INV_TIPO_COMPONENTE" DROP ("TCP_Obligatorio", "TCP_Cantidad")');
    }
}
