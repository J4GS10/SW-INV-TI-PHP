<?php

declare(strict_types=1);

namespace App\Directorio\Infrastructure;

use App\Db\InsercionOracle;
use Doctrine\DBAL\Connection;

/**
 * Departamentos y cargos por nombre (sin distinguir mayúsculas) durante una
 * sincronización: el que no existe se crea.
 */
final class CatalogosPersona
{
    /** @var array<string, int>|null */
    private ?array $departamentos = null;
    /** @var array<string, int>|null */
    private ?array $cargos = null;

    public function __construct(private readonly Connection $conexion)
    {
    }

    public function departamento(string $nombre): int
    {
        $this->departamentos ??= $this->cargar('SELECT "DEP_Nombre", "DEP_Departamento" FROM "INV_DEPARTAMENTO"');

        return $this->obtenerOCrear($this->departamentos, $nombre,
            static fn (Connection $c, string $n): string => InsercionOracle::insertar($c, 'INV_DEPARTAMENTO', ['DEP_Nombre' => $n, 'DEP_Activo' => true], 'DEP_Departamento'));
    }

    public function cargo(string $nombre): int
    {
        $this->cargos ??= $this->cargar('SELECT "CAR_Nombre", "CAR_Cargo" FROM "INV_CARGO"');

        return $this->obtenerOCrear($this->cargos, $nombre,
            static fn (Connection $c, string $n): string => InsercionOracle::insertar($c, 'INV_CARGO', ['CAR_Nombre' => $n, 'CAR_Es_Jefatura' => false], 'CAR_Cargo'));
    }

    /**
     * @param array<string, int>                     $cache
     * @param \Closure(Connection, string): string $crear
     */
    private function obtenerOCrear(array &$cache, string $nombre, \Closure $crear): int
    {
        $nombre = mb_substr(trim($nombre), 0, 100);
        $clave = mb_strtolower($nombre);

        return $cache[$clave] ??= (int) $crear($this->conexion, $nombre);
    }

    /** @return array<string, int> */
    private function cargar(string $sql): array
    {
        $mapa = [];
        foreach ($this->conexion->fetchAllNumeric($sql) as [$nombre, $id]) {
            $mapa[mb_strtolower((string) $nombre)] = (int) $id;
        }

        return $mapa;
    }
}
