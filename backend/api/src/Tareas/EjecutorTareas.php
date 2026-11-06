<?php

declare(strict_types=1);

namespace App\Tareas;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Ejecuta una tarea por su nombre: toma el cerrojo si es exclusiva y registra
 * el resultado. Un fallo se registra y no se propaga (como en el backend
 * actual): la tarea vuelve a intentarse en su siguiente turno, sin reintentos
 * de Messenger que la dupliquen.
 */
#[AsMessageHandler]
final readonly class EjecutorTareas
{
    /** @var array<string, TareaPeriodica> */
    private array $tareas;

    /** @param iterable<TareaPeriodica> $tareas */
    public function __construct(
        #[AutowireIterator(TareaPeriodica::ETIQUETA)] iterable $tareas,
        private Cerrojo $cerrojo,
        private LatidoWorker $latido,
        private LoggerInterface $logger,
    ) {
        $porNombre = [];
        foreach ($tareas as $tarea) {
            $porNombre[$tarea->nombre()] = $tarea;
        }
        $this->tareas = $porNombre;
    }

    /** @return array<string, TareaPeriodica> */
    public function tareas(): array
    {
        return $this->tareas;
    }

    public function __invoke(EjecutarTarea $mensaje): void
    {
        // Solo una tarea que terminó bien es señal de vida: con Oracle caído el healthcheck lo ve (fase 8).
        if (null === $this->ejecutar($mensaje->nombre)['error']) {
            $this->latido->marcar();
        }
    }

    /**
     * @return array{ejecutada: bool, resultado: array<string, mixed>|null, error: string|null}
     */
    public function ejecutar(string $nombre): array
    {
        $tarea = $this->tareas[$nombre] ?? throw new \InvalidArgumentException("Tarea desconocida: {$nombre}");
        $clave = "lock:job:{$nombre}";
        $tomado = false;
        try {
            if ($tarea instanceof TareaExclusiva) {
                if (!$this->cerrojo->adquirir($clave, max($tarea->cadaSegundos() - 5, 30))) {
                    return ['ejecutada' => false, 'resultado' => null, 'error' => null];
                }
                $tomado = true;
            }
            $resultado = $tarea->ejecutar();
            if (null !== $resultado) {
                $this->logger->info('job.done', ['job' => $nombre, 'result' => $resultado]);
            }

            return ['ejecutada' => true, 'resultado' => $resultado, 'error' => null];
        } catch (\Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 200);
            $this->logger->warning('job.failed', ['job' => $nombre, 'error' => $error]);
            if ($tomado) {
                // Sin esto, una purga fallida no se reintentaba hasta pasar su intervalo completo (6 h).
                $this->cerrojo->liberar($clave);
            }

            return ['ejecutada' => true, 'resultado' => null, 'error' => $error];
        }
    }
}
