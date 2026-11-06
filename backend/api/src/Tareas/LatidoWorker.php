<?php

declare(strict_types=1);

namespace App\Tareas;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Señal de vida del worker: se marca tras cada tarea atendida. El healthcheck
 * del contenedor comprueba que sea reciente (la cola de correos corre cada
 * OUTBOX_POLL_SECONDS); un worker colgado deja de marcarlo.
 */
final readonly class LatidoWorker
{
    public function __construct(#[Autowire('%kernel.project_dir%/var/latido_worker')] private string $archivo)
    {
    }

    public function marcar(): void
    {
        @touch($this->archivo);
    }
}
