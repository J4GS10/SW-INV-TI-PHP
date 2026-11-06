<?php

declare(strict_types=1);

namespace App\Http;

use App\Crud\Campo;
use App\Dominio\Ident;
use Symfony\Component\HttpFoundation\Request;

/** Filtros del kardex en la consulta (pantalla y CSV), con los mismos nombres y reglas que el backend anterior. */
final class ConsultaKardex
{
    /**
     * Lee los filtros; los errores quedan en `$e` (el que llama valida).
     *
     * @return array<string, mixed> filtros para App\Inventario\Kardex::filtros()
     */
    public static function filtros(Request $request, Entrada $e): array
    {
        $f = [
            'objeto' => $e->patron('objeto', '^(ACTIVO|CONSUMIBLE|LICENCIA)$'),
            'categoria' => $e->patron('categoria', '^(ENTRADAS|SALIDAS|ENTRADAS_SALIDAS|REASIGNACIONES)$'),
            'tipos' => self::repetidos($request, 'tipos'),
        ];
        foreach (['activo_id' => 'activo', 'persona_id' => 'persona'] as $param => $clave) {
            [, $v] = $e->campo(new Campo($param, Campo::UUID), false);
            $f[$clave] = null === $v ? null : Ident::aHex((string) $v);
        }
        foreach (['consumible_id' => 'consumible', 'licencia_id' => 'licencia', 'sede_id' => 'sede'] as $param => $clave) {
            [, $v] = $e->campo(Campo::entero($param), false);
            $f[$clave] = null === $v ? null : (int) $v;
        }
        [, $f['desde']] = $e->campo(new Campo('desde', Campo::FECHA), false);
        [, $f['hasta']] = $e->campo(new Campo('hasta', Campo::FECHA), false);
        [, $f['texto']] = $e->campo(Campo::texto('texto', max: 64), false);

        return $f;
    }

    /**
     * Valores repetidos de la query (`tipos=A&tipos=B`), como los lee FastAPI.
     *
     * @return list<string>
     */
    public static function repetidos(Request $request, string $nombre): array
    {
        $valores = [];
        foreach (explode('&', (string) $request->server->get('QUERY_STRING', '')) as $par) {
            [$k, $v] = array_pad(explode('=', $par, 2), 2, '');
            if (urldecode($k) === $nombre || urldecode($k) === "{$nombre}[]") {
                $valores[] = urldecode($v);
            }
        }

        return $valores;
    }
}
