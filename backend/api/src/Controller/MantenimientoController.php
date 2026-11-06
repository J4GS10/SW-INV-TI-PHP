<?php

declare(strict_types=1);

namespace App\Controller;

use App\Crud\Campo;
use App\Dominio\Ident;
use App\Dominio\Roles;
use App\Http\ClienteHttp;
use App\Http\Entrada;
use App\Http\Idempotencia;
use App\Inventario\Mantenimientos;
use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Mantenimiento y soporte: tipos, tickets y sus acciones realizadas. */
#[Route('/api/v1/mantenimiento', requirements: ['id' => '\d+'])]
#[Autenticado(roles: Roles::LECTURA_INVENTARIO)]
final readonly class MantenimientoController
{
    public function __construct(private Mantenimientos $mantenimientos, private UsuarioActual $actual, private Idempotencia $idempotencia)
    {
    }

    private function usuario(): string
    {
        return $this->actual->usuario()->id;
    }

    #[Route('/tipos', methods: ['GET'])]
    public function tipos(): JsonResponse
    {
        return new JsonResponse($this->mantenimientos->tipos());
    }

    #[Route('/tipos/{id}', methods: ['GET'])]
    public function tipo(int $id): JsonResponse
    {
        return new JsonResponse($this->mantenimientos->tipo($id));
    }

    #[Route('/tipos', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearTipo(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        [, $n] = $e->campo(Campo::texto('TMA_Nombre', true, 3, 50), true);
        $e->validar();

        return new JsonResponse($this->mantenimientos->crearTipo((string) $n, $this->usuario(), ClienteHttp::ip($request)), 201);
    }

    #[Route('/tipos/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function cambiarTipo(int $id, Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        [$hay, $n] = $e->campo(Campo::texto('TMA_Nombre', min: 3, max: 50), false);
        $e->validar();

        return new JsonResponse($this->mantenimientos->cambiarTipo($id, $hay ? ['TMA_Nombre' => $n] : [], $this->usuario(), ClienteHttp::ip($request)));
    }

    #[Route('/tipos/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarTipo(int $id, Request $request): Response
    {
        $this->mantenimientos->eliminarTipo($id, $this->usuario(), ClienteHttp::ip($request));

        return new Response(status: 204);
    }

    #[Route('/', methods: ['GET'])]
    #[Route('', methods: ['GET'])]
    public function listar(): JsonResponse
    {
        return new JsonResponse($this->mantenimientos->listar());
    }

    #[Route('/{ticket}', methods: ['GET'], requirements: ['ticket' => '[0-9a-fA-F-]{32,36}'])]
    public function uno(Request $request): JsonResponse
    {
        return new JsonResponse($this->mantenimientos->uno(Entrada::uuidDeRuta($request, 'ticket')));
    }

    #[Route('/', methods: ['POST'])]
    #[Route('', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function abrir(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $d = [];
        foreach (['ACT_Activo', 'PER_Persona_Solicita'] as $c) {
            [, $v] = $e->campo(new Campo($c, Campo::UUID, requerido: true), true);
            $d[$c] = null === $v ? null : Ident::aHex((string) $v);
        }
        foreach ([Campo::entero('TMA_Tipo_Mantenimiento', true), Campo::texto('MAN_Descripcion_Falla', max: 2000, defecto: ''), Campo::entero('TFA_Tipo_Falla'),
            new Campo('MAN_Costo_Total', Campo::DECIMAL, escala: 10, minimo: 0, defecto: '0')] as $c) {
            [$hay, $v] = $e->campo($c, true);
            $d[$c->nombre] = $hay && null !== $v ? $v : $c->defecto;
        }
        $d['MAN_Costo_Total'] = (string) $d['MAN_Costo_Total'];
        $d['MAN_Moneda'] = $e->presente('MAN_Moneda') ? ($e->moneda('MAN_Moneda') ?? 'GTQ') : 'GTQ';
        $detalles = [];
        foreach ($e->lista('detalles', 200) ?? [] as $item) {
            $s = Entrada::de(\is_array($item) ? $item : []);
            $detalles[] = self::detalle($s);
            $e->absorber($s);
        }
        $e->validar();

        return $this->idempotencia->ejecutar($request, fn (): array => $this->mantenimientos->abrir($d, $detalles, $this->usuario(), ClienteHttp::ip($request)), 201);
    }

    #[Route('/{ticket}/cerrar', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function cerrar(Request $request): JsonResponse
    {
        $id = Entrada::uuidDeRuta($request, 'ticket');
        $e = Entrada::json($request);
        [, $costo] = $e->campo(new Campo('MAN_Costo_Total', Campo::DECIMAL, requerido: true, escala: 10, minimo: 0), true);
        $moneda = $e->moneda('MAN_Moneda');
        [, $fecha] = $e->campo(new Campo('MAN_Fecha_Cierre', Campo::FECHAHORA), true);
        $e->validar();

        return new JsonResponse($this->mantenimientos->cerrar($id, (string) $costo, $moneda, $fecha, $this->usuario(), ClienteHttp::ip($request)));
    }

    #[Route('/{ticket}', methods: ['DELETE'], requirements: ['ticket' => '[0-9a-fA-F-]{32,36}'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminar(Request $request): Response
    {
        $this->mantenimientos->eliminar(Entrada::uuidDeRuta($request, 'ticket'), $this->usuario(), ClienteHttp::ip($request));

        return new Response(status: 204);
    }

    #[Route('/{ticket}/detalles', methods: ['GET'])]
    public function detalles(Request $request): JsonResponse
    {
        return new JsonResponse($this->mantenimientos->detalles(Entrada::uuidDeRuta($request, 'ticket')));
    }

    #[Route('/{ticket}/detalles', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function agregarDetalle(Request $request): JsonResponse
    {
        $ticket = Entrada::uuidDeRuta($request, 'ticket');
        $e = Entrada::json($request);
        $d = self::detalle($e);
        $e->validar();

        return new JsonResponse($this->mantenimientos->agregarDetalle($ticket, $d, $this->usuario(), ClienteHttp::ip($request)), 201);
    }

    #[Route('/detalles/{id}', methods: ['PATCH'], priority: 10)]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function cambiarDetalle(int $id, Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $d = self::detalle($e);
        $e->validar();

        return new JsonResponse($this->mantenimientos->cambiarDetalle($id, $d, $this->usuario(), ClienteHttp::ip($request)));
    }

    #[Route('/detalles/{id}', methods: ['DELETE'], priority: 10)]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function eliminarDetalle(int $id, Request $request): Response
    {
        $this->mantenimientos->eliminarDetalle($id, $this->usuario(), ClienteHttp::ip($request));

        return new Response(status: 204);
    }

    /** @return array{DMA_Accion_Realizada: string, DMA_Costo_Item: string, AMA_Accion: ?int} */
    private static function detalle(Entrada $s): array
    {
        [, $accion] = $s->campo(Campo::texto('DMA_Accion_Realizada', max: 255, defecto: ''), true);
        [$hay, $costo] = $s->campo(new Campo('DMA_Costo_Item', Campo::DECIMAL, escala: 10, minimo: 0), true);
        [, $ama] = $s->campo(Campo::entero('AMA_Accion'), true);

        return ['DMA_Accion_Realizada' => (string) ($accion ?? ''), 'DMA_Costo_Item' => $hay && null !== $costo ? (string) $costo : '0',
            'AMA_Accion' => null === $ama ? null : (int) $ama];
    }
}
