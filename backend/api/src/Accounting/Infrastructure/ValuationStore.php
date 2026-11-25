<?php

declare(strict_types=1);

namespace App\Accounting\Infrastructure;

use Doctrine\DBAL\Connection;

/**
 * The rows a valuation needs (cost, dates, type and accounting category of each asset), always in one
 * query per screen, never one per asset or per component. The site scope (VPD) filters them as usual.
 */
final readonly class ValuationStore
{
    private const string SELECT = <<<'SQL'
        SELECT a."ACT_Activo" "ASSET_ID", a."ACT_Codigo_Interno" "CODE", a."ACT_Serie_Fabricante" "SERIAL", a."ACT_Activo_Padre" "PARENT_ID",
               a."ACT_Costo" "COST", a."ACT_Moneda" "CURRENCY", a."ACT_Factura" "INVOICE",
               TO_CHAR(a."ACT_Fecha_Compra", 'YYYY-MM-DD') "ACQUIRED_ON", TO_CHAR(a."ACT_Baja_Fecha", 'YYYY-MM-DD') "DISPOSED_ON",
               a."SED_Sede" "SITE_ID", t."TAC_Tipo_Activo" "TYPE_ID", t."TAC_Nombre" "TYPE_NAME", t."TAC_Aplica_Depreciacion" "TYPE_DEPRECIATES",
               TRIM(mar."MAR_Nombre" || ' ' || m."MOD_Nombre") "PRODUCT",
               c."FAC_ASSET_CATEGORY" "CATEGORY_ID", c."FAC_CODE" "CATEGORY_CODE", c."FAC_NAME" "CATEGORY_NAME", c."FAC_METHOD" "METHOD",
               c."FAC_ANNUAL_RATE" "ANNUAL_RATE", c."FAC_RESIDUAL_PERCENT" "RESIDUAL_PERCENT", c."FAC_STARTS_NEXT_MONTH" "STARTS_NEXT_MONTH",
               c."FAC_ACCOUNT_CODE" "ACCOUNT_CODE",
               -- Who has it in custody (open assignment); an installed component belongs to whoever has its equipment.
               NVL(cu."PER", cp."PER") "HOLDER_ID", s."SED_Nombre" "SITE_NAME"
          FROM "INV_ACTIVO" a
          JOIN "INV_TIPO_ACTIVO" t ON t."TAC_Tipo_Activo" = a."TAC_Tipo_Activo"
          LEFT JOIN "INV_MODELO" m ON m."MOD_Modelo" = a."MOD_Modelo"
          LEFT JOIN "INV_MARCA" mar ON mar."MAR_Marca" = m."MAR_Marca"
          LEFT JOIN "FIN_ASSET_CATEGORY" c ON c."FAC_ASSET_CATEGORY" = t."FAC_ASSET_CATEGORY"
          LEFT JOIN "INV_SEDE" s ON s."SED_Sede" = a."SED_Sede"
          LEFT JOIN (SELECT "ACT_Activo", MAX("PER_Persona") "PER" FROM "INV_MOVIMIENTO" WHERE "MOV_Fecha_Devolucion" IS NULL GROUP BY "ACT_Activo") cu
            ON cu."ACT_Activo" = a."ACT_Activo"
          LEFT JOIN (SELECT "ACT_Activo", MAX("PER_Persona") "PER" FROM "INV_MOVIMIENTO" WHERE "MOV_Fecha_Devolucion" IS NULL GROUP BY "ACT_Activo") cp
            ON cp."ACT_Activo" = a."ACT_Activo_Padre"
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    /** @return list<array<string, mixed>> the asset and every component installed in it (at any depth), the asset first */
    public function assetWithComponents(string $asset): array
    {
        return $this->tree('START WITH "ACT_Activo" = :root', ['root' => $asset]);
    }

    /**
     * What a person has in custody, with the components installed in each of those assets.
     *
     * @return list<array<string, mixed>>
     */
    public function workstation(string $person): array
    {
        // Custody is the open assignment (INV_MOVIMIENTO without a return date), the same the person's file shows.
        return $this->tree('START WITH "ACT_Activo" IN (SELECT "ACT_Activo" FROM "INV_MOVIMIENTO" WHERE "PER_Persona" = :person AND "MOV_Fecha_Devolucion" IS NULL)',
            ['person' => $person]);
    }

    /**
     * Every asset on the books on a date: bought by then and not disposed of before it.
     *
     * @return list<array<string, mixed>>
     */
    public function onTheBooks(string $asOf, ?int $site): array
    {
        return $this->connection->fetchAllAssociative(self::SELECT.<<<'SQL'
             WHERE a."ACT_Fecha_Compra" <= TO_DATE(:d, 'YYYY-MM-DD')
               AND (a."ACT_Baja_Fecha" IS NULL OR a."ACT_Baja_Fecha" > TO_DATE(:d, 'YYYY-MM-DD'))
            SQL.(null === $site ? '' : ' AND a."SED_Sede" = :site').' ORDER BY t."TAC_Nombre", a."ACT_Codigo_Interno"',
            ['d' => $asOf, ...(null === $site ? [] : ['site' => $site])]);
    }

    /**
     * Names of the people who have assets in custody and of their departments, read in one query.
     *
     * @param list<string> $people
     *
     * @return array<string, array{name: string, department_id: int|null, department: string|null}> by person id (hex)
     */
    public function people(array $people): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($people)), 900) as $chunk) {
            $rows = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT p."PER_Persona" "ID",
                       TRIM(p."PER_Primer_Nombre" || ' ' || p."PER_Segundo_Nombre" || ' ' || p."PER_Primer_Apellido" || ' ' || p."PER_Segundo_Apellido") "NAME",
                       d."DEP_Departamento" "DEPARTMENT_ID", d."DEP_Nombre" "DEPARTMENT"
                  FROM "INV_PERSONA" p
                  LEFT JOIN "INV_DEPARTAMENTO" d ON d."DEP_Departamento" = p."DEP_Departamento"
                 WHERE p."PER_Persona" IN (?)
                SQL, [$chunk], [\Doctrine\DBAL\ArrayParameterType::STRING]);
            foreach ($rows as $r) {
                $out[(string) $r['ID']] = ['name' => (string) preg_replace('/\s+/', ' ', (string) $r['NAME']),
                    'department_id' => null === $r['DEPARTMENT_ID'] ? null : (int) $r['DEPARTMENT_ID'], 'department' => $r['DEPARTMENT']];
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null the person, with the department and position */
    public function person(string $person): ?array
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT p."PER_Persona" "PERSON_ID",
                   TRIM(p."PER_Primer_Nombre" || ' ' || p."PER_Segundo_Nombre" || ' ' || p."PER_Primer_Apellido" || ' ' || p."PER_Segundo_Apellido") "NAME",
                   p."PER_Email_Corporativo" "EMAIL", d."DEP_Nombre" "DEPARTMENT", c."CAR_Nombre" "POSITION"
              FROM "INV_PERSONA" p
              LEFT JOIN "INV_DEPARTAMENTO" d ON d."DEP_Departamento" = p."DEP_Departamento"
              LEFT JOIN "INV_CARGO" c ON c."CAR_Cargo" = p."CAR_Cargo"
             WHERE p."PER_Persona" = :p
            SQL, ['p' => $person]);

        return false === $row ? null : $row;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    private function tree(string $start, array $params): array
    {
        return $this->connection->fetchAllAssociative(<<<SQL
            WITH tree AS (
                -- ROWNUM over the hierarchy keeps its order: each parent right before its components (preorder).
                SELECT "ID", ROWNUM "SEQ" FROM (
                    SELECT "ACT_Activo" "ID" FROM "INV_ACTIVO"
                    {$start}
                    CONNECT BY NOCYCLE PRIOR "ACT_Activo" = "ACT_Activo_Padre"
                    ORDER SIBLINGS BY "ACT_Codigo_Interno"
                )
            )
            SQL.self::SELECT.' JOIN tree ON tree."ID" = a."ACT_Activo" ORDER BY tree."SEQ"', $params);
    }
}
