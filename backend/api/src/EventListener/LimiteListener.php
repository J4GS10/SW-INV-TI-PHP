<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Config\Ajustes;
use App\Controller\SaludController;
use App\Http\ClienteHttp;
use App\Http\Limite;
use App\Http\Limitador;
use App\Seguridad\Tokens;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Límites de peticiones por ruta (antes de autenticar: protege a la base).
 * Cada ruta tiene su propio contador; sin #[Limite] aplica RATE_LIMIT_DEFAULT.
 * Las sondas de salud quedan fuera (balanceadores y healthchecks).
 */
#[AsEventListener(event: KernelEvents::CONTROLLER, priority: 16)]
final readonly class LimiteListener
{
    public function __construct(private Limitador $limitador, private Tokens $tokens, private Ajustes $ajustes)
    {
    }

    public function __invoke(ControllerEvent $event): void
    {
        $controlador = $event->getController();
        if (\is_array($controlador) && $controlador[0] instanceof SaludController) {
            return;
        }
        $request = $event->getRequest();
        $atributos = $event->getAttributes(Limite::class);
        /** @var Limite|null $limite */
        $limite = [] === $atributos ? null : end($atributos);

        $regla = match (true) {
            null === $limite => $this->ajustes->limiteDefecto,
            null !== $limite->ajuste => (string) $this->ajustes->{$limite->ajuste},
            default => (string) $limite->regla,
        };
        $clave = Limite::CLAVE_REFRESH === $limite?->clave ? $this->claveRefresh($request) : $this->claveUsuarioOIp($request);
        $this->limitador->consumir((string) $request->attributes->get('_route', 'sin_ruta'), $regla, $clave);
    }

    /** El usuario si trae un access token válido (firma verificada); si no, la IP real. */
    private function claveUsuarioOIp(Request $request): string
    {
        $cabecera = (string) $request->headers->get('Authorization', '');
        if (1 === preg_match('/^Bearer\s+(\S+)$/i', $cabecera, $m)) {
            $payload = $this->tokens->payloadValido($m[1], 'access');
            if (null !== $payload) {
                return 'user:'.$payload['sub'];
            }
        }

        return ClienteHttp::ip($request) ?? 'desconocida';
    }

    /**
     * /login/refresh: el usuario de la cookie de refresh (firma válida). Contar por
     * IP haría que una oficina tras un NAT compartiera el cupo y perdiera la sesión.
     */
    private function claveRefresh(Request $request): string
    {
        $token = $request->cookies->get(\App\Http\CookiesSesion::REFRESH);
        if (\is_string($token) && '' !== $token) {
            $payload = $this->tokens->payloadValido($token, 'refresh');
            if (null !== $payload) {
                return 'refresh:'.$payload['sub'];
            }
        }

        return ClienteHttp::ip($request) ?? 'desconocida';
    }
}
