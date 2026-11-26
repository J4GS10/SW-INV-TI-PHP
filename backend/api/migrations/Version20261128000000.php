<?php

declare(strict_types=1);

namespace Migraciones;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rol «Escáner» y código de operador.
 * - ESCANER: la cuenta de un teléfono o tableta de bodega; ve el equipo escaneado y, para cambiar algo,
 *   pide el código de operador de quien lo hace.
 * - Código de operador (TI y técnicos): 6 dígitos con hash; con él, el cambio queda a nombre de su dueño.
 *   Se bloquea tras 5 intentos fallidos.
 */
final class Version20261128000000 extends AbstractMigration
{
    /** Los roles vigentes desde Version20261104000100. */
    private const string ROLES_ANTES = "'SUPER_ADMIN', 'ADMIN_SEGURIDAD', 'ADMIN_TI', 'TECNICO', 'AUDITOR', 'CONSULTA', 'COMPRAS'";

    public function getDescription(): string
    {
        return 'Rol ESCANER y código de operador de 6 dígitos (TI y técnicos)';
    }

    public function isTransactional(): bool
    {
        // El DDL de Oracle confirma implícitamente cada sentencia.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "INV_USUARIO" DROP CONSTRAINT "CK_USUARIO_ROL_VALIDO"');
        $this->addSql('ALTER TABLE "INV_USUARIO" ADD CONSTRAINT "CK_USUARIO_ROL_VALIDO" CHECK ("USU_Rol" IN ('.self::ROLES_ANTES.", 'ESCANER'))");
        $this->addSql('ALTER TABLE "INV_USUARIO" ADD ("USU_Codigo_Operador_Hash" VARCHAR2(255 CHAR), "USU_Codigo_Operador_En" TIMESTAMP(6), '
            .'"USU_Codigo_Intentos" NUMBER(2) DEFAULT 0 NOT NULL, "USU_Codigo_Bloqueado_Hasta" TIMESTAMP(6))');
        $this->addSql("COMMENT ON COLUMN \"INV_USUARIO\".\"USU_Codigo_Operador_Hash\" IS 'Hash (Argon2id) del código de operador de 6 dígitos; nunca el código'");
        $this->addSql("COMMENT ON COLUMN \"INV_USUARIO\".\"USU_Codigo_Operador_En\" IS 'Cuándo se definió el código de operador'");
        $this->addSql("COMMENT ON COLUMN \"INV_USUARIO\".\"USU_Codigo_Intentos\" IS 'Intentos fallidos seguidos del código de operador'");
        $this->addSql("COMMENT ON COLUMN \"INV_USUARIO\".\"USU_Codigo_Bloqueado_Hasta\" IS 'El código no se acepta hasta esta fecha (tras 5 intentos fallidos)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "INV_USUARIO" DROP ("USU_Codigo_Operador_Hash", "USU_Codigo_Operador_En", "USU_Codigo_Intentos", "USU_Codigo_Bloqueado_Hasta")');
        $this->addSql('ALTER TABLE "INV_USUARIO" DROP CONSTRAINT "CK_USUARIO_ROL_VALIDO"');
        $this->addSql('ALTER TABLE "INV_USUARIO" ADD CONSTRAINT "CK_USUARIO_ROL_VALIDO" CHECK ("USU_Rol" IN ('.self::ROLES_ANTES.'))');
    }
}
