<?php

declare(strict_types=1);

namespace App\Importacion;

/** Normalización del texto de las celdas para comparar con los catálogos. */
final class Texto
{
    /** Celda → texto sin espacios repetidos ni en los extremos; 12.0 → «12». */
    public static function limpio(mixed $valor): string
    {
        if (\is_float($valor) && floor($valor) === $valor && abs($valor) < 1e15) {
            $valor = (int) $valor;
        }
        $texto = match (true) {
            null === $valor => '',
            \is_bool($valor) => $valor ? 'True' : 'False',
            $valor instanceof \DateTimeInterface => $valor->format('Y-m-d H:i:s'),
            default => (string) $valor,
        };

        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    /** Clave de comparación: sin tildes, sin espacios repetidos, en minúsculas. */
    public static function normalizar(mixed $valor): string
    {
        $texto = null === $valor ? '' : (\is_scalar($valor) ? (string) $valor : self::limpio($valor));
        $descompuesto = \Normalizer::normalize($texto, \Normalizer::FORM_KD);
        $sinTildes = (string) preg_replace('/\p{Mn}+/u', '', false === $descompuesto ? $texto : $descompuesto);

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $sinTildes)));
    }

    /** Forma comparable de un código: «CAM-0001» y «cam-00001» son el mismo. */
    public static function claveCodigo(string $codigo): string
    {
        $c = mb_strtoupper((string) preg_replace('/\s+/u', '', $codigo));

        return 1 === preg_match('/^([A-Z]+-?)0*(\d+)$/', $c, $m) ? $m[1].(ltrim($m[2], '0') ?: '0') : $c;
    }
}
