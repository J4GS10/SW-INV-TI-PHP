<?php

declare(strict_types=1);

namespace App\Tareas;

/** Mensaje del Scheduler: «toca ejecutar esta tarea». */
final readonly class EjecutarTarea
{
    public function __construct(public string $nombre)
    {
    }
}
