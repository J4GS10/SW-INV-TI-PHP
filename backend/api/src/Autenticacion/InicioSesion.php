<?php

declare(strict_types=1);

namespace App\Autenticacion;

use App\Config\Ajustes;
use App\Correo\Notificador;
use App\Dominio\Ident;
use App\Dominio\Usuario;
use App\Repositorio\GobiernoRepositorio;
use App\Repositorio\SesionRepositorio;
use App\Repositorio\UsuarioRepositorio;
use App\Seguridad\PoliticaSesion;
use App\Seguridad\Tokens;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Apertura de una sesión tras un login completo (contraseña, 2FA o SSO):
 * registro en SYS_SESION, refresh en cookie HttpOnly + Secure + SameSite=Strict
 * (nunca en el JSON: un XSS no puede leerlo), detección de dispositivo nuevo
 * y respuesta con el access token.
 */
final readonly class InicioSesion
{
    public const string COOKIE_REFRESH = 'rtk';

    public function __construct(
        private SesionRepositorio $sesiones,
        private GobiernoRepositorio $gobierno,
        private UsuarioRepositorio $usuarios,
        private Tokens $tokens,
        private Notificador $notificador,
        private Ajustes $ajustes,
    ) {
    }

    /**
     * Registra la sesión y fija la cookie. Llamar DENTRO de la transacción del
     * login; el aviso de dispositivo nuevo se envía con `avisarDispositivo()`
     * después del commit.
     *
     * @param array<string, mixed> $claims
     *
     * @return array{claims: array<string, mixed>, dispositivo_nuevo: ?string}
     */
    public function iniciar(Usuario $usuario, array $claims, string $metodo, ?string $ip, ?string $ua, Response $respuesta): array
    {
        $sesion = $this->sesiones->abrir($usuario->id, $ip, $ua, $metodo);
        $claims = [...$claims, 'sid' => Ident::aTexto($sesion['id'])];
        $refresh = $this->tokens->crearRefresh($usuario->username, $usuario->rol, extra: $claims);
        $this->sesiones->fijarRefreshJti($sesion['id'], Tokens::jtiSinVerificar($refresh));
        if ($sesion['nuevo_dispositivo']) {
            $this->gobierno->registrarAuditoria('LOGIN_NEW_DEVICE', 'INV_USUARIO',
                ['username' => $usuario->username, 'dispositivo' => $sesion['dispositivo']], $usuario->id, $ip, $ua);
        }
        $this->fijarCookie($respuesta, $refresh);

        return ['claims' => $claims, 'dispositivo_nuevo' => $sesion['nuevo_dispositivo'] ? $sesion['dispositivo'] : null];
    }

    /** Aviso al titular de un inicio de sesión desde un dispositivo no visto (post-commit). */
    public function avisarDispositivo(Usuario $usuario, ?string $dispositivo, ?string $ip): void
    {
        if (null === $dispositivo || !$this->ajustes->avisoDispositivoNuevo) {
            return;
        }
        $persona = $this->usuarios->persona($usuario);
        $this->notificador->eventoSeguridad('nuevo_dispositivo', $persona?->nombreCorto() ?? $usuario->username,
            $usuario->username, $persona?->correo, ['dispositivo' => $dispositivo, 'ip' => $ip ?? '']);
    }

    /**
     * Cuerpo de login/refresh. `restriction` indica un paso de seguridad pendiente.
     *
     * @param array<string, mixed> $claims
     *
     * @return array{access_token: string, token_type: string, expires_in: int, restriction: ?string}
     */
    public function respuestaTokens(Usuario $usuario, array $claims): array
    {
        return [
            'access_token' => $this->tokens->crearAcceso($usuario->username, $usuario->rol, $claims),
            'token_type' => 'bearer',
            'expires_in' => $this->ajustes->accesoMinutos * 60,
            'restriction' => PoliticaSesion::restriccion($claims),
        ];
    }

    public function fijarCookie(Response $respuesta, string $refresh): void
    {
        $respuesta->headers->setCookie(Cookie::create(self::COOKIE_REFRESH)
            ->withValue($refresh)
            // Sin persistencia, cookie de sesión: el navegador la descarta al cerrarse.
            ->withExpires($this->ajustes->cookieRefreshPersistente ? time() + $this->ajustes->refreshDias * 86400 : 0)
            ->withPath($this->rutaCookie())
            ->withSecure($this->ajustes->esProduccion())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT));
    }

    public function borrarCookie(Response $respuesta): void
    {
        $respuesta->headers->clearCookie(self::COOKIE_REFRESH, $this->rutaCookie(), null,
            $this->ajustes->esProduccion(), true, Cookie::SAMESITE_STRICT);
    }

    /** Las respuestas con tokens o desafíos no se guardan en cachés intermedias. */
    public static function sinCache(Response $respuesta): Response
    {
        $respuesta->headers->set('Cache-Control', 'no-store');
        $respuesta->headers->set('Pragma', 'no-cache');

        return $respuesta;
    }

    private function rutaCookie(): string
    {
        return $this->ajustes->prefijoApi.'/login';
    }
}
