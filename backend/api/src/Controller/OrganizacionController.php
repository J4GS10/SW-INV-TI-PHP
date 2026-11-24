<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Versioned;
use App\Http\Peticion;
use App\Crud\Campo;
use App\Crud\Esquema;
use App\Crud\Operaciones;
use App\Dominio\Roles;
use App\Http\ClienteHttp;
use App\Validacion\Entrada;
use App\Http\ErrorApi;
use App\Http\Limite;
use App\Organizacion\Entidades as E;
use App\Organizacion\Identidades;
use App\Organizacion\Infrastructure\Sugerencias;
use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
use App\Organizacion\Infrastructure\OrganizacionStore;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Organización: departamentos, cargos, personas y cuentas de usuario.
 * Personas y su información: inventario con personas e identidades (nunca
 * Consulta ni Administración). Cuentas: las modifica SUPER_ADMIN; ADMIN_SEGURIDAD las consulta y cierra sesiones.
 */
#[Route('/api/v1/org')]
#[Autenticado]
final readonly class OrganizacionController
{
    private const array PERSONAS = [...Roles::LECTURA_PERSONAS, ...Roles::IAM];

    public function __construct(
        private Operaciones $crud,
        private Identidades $identidades,
        private Sugerencias $sugerencias,
        private OrganizacionStore $datos,
        private UsuarioActual $actual,
        private \App\Organizacion\Departamentos $departamentos,
        private \App\Organizacion\Personas $padron,
    ) {
    }

    /** Sedes que el usuario puede ver (todas si su alcance es global), con su ubicación. */
    #[Route('/sedes', methods: ['GET'])]
    public function sedes(): JsonResponse
    {
        $filas = $this->datos->sedesVisibles();

        return new JsonResponse(array_map(static fn (array $f): array => ['SED_Sede' => (int) $f['SED_Sede'], 'SED_Nombre' => $f['SED_Nombre'],
            'ubicacion' => implode(', ', array_filter([$f['MUN_Nombre'], $f['PAI_Nombre']]))], $filas));
    }

    // ================= DEPARTAMENTOS =================

    /** Departamentos con personas activas, activos asignados y desglose por tipo. */
    #[Route('/departamentos/resumen', methods: ['GET'], priority: 10)]
    #[Autenticado(roles: Roles::LECTURA_PERSONAS)]
    #[Limite('10/minute')]
    public function resumenDepartamentos(): JsonResponse
    {
        return new JsonResponse($this->departamentos->resumen());
    }

    /** Personas activas del departamento con los activos que tienen asignados. */
    #[Route('/departamentos/{id}/detalle', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTURA_PERSONAS)]
    #[Limite('10/minute')]
    public function detalleDepartamento(Request $r): JsonResponse
    {
        return new JsonResponse($this->departamentos->detalle(Peticion::enteroDeRuta($r)));
    }

    #[Route('/departamentos', methods: ['GET'])]
    #[Autenticado(roles: self::PERSONAS)]
    public function listarDepartamentos(): JsonResponse
    {
        return new JsonResponse($this->crud->repositorio->listar(E::departamento()));
    }

    #[Route('/departamentos/{id}', methods: ['GET'])]
    #[Autenticado(roles: self::PERSONAS)]
    public function obtenerDepartamento(Request $r): JsonResponse
    {
        return new JsonResponse($this->crud->obtenerO404(E::departamento(), Peticion::enteroDeRuta($r), 'DEPARTMENT_NOT_FOUND'));
    }

    #[Route('/departamentos', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearDepartamento(Request $r): JsonResponse
    {
        $d = $this->creacion(E::departamento(), $r, ['DEP_Nombre', 'DEP_Codigo_Costos', 'DEP_Descripcion']);

        return new JsonResponse($this->departamentos->crear($d, ClienteHttp::origen($r)), 201);
    }

    #[Route('/departamentos/{id}', methods: ['PATCH'])]
    #[Versioned('INV_DEPARTAMENTO', 'DEP_Departamento')]
    #[Autenticado(roles: Roles::ADMIN)]
    public function actualizarDepartamento(Request $r): JsonResponse
    {
        $id = Peticion::enteroDeRuta($r);
        $cambios = $this->cambios(E::departamento(), $r);

        return new JsonResponse($this->departamentos->cambiar($id, $cambios, ClienteHttp::origen($r)));
    }

    #[Route('/departamentos/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarDepartamento(Request $r): Response
    {
        $this->departamentos->eliminar(Peticion::enteroDeRuta($r), ClienteHttp::origen($r));

        return new Response(null, 204);
    }

    /** Cargo, jefe y área más probables para una persona nueva del departamento. */
    #[Route('/departamentos/{id}/contexto', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTURA_PERSONAS)]
    public function contextoDepartamento(Request $r): JsonResponse
    {
        return new JsonResponse($this->sugerencias->departamento(Peticion::enteroDeRuta($r)));
    }

    // ================= CARGOS =================

    #[Route('/cargos', methods: ['GET'])]
    #[Autenticado(roles: self::PERSONAS)]
    public function listarCargos(): JsonResponse
    {
        return new JsonResponse($this->crud->repositorio->listar(E::cargo()));
    }

    #[Route('/cargos/{id}', methods: ['GET'])]
    #[Autenticado(roles: self::PERSONAS)]
    public function obtenerCargo(Request $r): JsonResponse
    {
        return new JsonResponse($this->crud->obtenerO404(E::cargo(), Peticion::enteroDeRuta($r), 'POSITION_NOT_FOUND'));
    }

    #[Route('/cargos', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearCargo(Request $r): JsonResponse
    {
        $d = $this->creacion(E::cargo(), $r);

        return new JsonResponse($this->crud->crear(E::cargo(), $d, ClienteHttp::origen($r), ['nombre' => $d['CAR_Nombre']]), 201);
    }

    #[Route('/cargos/{id}', methods: ['PATCH'])]
    #[Versioned('INV_CARGO', 'CAR_Cargo')]
    #[Autenticado(roles: Roles::ADMIN)]
    public function actualizarCargo(Request $r): JsonResponse
    {
        $id = Peticion::enteroDeRuta($r);
        $cambios = $this->cambios(E::cargo(), $r);
        $this->crud->obtenerO404(E::cargo(), $id, 'POSITION_NOT_FOUND');

        return new JsonResponse($this->crud->actualizar(E::cargo(), $id, $cambios, ClienteHttp::origen($r)));
    }

    #[Route('/cargos/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarCargo(Request $r): Response
    {
        $id = Peticion::enteroDeRuta($r);
        $cargo = $this->crud->obtenerO404(E::cargo(), $id, 'POSITION_NOT_FOUND');
        $this->crud->sinHijos('INV_PERSONA', 'CAR_Cargo', $id, 'CANNOT_DELETE_POSITION_HAS_PERSONS');
        $this->crud->eliminar(E::cargo(), $id, ClienteHttp::origen($r), ['id' => $id, 'nombre' => $cargo['CAR_Nombre']]);

        return new Response(null, 204);
    }

    // ================= PERSONAS =================

    /** Padrón de personas de las sedes del alcance (datos personales: límite anti-scraping). */
    #[Route('/personas', methods: ['GET'])]
    #[Autenticado(roles: self::PERSONAS)]
    #[Limite('20/minute')]
    public function listarPersonas(): JsonResponse
    {
        return new JsonResponse($this->padron->listar());
    }

    /** Personas sin usuario asignado. */
    #[Route('/personas/disponibles', methods: ['GET'], priority: 10)]
    #[Autenticado(roles: self::PERSONAS)]
    #[Limite('20/minute')]
    public function personasDisponibles(): JsonResponse
    {
        return new JsonResponse($this->padron->listar(sinCuenta: true));
    }

    #[Route('/personas/{id}', methods: ['GET'])]
    #[Autenticado(roles: self::PERSONAS)]
    public function obtenerPersona(Request $r): JsonResponse
    {
        return new JsonResponse($this->padron->visible(Peticion::uuidDeRuta($r)));
    }

    #[Route('/personas', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearPersona(Request $r): JsonResponse
    {
        $d = $this->creacion(E::persona(), $r, array_values(array_diff(array_keys(E::persona()->campos), ['PER_Estado'])));

        return new JsonResponse($this->padron->crear($d, ClienteHttp::origen($r)), 201);
    }

    #[Route('/personas/{id}', methods: ['PATCH'])]
    #[Versioned('INV_PERSONA', 'PER_Persona', uuid: true)]
    #[Autenticado(roles: Roles::ADMIN)]
    public function actualizarPersona(Request $r): JsonResponse
    {
        $id = Peticion::uuidDeRuta($r);
        $cambios = $this->cambios(E::persona(), $r);

        return new JsonResponse($this->padron->cambiar($id, $cambios, ClienteHttp::origen($r)));
    }

    /** Baja lógica (preserva el historial). */
    #[Route('/personas/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarPersona(Request $r): Response
    {
        $this->padron->eliminar(Peticion::uuidDeRuta($r), ClienteHttp::origen($r));

        return new Response(null, 204);
    }

    /** Departamento, cargo, jefe, área habitual, activos en custodia y, para IAM, usuario y rol sugeridos. */
    #[Route('/personas/{id}/contexto', methods: ['GET'])]
    #[Autenticado(roles: self::PERSONAS)]
    public function contextoPersona(Request $r): JsonResponse
    {
        return new JsonResponse($this->sugerencias->persona(Peticion::uuidDeRuta($r)));
    }

    // ================= USUARIOS (identidades y accesos) =================

    #[Route('/usuarios', methods: ['GET'])]
    #[Autenticado(roles: Roles::IAM)]
    #[Limite('30/minute')]
    public function listarUsuarios(): JsonResponse
    {
        return new JsonResponse($this->identidades->listar());
    }

    #[Route('/usuarios/seguridad/resumen', methods: ['GET'], priority: 10)]
    #[Autenticado(roles: Roles::IAM)]
    public function resumenSeguridad(): JsonResponse
    {
        return new JsonResponse($this->identidades->resumenSeguridad());
    }

    #[Route('/usuarios/seguridad/sesiones', methods: ['GET'], priority: 10)]
    #[Autenticado(roles: Roles::IAM)]
    public function sesionesActivas(): JsonResponse
    {
        return new JsonResponse($this->identidades->todasLasSesiones($this->actual->usuario()));
    }

    #[Route('/usuarios/{id}', methods: ['GET'])]
    #[Autenticado(roles: Roles::IAM)]
    public function obtenerUsuario(Request $r): JsonResponse
    {
        return new JsonResponse($this->identidades->respuesta(Peticion::uuidDeRuta($r)) ?? throw new ErrorApi(404, 'USER_NOT_FOUND'));
    }

    #[Route('/usuarios', methods: ['POST'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function crearUsuario(Request $r): JsonResponse
    {
        $e = Peticion::json($r);
        [, $username] = $e->campo(new Campo('USU_Username', requerido: true, min: 4, max: 50), true);
        [, $password] = $e->campo(new Campo('USU_Password', min: 8, max: 128), true);
        [, $rol] = $e->campo(new Campo('USU_Rol', requerido: true, opciones: Roles::TODOS), true);
        [, $sso] = $e->campo(Campo::logico('USU_SSO_Habilitado'), true);
        [, $proveedor] = $e->campo(new Campo('USU_SSO_Provider', opciones: ['microsoft', 'google']), true);
        [, $persona] = $e->campo(new Campo('PER_Persona', Campo::UUID, true), true);
        [, $global] = $e->campo(Campo::logico('USU_Alcance_Global'), true);
        $sedes = $this->listaEnteros($e, 'sedes') ?? [];
        if (null === $password && true !== $sso) {
            $e->errorDeValor('USU_Password', 'AUTH_METHOD_REQUIRED', null);
        } elseif (null !== $proveedor && true !== $sso) {
            $e->errorDeValor('USU_SSO_Provider', 'SSO_PROVIDER_REQUIRES_SSO_ENABLED', $proveedor);
        }
        $e->validar();
        // La entrada de usuario no se recorta (contraseñas): se usa tal cual llegó.
        $crudo = $r->toArray();

        return new JsonResponse($this->identidades->crear([
            'USU_Username' => (string) $crudo['USU_Username'], 'USU_Password' => isset($crudo['USU_Password']) ? (string) $crudo['USU_Password'] : null,
            'USU_Rol' => (string) $rol, 'USU_SSO_Habilitado' => (bool) $sso, 'USU_SSO_Provider' => $proveedor, 'PER_Persona' => (string) $persona,
            'USU_Alcance_Global' => (bool) $global, 'sedes' => $sedes,
        ], $this->actual->usuario(), $this->auditor($r)), 201);
    }

    #[Route('/usuarios/{id}', methods: ['PATCH'])]
    #[Versioned('INV_USUARIO', 'USU_Usuario', uuid: true)]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function actualizarUsuario(Request $r): JsonResponse
    {
        $id = Peticion::uuidDeRuta($r);

        return new JsonResponse($this->identidades->actualizar($id, $this->cambiosUsuario($r), $this->actual->usuario(), $this->auditor($r), ClienteHttp::ip($r)));
    }

    /** Desactivación lógica (preserva la auditoría). */
    #[Route('/usuarios/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function desactivarUsuario(Request $r): Response
    {
        $this->identidades->actualizar(Peticion::uuidDeRuta($r), ['USU_Estado' => false], $this->actual->usuario(), $this->auditor($r), ClienteHttp::ip($r));

        return new Response(null, 204);
    }

    /** Contraseña temporal (se devuelve UNA sola vez), cambio obligatorio y sesiones cerradas. */
    #[Route('/usuarios/{id}/password/reset', methods: ['POST'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    #[Limite('10/minute')]
    public function restablecerContrasena(Request $r): JsonResponse
    {
        $temporal = $this->identidades->restablecerContrasena(Peticion::uuidDeRuta($r), $this->actual->usuario(), $this->auditor($r), ClienteHttp::ip($r));
        $respuesta = new JsonResponse(['temporary_password' => $temporal]);
        $respuesta->headers->set('Cache-Control', 'no-store');

        return $respuesta;
    }

    #[Route('/usuarios/{id}/2fa/reset', methods: ['POST'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function restablecer2fa(Request $r): JsonResponse
    {
        $this->identidades->restablecer2fa(Peticion::uuidDeRuta($r), $this->actual->usuario(), $this->auditor($r));

        return new JsonResponse(['status' => 'success', 'message' => '2FA_RESET']);
    }

    #[Route('/usuarios/{id}/unlock', methods: ['POST'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function desbloquear(Request $r): JsonResponse
    {
        $this->identidades->desbloquear(Peticion::uuidDeRuta($r), $this->actual->usuario(), $this->auditor($r));

        return new JsonResponse(['status' => 'success']);
    }

    /** Bloqueo administrativo con motivo y, si se da, hasta el final de `until` (AAAA-MM-DD); sin fecha, indefinido. */
    #[Route('/usuarios/{id}/lock', methods: ['POST'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function bloquear(Request $r): JsonResponse
    {
        $entrada = Peticion::json($r);
        $motivo = $entrada->texto('reason', 5, 500);
        $hasta = $entrada->patron('until', '^\d{4}-\d{2}-\d{2}$');
        $entrada->validar();
        $this->identidades->bloquear(Peticion::uuidDeRuta($r), $this->actual->usuario(), $this->auditor($r), (string) $motivo, $hasta);

        return new JsonResponse(['status' => 'success']);
    }

    #[Route('/usuarios/{id}/sessions/revoke', methods: ['POST'])]
    #[Autenticado(roles: Roles::IAM)]
    public function revocarSesiones(Request $r): JsonResponse
    {
        $this->identidades->revocarSesiones(Peticion::uuidDeRuta($r), $this->actual->usuario(), $this->auditor($r));

        return new JsonResponse(['status' => 'success']);
    }

    #[Route('/usuarios/{id}/sessions', methods: ['GET'])]
    #[Autenticado(roles: Roles::IAM)]
    public function sesionesDeUsuario(Request $r): JsonResponse
    {
        return new JsonResponse($this->identidades->sesionesDe(Peticion::uuidDeRuta($r), $this->actual->usuario()));
    }

    #[Route('/usuarios/{id}/sessions/{sesion}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::IAM)]
    public function cerrarSesion(Request $r, string $sesion): Response
    {
        $this->identidades->cerrarSesion(Peticion::uuidDeRuta($r), $sesion, $this->actual->usuario(), $this->auditor($r));

        return new Response(null, 204);
    }

    // ================= apoyo =================

    /** @return array<string, mixed> */
    private function cambiosUsuario(Request $r): array
    {
        $e = Peticion::json($r);
        $cambios = [];
        foreach ([new Campo('USU_Password', min: 8, max: 128), new Campo('USU_Rol', opciones: Roles::TODOS), Campo::logico('USU_Estado'),
            Campo::logico('USU_SSO_Habilitado'), new Campo('USU_SSO_Provider', opciones: ['microsoft', 'google']), Campo::logico('USU_Alcance_Global')] as $campo) {
            [$presente, $valor] = $e->campo($campo, false);
            if ($presente) {
                $cambios[$campo->nombre] = $valor;
            }
        }
        if (\array_key_exists('sedes', $r->toArray())) {
            $cambios['sedes'] = $this->listaEnteros($e, 'sedes');
        }
        $e->validar();
        if (isset($cambios['USU_Password'])) {
            $cambios['USU_Password'] = (string) $r->toArray()['USU_Password'];
        }

        return $cambios;
    }

    /** @return list<int>|null */
    private function listaEnteros(Entrada $e, string $campo): ?array
    {
        $lista = $e->lista($campo, 200);
        foreach ($lista ?? [] as $i => $valor) {
            if (!\is_int($valor)) {
                $e->errorDeValor("{$campo}.{$i}", 'INT_REQUIRED', $valor);
            }
        }

        return null === $lista ? null : array_values(array_filter($lista, 'is_int'));
    }

    private function auditor(Request $r): \Closure
    {
        return fn (string $accion, string $entidad, array $snapshot) => $this->crud->auditar($accion, $entidad, $snapshot, ClienteHttp::origen($r));
    }

    /**
     * @param list<string>|null $soloCampos
     *
     * @return array<string, mixed>
     */
    private function creacion(\App\Crud\Entidad $e, Request $r, ?array $soloCampos = null): array
    {
        $entrada = Peticion::json($r);
        $datos = Esquema::creacion($e, $entrada, $soloCampos);
        $entrada->validar();

        return $datos;
    }

    /** @return array<string, mixed> */
    private function cambios(\App\Crud\Entidad $e, Request $r): array
    {
        $entrada = Peticion::json($r);
        $datos = Esquema::actualizacion($e, $entrada);
        $entrada->validar();

        return $datos;
    }
}
