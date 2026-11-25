<?php

declare(strict_types=1);

namespace App\Tests\Soporte;

/**
 * Servicio del contenedor de pruebas con su tipo: `self::servicio(Tokens::class)->crearAcceso(…)`.
 * Un servicio que falta (o de otra clase) falla con un mensaje claro en lugar de un error de método.
 */
trait Servicios
{
    /**
     * @template T of object
     *
     * @param class-string<T> $clase
     *
     * @return T
     */
    protected static function servicio(string $clase): object
    {
        $servicio = static::getContainer()->get($clase);
        if (!$servicio instanceof $clase) {
            throw new \LogicException("{$clase} no está en el contenedor de pruebas.");
        }

        return $servicio;
    }
}
