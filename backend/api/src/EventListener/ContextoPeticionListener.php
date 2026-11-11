<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Config\Ajustes;
use App\Db\ContextoRls;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Cada petición:
 * - lee o genera X-Request-ID (App\EventListener\ProcesadorLog lo añade a los logs)
 *   y lo devuelve en la respuesta;
 * - garantiza que la conexión no traiga el contexto VPD de una petición anterior;
 * - responde con las cabeceras de seguridad del backend anterior.
 */
final readonly class ContextoPeticionListener
{
    public function __construct(private ContextoRls $rls, private Ajustes $ajustes)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 512)]
    public function alIniciar(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $id = (string) $request->headers->get('x-request-id', '');
        if (1 !== preg_match('/^[A-Za-z0-9._-]{1,64}$/', $id)) {
            $id = bin2hex(random_bytes(8));
        }
        $request->attributes->set('_request_id', $id);
        $this->rls->limpiarSiQuedo();
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -512)]
    public function alResponder(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $h = $event->getResponse()->headers;
        $h->set('X-Request-ID', (string) $event->getRequest()->attributes->get('_request_id', ''));
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'DENY');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=(self), payment=(), usb=(), '
            .'interest-cohort=(), browsing-topics=(), fullscreen=(self), '
            .'accelerometer=(), gyroscope=(), magnetometer=()');
        // La API devuelve datos, nunca páginas: nada de lo que responde puede cargar ni ejecutar recursos.
        // Una respuesta que fija su propia política (adjuntos en `sandbox`) la conserva.
        if (!$h->has('Content-Security-Policy')) {
            $h->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }
        // Sin una política propia (logo, foto de perfil), el navegador no guarda la respuesta en disco:
        // inventario, personas y costos no quedan en la caché de un equipo compartido.
        if ('no-cache, private' === $h->get('Cache-Control')) {
            $h->set('Cache-Control', 'no-store');
        }
        if ($this->ajustes->esProduccion()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }
        $h->remove('X-Powered-By');
    }
}
