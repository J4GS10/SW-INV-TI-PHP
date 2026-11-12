<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Correo\Notificador;
use App\Db\RecordInUse;
use App\Db\Transaccion;
use App\Dominio\ErrorDominio;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Inventario\Puertos\GarantiasActivos;
use App\Inventario\Puertos\Proveedores;
use App\Repositorio\GobiernoRepositorio;

/**
 * Compras: proveedores y vista de garantías. Lo recibido entra por el ingreso a bodega (App\GoodsReceipt).
 * Solo reglas: los datos llegan por los puertos Proveedores y GarantiasActivos, que implementa la capa
 * de datos (App\Repositorio\ProveedoresOracle).
 */
final readonly class Compras
{
    private const array PRIORIDAD_GARANTIA = ['vencida' => 0, 'por_vencer' => 1, 'vigente' => 2, 'sin_garantia' => 3];

    public function __construct(
        private Proveedores $proveedores,
        private GarantiasActivos $activos,
        private GobiernoRepositorio $gobierno,
        private Transaccion $transaccion,
        private Notificador $notificador,
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
        return array_map(self::proveedorAApi(...), $this->proveedores->todos($soloActivos));
    }

    /** @return array<string, mixed> */
    public function proveedor(int $id): array
    {
        $f = $this->proveedores->porId($id);

        return null === $f ? throw ErrorDominio::noEncontrado('SUPPLIER_NOT_FOUND') : self::proveedorAApi($f);
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>
     */
    public function crearProveedor(array $d, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($d, $usuario, $ip): array {
            if ($this->proveedores->existeNombre((string) $d['PRV_Nombre'])) {
                throw ErrorDominio::conflicto('SUPPLIER_ALREADY_EXISTS');
            }
            $id = $this->proveedores->crear($d);
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
            $this->proveedores->actualizar($id, $cambios);
            $this->gobierno->registrarAuditoria('UPDATE', 'INV_PROVEEDOR', ['id' => $id, 'cambios' => (object) $cambios], $usuario, $ip);

            return $this->proveedor($id);
        });
    }

    public function eliminarProveedor(int $id, string $usuario, ?string $ip): void
    {
        $this->transaccion->ejecutar(function () use ($id, $usuario, $ip): void {
            $p = $this->proveedor($id);
            if ($this->proveedores->tieneIngresos($id)) {
                throw ErrorDominio::conflicto('CANNOT_DELETE_SUPPLIER_HAS_RECEIPTS');
            }
            try {
                $this->proveedores->archivar($id);
            } catch (RecordInUse) {
                throw ErrorDominio::conflicto('CANNOT_DELETE_SUPPLIER_HAS_RECEIPTS');
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
        $hoy = new \DateTimeImmutable(Reloj::ahora()->format('Y-m-d'), new \DateTimeZone('UTC'));
        $umbral = $hoy->modify("+{$dias} days");
        $items = [];
        foreach ($this->activos->visibles() as $f) {
            $fin = null === $f['ACT_Fin_Garantia'] ? null : new \DateTimeImmutable(substr($f['ACT_Fin_Garantia'], 0, 10), new \DateTimeZone('UTC'));
            $estado = self::estadoGarantia($fin, $hoy, $umbral);
            if ($soloAlertas && !\in_array($estado, ['por_vencer', 'vencida'], true)) {
                continue;
            }
            $items[] = ['ACT_Activo' => Ident::aTexto($f['ACT_Activo']), 'ACT_Codigo_Interno' => $f['ACT_Codigo_Interno'], 'ACT_Serie_Fabricante' => $f['ACT_Serie_Fabricante'],
                'ACT_Fecha_Compra' => substr($f['ACT_Fecha_Compra'], 0, 10), 'ACT_Fin_Garantia' => $fin?->format('Y-m-d'),
                'dias_restantes' => null === $fin ? null : (int) $hoy->diff($fin)->format('%r%a'), 'estado_garantia' => $estado, 'proveedor' => $f['PRV_Nombre']];
        }
        usort($items, static fn (array $a, array $b): int => [self::PRIORIDAD_GARANTIA[$a['estado_garantia']], $a['dias_restantes'] ?? 10 ** 9]
            <=> [self::PRIORIDAD_GARANTIA[$b['estado_garantia']], $b['dias_restantes'] ?? 10 ** 9]);

        return $items;
    }

    /** Regla pura: sin fecha no hay garantía; vencida si ya pasó; por vencer si cae dentro del umbral. */
    public static function estadoGarantia(?\DateTimeImmutable $fin, \DateTimeImmutable $hoy, \DateTimeImmutable $umbral): string
    {
        return match (true) {
            null === $fin => 'sin_garantia',
            $fin < $hoy => 'vencida',
            $fin <= $umbral => 'por_vencer',
            default => 'vigente',
        };
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
