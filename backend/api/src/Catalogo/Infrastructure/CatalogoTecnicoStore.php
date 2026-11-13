<?php

declare(strict_types=1);

namespace App\Catalogo\Infrastructure;

use App\Db\InsercionOracle;
use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;

/**
 * The technical catalog as stored: what each asset type asks for (specifications, components), the models
 * with their brand, and the standard kits of a position or department. All that SQL lives here.
 */
final readonly class CatalogoTecnicoStore
{
    public function __construct(private Connection $conexion, private Alcance $alcance)
    {
    }

    // ---- Asset types, brands and models ----

    public function existeTipoDeActivo(int $tipo): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_TIPO_ACTIVO" WHERE "TAC_Tipo_Activo" = :t', ['t' => $tipo]);
    }

    public function existeTipoDeActivoLlamado(mixed $nombre): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_TIPO_ACTIVO" WHERE "TAC_Nombre" = :n', ['n' => $nombre]);
    }

    public function existeMarcaLlamada(mixed $nombre): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_MARCA" WHERE "MAR_Nombre" = :n', ['n' => $nombre]);
    }

    /**
     * Models with their brand, by brand and model name; `$texto` is searched literally in both names.
     *
     * @param ?int $tipo only the models of that asset type or of none
     *
     * @return list<array<string, mixed>>
     */
    public function modelosConMarca(?int $tipo, ?string $texto, int $limite): array
    {
        $donde = ['1 = 1'];
        $parametros = [];
        if (null !== $tipo) {
            $donde[] = '(m."TAC_Tipo_Activo" = :t OR m."TAC_Tipo_Activo" IS NULL)';
            $parametros['t'] = $tipo;
        }
        if (null !== $texto && '' !== $texto) {
            // The wildcards the user types are escaped: a literal search.
            $parametros['q'] = '%'.addcslashes(mb_strtolower($texto), '\\%_').'%';
            $donde[] = "(LOWER(m.\"MOD_Nombre\") LIKE :q ESCAPE '\\' OR LOWER(ma.\"MAR_Nombre\") LIKE :q ESCAPE '\\')";
        }

        return $this->conexion->fetchAllAssociative(
            'SELECT m."MOD_Modelo", m."MOD_Nombre", m."MAR_Marca", ma."MAR_Nombre", m."TCN_Tipo_Conexion", m."TAC_Tipo_Activo", '
            .'m."MOD_Anio_Lanzamiento" FROM "INV_MODELO" m JOIN "INV_MARCA" ma ON ma."MAR_Marca" = m."MAR_Marca" WHERE '
            .implode(' AND ', $donde).' ORDER BY ma."MAR_Nombre", m."MOD_Nombre" FETCH FIRST '.$limite.' ROWS ONLY',
            $parametros,
        );
    }

    /** The asset type that model is registered with most often, among the assets the current user may see. */
    public function tipoMasUsadoDeModelo(mixed $modelo): ?int
    {
        [$filtro, $parametros] = $this->alcance->lectura('INV_ACTIVO', 'a');
        $tipo = $this->conexion->fetchOne(
            'SELECT a."TAC_Tipo_Activo" FROM "INV_ACTIVO" a WHERE a."MOD_Modelo" = :m AND '.$filtro
            .' GROUP BY a."TAC_Tipo_Activo" ORDER BY COUNT(*) DESC FETCH FIRST 1 ROWS ONLY',
            ['m' => $modelo, ...$parametros],
        );

        return false === $tipo || null === $tipo ? null : (int) $tipo;
    }

    // ---- What an asset type asks for ----

    /** @return list<array<string, mixed>> the specifications asked for by one asset type (or by all), in their order, with the data of each specification type */
    public function especificacionesPorTipo(?int $tipo): array
    {
        return $this->conexion->fetchAllAssociative(
            'SELECT e."TAC_Tipo_Activo", e."TES_Tipo_Especificacion", t."TES_Nombre", t."TES_Unidad_Medida", t."TES_Data_Type", t."TES_Min_Value", t."TES_Max_Value", e."ETA_Obligatoria", '
            .'e."ETA_Orden" FROM "INV_ESPECIFICACION_TIPO_ACTIVO" e '
            .'LEFT JOIN "INV_TIPO_ESPECIFICACION" t ON t."TES_Tipo_Especificacion" = e."TES_Tipo_Especificacion" '
            .(null === $tipo ? '' : 'WHERE e."TAC_Tipo_Activo" = :t ').'ORDER BY e."ETA_Orden", e."ETA_Especificacion_Tipo"',
            null === $tipo ? [] : ['t' => $tipo],
        );
    }

    public function quitarEspecificacionesDeTipo(int $tipo): void
    {
        $this->conexion->executeStatement('DELETE FROM "INV_ESPECIFICACION_TIPO_ACTIVO" WHERE "TAC_Tipo_Activo" = :t', ['t' => $tipo]);
    }

    public function agregarEspecificacionATipo(int $tipo, int $especificacion, bool $obligatoria, int $orden): void
    {
        $this->conexion->executeStatement(
            'INSERT INTO "INV_ESPECIFICACION_TIPO_ACTIVO" ("TAC_Tipo_Activo", "TES_Tipo_Especificacion", "ETA_Obligatoria", '
            .'"ETA_Orden") VALUES (:t, :e, :o, :r)',
            ['t' => $tipo, 'e' => $especificacion, 'o' => $obligatoria ? 1 : 0, 'r' => $orden],
        );
    }

    /** @return list<int> the asset types that may be installed as components of that one, ascending */
    public function componentesDe(int $tipo): array
    {
        return array_map('intval', $this->conexion->fetchFirstColumn(
            'SELECT "TAC_Componente" FROM "INV_TIPO_COMPONENTE" WHERE "TAC_Padre" = :p ORDER BY "TAC_Componente"',
            ['p' => $tipo],
        ));
    }

    /** @param list<int> $componentes */
    public function reemplazarComponentes(int $tipo, array $componentes): void
    {
        $this->conexion->executeStatement('DELETE FROM "INV_TIPO_COMPONENTE" WHERE "TAC_Padre" = :p', ['p' => $tipo]);
        foreach ($componentes as $componente) {
            $this->conexion->executeStatement('INSERT INTO "INV_TIPO_COMPONENTE" ("TAC_Padre", "TAC_Componente") VALUES (:p, :c)', ['p' => $tipo, 'c' => $componente]);
        }
    }

    // ---- Standard kits ----

    /** @return list<int> every kit, by name */
    public function idsDeKits(): array
    {
        return array_map('intval', $this->conexion->fetchFirstColumn('SELECT "KIT_Kit" FROM "INV_KIT_ESTANDAR" ORDER BY "KIT_Nombre", "KIT_Kit"'));
    }

    /** @return array<string, mixed>|false */
    public function kit(int $id): array|false
    {
        return $this->conexion->fetchAssociative('SELECT * FROM "INV_KIT_ESTANDAR" WHERE "KIT_Kit" = :id', ['id' => $id]);
    }

    /** @return list<array<string, mixed>> the lines of a kit, in the order they were given */
    public function itemsDeKit(int $id): array
    {
        return $this->conexion->fetchAllAssociative('SELECT * FROM "INV_KIT_ESTANDAR_ITEM" WHERE "KIT_Kit" = :id ORDER BY "KIT_Item"', ['id' => $id]);
    }

    public function crearKit(mixed $nombre, mixed $cargo, mixed $departamento, bool $activo): int
    {
        return (int) InsercionOracle::insertar($this->conexion, 'INV_KIT_ESTANDAR',
            ['KIT_Nombre' => $nombre, 'CAR_Cargo' => $cargo, 'DEP_Departamento' => $departamento, 'KIT_Activo' => $activo ? 1 : 0], 'KIT_Kit');
    }

    public function actualizarKit(int $id, mixed $nombre, mixed $cargo, mixed $departamento, bool $activo): void
    {
        $this->conexion->executeStatement(
            'UPDATE "INV_KIT_ESTANDAR" SET "KIT_Nombre" = :n, "CAR_Cargo" = :c, "DEP_Departamento" = :d, "KIT_Activo" = :a WHERE "KIT_Kit" = :id',
            ['n' => $nombre, 'c' => $cargo, 'd' => $departamento, 'a' => $activo ? 1 : 0, 'id' => $id],
        );
    }

    /**
     * Replaces the lines of a kit (they are links, not records: the audit log keeps what the kit held).
     *
     * @param list<array<string, mixed>> $items TAC_Tipo_Activo, KIT_Cantidad and LIC_Licencia of each line
     */
    public function reemplazarItemsDeKit(int $kit, array $items): void
    {
        $this->conexion->executeStatement('DELETE FROM "INV_KIT_ESTANDAR_ITEM" WHERE "KIT_Kit" = :id', ['id' => $kit]);
        foreach ($items as $item) {
            $this->conexion->executeStatement(
                'INSERT INTO "INV_KIT_ESTANDAR_ITEM" ("KIT_Kit", "TAC_Tipo_Activo", "KIT_Cantidad", "LIC_Licencia") VALUES (:k, :t, :c, :l)',
                ['k' => $kit, 't' => $item['TAC_Tipo_Activo'], 'c' => $item['KIT_Cantidad'], 'l' => $item['LIC_Licencia']],
            );
        }
    }

    public function existeCargo(mixed $cargo): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_CARGO" WHERE "CAR_Cargo" = :c', ['c' => $cargo]);
    }

    public function existeDepartamento(mixed $departamento): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_DEPARTAMENTO" WHERE "DEP_Departamento" = :d', ['d' => $departamento]);
    }

    /** Whether an active kit other than `$excepto` already serves that position or, without position, that department. */
    public function hayKitActivoPara(mixed $cargo, mixed $departamento, ?int $excepto): bool
    {
        $columna = null !== $cargo ? 'CAR_Cargo' : 'DEP_Departamento';

        return false !== $this->conexion->fetchOne(
            "SELECT 1 FROM \"INV_KIT_ESTANDAR\" WHERE \"KIT_Activo\" = 1 AND \"{$columna}\" = :v".(null !== $excepto ? ' AND "KIT_Kit" <> :e' : ''),
            ['v' => $cargo ?? $departamento, ...(null !== $excepto ? ['e' => $excepto] : [])],
        );
    }
}
