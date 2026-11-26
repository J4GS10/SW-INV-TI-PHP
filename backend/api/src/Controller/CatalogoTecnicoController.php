<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Versioned;
use App\Http\ClienteHttp;
use App\Http\Peticion;
use App\Catalogo\ClasesActivo;
use App\Catalogo\EntidadesTecnicas as T;
use App\Crud\Campo;
use App\Crud\Entidad;
use App\Crud\Esquema;
use App\Crud\Operaciones;
use App\Dominio\Roles;
use App\Http\ErrorApi;
use App\Seguridad\Autenticado;
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
    public function __construct(
        private Operaciones $crud,
        private \App\Catalogo\CatalogoTecnico $catalogo,
    ) {
    }

    // ================= TIPO DE ACTIVO =================

    #[Route('/tipos-activo', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearTipo(Request $r): JsonResponse
    {
        return new JsonResponse($this->catalogo->crearTipo($this->creacion(T::tipoActivo(), $r), ClienteHttp::origen($r)), 201);
    }

    #[Route('/tipos-activo', methods: ['GET'])]
    public function listarTipos(): JsonResponse
    {
        return new JsonResponse($this->catalogo->tiposConReglas());
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
        $id = Peticion::enteroDeRuta($r);

        // ?detalle=1: con nombre, si es obligatorio al registrar el equipo y cuántos como mínimo.
        return new JsonResponse($r->query->getBoolean('detalle') ? $this->catalogo->componentesDetalleDeTipo($id) : $this->catalogo->componentesDeTipo($id));
    }

    /** Componentes internos que admite el tipo (vacío = cualquier tipo de clase Componente). */
    #[Route('/tipos-activo/{id}/componentes', methods: ['PUT'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function fijarComponentes(Request $r): JsonResponse
    {
        $id = Peticion::enteroDeRuta($r);
        $entrada = Peticion::json($r);
        $lista = $entrada->lista('componentes', 50) ?? [];
        // Cada elemento: el id del tipo (opcional, como antes) o {tipo, obligatorio, cantidad}.
        $entero = static fn (mixed $v): bool => \is_int($v) || (\is_string($v) && 1 === preg_match('/^\d+$/', $v));
        $pedidos = [];
        foreach ($lista as $i => $c) {
            if ($entero($c)) {
                $pedidos[] = ['tipo' => (int) $c, 'obligatorio' => false, 'cantidad' => 1];
            } elseif (\is_array($c) && $entero($c['tipo'] ?? null) && \is_bool($c['obligatorio'] ?? false)
                && (!isset($c['cantidad']) || ($entero($c['cantidad']) && (int) $c['cantidad'] >= 1 && (int) $c['cantidad'] <= 20))) {
                $pedidos[] = ['tipo' => (int) $c['tipo'], 'obligatorio' => (bool) ($c['obligatorio'] ?? false), 'cantidad' => (int) ($c['cantidad'] ?? 1)];
            } else {
                $entrada->errorDeValor("componentes.{$i}", 'INT_REQUIRED', $c);
            }
        }
        $entrada->validar();

        return new JsonResponse($this->catalogo->fijarComponentesDeTipo($id, $pedidos, ClienteHttp::origen($r)));
    }

    #[Route('/tipos-activo/{id}', methods: ['GET'])]
    public function obtenerTipo(Request $r): JsonResponse
    {
        return new JsonResponse($this->catalogo->tipoConReglas(Peticion::enteroDeRuta($r)));
    }

    #[Route('/tipos-activo/{id}', methods: ['PATCH'])]
    #[Versioned('INV_TIPO_ACTIVO', 'TAC_Tipo_Activo')]
    #[Autenticado(roles: Roles::ADMIN)]
    public function actualizarTipo(Request $r): JsonResponse
    {
        $id = Peticion::enteroDeRuta($r);
        $cambios = $this->cambios(T::tipoActivo(), $r);

        return new JsonResponse($this->catalogo->cambiarTipo($id, $cambios, ClienteHttp::origen($r)));
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
        return new JsonResponse($this->catalogo->crearMarca($this->creacion(T::marca(), $r), ClienteHttp::origen($r)), 201);
    }

    #[Route('/marcas', methods: ['GET'])]
    public function listarMarcas(): JsonResponse
    {
        return new JsonResponse($this->crud->repositorio->listar(T::marca()));
    }

    #[Route('/marcas/{id}', methods: ['GET'])]
    public function obtenerMarca(Request $r): JsonResponse
    {
        return new JsonResponse($this->crud->obtenerO404(T::marca(), Peticion::enteroDeRuta($r), 'BRAND_NOT_FOUND'));
    }

    #[Route('/marcas/{id}', methods: ['PATCH'])]
    #[Versioned('INV_MARCA', 'MAR_Marca')]
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

        return new JsonResponse($this->crud->crear(T::tipoConexion(), $d, ClienteHttp::origen($r), ['nombre' => $d['TCN_Nombre']]), 201);
    }

    #[Route('/tipos-conexion', methods: ['GET'])]
    public function listarConexiones(): JsonResponse
    {
        return new JsonResponse($this->crud->repositorio->listar(T::tipoConexion()));
    }

    #[Route('/tipos-conexion/{id}', methods: ['GET'])]
    public function obtenerConexion(Request $r): JsonResponse
    {
        return new JsonResponse($this->crud->obtenerO404(T::tipoConexion(), Peticion::enteroDeRuta($r), 'CONNECTION_TYPE_NOT_FOUND'));
    }

    #[Route('/tipos-conexion/{id}', methods: ['PATCH'])]
    #[Versioned('INV_TIPO_CONEXION', 'TCN_Tipo_Conexion')]
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

        return new JsonResponse($this->crud->crear(T::modelo(), $d, ClienteHttp::origen($r), ['nombre' => $d['MOD_Nombre'], 'marca_id' => $d['MAR_Marca']]), 201);
    }

    /** Modelos de una marca; con `tipo_id`, solo los de ese tipo (y los sin tipo, legados). */
    #[Route('/modelos', methods: ['GET'])]
    public function listarModelos(Request $r): JsonResponse
    {
        $q = Peticion::consulta($r);
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
        $q = Peticion::consulta($r);
        [, $texto] = $q->campo(Campo::texto('q', max: 64), false);
        [$hayLimite, $limite] = $q->campo(Campo::entero('limit', minimo: 1, maximo: 1000), false);
        [, $tipo] = $q->campo(Campo::entero('tipo_id'), false);
        $q->validar();

        return new JsonResponse($this->catalogo->modelosConMarca($tipo, $texto, $hayLimite && null !== $limite ? (int) $limite : 500));
    }

    #[Route('/modelos/{id}', methods: ['GET'])]
    public function obtenerModelo(Request $r): JsonResponse
    {
        return new JsonResponse($this->crud->obtenerO404(T::modelo(), Peticion::enteroDeRuta($r), 'MODEL_NOT_FOUND'));
    }

    #[Route('/modelos/{id}', methods: ['PATCH'])]
    #[Versioned('INV_MODELO', 'MOD_Modelo')]
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
        return new JsonResponse($this->catalogo->contextoDeModelo(Peticion::enteroDeRuta($r)));
    }

    // ================= ESTADO OPERATIVO =================

    #[Route('/estados-operativos', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearEstado(Request $r): JsonResponse
    {
        $d = $this->creacion(T::estadoOperativo(), $r);

        return new JsonResponse($this->crud->crear(T::estadoOperativo(), $d, ClienteHttp::origen($r), ['nombre' => $d['EOP_Nombre']]), 201);
    }

    #[Route('/estados-operativos', methods: ['GET'])]
    public function listarEstados(): JsonResponse
    {
        return new JsonResponse($this->crud->repositorio->listar(T::estadoOperativo()));
    }

    #[Route('/estados-operativos/{id}', methods: ['GET'])]
    public function obtenerEstado(Request $r): JsonResponse
    {
        return new JsonResponse($this->crud->obtenerO404(T::estadoOperativo(), Peticion::enteroDeRuta($r), 'OPERATIONAL_STATUS_NOT_FOUND'));
    }

    #[Route('/estados-operativos/{id}', methods: ['PATCH'])]
    #[Versioned('INV_ESTADO_OPERATIVO', 'EOP_Estado_Operativo')]
    #[Autenticado(roles: Roles::ADMIN)]
    public function actualizarEstado(Request $r): JsonResponse
    {
        return new JsonResponse($this->catalogo->actualizarEstadoOperativo(Peticion::enteroDeRuta($r), $this->cambios(T::estadoOperativo(), $r), ClienteHttp::origen($r)));
    }

    #[Route('/estados-operativos/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarEstado(Request $r): Response
    {
        $this->catalogo->eliminarEstadoOperativo(Peticion::enteroDeRuta($r), ClienteHttp::origen($r));

        return new Response(null, 204);
    }

    // ================= TIPO DE ESPECIFICACIÓN =================

    #[Route('/tipos-especificacion', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearEspecificacion(Request $r): JsonResponse
    {
        $d = $this->creacion(T::tipoEspecificacion(), $r);

        return new JsonResponse($this->crud->crear(T::tipoEspecificacion(), $d, ClienteHttp::origen($r), ['nombre' => $d['TES_Nombre']]), 201);
    }

    #[Route('/tipos-especificacion', methods: ['GET'])]
    public function listarEspecificaciones(): JsonResponse
    {
        return new JsonResponse($this->crud->repositorio->listar(T::tipoEspecificacion()));
    }

    #[Route('/tipos-especificacion/{id}', methods: ['GET'])]
    public function obtenerEspecificacion(Request $r): JsonResponse
    {
        return new JsonResponse($this->crud->obtenerO404(T::tipoEspecificacion(), Peticion::enteroDeRuta($r), 'SPECIFICATION_TYPE_NOT_FOUND'));
    }

    #[Route('/tipos-especificacion/{id}', methods: ['PATCH'])]
    #[Versioned('INV_TIPO_ESPECIFICACION', 'TES_Tipo_Especificacion')]
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

    private function actualizarSimple(Entidad $e, Request $r, string $noEncontrado): JsonResponse
    {
        $id = Peticion::enteroDeRuta($r);
        $cambios = $this->cambios($e, $r);
        $this->crud->obtenerO404($e, $id, $noEncontrado);

        return new JsonResponse($this->crud->actualizar($e, $id, $cambios, ClienteHttp::origen($r)));
    }

    /** Borrado lógico, o 409 `$enUso` si otro registro todavía lo referencia. */
    private function eliminarEnUso(Entidad $e, Request $r, string $noEncontrado, string $columnaNombre, string $enUso): Response
    {
        $id = Peticion::enteroDeRuta($r);
        $registro = $this->crud->obtenerO404($e, $id, $noEncontrado);
        try {
            $this->crud->eliminar($e, $id, ClienteHttp::origen($r), ['id' => $id, 'nombre' => $registro[$columnaNombre]]);
        } catch (RecordInUse) {
            throw new ErrorApi(409, $enUso);
        }

        return new Response(null, 204);
    }

    /** @return array<string, mixed> */
    private function creacion(Entidad $e, Request $r): array
    {
        $entrada = Peticion::json($r);
        $datos = Esquema::creacion($e, $entrada);
        $entrada->validar();

        return $datos;
    }

    /** @return array<string, mixed> */
    private function cambios(Entidad $e, Request $r): array
    {
        $entrada = Peticion::json($r);
        $datos = Esquema::actualizacion($e, $entrada);
        $entrada->validar();

        return $datos;
    }
}
