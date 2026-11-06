<?php

declare(strict_types=1);

namespace App\Directorio;

/** Separa nombres y apellidos del directorio en las columnas de INV_PERSONA. */
final class NombrePersona
{
    public const int LARGO = 50;
    /** Partículas que forman parte del apellido siguiente («De la Cruz», «Del Valle»). */
    private const array PARTICULAS = ['de', 'del', 'la', 'las', 'los', 'y', 'san', 'santa', 'van', 'von', 'da', 'di', 'do', 'dos'];

    /**
     * «Juan Carlos» → [Juan, Carlos]; el resto del texto va al segundo.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function nombres(?string $valor): array
    {
        $partes = preg_split('/\s+/u', trim((string) $valor), 2, \PREG_SPLIT_NO_EMPTY) ?: [];

        return [
            isset($partes[0]) ? mb_substr($partes[0], 0, self::LARGO) : null,
            isset($partes[1]) ? mb_substr($partes[1], 0, self::LARGO) : null,
        ];
    }

    /**
     * «De la Cruz Pérez» → [De la Cruz, Pérez]; «García López» → [García, López].
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function apellidos(?string $valor): array
    {
        $palabras = preg_split('/\s+/u', trim((string) $valor), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        if ([] === $palabras) {
            return [null, null];
        }
        $primero = [];
        foreach ($palabras as $palabra) {
            $primero[] = $palabra;
            if (!\in_array(mb_strtolower($palabra), self::PARTICULAS, true)) {
                break;
            }
        }
        $resto = mb_substr(implode(' ', \array_slice($palabras, \count($primero))), 0, self::LARGO);

        return [mb_substr(implode(' ', $primero), 0, self::LARGO), '' === $resto ? null : $resto];
    }
}
