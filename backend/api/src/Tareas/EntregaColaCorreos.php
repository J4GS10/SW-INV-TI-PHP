<?php

declare(strict_types=1);

namespace App\Tareas;

use App\Config\Ajustes;
use App\Correo\ProcesadorCola;

/**
 * Drena la cola SYS_EMAIL_OUTBOX cada OUTBOX_POLL_SECONDS. No usa cerrojo: la
 * cola admite varios consumidores a la vez (reclamo con SKIP LOCKED y arriendo).
 */
final readonly class EntregaColaCorreos implements TareaPeriodica
{
    public function __construct(private ProcesadorCola $procesador, private Ajustes $ajustes)
    {
    }

    public function nombre(): string
    {
        return 'email_outbox';
    }

    public function cadaSegundos(): int
    {
        return max(1, $this->ajustes->outboxSondeoSegundos);
    }

    public function ejecutar(): ?array
    {
        $intentados = $this->procesador->drenar();

        return 0 === $intentados ? null : ['intentados' => $intentados];
    }
}
