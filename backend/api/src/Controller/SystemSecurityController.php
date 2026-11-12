<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dominio\Roles;
use App\Http\ClienteHttp;
use App\Http\Entrada;
use App\Http\ErrorApi;
use App\Repositorio\GobiernoRepositorio;
use App\Security\Mfa\ChangeMfaRequirement;
use App\Security\Mfa\MfaCannotBeDisabled;
use App\Security\Mfa\MfaRequirementStore;
use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
use App\Db\Transaccion;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** System-wide security switches, managed only by the system administrator. */
#[Route('/api/v1/gov/security/mfa')]
#[Autenticado(roles: [Roles::SUPER_ADMIN])]
final readonly class SystemSecurityController
{
    public function __construct(
        private MfaRequirementStore $store,
        private ChangeMfaRequirement $changeRequirement,
        private GobiernoRepositorio $audit,
        private UsuarioActual $currentUser,
        private Transaccion $transaccion,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return new JsonResponse(['required' => $this->store->isRequired()]);
    }

    #[Route('', methods: ['POST'])]
    public function change(Request $request): JsonResponse
    {
        $input = Entrada::json($request);
        $required = $input->logico('required');
        if (null === $required && !$input->conErrores()) {
            $input->errorDeValor('required', 'missing', null);
        }
        $input->validar();

        try {
            $this->transaccion->ejecutar(function () use ($required, $request): void {
                $this->changeRequirement->execute((bool) $required);
                $this->audit->registrarAuditoria('MFA_REQUIREMENT_CHANGED', 'SYS_CONFIGURACION',
                    ['required' => (bool) $required], $this->currentUser->usuario()->id, ClienteHttp::ip($request));
            });
        } catch (MfaCannotBeDisabled) {
            throw new ErrorApi(409, 'MFA_CANNOT_BE_DISABLED');
        }

        return new JsonResponse(['required' => (bool) $required]);
    }
}
