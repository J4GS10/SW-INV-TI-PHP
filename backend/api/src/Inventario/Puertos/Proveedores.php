<?php

declare(strict_types=1);

namespace App\Inventario\Puertos;

/**
 * Suppliers as the purchasing rules need them. The business layer owns this contract; the data
 * layer implements it (App\Repositorio\ProveedoresOracle), so the rules do not know which
 * database stores them.
 *
 * A supplier row: PRV_Proveedor, PRV_Nombre, PRV_Identificacion_Fiscal, PRV_Contacto, PRV_Email,
 * PRV_Telefono, PRV_Direccion, PRV_Activo (as stored: "1"/"0").
 */
interface Proveedores
{
    /** @return list<array<string, mixed>> ordered by name */
    public function todos(bool $soloActivos): array;

    /** @return array<string, mixed>|null */
    public function porId(int $id): ?array;

    public function existeNombre(string $nombre): bool;

    /**
     * @param array<string, mixed> $datos
     *
     * @return int the new supplier's id
     */
    public function crear(array $datos): int;

    /** @param array<string, mixed> $cambios column => value; nothing is written when empty */
    public function actualizar(int $id, array $cambios): void;

    /** Any stock receipt from this supplier, whatever the site: one that was received from cannot be deleted. */
    public function tieneIngresos(int $id): bool;

    /**
     * Logical delete: the supplier stops being listed and its history stays.
     *
     * @throws \App\Db\RecordInUse when other records still point at it
     */
    public function archivar(int $id): void;
}
