<?php

declare(strict_types=1);

namespace App\Adjuntos;

/** Archivo recibido del cliente: el nombre que traía y su contenido. */
final readonly class Subida
{
    public function __construct(public ?string $nombre, public string $contenido)
    {
    }
}
