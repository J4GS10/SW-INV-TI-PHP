<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Correo\Notificador;
use App\Db\InsercionOracle;
use App\Db\Transaccion;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Http\ErrorApi;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;

/** Compras: proveedores y vista de garantías. Lo recibido entra por el ingreso a bodega (App\GoodsReceipt). */
final readonly class Compras
{
    private const string PROVEEDOR = '"PRV_Proveedor", "PRV_Nombre", "PRV_Identificacion_Fiscal", "PRV_Contacto", "PRV_Email", "PRV_Telefono", "PRV_Direccion", "PRV_Activo"';

    public function __construct(
        private Connection $conexion,
        private Alcance $alcance,
        private GobiernoRepositorio $gobierno,
        private Transaccion $transaccion,
        private Notificador $notificador,
        private \App\Db\LogicalDelete $borrado,
    ) {
    }

    // =====================================================================
    // Proveedores
    // =====================================================================

    /**
     * @param array<string, mixed> $f
     *
     * @return array<string, mixed>
     */
    private static function proveedorAApi(array $f): array
    {
        return ['PRV_Nombre' => $f['PRV_Nombre'], 'PRV_Identificacion_Fiscal' => $f['PRV_Identificacion_Fiscal'], 'PRV_Contacto' => $f['PRV_Contacto'],
            'PRV_Email' => $f['PRV_Email'], 'PRV_Telefono' => $f['PRV_Telefono'], 'PRV_Direccion' => $f['PRV_Direccion'],
            'PRV_Proveedor' => (int) $f['PRV_Proveedor'], 'PRV_Activo' => 1 === (int) $f['PRV_Activo']];
    }

    /** @return list<array<string, mixed>> */
    public function proveedores(bool $soloActivos): array
    {
        return array_map(self::proveedorAApi(...), $this->conexion->fetchAllAssociative('SELECT '.self::PROVEEDOR.' FROM "INV_PROVEEDOR"'
            .($soloActivos ? ' WHERE "PRV_Activo" = 1' : '').' ORDER BY "PRV_Nombre", "PRV_Proveedor"'));
    }

    /** @return array<string, mixed> */
    public function proveedor(int $id): array
    {
        $f = $this->conexion->fetchAssociative('SELECT '.self::PROVEEDOR.' FROM "INV_PROVEEDOR" WHERE "PRV_Proveedor" = :id', ['id' => $id]);

        return false === $f ? throw new ErrorApi(404, 'SUPPLIER_NOT_FOUND') : self::proveedorAApi($f);
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>
     */
    public function crearProveedor(array $d, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($d, $usuario, $ip): array {
            if (false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_PROVEEDOR" WHERE "PRV_Nombre" = :n FETCH FIRST 1 ROWS ONLY', ['n' => $d['PRV_Nombre']])) {
                throw new ErrorApi(409, 'SUPPLIER_ALREADY_EXISTS');
            }
            $id = (int) InsercionOracle::insertar($this->conexion, 'INV_PROVEEDOR', [...$d, 'PRV_Activo' => 1], 'PRV_Proveedor');
            $this->gobierno->registrarAuditoria('CREATE', 'INV_PROVEEDOR', ['nombre' => $d['PRV_Nombre']], $usuario, $ip);

            return $this->proveedor($id);
        });
    }

    /**
     * @param array<string, mixed> $cambios
     *
     * @return array<string, mixed>
     */
    public function cambiarProveedor(int $id, array $cambios, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($id, $cambios, $usuario, $ip): array {
            $this->proveedor($id);
            if ([] !== $cambios) {
                $sets = [];
                foreach (array_keys($cambios) as $c) {
                    $sets[] = "\"{$c}\" = :{$c}";
                }
                $this->conexion->executeStatement('UPDATE "INV_PROVEEDOR" SET '.implode(', ', $sets).', "UPDATED_AT" = :upd WHERE "PRV_Proveedor" = :id',
                    [...array_map(static fn (mixed $v): mixed => \is_bool($v) ? (int) $v : $v, $cambios), 'upd' => Reloj::aBd(Reloj::ahora()), 'id' => $id]);
            }
            $this->gobierno->registrarAuditoria('UPDATE', 'INV_PROVEEDOR', ['id' => $id, 'cambios' => (object) $cambios], $usuario, $ip);

            return $this->proveedor($id);
        });
    }

    public function eliminarProveedor(int $id, string $usuario, ?string $ip): void
    {
        $this->transaccion->ejecutar(function () use ($id, $usuario, $ip): void {
            $p = $this->proveedor($id);
            if (false !== $this->alcance->elevado(fn (): mixed => $this->conexion->fetchOne('SELECT 1 FROM PUR_GOODS_RECEIPT WHERE GRC_PRV_SUPPLIER = :id FETCH FIRST 1 ROWS ONLY', ['id' => $id]))) {
                throw new ErrorApi(409, 'CANNOT_DELETE_SUPPLIER_HAS_RECEIPTS');
            }
            try {
                $this->borrado->archive('INV_PROVEEDOR', 'PRV_Proveedor', $id);
            } catch (\App\Db\RecordInUse) {
                throw new ErrorApi(409, 'CANNOT_DELETE_SUPPLIER_HAS_RECEIPTS');
            }
            $this->gobierno->registrarAuditoria('DELETE', 'INV_PROVEEDOR', ['id' => $id, 'nombre' => $p['PRV_Nombre']], $usuario, $ip);
        });
    }

    // =====================================================================
    // Garantías
    // =====================================================================

    /** @return list<array<string, mixed>> GarantiaItem, alertas primero */
    public function garantias(int $dias, bool $soloAlertas): array
    {
        [$alcance, $p] = $this->alcance->lectura('INV_ACTIVO', 'a');
        $filas = $this->conexion->fetchAllAssociative('SELECT a."ACT_Activo", a."ACT_Codigo_Interno", a."ACT_Serie_Fabricante", a."ACT_Fecha_Compra", '
            .'a."ACT_Fin_Garantia", pr."PRV_Nombre" FROM "INV_ACTIVO" a LEFT JOIN "INV_PROVEEDOR" pr ON pr."PRV_Proveedor" = a."PRV_Proveedor" '
            .'WHERE '.$alcance.' ORDER BY a."ACT_Codigo_Interno"', $p);
        $hoy = new \DateTimeImmutable(Reloj::ahora()->format('Y-m-d'), new \DateTimeZone('UTC'));
        $umbral = $hoy->modify("+{$dias} days");
        $items = [];
        foreach ($filas as $f) {
            $id = (string) $f['ACT_Activo'];
            $fin = null === $f['ACT_Fin_Garantia'] ? null : new \DateTimeImmutable(substr((string) $f['ACT_Fin_Garantia'], 0, 10), new \DateTimeZone('UTC'));
            $restantes = null === $fin ? null : (int) $hoy->diff($fin)->format('%r%a');
            $estado = match (true) {
                null === $fin => 'sin_garantia',
                $fin < $hoy => 'vencida',
                $fin <= $umbral => 'por_vencer',
                default => 'vigente',
            };
            if ($soloAlertas && !\in_array($estado, ['por_vencer', 'vencida'], true)) {
                continue;
            }
            $items[] = ['ACT_Activo' => Ident::aTexto($id), 'ACT_Codigo_Interno' => $f['ACT_Codigo_Interno'], 'ACT_Serie_Fabricante' => $f['ACT_Serie_Fabricante'],
                'ACT_Fecha_Compra' => substr((string) $f['ACT_Fecha_Compra'], 0, 10), 'ACT_Fin_Garantia' => $fin?->format('Y-m-d'),
                'dias_restantes' => $restantes, 'estado_garantia' => $estado, 'proveedor' => $f['PRV_Nombre']];
        }
        $prioridad = ['vencida' => 0, 'por_vencer' => 1, 'vigente' => 2, 'sin_garantia' => 3];
        usort($items, static fn (array $a, array $b): int => [$prioridad[$a['estado_garantia']], $a['dias_restantes'] ?? 10 ** 9]
            <=> [$prioridad[$b['estado_garantia']], $b['dias_restantes'] ?? 10 ** 9]);

        return $items;
    }

    /**
     * Resumen de garantías vencidas o por vencer a los administradores.
     *
     * @return array{total: int, enviado: bool}
     */
    public function notificarGarantias(int $dias): array
    {
        $alertas = $this->garantias($dias, true);
        if ([] === $alertas) {
            return ['total' => 0, 'enviado' => false];
        }
        $this->notificador->enviar('garantia_por_vencer', ['total' => \count($alertas), 'dias' => $dias, 'items' => array_map(static fn (array $a): array => [
            'codigo' => $a['ACT_Codigo_Interno'], 'fin' => $a['ACT_Fin_Garantia'] ?? '—',
            'estado' => 'vencida' === $a['estado_garantia'] ? 'Vencida' : 'Por vencer', 'dias' => $a['dias_restantes']], $alertas)]);

        return ['total' => \count($alertas), 'enviado' => true];
    }
}
