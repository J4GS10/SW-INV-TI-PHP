<?php

declare(strict_types=1);

namespace App\Tareas;

use App\Operations\OperationalAlerts;

/** Checks every five minutes that backups, disk, mail and count closings are healthy, and warns if not. */
final readonly class RevisionOperativa implements TareaExclusiva
{
    public function __construct(private OperationalAlerts $alerts)
    {
    }

    public function nombre(): string
    {
        return 'operational_alerts';
    }

    public function cadaSegundos(): int
    {
        return 300;
    }

    /** @return array<string, int>|null */
    public function ejecutar(): ?array
    {
        $result = $this->alerts->evaluate();

        return 0 === $result['open'] && 0 === $result['resolved'] ? null : $result;
    }
}
