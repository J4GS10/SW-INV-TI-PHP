<?php

declare(strict_types=1);

namespace App\Controller;

use App\Crud\Campo;
use App\Dominio\Ident;
use App\Dominio\Roles;
use App\Http\ClienteHttp;
use App\Http\Entrada;
use App\Inventario\Aprobaciones;
use App\Inventario\Solicitudes;
use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Aprobaciones del Jefe de TI. Lectura: cada uno ve su alcance y sus propias
 * solicitudes. Aprobar o rechazar: ADMIN_TI, nunca quien solicitó.
 * Anular: quien solicitó, mientras siga pendiente.
 */
#[Route('/api/v1/aprobaciones', requirements: ['id' => '\d+'])]
#[Autenticado(roles: Roles::LECTURA_CONTROL)]
final readonly class AprobacionesController
{
    public function __construct(private Aprobaciones $aprobaciones, private Solicitudes $solicitudes, private UsuarioActual $actual)
    {
    }

    #[Route('', methods: ['GET'])]
    public function listar(Request $request): JsonResponse
    {
        $e = Entrada::consulta($request);
        $estado = $e->patron('estado', '^(PENDIENTE|APROBADA|RECHAZADA|ANULADA)$');
        [, $activo] = $e->campo(new Campo('activo_id', Campo::UUID), false);
        $e->validar();
        $yo = Ident::aTexto($this->actual->usuario()->id);

        // La interfaz decide qué botones mostrar (nadie resuelve lo propio).
        return new JsonResponse(array_map(static fn (array $f): array => [...$f, 'es_mia' => $f['APR_Solicitante'] === $yo],
            $this->solicitudes->listar($estado, null === $activo ? null : Ident::aHex((string) $activo))));
    }

    #[Route('/pendientes', methods: ['GET'])]
    public function pendientes(): JsonResponse
    {
        return new JsonResponse(['total' => $this->solicitudes->pendientes()]);
    }

    #[Route('/{id}/aprobar', methods: ['POST'])]
    #[Autenticado(roles: Roles::APROBADORES)]
    public function aprobar(int $id, Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        [, $comentario] = $e->campo(Campo::texto('comentario', max: 500), true);
        $e->validar();

        return new JsonResponse($this->aprobaciones->aprobar($id, $this->actual->usuario()->id, $comentario, ClienteHttp::ip($request)));
    }

    #[Route('/{id}/rechazar', methods: ['POST'])]
    #[Autenticado(roles: Roles::APROBADORES)]
    public function rechazar(int $id, Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        [, $comentario] = $e->campo(Campo::texto('comentario', true, 5, 500), true);
        $e->validar();

        return new JsonResponse($this->aprobaciones->rechazar($id, $this->actual->usuario()->id, (string) $comentario, ClienteHttp::ip($request)));
    }

    #[Route('/{id}/anular', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function anular(int $id, Request $request): JsonResponse
    {
        return new JsonResponse($this->aprobaciones->anular($id, $this->actual->usuario()->id, ClienteHttp::ip($request)));
    }
}
