<?php

declare(strict_types=1);

namespace App\Tareas;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Programa del worker (transporte `scheduler_tareas`):
 *
 *   php bin/console messenger:consume scheduler_tareas
 *
 * Cada tarea se dispara cada `cadaSegundos()` desde que arranca el worker.
 */
#[AsSchedule('tareas')]
final readonly class ProgramaTareas implements ScheduleProviderInterface
{
    /** Las tareas corren en múltiplos de su intervalo contados desde aquí (UTC). */
    private const string ANCLA = '2026-01-01T00:00:00+00:00';

    /** @param iterable<TareaPeriodica> $tareas */
    public function __construct(#[AutowireIterator(TareaPeriodica::ETIQUETA)] private iterable $tareas)
    {
    }

    public function getSchedule(): Schedule
    {
        $programa = new Schedule();
        foreach ($this->tareas as $tarea) {
            // Anclado a una hora fija (no al arranque): con despliegues más seguidos que el intervalo,
            // una tarea larga (purga cada 6 h) ya no se posterga sin fin (fase 8).
            $programa->add(RecurringMessage::every("{$tarea->cadaSegundos()} seconds", new EjecutarTarea($tarea->nombre()), from: self::ANCLA));
        }

        return $programa;
    }
}
