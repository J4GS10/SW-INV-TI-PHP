<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Error de la API con el mismo contrato que el backend anterior:
 * `{"detail": "<CÓDIGO>"}` y el código HTTP indicado. El frontend traduce
 * el código, así que no se cambia su texto.
 */
final class ErrorApi extends \RuntimeException
{
    /**
     * @param array<string, string>     $cabeceras
     * @param array<string, mixed>|null $cuerpo
     */
    public function __construct(
        public readonly int $estado,
        public readonly string $detalle,
        public readonly array $cabeceras = [],
        /** `detail` como objeto (p. ej. transiciones de estado: error, estado actual y destino). */
        public readonly ?array $cuerpo = null,
    ) {
        parent::__construct($detalle, $estado);
    }

    public static function noAutenticado(string $detalle = 'COULD_NOT_VALIDATE_CREDENTIALS'): self
    {
        return new self(401, $detalle, ['WWW-Authenticate' => 'Bearer']);
    }

    public static function prohibido(string $detalle = 'NOT_ENOUGH_PERMISSIONS'): self
    {
        return new self(403, $detalle);
    }
}
