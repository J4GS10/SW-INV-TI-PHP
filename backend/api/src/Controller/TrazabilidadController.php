<?php

declare(strict_types=1);

namespace App\Controller;

use App\Crud\Campo;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\Roles;
use App\Http\ClienteHttp;
use App\Http\Entrada;
use App\Http\ErrorValidacion;
use App\Http\Limite;
use App\Inventario\Movimientos;
use App\Inventario\OperacionesLote;
use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Trazabilidad: tipos de movimiento, asignaciones, devoluciones, transferencias, offboarding, equipos compartidos y lotes. */
#[Route('/api/v1/trazabilidad', requirements: ['id' => '\d+'])]
#[Autenticado(roles: Roles::LECTURA_INVENTARIO)]
final readonly class TrazabilidadController
{
    public function __construct(
        private Movimientos $movimientos,
        private \App\Inventario\TiposMovimiento $tiposMovimiento,
        private \App\Inventario\SalidaDePersona $salidas,
        private \App\Inventario\InstalacionEnArea $instalacionEnArea,
        private OperacionesLote $lote,
        private UsuarioActual $actual,
    ) {
    }

    private function usuario(): string
    {
        return $this->actual->usuario()->id;
    }

    // ---------------- Tipos ----------------

    #[Route('/tipos', methods: ['GET'])]
    public function tipos(): JsonResponse
    {
        return new JsonResponse($this->tiposMovimiento->tipos());
    }

    #[Route('/tipos/{id}', methods: ['GET'])]
    public function tipo(int $id): JsonResponse
    {
        return new JsonResponse($this->tiposMovimiento->tipo($id));
    }

    #[Route('/tipos', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearTipo(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        [, $nombre] = $e->campo(Campo::texto('TMO_Nombre', true, 3, 50), true);
        $e->validar();

        return new JsonResponse($this->tiposMovimiento->crearTipo((string) $nombre, $this->usuario(), ClienteHttp::ip($request)), 201);
    }

    #[Route('/tipos/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function cambiarTipo(int $id, Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        [$hay, $nombre] = $e->campo(Campo::texto('TMO_Nombre', min: 3, max: 50), false);
        $e->validar();

        return new JsonResponse($this->tiposMovimiento->cambiarTipo($id, $hay ? ['TMO_Nombre' => $nombre] : [], $this->usuario(), ClienteHttp::ip($request)));
    }

    #[Route('/tipos/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarTipo(int $id, Request $request): Response
    {
        $this->tiposMovimiento->eliminarTipo($id, $this->usuario(), ClienteHttp::ip($request));

        return new Response(status: 204);
    }

    // ---------------- Movimientos ----------------

    #[Route('/movimientos', methods: ['GET'])]
    public function listar(Request $request): JsonResponse
    {
        [$saltar, $limite] = Entrada::paginacion($request);

        return new JsonResponse($this->movimientos->listar($saltar, $limite));
    }

    /** Asigna (o presta) un activo. Admite `Idempotency-Key`. */
    #[Route('/movimientos', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    #[Limite('60/minute')]
    public function asignar(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $d = self::uuids($e, ['ACT_Activo', 'PER_Persona'], true);
        foreach ([Campo::entero('ARE_Area', true), Campo::entero('TMO_Tipo_Movimiento', true), Campo::texto('MOV_Observacion', max: 1000),
            Campo::texto('ACT_Hostname', max: 100), new Campo('MOV_Fecha_Prevista_Devolucion', Campo::FECHA), ...self::camposMotivo()] as $c) {
            [, $d[$c->nombre]] = $e->campo($c, true);
        }
        if (null !== $d['MOV_Fecha_Prevista_Devolucion'] && $d['MOV_Fecha_Prevista_Devolucion'] < Reloj::ahora()->format('Y-m-d')) {
            $e->errorDeValor('MOV_Fecha_Prevista_Devolucion', 'La fecha prevista de devolución no puede estar en el pasado', $d['MOV_Fecha_Prevista_Devolucion']);
        }
        $e->validar();

        return new JsonResponse($this->movimientos->asignar($d, $this->usuario(), ClienteHttp::ip($request)), 201);
    }

    #[Route('/devolucion', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    #[Limite('60/minute')]
    public function devolver(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $d = self::uuids($e, ['ACT_Activo'], true);
        foreach ([...self::camposMotivo(), Campo::entero('ARE_Area_Destino')] as $c) {
            [, $d[$c->nombre]] = $e->campo($c, true);
        }
        $d['condicion'] = $e->patron('condicion', '^(BUENO|CON_DANO)$');
        $e->validar();

        return new JsonResponse($this->movimientos->devolver($d, $this->usuario(), ClienteHttp::ip($request)));
    }

    #[Route('/transferencia', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    #[Limite('30/minute')]
    public function transferir(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $d = self::uuids($e, ['ACT_Activo', 'PER_Persona_Destino'], true);
        foreach ([Campo::entero('ARE_Area_Destino', true), Campo::texto('MOV_Observacion', max: 1000), ...self::camposMotivo()] as $c) {
            [, $d[$c->nombre]] = $e->campo($c, true);
        }
        $e->validar();

        return new JsonResponse($this->movimientos->transferir($d, $this->usuario(), ClienteHttp::ip($request)), 201);
    }

    #[Route('/activo/{activo}/historial', methods: ['GET'])]
    public function historial(Request $request): JsonResponse
    {
        return new JsonResponse($this->movimientos->historial(Entrada::uuidDeRuta($request, 'activo')));
    }

    #[Route('/persona/{persona}/asignaciones', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTURA_PERSONAS)]
    public function asignaciones(Request $request): JsonResponse
    {
        return new JsonResponse($this->movimientos->asignacionesDe(Entrada::uuidDeRuta($request, 'persona')));
    }

    /** Salida de una persona: cierra sus custodias, libera licencias y desactiva su cuenta, en una transacción. */
    #[Route('/persona/{persona}/offboarding', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    #[Limite('10/minute')]
    public function offboarding(Request $request): JsonResponse
    {
        $persona = Entrada::uuidDeRuta($request, 'persona');
        $e = Entrada::consulta($request);
        [$hay, $desactivar] = $e->campo(Campo::logico('desactivar_usuario'), false);
        $e->validar();
        $u = $this->actual->usuario();

        return new JsonResponse($this->salidas->offboarding($persona, !$hay || null === $desactivar || true === $desactivar, $u->id, $u->rol, ClienteHttp::ip($request)));
    }

    // ---------------- Equipos compartidos ----------------

    #[Route('/instalacion/{activo}', methods: ['GET'])]
    public function estadoInstalacion(Request $request): JsonResponse
    {
        return new JsonResponse($this->instalacionEnArea->estadoInstalacion(Entrada::uuidDeRuta($request, 'activo')));
    }

    #[Route('/instalar-area', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function instalarEnArea(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $d = self::uuids($e, ['ACT_Activo'], true) + self::uuids($e, ['PER_Responsable'], false);
        foreach ([Campo::entero('ARE_Area', true), ...self::camposMotivo()] as $c) {
            [, $d[$c->nombre]] = $e->campo($c, true);
        }
        $e->validar();

        return new JsonResponse($this->instalacionEnArea->instalarEnArea($d, $this->usuario(), ClienteHttp::ip($request)));
    }

    #[Route('/retirar-area', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function retirarDeArea(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $d = self::uuids($e, ['ACT_Activo'], true);
        foreach ([Campo::entero('ARE_Area_Destino'), ...self::camposMotivo()] as $c) {
            [, $d[$c->nombre]] = $e->campo($c, true);
        }
        $d['condicion'] = $e->patron('condicion', '^(BUENO|CON_DANO)$');
        $e->validar();

        return new JsonResponse($this->instalacionEnArea->retirarDeArea($d, $this->usuario(), ClienteHttp::ip($request)));
    }

    // ---------------- Lotes ----------------

    #[Route('/movimientos/revertir', methods: ['POST'], priority: 10)]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function revertir(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $movimientos = $e->uuids('movimientos', 50, 1) ?? [];
        $instalaciones = $e->enteros('instalaciones', 20) ?? [];
        $e->validar();
        $u = $this->actual->usuario();

        return new JsonResponse($this->lote->revertir($movimientos, $instalaciones, $u->id, $u->rol, ClienteHttp::ip($request)));
    }

    #[Route('/entrega-lote', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    #[Limite('20/minute')]
    public function entregaLote(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $d = self::uuids($e, ['PER_Persona'], true);
        $d['activos'] = self::sinRepetidos($e, $e->uuids('activos', 50, 1) ?? [], $request);
        foreach ([Campo::entero('ARE_Area', true), Campo::texto('MOV_Observacion', max: 900), Campo::texto('ticket', max: 50), ...self::camposMotivo()] as $c) {
            [, $d[$c->nombre]] = $e->campo($c, true);
        }
        $d['licencias'] = $e->enteros('licencias', 20) ?? [];
        $e->validar();

        return new JsonResponse($this->lote->entrega($d, $this->usuario(), ClienteHttp::ip($request)), 201);
    }

    #[Route('/devolucion-lote', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    #[Limite('20/minute')]
    public function devolucionLote(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $d = ['activos' => self::sinRepetidos($e, $e->uuids('activos', 100, 1) ?? [], $request)];
        foreach ([...self::camposMotivo(), Campo::entero('ARE_Area_Destino')] as $c) {
            [, $d[$c->nombre]] = $e->campo($c, true);
        }
        $d['condicion'] = $e->patron('condicion', '^(BUENO|CON_DANO)$');
        $e->validar();

        return new JsonResponse($this->lote->devolucion($d, $this->usuario(), ClienteHttp::ip($request)));
    }

    // ---------------- Validación ----------------

    /** @return list<Campo> */
    private static function camposMotivo(): array
    {
        return [Campo::texto('MOV_Motivo', max: 255), Campo::entero('MMO_Motivo'), Campo::texto('MOV_Motivo_Detalle', max: 255)];
    }

    /**
     * @param list<string> $campos
     *
     * @return array<string, ?string> en hex
     */
    private static function uuids(Entrada $e, array $campos, bool $requeridos): array
    {
        $d = [];
        foreach ($campos as $c) {
            [, $v] = $e->campo(new Campo($c, Campo::UUID, requerido: $requeridos), true);
            $d[$c] = null === $v ? null : Ident::aHex((string) $v);
        }

        return $d;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private static function sinRepetidos(Entrada $e, array $ids, Request $request): array
    {
        if (\count($ids) !== \count(array_unique($ids))) {
            $e->errorDeValor('activos', 'DUPLICATED_ASSETS', json_decode($request->getContent(), true)['activos'] ?? null);
        }

        return $ids;
    }
}
