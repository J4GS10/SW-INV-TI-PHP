<?php

declare(strict_types=1);

namespace App\Tareas;

use App\Seguridad\CacheAuth;

/**
 * Cerrojo con `SET clave 1 NX EX segundos` en Redis: con varias réplicas del
 * worker, una tarea la ejecuta una sola. Mismo formato que el backend anterior,
 * así una reversión (revertir y volver) no repite una tarea dentro de su intervalo. El cerrojo no se libera al terminar: marca el intervalo.
 * Sin Redis (desarrollo) siempre se obtiene: las tareas son idempotentes.
 */
final readonly class CerrojoRedis implements Cerrojo
{
    public function __construct(private CacheAuth $redis)
    {
    }

    public function adquirir(string $clave, int $segundos): bool
    {
        try {
            return $this->redis->fijarSiNoExiste($clave, '1', $segundos) ?? true;
        } catch (\RedisException $e) {
            throw new \RuntimeException('No se pudo tomar el cerrojo: '.$e->getMessage(), 0, $e);
        }
    }

    public function liberar(string $clave): void
    {
        $this->redis->borrar($clave);
    }
}
