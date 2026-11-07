<?php

declare(strict_types=1);

namespace App\Controller;

use App\Crud\Campo;
use App\Dominio\Roles;
use App\Http\ClienteHttp;
use App\Http\Entrada;
use App\Http\Limite;
use App\Inventario\Compras;
use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Compras: proveedores y garantías. Compras (Administración) crea proveedores;
 * borrarlos es de TI.
 */
#[Route('/api/v1/compras', requirements: ['id' => '\d+'])]
#[Autenticado(roles: Roles::LECTURA_COMPRAS)]
final readonly class ComprasController
{
    public function __construct(
        private Compras $compras,
        private UsuarioActual $actual,
    ) {
    }

    private function usuario(): string
    {
        return $this->actual->usuario()->id;
    }

    // ---------------- Proveedores ----------------

    #[Route('/proveedores', methods: ['GET'])]
    public function proveedores(Request $request): JsonResponse
    {
        $e = Entrada::consulta($request);
        [, $solo] = $e->campo(Campo::logico('solo_activos'), false);
        $e->validar();

        return new JsonResponse($this->compras->proveedores(true === $solo));
    }

    #[Route('/proveedores/{id}', methods: ['GET'])]
    public function proveedor(int $id): JsonResponse
    {
        return new JsonResponse($this->compras->proveedor($id));
    }

    #[Route('/proveedores', methods: ['POST'])]
    #[Autenticado(roles: Roles::ESCRITURA_COMPRAS)]
    public function crearProveedor(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $d = self::proveedor_($e, true);
        $e->validar();

        return new JsonResponse($this->compras->crearProveedor($d, $this->usuario(), ClienteHttp::ip($request)), 201);
    }

    #[Route('/proveedores/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::ESCRITURA_COMPRAS)]
    public function cambiarProveedor(int $id, Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $d = self::proveedor_($e, false);
        [$hay, $activo] = $e->campo(Campo::logico('PRV_Activo'), false);
        $e->validar();
        if ($hay) {
            $d['PRV_Activo'] = $activo;
        }

        return new JsonResponse($this->compras->cambiarProveedor($id, $d, $this->usuario(), ClienteHttp::ip($request)));
    }

    #[Route('/proveedores/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarProveedor(int $id, Request $request): Response
    {
        $this->compras->eliminarProveedor($id, $this->usuario(), ClienteHttp::ip($request));

        return new Response(status: 204);
    }

    // ---------------- Garantías ----------------

    #[Route('/garantias', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTURA_CONTROL)]
    public function garantias(Request $request): JsonResponse
    {
        [$dias, $solo] = self::ventana($request);

        return new JsonResponse($this->compras->garantias($dias, $solo));
    }

    /** Resumen a los administradores de garantías por vencer o vencidas. */
    #[Route('/garantias/notificar', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    #[Limite('10/minute')]
    public function notificar(Request $request): JsonResponse
    {
        [$dias] = self::ventana($request);

        return new JsonResponse($this->compras->notificarGarantias($dias));
    }

    /** @return array{0: int, 1: bool} */
    private static function ventana(Request $request): array
    {
        $e = Entrada::consulta($request);
        [$hay, $dias] = $e->campo(Campo::entero('dias', minimo: 1, maximo: 3650), false);
        [, $solo] = $e->campo(Campo::logico('solo_alertas'), false);
        $e->validar();

        return [$hay && null !== $dias ? (int) $dias : 90, true === $solo];
    }

    /** @return array<string, mixed> */
    private static function proveedor_(Entrada $e, bool $creacion): array
    {
        $d = [];
        foreach ([Campo::texto('PRV_Nombre', $creacion, 2, 150), Campo::texto('PRV_Identificacion_Fiscal', max: 50), Campo::texto('PRV_Contacto', max: 100),
            new Campo('PRV_Email', Campo::CORREO), Campo::texto('PRV_Telefono', max: 30), Campo::texto('PRV_Direccion', max: 255)] as $c) {
            [$hay, $v] = $e->campo($c, $creacion);
            if ($hay || $creacion) {
                $d[$c->nombre] = $v;
            }
        }

        return $d;
    }
}
