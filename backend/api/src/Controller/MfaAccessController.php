<?php

declare(strict_types=1);

namespace App\Controller;

use App\Autenticacion\InicioSesion;
use App\Dominio\Reloj;
use App\Dominio\Roles;
use App\Http\ClienteHttp;
use App\Http\Entrada;
use App\Http\ErrorApi;
use App\Http\Limite;
use App\Repositorio\GobiernoRepositorio;
use App\Security\Mfa\MfaExemption;
use App\Security\Mfa\SkipMfaEnrollment;
use App\Security\Mfa\TrustedDevices;
use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Flexible MFA: one-time skip in the first sign-in, temporary exemption per account and remembered devices. */
#[Route('/api/v1')]
final readonly class MfaAccessController
{
    public function __construct(
        private MfaExemption $exemption,
        private TrustedDevices $devices,
        private SkipMfaEnrollment $skip,
        private InicioSesion $signIn,
        private GobiernoRepositorio $audit,
        private UsuarioActual $current,
    ) {
    }

    /** Skips setting up MFA in the first sign-in; returns an access token without the restriction. */
    #[Route('/me/2fa/skip', methods: ['POST'])]
    #[Autenticado(permitirRestringida: true)]
    #[Limite('5/minute')]
    public function skipEnrollment(Request $request): Response
    {
        $result = $this->skip->execute($this->current->usuario(), $this->current->payload(),
            ClienteHttp::ip($request), ClienteHttp::userAgent($request));

        return InicioSesion::sinCache(new JsonResponse($this->signIn->respuestaTokens($result['user'], $result['claims'])));
    }

    #[Route('/org/usuarios/{id}/mfa-exemption', methods: ['PUT'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function grantExemption(Request $request): JsonResponse
    {
        $input = Entrada::json($request);
        $until = $input->patron('until', '^\d{4}-\d{2}-\d{2}$');
        $reason = $input->texto('reason', 5, 200);
        if (null === $until && !$input->conErrores()) {
            $input->errorDeTipo('missing', 'until', 'Field required', null);
        }
        $input->validar();

        $end = $this->exemption->grant(Entrada::uuidDeRuta($request), (string) $until, trim((string) $reason),
            $this->current->usuario(), ClienteHttp::ip($request));

        return new JsonResponse(['mfa_exento_hasta' => Reloj::iso($end)]);
    }

    #[Route('/org/usuarios/{id}/mfa-exemption', methods: ['DELETE'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function revokeExemption(Request $request): Response
    {
        $this->exemption->revoke(Entrada::uuidDeRuta($request), $this->current->usuario(), ClienteHttp::ip($request));

        return new Response(null, 204);
    }

    #[Route('/me/trusted-devices', methods: ['GET'])]
    #[Autenticado]
    public function myDevices(): JsonResponse
    {
        return new JsonResponse($this->devices->listFor($this->current->usuario()->id));
    }

    #[Route('/me/trusted-devices/{device}', methods: ['DELETE'], requirements: ['device' => '\d+'])]
    #[Autenticado]
    public function forgetDevice(Request $request, int $device): Response
    {
        $user = $this->current->usuario();
        if (!$this->devices->forget($user->id, $device)) {
            throw new ErrorApi(404, 'TRUSTED_DEVICE_NOT_FOUND');
        }
        $this->audit->registrarAuditoria('MFA_DEVICE_FORGOTTEN', 'INV_USUARIO', ['username' => $user->username, 'device' => $device],
            $user->id, ClienteHttp::ip($request), ClienteHttp::userAgent($request));

        return new Response(null, 204);
    }

    #[Route('/me/trusted-devices', methods: ['DELETE'])]
    #[Autenticado]
    public function forgetAllDevices(Request $request): JsonResponse
    {
        $user = $this->current->usuario();
        $removed = $this->devices->forgetAll($user->id);
        $this->audit->registrarAuditoria('MFA_DEVICE_FORGOTTEN', 'INV_USUARIO', ['username' => $user->username, 'all' => true, 'count' => $removed],
            $user->id, ClienteHttp::ip($request), ClienteHttp::userAgent($request));

        return new JsonResponse(['removed' => $removed]);
    }
}
