<?php

declare(strict_types=1);

namespace App\Directorio;

final readonly class GrupoDirectorio
{
    public function __construct(public string $nombre, public string $dn)
    {
    }

    /** @return array{nombre: string, dn: string} */
    public function aApi(): array
    {
        return ['nombre' => $this->nombre, 'dn' => $this->dn];
    }
}
