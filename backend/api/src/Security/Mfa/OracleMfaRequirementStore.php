<?php

declare(strict_types=1);

namespace App\Security\Mfa;

use Doctrine\DBAL\Connection;

final readonly class OracleMfaRequirementStore implements MfaRequirementStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function isRequired(): bool
    {
        $stored = $this->connection->fetchOne(
            'SELECT "SYS_Mfa_Required" FROM "SYS_CONFIGURACION" WHERE "SYS_Configuracion" = 1',
        );

        // Fails closed: only an explicit 0 relaxes the requirement.
        return false === $stored || 0 !== (int) $stored;
    }

    public function setRequired(bool $required): void
    {
        $this->connection->executeStatement(
            'MERGE INTO "SYS_CONFIGURACION" t USING (SELECT 1 AS id FROM DUAL) s ON (t."SYS_Configuracion" = s.id) '
            .'WHEN MATCHED THEN UPDATE SET t."SYS_Mfa_Required" = :required '
            .'WHEN NOT MATCHED THEN INSERT ("SYS_Configuracion", "SYS_Mfa_Required") VALUES (1, :required)',
            ['required' => $required ? 1 : 0],
        );
    }
}
