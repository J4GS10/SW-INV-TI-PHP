<?php

declare(strict_types=1);

namespace App\Realtime;

use App\Config\Ajustes;
use App\Dominio\Ident;
use App\Seguridad\UsuarioActual;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Every successful write through the API is announced to the other open browsers,
 * for the whole system with no list to maintain: the resource is the API path.
 * Writes to a user (`org/usuarios/{id}/…`) or to one's own account (`me/…`) are also
 * sent to that user's topic, so a closed session or a role change applies at once.
 */
final readonly class PublishChangesListener
{
    public const string ORIGIN_HEADER = 'X-Client-Id';
    private const array WRITES = ['POST', 'PUT', 'PATCH', 'DELETE'];
    // Signing in or out only concerns the browser that does it.
    private const array IGNORED = ['login', 'logout'];

    public function __construct(private UpdatePublisher $publisher, private UsuarioActual $actual, private Ajustes $ajustes)
    {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -64)]
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !\in_array($request->getMethod(), self::WRITES, true) || $event->getResponse()->getStatusCode() >= 400) {
            return;
        }
        $prefix = rtrim($this->ajustes->prefijoApi, '/').'/';
        $path = $request->getPathInfo();
        if (!str_starts_with($path, $prefix)) {
            return;
        }
        $resource = trim(substr($path, \strlen($prefix)), '/');
        $first = explode('/', $resource)[0];
        if ('' === $resource || \in_array($first, self::IGNORED, true)) {
            return;
        }
        $origin = mb_substr((string) $request->headers->get(self::ORIGIN_HEADER, ''), 0, 64);
        $this->publisher->changed($resource, $origin);

        if (1 === preg_match('#^org/usuarios/([0-9a-fA-F-]{32,36})(/|$)#', $resource, $m) && Ident::esValido($m[1])) {
            $this->publisher->userChanged(Ident::aHex($m[1]), $origin);
        }
        if ('me' === $first) {
            $user = $this->currentUserId();
            if (null !== $user) {
                $this->publisher->userChanged($user, $origin);
            }
        }
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    #[AsEventListener(event: 'console.terminate')]
    #[AsEventListener(event: WorkerMessageHandledEvent::class)]
    public function flush(): void
    {
        $this->publisher->flush();
    }

    private function currentUserId(): ?string
    {
        try {
            return $this->actual->usuario()->id;
        } catch (\LogicException) {
            return null;
        }
    }
}
