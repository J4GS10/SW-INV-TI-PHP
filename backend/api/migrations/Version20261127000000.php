<?php

declare(strict_types=1);

namespace Migraciones;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Dirección pública del sistema, configurable por su administrador: con ella se arman los QR de las
 * etiquetas (https://lombardi.inventario.group/activos/LAP00013). Vacía, se usa la del servidor
 * (FRONTEND_BASE_URL). Con un nombre fijo en el DNS, un cambio de IP no obliga a reimprimir.
 */
final class Version20261127000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dirección pública del sistema (QR de las etiquetas), configurable por su administrador';
    }

    public function isTransactional(): bool
    {
        // El DDL de Oracle confirma implícitamente cada sentencia.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "SYS_CONFIGURACION" ADD ("SYS_URL_Publica" VARCHAR2(255 CHAR))');
        $this->addSql("COMMENT ON COLUMN \"SYS_CONFIGURACION\".\"SYS_URL_Publica\" IS 'Dirección por la que se entra al sistema (https://nombre); la usan los QR de las etiquetas. Vacía: la del servidor'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "SYS_CONFIGURACION" DROP ("SYS_URL_Publica")');
    }
}
