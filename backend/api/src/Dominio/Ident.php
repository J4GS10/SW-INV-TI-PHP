<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Identificadores UUID del esquema: se guardan como CHAR(32) hexadecimal en
 * minúsculas y la API los expone en texto con guiones (formato RFC 4122).
 */
final class Ident
{
    /** Un UUID versión 4 (RFC 4122): 122 bits del generador criptográfico del sistema. */
    public static function nuevo(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40); // versión 4
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80); // variante RFC 4122

        return bin2hex($bytes);
    }

    /** Hexadecimal de 32 caracteres; acepta el UUID con o sin guiones. */
    public static function aHex(string $valor): string
    {
        $hex = strtolower(str_replace(['-', '{', '}'], '', trim($valor)));
        if (1 !== preg_match('/^[0-9a-f]{32}$/', $hex)) {
            throw new \InvalidArgumentException('UUID inválido.');
        }

        return $hex;
    }

    public static function esValido(string $valor): bool
    {
        try {
            self::aHex($valor);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** Texto con guiones, como lo devuelve la API. */
    public static function aTexto(string $hex): string
    {
        $h = self::aHex($hex);

        return \sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20));
    }
}
