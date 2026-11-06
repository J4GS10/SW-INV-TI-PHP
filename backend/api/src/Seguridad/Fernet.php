<?php

declare(strict_types=1);

namespace App\Seguridad;

/**
 * Fernet (especificación de `cryptography`): AES-128-CBC + HMAC-SHA256 con
 * versión y marca de tiempo, en base64url. Interoperable con los valores que
 * ya cifró el backend anterior (secretos TOTP, claves de licencias, credenciales
 * de integración).
 *
 * Rotación (como MultiFernet): la primera clave cifra; todas se prueban al
 * descifrar.
 */
final readonly class Fernet
{
    private const string VERSION = "\x80";

    /** @var list<array{firma: string, cifrado: string}> */
    private array $claves;

    /** @param list<string> $claves claves Fernet (32 bytes en base64url) */
    public function __construct(array $claves)
    {
        if ([] === $claves) {
            throw new \InvalidArgumentException('Fernet requiere al menos una clave.');
        }
        $this->claves = array_map(static function (string $clave): array {
            $bytes = self::b64Decodificar($clave);
            if (32 !== \strlen($bytes)) {
                throw new \InvalidArgumentException('Clave Fernet inválida: debe tener 32 bytes en base64url.');
            }

            return ['firma' => substr($bytes, 0, 16), 'cifrado' => substr($bytes, 16)];
        }, $claves);
    }

    public static function generarClave(): string
    {
        return strtr(base64_encode(random_bytes(32)), '+/', '-_');
    }

    public function cifrar(string $texto, ?int $momento = null): string
    {
        $clave = $this->claves[0];
        $iv = random_bytes(16);
        $cifrado = openssl_encrypt($texto, 'aes-128-cbc', $clave['cifrado'], \OPENSSL_RAW_DATA, $iv);
        if (false === $cifrado) {
            throw new \RuntimeException('No se pudo cifrar.');
        }
        $cuerpo = self::VERSION.pack('J', $momento ?? time()).$iv.$cifrado;

        return strtr(base64_encode($cuerpo.hash_hmac('sha256', $cuerpo, $clave['firma'], true)), '+/', '-_');
    }

    /** @throws TokenFernetInvalido si ninguna clave valida la firma o el formato es incorrecto */
    public function descifrar(string $token): string
    {
        $datos = self::b64Decodificar($token);
        // versión (1) + tiempo (8) + IV (16) + al menos un bloque (16) + HMAC (32)
        if (\strlen($datos) < 73 || self::VERSION !== $datos[0] || 0 !== (\strlen($datos) - 57) % 16) {
            throw new TokenFernetInvalido();
        }
        $cuerpo = substr($datos, 0, -32);
        $firma = substr($datos, -32);
        foreach ($this->claves as $clave) {
            if (!hash_equals(hash_hmac('sha256', $cuerpo, $clave['firma'], true), $firma)) {
                continue;
            }
            $texto = openssl_decrypt(substr($cuerpo, 25), 'aes-128-cbc', $clave['cifrado'], \OPENSSL_RAW_DATA, substr($cuerpo, 9, 16));
            if (false === $texto) {
                throw new TokenFernetInvalido();
            }

            return $texto;
        }
        throw new TokenFernetInvalido();
    }

    private static function b64Decodificar(string $valor): string
    {
        $limpio = strtr(trim($valor), '-_', '+/');
        $resultado = base64_decode($limpio.str_repeat('=', (4 - \strlen($limpio) % 4) % 4), true);

        return false === $resultado ? '' : $resultado;
    }
}
