<?php

declare(strict_types=1);

namespace App\Db;

use App\Dominio\Listado;

/**
 * ORDER BY and OFFSET/FETCH for a page of a list. The column comes from a closed map (public key => SQL
 * expression written by the store), never from the request, and a unique tie-breaker keeps pages stable.
 */
final class OrdenSql
{
    /**
     * @param array<string, string> $columnas public sort key => SQL expression
     * @param string                $porDefecto the usual order when the request asks for none (or for an unknown key)
     * @param string                $desempate unique column(s) appended last
     */
    public static function de(array $columnas, Listado $listado, string $porDefecto, string $desempate): string
    {
        $orden = null !== $listado->orden && isset($columnas[$listado->orden])
            ? $columnas[$listado->orden].($listado->descendente ? ' DESC' : ' ASC').' NULLS LAST'
            : $porDefecto;

        return ' ORDER BY '.$orden.', '.$desempate.' OFFSET :saltar ROWS FETCH NEXT :limite ROWS ONLY';
    }

    /** @return array{saltar: int, limite: int} the parameters of {@see de()} */
    public static function parametros(Listado $listado): array
    {
        return ['saltar' => $listado->saltar, 'limite' => $listado->limite];
    }
}
