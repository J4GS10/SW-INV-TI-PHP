<?php

declare(strict_types=1);

namespace App\Controller;

use App\Crud\Campo;
use App\Dominio\Ident;
use App\Dominio\Roles;
use App\EventListener\OcultarCostosListener;
use App\Http\ClienteHttp;
use App\Http\Entrada;
use App\Http\ErrorApi;
use App\Http\Limite;
use App\Inventario\Activos;
use App\Inventario\Consumibles;
use App\Inventario\EdicionLote;
use App\Inventario\Esquemas;
use App\Inventario\Reclasificacion;
use App\Inventario\Solicitudes;
use App\Organizacion\Sugerencias;
use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Activos (/core). Lectura: inventario y Contabilidad. Mutación: TECNICO o más; baja y lote: administración de TI. */
#[Route('/api/v1/core', requirements: ['id' => '\d+'])]
#[Autenticado(roles: Roles::LECTURA_EXISTENCIAS)]
final readonly class CoreController
{
    public function __construct(
        private Activos $activos,
        private EdicionLote $lote,
        private Solicitudes $solicitudes,
        private Reclasificacion $reclasificacion,
        private Consumibles $consumibles,
        private Sugerencias $sugerencias,
        private UsuarioActual $actual,
        private \App\Config\Ajustes $ajustes,
    ) {
    }

    private function usuario(): string
    {
        return $this->actual->usuario()->id;
    }

    // ---------------- Especificaciones ----------------

    #[Route('/activos/{activo}/especificaciones', methods: ['GET'])]
    public function especificaciones(Request $request): JsonResponse
    {
        return new JsonResponse($this->activos->especificaciones(Entrada::uuidDeRuta($request, 'activo')));
    }

    #[Route('/activos/{activo}/especificaciones', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function agregarEspecificacion(Request $request): JsonResponse
    {
        $activo = Entrada::uuidDeRuta($request, 'activo');
        $e = Entrada::json($request);
        [, $tipo] = $e->campo(Campo::entero('TES_Tipo_Especificacion', true), true);
        [, $valor] = $e->campo(Campo::texto('ESP_Valor', true, 1, 255), true);
        [, $motivo] = $e->campo(Campo::texto('motivo', max: 255), true);
        $e->validar();

        return new JsonResponse($this->activos->agregarEspecificacion($activo, (int) $tipo, (string) $valor, $motivo, $this->usuario(), ClienteHttp::ip($request)), 201);
    }

    #[Route('/especificaciones/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function cambiarEspecificacion(int $id, Request $request): Response
    {
        $e = Entrada::json($request);
        [, $valor] = $e->campo(Campo::texto('ESP_Valor', true, 1, 255), true);
        [, $motivo] = $e->campo(Campo::texto('motivo', max: 255), true);
        $e->validar();
        $this->activos->cambiarEspecificacion($id, (string) $valor, $motivo, $this->usuario(), ClienteHttp::ip($request));

        return new Response(status: 204);
    }

    #[Route('/especificaciones/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function eliminarEspecificacion(int $id, Request $request): Response
    {
        $this->activos->eliminarEspecificacion($id, $this->usuario(), ClienteHttp::ip($request));

        return new Response(status: 204);
    }

    // ---------------- Activos ----------------

    #[Route('/activos', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crear(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $d = $this->camposActivo($e, true);
        $especificaciones = [];
        foreach ($e->lista('especificaciones', 500) ?? [] as $item) {
            $s = Entrada::de(\is_array($item) ? $item : []);
            [, $tipo] = $s->campo(Campo::entero('TES_Tipo_Especificacion', true), true);
            [, $valor] = $s->campo(Campo::texto('ESP_Valor', true, 1, 255), true);
            $s->campo(Campo::texto('motivo', max: 255), true);
            $e->absorber($s);
            $especificaciones[] = ['TES_Tipo_Especificacion' => (int) $tipo, 'ESP_Valor' => (string) $valor];
        }
        $e->validar();
        // Quien no ve costos tampoco los escribe.
        $d = OcultarCostosListener::descartarCostos($d, $this->actual->usuario()->rol, 'ACT_Costo') + ['ACT_Costo' => null];

        return new JsonResponse($this->activos->crear($d, $especificaciones, $this->usuario()), 201);
    }

    /** Edición en lote (antes de /activos/{id}: «lote» no es un id). */
    #[Route('/activos/lote', methods: ['PATCH'], priority: 10)]
    #[Autenticado(roles: Roles::ADMIN)]
    public function editarLote(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $activos = $e->uuids('activos', 500, 1) ?? [];
        $sub = $e->objeto('cambios', true);
        $cambios = null === $sub ? [] : self::cambiosLote($sub);
        if (null !== $sub) {
            $e->absorber($sub);
        }
        $e->validar();

        return new JsonResponse($this->lote->aplicar(array_map(static fn (string $a): array => [$a, $cambios], $activos), $this->usuario(), ClienteHttp::ip($request)));
    }

    /** Deshacer una edición en lote: cada activo vuelve a sus valores anteriores. */
    #[Route('/activos/lote/restaurar', methods: ['POST'], priority: 10)]
    #[Autenticado(roles: Roles::ADMIN)]
    public function restaurarLote(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $items = [];
        $lista = $e->lista('items', 500);
        if (null === $lista || [] === $lista) {
            $e->errorDeValor('items', 'Field required', $lista);
        }
        foreach ($lista ?? [] as $item) {
            $s = Entrada::de(\is_array($item) ? $item : []);
            [, $activo] = $s->campo(new Campo('ACT_Activo', Campo::UUID, requerido: true), true);
            $sub = $s->objeto('cambios', true);
            $cambios = null === $sub ? [] : self::cambiosLote($sub);
            if (null !== $sub) {
                $s->absorber($sub);
            }
            $e->absorber($s);
            if (null !== $activo) {
                $items[] = [Ident::aHex((string) $activo), $cambios];
            }
        }
        $e->validar();

        return new JsonResponse($this->lote->aplicar($items, $this->usuario(), ClienteHttp::ip($request), 'UPDATE_BATCH_UNDO'));
    }

    #[Route('/activos/{activo}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function actualizar(Request $request): JsonResponse
    {
        $id = Entrada::uuidDeRuta($request, 'activo');
        $e = Entrada::json($request);
        $cambios = $this->camposActivo($e, false);
        $e->validar();
        $cambios = OcultarCostosListener::descartarCostos($cambios, $this->actual->usuario()->rol, 'ACT_Costo', 'ACT_Moneda');

        return new JsonResponse($this->activos->actualizar($id, $cambios, $this->usuario()));
    }

    #[Route('/activos', methods: ['GET'])]
    public function listar(Request $request): JsonResponse
    {
        [$saltar, $limite] = Entrada::paginacion($request, $this->ajustes->paginacionMaxima);

        return new JsonResponse($this->activos->listar($saltar, $limite));
    }

    #[Route('/activos/by-code/{codigo}', methods: ['GET'], requirements: ['codigo' => '.+'], priority: 10)]
    public function porCodigo(string $codigo): JsonResponse
    {
        return new JsonResponse($this->activos->porCodigo($codigo));
    }

    #[Route('/activos/{activo}', methods: ['GET'])]
    public function detalle(Request $request): JsonResponse
    {
        return new JsonResponse($this->activos->conRelaciones($this->activos->filaO404(Entrada::uuidDeRuta($request, 'activo'))));
    }

    /** Ya no da de baja: toda salida exige motivo, destino y la aprobación del Jefe de TI. */
    #[Route('/activos/{activo}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminar(Request $request): Response
    {
        Entrada::uuidDeRuta($request, 'activo');

        throw new ErrorApi(409, 'USE_DECOMMISSION_REQUEST');
    }

    #[Route('/activos/search', methods: ['POST'], priority: 10)]
    #[Limite('120/minute')]
    public function buscar(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $f = [];
        [, $f['q']] = $e->campo(Campo::texto('q', max: 64), true);
        foreach (['modelo_id', 'tipo_activo_id', 'estado_operativo_id', 'sede_id'] as $c) {
            [, $v] = $e->campo(Campo::entero($c), true);
            $f[$c] = null === $v ? null : (int) $v;
        }
        [, $f['fecha_compra_start']] = $e->campo(new Campo('fecha_compra_start', Campo::FECHA), true);
        [, $f['fecha_compra_end']] = $e->campo(new Campo('fecha_compra_end', Campo::FECHA), true);
        [$hayPagina, $pagina] = $e->campo(Campo::entero('page', minimo: 1), true);
        [$hayPorPagina, $porPagina] = $e->campo(Campo::entero('per_page', minimo: 1, maximo: 200), true);
        [, $f['sort']] = $e->campo(Campo::texto('sort', max: 16), true);
        [, $f['sort_dir']] = $e->campo(Campo::texto('sort_dir', max: 4), true);
        $e->validar();
        $f['page'] = $hayPagina && null !== $pagina ? (int) $pagina : 1;
        $f['per_page'] = $hayPorPagina && null !== $porPagina ? (int) $porPagina : 20;

        return new JsonResponse($this->activos->buscar($f));
    }

    /** Solicita la salida del inventario; se ejecuta cuando el Jefe de TI la aprueba (202). */
    #[Route('/activos/{activo}/baja', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function solicitarBaja(Request $request): JsonResponse
    {
        $id = Entrada::uuidDeRuta($request, 'activo');
        $baja = Esquemas::baja(Entrada::json($request));

        return new JsonResponse($this->solicitudes->solicitarBaja($id, $baja, $this->usuario(), ClienteHttp::ip($request)), 202);
    }

    // ---------------- Componentes ----------------

    #[Route('/activos/{activo}/componentes', methods: ['GET'])]
    public function componentes(Request $request): JsonResponse
    {
        return new JsonResponse($this->activos->componentes(Entrada::uuidDeRuta($request, 'activo')));
    }

    #[Route('/activos/{activo}/componentes', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function instalarComponente(Request $request): JsonResponse
    {
        $equipo = Entrada::uuidDeRuta($request, 'activo');
        $e = Entrada::json($request);
        [, $comp] = $e->campo(new Campo('ACT_Componente', Campo::UUID, requerido: true), true);
        [, $motivo] = $e->campo(Campo::texto('motivo', max: 255), true);
        $e->validar();

        return new JsonResponse($this->activos->instalarComponente($equipo, Ident::aHex((string) $comp), $motivo, $this->usuario()));
    }

    #[Route('/activos/{activo}/componentes/{componente}/retirar', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function retirarComponente(Request $request): JsonResponse
    {
        $equipo = Entrada::uuidDeRuta($request, 'activo');
        $comp = Entrada::uuidDeRuta($request, 'componente');
        $e = Entrada::json($request);
        [, $motivo] = $e->campo(Campo::texto('motivo', max: 255), true);
        $e->validar();

        return new JsonResponse($this->activos->retirarComponente($equipo, $comp, $motivo, \App\Inventario\EstadosActivo::BODEGA, $this->usuario()));
    }

    /** Consumibles instalados en el equipo y los compatibles con su modelo. */
    #[Route('/activos/{activo}/consumibles', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTURA_INVENTARIO)]
    public function consumibles(Request $request): JsonResponse
    {
        return new JsonResponse($this->consumibles->consumoDeEquipo(Entrada::uuidDeRuta($request, 'activo')));
    }

    // ---------------- Reclasificación de insumos ----------------

    #[Route('/tipos-activo/{id}/reclasificar', methods: ['GET'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function vistaReclasificacion(int $id, Request $request): JsonResponse
    {
        $e = Entrada::consulta($request);
        [, $sede] = $e->campo(Campo::entero('sede'), false);
        $e->validar();

        return new JsonResponse($this->reclasificacion->vistaPrevia($id, null === $sede ? null : (int) $sede));
    }

    #[Route('/tipos-activo/{id}/reclasificar', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function solicitarReclasificacion(int $id, Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $d = [];
        foreach ([Campo::entero('SED_Sede', true), Campo::texto('nombre', true, 2, 100), Campo::texto('CON_Unidad', min: 1, max: 20, defecto: 'unidad'),
            Campo::entero('UME_Unidad'), Campo::entero('CCO_Categoria'), Campo::entero('CON_Stock_Minimo', minimo: 0, defecto: 0),
            Campo::texto('motivo', true, 5, 500)] as $c) {
            [$hay, $v] = $e->campo($c, true);
            $d[$c->nombre] = $hay ? $v : $c->defecto;
        }
        $e->validar();

        return new JsonResponse($this->solicitudes->solicitarReclasificacion($id, $d, $this->usuario(), ClienteHttp::ip($request)), 202);
    }

    #[Route('/activos/{activo}/contexto', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTURA_PERSONAS)]
    public function contexto(Request $request): JsonResponse
    {
        return new JsonResponse($this->sugerencias->activo(Entrada::uuidDeRuta($request, 'activo')));
    }

    // ---------------- Validación ----------------

    /** @return array<string, mixed> en la edición, solo lo enviado */
    private function camposActivo(Entrada $e, bool $creacion): array
    {
        $campos = [Campo::identificador('ACT_Codigo_Interno', min: 3, max: 50), Campo::identificador('ACT_Serie_Fabricante', $creacion, 3, 100),
            Campo::identificador('ACT_Hostname', max: 100), new Campo('ACT_Fecha_Compra', Campo::FECHA, requerido: $creacion, noFutura: true),
            new Campo('ACT_Fin_Garantia', Campo::FECHA), new Campo('ACT_Costo', Campo::DECIMAL, max: 12, escala: 2, minimo: 0),
            Campo::entero('PRV_Proveedor'), Campo::texto('ACT_Factura', max: 60), Campo::entero('MOD_Modelo', $creacion),
            Campo::entero('TAC_Tipo_Activo', $creacion), Campo::entero('EOP_Estado_Operativo', $creacion), Campo::entero('SED_Sede')];
        if ($creacion) {
            $campos[] = new Campo('ACT_Activo_Padre', Campo::UUID);
        }
        $d = [];
        foreach ($campos as $c) {
            [$hay, $v] = $e->campo($c, $creacion);
            if ($hay || $creacion) {
                $d[$c->nombre] = $v;
            }
        }
        if ($creacion) {
            $d['ACT_Moneda'] = $e->presente('ACT_Moneda') ? ($e->moneda('ACT_Moneda') ?? 'GTQ') : 'GTQ';
            $d['ACT_Activo_Padre'] = null === $d['ACT_Activo_Padre'] ? null : Ident::aHex((string) $d['ACT_Activo_Padre']);
            $d['ACT_Codigo_Interno'] = '' === $d['ACT_Codigo_Interno'] ? null : $d['ACT_Codigo_Interno'];
        } elseif ($e->presente('ACT_Moneda')) {
            $d['ACT_Moneda'] = $e->moneda('ACT_Moneda');
        }
        foreach (['ACT_Costo'] as $c) {
            if (\array_key_exists($c, $d) && null !== $d[$c]) {
                $d[$c] = (string) $d[$c];
            }
        }

        return $d;
    }

    /** @return array<string, mixed> CambiosLote (solo lo enviado) */
    private static function cambiosLote(Entrada $s): array
    {
        $d = [];
        foreach ([Campo::entero('ARE_Area_Ubicacion'), new Campo('ACT_Costo', Campo::DECIMAL, max: 12, escala: 2, minimo: 0),
            new Campo('ACT_Fin_Garantia', Campo::FECHA), Campo::entero('PRV_Proveedor'), Campo::texto('ACT_Factura', max: 60)] as $c) {
            [$hay, $v] = $s->campo($c, false);
            if ($hay) {
                $d[$c->nombre] = 'ACT_Costo' === $c->nombre && null !== $v ? (string) $v : $v;
            }
        }
        if ($s->presente('ACT_Moneda')) {
            $d['ACT_Moneda'] = $s->moneda('ACT_Moneda');
        }
        $specs = [];
        foreach ($s->lista('especificaciones', 30) ?? [] as $item) {
            $x = Entrada::de(\is_array($item) ? $item : []);
            [, $tipo] = $x->campo(Campo::entero('TES_Tipo_Especificacion', true), true);
            [, $valor] = $x->campo(Campo::texto('valor', max: 255), true);
            $s->absorber($x);
            $specs[] = ['TES_Tipo_Especificacion' => (int) $tipo, 'valor' => $valor];
        }
        if ($s->presente('especificaciones')) {
            $d['especificaciones'] = $specs;
        }

        return $d;
    }
}
