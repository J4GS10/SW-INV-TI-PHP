<?php

declare(strict_types=1);

namespace App\Operations;

use App\Correo\Notificador;
use App\Dominio\Reloj;
use App\Repositorio\GobiernoRepositorio;
use Doctrine\DBAL\Connection;

/**
 * Turns what HealthChecks finds into alerts someone hears about: each problem has one row, a waiting time so a
 * passing hiccup does not write to anyone, a reminder once a day while it lasts, and a record when it opens and closes.
 */
final readonly class OperationalAlerts
{
    public const string BACKUP_STALE = 'BACKUP_STALE';
    public const string BACKUP_NO_REPLICA = 'BACKUP_NO_REPLICA';
    public const string DISK_LOW = 'DISK_LOW';
    public const string EMAIL_QUEUE_STUCK = 'EMAIL_QUEUE_STUCK';
    public const string COUNT_CLOSE_STUCK = 'COUNT_CLOSE_STUCK';
    public const string IT_ADMIN_SINGLE = 'IT_ADMIN_SINGLE';

    /** Minutes a problem must last before anyone is notified. */
    private const array WAIT_MINUTES = [self::COUNT_CLOSE_STUCK => 30, self::IT_ADMIN_SINGLE => 60];
    private const string REMIND_AFTER = '-24 hours';

    private const array MESSAGES = [
        self::BACKUP_STALE => 'No hay un respaldo reciente de la base de datos.',
        self::BACKUP_NO_REPLICA => 'Los respaldos están solo en este equipo: si el equipo se daña, se pierden con él.',
        self::DISK_LOW => 'Queda poco espacio en el disco de los respaldos.',
        self::EMAIL_QUEUE_STUCK => 'Hay correos que el sistema no ha logrado enviar.',
        self::COUNT_CLOSE_STUCK => 'Una toma de inventario lleva demasiado tiempo cerrándose.',
        self::IT_ADMIN_SINGLE => 'Hay menos de dos administradores de TI activos: nadie puede aprobar lo que pide el otro.',
    ];

    public function __construct(
        private Connection $connection,
        private HealthChecks $checks,
        private Notificador $notifier,
        private GobiernoRepositorio $audit,
    ) {
    }

    /** @return array{open: int, notified: int, resolved: int} */
    public function evaluate(): array
    {
        $problems = $this->checks->run();
        $now = Reloj::ahora();
        $notified = 0;
        foreach ($problems as $code => $detail) {
            $notified += $this->keepOpen($code, $detail, $now) ? 1 : 0;
        }
        $resolved = 0;
        foreach ($this->connection->fetchFirstColumn("SELECT ALR_CODE FROM SYS_OPERATIONAL_ALERT WHERE ALR_STATUS = 'OPEN'") as $code) {
            if (!isset($problems[(string) $code])) {
                $this->connection->executeStatement("UPDATE SYS_OPERATIONAL_ALERT SET ALR_STATUS = 'RESOLVED', ALR_RESOLVED_AT = :now WHERE ALR_CODE = :code", ['now' => Reloj::aBd($now), 'code' => $code]);
                $this->audit->registrarAuditoria('ALERT_RESOLVED', 'SYS_OPERATIONAL_ALERT', ['codigo' => $code]);
                ++$resolved;
            }
        }

        return ['open' => \count($problems), 'notified' => $notified, 'resolved' => $resolved];
    }

    /** @return list<array<string, mixed>> */
    public function open(): array
    {
        return array_map(static fn (array $row): array => [
            'code' => $row['ALR_CODE'], 'message' => self::MESSAGES[(string) $row['ALR_CODE']] ?? (string) $row['ALR_CODE'],
            'detail' => json_decode((string) $row['ALR_DETAIL'], true), 'since' => Reloj::iso(Reloj::desdeBd((string) $row['ALR_FIRST_SEEN']) ?? Reloj::ahora()),
        ], $this->connection->fetchAllAssociative("SELECT ALR_CODE, ALR_DETAIL, ALR_FIRST_SEEN FROM SYS_OPERATIONAL_ALERT WHERE ALR_STATUS = 'OPEN' ORDER BY ALR_FIRST_SEEN"));
    }

    /** @param array<string, int|string> $detail */
    private function keepOpen(string $code, array $detail, \DateTimeImmutable $now): bool
    {
        $row = $this->connection->fetchAssociative('SELECT ALR_STATUS, ALR_FIRST_SEEN, ALR_LAST_NOTIFIED FROM SYS_OPERATIONAL_ALERT WHERE ALR_CODE = :code', ['code' => $code]);
        $json = json_encode($detail, \JSON_THROW_ON_ERROR);
        $stamp = Reloj::aBd($now);
        $opening = false === $row || 'OPEN' !== $row['ALR_STATUS'];
        if (false === $row) {
            $this->connection->executeStatement("INSERT INTO SYS_OPERATIONAL_ALERT (ALR_CODE, ALR_STATUS, ALR_DETAIL, ALR_FIRST_SEEN, ALR_LAST_SEEN) VALUES (:code, 'OPEN', :detail, :now, :seen)",
                ['code' => $code, 'detail' => $json, 'now' => $stamp, 'seen' => $stamp]);
        } elseif ($opening) {
            $this->connection->executeStatement("UPDATE SYS_OPERATIONAL_ALERT SET ALR_STATUS = 'OPEN', ALR_DETAIL = :detail, ALR_FIRST_SEEN = :now, ALR_LAST_SEEN = :seen, ALR_LAST_NOTIFIED = NULL, ALR_RESOLVED_AT = NULL WHERE ALR_CODE = :code",
                ['code' => $code, 'detail' => $json, 'now' => $stamp, 'seen' => $stamp]);
        } else {
            $this->connection->executeStatement('UPDATE SYS_OPERATIONAL_ALERT SET ALR_DETAIL = :detail, ALR_LAST_SEEN = :seen WHERE ALR_CODE = :code', ['code' => $code, 'detail' => $json, 'seen' => $stamp]);
        }
        if ($opening) {
            $this->audit->registrarAuditoria('ALERT_OPENED', 'SYS_OPERATIONAL_ALERT', ['codigo' => $code, 'detalle' => $detail]);
        }

        $firstSeen = $opening ? $now : (Reloj::desdeBd((string) $row['ALR_FIRST_SEEN']) ?? $now);
        $wait = self::WAIT_MINUTES[$code] ?? 0;
        if ($firstSeen > $now->modify("-{$wait} minutes")) {
            return false;
        }
        $lastNotified = $opening || null === $row['ALR_LAST_NOTIFIED'] ? null : Reloj::desdeBd((string) $row['ALR_LAST_NOTIFIED']);
        if (null !== $lastNotified && $lastNotified > $now->modify(self::REMIND_AFTER)) {
            return false;
        }
        $this->connection->executeStatement('UPDATE SYS_OPERATIONAL_ALERT SET ALR_LAST_NOTIFIED = :now WHERE ALR_CODE = :code', ['now' => $stamp, 'code' => $code]);
        $this->notifier->enviar('alerta_operacion', ['codigo' => $code, 'mensaje' => self::MESSAGES[$code] ?? $code]);

        return true;
    }
}
