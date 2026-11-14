<?php

declare(strict_types=1);

namespace App\Organizacion;

use App\Crud\Operaciones;
use App\Dominio\ErrorDominio;
use App\Dominio\Ident;
use App\Dominio\Origen;
use App\Dominio\Reloj;
use App\Organizacion\Entidades as E;
use App\Organizacion\Infrastructure\OrganizacionStore;

/**
 * Departamentos: su catálogo y lo que de ellos ve cada usuario (personas activas y activos que tienen
 * asignados, siempre dentro de su alcance). Un departamento con personas no se elimina.
 */
final readonly class Departamentos
{
    public function __construct(private OrganizacionStore $datos, private Operaciones $crud)
    {
    }

    /** @return list<array<string, mixed>> cada departamento con sus personas activas, sus activos asignados y el desglose por tipo */
    public function resumen(): array
    {
        $personas = $this->datos->personasActivasPorDepartamento();
        $activos = $this->datos->activosAsignadosPorDepartamento();
        $porTipo = [];
        foreach ($this->datos->activosAsignadosPorDepartamentoYTipo() as [$dep, $tipo, $n]) {
            $porTipo[(int) $dep][$tipo] = (int) $n;
        }

        return array_map(static fn (array $d): array => [
            'DEP_Departamento' => (int) $d['DEP_Departamento'], 'DEP_Nombre' => $d['DEP_Nombre'], 'DEP_Codigo_Costos' => $d['DEP_Codigo_Costos'],
            'DEP_Activo' => 1 === (int) $d['DEP_Activo'], 'personas_activas' => (int) ($personas[$d['DEP_Departamento']] ?? 0),
            'activos_asignados' => (int) ($activos[$d['DEP_Departamento']] ?? 0), 'por_tipo' => (object) ($porTipo[(int) $d['DEP_Departamento']] ?? []),
        ], $this->datos->departamentos());
    }

    /** @return array<string, mixed> el departamento con sus personas activas y los activos que cada una tiene asignados */
    public function detalle(int $id): array
    {
        $dep = $this->datos->departamento($id) ?: throw ErrorDominio::noEncontrado('DEPARTMENT_NOT_FOUND');
        $personas = $this->datos->personasActivasDe($id);
        $porPersona = [];
        foreach ([] === $personas ? [] : $this->datos->custodiasDe($id) as $m) {
            $porPersona[$m['PER_Persona']][] = [
                'MOV_Movimiento' => Ident::aTexto((string) $m['MOV_Movimiento']),
                'MOV_Fecha_Asignacion' => Reloj::iso(Reloj::desdeBd($m['MOV_Fecha_Asignacion'])),
                'activo' => null === $m['ACT_Activo'] ? null : ['ACT_Activo' => Ident::aTexto((string) $m['ACT_Activo']), 'ACT_Codigo_Interno' => $m['ACT_Codigo_Interno'],
                    'ACT_Hostname' => $m['ACT_Hostname'], 'ACT_Serie_Fabricante' => $m['ACT_Serie_Fabricante'], 'tipo' => $m['TAC_Nombre'],
                    'modelo' => $m['MOD_Nombre'], 'marca' => $m['MAR_Nombre']],
                'area' => $m['ARE_Nombre'],
            ];
        }

        return [
            'DEP_Departamento' => (int) $dep['DEP_Departamento'], 'DEP_Nombre' => $dep['DEP_Nombre'], 'DEP_Codigo_Costos' => $dep['DEP_Codigo_Costos'],
            'DEP_Descripcion' => $dep['DEP_Descripcion'], 'DEP_Activo' => 1 === (int) $dep['DEP_Activo'],
            'personas' => array_map(static fn (array $p): array => [
                'PER_Persona' => Ident::aTexto((string) $p['PER_Persona']), 'nombre_completo' => "{$p['PER_Primer_Nombre']} {$p['PER_Primer_Apellido']}",
                'PER_Email_Corporativo' => $p['PER_Email_Corporativo'], 'PER_Telefono' => $p['PER_Telefono'], 'PER_Estado' => 1 === (int) $p['PER_Estado'],
                'activos_asignados' => $porPersona[$p['PER_Persona']] ?? [],
            ], $personas),
            'totales' => ['personas' => \count($personas), 'activos' => array_sum(array_map('count', $porPersona))],
        ];
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed> el departamento creado, activo
     */
    public function crear(array $d, Origen $origen): array
    {
        if ($this->datos->existeDepartamentoLlamado($d['DEP_Nombre'])) {
            throw ErrorDominio::invalido('DEPARTMENT_ALREADY_EXISTS');
        }

        return $this->crud->crear(E::departamento(), [...$d, 'DEP_Activo' => 1], $origen, ['nombre' => $d['DEP_Nombre']]);
    }

    /**
     * @param array<string, mixed> $cambios
     *
     * @return array<string, mixed>
     */
    public function cambiar(int $id, array $cambios, Origen $origen): array
    {
        $this->crud->obtenerO404(E::departamento(), $id, 'DEPARTMENT_NOT_FOUND');

        return $this->crud->actualizar(E::departamento(), $id, $cambios, $origen);
    }

    public function eliminar(int $id, Origen $origen): void
    {
        $dep = $this->crud->obtenerO404(E::departamento(), $id, 'DEPARTMENT_NOT_FOUND');
        $this->crud->sinHijos('INV_PERSONA', 'DEP_Departamento', $id, 'CANNOT_DELETE_DEPARTMENT_HAS_PERSONS');
        $this->crud->eliminar(E::departamento(), $id, $origen, ['id' => $id, 'nombre' => $dep['DEP_Nombre']]);
    }
}
