<?php

declare(strict_types=1);

namespace App\Inventario\Infrastructure;

use App\Dominio\Reloj;
use App\Inventario\Activos;
use App\Seguridad\Alcance;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** Assets as stored, with what they read about their custody, components and catalogs: all that SQL lives here. */
final readonly class ActivosStore
{
    /** Columns written as YYYY-MM-DD dates. */
    private const array FECHAS = ['ACT_Fecha_Compra', 'ACT_Fin_Garantia', 'ACT_Baja_Fecha'];

    public function __construct(private Connection $conexion, private Alcance $alcance)
    {
    }

    // ---- Reading ----

    /** @return array<string, mixed>|false the asset, if the current user may see it */
    public function visible(string $id): array|false
    {
        [$alcance, $p] = $this->alcance->lectura('INV_ACTIVO', 'a');

        return $this->conexion->fetchAssociative('SELECT '.Activos::select().' FROM "INV_ACTIVO" a WHERE a."ACT_Activo" = :id AND '.$alcance, [...$p, 'id' => $id]);
    }

    /** @return array<string, mixed>|false the asset with that internal code, if the current user may see it */
    public function visiblePorCodigo(string $codigo): array|false
    {
        [$alcance, $p] = $this->alcance->lectura('INV_ACTIVO', 'a');

        return $this->conexion->fetchAssociative('SELECT '.Activos::select().' FROM "INV_ACTIVO" a WHERE a."ACT_Codigo_Interno" = :c AND '.$alcance, [...$p, 'c' => $codigo]);
    }

    /** @return array<string, mixed>|false the asset, whatever its site */
    public function porId(string $id): array|false
    {
        return $this->conexion->fetchAssociative('SELECT '.Activos::select().' FROM "INV_ACTIVO" a WHERE a."ACT_Activo" = :id', ['id' => $id]);
    }

    /** Locks the asset row until the transaction ends. */
    public function bloquear(string $id): void
    {
        $this->conexion->fetchOne('SELECT 1 FROM "INV_ACTIVO" WHERE "ACT_Activo" = :id FOR UPDATE', ['id' => $id]);
    }

    /** @return list<array<string, mixed>> a page of the visible assets, by internal code */
    public function pagina(int $saltar, int $limite): array
    {
        [$alcance, $p] = $this->alcance->lectura('INV_ACTIVO', 'a');

        return $this->conexion->fetchAllAssociative('SELECT '.Activos::select().' FROM "INV_ACTIVO" a WHERE '.$alcance
            .' ORDER BY a."ACT_Codigo_Interno" OFFSET :saltar ROWS FETCH NEXT :limite ROWS ONLY', [...$p, 'saltar' => $saltar, 'limite' => $limite]);
    }

    /**
     * A page of the visible assets that match the filters, and how many match in all.
     *
     * @param array<string, mixed> $f q, modelo_id, tipo_activo_id, estado_operativo_id, sede_id, fecha_compra_start, fecha_compra_end, sort, sort_dir, page, per_page
     *
     * @return array{0: int, 1: list<array<string, mixed>>}
     */
    public function buscar(array $f): array
    {
        [$sql, $p] = $this->alcance->lectura('INV_ACTIVO', 'a');
        if (null !== $f['q'] && '' !== $f['q']) {
            // The wildcards the user types are literal (explicit ESCAPE: Oracle has no default one).
            $p['q'] = '%'.addcslashes(mb_strtolower($f['q']), '\\%_').'%';
            $sql .= " AND (LOWER(a.\"ACT_Codigo_Interno\") LIKE :q ESCAPE '\\' OR LOWER(a.\"ACT_Hostname\") LIKE :q ESCAPE '\\' "
                ."OR LOWER(a.\"ACT_Serie_Fabricante\") LIKE :q ESCAPE '\\')";
        }
        foreach (['modelo_id' => 'MOD_Modelo', 'tipo_activo_id' => 'TAC_Tipo_Activo', 'estado_operativo_id' => 'EOP_Estado_Operativo',
            'sede_id' => 'SED_Sede'] as $filtro => $columna) {
            if (null !== $f[$filtro] && 0 !== $f[$filtro]) {
                $sql .= " AND a.\"{$columna}\" = :{$filtro}";
                $p[$filtro] = $f[$filtro];
            }
        }
        if (null !== $f['fecha_compra_start']) {
            $sql .= " AND a.\"ACT_Fecha_Compra\" >= TO_DATE(:desde, 'YYYY-MM-DD')";
            $p['desde'] = $f['fecha_compra_start'];
        }
        if (null !== $f['fecha_compra_end']) {
            $sql .= " AND a.\"ACT_Fecha_Compra\" <= TO_DATE(:hasta, 'YYYY-MM-DD')";
            $p['hasta'] = $f['fecha_compra_end'];
        }
        $total = (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "INV_ACTIVO" a WHERE '.$sql, $p);
        $filas = $this->conexion->fetchAllAssociative('SELECT '.Activos::select().' FROM "INV_ACTIVO" a WHERE '.$sql
            .' ORDER BY '.Activos::ordenBusqueda($f['sort'] ?? null, $f['sort_dir'] ?? null).' OFFSET :saltar ROWS FETCH NEXT :limite ROWS ONLY',
            [...$p, 'saltar' => ($f['page'] - 1) * $f['per_page'], 'limite' => $f['per_page']]);

        return [$total, $filas];
    }

    /** @return array<string, mixed>|false names of the model, brand, type and operational state of an asset */
    public function relaciones(string $id): array|false
    {
        return $this->conexion->fetchAssociative(
            'SELECT m."MOD_Nombre", ma."MAR_Marca", ma."MAR_Nombre", t."TAC_Nombre", e."EOP_Nombre" FROM "INV_ACTIVO" a '
            .'LEFT JOIN "INV_MODELO" m ON m."MOD_Modelo" = a."MOD_Modelo" LEFT JOIN "INV_MARCA" ma ON ma."MAR_Marca" = m."MAR_Marca" '
            .'LEFT JOIN "INV_TIPO_ACTIVO" t ON t."TAC_Tipo_Activo" = a."TAC_Tipo_Activo" '
            .'LEFT JOIN "INV_ESTADO_OPERATIVO" e ON e."EOP_Estado_Operativo" = a."EOP_Estado_Operativo" WHERE a."ACT_Activo" = :id', ['id' => $id]);
    }

    /** @return list<array<string, mixed>> the assets installed in that one, by internal code */
    public function componentesDe(string $equipo): array
    {
        return $this->conexion->fetchAllAssociative('SELECT '.Activos::select().' FROM "INV_ACTIVO" a WHERE a."ACT_Activo_Padre" = :e ORDER BY a."ACT_Codigo_Interno"', ['e' => $equipo]);
    }

    /** @return mixed the asset this one is installed in (null if none); false when the asset does not exist */
    public function padreDe(mixed $activo): mixed
    {
        return $this->conexion->fetchOne('SELECT "ACT_Activo_Padre" FROM "INV_ACTIVO" WHERE "ACT_Activo" = :a', ['a' => $activo]);
    }

    // ---- Uniqueness and catalogs ----

    /**
     * Whether an asset other than `$excepto` holds that exact value in that column (run it elevated to check the whole company).
     *
     * @param 'ACT_Codigo_Interno'|'ACT_Serie_Fabricante' $columna
     */
    public function existeValor(string $columna, string $valor, ?string $excepto = null): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_ACTIVO" WHERE "'.$columna.'" = :v'
            .(null === $excepto ? '' : ' AND "ACT_Activo" <> :e').' FETCH FIRST 1 ROWS ONLY',
            null === $excepto ? ['v' => $valor] : ['v' => $valor, 'e' => $excepto]);
    }

    /** Whether another asset still in service has that hostname, whatever its case (run it elevated to check the whole company). */
    public function hostnameEnUso(string $hostname, ?string $excepto): bool
    {
        return false !== $this->conexion->fetchOne(
            'SELECT 1 FROM "INV_ACTIVO" WHERE LOWER(TRIM("ACT_Hostname")) = LOWER(TRIM(:h)) AND "ACT_Baja_Fecha" IS NULL'.(null === $excepto ? '' : ' AND "ACT_Activo" <> :a').' FETCH FIRST 1 ROWS ONLY',
            ['h' => $hostname, ...(null === $excepto ? [] : ['a' => $excepto])]);
    }

    /** @return array<string, mixed>|false TAC_Prefijo of the asset type; false when the type does not exist */
    public function tipo(mixed $tipo): array|false
    {
        return $this->conexion->fetchAssociative('SELECT "TAC_Prefijo" FROM "INV_TIPO_ACTIVO" WHERE "TAC_Tipo_Activo" = :t', ['t' => $tipo]);
    }

    public function existeModelo(mixed $modelo): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_MODELO" WHERE "MOD_Modelo" = :m', ['m' => $modelo]);
    }

    // ---- Writing ----

    /**
     * @param array<string, mixed> $columnas column => value, without the dates
     * @param ?string              $compra   YYYY-MM-DD
     * @param ?string              $garantia YYYY-MM-DD
     */
    public function insertar(array $columnas, ?string $compra, ?string $garantia): void
    {
        $nombres = array_keys($columnas);
        $this->conexion->executeStatement('INSERT INTO "INV_ACTIVO" ("'.implode('", "', $nombres).'", "ACT_Fecha_Compra", "ACT_Fin_Garantia") VALUES (:'
            .implode(', :', $nombres).", TO_DATE(:compra, 'YYYY-MM-DD'), TO_DATE(:garantia, 'YYYY-MM-DD'))",
            [...$columnas, 'compra' => $compra, 'garantia' => $garantia]);
    }

    /** @param array<string, mixed> $cambios column => value; the column names come from the validated form or from the code */
    public function actualizar(string $id, array $cambios): void
    {
        if ([] === $cambios) {
            return;
        }
        $sets = [];
        $p = ['id' => $id];
        foreach ($cambios as $c => $v) {
            $sets[] = \in_array($c, self::FECHAS, true) ? "\"{$c}\" = TO_DATE(:{$c}, 'YYYY-MM-DD')" : "\"{$c}\" = :{$c}";
            $p[$c] = \is_bool($v) ? (int) $v : $v;
        }
        $this->conexion->executeStatement('UPDATE "INV_ACTIVO" SET '.implode(', ', $sets).' WHERE "ACT_Activo" = :id', $p);
    }

    // ---- Custody ----

    /** @return array<string, mixed>|false the open movement of an asset: MOV_Movimiento, PER_Persona, ARE_Area, TMO_Tipo_Movimiento */
    public function movimientoVigente(string $activo): array|false
    {
        return $this->conexion->fetchAssociative('SELECT "MOV_Movimiento", "PER_Persona", "ARE_Area", "TMO_Tipo_Movimiento" FROM "INV_MOVIMIENTO" '
            .'WHERE "ACT_Activo" = :a AND "MOV_Fecha_Devolucion" IS NULL ORDER BY "MOV_Fecha_Asignacion" DESC FETCH FIRST 1 ROWS ONLY', ['a' => $activo]);
    }

    /**
     * @param list<string> $activos at most 1000
     *
     * @return list<array<string, mixed>> ACT_Activo, PER_Primer_Nombre and PER_Primer_Apellido of who holds each asset now
     */
    public function custodiosDe(array $activos): array
    {
        return $this->conexion->fetchAllAssociative('SELECT m."ACT_Activo", per."PER_Primer_Nombre", per."PER_Primer_Apellido" FROM "INV_MOVIMIENTO" m '
            .'JOIN "INV_PERSONA" per ON per."PER_Persona" = m."PER_Persona" WHERE m."MOV_Fecha_Devolucion" IS NULL AND m."ACT_Activo" IN (:a)',
            ['a' => $activos], ['a' => ArrayParameterType::STRING]);
    }

    /** @return mixed the person who holds the asset, with the open movement locked; false when nobody does */
    public function custodioBloqueado(string $activo): mixed
    {
        return $this->conexion->fetchOne('SELECT "PER_Persona" FROM "INV_MOVIMIENTO" WHERE "ACT_Activo" = :a AND "MOV_Fecha_Devolucion" IS NULL FOR UPDATE', ['a' => $activo]);
    }

    /** Closes the open custody of an asset without a return (it was lost or stolen). */
    public function cerrarCustodia(string $activo, \DateTimeImmutable $ahora): void
    {
        $this->conexion->executeStatement('UPDATE "INV_MOVIMIENTO" SET "MOV_Fecha_Devolucion" = :f WHERE "ACT_Activo" = :a AND "MOV_Fecha_Devolucion" IS NULL',
            ['f' => Reloj::aBd($ahora), 'a' => $activo]);
    }

    // ---- Specifications ----

    /**
     * @param list<string> $activos at most 1000
     *
     * @return list<array<string, mixed>> ESP_Especificacion, TES_Tipo_Especificacion, ESP_Valor and ACT_Activo, oldest first
     */
    public function especificacionesDe(array $activos): array
    {
        return $this->conexion->fetchAllAssociative('SELECT "ESP_Especificacion", "TES_Tipo_Especificacion", "ESP_Valor", "ACT_Activo" '
            .'FROM "INV_ESPECIFICACION" WHERE "ACT_Activo" IN (:a) ORDER BY "ESP_Especificacion"', ['a' => $activos], ['a' => ArrayParameterType::STRING]);
    }

    /** @return list<array<string, mixed>> the specifications of an asset with the name and unit of their type, by type name */
    public function especificacionesConTipo(string $activo): array
    {
        return $this->conexion->fetchAllAssociative('SELECT e."ESP_Especificacion", e."TES_Tipo_Especificacion", t."TES_Nombre", t."TES_Unidad_Medida", e."ESP_Valor" '
            .'FROM "INV_ESPECIFICACION" e JOIN "INV_TIPO_ESPECIFICACION" t ON t."TES_Tipo_Especificacion" = e."TES_Tipo_Especificacion" '
            .'WHERE e."ACT_Activo" = :a ORDER BY t."TES_Nombre", e."ESP_Especificacion"', ['a' => $activo]);
    }

    /** @return array<string, mixed>|false ESP_Especificacion, ESP_Valor, ACT_Activo and TES_Tipo_Especificacion */
    public function especificacion(int $id): array|false
    {
        return $this->conexion->fetchAssociative('SELECT "ESP_Especificacion", "ESP_Valor", "ACT_Activo", "TES_Tipo_Especificacion" FROM "INV_ESPECIFICACION" '
            .'WHERE "ESP_Especificacion" = :id', ['id' => $id]);
    }
}
