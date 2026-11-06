<?php

declare(strict_types=1);

namespace App\Tareas;

use App\Inventario\Tomas;

/** Closes large inventory counts outside the HTTP request: one pass per minute, idempotent per count. */
final readonly class CierreTomasPendientes implements TareaExclusiva
{
    public function __construct(private Tomas $counts)
    {
    }

    public function nombre(): string
    {
        return 'close_inventory_counts';
    }

    public function cadaSegundos(): int
    {
        return 60;
    }

    /** @return array<string, int>|null */
    public function ejecutar(): ?array
    {
        $closed = $this->counts->cerrarPendientes();

        return 0 === $closed ? null : ['tomas_cerradas' => $closed];
    }
}
