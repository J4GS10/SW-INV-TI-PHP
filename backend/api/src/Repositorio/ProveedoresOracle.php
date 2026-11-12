<?php

declare(strict_types=1);

namespace App\Repositorio;

use App\Db\InsercionOracle;
use App\Db\LogicalDelete;
use App\Dominio\Reloj;
use App\Inventario\Puertos\GarantiasActivos;
use App\Inventario\Puertos\Proveedores;
use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;

/** Suppliers and the warranty view in Oracle: all their SQL lives here. */
final readonly class ProveedoresOracle implements Proveedores, GarantiasActivos
{
    private const string COLUMNAS = '"PRV_Proveedor", "PRV_Nombre", "PRV_Identificacion_Fiscal", "PRV_Contacto", "PRV_Email", "PRV_Telefono", "PRV_Direccion", "PRV_Activo"';

    public function __construct(private Connection $conexion, private Alcance $alcance, private LogicalDelete $borrado)
    {
    }

    public function todos(bool $soloActivos): array
    {
        return $this->conexion->fetchAllAssociative('SELECT '.self::COLUMNAS.' FROM "INV_PROVEEDOR"'
            .($soloActivos ? ' WHERE "PRV_Activo" = 1' : '').' ORDER BY "PRV_Nombre", "PRV_Proveedor"');
    }

    public function porId(int $id): ?array
    {
        $fila = $this->conexion->fetchAssociative('SELECT '.self::COLUMNAS.' FROM "INV_PROVEEDOR" WHERE "PRV_Proveedor" = :id', ['id' => $id]);

        return false === $fila ? null : $fila;
    }

    public function existeNombre(string $nombre): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_PROVEEDOR" WHERE "PRV_Nombre" = :n FETCH FIRST 1 ROWS ONLY', ['n' => $nombre]);
    }

    public function crear(array $datos): int
    {
        return (int) InsercionOracle::insertar($this->conexion, 'INV_PROVEEDOR', [...$datos, 'PRV_Activo' => 1], 'PRV_Proveedor');
    }

    public function actualizar(int $id, array $cambios): void
    {
        if ([] === $cambios) {
            return;
        }
        $sets = [];
        foreach (array_keys($cambios) as $columna) {
            $sets[] = "\"{$columna}\" = :{$columna}";
        }
        $this->conexion->executeStatement('UPDATE "INV_PROVEEDOR" SET '.implode(', ', $sets).', "UPDATED_AT" = :upd WHERE "PRV_Proveedor" = :id',
            [...array_map(static fn (mixed $v): mixed => \is_bool($v) ? (int) $v : $v, $cambios), 'upd' => Reloj::aBd(Reloj::ahora()), 'id' => $id]);
    }

    public function tieneIngresos(int $id): bool
    {
        return false !== $this->alcance->elevado(fn (): mixed => $this->conexion->fetchOne(
            'SELECT 1 FROM PUR_GOODS_RECEIPT WHERE GRC_PRV_SUPPLIER = :id FETCH FIRST 1 ROWS ONLY', ['id' => $id]));
    }

    public function archivar(int $id): void
    {
        $this->borrado->archive('INV_PROVEEDOR', 'PRV_Proveedor', $id);
    }

    public function visibles(): array
    {
        [$alcance, $parametros] = $this->alcance->lectura('INV_ACTIVO', 'a');

        /** @var list<array{ACT_Activo: string, ACT_Codigo_Interno: string, ACT_Serie_Fabricante: string|null, ACT_Fecha_Compra: string, ACT_Fin_Garantia: string|null, PRV_Nombre: string|null}> */
        return $this->conexion->fetchAllAssociative('SELECT a."ACT_Activo", a."ACT_Codigo_Interno", a."ACT_Serie_Fabricante", a."ACT_Fecha_Compra", '
            .'a."ACT_Fin_Garantia", pr."PRV_Nombre" FROM "INV_ACTIVO" a LEFT JOIN "INV_PROVEEDOR" pr ON pr."PRV_Proveedor" = a."PRV_Proveedor" '
            .'WHERE '.$alcance.' ORDER BY a."ACT_Codigo_Interno"', $parametros);
    }
}
