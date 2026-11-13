<?php

declare(strict_types=1);

namespace App\Controller;

use App\Catalogo\ClasesActivo;
use App\Catalogo\EntidadesTecnicas as T;
use App\Catalogo\PlantillaEspecificaciones;
use App\Crud\Campo;
use App\Crud\Entidad;
use App\Crud\Esquema;
use App\Crud\Operaciones;
use App\Dominio\Roles;
use App\Http\Entrada;
use App\Http\ErrorApi;
use App\Seguridad\Autenticado;
use App\Catalogo\Infrastructure\CatalogoTecnicoStore;
use App\Db\RecordInUse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Catálogos técnicos. Lectura: roles de la aplicación; escritura: ADMIN. */
#[Route('/api/v1/cat')]
#[Autenticado(roles: Roles::APP)]
final readonly class CatalogoTecnicoController
{
    /**
     * Estados de los que depende la máquina de estados de los activos: no se
     * pueden renombrar ni borrar.
     */
    private const array ESTADOS_DEL_SISTEMA = ['disponible', 'asignado', 'en reparación', 'en reparacion', 'baja', 'en bodega'];

    public function __construct(
        private Operaciones $crud,
        private PlantillaEspecificaciones $plantilla,
        private CatalogoTecnicoStore $datos,
        private \App\Db\Transaccion $transaccion,
    ) {
    }

    // ================= TIPO DE ACTIVO =================

    #[Route('/tipos-activo', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearTipo(Request $r): JsonResponse
    {
        $d = $this->creacion(T::tipoActivo(), $r);
        if ($this->datos->existeTipoDeActivoLlamado($d['TAC_Nombre'])) {
            throw new ErrorApi(400, 'ASSET_TYPE_ALREADY_EXISTS');
        }
        // Sin clase indicada se deduce del nombre.
        $d['TAC_Clase'] ??= ClasesActivo::porNombre($d['TAC_Nombre']);
        $tipo = $this->transaccion->ejecutar(function () use ($d, $r): array {
            $id = $this->crud->repositorio->insertar(T::tipoActivo(), $d);
            $this->plantilla->aplicar((int) $id);
            $this->crud->auditar('CREATE', 'INV_TIPO_ACTIVO', ['nombre' => $d['TAC_Nombre']], $r);

            return (array) $this->crud->repositorio->obtener(T::tipoActivo(), $id);
        });

        return new JsonResponse(self::conReglas($tipo), 201);
    }

    #[Route('/tipos-activo', methods: ['GET'])]
    public function listarTipos(): JsonResponse
    {
        return new JsonResponse(array_map(self::conReglas(...), $this->crud->repositorio->listar(T::tipoActivo())));
    }

    /** Clases de activo y sus reglas (cómo se opera cada una). */
    #[Route('/clases-activo', methods: ['GET'])]
    public function clases(): JsonResponse
    {
        return new JsonResponse(array_map(ClasesActivo::reglas(...), array_keys(ClasesActivo::CLASES)));
    }

    #[Route('/tipos-activo/{id}/componentes', methods: ['GET'])]
    public function componentes(Request $r): JsonResponse
    {
        $id = Entrada::enteroDeRuta($r);
        $this->crud->obtenerO404(T::tipoActivo(), $id, 'ASSET_TYPE_NOT_FOUND');

        return new JsonResponse($this->componentesDe($id));
    }

    /** Componentes internos que admite el tipo (vacío = cualquier tipo de clase Componente). */
    #[Route('/tipos-activo/{id}/componentes', methods: ['PUT'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function fijarComponentes(Request $r): JsonResponse
    {
        $id = Entrada::enteroDeRuta($r);
        $entrada = Entrada::json($r);
        $lista = $entrada->lista('componentes', 50) ?? [];
        foreach ($lista as $i => $c) {
            if (!\is_int($c) && !(\is_string($c) && 1 === preg_match('/^\d+$/', $c))) {
                $entrada->errorDeValor("componentes.{$i}", 'INT_REQUIRED', $c);
            }
        }
        $entrada->validar();
        $tipo = $this->crud->obtenerO404(T::tipoActivo(), $id, 'ASSET_TYPE_NOT_FOUND');
        $componentes = array_values(array_diff(array_unique(array_map('intval', $lista)), [$id]));
        sort($componentes);
        if ([] !== $componentes && !ClasesActivo::reglas($tipo['TAC_Clase'])['componentes']) {
            throw new ErrorApi(422, 'ASSET_CLASS_NO_COMPONENTS');
        }
        foreach ($componentes as $c) {
            if (null === $this->crud->repositorio->obtener(T::tipoActivo(), $c)) {
                throw new ErrorApi(404, 'ASSET_TYPE_NOT_FOUND');
            }
        }
        $this->transaccion->ejecutar(function () use ($id, $tipo, $componentes, $r): void {
            $antes = $this->datos->componentesDe($id);
            $this->datos->reemplazarComponentes($id, $componentes);
            $this->crud->auditar('UPDATE', 'INV_TIPO_COMPONENTE', ['tipo' => $tipo['TAC_Nombre'], 'antes' => $antes, 'despues' => $componentes], $r);
        });

        return new JsonResponse($componentes);
    }

    #[Route('/tipos-activo/{id}', methods: ['GET'])]
    public function obtenerTipo(Request $r): JsonResponse
    {
        return new JsonResponse(self::conReglas($this->crud->obtenerO404(T::tipoActivo(), Entrada::enteroDeRuta($r), 'ASSET_TYPE_NOT_FOUND')));
    }

    #[Route('/tipos-activo/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function actualizarTipo(Request $r): JsonResponse
    {
        $id = Entrada::enteroDeRuta($r);
        $cambios = $this->cambios(T::tipoActivo(), $r);
        $this->crud->obtenerO404(T::tipoActivo(), $id, 'ASSET_TYPE_NOT_FOUND');
        $tipo = $this->transaccion->ejecutar(function () use ($id, $cambios, $r): array {
            $this->crud->repositorio->actualizar(T::tipoActivo(), $id, $cambios);
            if (!empty($cambios['TAC_Clase'])) {
                $this->plantilla->aplicar($id);
            }
            $this->crud->auditar('UPDATE', 'INV_TIPO_ACTIVO', ['id' => $id, 'cambios' => (object) $cambios], $r);

            return (array) $this->crud->repositorio->obtener(T::tipoActivo(), $id);
        });

        return new JsonResponse(self::conReglas($tipo));
    }

    #[Route('/tipos-activo/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarTipo(Request $r): Response
    {
        return $this->eliminarEnUso(T::tipoActivo(), $r, 'ASSET_TYPE_NOT_FOUND', 'TAC_Nombre', 'CANNOT_DELETE_ASSET_TYPE_IN_USE');
    }

    // ================= MARCA =================

    #[Route('/marcas', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearMarca(Request $r): JsonResponse
    {
        $d = $this->creacion(T::marca(), $r);
        if ($this->datos->existeMarcaLlamada($d['MAR_Nombre'])) {
            throw new ErrorApi(400, 'BRAND_ALREADY_EXISTS');
        }

        return new JsonResponse($this->crud->crear(T::marca(), $d, $r, ['nombre' => $d['MAR_Nombre']]), 201);
    }

    #[Route('/marcas', methods: ['GET'])]
    public function listarMarcas(): JsonResponse
    {
        return new JsonResponse($this->crud->repositorio->listar(T::marca()));
    }

    #[Route('/marcas/{id}', methods: ['GET'])]
    public function obtenerMarca(Request $r): JsonResponse
    {
        return new JsonResponse($this->crud->obtenerO404(T::marca(), Entrada::enteroDeRuta($r), 'BRAND_NOT_FOUND'));
    }

    #[Route('/marcas/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function actualizarMarca(Request $r): JsonResponse
    {
        return $this->actualizarSimple(T::marca(), $r, 'BRAND_NOT_FOUND');
    }

    #[Route('/marcas/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarMarca(Request $r): Response
    {
        return $this->eliminarEnUso(T::marca(), $r, 'BRAND_NOT_FOUND', 'MAR_Nombre', 'CANNOT_DELETE_BRAND_IN_USE');
    }

    // ================= TIPO DE CONEXIÓN =================

    #[Route('/tipos-conexion', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearConexion(Request $r): JsonResponse
    {
        $d = $this->creacion(T::tipoConexion(), $r);

        return new JsonResponse($this->crud->crear(T::tipoConexion(), $d, $r, ['nombre' => $d['TCN_Nombre']]), 201);
    }

    #[Route('/tipos-conexion', methods: ['GET'])]
    public function listarConexiones(): JsonResponse
    {
        return new JsonResponse($this->crud->repositorio->listar(T::tipoConexion()));
    }

    #[Route('/tipos-conexion/{id}', methods: ['GET'])]
    public function obtenerConexion(Request $r): JsonResponse
    {
        return new JsonResponse($this->crud->obtenerO404(T::tipoConexion(), Entrada::enteroDeRuta($r), 'CONNECTION_TYPE_NOT_FOUND'));
    }

    #[Route('/tipos-conexion/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function actualizarConexion(Request $r): JsonResponse
    {
        return $this->actualizarSimple(T::tipoConexion(), $r, 'CONNECTION_TYPE_NOT_FOUND');
    }

    #[Route('/tipos-conexion/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarConexion(Request $r): Response
    {
        return $this->eliminarEnUso(T::tipoConexion(), $r, 'CONNECTION_TYPE_NOT_FOUND', 'TCN_Nombre', 'CANNOT_DELETE_CONNECTION_TYPE_IN_USE');
    }

    // ================= MODELO =================

    #[Route('/modelos', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearModelo(Request $r): JsonResponse
    {
        $d = $this->creacion(T::modelo(), $r);

        return new JsonResponse($this->crud->crear(T::modelo(), $d, $r, ['nombre' => $d['MOD_Nombre'], 'marca_id' => $d['MAR_Marca']]), 201);
    }

    /** Modelos de una marca; con `tipo_id`, solo los de ese tipo (y los sin tipo, legados). */
    #[Route('/modelos', methods: ['GET'])]
    public function listarModelos(Request $r): JsonResponse
    {
        $q = Entrada::consulta($r);
        [, $marca] = $q->campo(Campo::entero('marca_id', true), true);
        [, $tipo] = $q->campo(Campo::entero('tipo_id'), false);
        $q->validar();
        $donde = 't."MAR_Marca" = :m';
        $parametros = ['m' => $marca];
        if (null !== $tipo) {
            $donde .= ' AND (t."TAC_Tipo_Activo" = :t OR t."TAC_Tipo_Activo" IS NULL)';
            $parametros['t'] = $tipo;
        }

        return new JsonResponse($this->crud->repositorio->listar(T::modelo(), $donde, $parametros));
    }

    /** Modelos con su marca en una sola consulta (selectores del frontend). */
    #[Route('/modelos-flat', methods: ['GET'])]
    public function modelosPlanos(Request $r): JsonResponse
    {
        $q = Entrada::consulta($r);
        [, $texto] = $q->campo(Campo::texto('q', max: 64), false);
        [$hayLimite, $limite] = $q->campo(Campo::entero('limit', minimo: 1, maximo: 1000), false);
        [, $tipo] = $q->campo(Campo::entero('tipo_id'), false);
        $q->validar();
        $filas = $this->datos->modelosConMarca($tipo, $texto, $hayLimite && null !== $limite ? (int) $limite : 500);
        $entero = static fn (mixed $v): ?int => null === $v ? null : (int) $v;

        return new JsonResponse(array_map(static fn (array $f): array => [
            'MOD_Modelo' => (int) $f['MOD_Modelo'], 'MOD_Nombre' => $f['MOD_Nombre'], 'MAR_Marca' => (int) $f['MAR_Marca'],
            'MAR_Nombre' => $f['MAR_Nombre'], 'TCN_Tipo_Conexion' => $entero($f['TCN_Tipo_Conexion']),
            'TAC_Tipo_Activo' => $entero($f['TAC_Tipo_Activo']), 'MOD_Anio_Lanzamiento' => $entero($f['MOD_Anio_Lanzamiento']),
        ], $filas));
    }

    #[Route('/modelos/{id}', methods: ['GET'])]
    public function obtenerModelo(Request $r): JsonResponse
    {
        return new JsonResponse($this->crud->obtenerO404(T::modelo(), Entrada::enteroDeRuta($r), 'MODEL_NOT_FOUND'));
    }

    #[Route('/modelos/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function actualizarModelo(Request $r): JsonResponse
    {
        return $this->actualizarSimple(T::modelo(), $r, 'MODEL_NOT_FOUND');
    }

    #[Route('/modelos/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarModelo(Request $r): Response
    {
        return $this->eliminarEnUso(T::modelo(), $r, 'MODEL_NOT_FOUND', 'MOD_Nombre', 'CANNOT_DELETE_MODEL_IN_USE');
    }

    /** Marca del modelo y el tipo de activo con que más se registra (entre los activos visibles). */
    #[Route('/modelos/{id}/contexto', methods: ['GET'])]
    public function contextoModelo(Request $r): JsonResponse
    {
        $modelo = $this->crud->obtenerO404(T::modelo(), Entrada::enteroDeRuta($r), 'MODEL_NOT_FOUND');
        $marca = $this->crud->repositorio->obtener(T::marca(), $modelo['MAR_Marca']);
        $tipoId = $this->datos->tipoMasUsadoDeModelo($modelo['MOD_Modelo']);
        $tipo = null === $tipoId ? null : $this->crud->repositorio->obtener(T::tipoActivo(), $tipoId);

        return new JsonResponse([
            'marca' => null === $marca ? null : ['MAR_Marca' => $marca['MAR_Marca'], 'nombre' => $marca['MAR_Nombre']],
            'tipo_sugerido' => null === $tipo ? null : ['TAC_Tipo_Activo' => $tipo['TAC_Tipo_Activo'], 'nombre' => $tipo['TAC_Nombre'],
                'prefijo' => $tipo['TAC_Prefijo'], 'origen' => 'activos_del_modelo'],
        ]);
    }

    // ================= ESTADO OPERATIVO =================

    #[Route('/estados-operativos', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearEstado(Request $r): JsonResponse
    {
        $d = $this->creacion(T::estadoOperativo(), $r);

        return new JsonResponse($this->crud->crear(T::estadoOperativo(), $d, $r, ['nombre' => $d['EOP_Nombre']]), 201);
    }

    #[Route('/estados-operativos', methods: ['GET'])]
    public function listarEstados(): JsonResponse
    {
        return new JsonResponse($this->crud->repositorio->listar(T::estadoOperativo()));
    }

    #[Route('/estados-operativos/{id}', methods: ['GET'])]
    public function obtenerEstado(Request $r): JsonResponse
    {
        return new JsonResponse($this->crud->obtenerO404(T::estadoOperativo(), Entrada::enteroDeRuta($r), 'OPERATIONAL_STATUS_NOT_FOUND'));
    }

    #[Route('/estados-operativos/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function actualizarEstado(Request $r): JsonResponse
    {
        $id = Entrada::enteroDeRuta($r);
        $cambios = $this->cambios(T::estadoOperativo(), $r);
        $estado = $this->crud->obtenerO404(T::estadoOperativo(), $id, 'OPERATIONAL_STATUS_NOT_FOUND');
        // Las transiciones de estado dependen de estos nombres canónicos.
        if (self::delSistema($estado['EOP_Nombre'])) {
            throw new ErrorApi(409, 'CANNOT_MODIFY_SYSTEM_OPERATIONAL_STATUS');
        }

        return new JsonResponse($this->crud->actualizar(T::estadoOperativo(), $id, $cambios, $r));
    }

    #[Route('/estados-operativos/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarEstado(Request $r): Response
    {
        $id = Entrada::enteroDeRuta($r);
        $estado = $this->crud->obtenerO404(T::estadoOperativo(), $id, 'OPERATIONAL_STATUS_NOT_FOUND');
        if (self::delSistema($estado['EOP_Nombre'])) {
            throw new ErrorApi(409, 'CANNOT_DELETE_SYSTEM_OPERATIONAL_STATUS');
        }

        return $this->eliminarEnUso(T::estadoOperativo(), $r, 'OPERATIONAL_STATUS_NOT_FOUND', 'EOP_Nombre', 'CANNOT_DELETE_OPERATIONAL_STATUS_IN_USE');
    }

    // ================= TIPO DE ESPECIFICACIÓN =================

    #[Route('/tipos-especificacion', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearEspecificacion(Request $r): JsonResponse
    {
        $d = $this->creacion(T::tipoEspecificacion(), $r);

        return new JsonResponse($this->crud->crear(T::tipoEspecificacion(), $d, $r, ['nombre' => $d['TES_Nombre']]), 201);
    }

    #[Route('/tipos-especificacion', methods: ['GET'])]
    public function listarEspecificaciones(): JsonResponse
    {
        return new JsonResponse($this->crud->repositorio->listar(T::tipoEspecificacion()));
    }

    #[Route('/tipos-especificacion/{id}', methods: ['GET'])]
    public function obtenerEspecificacion(Request $r): JsonResponse
    {
        return new JsonResponse($this->crud->obtenerO404(T::tipoEspecificacion(), Entrada::enteroDeRuta($r), 'SPECIFICATION_TYPE_NOT_FOUND'));
    }

    #[Route('/tipos-especificacion/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function actualizarEspecificacion(Request $r): JsonResponse
    {
        return $this->actualizarSimple(T::tipoEspecificacion(), $r, 'SPECIFICATION_TYPE_NOT_FOUND');
    }

    #[Route('/tipos-especificacion/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarEspecificacion(Request $r): Response
    {
        return $this->eliminarEnUso(T::tipoEspecificacion(), $r, 'SPECIFICATION_TYPE_NOT_FOUND', 'TES_Nombre', 'CANNOT_DELETE_SPECIFICATION_TYPE_IN_USE');
    }

    // ================= apoyo =================

    /**
     * @param array<string, mixed> $tipo
     *
     * @return array<string, mixed>
     */
    private static function conReglas(array $tipo): array
    {
        return [...$tipo, 'reglas' => ClasesActivo::reglas($tipo['TAC_Clase'])];
    }

    /** @return list<int> */
    private function componentesDe(int $tipo): array
    {
        return $this->datos->componentesDe($tipo);
    }

    private static function delSistema(?string $nombre): bool
    {
        return \in_array(mb_strtolower(trim((string) $nombre)), self::ESTADOS_DEL_SISTEMA, true);
    }

    private function actualizarSimple(Entidad $e, Request $r, string $noEncontrado): JsonResponse
    {
        $id = Entrada::enteroDeRuta($r);
        $cambios = $this->cambios($e, $r);
        $this->crud->obtenerO404($e, $id, $noEncontrado);

        return new JsonResponse($this->crud->actualizar($e, $id, $cambios, $r));
    }

    /** Borrado lógico, o 409 `$enUso` si otro registro todavía lo referencia. */
    private function eliminarEnUso(Entidad $e, Request $r, string $noEncontrado, string $columnaNombre, string $enUso): Response
    {
        $id = Entrada::enteroDeRuta($r);
        $registro = $this->crud->obtenerO404($e, $id, $noEncontrado);
        try {
            $this->crud->eliminar($e, $id, $r, ['id' => $id, 'nombre' => $registro[$columnaNombre]]);
        } catch (RecordInUse) {
            throw new ErrorApi(409, $enUso);
        }

        return new Response(null, 204);
    }

    /** @return array<string, mixed> */
    private function creacion(Entidad $e, Request $r): array
    {
        $entrada = Entrada::json($r);
        $datos = Esquema::creacion($e, $entrada);
        $entrada->validar();

        return $datos;
    }

    /** @return array<string, mixed> */
    private function cambios(Entidad $e, Request $r): array
    {
        $entrada = Entrada::json($r);
        $datos = Esquema::actualizacion($e, $entrada);
        $entrada->validar();

        return $datos;
    }
}
