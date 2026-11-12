<?php

declare(strict_types=1);

namespace App\Autenticacion;

use App\Config\Ajustes;
use App\Dominio\Usuario;
use App\Dominio\ErrorDominio;
use App\Repositorio\GobiernoRepositorio;
use App\Repositorio\UsuarioRepositorio;
use Doctrine\DBAL\Connection;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as ExcepcionHttp;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Inicio de sesión SSO con OpenID Connect (Microsoft Entra ID y Google
 * Workspace), con las mismas validaciones que el backend anterior:
 * - id_token solo con firmas asimétricas (nunca HMAC ni «none») y clave del JWKS
 *   del proveedor; aud, iss, exp, iat y nonce obligatorios;
 * - Entra ID con tenant genérico: solo tenants permitidos y emisor del tenant;
 * - correo de Entra ID solo si Microsoft certifica el dominio (xms_edov); si
 *   no, el UPN («nOAuth»);
 * - dominios de correo permitidos y cuenta habilitada para SSO;
 * - `return_to` limitado al origen del frontend (sin redirección abierta).
 * Endurecimiento: el `state` queda ligado al navegador que inició el flujo
 * (cookie HttpOnly): un enlace de callback ajeno no inicia sesión (CSRF de login).
 */
final readonly class Sso
{
    public const string COOKIE_VINCULO = 'sso_vinculo';
    public const int STATE_MINUTOS = 10;
    private const array ALGORITMOS = ['RS256', 'RS384', 'RS512', 'PS256', 'ES256'];
    private const array TENANTS_GENERICOS = ['common', 'organizations', 'consumers'];

    public function __construct(
        private HttpClientInterface $http,
        private UsuarioRepositorio $usuarios,
        private GobiernoRepositorio $gobierno,
        private Connection $conexion,
        private Ajustes $ajustes,
    ) {
    }

    /** @return list<array{provider: string, label: string}> */
    public function proveedores(): array
    {
        if (!$this->ajustes->ssoHabilitado) {
            return [];
        }
        $lista = [];
        foreach (['microsoft', 'google'] as $nombre) {
            try {
                $p = $this->proveedor($nombre);
                $lista[] = ['provider' => $p['nombre'], 'label' => $p['etiqueta']];
            } catch (ErrorDominio) {
            }
        }

        return $lista;
    }

    /** @return array{0: string, 1: string} [URL de autorización, vínculo para la cookie] */
    public function urlAutorizacion(string $nombre, ?string $volverA): array
    {
        $p = $this->proveedor($nombre);
        $nonce = self::aleatorio(24);
        $vinculo = self::aleatorio(32);
        $ahora = time();
        $state = JWT::encode([
            'type' => 'sso_state',
            'provider' => $p['nombre'],
            'nonce' => $nonce,
            'return_to' => $this->volverASeguro($volverA),
            'bnd' => hash('sha256', $vinculo),
            'iat' => $ahora,
            'exp' => $ahora + self::STATE_MINUTOS * 60,
        ], $this->ajustes->claveSecreta, $this->ajustes->algoritmo);
        $parametros = http_build_query([
            'client_id' => $p['client_id'],
            'response_type' => 'code',
            'redirect_uri' => $p['redirect_uri'],
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'prompt' => 'select_account',
        ], '', '&', \PHP_QUERY_RFC3986);

        return [$p['autorizacion'].'?'.$parametros, $vinculo];
    }

    /**
     * Valida el callback y devuelve la cuenta y el destino del frontend. Audita
     * los rechazos y el paso por el proveedor.
     *
     * @return array{0: Usuario, 1: string}
     */
    public function autenticar(string $nombre, string $codigo, string $state, ?string $vinculo, ?string $ip, ?string $ua): array
    {
        $p = $this->proveedor($nombre);
        $estado = $this->decodificarState($state, $p['nombre'], $vinculo);
        $descubrimiento = $this->obtenerJson('GET', $p['descubrimiento'], [], 'SSO_DISCOVERY_UNAVAILABLE', 8);
        $tokens = $this->canjearCodigo($p, $descubrimiento, $codigo);
        $claims = $this->verificarIdToken($p, $descubrimiento, $tokens['id_token'] ?? null, (string) $estado['nonce']);
        $correo = self::extraerCorreo($p['nombre'], $claims);
        $this->validarDominio($correo);

        $usuario = $this->usuarios->porCorreoPersona($correo);
        if (null === $usuario) {
            $this->auditarRechazo($p['nombre'], $correo, 'user_not_found', $ip, $ua);
            throw ErrorDominio::prohibido('SSO_USER_NOT_AUTHORIZED');
        }
        $persona = $this->usuarios->persona($usuario);
        if (!$usuario->activo || null === $persona || !$persona->activa) {
            $this->auditarRechazo($p['nombre'], $correo, 'inactive_user', $ip, $ua, $usuario);
            throw ErrorDominio::prohibido('SSO_USER_NOT_AUTHORIZED');
        }
        // El bloqueo del administrador también vale para Microsoft o Google.
        if ($usuario->bloqueoAdministrativoVigente(\App\Dominio\Reloj::ahora())) {
            $this->auditarRechazo($p['nombre'], $correo, 'account_locked', $ip, $ua, $usuario);
            throw ErrorDominio::prohibido('SSO_USER_NOT_AUTHORIZED');
        }
        if (!$usuario->ssoHabilitado) {
            $this->auditarRechazo($p['nombre'], $correo, 'sso_disabled_for_user', $ip, $ua, $usuario);
            throw ErrorDominio::prohibido('SSO_NOT_ENABLED_FOR_USER');
        }
        if (null !== $usuario->ssoProveedor && $usuario->ssoProveedor !== $p['nombre']) {
            $this->auditarRechazo($p['nombre'], $correo, 'provider_mismatch', $ip, $ua, $usuario);
            throw ErrorDominio::prohibido('SSO_PROVIDER_NOT_ALLOWED_FOR_USER');
        }

        return [$usuario, (string) $estado['return_to']];
    }

    /** Tras el proveedor falta el segundo factor local (SSO_MFA_LOCAL y la cuenta lo tiene). */
    public function requiereSegundoFactor(Usuario $u): bool
    {
        return $this->ajustes->ssoMfaLocal && $u->dosFactoresHabilitado;
    }

    /** @return array{nombre: string, etiqueta: string, client_id: string, client_secret: string, redirect_uri: string, descubrimiento: string, autorizacion: string} */
    private function proveedor(string $nombre): array
    {
        if (!$this->ajustes->ssoHabilitado) {
            throw ErrorDominio::invalido('SSO_DISABLED');
        }
        $p = match ($nombre) {
            'microsoft' => [
                'nombre' => 'microsoft', 'etiqueta' => 'Microsoft 365', ...$this->ajustes->ssoMicrosoft,
                'descubrimiento' => 'https://login.microsoftonline.com/'.rawurlencode($this->ajustes->ssoMicrosoft['tenant']).'/v2.0/.well-known/openid-configuration',
                'autorizacion' => 'https://login.microsoftonline.com/'.rawurlencode($this->ajustes->ssoMicrosoft['tenant']).'/oauth2/v2.0/authorize',
            ],
            'google' => [
                'nombre' => 'google', 'etiqueta' => 'Google Workspace', ...$this->ajustes->ssoGoogle,
                'descubrimiento' => 'https://accounts.google.com/.well-known/openid-configuration',
                'autorizacion' => 'https://accounts.google.com/o/oauth2/v2/auth',
            ],
            default => throw ErrorDominio::noEncontrado('SSO_PROVIDER_NOT_SUPPORTED'),
        };
        if ('' === $p['client_id'] || '' === $p['client_secret'] || '' === $p['redirect_uri']) {
            throw ErrorDominio::invalido('SSO_PROVIDER_NOT_CONFIGURED');
        }
        unset($p['tenant']);

        return $p;
    }

    /** @return array<string, mixed> */
    private function decodificarState(string $state, string $proveedor, ?string $vinculo): array
    {
        try {
            $payload = (array) JWT::decode($state, new Key($this->ajustes->claveSecreta, $this->ajustes->algoritmo));
        } catch (\Throwable) {
            throw ErrorDominio::noAutenticado('SSO_STATE_INVALID');
        }
        if (($payload['type'] ?? null) !== 'sso_state' || ($payload['provider'] ?? null) !== $proveedor
            || !\is_string($payload['nonce'] ?? null) || !\is_string($payload['return_to'] ?? null)) {
            throw ErrorDominio::noAutenticado('SSO_STATE_INVALID');
        }
        if (null === $vinculo || '' === $vinculo || !hash_equals((string) ($payload['bnd'] ?? ''), hash('sha256', $vinculo))) {
            throw ErrorDominio::noAutenticado('SSO_STATE_INVALID');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $p
     * @param array<string, mixed> $descubrimiento
     *
     * @return array<string, mixed>
     */
    private function canjearCodigo(array $p, array $descubrimiento, string $codigo): array
    {
        $endpoint = $descubrimiento['token_endpoint'] ?? null;
        if (!\is_string($endpoint) || !str_starts_with($endpoint, 'https://')) {
            throw ErrorDominio::servicioExterno('SSO_DISCOVERY_INVALID');
        }
        try {
            $respuesta = $this->http->request('POST', $endpoint, ['timeout' => 12, 'body' => [
                'grant_type' => 'authorization_code',
                'code' => $codigo,
                'redirect_uri' => $p['redirect_uri'],
                'client_id' => $p['client_id'],
                'client_secret' => $p['client_secret'],
            ]]);
            if ($respuesta->getStatusCode() >= 400) {
                throw ErrorDominio::noAutenticado('SSO_CODE_EXCHANGE_FAILED');
            }

            return $respuesta->toArray(false);
        } catch (ExcepcionHttp) {
            throw ErrorDominio::noAutenticado('SSO_CODE_EXCHANGE_FAILED');
        }
    }

    /**
     * @param array<string, mixed> $p
     * @param array<string, mixed> $descubrimiento
     *
     * @return array<string, mixed>
     */
    private function verificarIdToken(array $p, array $descubrimiento, mixed $idToken, string $nonce): array
    {
        if (!\is_string($idToken) || '' === $idToken) {
            throw ErrorDominio::noAutenticado('SSO_ID_TOKEN_MISSING');
        }
        $jwksUri = $descubrimiento['jwks_uri'] ?? null;
        if (!\is_string($jwksUri) || !str_starts_with($jwksUri, 'https://')) {
            throw ErrorDominio::servicioExterno('SSO_DISCOVERY_INVALID');
        }
        $invalido = ErrorDominio::noAutenticado('SSO_ID_TOKEN_INVALID');
        try {
            $cabecera = json_decode(JWT::urlsafeB64Decode(explode('.', $idToken)[0]), true);
        } catch (\Throwable) {
            throw $invalido;
        }
        if (!\is_array($cabecera)) {
            throw $invalido;
        }
        // El algoritmo lo declara quien envía el token: solo firmas asimétricas.
        $alg = (string) ($cabecera['alg'] ?? 'RS256');
        if (!\in_array($alg, self::ALGORITMOS, true)) {
            throw $invalido;
        }
        $jwks = $this->obtenerJson('GET', $jwksUri, [], 'SSO_JWKS_UNAVAILABLE', 8);
        $jwk = null;
        foreach ((array) ($jwks['keys'] ?? []) as $candidata) {
            if (\is_array($candidata) && ($candidata['kid'] ?? null) === ($cabecera['kid'] ?? null)) {
                $jwk = $candidata;
                break;
            }
        }
        if (null === $jwk) {
            throw ErrorDominio::noAutenticado('SSO_SIGNING_KEY_NOT_FOUND');
        }
        if (!\in_array($jwk['kty'] ?? null, ['RSA', 'EC'], true) || ($jwk['alg'] ?? $alg) !== $alg) {
            throw $invalido;
        }
        try {
            $clave = JWK::parseKey([...$jwk, 'alg' => $alg], $alg) ?? throw $invalido;
            $claims = json_decode(json_encode(JWT::decode($idToken, $clave), \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        } catch (ErrorDominio $e) {
            throw $e;
        } catch (\Throwable) {
            throw $invalido;
        }
        foreach (['exp', 'iat', 'aud'] as $obligatorio) {
            if (!isset($claims[$obligatorio])) {
                throw $invalido;
            }
        }
        if (!\in_array($p['client_id'], (array) $claims['aud'], true)) {
            throw $invalido;
        }
        $generico = 'microsoft' === $p['nombre'] && \in_array($this->ajustes->ssoMicrosoft['tenant'], self::TENANTS_GENERICOS, true);
        // Con tenant genérico el emisor depende del tenant del usuario: se valida contra `tid`.
        $emisor = $descubrimiento['issuer'] ?? null;
        if (!$generico && \is_string($emisor) && ($claims['iss'] ?? null) !== $emisor) {
            throw $invalido;
        }
        if (!\is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])) {
            throw ErrorDominio::noAutenticado('SSO_NONCE_INVALID');
        }
        if ($generico) {
            $this->validarTenantMicrosoft($claims);
        }
        if ('google' === $p['nombre'] && !\in_array($claims['iss'] ?? null, ['https://accounts.google.com', 'accounts.google.com'], true)) {
            throw $invalido;
        }
        if (false === ($claims['email_verified'] ?? null)) {
            throw ErrorDominio::prohibido('SSO_EMAIL_NOT_VERIFIED');
        }

        return $claims;
    }

    /** @param array<string, mixed> $claims */
    private function validarTenantMicrosoft(array $claims): void
    {
        $tid = strtolower((string) ($claims['tid'] ?? ''));
        if ('' === $tid || !\in_array($tid, $this->ajustes->ssoMicrosoftTenants, true)) {
            throw ErrorDominio::prohibido('SSO_TENANT_NOT_ALLOWED');
        }
        if (($claims['iss'] ?? null) !== "https://login.microsoftonline.com/{$claims['tid']}/v2.0") {
            throw ErrorDominio::noAutenticado('SSO_ID_TOKEN_INVALID');
        }
    }

    /** @param array<string, mixed> $claims */
    public static function extraerCorreo(string $proveedor, array $claims): string
    {
        $correo = $claims['email'] ?? null;
        if ('microsoft' === $proveedor) {
            // En Entra ID `email` lo fija el administrador de cualquier tenant y no está
            // verificado: solo vale si Microsoft certifica el dominio; si no, el UPN.
            if (true !== ($claims['xms_edov'] ?? null)) {
                $correo = null;
            }
            $correo = $correo ?: ($claims['preferred_username'] ?? null) ?: ($claims['upn'] ?? null);
        }
        if (!\is_string($correo) || !str_contains($correo, '@')) {
            throw ErrorDominio::prohibido('SSO_EMAIL_MISSING');
        }

        return strtolower(trim($correo));
    }

    private function validarDominio(string $correo): void
    {
        if ([] === $this->ajustes->ssoDominios) {
            return;
        }
        $dominio = strtolower(substr((string) strrchr($correo, '@'), 1));
        if (!\in_array($dominio, $this->ajustes->ssoDominios, true)) {
            throw ErrorDominio::prohibido('SSO_EMAIL_DOMAIN_NOT_ALLOWED');
        }
    }

    /** Destino tras el login: solo del mismo origen que el frontend (sin redirección abierta). */
    public function volverASeguro(?string $volverA): string
    {
        $defecto = $this->ajustes->urlFrontend.'/sso/callback';
        if (null === $volverA || '' === $volverA) {
            return $defecto;
        }
        $permitido = parse_url($this->ajustes->urlFrontend);
        $candidato = parse_url($volverA);
        if (false === $permitido || false === $candidato
            || !\in_array($candidato['scheme'] ?? '', ['http', 'https'], true)
            || $candidato['scheme'] !== ($permitido['scheme'] ?? '')
            || strtolower($candidato['host'] ?? '') !== strtolower($permitido['host'] ?? '')
            || ($candidato['port'] ?? null) !== ($permitido['port'] ?? null)
            || isset($candidato['user']) || isset($candidato['pass'])
            || 1 === preg_match('/[\x00-\x1F\x7F\\\\]/', $volverA)) {
            return $defecto;
        }

        return $volverA;
    }

    /**
     * @param array<string, mixed> $opciones
     *
     * @return array<string, mixed>
     */
    private function obtenerJson(string $metodo, string $url, array $opciones, string $error, int $timeout): array
    {
        try {
            $respuesta = $this->http->request($metodo, $url, [...$opciones, 'timeout' => $timeout]);
            if ($respuesta->getStatusCode() >= 400) {
                throw ErrorDominio::servicioExterno($error);
            }

            return $respuesta->toArray();
        } catch (ExcepcionHttp) {
            throw ErrorDominio::servicioExterno($error);
        }
    }

    private function auditarRechazo(string $proveedor, string $correo, string $motivo, ?string $ip, ?string $ua, ?Usuario $u = null): void
    {
        $this->conexion->transactional(fn () => $this->gobierno->registrarAuditoria('LOGIN_FAILED', 'INV_USUARIO',
            ['provider' => $proveedor, 'email' => $correo, 'reason' => $motivo], $u?->id, $ip, $ua));
    }

    private static function aleatorio(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
