<?php

declare(strict_types=1);

namespace App\Tareas;

/**
 * Tarea que corre UNA sola vez por intervalo aunque haya varias réplicas del
 * worker: antes de ejecutarla se toma
 * el cerrojo `lock:job:{nombre}` por el intervalo menos 5 s (mínimo 30 s),
 * igual que el backend anterior.
 */
interface TareaExclusiva extends TareaPeriodica
{
}
