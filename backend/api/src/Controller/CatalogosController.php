<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Versioned;
use App\Http\ClienteHttp;
use App\Http\Peticion;
use App\Catalogo\CatalogoGenerico;
use App\Catalogo\RegistroCatalogos;
use App\Crud\Campo;
use App\Crud\Operaciones;
use App\Dominio\Roles;
use App\Validacion\Entrada;
use App\Seguridad\Autenticado;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Centro de catálogos: CRUD genérico, reorden y fusión de duplicados, más las
 * especificaciones que pide cada tipo de activo y los kits estándar de puesto.
 * Lectura: roles de la aplicación. Escritura: ADMIN (la fusión, además, alcance global).
 */
#[Route('/api/v1/catalogos')]
#[Autenticado(roles: Roles::APP)]
final readonly class CatalogosController
{
    public function __construct(
        private CatalogoGenerico $catalogos,
        private Operaciones $crud,
        private \App\Catalogo\EspecificacionesPorTipo $especificaciones,
        private \App\Catalogo\KitsEstandar $kits,
    ) {
    }

    /** Catálogos administrables, con su grupo y sus campos propios. */
    #[Route('', methods: ['GET'])]
    public function listarCatalogos(): JsonResponse
    {
        return new JsonResponse(array_values(array_map(CatalogoGenerico::describir(...), RegistroCatalogos::todos())));
    }

    // ── Especificaciones por tipo de activo ─────────────────────────────────

    /** Especificaciones que pide cada tipo de activo: {TAC_Tipo_Activo: [...]}. */
    #[Route('/especificaciones-tipo', methods: ['GET'], priority: 10)]
    public function todasLasEspecificaciones(): JsonResponse
    {
        return new JsonResponse((object) $this->especificaciones->todas());
    }

    #[Route('/especificaciones-tipo/{tipo}', methods: ['GET'], priority: 10)]
    public function especificacionesDeTipo(Request $r): JsonResponse
    {
        return new JsonResponse($this->especificaciones->de(Peticion::enteroDeRuta($r, 'tipo')));
    }

    /** Reemplaza la lista de especificaciones que pide el tipo (en el orden recibido). */
    #[Route('/especificaciones-tipo/{tipo}', methods: ['PUT'], priority: 10)]
    #[Autenticado(roles: Roles::ADMIN)]
    public function fijarEspecificaciones(Request $r): JsonResponse
    {
        $tipo = Peticion::enteroDeRuta($r, 'tipo');
        $entrada = Peticion::json($r);
        $items = [];
        foreach ($entrada->lista('items', 40) ?? [] as $i => $item) {
            $sub = Entrada::de(\is_array($item) ? $item : []);
            [, $tes] = $sub->campo(Campo::entero('TES_Tipo_Especificacion', true), true);
            [, $obligatoria] = $sub->campo(Campo::logico('obligatoria'), false);
            // Valores permitidos: textos de hasta 255 caracteres (lo que guarda la tabla), nunca listas ni números.
            $valores = $sub->textos('valores', 50) ?? [];
            foreach ($valores as $j => $v) {
                if (mb_strlen(trim($v)) > 255) {
                    $sub->errorDeTipo('string_too_long', "valores.{$j}", 'String should have at most 255 characters', mb_substr($v, 0, 100), ['max_length' => 255]);
                }
            }
            try {
                $sub->validar();
            } catch (\App\Validacion\ErrorValidacion $e) {
                throw new \App\Validacion\ErrorValidacion(array_map(static fn (array $x): array => [...$x, 'loc' => ['body', 'items', $i, ...\array_slice($x['loc'], 1)]], $e->errores));
            }
            $items[] = ['TES_Tipo_Especificacion' => (int) $tes, 'obligatoria' => (bool) $obligatoria,
                'valores' => array_values(array_filter(array_map(trim(...), $valores), static fn (string $v): bool => '' !== $v))];
        }
        $entrada->validar();

        return new JsonResponse($this->especificaciones->fijar($tipo, $items, ClienteHttp::origen($r)));
    }

    // ── Kits estándar de puesto ─────────────────────────────────────────────

    #[Route('/kits', methods: ['GET'], priority: 10)]
    public function listarKits(): JsonResponse
    {
        return new JsonResponse($this->kits->listar());
    }

    #[Route('/kits', methods: ['POST'], priority: 10)]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearKit(Request $r): JsonResponse
    {
        return new JsonResponse($this->kits->crear($this->datosKit($r), ClienteHttp::origen($r)), 201);
    }

    #[Route('/kits/{kit}', methods: ['PUT'], priority: 10)]
    #[Versioned('INV_KIT_ESTANDAR', 'KIT_Kit', parameter: 'kit')]
    #[Autenticado(roles: Roles::ADMIN)]
    public function editarKit(Request $r): JsonResponse
    {
        return new JsonResponse($this->kits->editar(Peticion::enteroDeRuta($r, 'kit'), $this->datosKit($r), ClienteHttp::origen($r)));
    }

    #[Route('/kits/{kit}', methods: ['DELETE'], priority: 10)]
    #[Autenticado(roles: Roles::ADMIN)]
    public function borrarKit(Request $r): Response
    {
        $this->kits->borrar(Peticion::enteroDeRuta($r, 'kit'), ClienteHttp::origen($r));

        return new Response(null, 204);
    }

    // ── Catálogos estándar ──────────────────────────────────────────────────

    #[Route('/{clave}', methods: ['GET'])]
    public function listarItems(string $clave, Request $r): JsonResponse
    {
        $q = Peticion::consulta($r);
        [, $inactivos] = $q->campo(Campo::logico('inactivos'), false);
        $q->validar();

        return new JsonResponse($this->catalogos->listar($clave, (bool) $inactivos));
    }

    #[Route('/{clave}', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearItem(string $clave, Request $r): JsonResponse
    {
        $datos = $this->datosItem($r, true);

        return new JsonResponse($this->catalogos->crear($clave, $datos, $this->auditor($r)), 201);
    }

    #[Route('/{clave}/{item}', methods: ['PATCH'], requirements: ['item' => '[^/]+'])]
    #[Versioned(catalog: 'clave', parameter: 'item')]
    #[Autenticado(roles: Roles::ADMIN)]
    public function editarItem(string $clave, Request $r): JsonResponse
    {
        $id = Peticion::enteroDeRuta($r, 'item');
        $datos = $this->datosItem($r, false);

        return new JsonResponse($this->catalogos->editar($clave, $id, $datos, $this->auditor($r)));
    }

    #[Route('/{clave}/reordenar', methods: ['POST'], priority: 5)]
    #[Autenticado(roles: Roles::ADMIN)]
    public function reordenar(string $clave, Request $r): Response
    {
        $entrada = Peticion::json($r);
        $ids = $entrada->lista('ids', 500);
        if (null === $ids) {
            $entrada->campo(new Campo('ids', requerido: true), true);
        } elseif ([] === $ids) {
            $entrada->errorDeValor('ids', 'TOO_SHORT', $ids);
        }
        foreach ($ids ?? [] as $i => $id) {
            if (!\is_int($id)) {
                $entrada->errorDeValor("ids.{$i}", 'INT_REQUIRED', $id);
            }
        }
        $entrada->validar();
        $this->catalogos->reordenar($clave, array_map(intval(...), $ids ?? []), $this->auditor($r));

        return new Response(null, 204);
    }

    /** Cuántos registros se moverán si se fusiona `origen` en `destino`. */
    #[Route('/{clave}/{origen}/fusionar/{destino}', methods: ['GET'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function previaFusion(string $clave, Request $r): JsonResponse
    {
        return new JsonResponse($this->catalogos->previaFusion($clave, Peticion::enteroDeRuta($r, 'origen'), Peticion::enteroDeRuta($r, 'destino')));
    }

    #[Route('/{clave}/{origen}/fusionar/{destino}', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function fusionar(string $clave, Request $r): JsonResponse
    {
        return new JsonResponse($this->catalogos->fusionar($clave, Peticion::enteroDeRuta($r, 'origen'), Peticion::enteroDeRuta($r, 'destino'), $this->auditor($r)));
    }

    // ── apoyo ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function datosItem(Request $r, bool $creacion): array
    {
        $entrada = Peticion::json($r);
        $datos = [];
        foreach ([Campo::texto('nombre', $creacion, 1, 150), Campo::texto('descripcion', max: 255),
            Campo::entero('orden', minimo: 0, maximo: 100000), Campo::logico('activo')] as $campo) {
            if ('activo' === $campo->nombre && $creacion) {
                continue;
            }
            [$presente, $valor] = $entrada->campo($campo, $creacion);
            if ($presente) {
                $datos[$campo->nombre] = $valor;
            }
        }
        $extras = $entrada->tiene('extras') ? $r->toArray()['extras'] : [];
        if (!\is_array($extras) || (array_is_list($extras) && [] !== $extras)) {
            $entrada->errorDeValor('extras', 'DICT_REQUIRED', $extras);
        }
        $entrada->validar();
        $datos['extras'] = $extras;

        return $datos;
    }

    private function auditor(Request $r): \Closure
    {
        return fn (string $accion, string $entidad, array $snapshot) => $this->crud->auditar($accion, $entidad, $snapshot, ClienteHttp::origen($r));
    }

    /** @return array{KIT_Nombre: string, CAR_Cargo: ?int, DEP_Departamento: ?int, KIT_Activo: bool, items: list<array<string, mixed>>} */
    private function datosKit(Request $r): array
    {
        $entrada = Peticion::json($r);
        [, $nombre] = $entrada->campo(Campo::texto('KIT_Nombre', true, 2, 100), true);
        [, $cargo] = $entrada->campo(Campo::entero('CAR_Cargo'), false);
        [, $departamento] = $entrada->campo(Campo::entero('DEP_Departamento'), false);
        [$hayActivo, $activo] = $entrada->campo(Campo::logico('KIT_Activo'), false);
        $lista = $entrada->lista('items', 30);
        if (null === $lista) {
            $entrada->campo(new Campo('items', requerido: true), true);
        } elseif ([] === $lista) {
            $entrada->errorDeValor('items', 'TOO_SHORT', $lista);
        }
        $items = [];
        foreach ($lista ?? [] as $item) {
            $sub = Entrada::de(\is_array($item) ? $item : []);
            [, $tipo] = $sub->campo(Campo::entero('TAC_Tipo_Activo'), false);
            [$hayCantidad, $cantidad] = $sub->campo(Campo::entero('KIT_Cantidad', minimo: 1, maximo: 50), false);
            [, $licencia] = $sub->campo(Campo::entero('LIC_Licencia'), false);
            $sub->validar();
            $items[] = ['TAC_Tipo_Activo' => $tipo, 'KIT_Cantidad' => $hayCantidad && null !== $cantidad ? $cantidad : 1, 'LIC_Licencia' => $licencia];
        }
        $entrada->validar();

        return ['KIT_Nombre' => (string) $nombre, 'CAR_Cargo' => $cargo, 'DEP_Departamento' => $departamento,
            'KIT_Activo' => $hayActivo && null !== $activo ? (bool) $activo : true, 'items' => $items];
    }
}
