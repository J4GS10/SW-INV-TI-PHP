<?php

declare(strict_types=1);

namespace App\Seguridad;

use App\Config\Ajustes;

/**
 * Códigos de un solo uso, con el mismo formato y hash que el backend anterior:
 * - OTP de correo y códigos de recuperación: se guardan como HMAC-SHA256 con
 *   la clave primaria de HMAC_KEYS (leer la base no basta para invertirlos).
 *   Se aceptan también las claves anteriores y el SHA-256 sin clave legado.
 * - Tokens de restablecimiento de contraseña: 256 bits aleatorios; en la base
 *   solo su SHA-256.
 */
final readonly class CodigosSeguridad
{
    private const string ALFABETO_RECUPERACION = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private Ajustes $ajustes)
    {
    }

    public function hash(string $codigo): string
    {
        return hash_hmac('sha256', self::normalizar($codigo), $this->ajustes->clavesHmac[0]);
    }

    /** @return list<string> hashes aceptables de un código (rotación de claves + legado) */
    public function candidatos(string $codigo): array
    {
        $normalizado = self::normalizar($codigo);

        return [
            ...array_map(static fn (string $k): string => hash_hmac('sha256', $normalizado, $k), $this->ajustes->clavesHmac),
            hash('sha256', $normalizado),
        ];
    }

    /** Código numérico (OTP por correo). */
    public static function otpNumerico(int $digitos = 6): string
    {
        $codigo = '';
        for ($i = 0; $i < $digitos; ++$i) {
            $codigo .= (string) random_int(0, 9);
        }

        return $codigo;
    }

    /** @return list<string> códigos legibles xxxx-xxxx sin caracteres ambiguos */
    public static function codigosRecuperacion(int $cantidad): array
    {
        $codigos = [];
        for ($n = 0; $n < $cantidad; ++$n) {
            $crudo = '';
            for ($i = 0; $i < 8; ++$i) {
                $crudo .= self::ALFABETO_RECUPERACION[random_int(0, 31)];
            }
            $codigos[] = substr($crudo, 0, 4).'-'.substr($crudo, 4);
        }

        return $codigos;
    }

    /** @return array{0: string, 1: string} [token plano para el correo, hash para la base] */
    public static function tokenRestablecimiento(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return [$token, self::hashRestablecimiento($token)];
    }

    public static function hashRestablecimiento(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function normalizar(string $codigo): string
    {
        return mb_strtoupper(trim($codigo));
    }
}
