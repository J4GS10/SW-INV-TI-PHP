<?php

declare(strict_types=1);

namespace App\Controller;

use App\Catalogo\CatalogoGenerico;
use App\Crud\Entidad;
use App\Crud\Esquema;
use App\Crud\Operaciones;
use App\Dominio\Roles;
use App\Http\Entrada;
use App\Http\ErrorApi;
use App\Seguridad\Alcance;
use App\Seguridad\Autenticado;
use App\Ubicacion\Entidades as E;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ubicación geográfica. Lectura: roles de la aplicación; escritura: ADMIN.
 * País, estado, municipio y sede son jerarquía compartida (solo alcance
 * global); edificios, niveles y áreas pertenecen a una sede del alcance.
 */
#[Route('/api/v1/geo')]
#[Autenticado(roles: Roles::APP)]
final readonly class UbicacionController
{
    public function __construct(
        private Operaciones $crud,
        private Alcance $alcance,
        private CatalogoGenerico $catalogos,
        private Connection $conexion,
    ) {
    }

    // ================= PAÍS =================

    #[Route('/paises', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearPais(Request $r): JsonResponse
    {
        $this->alcance->exigirGlobal();
        $d = $this->validarCreacion(E::pais(), $r);

        return new JsonResponse($this->crud->crear(E::pais(), $d, $r, ['nombre' => $d['PAI_Nombre'], 'iso' => $d['PAI_Codigo_ISO']]), 201);
    }

    #[Route('/paises', methods: ['GET'])]
    public function listarPaises(): JsonResponse
    {
        return new JsonResponse($this->crud->repositorio->listar(E::pais()));
    }

    #[Route('/paises/{id}', methods: ['GET'])]
    public function obtenerPais(Request $r): JsonResponse
    {
        return new JsonResponse($this->crud->obtenerO404(E::pais(), Entrada::enteroDeRuta($r), 'COUNTRY_NOT_FOUND'));
    }

    #[Route('/paises/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function actualizarPais(Request $r): JsonResponse
    {
        $id = Entrada::enteroDeRuta($r);
        $this->alcance->exigirGlobal();
        $cambios = $this->validarCambios(E::pais(), $r);
        $this->crud->obtenerO404(E::pais(), $id, 'COUNTRY_NOT_FOUND');

        return new JsonResponse($this->crud->actualizar(E::pais(), $id, $cambios, $r));
    }

    #[Route('/paises/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarPais(Request $r): Response
    {
        $id = Entrada::enteroDeRuta($r);
        $this->alcance->exigirGlobal();
        $pais = $this->crud->obtenerO404(E::pais(), $id, 'COUNTRY_NOT_FOUND');
        $this->crud->sinHijos('INV_ESTADO', 'PAI_Pais', $id, 'CANNOT_DELETE_COUNTRY_HAS_STATES');
        $this->crud->eliminar(E::pais(), $id, $r, ['id' => $id, 'nombre' => $pais['PAI_Nombre']]);

        return new Response(null, 204);
    }

    // ================= ESTADO =================

    #[Route('/estados', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearEstado(Request $r): JsonResponse
    {
        $this->alcance->exigirGlobal();
        $d = $this->validarCreacion(E::estado(), $r);

        return new JsonResponse($this->crud->crear(E::estado(), $d, $r, ['nombre' => $d['EST_Nombre'], 'pais_id' => $d['PAI_Pais']]), 201);
    }

    #[Route('/estados', methods: ['GET'])]
    public function listarEstados(Request $r): JsonResponse
    {
        $pais = $this->consultaEntera($r, 'pais_id');

        return new JsonResponse($this->crud->repositorio->listar(E::estado(), 't."PAI_Pais" = :p', ['p' => $pais]));
    }

    #[Route('/estados/{id}', methods: ['GET'])]
    public function obtenerEstado(Request $r): JsonResponse
    {
        return new JsonResponse($this->crud->obtenerO404(E::estado(), Entrada::enteroDeRuta($r), 'STATE_NOT_FOUND'));
    }

    #[Route('/estados/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function actualizarEstado(Request $r): JsonResponse
    {
        $id = Entrada::enteroDeRuta($r);
        $this->alcance->exigirGlobal();
        $cambios = $this->validarCambios(E::estado(), $r);
        $this->crud->obtenerO404(E::estado(), $id, 'STATE_NOT_FOUND');

        return new JsonResponse($this->crud->actualizar(E::estado(), $id, $cambios, $r));
    }

    #[Route('/estados/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarEstado(Request $r): Response
    {
        $id = Entrada::enteroDeRuta($r);
        $this->alcance->exigirGlobal();
        $estado = $this->crud->obtenerO404(E::estado(), $id, 'STATE_NOT_FOUND');
        $this->crud->sinHijos('INV_MUNICIPIO', 'EST_Estado', $id, 'CANNOT_DELETE_STATE_HAS_MUNICIPALITIES');
        $this->crud->eliminar(E::estado(), $id, $r, ['id' => $id, 'nombre' => $estado['EST_Nombre']]);

        return new Response(null, 204);
    }

    // ================= MUNICIPIO =================

    #[Route('/municipios', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearMunicipio(Request $r): JsonResponse
    {
        $this->alcance->exigirGlobal();
        $d = $this->validarCreacion(E::municipio(), $r);

        return new JsonResponse($this->crud->crear(E::municipio(), $d, $r, ['nombre' => $d['MUN_Nombre'], 'estado_id' => $d['EST_Estado']]), 201);
    }

    #[Route('/municipios', methods: ['GET'])]
    public function listarMunicipios(Request $r): JsonResponse
    {
        $estado = $this->consultaEntera($r, 'estado_id');

        return new JsonResponse($this->crud->repositorio->listar(E::municipio(), 't."EST_Estado" = :e', ['e' => $estado]));
    }

    #[Route('/municipios/{id}', methods: ['GET'])]
    public function obtenerMunicipio(Request $r): JsonResponse
    {
        return new JsonResponse($this->crud->obtenerO404(E::municipio(), Entrada::enteroDeRuta($r), 'MUNICIPALITY_NOT_FOUND'));
    }

    #[Route('/municipios/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function actualizarMunicipio(Request $r): JsonResponse
    {
        $id = Entrada::enteroDeRuta($r);
        $this->alcance->exigirGlobal();
        $cambios = $this->validarCambios(E::municipio(), $r);
        $this->crud->obtenerO404(E::municipio(), $id, 'MUNICIPALITY_NOT_FOUND');

        return new JsonResponse($this->crud->actualizar(E::municipio(), $id, $cambios, $r));
    }

    #[Route('/municipios/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarMunicipio(Request $r): Response
    {
        $id = Entrada::enteroDeRuta($r);
        $this->alcance->exigirGlobal();
        $municipio = $this->crud->obtenerO404(E::municipio(), $id, 'MUNICIPALITY_NOT_FOUND');
        $this->crud->sinHijos('INV_SEDE', 'MUN_Municipio', $id, 'CANNOT_DELETE_MUNICIPALITY_HAS_SITES');
        $this->crud->eliminar(E::municipio(), $id, $r, ['id' => $id, 'nombre' => $municipio['MUN_Nombre']]);

        return new Response(null, 204);
    }

    // ================= SEDE =================

    #[Route('/sedes', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearSede(Request $r): JsonResponse
    {
        $this->alcance->exigirGlobal();
        $d = $this->validarCreacion(E::sede(), $r);

        return new JsonResponse($this->crud->crear(E::sede(), $d, $r, ['nombre' => $d['SED_Nombre']]), 201);
    }

    /** Sedes de un municipio, solo las del alcance del usuario. */
    #[Route('/sedes', methods: ['GET'])]
    public function listarSedes(Request $r): JsonResponse
    {
        $municipio = $this->consultaEntera($r, 'municipio_id');
        [$filtro, $parametros] = $this->alcance->filtroSede('t."SED_Sede"');

        return new JsonResponse($this->crud->repositorio->listar(E::sede(), 't."MUN_Municipio" = :m AND '.$filtro, ['m' => $municipio, ...$parametros]));
    }

    #[Route('/sedes/{id}', methods: ['GET'])]
    public function obtenerSede(Request $r): JsonResponse
    {
        $sede = $this->crud->obtenerO404(E::sede(), Entrada::enteroDeRuta($r), 'SITE_NOT_FOUND');
        if (!$this->alcance->permite($sede['SED_Sede'])) {
            throw new ErrorApi(404, 'SITE_NOT_FOUND');
        }

        return new JsonResponse($sede);
    }

    #[Route('/sedes/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function actualizarSede(Request $r): JsonResponse
    {
        $id = Entrada::enteroDeRuta($r);
        $this->alcance->exigirGlobal();
        $cambios = $this->validarCambios(E::sede(), $r);
        $this->crud->obtenerO404(E::sede(), $id, 'SITE_NOT_FOUND');

        return new JsonResponse($this->crud->actualizar(E::sede(), $id, $cambios, $r));
    }

    #[Route('/sedes/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarSede(Request $r): Response
    {
        $id = Entrada::enteroDeRuta($r);
        $this->alcance->exigirGlobal();
        $sede = $this->crud->obtenerO404(E::sede(), $id, 'SITE_NOT_FOUND');
        $this->crud->sinHijos('INV_EDIFICIO', 'SED_Sede', $id, 'CANNOT_DELETE_SITE_HAS_BUILDINGS');
        $this->crud->eliminar(E::sede(), $id, $r, ['id' => $id, 'nombre' => $sede['SED_Nombre']]);

        return new Response(null, 204);
    }

    // ================= EDIFICIO =================

    #[Route('/edificios', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearEdificio(Request $r): JsonResponse
    {
        $d = $this->validarCreacion(E::edificio(), $r);
        $this->alcance->exigirSede($d['SED_Sede']);

        return new JsonResponse($this->crud->crear(E::edificio(), $d, $r,
            ['nombre' => $d['EDI_Nombre'], 'sede_id' => $d['SED_Sede']], $d['SED_Sede']), 201);
    }

    #[Route('/edificios', methods: ['GET'])]
    public function listarEdificios(Request $r): JsonResponse
    {
        $sede = $this->consultaEntera($r, 'sede_id');
        if (!$this->alcance->permite($sede)) {
            return new JsonResponse([]);
        }

        return new JsonResponse($this->crud->repositorio->listar(E::edificio(), 't."SED_Sede" = :s', ['s' => $sede]));
    }

    #[Route('/edificios/{id}', methods: ['GET'])]
    public function obtenerEdificio(Request $r): JsonResponse
    {
        $edificio = $this->crud->obtenerO404(E::edificio(), Entrada::enteroDeRuta($r), 'BUILDING_NOT_FOUND');
        if (!$this->alcance->permite($edificio['SED_Sede'])) {
            throw new ErrorApi(404, 'BUILDING_NOT_FOUND');
        }

        return new JsonResponse($edificio);
    }

    #[Route('/edificios/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function actualizarEdificio(Request $r): JsonResponse
    {
        $id = Entrada::enteroDeRuta($r);
        $cambios = $this->validarCambios(E::edificio(), $r);
        $this->alcance->exigirPropio($this->sedeDeEdificio($id));

        return new JsonResponse($this->crud->actualizar(E::edificio(), $id, $cambios, $r));
    }

    #[Route('/edificios/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarEdificio(Request $r): Response
    {
        $id = Entrada::enteroDeRuta($r);
        $edificio = $this->crud->obtenerO404(E::edificio(), $id, 'BUILDING_NOT_FOUND');
        $this->alcance->exigirPropio($edificio['SED_Sede']);
        $this->crud->sinHijos('INV_NIVEL', 'EDI_Edificio', $id, 'CANNOT_DELETE_BUILDING_HAS_LEVELS');
        $this->crud->eliminar(E::edificio(), $id, $r, ['id' => $id, 'nombre' => $edificio['EDI_Nombre']]);

        return new Response(null, 204);
    }

    // ================= NIVEL =================

    #[Route('/niveles', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearNivel(Request $r): JsonResponse
    {
        $d = $this->validarCreacion(E::nivel(), $r);
        $this->alcance->exigirPropio($this->sedeDeEdificio($d['EDI_Edificio']));

        return new JsonResponse($this->crud->crear(E::nivel(), $d, $r,
            ['piso' => $d['NIV_Numero_Piso'], 'edificio_id' => $d['EDI_Edificio']]), 201);
    }

    #[Route('/niveles', methods: ['GET'])]
    public function listarNiveles(Request $r): JsonResponse
    {
        $edificio = $this->crud->repositorio->obtener(E::edificio(), $this->consultaEntera($r, 'edificio_id'));
        if (null === $edificio || !$this->alcance->permite($edificio['SED_Sede'])) {
            return new JsonResponse([]);
        }

        return new JsonResponse($this->crud->repositorio->listar(E::nivel(), 't."EDI_Edificio" = :e', ['e' => $edificio['EDI_Edificio']]));
    }

    #[Route('/niveles/{id}', methods: ['GET'])]
    public function obtenerNivel(Request $r): JsonResponse
    {
        $nivel = $this->crud->obtenerO404(E::nivel(), Entrada::enteroDeRuta($r), 'LEVEL_NOT_FOUND');
        if (!$this->alcance->permite($this->sedeDeEdificio($nivel['EDI_Edificio']))) {
            throw new ErrorApi(404, 'LEVEL_NOT_FOUND');
        }

        return new JsonResponse($nivel);
    }

    #[Route('/niveles/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function actualizarNivel(Request $r): JsonResponse
    {
        $id = Entrada::enteroDeRuta($r);
        $cambios = $this->validarCambios(E::nivel(), $r);
        $this->alcance->exigirPropio($this->sedeDeNivel($id));

        return new JsonResponse($this->crud->actualizar(E::nivel(), $id, $cambios, $r));
    }

    #[Route('/niveles/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarNivel(Request $r): Response
    {
        $id = Entrada::enteroDeRuta($r);
        $nivel = $this->crud->obtenerO404(E::nivel(), $id, 'LEVEL_NOT_FOUND');
        $this->alcance->exigirPropio($this->sedeDeEdificio($nivel['EDI_Edificio']));
        $this->crud->sinHijos('INV_AREA', 'NIV_Nivel', $id, 'CANNOT_DELETE_LEVEL_HAS_AREAS');
        $this->crud->eliminar(E::nivel(), $id, $r, ['id' => $id, 'piso' => $nivel['NIV_Numero_Piso']]);

        return new Response(null, 204);
    }

    // ================= ÁREA =================

    /** Todas las áreas del alcance con su ruta (Sede › Edificio › Piso › Área), para selectores. */
    #[Route('/areas/all', methods: ['GET'], priority: 10)]
    public function areasJerarquia(): JsonResponse
    {
        [$filtro, $parametros] = $this->alcance->filtroSede('e."SED_Sede"');
        $filas = $this->conexion->fetchAllAssociative(
            'SELECT a."ARE_Area", a."ARE_Nombre", a."ARE_Tipo_Acceso", a."TAR_Tipo_Area", ta."TAR_Codigo", a."NIV_Nivel", '
            .'e."SED_Sede", s."SED_Nombre", e."EDI_Nombre", n."NIV_Numero_Piso" '
            .'FROM "INV_AREA" a JOIN "INV_NIVEL" n ON n."NIV_Nivel" = a."NIV_Nivel" '
            .'JOIN "INV_EDIFICIO" e ON e."EDI_Edificio" = n."EDI_Edificio" JOIN "INV_SEDE" s ON s."SED_Sede" = e."SED_Sede" '
            .'LEFT JOIN "INV_TIPO_AREA" ta ON ta."TAR_Tipo_Area" = a."TAR_Tipo_Area" '
            .'WHERE '.$filtro.' ORDER BY a."ARE_Nombre", a."ARE_Area"',
            $parametros,
        );

        return new JsonResponse(array_map(static fn (array $f): array => [
            'ARE_Area' => (int) $f['ARE_Area'],
            'ARE_Nombre' => $f['ARE_Nombre'],
            'ARE_Tipo_Acceso' => $f['ARE_Tipo_Acceso'],
            'TAR_Tipo_Area' => null === $f['TAR_Tipo_Area'] ? null : (int) $f['TAR_Tipo_Area'],
            'tipo_area' => $f['TAR_Codigo'],
            'NIV_Nivel' => (int) $f['NIV_Nivel'],
            'SED_Sede' => (int) $f['SED_Sede'],
            'ruta' => implode(' › ', array_filter([$f['SED_Nombre'], $f['EDI_Nombre'], 'Piso '.$f['NIV_Numero_Piso'], $f['ARE_Nombre']])),
        ], $filas));
    }

    #[Route('/areas', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearArea(Request $r): JsonResponse
    {
        $d = $this->validarCreacion(E::area(), $r);
        $this->alcance->exigirPropio($this->sedeDeNivel($d['NIV_Nivel']));
        $this->validarTipoArea($d['TAR_Tipo_Area'] ?? null);

        return new JsonResponse($this->crud->crear(E::area(), $d, $r,
            ['nombre' => $d['ARE_Nombre'], 'nivel_id' => $d['NIV_Nivel']]), 201);
    }

    #[Route('/areas', methods: ['GET'])]
    public function listarAreas(Request $r): JsonResponse
    {
        $nivel = $this->crud->repositorio->obtener(E::nivel(), $this->consultaEntera($r, 'nivel_id'));
        if (null === $nivel || !$this->alcance->permite($this->sedeDeEdificio($nivel['EDI_Edificio']))) {
            return new JsonResponse([]);
        }

        return new JsonResponse($this->crud->repositorio->listar(E::area(), 't."NIV_Nivel" = :n', ['n' => $nivel['NIV_Nivel']]));
    }

    #[Route('/areas/{id}', methods: ['GET'])]
    public function obtenerArea(Request $r): JsonResponse
    {
        $area = $this->crud->obtenerO404(E::area(), Entrada::enteroDeRuta($r), 'AREA_NOT_FOUND');
        if (!$this->alcance->permite($this->sedeDeNivel($area['NIV_Nivel']))) {
            throw new ErrorApi(404, 'AREA_NOT_FOUND');
        }

        return new JsonResponse($area);
    }

    #[Route('/areas/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function actualizarArea(Request $r): JsonResponse
    {
        $id = Entrada::enteroDeRuta($r);
        $cambios = $this->validarCambios(E::area(), $r);
        $area = $this->crud->obtenerO404(E::area(), $id, 'AREA_NOT_FOUND');
        $this->alcance->exigirPropio($this->sedeDeNivel($area['NIV_Nivel']));
        $this->validarTipoArea($cambios['TAR_Tipo_Area'] ?? null);

        return new JsonResponse($this->crud->actualizar(E::area(), $id, $cambios, $r));
    }

    #[Route('/areas/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarArea(Request $r): Response
    {
        $id = Entrada::enteroDeRuta($r);
        $area = $this->crud->obtenerO404(E::area(), $id, 'AREA_NOT_FOUND');
        $this->alcance->exigirPropio($this->sedeDeNivel($area['NIV_Nivel']));
        try {
            $this->crud->eliminar(E::area(), $id, $r, ['id' => $id, 'nombre' => $area['ARE_Nombre']]);
        } catch (\App\Db\RecordInUse) {
            throw new ErrorApi(409, 'CANNOT_DELETE_AREA_IN_USE');
        }

        return new Response(null, 204);
    }

    // ================= apoyo =================

    /** @return array<string, mixed> */
    private function validarCreacion(Entidad $e, Request $r): array
    {
        $entrada = Entrada::json($r);
        $datos = Esquema::creacion($e, $entrada);
        $entrada->validar();

        return $datos;
    }

    /** @return array<string, mixed> */
    private function validarCambios(Entidad $e, Request $r): array
    {
        $entrada = Entrada::json($r);
        $datos = Esquema::actualizacion($e, $entrada);
        $entrada->validar();

        return $datos;
    }

    private function consultaEntera(Request $r, string $nombre): int
    {
        $entrada = Entrada::consulta($r);
        [, $valor] = $entrada->campo(\App\Crud\Campo::entero($nombre, true), true);
        $entrada->validar();

        return (int) $valor;
    }

    private function sedeDeEdificio(int $edificio): ?int
    {
        return $this->crud->obtenerO404(E::edificio(), $edificio, 'BUILDING_NOT_FOUND')['SED_Sede'];
    }

    private function sedeDeNivel(int $nivel): ?int
    {
        return $this->sedeDeEdificio($this->crud->obtenerO404(E::nivel(), $nivel, 'LEVEL_NOT_FOUND')['EDI_Edificio']);
    }

    private function validarTipoArea(?int $tipo): void
    {
        if (null !== $tipo) {
            $this->catalogos->nombreVigente('tipos-area', $tipo);
        }
    }
}
