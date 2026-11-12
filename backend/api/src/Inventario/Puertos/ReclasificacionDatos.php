<?php

declare(strict_types=1);

namespace App\Inventario\Puertos;

/**
 * What reclassifying supplies registered as assets needs of the data. The rules are in
 * App\Inventario\Reclasificacion; the data layer implements this (App\Repositorio\ReclasificacionOracle).
 */
interface ReclasificacionDatos
{
    /** @return array{TAC_Nombre: string, TAC_Clase: string|null}|null */
    public function tipo(int $tipo): ?array;

    /**
     * Assets of the type that are not decommissioned, with the columns of an asset (ACT_Activo, ACT_Codigo_Interno,
     * ACT_Costo, ACT_Moneda, SED_Sede, ARE_Area_Ubicacion, ACT_Activo_Padre, ACT_Instalado…), ordered by internal code.
     *
     * @return list<array<string, mixed>>
     */
    public function activosVigentes(int $tipo, ?int $sede, int $estadoBaja): array;

    /**
     * Locks the assets until the transaction ends, always in the same order (no deadlocks with other operations).
     *
     * @param list<string> $activos
     */
    public function bloquear(array $activos): void;

    /** Someone holds it: a custody that has not been returned. */
    public function tieneCustodia(string $activo): bool;

    public function tieneMantenimientoAbierto(string $activo): bool;

    /** @return array<int|string, string> site id => name */
    public function nombresDeSedes(): array;

    /** @return array{CON_Consumible: int, SED_Sede: int|null, CON_Moneda: string|null}|null matched without case */
    public function consumiblePorNombre(string $nombre): ?array;

    /**
     * @param array<string, mixed> $datos
     *
     * @return int the new consumable's id
     */
    public function crearConsumible(array $datos): int;

    /** Marks the asset as decommissioned because it became a consumable (reason RECLASIFICACION, destination CONSUMIBLES). */
    public function darDeBaja(string $activo, int $estadoBaja, \DateTimeImmutable $fecha, string $documento, string $observacion): void;
}
