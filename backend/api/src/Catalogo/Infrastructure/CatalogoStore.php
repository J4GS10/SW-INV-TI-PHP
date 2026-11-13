<?php

declare(strict_types=1);

namespace App\Catalogo\Infrastructure;

use App\Db\InsercionOracle;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The generic catalogs as stored. Table, column and prefix names always come from App\Catalogo\RegistroCatalogos
 * or from the schema dictionary, never from a request: all that SQL lives here.
 */
final readonly class CatalogoStore
{
    public function __construct(private Connection $conexion)
    {
    }

    /** @return list<array<string, mixed>> the items of a catalog in display order */
    public function filas(string $tabla, string $prefijo, bool $inactivos): array
    {
        return $this->conexion->fetchAllAssociative(
            'SELECT * FROM "'.$tabla.'"'.($inactivos ? '' : " WHERE \"{$prefijo}_Activo\" = 1")
            ." ORDER BY \"{$prefijo}_Orden\", LOWER(\"{$prefijo}_Nombre\")",
        );
    }

    /** @return array<string, mixed>|false */
    public function fila(string $tabla, string $pk, int $id): array|false
    {
        return $this->conexion->fetchAssociative('SELECT * FROM "'.$tabla.'" WHERE "'.$pk.'" = :id', ['id' => $id]);
    }

    /** @return array<string, mixed>|false */
    public function porCodigo(string $tabla, string $prefijo, string $codigo, bool $soloActivos): array|false
    {
        return $this->conexion->fetchAssociative(
            'SELECT * FROM "'.$tabla."\" WHERE \"{$prefijo}_Codigo\" = :c".($soloActivos ? " AND \"{$prefijo}_Activo\" = 1" : ''),
            ['c' => $codigo],
        );
    }

    public function existeCodigo(string $tabla, string $prefijo, string $codigo): bool
    {
        return false !== $this->conexion->fetchOne("SELECT 1 FROM \"{$tabla}\" WHERE \"{$prefijo}_Codigo\" = :c", ['c' => $codigo]);
    }

    /** Whether an item other than `$excepto` has that name, whatever its case and outer spaces. */
    public function existeNombre(string $tabla, string $prefijo, string $pk, string $nombre, ?int $excepto): bool
    {
        $sql = "SELECT 1 FROM \"{$tabla}\" WHERE LOWER(TRIM(\"{$prefijo}_Nombre\")) = LOWER(TRIM(:n))";
        $parametros = ['n' => $nombre];
        if (null !== $excepto) {
            $sql .= " AND \"{$pk}\" <> :e";
            $parametros['e'] = $excepto;
        }

        return false !== $this->conexion->fetchOne($sql.' FETCH FIRST 1 ROWS ONLY', $parametros);
    }

    public function mayorOrden(string $tabla, string $prefijo): int
    {
        return (int) $this->conexion->fetchOne("SELECT MAX(\"{$prefijo}_Orden\") FROM \"{$tabla}\"");
    }

    /** @param array<string, mixed> $valores */
    public function insertar(string $tabla, array $valores, string $pk): int
    {
        return (int) InsercionOracle::insertar($this->conexion, $tabla, $valores, $pk);
    }

    /** @param array<string, mixed> $valores column => scalar value */
    public function actualizar(string $tabla, string $pk, int $id, array $valores): void
    {
        $sets = [];
        $parametros = ['id' => $id];
        $tipos = [];
        foreach ($valores as $columna => $valor) {
            $marca = 'c'.\count($sets);
            $sets[] = "\"{$columna}\" = :{$marca}";
            $parametros[$marca] = $valor;
            if (\is_string($valor) && \strlen($valor) > 4000) {
                $tipos[$marca] = ParameterType::LARGE_OBJECT;
            }
        }
        $this->conexion->executeStatement("UPDATE \"{$tabla}\" SET ".implode(', ', $sets)." WHERE \"{$pk}\" = :id", $parametros, $tipos);
    }

    public function fijarOrden(string $tabla, string $prefijo, string $pk, mixed $id, int $orden): void
    {
        $this->conexion->executeStatement("UPDATE \"{$tabla}\" SET \"{$prefijo}_Orden\" = :o WHERE \"{$pk}\" = :id", ['o' => $orden, 'id' => $id]);
    }

    /**
     * Points every row that holds `$de` in that column to `$a`; with `$columnaTexto`, also writes `$texto` beside it.
     */
    public function reapuntar(string $tabla, string $columna, mixed $de, mixed $a, ?string $columnaTexto = null, ?string $texto = null): void
    {
        $sets = ["\"{$columna}\" = :destino"];
        $parametros = ['destino' => $a, 'origen' => $de];
        if (null !== $columnaTexto) {
            $sets[] = "\"{$columnaTexto}\" = :texto";
            $parametros['texto'] = $texto;
        }
        $this->conexion->executeStatement("UPDATE \"{$tabla}\" SET ".implode(', ', $sets)." WHERE \"{$columna}\" = :origen", $parametros);
    }

    /** How many rows hold that value in that column. */
    public function cuantos(string $tabla, string $columna, mixed $valor): int
    {
        return (int) $this->conexion->fetchOne("SELECT COUNT(*) FROM \"{$tabla}\" WHERE \"{$columna}\" = :v", ['v' => $valor]);
    }

    /** @return list<list<mixed>> [value, rows that hold it], for every value in use */
    public function usoPorValor(string $tabla, string $columna): array
    {
        return $this->conexion->fetchAllNumeric("SELECT \"{$columna}\", COUNT(*) FROM \"{$tabla}\" WHERE \"{$columna}\" IS NOT NULL GROUP BY \"{$columna}\"");
    }

    /** @return array<mixed, mixed> code => id */
    public function idsPorCodigo(string $tabla, string $prefijo, string $pk): array
    {
        return $this->conexion->fetchAllKeyValue("SELECT \"{$prefijo}_Codigo\", \"{$pk}\" FROM \"{$tabla}\"");
    }

    /** How many assets belong to models of that brand. */
    public function activosDeMarca(int $marca): int
    {
        return (int) $this->conexion->fetchOne(
            'SELECT COUNT(*) FROM "INV_ACTIVO" a JOIN "INV_MODELO" m ON m."MOD_Modelo" = a."MOD_Modelo" WHERE m."MAR_Marca" = :o',
            ['o' => $marca],
        );
    }

    /** @return list<list<mixed>> [id, name] of the models of a brand */
    public function modelosDeMarca(int $marca): array
    {
        return $this->conexion->fetchAllNumeric('SELECT "MOD_Modelo", "MOD_Nombre" FROM "INV_MODELO" WHERE "MAR_Marca" = :m', ['m' => $marca]);
    }
}
