<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dominio\Roles;
use App\Operations\OperationalAlerts;
use App\Seguridad\Autenticado;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Open system problems, for whoever keeps the system running. They contain no business data. */
#[Route('/api/v1/gov/alerts')]
#[Autenticado(roles: [Roles::SUPER_ADMIN, Roles::ADMIN_TI])]
final readonly class SystemAlertsController
{
    public function __construct(private OperationalAlerts $alerts)
    {
    }

    #[Route('', methods: ['GET'])]
    public function open(): JsonResponse
    {
        return new JsonResponse($this->alerts->open());
    }
}
