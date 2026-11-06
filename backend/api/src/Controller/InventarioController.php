<?php

declare(strict_types=1);

namespace App\Controller;

use App\Crud\Campo;
use App\Dominio\Ident;
use App\Dominio\Roles;
use App\Http\ClienteHttp;
use App\Http\ConsultaKardex;
use App\Http\Entrada;
use App\Http\Limite;
use App\Inventario\CierreMensual;
use App\Inventario\Esquemas;
use App\Inventario\Faltantes;
use App\Inventario\Reportes;
use App\Inventario\Tomas;
use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Trazabilidad e inventario: kardex y su verificación, custodia a una fecha,
 * entradas y salidas, resumen por motivos, bandeja de atención, cierre mensual,
 * faltantes y tomas de inventario físico. Los archivos (CSV, XLSX, PDF) están en
 * ArchivosInventarioController.
 */
#[Route('/api/v1/inventario', requirements: ['id' => '\d+'])]
#[Autenticado(roles: Roles::APP)]
final readonly class InventarioController
{
    public function __construct(
        private Reportes $reportes,
        private CierreMensual $cierre,
        private Faltantes $faltantes,
        private Tomas $tomas,
        private UsuarioActual $actual,
    ) {
    }

    private function usuario(): string
    {
        return $this->actual->usuario()->id;
    }

    #[Route('/kardex', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTURA_INVENTARIO)]
    public function kardex(Request $request): JsonResponse
    {
        $e = Entrada::consulta($request);
        $f = ConsultaKardex::filtros($request, $e);
        [$hayL, $limite] = $e->campo(Campo::entero('limite', minimo: 1, maximo: 500), false);
        [$hayD, $desp] = $e->campo(Campo::entero('desplazamiento', minimo: 0), false);
        $e->validar();

        return new JsonResponse($this->reportes->kardex($f, $hayL && null !== $limite ? (int) $limite : 50, $hayD && null !== $desp ? (int) $desp : 0));
    }

    /** Recalcula el sello encadenado: confirma que ningún evento se alteró, borró ni intercaló. */
    #[Route('/kardex/verificar', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTURA_CONTROL)]
    public function verificar(): JsonResponse
    {
        return new JsonResponse($this->reportes->verificarKardex());
    }

    #[Route('/custodia', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTURA_INVENTARIO)]
    public function custodia(Request $request): JsonResponse
    {
        $e = Entrada::consulta($request);
        [, $fecha] = $e->campo(new Campo('fecha', Campo::FECHA), false);
        [, $persona] = $e->campo(new Campo('persona_id', Campo::UUID), false);
        [, $area] = $e->campo(Campo::entero('area_id'), false);
        [, $sede] = $e->campo(Campo::entero('sede_id'), false);
        $e->validar();

        return new JsonResponse($this->reportes->custodia($fecha, null === $persona ? null : Ident::aHex((string) $persona),
            null === $area ? null : (int) $area, null === $sede ? null : (int) $sede));
    }

    /** @return array{0: ?string, 1: ?string, 2: ?int} */
    private static function periodo(Request $request): array
    {
        $e = Entrada::consulta($request);
        [, $desde] = $e->campo(new Campo('desde', Campo::FECHA), false);
        [, $hasta] = $e->campo(new Campo('hasta', Campo::FECHA), false);
        [, $sede] = $e->campo(Campo::entero('sede_id'), false);
        $e->validar();

        return [$desde, $hasta, null === $sede ? null : (int) $sede];
    }

    #[Route('/entradas-salidas', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTURA_EXISTENCIAS)]
    public function entradasSalidas(Request $request): JsonResponse
    {
        return new JsonResponse($this->reportes->entradasSalidas(...self::periodo($request)));
    }

    #[Route('/resumen-motivos', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTURA_INVENTARIO)]
    public function resumenMotivos(Request $request): JsonResponse
    {
        return new JsonResponse($this->reportes->resumenMotivos(...self::periodo($request)));
    }

    #[Route('/atencion', methods: ['GET'])]
    public function atencion(): JsonResponse
    {
        return new JsonResponse($this->reportes->atencion($this->actual->usuario()->rol));
    }

    /** Congela el mes: lo reportado queda igual aunque después cambien los datos. */
    #[Route('/cierre-mensual/cerrar', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function cerrarMes(Request $request): JsonResponse
    {
        $e = Entrada::consulta($request);
        [, $anio] = $e->campo(Campo::entero('anio', true, minimo: 2000, maximo: 2100), true);
        [, $mes] = $e->campo(Campo::entero('mes', true, minimo: 1, maximo: 12), true);
        [, $sede] = $e->campo(Campo::entero('sede_id'), false);
        $e->validar();

        return new JsonResponse($this->cierre->cerrar((int) $anio, (int) $mes, null === $sede ? null : (int) $sede, $this->usuario(), ClienteHttp::ip($request)), 201);
    }

    #[Route('/cierre-mensual', methods: ['GET'])]
    #[Autenticado(roles: Roles::EXPORTACION_FINANZAS)]
    public function cierreMensual(Request $request): JsonResponse
    {
        $e = Entrada::consulta($request);
        [, $anio] = $e->campo(Campo::entero('anio', true, minimo: 2000, maximo: 2100), true);
        [, $mes] = $e->campo(Campo::entero('mes', true, minimo: 1, maximo: 12), true);
        [, $sede] = $e->campo(Campo::entero('sede_id'), false);
        $e->validar();

        return new JsonResponse($this->cierre->cierre((int) $anio, (int) $mes, null === $sede ? null : (int) $sede));
    }

    // ---------------- Faltantes ----------------

    #[Route('/faltantes', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTURA_INVENTARIO)]
    public function faltantes(Request $request): JsonResponse
    {
        $e = Entrada::consulta($request);
        $estado = $e->patron('estado', '^(PENDIENTE|EN_INVESTIGACION|LOCALIZADO|BAJA_SOLICITADA|DADO_DE_BAJA)$');
        [, $abiertos] = $e->campo(Campo::logico('abiertos'), false);
        $e->validar();

        return new JsonResponse($this->faltantes->listar($estado, true === $abiertos));
    }

    #[Route('/faltantes/{id}/seguimiento', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function seguimiento(int $id, Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        [, $nota] = $e->campo(Campo::texto('nota', true, 3, 500), true);
        $e->validar();

        return new JsonResponse($this->faltantes->seguimiento($id, (string) $nota, $this->usuario(), ClienteHttp::ip($request)));
    }

    #[Route('/faltantes/{id}/localizado', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function localizado(int $id, Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        [, $nota] = $e->campo(Campo::texto('nota', true, 3, 500), true);
        [, $area] = $e->campo(Campo::entero('ARE_Area'), true);
        $e->validar();

        return new JsonResponse($this->faltantes->localizar($id, (string) $nota, null === $area ? null : (int) $area, $this->usuario(), ClienteHttp::ip($request)));
    }

    /** Baja por pérdida o robo de un faltante: va a aprobación del Jefe de TI. */
    #[Route('/faltantes/{id}/baja', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function bajaFaltante(int $id, Request $request): JsonResponse
    {
        return new JsonResponse($this->faltantes->solicitarBaja($id, Esquemas::baja(Entrada::json($request)), $this->usuario(), ClienteHttp::ip($request)), 202);
    }

    // ---------------- Tomas ----------------

    #[Route('/tomas', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTURA_INVENTARIO)]
    public function tomas(): JsonResponse
    {
        return new JsonResponse($this->tomas->listar());
    }

    #[Route('/tomas', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function crearToma(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        [, $sede] = $e->campo(Campo::entero('SED_Sede', true), true);
        [, $area] = $e->campo(Campo::entero('ARE_Area'), true);
        [, $obs] = $e->campo(Campo::texto('TOM_Observacion', max: 500), true);
        $e->validar();

        return new JsonResponse($this->tomas->crear((int) $sede, null === $area ? null : (int) $area, $obs, $this->usuario(), ClienteHttp::ip($request)), 201);
    }

    #[Route('/tomas/{id}', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTURA_INVENTARIO)]
    public function toma(int $id): JsonResponse
    {
        return new JsonResponse($this->tomas->detalle($id));
    }

    #[Route('/tomas/{id}/lecturas', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    #[Limite('600/minute')]
    public function leer(int $id, Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        [, $codigo] = $e->campo(Campo::texto('codigo', true, 1, 100), true);
        [, $area] = $e->campo(Campo::entero('ARE_Area'), true);
        [, $obs] = $e->campo(Campo::texto('observacion', max: 255), true);
        $e->validar();

        return new JsonResponse($this->tomas->leer($id, (string) $codigo, null === $area ? null : (int) $area, $obs, $this->usuario()), 201);
    }

    #[Route('/tomas/{id}/cerrar', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function cerrarToma(int $id, Request $request): JsonResponse
    {
        $e = Entrada::consulta($request);
        [, $actualizar] = $e->campo(Campo::logico('actualizar_ubicacion'), false);
        $e->validar();

        $toma = $this->tomas->cerrar($id, true === $actualizar, $this->usuario(), ClienteHttp::ip($request));

        return new JsonResponse($toma, 'CERRANDO' === ($toma['TOM_Estado'] ?? null) ? 202 : 200);
    }

    #[Route('/tomas/{id}/anular', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function anularToma(int $id, Request $request): JsonResponse
    {
        return new JsonResponse($this->tomas->anular($id, $this->usuario(), ClienteHttp::ip($request)));
    }
}
