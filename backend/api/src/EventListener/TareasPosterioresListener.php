<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Tareas\TareasPosteriores;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;

/** Ejecuta las tareas diferidas cuando la respuesta ya salió hacia el cliente, o al terminar un comando de consola. */
final readonly class TareasPosterioresListener
{
    public function __construct(private TareasPosteriores $tareas)
    {
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    #[AsEventListener(event: 'console.terminate')]
    public function __invoke(): void
    {
        $this->tareas->ejecutar();
    }
}
