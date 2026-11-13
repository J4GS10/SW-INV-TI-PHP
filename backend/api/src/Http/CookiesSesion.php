<?php

declare(strict_types=1);

namespace App\Http;

use App\Config\Ajustes;
use App\Realtime\UpdatePublisher;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The cookies of a session, the only place that writes them: the refresh token (HttpOnly + Secure +
 * SameSite=Strict, never in the JSON: an XSS cannot read it), the permission to listen to live changes
 * and the mark of a browser that was remembered after a second factor. The sign-in use cases return
 * the values; this class decides how they travel.
 */
final readonly class CookiesSesion
{
    public const string REFRESH = 'rtk';
    public const string EQUIPO = 'inv_mfa_device';

    public function __construct(private Ajustes $ajustes, private UpdatePublisher $tiempoReal)
    {
    }

    /** The refresh token the browser sent; '' when there is none. */
    public static function refresh(Request $peticion): string
    {
        $valor = $peticion->cookies->get(self::REFRESH);

        return \is_string($valor) ? $valor : '';
    }

    /** The mark of a remembered browser; '' when there is none. */
    public static function equipo(Request $peticion): string
    {
        $valor = $peticion->cookies->get(self::EQUIPO);

        return \is_string($valor) ? $valor : '';
    }

    /** Sets the refresh token and renews the permission to listen to live changes. */
    public function abrir(Response $respuesta, string $refresh, string $usuarioHex): void
    {
        $this->fijarTiempoReal($respuesta, $usuarioHex);
        $respuesta->headers->setCookie(Cookie::create(self::REFRESH)
            ->withValue($refresh)
            // Without persistence it is a session cookie: the browser drops it when it closes.
            ->withExpires($this->ajustes->cookieRefreshPersistente ? time() + $this->ajustes->refreshDias * 86400 : 0)
            ->withPath($this->ruta())
            ->withSecure($this->ajustes->esProduccion())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT));
    }

    public function cerrar(Response $respuesta): void
    {
        $respuesta->headers->clearCookie(self::REFRESH, $this->ruta(), null, $this->ajustes->esProduccion(), true, Cookie::SAMESITE_STRICT);
        $respuesta->headers->clearCookie(UpdatePublisher::COOKIE, UpdatePublisher::COOKIE_PATH, null, true, true, Cookie::SAMESITE_STRICT);
    }

    /** Leaves in the browser the mark that spares it the second factor until `$expira`; it only travels to the sign-in routes. */
    public function recordarEquipo(Response $respuesta, string $marca, \DateTimeImmutable $expira): void
    {
        $respuesta->headers->setCookie(Cookie::create(self::EQUIPO)
            ->withValue($marca)
            ->withExpires($expira->getTimestamp())
            ->withPath($this->ruta())
            ->withSecure($this->ajustes->esProduccion())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT));
    }

    /** Responses that carry tokens or challenges are not kept by intermediate caches. */
    public static function sinCache(Response $respuesta): Response
    {
        $respuesta->headers->set('Cache-Control', 'no-store');
        $respuesta->headers->set('Pragma', 'no-cache');

        return $respuesta;
    }

    /**
     * Permission to listen to live changes (Mercure hub, same origin). It lives as long as the idle
     * timeout of the session and is renewed with every refresh.
     */
    private function fijarTiempoReal(Response $respuesta, string $usuarioHex): void
    {
        $segundos = $this->ajustes->sesionInactividadMinutos * 60;
        $token = $this->tiempoReal->subscriberToken($usuarioHex, $segundos);
        if (null === $token) {
            return;
        }
        $respuesta->headers->setCookie(Cookie::create(UpdatePublisher::COOKIE)
            ->withValue($token)
            ->withExpires($this->ajustes->cookieRefreshPersistente ? time() + $segundos : 0)
            ->withPath(UpdatePublisher::COOKIE_PATH)
            ->withSecure(true) // __Secure- prefix: the browser always demands Secure with it
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT));
    }

    private function ruta(): string
    {
        return $this->ajustes->prefijoApi.'/login';
    }
}
