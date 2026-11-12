<?php

declare(strict_types=1);

namespace App\Repositorio;

use App\Correo\CorreoEnCola;
use App\Dominio\Reloj;
use App\Dominio\Roles;
use App\Operations\Ports\AlertStore;
use App\Operations\Ports\SystemState;
use Doctrine\DBAL\Connection;

/** Operational alerts and the state the health checks read, in Oracle: all their SQL lives here. */
final readonly class OperationsOracle implements SystemState, AlertStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function stuckEmails(\DateTimeImmutable $waitingSince): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM "SYS_EMAIL_OUTBOX" WHERE ("EOB_Estado" = :pending AND "EOB_Proximo_Intento" < :limit) OR "EOB_Estado" = :failed',
            ['pending' => CorreoEnCola::PENDIENTE, 'failed' => CorreoEnCola::FALLIDO, 'limit' => Reloj::aBd($waitingSince)]);
    }

    public function itAdministratorsAbleToSignIn(\DateTimeImmutable $now): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM "INV_USUARIO" WHERE "USU_Rol" = :role AND "USU_Estado" = 1 '
            .'AND ("USU_Bloqueo_Admin_Motivo" IS NULL OR "USU_Bloqueo_Admin_Hasta" < :now)',
            ['role' => Roles::ADMIN_TI, 'now' => Reloj::aBd($now)]);
    }

    public function countsBeingClosed(): int
    {
        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM \"INV_TOMA_INVENTARIO\" WHERE \"TOM_Estado\" = 'CERRANDO'");
    }

    public function find(string $code): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT ALR_STATUS, ALR_FIRST_SEEN, ALR_LAST_NOTIFIED FROM SYS_OPERATIONAL_ALERT WHERE ALR_CODE = :code', ['code' => $code]);
        if (false === $row) {
            return null;
        }

        return [
            'open' => 'OPEN' === $row['ALR_STATUS'],
            'firstSeen' => null === $row['ALR_FIRST_SEEN'] ? null : Reloj::desdeBd((string) $row['ALR_FIRST_SEEN']),
            'lastNotified' => null === $row['ALR_LAST_NOTIFIED'] ? null : Reloj::desdeBd((string) $row['ALR_LAST_NOTIFIED']),
        ];
    }

    public function open(string $code, array $detail, \DateTimeImmutable $now): void
    {
        $values = ['code' => $code, 'detail' => json_encode($detail, \JSON_THROW_ON_ERROR), 'now' => Reloj::aBd($now), 'seen' => Reloj::aBd($now)];
        $reopened = $this->connection->executeStatement(
            "UPDATE SYS_OPERATIONAL_ALERT SET ALR_STATUS = 'OPEN', ALR_DETAIL = :detail, ALR_FIRST_SEEN = :now, ALR_LAST_SEEN = :seen, ALR_LAST_NOTIFIED = NULL, ALR_RESOLVED_AT = NULL WHERE ALR_CODE = :code", $values);
        if (0 === $reopened) {
            $this->connection->executeStatement("INSERT INTO SYS_OPERATIONAL_ALERT (ALR_CODE, ALR_STATUS, ALR_DETAIL, ALR_FIRST_SEEN, ALR_LAST_SEEN) VALUES (:code, 'OPEN', :detail, :now, :seen)", $values);
        }
    }

    public function seenAgain(string $code, array $detail, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement('UPDATE SYS_OPERATIONAL_ALERT SET ALR_DETAIL = :detail, ALR_LAST_SEEN = :seen WHERE ALR_CODE = :code',
            ['detail' => json_encode($detail, \JSON_THROW_ON_ERROR), 'seen' => Reloj::aBd($now), 'code' => $code]);
    }

    public function notified(string $code, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement('UPDATE SYS_OPERATIONAL_ALERT SET ALR_LAST_NOTIFIED = :now WHERE ALR_CODE = :code', ['now' => Reloj::aBd($now), 'code' => $code]);
    }

    public function resolve(string $code, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement("UPDATE SYS_OPERATIONAL_ALERT SET ALR_STATUS = 'RESOLVED', ALR_RESOLVED_AT = :now WHERE ALR_CODE = :code", ['now' => Reloj::aBd($now), 'code' => $code]);
    }

    public function openCodes(): array
    {
        return array_map(strval(...), $this->connection->fetchFirstColumn("SELECT ALR_CODE FROM SYS_OPERATIONAL_ALERT WHERE ALR_STATUS = 'OPEN'"));
    }

    public function openAlerts(): array
    {
        return array_map(static fn (array $row): array => [
            'code' => (string) $row['ALR_CODE'],
            'detail' => json_decode((string) $row['ALR_DETAIL'], true),
            'firstSeen' => Reloj::desdeBd((string) $row['ALR_FIRST_SEEN']),
        ], $this->connection->fetchAllAssociative("SELECT ALR_CODE, ALR_DETAIL, ALR_FIRST_SEEN FROM SYS_OPERATIONAL_ALERT WHERE ALR_STATUS = 'OPEN' ORDER BY ALR_FIRST_SEEN"));
    }
}
