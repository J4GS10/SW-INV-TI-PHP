<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Efectos que no deben retrasar ni revertir la respuesta (p. ej. correos con
 * secretos efímeros): se ejecutan en kernel.terminate, ya enviada la respuesta
 * al cliente. Un fallo se registra y no afecta a los demás.
 */
final class TareasPosteriores implements ResetInterface
{
    /** @var list<array{nombre: string, tarea: \Closure(): void}> */
    private array $pendientes = [];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /** @param \Closure(): void $tarea */
    public function programar(string $nombre, \Closure $tarea): void
    {
        $this->pendientes[] = ['nombre' => $nombre, 'tarea' => $tarea];
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    #[AsEventListener(event: 'console.terminate')]
    public function ejecutar(): void
    {
        while ([] !== $this->pendientes) {
            $pendiente = array_shift($this->pendientes);
            try {
                ($pendiente['tarea'])();
            } catch (\Throwable $e) {
                $this->logger->warning('tarea_posterior.fallida', [
                    'tarea' => $pendiente['nombre'], 'error' => $e::class, 'mensaje' => mb_substr($e->getMessage(), 0, 200),
                ]);
            }
        }
    }

    /** @return list<string> nombres de las tareas en espera (diagnóstico y pruebas) */
    public function pendientes(): array
    {
        return array_column($this->pendientes, 'nombre');
    }

    public function reset(): void
    {
        $this->pendientes = [];
    }
}
