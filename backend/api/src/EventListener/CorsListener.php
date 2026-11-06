<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Config\Ajustes;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CORS con la misma política que el backend anterior: solo los orígenes de
 * BACKEND_CORS_ORIGINS, sin credenciales (JWT en Authorization, no cookies
 * cross-origin). Sin orígenes configurados no se emite ninguna cabecera CORS.
 */
final readonly class CorsListener
{
    private const string METODOS = 'GET, POST, PATCH, PUT, DELETE, OPTIONS';
    private const array CABECERAS = ['authorization', 'content-type', 'accept', 'idempotency-key', 'x-request-id'];

    public function __construct(private Ajustes $ajustes)
    {
    }

    /** Preflight: responde aquí, antes del enrutado (OPTIONS no tiene rutas propias). */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 600)]
    public function alIniciar(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $origen = $request->headers->get('Origin');
        if (!$event->isMainRequest() || null === $origen || [] === $this->ajustes->origenesCors
            || 'OPTIONS' !== $request->getMethod() || !$request->headers->has('Access-Control-Request-Method')) {
            return;
        }
        $pedidas = array_filter(array_map(
            static fn (string $h): string => strtolower(trim($h)),
            explode(',', (string) $request->headers->get('Access-Control-Request-Headers', '')),
        ));
        $metodo = strtoupper((string) $request->headers->get('Access-Control-Request-Method'));
        $permitido = $this->permitido($origen)
            && str_contains(self::METODOS, $metodo)
            && [] === array_diff($pedidas, self::CABECERAS);

        $respuesta = new Response($permitido ? 'OK' : 'Disallowed CORS request', $permitido ? 200 : 400, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Vary' => 'Origin',
            'Access-Control-Allow-Methods' => self::METODOS,
            'Access-Control-Allow-Headers' => 'Accept, Authorization, Content-Type, Idempotency-Key, X-Request-ID',
            'Access-Control-Max-Age' => '600',
        ]);
        if ($permitido) {
            $respuesta->headers->set('Access-Control-Allow-Origin', $origen);
        }
        $event->setResponse($respuesta);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -256)]
    public function alResponder(ResponseEvent $event): void
    {
        $origen = $event->getRequest()->headers->get('Origin');
        if (!$event->isMainRequest() || null === $origen || !$this->permitido($origen)) {
            return;
        }
        $h = $event->getResponse()->headers;
        if ($h->has('Access-Control-Allow-Origin')) {
            return;
        }
        $h->set('Access-Control-Allow-Origin', $origen);
        $h->set('Access-Control-Expose-Headers', 'Content-Disposition, X-Request-ID');
        $h->set('Vary', 'Origin', false);
    }

    private function permitido(string $origen): bool
    {
        return \in_array(rtrim($origen, '/'), $this->ajustes->origenesCors, true);
    }
}
