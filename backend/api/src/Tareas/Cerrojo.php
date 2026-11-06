<?php

declare(strict_types=1);

namespace App\Tareas;

/** Exclusión entre procesos: quien obtiene la clave ejecuta; el resto no. */
interface Cerrojo
{
    /**
     * true si se obtuvo (y queda tomado `$segundos`, sin liberarse antes).
     *
     * @throws \RuntimeException si no se puede saber (no se debe ejecutar)
     */
    public function adquirir(string $clave, int $segundos): bool;

    /** Suelta la clave antes de tiempo (tras una falla: la tarea se reintenta en el siguiente turno). */
    public function liberar(string $clave): void;
}
