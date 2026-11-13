<?php

declare(strict_types=1);

namespace App\Dominio;

/** Desde dónde se pidió una operación (dirección y programa del cliente): lo que la bitácora guarda junto a quién la hizo. */
final readonly class Origen
{
    public function __construct(public ?string $ip = null, public ?string $agente = null)
    {
    }
}
