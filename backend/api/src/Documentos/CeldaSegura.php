<?php

declare(strict_types=1);

namespace App\Documentos;

/**
 * Celdas seguras para CSV y XLSX: neutraliza la inyección de fórmulas (OWASP
 * «CSV Injection»). Un texto que empieza con = + - @, tabulador o salto de línea
 * (o sus variantes de ancho completo) lo interpretaría Excel como fórmula
 * (=HYPERLINK(...), DDE).
 *
 * - CSV: se antepone una comilla simple; el visor la muestra como texto.
 * - XLSX: la celda se escribe como texto explícito con «quotePrefix»
 *   (App\Documentos\LibroExcel); el texto no se altera.
 */
final class CeldaSegura
{
    private const array PREFIJOS_FORMULA = ['=', '+', '-', '@', "\t", "\r", "\n", '＝', '＋', '－', '＠'];

    public static function esPeligrosa(mixed $valor): bool
    {
        if (!\is_string($valor) || '' === $valor) {
            return false;
        }

        return \in_array(mb_substr($valor, 0, 1), self::PREFIJOS_FORMULA, true);
    }

    public static function csv(mixed $valor): string
    {
        $texto = match (true) {
            null === $valor => '',
            \is_bool($valor) => $valor ? 'True' : 'False',
            default => (string) $valor,
        };

        return self::esPeligrosa($texto) ? "'".$texto : $texto;
    }
}
