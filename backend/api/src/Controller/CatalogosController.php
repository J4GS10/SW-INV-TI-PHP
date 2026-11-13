<?php

declare(strict_types=1);

namespace App\Controller;

use App\Catalogo\CatalogoGenerico;
use App\Catalogo\RegistroCatalogos;
use App\Crud\Campo;
use App\Crud\Operaciones;
use App\Dominio\Roles;
use App\Http\Entrada;
use App\Http\ErrorApi;
use App\Seguridad\Autenticado;
use App\Catalogo\Infrastructure\CatalogoTecnicoStore;
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
        private CatalogoTecnicoStore $datos,
        private \App\Inventario\Infrastructure\DatosActivos $activos,
        private \App\Db\Transaccion $transaccion,
        private \App\Catalogo\SpecificationRules $specificationRules,
        private \App\Db\LogicalDelete $borrado,
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
        $porTipo = [];
        foreach ($this->filasEspecificaciones() as $f) {
            $porTipo[(string) (int) $f['TAC_Tipo_Activo']][] = $this->especificacion($f);
        }

        return new JsonResponse((object) $porTipo);
    }

    #[Route('/especificaciones-tipo/{tipo}', methods: ['GET'], priority: 10)]
    public function especificacionesDeTipo(Request $r): JsonResponse
    {
        return new JsonResponse(array_map($this->especificacion(...), $this->filasEspecificaciones(Entrada::enteroDeRuta($r, 'tipo'))));
    }

    /** Reemplaza la lista de especificaciones que pide el tipo (en el orden recibido). */
    #[Route('/especificaciones-tipo/{tipo}', methods: ['PUT'], priority: 10)]
    #[Autenticado(roles: Roles::ADMIN)]
    public function fijarEspecificaciones(Request $r): JsonResponse
    {
        $tipo = Entrada::enteroDeRuta($r, 'tipo');
        $entrada = Entrada::json($r);
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
            } catch (\App\Http\ErrorValidacion $e) {
                throw new \App\Http\ErrorValidacion(array_map(static fn (array $x): array => [...$x, 'loc' => ['body', 'items', $i, ...\array_slice($x['loc'], 1)]], $e->errores));
            }
            $items[] = ['TES_Tipo_Especificacion' => (int) $tes, 'obligatoria' => (bool) $obligatoria,
                'valores' => array_values(array_filter(array_map(trim(...), $valores), static fn (string $v): bool => '' !== $v))];
        }
        $entrada->validar();
        if (!$this->datos->existeTipoDeActivo($tipo)) {
            throw new ErrorApi(404, 'ASSET_TYPE_NOT_FOUND');
        }
        $ids = array_column($items, 'TES_Tipo_Especificacion');
        if (\count(array_unique($ids)) !== \count($ids)) {
            throw new ErrorApi(422, 'DUPLICATED_SPECIFICATION');
        }
        foreach ($ids as $id) {
            if (!$this->activos->existeTipoEspecificacion($id)) {
                throw new ErrorApi(404, 'SPECIFICATION_TYPE_NOT_FOUND');
            }
        }
        $this->transaccion->ejecutar(function () use ($tipo, $items, $r): void {
            $this->specificationRules->removeOptionsOfType($tipo);
            $this->datos->quitarEspecificacionesDeTipo($tipo);
            foreach ($items as $orden => $item) {
                $this->datos->agregarEspecificacionATipo($tipo, $item['TES_Tipo_Especificacion'], $item['obligatoria'], ($orden + 1) * 10);
                $this->specificationRules->replaceOptions($tipo, $item['TES_Tipo_Especificacion'], $item['valores']);
            }
            $this->crud->auditar('UPDATE', 'INV_ESPECIFICACION_TIPO_ACTIVO', ['tipo' => $tipo, 'especificaciones' => $items], $r);
        });

        return new JsonResponse(array_map($this->especificacion(...), $this->filasEspecificaciones($tipo)));
    }

    // ── Kits estándar de puesto ─────────────────────────────────────────────

    #[Route('/kits', methods: ['GET'], priority: 10)]
    public function listarKits(): JsonResponse
    {
        $ids = $this->datos->idsDeKits();

        return new JsonResponse(array_map(fn ($id): array => (array) $this->kit((int) $id), $ids));
    }

    #[Route('/kits', methods: ['POST'], priority: 10)]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearKit(Request $r): JsonResponse
    {
        $datos = $this->datosKit($r);
        $this->validarKit($datos);
        $id = $this->transaccion->ejecutar(function () use ($datos, $r): int {
            $id = $this->datos->crearKit($datos['KIT_Nombre'], $datos['CAR_Cargo'], $datos['DEP_Departamento'], (bool) $datos['KIT_Activo']);
            $this->datos->reemplazarItemsDeKit($id, $datos['items']);
            $this->crud->auditar('CREATE', 'INV_KIT_ESTANDAR', ['id' => $id, ...$datos], $r);

            return $id;
        });

        return new JsonResponse($this->kit($id), 201);
    }

    #[Route('/kits/{kit}', methods: ['PUT'], priority: 10)]
    #[Autenticado(roles: Roles::ADMIN)]
    public function editarKit(Request $r): JsonResponse
    {
        $id = Entrada::enteroDeRuta($r, 'kit');
        $datos = $this->datosKit($r);
        $this->kit($id) ?? throw new ErrorApi(404, 'STANDARD_KIT_NOT_FOUND');
        $this->validarKit($datos, $id);
        $this->transaccion->ejecutar(function () use ($id, $datos, $r): void {
            $this->datos->actualizarKit($id, $datos['KIT_Nombre'], $datos['CAR_Cargo'], $datos['DEP_Departamento'], (bool) $datos['KIT_Activo']);
            $this->datos->reemplazarItemsDeKit($id, $datos['items']);
            $this->crud->auditar('UPDATE', 'INV_KIT_ESTANDAR', ['id' => $id, ...$datos], $r);
        });

        return new JsonResponse($this->kit($id));
    }

    #[Route('/kits/{kit}', methods: ['DELETE'], priority: 10)]
    #[Autenticado(roles: Roles::ADMIN)]
    public function borrarKit(Request $r): Response
    {
        $id = Entrada::enteroDeRuta($r, 'kit');
        $kit = $this->kit($id) ?? throw new ErrorApi(404, 'STANDARD_KIT_NOT_FOUND');
        $this->transaccion->ejecutar(function () use ($id, $kit, $r): void {
            // El kit se archiva; su composición queda en la auditoría (los renglones son vínculos y se quitan con él).
            $this->borrado->archive('INV_KIT_ESTANDAR', 'KIT_Kit', $id);
            $this->crud->auditar('DELETE', 'INV_KIT_ESTANDAR', ['id' => $id, 'nombre' => $kit['KIT_Nombre'], 'items' => $kit['items'] ?? []], $r);
        });

        return new Response(null, 204);
    }

    // ── Catálogos estándar ──────────────────────────────────────────────────

    #[Route('/{clave}', methods: ['GET'])]
    public function listarItems(string $clave, Request $r): JsonResponse
    {
        $q = Entrada::consulta($r);
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
    #[Autenticado(roles: Roles::ADMIN)]
    public function editarItem(string $clave, Request $r): JsonResponse
    {
        $id = Entrada::enteroDeRuta($r, 'item');
        $datos = $this->datosItem($r, false);

        return new JsonResponse($this->catalogos->editar($clave, $id, $datos, $this->auditor($r)));
    }

    #[Route('/{clave}/reordenar', methods: ['POST'], priority: 5)]
    #[Autenticado(roles: Roles::ADMIN)]
    public function reordenar(string $clave, Request $r): Response
    {
        $entrada = Entrada::json($r);
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
        $this->catalogos->reordenar($clave, $ids, $this->auditor($r));

        return new Response(null, 204);
    }

    /** Cuántos registros se moverán si se fusiona `origen` en `destino`. */
    #[Route('/{clave}/{origen}/fusionar/{destino}', methods: ['GET'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function previaFusion(string $clave, Request $r): JsonResponse
    {
        return new JsonResponse($this->catalogos->previaFusion($clave, Entrada::enteroDeRuta($r, 'origen'), Entrada::enteroDeRuta($r, 'destino')));
    }

    #[Route('/{clave}/{origen}/fusionar/{destino}', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function fusionar(string $clave, Request $r): JsonResponse
    {
        return new JsonResponse($this->catalogos->fusionar($clave, Entrada::enteroDeRuta($r, 'origen'), Entrada::enteroDeRuta($r, 'destino'), $this->auditor($r)));
    }

    // ── apoyo ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function datosItem(Request $r, bool $creacion): array
    {
        $entrada = Entrada::json($r);
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
        return fn (string $accion, string $entidad, array $snapshot) => $this->crud->auditar($accion, $entidad, $snapshot, $r);
    }

    /** @return list<array<string, mixed>> */
    private function filasEspecificaciones(?int $tipo = null): array
    {
        return $this->datos->especificacionesPorTipo($tipo);
    }

    /**
     * @param array<string, mixed> $f
     * @return array<string, mixed>
     */
    private function especificacion(array $f): array
    {
        return [
            'TES_Tipo_Especificacion' => (int) $f['TES_Tipo_Especificacion'],
            'nombre' => $f['TES_Nombre'],
            'unidad' => $f['TES_Unidad_Medida'],
            'obligatoria' => 1 === (int) $f['ETA_Obligatoria'],
            'valores' => $this->specificationRules->options((int) $f['TAC_Tipo_Activo'], (int) $f['TES_Tipo_Especificacion']),
            'tipo_dato' => $f['TES_Data_Type'],
            'minimo' => null === $f['TES_Min_Value'] ? null : (float) $f['TES_Min_Value'],
            'maximo' => null === $f['TES_Max_Value'] ? null : (float) $f['TES_Max_Value'],
            'orden' => (int) $f['ETA_Orden'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function kit(int $id): ?array
    {
        $k = $this->datos->kit($id);
        if (false === $k) {
            return null;
        }
        $entero = static fn (mixed $v): ?int => null === $v ? null : (int) $v;
        $items = $this->datos->itemsDeKit($id);

        return [
            'KIT_Kit' => (int) $k['KIT_Kit'], 'KIT_Nombre' => $k['KIT_Nombre'], 'CAR_Cargo' => $entero($k['CAR_Cargo']),
            'DEP_Departamento' => $entero($k['DEP_Departamento']), 'KIT_Activo' => 1 === (int) $k['KIT_Activo'],
            'items' => array_map(static fn (array $i): array => ['KIT_Item' => (int) $i['KIT_Item'], 'TAC_Tipo_Activo' => $entero($i['TAC_Tipo_Activo']),
                'KIT_Cantidad' => (int) $i['KIT_Cantidad'], 'LIC_Licencia' => $entero($i['LIC_Licencia'])], $items),
        ];
    }

    /** @return array{KIT_Nombre: string, CAR_Cargo: ?int, DEP_Departamento: ?int, KIT_Activo: bool, items: list<array<string, mixed>>} */
    private function datosKit(Request $r): array
    {
        $entrada = Entrada::json($r);
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

    /**
     * @param array<string, mixed> $datos
     */
    private function validarKit(array $datos, ?int $excepto = null): void
    {
        [$cargo, $departamento] = [$datos['CAR_Cargo'], $datos['DEP_Departamento']];
        if ((null === $cargo) === (null === $departamento)) {
            throw new ErrorApi(422, 'STANDARD_KIT_NEEDS_POSITION_OR_DEPARTMENT');
        }
        if (null !== $cargo && !$this->datos->existeCargo($cargo)) {
            throw new ErrorApi(404, 'POSITION_NOT_FOUND');
        }
        if (null !== $departamento && !$this->datos->existeDepartamento($departamento)) {
            throw new ErrorApi(404, 'DEPARTMENT_NOT_FOUND');
        }
        // Un solo kit activo por cargo o por departamento.
        if ($datos['KIT_Activo'] && $this->datos->hayKitActivoPara($cargo, $departamento, $excepto)) {
            throw new ErrorApi(409, 'STANDARD_KIT_ALREADY_EXISTS');
        }
        if ([] === $datos['items']) {
            throw new ErrorApi(422, 'STANDARD_KIT_EMPTY');
        }
    }
}
