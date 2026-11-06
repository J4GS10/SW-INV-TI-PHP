<?php

declare(strict_types=1);

namespace App\Correo;

/**
 * Fallo de entrega. `codigo`: CONNECTION_FAILED | TLS_FAILED | SENDER_REJECTED |
 * NOT_CONFIGURED | AUTH_FAILED | AUTH_PAUSED | ERROR.
 */
class ErrorEnvioCorreo extends \RuntimeException
{
    /** @param string $detalle mensaje sin el código (lo que la prueba de la interfaz muestra) */
    public function __construct(public readonly string $codigo, public readonly string $detalle = '', ?\Throwable $previo = null)
    {
        parent::__construct('' === $detalle ? $codigo : "{$codigo}: {$detalle}", 0, $previo);
    }

    /** Credenciales rechazadas: dispara el cortacircuitos. */
    public function esDeAutenticacion(): bool
    {
        return \in_array($this->codigo, ['AUTH_FAILED', 'AUTH_PAUSED'], true);
    }
}
