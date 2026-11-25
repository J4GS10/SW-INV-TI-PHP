<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Importes y cantidades como texto exacto para bcmath (nunca coma flotante). Un valor que no es un número
 * se rechaza aquí con su contenido, en lugar de un ValueError anónimo dentro de bcadd o bcmul.
 */
final class Decimal
{
    /** @return numeric-string */
    public static function de(mixed $valor): string
    {
        $texto = match (true) {
            \is_string($valor) => trim($valor),
            \is_int($valor) => (string) $valor,
            // Sin notación científica: bcmath no la admite.
            \is_float($valor) => \sprintf('%.10F', $valor),
            default => '',
        };
        // bcmath tampoco admite exponentes («1E3»).
        if (!is_numeric($texto) || 1 === preg_match('/[eE]/', $texto)) {
            throw new \InvalidArgumentException('No es un importe válido: «'.mb_substr($texto, 0, 40).'».');
        }

        return $texto;
    }
}
