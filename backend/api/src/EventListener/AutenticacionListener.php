<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Dominio\Roles;
use App\Dominio\Usuario;
use App\Http\ClienteHttp;
use App\Http\ErrorApi;
use App\Seguridad\Autenticado;
use App\Seguridad\Autenticador;
use App\Seguridad\CodigosOperador;
use App\Seguridad\ConCodigoOperador;
use App\Seguridad\Delegacion;
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
    public function __construct(
        private Autenticador $autenticador,
        private CodigosOperador $codigos,
        private Delegacion $delegacion,
    ) {
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
        $usuario = $this->conCodigoOperador($event, $usuario);
        foreach ($reglas as $regla) {
            if ([] !== $regla->roles && !\in_array($usuario->rol, $regla->roles, true)) {
                throw ErrorApi::prohibido('NOT_ENOUGH_PERMISSIONS');
            }
        }
        // El ocultamiento de costos (OcultarCostosListener) usa el rol de la petición.
        $event->getRequest()->attributes->set('_rol', $usuario->rol);
    }

    /**
     * Acción con #[ConCodigoOperador]: si viene el código (o la cuenta es de escáner, que no cambia nada sin él),
     * se comprueba y la acción sigue como su dueño, con sus roles y sus sedes; la cuenta de origen queda anotada.
     */
    private function conCodigoOperador(ControllerEvent $event, Usuario $cuenta): Usuario
    {
        if ([] === $event->getAttributes(ConCodigoOperador::class)) {
            return $cuenta;
        }
        $cabeceras = $event->getRequest()->headers;
        $operador = trim((string) $cabeceras->get(ConCodigoOperador::CABECERA_USUARIO, ''));
        $codigo = trim((string) $cabeceras->get(ConCodigoOperador::CABECERA_CODIGO, ''));
        if ('' === $operador && '' === $codigo) {
            if (Roles::ESCANER === $cuenta->rol) {
                throw ErrorApi::prohibido('OPERATOR_CODE_REQUIRED');
            }

            return $cuenta;
        }
        $dueno = $this->codigos->autorizar($operador, $codigo, $cuenta, ClienteHttp::ip($event->getRequest()));
        $this->autenticador->actuarComo($dueno);
        $this->delegacion->registrar($cuenta);

        return $dueno;
    }
}
