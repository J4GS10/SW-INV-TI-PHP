<?php

declare(strict_types=1);

namespace App\Seguridad;

use App\Config\Ajustes;
use App\Http\ErrorApi;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * JWT HS256 con los mismos claims que el backend anterior (sub, role, type, jti,
 * iat, iat_ms, exp y las restricciones de sesión). Con la misma SECRET_KEY,
 * un token emitido por cualquiera de los dos backends vale en el otro: eso
 * permite migrar ruta por ruta detrás del proxy.
 */
final readonly class Tokens
{
    public function __construct(private Ajustes $ajustes)
    {
    }

    /** @param array<string, mixed> $extra */
    public function crearAcceso(string $username, string $rol, array $extra = [], ?int $segundos = null): string
    {
        return $this->crear($username, $rol, 'access', $segundos ?? $this->ajustes->accesoMinutos * 60, $extra);
    }

    /**
     * `auth_time` (epoch s) = login original; se conserva al rotar para aplicar
     * SESSION_ABSOLUTE_MAX_HOURS: el refresh nunca vive más allá de ese límite.
     *
     * @param array<string, mixed> $extra
     */
    public function crearRefresh(string $username, string $rol, ?int $authTime = null, array $extra = []): string
    {
        $ahora = time();
        $authTime ??= $ahora;
        $fin = $authTime + $this->ajustes->sesionMaxHoras * 3600;
        $segundos = max(0, min(
            $this->ajustes->sesionInactividadMinutos * 60,
            $this->ajustes->refreshDias * 86400,
            $fin - $ahora,
        ));

        return $this->crear($username, $rol, 'refresh', $segundos, [...$extra, 'auth_time' => $authTime]);
    }

    /**
     * Token efímero entre la contraseña y el segundo factor: solo vale en
     * /login/2fa/verify, una vez, y mientras la contraseña no cambie (`pwf`).
     */
    public function crearDesafio2fa(string $username, ?string $passwordHash, bool $viaSso = false): string
    {
        $ahora = time();
        $payload = [
            'iat' => $ahora,
            'exp' => $ahora + $this->ajustes->desafio2faMinutos * 60,
            'sub' => $username,
            'type' => '2fa',
            'jti' => bin2hex(random_bytes(16)),
            'pwf' => $this->huellaPassword($passwordHash),
        ];
        if ($viaSso) {
            $payload['amr'] = 'sso';
        }

        return JWT::encode($payload, $this->ajustes->claveSecreta, $this->ajustes->algoritmo);
    }

    /** Huella corta del hash vigente: liga un desafío 2FA a la contraseña actual. */
    public function huellaPassword(?string $passwordHash): string
    {
        return substr(hash_hmac('sha256', $passwordHash ?? '', $this->ajustes->claveSecreta), 0, 16);
    }

    /**
     * Decodifica y valida tipo y sujeto. Errores con los mismos códigos que el
     * backend anterior (TOKEN_EXPIRED, COULD_NOT_VALIDATE_CREDENTIALS, INVALID_TOKEN_TYPE).
     *
     * @return array<string, mixed>
     */
    public function decodificar(string $token, string $tipoEsperado = 'access'): array
    {
        try {
            $objeto = JWT::decode($token, new Key($this->ajustes->claveSecreta, $this->ajustes->algoritmo));
        } catch (ExpiredException) {
            throw ErrorApi::noAutenticado('TOKEN_EXPIRED');
        } catch (\Throwable) {
            throw ErrorApi::noAutenticado();
        }
        /** @var array<string, mixed> $payload */
        $payload = json_decode(json_encode($objeto, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        if (($payload['type'] ?? null) !== $tipoEsperado) {
            throw new ErrorApi(401, 'INVALID_TOKEN_TYPE');
        }
        if (empty($payload['sub'])) {
            throw new ErrorApi(401, 'COULD_NOT_VALIDATE_CREDENTIALS');
        }

        return $payload;
    }

    /**
     * Payload si el token es válido (firma y vigencia) y del tipo indicado;
     * null en cualquier otro caso. Para usos que no deben fallar (claves de
     * límites de peticiones).
     *
     * @return array<string, mixed>|null
     */
    public function payloadValido(string $token, string $tipo): ?array
    {
        try {
            return $this->decodificar($token, $tipo);
        } catch (ErrorApi) {
            return null;
        }
    }

    /**
     * Desafío 2FA válido (firma, vigencia, tipo `2fa`, sujeto y jti) o null.
     *
     * @return array<string, mixed>|null
     */
    public function decodificarDesafio(string $token): ?array
    {
        $payload = $this->payloadValido($token, '2fa');

        return null === $payload || empty($payload['jti']) ? null : $payload;
    }

    /**
     * Verifica FIRMA, algoritmo y tipo pero NO la expiración: al cerrar sesión
     * se revoca incluso un token ya vencido. null si es inválido.
     *
     * @return array<string, mixed>|null
     */
    public function decodificarSinExpiracion(string $token, string $tipo): ?array
    {
        try {
            return $this->verificarSinExpiracion($token, $tipo);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function verificarSinExpiracion(string $token, string $tipo): ?array
    {
        $partes = explode('.', $token);
        if (3 !== \count($partes)) {
            return null;
        }
        [$cabecera64, $payload64, $firma64] = $partes;
        $cabecera = json_decode(JWT::urlsafeB64Decode($cabecera64), true);
        if (!\is_array($cabecera) || ($cabecera['alg'] ?? null) !== $this->ajustes->algoritmo) {
            return null;
        }
        $esperada = hash_hmac('sha256', "{$cabecera64}.{$payload64}", $this->ajustes->claveSecreta, true);
        if (!hash_equals($esperada, JWT::urlsafeB64Decode($firma64))) {
            return null;
        }
        $payload = json_decode(JWT::urlsafeB64Decode($payload64), true);
        if (!\is_array($payload) || ($payload['type'] ?? null) !== $tipo || empty($payload['jti']) || empty($payload['sub'])) {
            return null;
        }

        return $payload;
    }

    /**
     * Expiración del token (UTC).
     *
     * @param array<string, mixed> $payload
     */
    public static function expira(array $payload): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@'.(int) ($payload['exp'] ?? 0)))->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Instante de emisión (UTC) con precisión de milisegundos si el token la trae.
     *
     * @param array<string, mixed> $payload
     */
    public static function emitidoEn(array $payload): ?\DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        if (!empty($payload['iat_ms'])) {
            $ms = (int) $payload['iat_ms'];

            return \DateTimeImmutable::createFromFormat('U.u', \sprintf('%d.%06d', intdiv($ms, 1000), ($ms % 1000) * 1000), $utc)
                ->setTimezone($utc);
        }
        if (!empty($payload['iat'])) {
            return (new \DateTimeImmutable('@'.(int) $payload['iat']))->setTimezone($utc);
        }

        return null;
    }

    /** jti sin verificar la firma (solo para registrar el refresh emitido). */
    public static function jtiSinVerificar(string $token): ?string
    {
        $partes = explode('.', $token);
        if (3 !== \count($partes)) {
            return null;
        }
        $payload = json_decode(JWT::urlsafeB64Decode($partes[1]), true);

        return \is_array($payload) && isset($payload['jti']) ? (string) $payload['jti'] : null;
    }

    /** @param array<string, mixed> $extra */
    private function crear(string $username, string $rol, string $tipo, int $segundos, array $extra): string
    {
        $ahora = microtime(true);
        $payload = [
            ...$extra,
            'iat' => (int) floor($ahora),
            // iat es entero (s); iat_ms compara contra una revocación global del mismo segundo.
            'iat_ms' => (int) floor($ahora * 1000),
            'exp' => (int) floor($ahora) + $segundos,
            'sub' => $username,
            'role' => $rol,
            'type' => $tipo,
            'jti' => bin2hex(random_bytes(16)),
        ];

        return JWT::encode($payload, $this->ajustes->claveSecreta, $this->ajustes->algoritmo);
    }
}
