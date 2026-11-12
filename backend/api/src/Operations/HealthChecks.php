<?php

declare(strict_types=1);

namespace App\Operations;

use App\Dominio\Reloj;
use App\Operations\Ports\SystemState;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Looks at the system's own state and says what is wrong, with no side effects. The thresholds are here;
 * the facts about the data come through SystemState.
 */
final readonly class HealthChecks
{
    private const float MIN_FREE_DISK_RATIO = 0.10;
    private const int BACKUP_MAX_AGE_HOURS = 3;
    private const string EMAIL_MAX_WAIT = '-30 minutes';
    /** Nobody approves their own request: fewer than this and requests wait for ever. */
    private const int MIN_IT_ADMINISTRATORS = 2;

    public function __construct(
        private SystemState $state,
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
            OperationalAlerts::IT_ADMIN_SINGLE => $this->tooFewItAdministrators(),
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
        $count = $this->state->stuckEmails(Reloj::ahora()->modify(self::EMAIL_MAX_WAIT));

        return $count > 0 ? ['emails' => $count] : null;
    }

    /**
     * Nobody approves their own request: with fewer than two IT administrators able to sign in,
     * disposals, adjustments and reclassifications wait for ever.
     *
     * @return array<string, int|string>|null
     */
    private function tooFewItAdministrators(): ?array
    {
        $count = $this->state->itAdministratorsAbleToSignIn(Reloj::ahora());

        return $count < self::MIN_IT_ADMINISTRATORS ? ['administrators' => $count] : null;
    }

    /** @return array<string, int|string>|null */
    private function stuckCount(): ?array
    {
        $count = $this->state->countsBeingClosed();

        return $count > 0 ? ['counts' => $count] : null;
    }
}
