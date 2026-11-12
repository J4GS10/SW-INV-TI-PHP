<?php

declare(strict_types=1);

namespace App\Inventario\Puertos;

/** What the warranty report needs of each asset the current user may see. */
interface GarantiasActivos
{
    /**
     * @return list<array{ACT_Activo: string, ACT_Codigo_Interno: string, ACT_Serie_Fabricante: string|null, ACT_Fecha_Compra: string,
     *     ACT_Fin_Garantia: string|null, PRV_Nombre: string|null}> ordered by internal code
     */
    public function visibles(): array;
}
