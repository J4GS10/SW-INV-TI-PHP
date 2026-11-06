<?php

declare(strict_types=1);

namespace App\Adjuntos;

use App\Dominio\Roles;

/**
 * A qué pertenece un adjunto. Cada adjunto hereda el permiso de su dueño: los del
 * activo, del inventario; los de la orden, de Compras; los de la licencia (pueden
 * contener la activación), solo de los administradores.
 */
enum TipoDueno: string
{
    case Activo = 'activos';
    case Orden = 'ordenes';
    case Licencia = 'licencias';
    case Ingreso = 'ingresos';

    public function columna(): string
    {
        return match ($this) {
            self::Activo => 'ACT_Activo',
            self::Orden => 'OCO_Orden',
            self::Licencia => 'LIC_Licencia',
            self::Ingreso => 'GRC_Goods_Receipt',
        };
    }

    /** Código 404 cuando el dueño no existe o no está en el alcance. */
    public function noEncontrado(): string
    {
        return match ($this) {
            self::Activo => 'ASSET_NOT_FOUND',
            self::Orden => 'PURCHASE_ORDER_NOT_FOUND',
            self::Licencia => 'LICENSE_NOT_FOUND',
            self::Ingreso => 'GOODS_RECEIPT_NOT_FOUND',
        };
    }

    /** Categoría por defecto de una subida sin categoría. */
    public function categoriaPorDefecto(): string
    {
        return self::Orden === $this || self::Ingreso === $this ? 'factura' : 'otro';
    }

    /** @return list<string> roles que pueden descargar sus archivos */
    public function lectores(): array
    {
        return match ($this) {
            self::Activo => Roles::LECTURA_INVENTARIO,
            self::Orden => Roles::LECTURA_COMPRAS,
            self::Licencia => [Roles::ADMIN_TI],
            // La hoja escaneada muestra precios: ni técnicos ni consulta.
            self::Ingreso => Roles::LECTURA_COMPRAS,
        };
    }

    /** @param array<string, mixed> $fila */
    public static function deFila(array $fila): self
    {
        return match (true) {
            null !== $fila['LIC_Licencia'] => self::Licencia,
            null !== $fila['OCO_Orden'] => self::Orden,
            null !== ($fila['GRC_Goods_Receipt'] ?? null) => self::Ingreso,
            default => self::Activo,
        };
    }
}
