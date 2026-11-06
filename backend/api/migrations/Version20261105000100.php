<?php

declare(strict_types=1);

namespace Migraciones;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Alertas de operación: un problema, una fila; recuerda cuándo se abrió, cuándo se avisó y cuándo se cerró. */
final class Version20261105000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Alertas de operación';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE SYS_OPERATIONAL_ALERT (
            ALR_Code VARCHAR2(40) NOT NULL,
            ALR_Status VARCHAR2(10) DEFAULT 'OPEN' NOT NULL,
            ALR_Detail VARCHAR2(500),
            ALR_First_Seen TIMESTAMP(6) NOT NULL,
            ALR_Last_Seen TIMESTAMP(6) NOT NULL,
            ALR_Last_Notified TIMESTAMP(6),
            ALR_Resolved_At TIMESTAMP(6),
            CONSTRAINT PK_SYS_OPERATIONAL_ALERT_Code PRIMARY KEY (ALR_Code),
            CONSTRAINT CK_SYS_OPERATIONAL_ALERT_Status CHECK (ALR_Status IN ('OPEN', 'RESOLVED'))
        )");
        $this->addSql("COMMENT ON TABLE SYS_OPERATIONAL_ALERT IS 'Problemas de operación detectados por el worker'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE SYS_OPERATIONAL_ALERT');
    }
}
