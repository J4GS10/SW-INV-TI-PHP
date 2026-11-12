<?php

declare(strict_types=1);

namespace App\Operations;

use App\Correo\Notificador;
use App\Dominio\Reloj;
use App\Operations\Ports\AlertStore;
use App\Repositorio\GobiernoRepositorio;

/**
 * Turns what HealthChecks finds into alerts someone hears about: each problem has one record, a waiting time so a
 * passing hiccup does not write to anyone, a reminder once a day while it lasts, and a record when it opens and closes.
 * The records are kept through AlertStore.
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
        private AlertStore $store,
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
        foreach ($this->store->openCodes() as $code) {
            if (!isset($problems[$code])) {
                $this->store->resolve($code, $now);
                $this->audit->registrarAuditoria('ALERT_RESOLVED', 'SYS_OPERATIONAL_ALERT', ['codigo' => $code]);
                ++$resolved;
            }
        }

        return ['open' => \count($problems), 'notified' => $notified, 'resolved' => $resolved];
    }

    /** @return list<array<string, mixed>> */
    public function open(): array
    {
        return array_map(static fn (array $alert): array => [
            'code' => $alert['code'], 'message' => self::MESSAGES[$alert['code']] ?? $alert['code'],
            'detail' => $alert['detail'], 'since' => Reloj::iso($alert['firstSeen'] ?? Reloj::ahora()),
        ], $this->store->openAlerts());
    }

    /**
     * @param array<string, int|string> $detail
     *
     * @return bool whether someone was notified now
     */
    private function keepOpen(string $code, array $detail, \DateTimeImmutable $now): bool
    {
        $known = $this->store->find($code);
        $opening = null === $known || !$known['open'];
        if ($opening) {
            $this->store->open($code, $detail, $now);
            $this->audit->registrarAuditoria('ALERT_OPENED', 'SYS_OPERATIONAL_ALERT', ['codigo' => $code, 'detalle' => $detail]);
        } else {
            $this->store->seenAgain($code, $detail, $now);
        }

        $firstSeen = $opening ? $now : ($known['firstSeen'] ?? $now);
        $lastNotified = $opening ? null : $known['lastNotified'];
        if (!self::shouldNotify($code, $firstSeen, $lastNotified, $now)) {
            return false;
        }
        $this->store->notified($code, $now);
        $this->notifier->enviar('alerta_operacion', ['codigo' => $code, 'mensaje' => self::MESSAGES[$code] ?? $code]);

        return true;
    }

    /** The rule on its own: wait before the first notice, then remind once a day while the problem lasts. */
    public static function shouldNotify(string $code, \DateTimeImmutable $firstSeen, ?\DateTimeImmutable $lastNotified, \DateTimeImmutable $now): bool
    {
        $wait = self::WAIT_MINUTES[$code] ?? 0;
        if ($firstSeen > $now->modify("-{$wait} minutes")) {
            return false;
        }

        return null === $lastNotified || $lastNotified <= $now->modify(self::REMIND_AFTER);
    }
}
