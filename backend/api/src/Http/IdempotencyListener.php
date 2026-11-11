<?php

declare(strict_types=1);

namespace App\Http;

use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Every authenticated write (POST, PUT, PATCH, DELETE) that carries an Idempotency-Key
 * runs once, with no list of routes to maintain: a retry after a dropped connection
 * gets the stored answer, a duplicate in flight gets 409, and nothing runs twice
 * (App\Http\Idempotencia, App\Db\IdempotencyCommitMiddleware).
 *
 * It runs after authentication (priority below AutenticacionListener) so the key belongs
 * to the user, and stores the answer after the costs were hidden (OcultarCostosListener).
 */
final readonly class IdempotencyListener
{
    private const array WRITES = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private Idempotencia $store, private IdempotencyContext $context, private UsuarioActual $current)
    {
    }

    #[AsEventListener(event: KernelEvents::CONTROLLER, priority: -16)]
    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !\in_array($request->getMethod(), self::WRITES, true)
            || !$request->headers->has(Idempotencia::CABECERA) || [] === $event->getAttributes(Autenticado::class)) {
            return;
        }
        $claim = $this->store->reservar($request, $this->current->usuario()->id);
        if ($claim instanceof JsonResponse) {
            $event->setController(static fn (): JsonResponse => $claim);

            return;
        }
        $this->context->begin($claim);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -200)]
    public function onResponse(ResponseEvent $event): void
    {
        $claim = $this->context->claim();
        if (!$event->isMainRequest() || null === $claim) {
            return;
        }
        $applied = $this->context->applied();
        $this->context->reset();
        $response = $event->getResponse();
        // Failed without committing anything: the same key can be retried.
        if ($response->getStatusCode() >= 400 && !$applied) {
            $this->store->liberar($claim);

            return;
        }
        $this->store->completar($claim, $response->getStatusCode(), self::jsonBody($response));
    }

    private static function jsonBody(Response $response): ?string
    {
        $content = $response->getContent();
        if (false === $content || '' === $content || !str_contains((string) $response->headers->get('Content-Type', ''), 'json')) {
            return null;
        }

        return json_validate($content) ? $content : null;
    }
}
