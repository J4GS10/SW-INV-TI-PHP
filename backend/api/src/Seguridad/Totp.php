<?php

declare(strict_types=1);

namespace App\Seguridad;

/**
 * TOTP (RFC 6238): HMAC-SHA1, 6 dígitos, pasos de 30 s. Compatible con las
 * apps autenticadoras y con los secretos ya enrolados en el backend anterior.
 */
final class Totp
{
    public const int INTERVALO = 30;
    private const string BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Secreto base32 de 32 caracteres (160 bits). */
    public static function generarSecreto(): string
    {
        $secreto = '';
        for ($i = 0; $i < 32; ++$i) {
            $secreto .= self::BASE32[random_int(0, 31)];
        }

        return $secreto;
    }

    /** URI otpauth:// para el QR de la app autenticadora. */
    public static function uriAprovisionamiento(string $secreto, string $cuenta, string $emisor): string
    {
        $codificar = static fn (string $v): string => str_replace('%2F', '/', rawurlencode($v));

        return 'otpauth://totp/'.$codificar($emisor).':'.$codificar($cuenta)
            .'?secret='.$secreto.'&issuer='.$codificar($emisor);
    }

    public static function codigo(string $secreto, int $paso): string
    {
        $clave = self::base32Decodificar($secreto);
        $hmac = hash_hmac('sha1', pack('J', $paso), $clave, true);
        $desplazamiento = \ord($hmac[19]) & 0x0F;
        $numero = (unpack('N', substr($hmac, $desplazamiento, 4))[1] & 0x7FFFFFFF) % 1_000_000;

        return str_pad((string) $numero, 6, '0', \STR_PAD_LEFT);
    }

    public static function codigoEn(string $secreto, int $momento): string
    {
        return self::codigo($secreto, intdiv($momento, self::INTERVALO));
    }

    /**
     * Paso (contador de 30 s) al que corresponde el código, con tolerancia de
     * ±1 ventana por desfase de reloj; null si no es válido. Permite rechazar
     * la reutilización de un código ya aceptado.
     */
    public static function pasoCoincidente(string $secreto, string $codigo, ?int $momento = null): ?int
    {
        $codigo = trim($codigo);
        if ('' === $secreto || 1 !== preg_match('/^\d{6}$/', $codigo)) {
            return null;
        }
        $actual = intdiv($momento ?? time(), self::INTERVALO);
        try {
            foreach ([$actual - 1, $actual, $actual + 1] as $paso) {
                if (hash_equals(self::codigo($secreto, $paso), $codigo)) {
                    return $paso;
                }
            }
        } catch (\InvalidArgumentException) {
            return null;
        }

        return null;
    }

    private static function base32Decodificar(string $secreto): string
    {
        $limpio = strtoupper(rtrim(str_replace(' ', '', $secreto), '='));
        $bits = '';
        foreach (str_split($limpio) as $caracter) {
            $valor = strpos(self::BASE32, $caracter);
            if (false === $valor) {
                throw new \InvalidArgumentException('Secreto TOTP inválido.');
            }
            $bits .= str_pad(decbin($valor), 5, '0', \STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $octeto) {
            if (8 === \strlen($octeto)) {
                $bytes .= \chr((int) bindec($octeto));
            }
        }

        return $bytes;
    }
}
