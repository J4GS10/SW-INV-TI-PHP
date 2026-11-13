<?php

declare(strict_types=1);

namespace App\Importacion\Infrastructure;

use App\Db\InsercionOracle;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** What the asset import reads from the catalogs and writes besides the assets themselves: all that SQL lives here. */
final readonly class ImportacionStore
{
    public function __construct(private Connection $conexion)
    {
    }

    /** Whether any site has closed a month (run it elevated: it must see every site). */
    public function hayCierreMensual(): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM TRC_MONTH_END_CLOSE FETCH FIRST 1 ROWS ONLY');
    }

    public function idEstadoOperativo(string $nombre): ?int
    {
        $id = $this->conexion->fetchOne('SELECT "EOP_Estado_Operativo" FROM "INV_ESTADO_OPERATIVO" WHERE "EOP_Nombre" = :n', ['n' => $nombre]);

        return false === $id || null === $id ? null : (int) $id;
    }

    public function crearMarca(string $nombre): int
    {
        return (int) InsercionOracle::insertar($this->conexion, 'INV_MARCA', ['MAR_Nombre' => $nombre], 'MAR_Marca');
    }

    public function crearModelo(string $nombre, int $marca, int $tipo): int
    {
        return (int) InsercionOracle::insertar($this->conexion, 'INV_MODELO', ['MOD_Nombre' => $nombre, 'MAR_Marca' => $marca, 'TAC_Tipo_Activo' => $tipo], 'MOD_Modelo');
    }

    /** @param string $activo hex id */
    public function ubicarActivo(string $activo, int $area): void
    {
        $this->conexion->executeStatement('UPDATE "INV_ACTIVO" SET "ARE_Area_Ubicacion" = :a WHERE "ACT_Activo" = :id', ['a' => $area, 'id' => $activo]);
    }

    /** @return list<mixed> */
    public function nombresDeTipos(): array
    {
        return $this->conexion->fetchFirstColumn('SELECT "TAC_Nombre" FROM "INV_TIPO_ACTIVO" ORDER BY "TAC_Nombre"');
    }

    /** @return list<mixed> */
    public function nombresDeAreas(): array
    {
        return $this->conexion->fetchFirstColumn('SELECT "ARE_Nombre" FROM "INV_AREA" ORDER BY "ARE_Nombre"');
    }

    /** @return list<mixed> */
    public function nombresDeMarcas(): array
    {
        return $this->conexion->fetchFirstColumn('SELECT "MAR_Nombre" FROM "INV_MARCA" ORDER BY "MAR_Nombre"');
    }

    /** @return list<string> every internal code visible to the current scope (run it elevated for the whole company) */
    public function codigosInternos(): array
    {
        return array_map('strval', $this->conexion->fetchFirstColumn('SELECT "ACT_Codigo_Interno" FROM "INV_ACTIVO"'));
    }

    /** @return list<array<string, mixed>> TAC_Tipo_Activo, TAC_Nombre, TAC_Prefijo, by id */
    public function tipos(): array
    {
        return $this->conexion->fetchAllAssociative('SELECT "TAC_Tipo_Activo", "TAC_Nombre", "TAC_Prefijo" FROM "INV_TIPO_ACTIVO" ORDER BY "TAC_Tipo_Activo"');
    }

    /** @return list<list<mixed>> [id, name], by id */
    public function marcas(): array
    {
        return $this->conexion->fetchAllNumeric('SELECT "MAR_Marca", "MAR_Nombre" FROM "INV_MARCA" ORDER BY "MAR_Marca"');
    }

    /** @return list<list<mixed>> [id, brand, name], by id */
    public function modelos(): array
    {
        return $this->conexion->fetchAllNumeric('SELECT "MOD_Modelo", "MAR_Marca", "MOD_Nombre" FROM "INV_MODELO" ORDER BY "MOD_Modelo"');
    }

    /** @return list<list<mixed>> [id, name], by id */
    public function areas(): array
    {
        return $this->conexion->fetchAllNumeric('SELECT "ARE_Area", "ARE_Nombre" FROM "INV_AREA" ORDER BY "ARE_Area"');
    }

    /**
     * @param list<string> $series lowercase, at most 1000
     *
     * @return list<string> those already used by an asset, lowercase
     */
    public function seriesExistentes(array $series): array
    {
        if ([] === $series) {
            return [];
        }

        return array_map('strval', $this->conexion->fetchFirstColumn('SELECT LOWER("ACT_Serie_Fabricante") FROM "INV_ACTIVO" WHERE LOWER("ACT_Serie_Fabricante") IN (:s)',
            ['s' => $series], ['s' => ArrayParameterType::STRING]));
    }

    /** The oldest specification type with that name, whatever its case. */
    public function idTipoEspecificacion(string $nombre): ?int
    {
        $id = $this->conexion->fetchOne('SELECT "TES_Tipo_Especificacion" FROM "INV_TIPO_ESPECIFICACION" WHERE LOWER("TES_Nombre") = :n '
            .'ORDER BY "TES_Tipo_Especificacion" FETCH FIRST 1 ROWS ONLY', ['n' => mb_strtolower($nombre)]);

        return false === $id ? null : (int) $id;
    }

    public function crearTipoEspecificacion(string $nombre): int
    {
        return (int) InsercionOracle::insertar($this->conexion, 'INV_TIPO_ESPECIFICACION', ['TES_Nombre' => $nombre], 'TES_Tipo_Especificacion');
    }

    /** Site of an area (area, level, building, site). */
    public function sedeDeArea(int $area): ?int
    {
        $sede = $this->conexion->fetchOne('SELECT e."SED_Sede" FROM "INV_AREA" a JOIN "INV_NIVEL" n ON n."NIV_Nivel" = a."NIV_Nivel" '
            .'JOIN "INV_EDIFICIO" e ON e."EDI_Edificio" = n."EDI_Edificio" WHERE a."ARE_Area" = :a', ['a' => $area]);

        return false === $sede || null === $sede ? null : (int) $sede;
    }

    /** @return list<string> */
    public function prefijosDeTipos(): array
    {
        return array_map('strval', $this->conexion->fetchFirstColumn('SELECT "TAC_Prefijo" FROM "INV_TIPO_ACTIVO" WHERE "TAC_Prefijo" IS NOT NULL'));
    }

    /** @return list<mixed> the internal codes that start with that prefix, whatever their case */
    public function codigosConPrefijo(string $prefijo): array
    {
        return $this->conexion->fetchFirstColumn("SELECT \"ACT_Codigo_Interno\" FROM \"INV_ACTIVO\" WHERE UPPER(\"ACT_Codigo_Interno\") LIKE :p ESCAPE '\\'",
            ['p' => mb_strtoupper(addcslashes($prefijo, '%_\\')).'%']);
    }
}
