<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * One page of a list read on the server: how many rows to skip and return, and the column to sort by. The
 * column is a public key («date», «code»…); each store maps the ones it accepts to SQL and ignores the rest.
 */
final readonly class Listado
{
    public function __construct(
        public int $saltar = 0,
        public int $limite = 50,
        public ?string $orden = null,
        public bool $descendente = false,
    ) {
        if ($saltar < 0 || $limite < 1) {
            throw new \InvalidArgumentException('A page skips zero or more rows and returns at least one.');
        }
    }
}
