<?php

declare(strict_types=1);

namespace App\Controller;

use App\Crud\Campo;
use App\Dominio\Ident;
use App\Dominio\Roles;
use App\Http\ClienteHttp;
use App\Http\Entrada;
use App\Http\ErrorApi;
use App\Http\Idempotencia;
use App\Http\Limite;
use App\Inventario\Compras;
use App\Inventario\Esquemas;
use App\Inventario\Solicitudes;
use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Compras: proveedores, órdenes y garantías. Compras (Administración) crea
 * proveedores y órdenes; recibir en bodega, cerrar con faltante y borrar
 * proveedores es de TI.
 */
#[Route('/api/v1/compras', requirements: ['id' => '\d+'])]
#[Autenticado(roles: Roles::LECTURA_COMPRAS)]
final readonly class ComprasController
{
    public function __construct(
        private Compras $compras,
        private Solicitudes $solicitudes,
        private UsuarioActual $actual,
        private Idempotencia $idempotencia,
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

    // ---------------- Órdenes ----------------

    #[Route('/ordenes', methods: ['GET'])]
    public function ordenes(Request $request): JsonResponse
    {
        $e = Entrada::consulta($request);
        $estado = $e->texto('estado', opcional: true);
        $e->validar();

        return new JsonResponse($this->compras->ordenes($estado));
    }

    #[Route('/ordenes/{id}', methods: ['GET'])]
    public function orden(int $id): JsonResponse
    {
        return new JsonResponse($this->compras->orden($id));
    }

    #[Route('/ordenes', methods: ['POST'])]
    #[Autenticado(roles: Roles::ESCRITURA_COMPRAS)]
    #[Limite('30/minute')]
    public function crearOrden(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        [, $numero] = $e->campo(Campo::texto('OCO_Numero', true, 1, 50), true);
        [, $fecha] = $e->campo(new Campo('OCO_Fecha', Campo::FECHA, requerido: true), true);
        $moneda = $e->presente('OCO_Moneda') ? $e->moneda('OCO_Moneda') : 'GTQ';
        [, $notas] = $e->campo(Campo::texto('OCO_Notas', max: 500), true);
        [, $proveedor] = $e->campo(Campo::entero('PRV_Proveedor', true), true);
        [, $sede] = $e->campo(Campo::entero('SED_Sede'), true);
        $lineas = [];
        foreach ($e->lista('lineas', 1000) ?? [] as $item) {
            $s = Entrada::de(\is_array($item) ? $item : []);
            [, $desc] = $s->campo(Campo::texto('OCL_Descripcion', true, 1, 255), true);
            [$hayCant, $cant] = $s->campo(Campo::entero('OCL_Cantidad', minimo: 1), true);
            [$hayPrecio, $precio] = $s->campo(new Campo('OCL_Precio_Unitario', Campo::DECIMAL, max: 14, escala: 2, minimo: 0), true);
            $tipo = $s->patron('OCL_Tipo', '^(ACTIVO|CONSUMIBLE|LICENCIA|OTRO)$');
            [, $activo] = $s->campo(new Campo('ACT_Activo', Campo::UUID), true);
            [, $consumible] = $s->campo(Campo::entero('CON_Consumible'), true);
            [, $licencia] = $s->campo(Campo::entero('LIC_Licencia'), true);
            $e->absorber($s);
            $lineas[] = ['OCL_Descripcion' => $desc, 'OCL_Cantidad' => $hayCant && null !== $cant ? (int) $cant : 1,
                'OCL_Precio_Unitario' => $hayPrecio && null !== $precio ? (string) $precio : '0', 'OCL_Tipo' => $tipo,
                'ACT_Activo' => null === $activo ? null : Ident::aHex((string) $activo), 'CON_Consumible' => $consumible, 'LIC_Licencia' => $licencia];
        }
        $e->validar();

        return new JsonResponse($this->compras->crearOrden(['OCO_Numero' => $numero, 'OCO_Fecha' => $fecha, 'OCO_Moneda' => $moneda ?? 'GTQ', 'OCO_Notas' => $notas,
            'PRV_Proveedor' => $proveedor, 'SED_Sede' => $sede], $lineas, $this->usuario(), ClienteHttp::ip($request)), 201);
    }

    /** Cancelar una orden se solicita (202): la ejecuta el Jefe de TI al aprobarla. */
    #[Route('/ordenes/{id}/estado', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::ESCRITURA_COMPRAS)]
    public function cambiarEstado(int $id, Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $estado = $e->patron('OCO_Estado', '^(BORRADOR|PARCIAL|RECIBIDA|CERRADA_CON_FALTANTE|CANCELADA)$');
        if (!$e->tiene('OCO_Estado')) {
            $e->campo(Campo::texto('OCO_Estado', true), true);
        }
        [, $motivo] = $e->campo(Campo::texto('motivo', max: 500), true);
        $e->validar();
        if ('CANCELADA' !== $estado) {
            throw new ErrorApi(409, 'ORDER_STATE_TRANSITION_NOT_ALLOWED');
        }

        return new JsonResponse($this->solicitudes->solicitarCancelacionOrden($id, $motivo, $this->usuario(), ClienteHttp::ip($request)), 202);
    }

    /** Solicita cerrar una orden parcial aceptando el faltante (202). */
    #[Route('/ordenes/{id}/cerrar', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function cerrar(int $id, Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        [, $motivo] = $e->campo(Campo::texto('motivo', true, 5, 500), true);
        $e->validar();

        return new JsonResponse($this->solicitudes->solicitarCierreOrden($id, (string) $motivo, $this->usuario(), ClienteHttp::ip($request)), 202);
    }

    #[Route('/ordenes/{id}/recepciones', methods: ['GET'])]
    public function recepciones(int $id): JsonResponse
    {
        return new JsonResponse($this->compras->recepciones($id));
    }

    /** Recibe una entrega: suma stock, da de alta activos y registra licencias, todo en una transacción. */
    #[Route('/ordenes/{id}/recibir', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    #[Limite('30/minute')]
    public function recibir(int $id, Request $request): JsonResponse
    {
        $r = Esquemas::recepcion(Entrada::json($request));

        return $this->idempotencia->ejecutar($request, fn (): array => $this->compras->recibir($id, $r, $this->usuario(), ClienteHttp::ip($request)));
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
