<?php

declare(strict_types=1);

namespace App\Controller;

use App\Crud\Campo;
use App\Crud\Esquema;
use App\Crud\Operaciones;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\Roles;
use App\Http\ClienteHttp;
use App\Http\Entrada;
use App\Http\ErrorApi;
use App\Http\Limite;
use App\Organizacion\Entidades as E;
use App\Organizacion\Identidades;
use App\Organizacion\Sugerencias;
use App\Seguridad\Alcance;
use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
use Doctrine\DBAL\Connection;
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
        private Alcance $alcance,
        private UsuarioActual $actual,
        private Connection $conexion,
    ) {
    }

    /** Sedes que el usuario puede ver (todas si su alcance es global), con su ubicación. */
    #[Route('/sedes', methods: ['GET'])]
    public function sedes(): JsonResponse
    {
        [$filtro, $parametros] = $this->alcance->filtroSede('s."SED_Sede"');
        $filas = $this->conexion->fetchAllAssociative(
            'SELECT s."SED_Sede", s."SED_Nombre", m."MUN_Nombre", pa."PAI_Nombre" FROM "INV_SEDE" s '
            .'LEFT JOIN "INV_MUNICIPIO" m ON m."MUN_Municipio" = s."MUN_Municipio" LEFT JOIN "INV_ESTADO" e ON e."EST_Estado" = m."EST_Estado" '
            .'LEFT JOIN "INV_PAIS" pa ON pa."PAI_Pais" = e."PAI_Pais" WHERE '.$filtro.' ORDER BY s."SED_Nombre"', $parametros);

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
        [$sedes, $parSedes] = $this->alcance->filtroSede('p."SED_Sede"');
        [$mov, $parMov] = $this->alcance->lectura('INV_MOVIMIENTO', 'm');
        [$per, $parPer] = $this->alcance->lectura('INV_PERSONA', 'p');
        [$act, $parAct] = $this->alcance->lectura('INV_ACTIVO', 'a');
        $personas = $this->conexion->fetchAllKeyValue('SELECT p."DEP_Departamento", COUNT(*) FROM "INV_PERSONA" p WHERE p."PER_Estado" = 1 AND '.$sedes.' AND '.$per.' GROUP BY p."DEP_Departamento"', [...$parSedes, ...$parPer]);
        $activos = $this->conexion->fetchAllKeyValue(
            'SELECT p."DEP_Departamento", COUNT(DISTINCT m."ACT_Activo") FROM "INV_PERSONA" p JOIN "INV_MOVIMIENTO" m ON m."PER_Persona" = p."PER_Persona" '
            .'WHERE m."MOV_Fecha_Devolucion" IS NULL AND '.$mov.' AND '.$per.' GROUP BY p."DEP_Departamento"', [...$parMov, ...$parPer]);
        $porTipo = [];
        foreach ($this->conexion->fetchAllNumeric(
            'SELECT p."DEP_Departamento", t."TAC_Nombre", COUNT(DISTINCT m."ACT_Activo") FROM "INV_PERSONA" p '
            .'JOIN "INV_MOVIMIENTO" m ON m."PER_Persona" = p."PER_Persona" JOIN "INV_ACTIVO" a ON a."ACT_Activo" = m."ACT_Activo" '
            .'JOIN "INV_TIPO_ACTIVO" t ON t."TAC_Tipo_Activo" = a."TAC_Tipo_Activo" WHERE m."MOV_Fecha_Devolucion" IS NULL AND '.$mov.' AND '.$per.' AND '.$act
            .' GROUP BY p."DEP_Departamento", t."TAC_Nombre"', [...$parMov, ...$parPer, ...$parAct]) as [$dep, $tipo, $n]) {
            $porTipo[(int) $dep][$tipo] = (int) $n;
        }

        return new JsonResponse(array_map(static fn (array $d): array => [
            'DEP_Departamento' => (int) $d['DEP_Departamento'], 'DEP_Nombre' => $d['DEP_Nombre'], 'DEP_Codigo_Costos' => $d['DEP_Codigo_Costos'],
            'DEP_Activo' => 1 === (int) $d['DEP_Activo'], 'personas_activas' => (int) ($personas[$d['DEP_Departamento']] ?? 0),
            'activos_asignados' => (int) ($activos[$d['DEP_Departamento']] ?? 0), 'por_tipo' => (object) ($porTipo[(int) $d['DEP_Departamento']] ?? []),
        ], $this->conexion->fetchAllAssociative('SELECT * FROM "INV_DEPARTAMENTO" ORDER BY "DEP_Nombre"')));
    }

    /** Personas activas del departamento con los activos que tienen asignados. */
    #[Route('/departamentos/{id}/detalle', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTURA_PERSONAS)]
    #[Limite('10/minute')]
    public function detalleDepartamento(Request $r): JsonResponse
    {
        $id = Entrada::enteroDeRuta($r);
        $dep = $this->conexion->fetchAssociative('SELECT * FROM "INV_DEPARTAMENTO" WHERE "DEP_Departamento" = :d', ['d' => $id]) ?: throw new ErrorApi(404, 'DEPARTMENT_NOT_FOUND');
        [$sedes, $parSedes] = $this->alcance->filtroSede('p."SED_Sede"');
        [$per, $parPer] = $this->alcance->lectura('INV_PERSONA', 'p');
        [$mov, $parMov] = $this->alcance->lectura('INV_MOVIMIENTO', 'm');
        $personas = $this->conexion->fetchAllAssociative(
            'SELECT p.* FROM "INV_PERSONA" p WHERE p."DEP_Departamento" = :d AND p."PER_Estado" = 1 AND '.$sedes.' AND '.$per.' ORDER BY p."PER_Primer_Apellido"',
            ['d' => $id, ...$parSedes, ...$parPer]);
        $porPersona = [];
        if ([] !== $personas) {
            foreach ($this->conexion->fetchAllAssociative(
                'SELECT m."MOV_Movimiento", m."PER_Persona", m."MOV_Fecha_Asignacion", a."ACT_Activo", a."ACT_Codigo_Interno", a."ACT_Hostname", '
                .'a."ACT_Serie_Fabricante", t."TAC_Nombre", mo."MOD_Nombre", ma."MAR_Nombre", ar."ARE_Nombre" FROM "INV_MOVIMIENTO" m '
                .'LEFT JOIN "INV_ACTIVO" a ON a."ACT_Activo" = m."ACT_Activo" LEFT JOIN "INV_TIPO_ACTIVO" t ON t."TAC_Tipo_Activo" = a."TAC_Tipo_Activo" '
                .'LEFT JOIN "INV_MODELO" mo ON mo."MOD_Modelo" = a."MOD_Modelo" LEFT JOIN "INV_MARCA" ma ON ma."MAR_Marca" = mo."MAR_Marca" '
                .'LEFT JOIN "INV_AREA" ar ON ar."ARE_Area" = m."ARE_Area" WHERE m."MOV_Fecha_Devolucion" IS NULL AND '.$mov
                .' AND m."PER_Persona" IN (SELECT p."PER_Persona" FROM "INV_PERSONA" p WHERE p."DEP_Departamento" = :d AND p."PER_Estado" = 1 AND '.$sedes.' AND '.$per.')',
                ['d' => $id, ...$parSedes, ...$parPer, ...$parMov]) as $m) {
                $porPersona[$m['PER_Persona']][] = [
                    'MOV_Movimiento' => Ident::aTexto((string) $m['MOV_Movimiento']),
                    'MOV_Fecha_Asignacion' => Reloj::iso(Reloj::desdeBd($m['MOV_Fecha_Asignacion'])),
                    'activo' => null === $m['ACT_Activo'] ? null : ['ACT_Activo' => Ident::aTexto((string) $m['ACT_Activo']), 'ACT_Codigo_Interno' => $m['ACT_Codigo_Interno'],
                        'ACT_Hostname' => $m['ACT_Hostname'], 'ACT_Serie_Fabricante' => $m['ACT_Serie_Fabricante'], 'tipo' => $m['TAC_Nombre'],
                        'modelo' => $m['MOD_Nombre'], 'marca' => $m['MAR_Nombre']],
                    'area' => $m['ARE_Nombre'],
                ];
            }
        }

        return new JsonResponse([
            'DEP_Departamento' => (int) $dep['DEP_Departamento'], 'DEP_Nombre' => $dep['DEP_Nombre'], 'DEP_Codigo_Costos' => $dep['DEP_Codigo_Costos'],
            'DEP_Descripcion' => $dep['DEP_Descripcion'], 'DEP_Activo' => 1 === (int) $dep['DEP_Activo'],
            'personas' => array_map(static fn (array $p): array => [
                'PER_Persona' => Ident::aTexto((string) $p['PER_Persona']), 'nombre_completo' => "{$p['PER_Primer_Nombre']} {$p['PER_Primer_Apellido']}",
                'PER_Email_Corporativo' => $p['PER_Email_Corporativo'], 'PER_Telefono' => $p['PER_Telefono'], 'PER_Estado' => 1 === (int) $p['PER_Estado'],
                'activos_asignados' => $porPersona[$p['PER_Persona']] ?? [],
            ], $personas),
            'totales' => ['personas' => \count($personas), 'activos' => array_sum(array_map('count', $porPersona))],
        ]);
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
        return new JsonResponse($this->crud->obtenerO404(E::departamento(), Entrada::enteroDeRuta($r), 'DEPARTMENT_NOT_FOUND'));
    }

    #[Route('/departamentos', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearDepartamento(Request $r): JsonResponse
    {
        $d = $this->creacion(E::departamento(), $r, ['DEP_Nombre', 'DEP_Codigo_Costos', 'DEP_Descripcion']);
        if (false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_DEPARTAMENTO" WHERE "DEP_Nombre" = :n', ['n' => $d['DEP_Nombre']])) {
            throw new ErrorApi(400, 'DEPARTMENT_ALREADY_EXISTS');
        }

        return new JsonResponse($this->crud->crear(E::departamento(), [...$d, 'DEP_Activo' => 1], $r, ['nombre' => $d['DEP_Nombre']]), 201);
    }

    #[Route('/departamentos/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function actualizarDepartamento(Request $r): JsonResponse
    {
        $id = Entrada::enteroDeRuta($r);
        $cambios = $this->cambios(E::departamento(), $r);
        $this->crud->obtenerO404(E::departamento(), $id, 'DEPARTMENT_NOT_FOUND');

        return new JsonResponse($this->crud->actualizar(E::departamento(), $id, $cambios, $r));
    }

    #[Route('/departamentos/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarDepartamento(Request $r): Response
    {
        $id = Entrada::enteroDeRuta($r);
        $dep = $this->crud->obtenerO404(E::departamento(), $id, 'DEPARTMENT_NOT_FOUND');
        $this->crud->sinHijos('INV_PERSONA', 'DEP_Departamento', $id, 'CANNOT_DELETE_DEPARTMENT_HAS_PERSONS');
        $this->crud->eliminar(E::departamento(), $id, $r, ['id' => $id, 'nombre' => $dep['DEP_Nombre']]);

        return new Response(null, 204);
    }

    /** Cargo, jefe y área más probables para una persona nueva del departamento. */
    #[Route('/departamentos/{id}/contexto', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTURA_PERSONAS)]
    public function contextoDepartamento(Request $r): JsonResponse
    {
        return new JsonResponse($this->sugerencias->departamento(Entrada::enteroDeRuta($r)));
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
        return new JsonResponse($this->crud->obtenerO404(E::cargo(), Entrada::enteroDeRuta($r), 'POSITION_NOT_FOUND'));
    }

    #[Route('/cargos', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearCargo(Request $r): JsonResponse
    {
        $d = $this->creacion(E::cargo(), $r);

        return new JsonResponse($this->crud->crear(E::cargo(), $d, $r, ['nombre' => $d['CAR_Nombre']]), 201);
    }

    #[Route('/cargos/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function actualizarCargo(Request $r): JsonResponse
    {
        $id = Entrada::enteroDeRuta($r);
        $cambios = $this->cambios(E::cargo(), $r);
        $this->crud->obtenerO404(E::cargo(), $id, 'POSITION_NOT_FOUND');

        return new JsonResponse($this->crud->actualizar(E::cargo(), $id, $cambios, $r));
    }

    #[Route('/cargos/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarCargo(Request $r): Response
    {
        $id = Entrada::enteroDeRuta($r);
        $cargo = $this->crud->obtenerO404(E::cargo(), $id, 'POSITION_NOT_FOUND');
        $this->crud->sinHijos('INV_PERSONA', 'CAR_Cargo', $id, 'CANNOT_DELETE_POSITION_HAS_PERSONS');
        $this->crud->eliminar(E::cargo(), $id, $r, ['id' => $id, 'nombre' => $cargo['CAR_Nombre']]);

        return new Response(null, 204);
    }

    // ================= PERSONAS =================

    /** Padrón de personas de las sedes del alcance (datos personales: límite anti-scraping). */
    #[Route('/personas', methods: ['GET'])]
    #[Autenticado(roles: self::PERSONAS)]
    #[Limite('20/minute')]
    public function listarPersonas(): JsonResponse
    {
        return new JsonResponse($this->personas('1 = 1'));
    }

    /** Personas sin usuario asignado. */
    #[Route('/personas/disponibles', methods: ['GET'], priority: 10)]
    #[Autenticado(roles: self::PERSONAS)]
    #[Limite('20/minute')]
    public function personasDisponibles(): JsonResponse
    {
        return new JsonResponse($this->personas('NOT EXISTS (SELECT 1 FROM "INV_USUARIO" u WHERE u."PER_Persona" = t."PER_Persona")'));
    }

    #[Route('/personas/{id}', methods: ['GET'])]
    #[Autenticado(roles: self::PERSONAS)]
    public function obtenerPersona(Request $r): JsonResponse
    {
        return new JsonResponse($this->personaVisible(Entrada::uuidDeRuta($r)));
    }

    #[Route('/personas', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearPersona(Request $r): JsonResponse
    {
        $d = $this->creacion(E::persona(), $r, array_values(array_diff(array_keys(E::persona()->campos), ['PER_Estado'])));
        $d['PER_Email_Corporativo'] = mb_strtolower((string) $d['PER_Email_Corporativo']);
        $d['SED_Sede'] = $this->alcance->exigirSede($d['SED_Sede'], false);
        $this->validarJefe($d['PER_Jefe']);
        // El correo es único en toda la empresa, no solo en el alcance.
        if ($this->alcance->elevado(fn (): bool => false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_PERSONA" WHERE "PER_Email_Corporativo" = :c', ['c' => $d['PER_Email_Corporativo']]))) {
            throw new ErrorApi(400, 'EMAIL_ALREADY_EXISTS');
        }
        $persona = $this->crud->crear(E::persona(), [...$d, 'PER_Estado' => 1], $r, [
            'email' => $d['PER_Email_Corporativo'], 'nombre' => "{$d['PER_Primer_Nombre']} {$d['PER_Primer_Apellido']}", 'sede' => $d['SED_Sede'],
        ], $d['SED_Sede']);

        return new JsonResponse($persona, 201);
    }

    #[Route('/personas/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function actualizarPersona(Request $r): JsonResponse
    {
        $id = Entrada::uuidDeRuta($r);
        $cambios = $this->cambios(E::persona(), $r);
        $persona = $this->personaVisible($id);
        // Solo personas de las sedes del alcance (no las visibles solo por historial).
        $this->alcance->exigirPropio($persona['SED_Sede']);
        if (\array_key_exists('SED_Sede', $cambios)) {
            $cambios['SED_Sede'] = $this->alcance->exigirSede($cambios['SED_Sede'], false);
        }
        if (isset($cambios['PER_Email_Corporativo'])) {
            $cambios['PER_Email_Corporativo'] = mb_strtolower((string) $cambios['PER_Email_Corporativo']);
        }
        if (null !== ($cambios['PER_Jefe'] ?? null) && $cambios['PER_Jefe'] === $id) {
            throw new ErrorApi(400, 'PERSON_CANNOT_BE_OWN_MANAGER');
        }
        $this->validarJefe($cambios['PER_Jefe'] ?? null);
        $sede = \array_key_exists('SED_Sede', $cambios) ? $cambios['SED_Sede'] : $persona['SED_Sede'];

        return new JsonResponse($this->crud->actualizar(E::persona(), $id, $cambios, $r,
            ['id' => Ident::aTexto($id), 'cambios' => (object) $cambios], $sede));
    }

    /** Baja lógica (preserva el historial). */
    #[Route('/personas/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarPersona(Request $r): Response
    {
        $id = Entrada::uuidDeRuta($r);
        $persona = $this->personaVisible($id);
        $this->alcance->exigirPropio($persona['SED_Sede']);
        if (false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_USUARIO" WHERE "PER_Persona" = :p', ['p' => $id])) {
            throw new ErrorApi(409, 'CANNOT_DELETE_PERSON_HAS_USER_ACCOUNT');
        }
        $this->conexion->transactional(function () use ($id, $persona, $r): void {
            $this->crud->repositorio->actualizar(E::persona(), $id, ['PER_Estado' => 0]);
            $this->crud->auditar('DELETE_LOGIC', 'INV_PERSONA', ['id' => Ident::aTexto($id), 'email' => $persona['PER_Email_Corporativo']], $r, $persona['SED_Sede']);
        });

        return new Response(null, 204);
    }

    /** Departamento, cargo, jefe, área habitual, activos en custodia y, para IAM, usuario y rol sugeridos. */
    #[Route('/personas/{id}/contexto', methods: ['GET'])]
    #[Autenticado(roles: self::PERSONAS)]
    public function contextoPersona(Request $r): JsonResponse
    {
        return new JsonResponse($this->sugerencias->persona(Entrada::uuidDeRuta($r)));
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
        return new JsonResponse($this->identidades->respuesta(Entrada::uuidDeRuta($r)) ?? throw new ErrorApi(404, 'USER_NOT_FOUND'));
    }

    #[Route('/usuarios', methods: ['POST'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function crearUsuario(Request $r): JsonResponse
    {
        $e = Entrada::json($r);
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
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function actualizarUsuario(Request $r): JsonResponse
    {
        $id = Entrada::uuidDeRuta($r);

        return new JsonResponse($this->identidades->actualizar($id, $this->cambiosUsuario($r), $this->actual->usuario(), $this->auditor($r), ClienteHttp::ip($r)));
    }

    /** Desactivación lógica (preserva la auditoría). */
    #[Route('/usuarios/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function desactivarUsuario(Request $r): Response
    {
        $this->identidades->actualizar(Entrada::uuidDeRuta($r), ['USU_Estado' => false], $this->actual->usuario(), $this->auditor($r), ClienteHttp::ip($r));

        return new Response(null, 204);
    }

    /** Contraseña temporal (se devuelve UNA sola vez), cambio obligatorio y sesiones cerradas. */
    #[Route('/usuarios/{id}/password/reset', methods: ['POST'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    #[Limite('10/minute')]
    public function restablecerContrasena(Request $r): JsonResponse
    {
        $temporal = $this->identidades->restablecerContrasena(Entrada::uuidDeRuta($r), $this->actual->usuario(), $this->auditor($r), ClienteHttp::ip($r));
        $respuesta = new JsonResponse(['temporary_password' => $temporal]);
        $respuesta->headers->set('Cache-Control', 'no-store');

        return $respuesta;
    }

    #[Route('/usuarios/{id}/2fa/reset', methods: ['POST'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function restablecer2fa(Request $r): JsonResponse
    {
        $this->identidades->restablecer2fa(Entrada::uuidDeRuta($r), $this->actual->usuario(), $this->auditor($r));

        return new JsonResponse(['status' => 'success', 'message' => '2FA_RESET']);
    }

    #[Route('/usuarios/{id}/unlock', methods: ['POST'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function desbloquear(Request $r): JsonResponse
    {
        $this->identidades->desbloquear(Entrada::uuidDeRuta($r), $this->actual->usuario(), $this->auditor($r));

        return new JsonResponse(['status' => 'success']);
    }

    #[Route('/usuarios/{id}/sessions/revoke', methods: ['POST'])]
    #[Autenticado(roles: Roles::IAM)]
    public function revocarSesiones(Request $r): JsonResponse
    {
        $this->identidades->revocarSesiones(Entrada::uuidDeRuta($r), $this->actual->usuario(), $this->auditor($r));

        return new JsonResponse(['status' => 'success']);
    }

    #[Route('/usuarios/{id}/sessions', methods: ['GET'])]
    #[Autenticado(roles: Roles::IAM)]
    public function sesionesDeUsuario(Request $r): JsonResponse
    {
        return new JsonResponse($this->identidades->sesionesDe(Entrada::uuidDeRuta($r), $this->actual->usuario()));
    }

    #[Route('/usuarios/{id}/sessions/{sesion}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::IAM)]
    public function cerrarSesion(Request $r, string $sesion): Response
    {
        $this->identidades->cerrarSesion(Entrada::uuidDeRuta($r), $sesion, $this->actual->usuario(), $this->auditor($r));

        return new Response(null, 204);
    }

    // ================= apoyo =================

    /** @return list<array<string, mixed>> */
    private function personas(string $condicion): array
    {
        [$sedes, $parSedes] = $this->alcance->filtroSede('t."SED_Sede"');
        [$per, $parPer] = $this->alcance->lectura('INV_PERSONA', 't');

        return $this->crud->repositorio->listar(E::persona(), "{$condicion} AND {$sedes} AND {$per}", [...$parSedes, ...$parPer], 't."PER_Primer_Apellido", t."PER_Primer_Nombre", t."PER_Persona"');
    }

    /**

     * Persona visible para el usuario (también por el historial de un activo) o 404.

     *

     * @return array<string, mixed>

     */
    private function personaVisible(string $id): array
    {
        [$per, $parPer] = $this->alcance->lectura('INV_PERSONA', 't');

        return $this->crud->repositorio->listar(E::persona(), 't."PER_Persona" = :id AND '.$per, ['id' => $id, ...$parPer])[0]
            ?? throw new ErrorApi(404, 'PERSON_NOT_FOUND');
    }

    private function validarJefe(?string $jefe): void
    {
        if (null !== $jefe) {
            try {
                $this->personaVisible(Ident::aHex($jefe));
            } catch (ErrorApi) {
                throw new ErrorApi(404, 'MANAGER_NOT_FOUND');
            }
        }
    }

    /** @return array<string, mixed> */
    private function cambiosUsuario(Request $r): array
    {
        $e = Entrada::json($r);
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
        return fn (string $accion, string $entidad, array $snapshot) => $this->crud->auditar($accion, $entidad, $snapshot, $r);
    }

    /**
     * @param list<string>|null $soloCampos
     *
     * @return array<string, mixed>
     */
    private function creacion(\App\Crud\Entidad $e, Request $r, ?array $soloCampos = null): array
    {
        $entrada = Entrada::json($r);
        $datos = Esquema::creacion($e, $entrada, $soloCampos);
        $entrada->validar();

        return $datos;
    }

    /** @return array<string, mixed> */
    private function cambios(\App\Crud\Entidad $e, Request $r): array
    {
        $entrada = Entrada::json($r);
        $datos = Esquema::actualizacion($e, $entrada);
        $entrada->validar();

        return $datos;
    }
}
