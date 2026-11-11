<?php

declare(strict_types=1);

namespace App\Controller;

use App\Crud\Campo;
use App\Dominio\Roles;
use App\EventListener\OcultarCostosListener;
use App\Http\ClienteHttp;
use App\Http\Entrada;
use App\Http\Limite;
use App\Inventario\Aprobaciones;
use App\Inventario\Consumibles;
use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Consumibles: inventario por cantidad. Catálogo y existencias: el inventario,
 * Contabilidad (valores) y Compras (para reabastecer); movimientos, solo el inventario.
 */
#[Route('/api/v1/consumibles', requirements: ['id' => '\d+'])]
#[Autenticado(roles: Roles::APP)]
final readonly class ConsumiblesController
{
    private const array CATALOGO = [...Roles::LECTURA_EXISTENCIAS, Roles::COMPRAS];

    public function __construct(
        private Consumibles $consumibles,
        private Aprobaciones $aprobaciones,
        private UsuarioActual $actual,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[Autenticado(roles: self::CATALOGO)]
    public function listar(Request $request): JsonResponse
    {
        $e = Entrada::consulta($request);
        [, $bajo] = $e->campo(Campo::logico('bajo_stock'), false);
        $e->validar();

        return new JsonResponse($this->consumibles->listar(true === $bajo));
    }

    #[Route('/{id}', methods: ['GET'])]
    #[Autenticado(roles: self::CATALOGO)]
    public function obtener(int $id): JsonResponse
    {
        return new JsonResponse(Consumibles::aApi($this->consumibles->filaO404($id)));
    }

    #[Route('', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crear(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $d = $this->campos($e, true);
        [, $stock] = $e->campo(Campo::entero('CON_Stock_Actual', minimo: 0), true);
        [, $costo] = $e->campo(new Campo('CON_Costo_Unitario', Campo::DECIMAL, max: 14, escala: 4, minimo: 0), true);
        $moneda = $e->moneda('CON_Moneda');
        $e->validar();
        $d += ['CON_Stock_Actual' => $stock ?? 0, 'CON_Costo_Unitario' => $costo, 'CON_Moneda' => $moneda];
        $d['CON_Unidad'] ??= 'unidad';
        $d['CON_Stock_Minimo'] ??= 0;

        return new JsonResponse($this->consumibles->crear($d, $this->actual->usuario()->id, ClienteHttp::ip($request)), 201);
    }

    #[Route('/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function actualizar(int $id, Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $cambios = $this->campos($e, false);
        [$hay, $activo] = $e->campo(Campo::logico('CON_Activo'), false);
        $e->validar();
        if ($hay) {
            $cambios['CON_Activo'] = $activo;
        }

        return new JsonResponse($this->consumibles->actualizar($id, $cambios, $this->actual->usuario()->id, ClienteHttp::ip($request)));
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminar(int $id, Request $request): Response
    {
        $this->consumibles->eliminar($id, $this->actual->usuario()->id, ClienteHttp::ip($request));

        return new Response(status: 204);
    }

    /** Suma stock (compra, reabastecimiento). Sin precio se mantiene el costo promedio. */
    #[Route('/{id}/entrada', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    #[Limite('60/minute')]
    public function entrada(int $id, Request $request): JsonResponse
    {
        $m = $this->movimiento($request);
        // Quien no ve costos tampoco los registra.
        $m = OcultarCostosListener::descartarCostos($m, $this->actual->usuario()->rol, 'precio_unitario', 'moneda') + ['precio_unitario' => null, 'moneda' => null];

        return new JsonResponse($this->consumibles->mover($id, $m, 'ENTRADA',
            $this->actual->usuario()->id, ClienteHttp::ip($request)), 201);
    }

    /** Descuenta stock (entrega a una persona o a un equipo). 409 si no alcanza. */
    #[Route('/{id}/salida', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    #[Limite('60/minute')]
    public function salida(int $id, Request $request): JsonResponse
    {
        $m = $this->movimiento($request);

        return new JsonResponse($this->consumibles->mover($id, $m, 'SALIDA',
            $this->actual->usuario()->id, ClienteHttp::ip($request)), 201);
    }

    /** Fija la existencia real tras un conteo: suba o baje, se pide aprobación (202; fase 8). */
    #[Route('/{id}/ajuste', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    #[Limite('30/minute')]
    public function ajuste(int $id, Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        [, $real] = $e->campo(Campo::entero('CON_Stock_Real', true, minimo: 0), true);
        [, $motivo] = $e->campo(Campo::texto('MOC_Motivo', true, 5, 255), true);
        $e->validar();

        return new JsonResponse($this->aprobaciones->solicitarAjuste($id, (int) $real, (string) $motivo, $this->actual->usuario()->id,
            ClienteHttp::ip($request)), 202);
    }

    #[Route('/{id}/compatibles', methods: ['GET'])]
    #[Autenticado(roles: self::CATALOGO)]
    public function compatibles(int $id): JsonResponse
    {
        return new JsonResponse($this->consumibles->compatibles($id));
    }

    #[Route('/{id}/compatibles', methods: ['PUT'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function fijarCompatibles(int $id, Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $modelos = $e->enteros('modelos', 200) ?? [];
        $e->validar();

        return new JsonResponse($this->consumibles->fijarCompatibles($id, $modelos, $this->actual->usuario()->id, ClienteHttp::ip($request)));
    }

    #[Route('/{id}/movimientos', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTURA_INVENTARIO)]
    public function movimientos(int $id): JsonResponse
    {
        return new JsonResponse($this->consumibles->movimientos($id));
    }

    /**
     * Campos comunes de alta y edición (en la edición, solo los enviados).
     *
     * @return array<string, mixed>
     */
    private function campos(Entrada $e, bool $creacion): array
    {
        $definicion = [
            Campo::texto('CON_Nombre', $creacion, 2, 100),
            Campo::texto('CON_Descripcion', max: 255),
            Campo::texto('CON_Categoria', max: 50),
            Campo::texto('CON_Unidad', min: 1, max: 20),
            Campo::entero('CCO_Categoria'),
            Campo::entero('UME_Unidad'),
            Campo::entero('CON_Stock_Minimo', minimo: 0),
            Campo::entero('SED_Sede'),
        ];
        $d = [];
        foreach ($definicion as $campo) {
            [$hay, $valor] = $e->campo($campo, $creacion);
            if ($creacion || $hay) {
                $d[$campo->nombre] = $valor;
            }
        }

        return $d;
    }

    /** @return array{MOC_Cantidad: int, MOC_Motivo: ?string, PER_Persona: ?string, ACT_Activo: ?string, precio_unitario?: ?string, moneda?: ?string} */
    private function movimiento(Request $request): array
    {
        $e = Entrada::json($request);
        [, $cantidad] = $e->campo(Campo::entero('MOC_Cantidad', true, minimo: 1), true);
        [, $motivo] = $e->campo(Campo::texto('MOC_Motivo', max: 255), true);
        [, $persona] = $e->campo(new Campo('PER_Persona', Campo::UUID), true);
        [, $activo] = $e->campo(new Campo('ACT_Activo', Campo::UUID), true);
        [$hayPrecio, $precio] = $e->campo(new Campo('precio_unitario', Campo::DECIMAL, max: 14, escala: 2, minimo: 0), true);
        $moneda = $e->moneda('moneda');
        $e->validar();
        $m = ['MOC_Cantidad' => (int) $cantidad, 'MOC_Motivo' => $motivo,
            'PER_Persona' => null === $persona ? null : \App\Dominio\Ident::aHex((string) $persona),
            'ACT_Activo' => null === $activo ? null : \App\Dominio\Ident::aHex((string) $activo)];
        if ($hayPrecio) {
            $m['precio_unitario'] = null === $precio ? null : (string) $precio;
        }
        if ($e->presente('moneda')) {
            $m['moneda'] = $moneda;
        }

        return $m + ['precio_unitario' => null, 'moneda' => null];
    }
}
