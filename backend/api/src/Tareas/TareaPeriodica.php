<?php

declare(strict_types=1);

namespace App\Tareas;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Tarea en segundo plano que el worker (Symfony Scheduler) ejecuta cada
 * `cadaSegundos()`. Se registra sola al implementar esta interfaz.
 */
#[AutoconfigureTag(self::ETIQUETA)]
interface TareaPeriodica
{
    public const string ETIQUETA = 'app.tarea_periodica';

    /** Nombre estable (registros y, en las exclusivas, clave del cerrojo `lock:job:{nombre}`). */
    public function nombre(): string;

    public function cadaSegundos(): int;

    /**
     * @return array<string, mixed>|null resultado para el registro; null si no había nada que hacer
     */
    public function ejecutar(): ?array;
}
