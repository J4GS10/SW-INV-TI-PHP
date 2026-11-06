<?php

declare(strict_types=1);

namespace App\Operations;

use App\Correo\CorreoEnCola;
use App\Dominio\Reloj;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Looks at the system's own state and says what is wrong, with no side effects. */
final readonly class HealthChecks
{
    private const float MIN_FREE_DISK_RATIO = 0.10;
    private const int BACKUP_MAX_AGE_HOURS = 3;

    public function __construct(
        private Connection $connection,
        #[Autowire('%env(default::BACKUP_ALERT_DIR)%')] private ?string $backupDir = null,
        #[Autowire('%env(default::BACKUP_REPLICA_CONFIGURED)%')] private ?string $replicaConfigured = null,
    ) {
    }

    /** @return array<string, array<string, int|string>> problem code => details; a code that is absent is healthy */
    public function run(): array
    {
        return array_filter([
            OperationalAlerts::BACKUP_STALE => $this->staleBackup(),
            OperationalAlerts::BACKUP_NO_REPLICA => $this->missingReplica(),
            OperationalAlerts::DISK_LOW => $this->lowDisk(),
            OperationalAlerts::EMAIL_QUEUE_STUCK => $this->stuckEmail(),
            OperationalAlerts::COUNT_CLOSE_STUCK => $this->stuckCount(),
        ], static fn (?array $problem): bool => null !== $problem);
    }

    /** @return array<string, int|string>|null */
    private function staleBackup(): ?array
    {
        if (null === $this->backupDir || '' === $this->backupDir) {
            return null;
        }
        $files = glob($this->backupDir.'/inventario_*.dmp') ?: [];
        if ([] === $files) {
            return ['hours' => -1];
        }
        $newest = max(array_map(static fn (string $file): int => (int) filemtime($file), $files));
        $hours = (int) floor((time() - $newest) / 3600);

        return $hours >= self::BACKUP_MAX_AGE_HOURS ? ['hours' => $hours] : null;
    }

    /** @return array<string, int|string>|null */
    private function missingReplica(): ?array
    {
        if (null === $this->backupDir || '' === $this->backupDir || '' !== (string) $this->replicaConfigured) {
            return null;
        }

        return ['location' => 'same_machine'];
    }

    /** @return array<string, int|string>|null */
    private function lowDisk(): ?array
    {
        if (null === $this->backupDir || '' === $this->backupDir || !is_dir($this->backupDir)) {
            return null;
        }
        $total = @disk_total_space($this->backupDir);
        $free = @disk_free_space($this->backupDir);
        if (false === $total || false === $free || $total <= 0.0) {
            return null;
        }

        return $free / $total < self::MIN_FREE_DISK_RATIO ? ['free_percent' => (int) floor(100 * $free / $total)] : null;
    }

    /** @return array<string, int|string>|null */
    private function stuckEmail(): ?array
    {
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM "SYS_EMAIL_OUTBOX" WHERE ("EOB_Estado" = :pending AND "EOB_Proximo_Intento" < :limit) OR "EOB_Estado" = :failed',
            ['pending' => CorreoEnCola::PENDIENTE, 'failed' => CorreoEnCola::FALLIDO, 'limit' => Reloj::aBd(Reloj::ahora()->modify('-30 minutes'))]);

        return $count > 0 ? ['emails' => $count] : null;
    }

    /** @return array<string, int|string>|null */
    private function stuckCount(): ?array
    {
        $count = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM \"INV_TOMA_INVENTARIO\" WHERE \"TOM_Estado\" = 'CERRANDO'");

        return $count > 0 ? ['counts' => $count] : null;
    }
}
