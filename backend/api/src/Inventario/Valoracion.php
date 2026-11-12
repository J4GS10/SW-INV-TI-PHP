<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Dominio\ErrorDominio;

/**
 * Valoración de consumibles por COSTO PROMEDIO PONDERADO, en decimal exacto (bcmath):
 * - Entrada con precio: (existencia × promedio + cantidad × precio) / (existencia + cantidad).
 * - Entrada sin precio, salidas y ajustes: al promedio vigente (no lo alteran).
 * - Sin existencias o sin costo conocido, el promedio pasa a ser el precio de la entrada.
 * - Una sola moneda por consumible: una entrada en otra moneda solo con existencia cero.
 */
final class Valoracion
{
    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} [nuevo promedio, moneda de valoración, costo unitario del movimiento]
     */
    public static function entrada(int $stockAntes, ?string $promedio, ?string $monedaValoracion, int $cantidad, ?string $precio, ?string $monedaEntrada): array
    {
        if (null === $precio) {
            return [$promedio, $monedaValoracion, $promedio];
        }
        $moneda = $monedaEntrada ?? $monedaValoracion ?? 'GTQ';
        $existencia = max($stockAntes, 0);
        if (null === $promedio || 0 === $existencia) {
            return [self::redondear($precio, 4), $moneda, $precio];
        }
        if (null !== $monedaValoracion && '' !== $monedaValoracion && $moneda !== $monedaValoracion) {
            throw ErrorDominio::invalido("CURRENCY_DIFFERS_FROM_VALUATION:{$monedaValoracion}");
        }
        $total = bcadd(bcmul((string) $existencia, $promedio, 10), bcmul((string) $cantidad, $precio, 10), 10);

        return [self::redondear(bcdiv($total, (string) ($existencia + $cantidad), 10), 4), $moneda, $precio];
    }

    public static function valorSaldo(?int $saldo, ?string $promedio): ?string
    {
        if (null === $saldo || null === $promedio) {
            return null;
        }

        return self::redondear(bcmul((string) $saldo, $promedio, 10), 2);
    }

    /**
     * Promedio simple con 2 decimales (ROUND_HALF_UP).
     *
     * @param list<string> $valores
     */
    public static function promedio(array $valores): string
    {
        $suma = '0';
        foreach ($valores as $v) {
            $suma = bcadd($suma, $v, 10);
        }

        return self::redondear(bcdiv($suma, (string) \count($valores), 10), 2);
    }

    /** Redondeo mitad hacia arriba (alejándose de cero), como Decimal.quantize(ROUND_HALF_UP). */
    public static function redondear(string $valor, int $escala): string
    {
        return bcround($valor, $escala, \RoundingMode::HalfAwayFromZero);
    }
}
