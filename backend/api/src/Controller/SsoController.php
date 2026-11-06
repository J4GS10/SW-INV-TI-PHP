<?php

declare(strict_types=1);

namespace App\Controller;

use App\Autenticacion\DosFactores;
use App\Autenticacion\InicioSesion;
use App\Autenticacion\Sso;
use App\Config\Ajustes;
use App\Dominio\Reloj;
use App\Http\ClienteHttp;
use App\Http\ErrorApi;
use App\Http\Limite;
use App\Repositorio\GobiernoRepositorio;
use App\Repositorio\UsuarioRepositorio;
use App\Seguridad\PoliticaSesion;
use App\Seguridad\Tokens;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Inicio de sesión SSO (OpenID Connect). */
#[Route('/api/v1/login/sso')]
final readonly class SsoController
{
    public function __construct(
        private Sso $sso,
        private InicioSesion $inicio,
        private DosFactores $dosFactores,
        private UsuarioRepositorio $usuarios,
        private GobiernoRepositorio $gobierno,
        private PoliticaSesion $politica,
        private Tokens $tokens,
        private Connection $conexion,
        private Ajustes $ajustes,
    ) {
    }

    #[Route('/providers', methods: ['GET'])]
    public function proveedores(): JsonResponse
    {
        return new JsonResponse(['enabled' => $this->ajustes->ssoHabilitado, 'providers' => $this->sso->proveedores()]);
    }

    /** URL de autorización del proveedor; el `state` queda ligado a este navegador. */
    #[Route('/{provider}/authorize', methods: ['GET'])]
    #[Limite('20/minute')]
    public function autorizar(string $provider, Request $request): JsonResponse
    {
        $volverA = $request->query->get('return_to');
        [$url, $vinculo] = $this->sso->urlAutorizacion($provider, \is_string($volverA) ? $volverA : null);
        $respuesta = new JsonResponse(['authorization_url' => $url]);
        // SameSite=Lax: el proveedor vuelve con una navegación de nivel superior (GET).
        $respuesta->headers->setCookie(Cookie::create(Sso::COOKIE_VINCULO)
            ->withValue($vinculo)
            ->withExpires(time() + Sso::STATE_MINUTOS * 60)
            ->withPath($this->rutaCookie())
            ->withSecure($this->ajustes->esProduccion())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX));

        return $respuesta;
    }

    /**
     * Vuelta del proveedor. Sin 2FA local pendiente abre la sesión y redirige al
     * frontend con `#sso=ok`; si falta el segundo factor, redirige con el desafío
     * en el fragmento (no llega a servidores ni a logs) y la sesión se abre en
     * /login/2fa/verify.
     */
    #[Route('/{provider}/callback', methods: ['GET'])]
    #[Limite('20/minute')]
    public function callback(string $provider, Request $request): Response
    {
        [$ip, $ua] = [ClienteHttp::ip($request), ClienteHttp::userAgent($request)];
        if (null !== $request->query->get('error')) {
            throw new ErrorApi(401, 'SSO_PROVIDER_ERROR');
        }
        $codigo = $request->query->get('code');
        $state = $request->query->get('state');
        if (!\is_string($codigo) || '' === $codigo || !\is_string($state) || '' === $state) {
            throw new ErrorApi(400, 'SSO_CALLBACK_MISSING_CODE_OR_STATE');
        }
        $vinculo = $request->cookies->get(Sso::COOKIE_VINCULO);
        [$usuario, $volverA] = $this->sso->autenticar($provider, $codigo, $state, \is_string($vinculo) ? $vinculo : null, $ip, $ua);
        $separador = str_contains($volverA, '#') ? '&' : '#';

        if ($this->sso->requiereSegundoFactor($usuario)) {
            $this->conexion->transactional(fn () => $this->gobierno->registrarAuditoria('2FA_CHALLENGE', 'INV_USUARIO',
                ['username' => $usuario->username, 'metodo' => $usuario->dosFactoresMetodo, 'sso' => $provider], $usuario->id, $ip, $ua));
            if ('EMAIL' === $usuario->dosFactoresMetodo) {
                $this->dosFactores->emitirCodigoCorreo($usuario);
            }
            $fragmento = http_build_query([
                'sso' => '2fa',
                'method' => $usuario->dosFactoresMetodo ?? 'TOTP',
                'challenge' => $this->tokens->crearDesafio2fa($usuario->username, $usuario->passwordHash, true),
            ]);

            return $this->redirigir($volverA.$separador.$fragmento);
        }

        $respuesta = $this->redirigir($volverA.$separador.'sso=ok');
        $usuario->ultimoLogin = Reloj::ahora();
        $sesion = $this->conexion->transactional(function () use ($usuario, $provider, $ip, $ua, $respuesta): array {
            $this->usuarios->guardarEstadoAcceso($usuario);
            $this->gobierno->registrarAuditoria('LOGIN_SUCCESS', 'INV_USUARIO',
                ['username' => $usuario->username, 'rol' => $usuario->rol, 'sso' => $provider], $usuario->id, $ip, $ua);

            return $this->inicio->iniciar($usuario, $this->politica->claims($usuario, true), 'sso', $ip, $ua, $respuesta);
        });
        $this->inicio->avisarDispositivo($usuario, $sesion['dispositivo_nuevo'], $ip);

        return $respuesta;
    }

    private function redirigir(string $url): Response
    {
        $respuesta = InicioSesion::sinCache(new RedirectResponse($url, 303));
        // El vínculo del state es de un solo uso.
        $respuesta->headers->clearCookie(Sso::COOKIE_VINCULO, $this->rutaCookie(), null, $this->ajustes->esProduccion(), true, Cookie::SAMESITE_LAX);

        return $respuesta;
    }

    private function rutaCookie(): string
    {
        return $this->ajustes->prefijoApi.'/login/sso';
    }
}
