<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Http\ErrorApi;
use App\Seguridad\Autenticado;
use App\Seguridad\Autenticador;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Aplica #[Autenticado] antes de ejecutar la acción: token Bearer obligatorio,
 * validación completa (App\Seguridad\Autenticador) y TODOS los grupos de roles
 * declarados (el del módulo en la clase y el de la acción en el método).
 */
#[AsEventListener(event: KernelEvents::CONTROLLER)]
final readonly class AutenticacionListener
{
    public function __construct(private Autenticador $autenticador)
    {
    }

    public function __invoke(ControllerEvent $event): void
    {
        /** @var list<Autenticado> $reglas */
        $reglas = $event->getAttributes(Autenticado::class);
        if ([] === $reglas) {
            return;
        }
        // Una sesión restringida solo vale si TODAS las reglas lo admiten.
        $permitirRestringida = array_reduce($reglas, static fn (bool $a, Autenticado $r): bool => $a && $r->permitirRestringida, true);

        $cabecera = (string) $event->getRequest()->headers->get('Authorization', '');
        if (1 !== preg_match('/^Bearer\s+(\S+)$/i', $cabecera, $m)) {
            throw ErrorApi::noAutenticado('Not authenticated');
        }
        $usuario = $this->autenticador->autenticar($m[1], $permitirRestringida);
        foreach ($reglas as $regla) {
            if ([] !== $regla->roles && !\in_array($usuario->rol, $regla->roles, true)) {
                throw ErrorApi::prohibido('NOT_ENOUGH_PERMISSIONS');
            }
        }
        // El ocultamiento de costos (OcultarCostosListener) usa el rol de la petición.
        $event->getRequest()->attributes->set('_rol', $usuario->rol);
    }
}
