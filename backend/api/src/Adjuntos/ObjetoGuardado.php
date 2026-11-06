<?php

declare(strict_types=1);

namespace App\Adjuntos;

/** Resultado de guardar un binario: lo que se registra en INV_ADJUNTO. */
final readonly class ObjetoGuardado
{
    public function __construct(
        public string $backend,
        public string $clave,
        public ?string $bucket,
        public int $tamano,
        public string $sha256,
    ) {
    }
}
