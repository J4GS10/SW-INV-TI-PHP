<?php

declare(strict_types=1);

namespace App\Seguridad;

/**
 * Hash de contraseñas compatible con el backend anterior (passlib):
 * argon2id m=65536, t=3, p=2 como esquema vigente y bcrypt ($2b$) como
 * esquema anterior, que se rehace a argon2id en el siguiente login correcto.
 * PHP 8.5 verifica ambos formatos de forma nativa.
 */
final class Contrasenas
{
    private const array OPCIONES = ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 2];

    /**
     * Hash fijo con los mismos parámetros que OPCIONES, de una clave al azar que nadie conoce. Precalculado: si
     * cada worker lo generara en su primera llamada, esa respuesta de «usuario inexistente» costaría el doble.
     */
    private const string HASH_SENUELO = '$argon2id$v=19$m=65536,t=3,p=2$bkJJVnVxa2E2dzBsdjFSMA$eHBnsfmb72jv5+YVwGHpdWZ+VdPpBhLe/b1W/hzled4';

    public static function hashear(string $plano): string
    {
        return password_hash($plano, \PASSWORD_ARGON2ID, self::OPCIONES);
    }

    public static function verificar(string $plano, ?string $hash): bool
    {
        if (null === $hash || '' === $hash) {
            return false;
        }

        return password_verify($plano, $hash);
    }

    public static function necesitaRehash(string $hash): bool
    {
        return password_needs_rehash($hash, \PASSWORD_ARGON2ID, self::OPCIONES);
    }

    /**
     * Gasta el mismo tiempo que una verificación real cuando el usuario no
     * existe: la latencia no revela qué cuentas existen.
     */
    public static function verificarSenuelo(): void
    {
        password_verify('timing', self::HASH_SENUELO);
    }
}
