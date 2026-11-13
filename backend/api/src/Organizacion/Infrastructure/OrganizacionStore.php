<?php

declare(strict_types=1);

namespace App\Organizacion\Infrastructure;

use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;

/**
 * What the organization screens read: sites, departments, their people and what each one holds.
 * Every query is filtered by the scope of the current user. All that SQL lives here.
 */
final readonly class OrganizacionStore
{
    public function __construct(private Connection $conexion, private Alcance $alcance)
    {
    }

    /** @return list<array<string, mixed>> SED_Sede, SED_Nombre, MUN_Nombre and PAI_Nombre of the sites the current user may see, by name */
    public function sedesVisibles(): array
    {
        [$filtro, $parametros] = $this->alcance->filtroSede('s."SED_Sede"');

        return $this->conexion->fetchAllAssociative(
            'SELECT s."SED_Sede", s."SED_Nombre", m."MUN_Nombre", pa."PAI_Nombre" FROM "INV_SEDE" s '
            .'LEFT JOIN "INV_MUNICIPIO" m ON m."MUN_Municipio" = s."MUN_Municipio" LEFT JOIN "INV_ESTADO" e ON e."EST_Estado" = m."EST_Estado" '
            .'LEFT JOIN "INV_PAIS" pa ON pa."PAI_Pais" = e."PAI_Pais" WHERE '.$filtro.' ORDER BY s."SED_Nombre"', $parametros);
    }

    /** @return list<array<string, mixed>> every department, by name */
    public function departamentos(): array
    {
        return $this->conexion->fetchAllAssociative('SELECT * FROM "INV_DEPARTAMENTO" ORDER BY "DEP_Nombre"');
    }

    /** @return array<string, mixed>|false */
    public function departamento(int $id): array|false
    {
        return $this->conexion->fetchAssociative('SELECT * FROM "INV_DEPARTAMENTO" WHERE "DEP_Departamento" = :d', ['d' => $id]);
    }

    public function existeDepartamentoLlamado(mixed $nombre): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_DEPARTAMENTO" WHERE "DEP_Nombre" = :n', ['n' => $nombre]);
    }

    /** Whether a person has that corporate e-mail (run it elevated to check the whole company). */
    public function existeCorreo(mixed $correo): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_PERSONA" WHERE "PER_Email_Corporativo" = :c', ['c' => $correo]);
    }

    /** @return array<mixed, mixed> department => visible active people */
    public function personasActivasPorDepartamento(): array
    {
        [$sedes, $parSedes] = $this->alcance->filtroSede('p."SED_Sede"');
        [$per, $parPer] = $this->alcance->lectura('INV_PERSONA', 'p');

        return $this->conexion->fetchAllKeyValue('SELECT p."DEP_Departamento", COUNT(*) FROM "INV_PERSONA" p WHERE p."PER_Estado" = 1 AND '.$sedes.' AND '.$per
            .' GROUP BY p."DEP_Departamento"', [...$parSedes, ...$parPer]);
    }

    /** @return array<mixed, mixed> department => visible assets its people hold now */
    public function activosAsignadosPorDepartamento(): array
    {
        [$mov, $parMov] = $this->alcance->lectura('INV_MOVIMIENTO', 'm');
        [$per, $parPer] = $this->alcance->lectura('INV_PERSONA', 'p');

        return $this->conexion->fetchAllKeyValue(
            'SELECT p."DEP_Departamento", COUNT(DISTINCT m."ACT_Activo") FROM "INV_PERSONA" p JOIN "INV_MOVIMIENTO" m ON m."PER_Persona" = p."PER_Persona" '
            .'WHERE m."MOV_Fecha_Devolucion" IS NULL AND '.$mov.' AND '.$per.' GROUP BY p."DEP_Departamento"', [...$parMov, ...$parPer]);
    }

    /** @return list<list<mixed>> [department, asset type name, visible assets its people hold now] */
    public function activosAsignadosPorDepartamentoYTipo(): array
    {
        [$mov, $parMov] = $this->alcance->lectura('INV_MOVIMIENTO', 'm');
        [$per, $parPer] = $this->alcance->lectura('INV_PERSONA', 'p');
        [$act, $parAct] = $this->alcance->lectura('INV_ACTIVO', 'a');

        return $this->conexion->fetchAllNumeric(
            'SELECT p."DEP_Departamento", t."TAC_Nombre", COUNT(DISTINCT m."ACT_Activo") FROM "INV_PERSONA" p '
            .'JOIN "INV_MOVIMIENTO" m ON m."PER_Persona" = p."PER_Persona" JOIN "INV_ACTIVO" a ON a."ACT_Activo" = m."ACT_Activo" '
            .'JOIN "INV_TIPO_ACTIVO" t ON t."TAC_Tipo_Activo" = a."TAC_Tipo_Activo" WHERE m."MOV_Fecha_Devolucion" IS NULL AND '.$mov.' AND '.$per.' AND '.$act
            .' GROUP BY p."DEP_Departamento", t."TAC_Nombre"', [...$parMov, ...$parPer, ...$parAct]);
    }

    /** @return list<array<string, mixed>> the visible active people of a department, by surname */
    public function personasActivasDe(int $departamento): array
    {
        [$sedes, $parSedes] = $this->alcance->filtroSede('p."SED_Sede"');
        [$per, $parPer] = $this->alcance->lectura('INV_PERSONA', 'p');

        return $this->conexion->fetchAllAssociative(
            'SELECT p.* FROM "INV_PERSONA" p WHERE p."DEP_Departamento" = :d AND p."PER_Estado" = 1 AND '.$sedes.' AND '.$per.' ORDER BY p."PER_Primer_Apellido"',
            ['d' => $departamento, ...$parSedes, ...$parPer]);
    }

    /** @return list<array<string, mixed>> what the visible active people of a department hold now, with asset, type, model, brand and area */
    public function custodiasDe(int $departamento): array
    {
        [$sedes, $parSedes] = $this->alcance->filtroSede('p."SED_Sede"');
        [$per, $parPer] = $this->alcance->lectura('INV_PERSONA', 'p');
        [$mov, $parMov] = $this->alcance->lectura('INV_MOVIMIENTO', 'm');

        return $this->conexion->fetchAllAssociative(
            'SELECT m."MOV_Movimiento", m."PER_Persona", m."MOV_Fecha_Asignacion", a."ACT_Activo", a."ACT_Codigo_Interno", a."ACT_Hostname", '
            .'a."ACT_Serie_Fabricante", t."TAC_Nombre", mo."MOD_Nombre", ma."MAR_Nombre", ar."ARE_Nombre" FROM "INV_MOVIMIENTO" m '
            .'LEFT JOIN "INV_ACTIVO" a ON a."ACT_Activo" = m."ACT_Activo" LEFT JOIN "INV_TIPO_ACTIVO" t ON t."TAC_Tipo_Activo" = a."TAC_Tipo_Activo" '
            .'LEFT JOIN "INV_MODELO" mo ON mo."MOD_Modelo" = a."MOD_Modelo" LEFT JOIN "INV_MARCA" ma ON ma."MAR_Marca" = mo."MAR_Marca" '
            .'LEFT JOIN "INV_AREA" ar ON ar."ARE_Area" = m."ARE_Area" WHERE m."MOV_Fecha_Devolucion" IS NULL AND '.$mov
            .' AND m."PER_Persona" IN (SELECT p."PER_Persona" FROM "INV_PERSONA" p WHERE p."DEP_Departamento" = :d AND p."PER_Estado" = 1 AND '.$sedes.' AND '.$per.')',
            ['d' => $departamento, ...$parSedes, ...$parPer, ...$parMov]);
    }
}
