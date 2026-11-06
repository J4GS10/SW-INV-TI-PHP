<?php

declare(strict_types=1);

namespace App\Db;

/**
 * Código de error de cada índice único (insensible a mayúsculas y espacios):
 * un duplicado se informa con su código (BRAND_ALREADY_EXISTS…) y no con el
 * mensaje genérico de integridad. Las comprobaciones previas de los servicios
 * dan el error antes de escribir; el índice es la garantía final (también ante
 * peticiones simultáneas).
 */
final class Unicidad
{
    private const array CODIGOS = [
        'UQ_CI_MARCA_NOMBRE' => 'BRAND_ALREADY_EXISTS',
        'UQ_CI_MODELO_NOMBRE' => 'MODEL_ALREADY_EXISTS',
        'UQ_CI_TIPO_ACTIVO_NOMBRE' => 'ASSET_TYPE_ALREADY_EXISTS',
        'UQ_CI_TIPO_ACTIVO_PREFIJO' => 'ASSET_TYPE_PREFIX_ALREADY_EXISTS',
        'UQ_CI_TIPO_CONEXION_NOMBRE' => 'CONNECTION_TYPE_ALREADY_EXISTS',
        'UQ_CI_ESTADO_OPERATIVO_NOMBRE' => 'OPERATIONAL_STATUS_ALREADY_EXISTS',
        'UQ_CI_TIPO_ESPECIFICACION_NOMBRE' => 'SPECIFICATION_TYPE_ALREADY_EXISTS',
        'UQ_CI_PAIS_NOMBRE' => 'COUNTRY_ALREADY_EXISTS',
        'UQ_CI_PAIS_ISO' => 'COUNTRY_ISO_ALREADY_EXISTS',
        'UQ_CI_DEPARTAMENTO_NOMBRE' => 'DEPARTMENT_ALREADY_EXISTS',
        'UQ_CI_CARGO_NOMBRE' => 'POSITION_ALREADY_EXISTS',
        'UQ_CI_PERSONA_EMAIL' => 'EMAIL_ALREADY_EXISTS',
        'UQ_CI_ACTIVO_SERIE' => 'SERIAL_NUMBER_ALREADY_EXISTS',
        'UQ_CI_ACTIVO_CODIGO' => 'ASSET_CODE_ALREADY_EXISTS',
        'UQ_CI_CONSUMIBLE_NOMBRE' => 'CONSUMABLE_ALREADY_EXISTS',
        'UQ_CI_PROVEEDOR_NOMBRE' => 'SUPPLIER_ALREADY_EXISTS',
        'UQ_CI_ORDEN_NUMERO' => 'PURCHASE_ORDER_NUMBER_EXISTS',
        'UQ_CI_TIPO_LICENCIA_NOMBRE' => 'LICENSE_TYPE_ALREADY_EXISTS',
        'UQ_CI_SOFTWARE' => 'SOFTWARE_ALREADY_EXISTS',
        'UQ_CI_TIPO_MANTENIMIENTO_NOMBRE' => 'MAINTENANCE_TYPE_ALREADY_EXISTS',
        'UQ_CI_TIPO_MOVIMIENTO_NOMBRE' => 'MOVEMENT_TYPE_ALREADY_EXISTS',
        'UQ_CI_MOTIVO_MOVIMIENTO_NOMBRE' => 'CATALOG_ITEM_ALREADY_EXISTS',
        'UQ_CI_MOTIVO_BAJA_NOMBRE' => 'CATALOG_ITEM_ALREADY_EXISTS',
        'UQ_CI_DESTINO_BAJA_NOMBRE' => 'CATALOG_ITEM_ALREADY_EXISTS',
        'UQ_CI_UNIDAD_MEDIDA_NOMBRE' => 'CATALOG_ITEM_ALREADY_EXISTS',
        'UQ_CI_CATEGORIA_CONSUMIBLE_NOMBRE' => 'CATALOG_ITEM_ALREADY_EXISTS',
        'UQ_CI_TIPO_AREA_NOMBRE' => 'CATALOG_ITEM_ALREADY_EXISTS',
        'UQ_CI_TIPO_FALLA_NOMBRE' => 'CATALOG_ITEM_ALREADY_EXISTS',
        'UQ_CI_ACCION_MANTENIMIENTO_NOMBRE' => 'CATALOG_ITEM_ALREADY_EXISTS',
        'UQ_CI_TIPO_DOCUMENTO_NOMBRE' => 'CATALOG_ITEM_ALREADY_EXISTS',
        'UQ_CI_MODELO_COMERCIAL_NOMBRE' => 'CATALOG_ITEM_ALREADY_EXISTS',
        'UQ_CI_METODO_ACTIVACION_NOMBRE' => 'CATALOG_ITEM_ALREADY_EXISTS',
        'UQ_CI_FORMA_CONTEO_NOMBRE' => 'CATALOG_ITEM_ALREADY_EXISTS',
        'UQ_CI_TIPO_EVIDENCIA_NOMBRE' => 'CATALOG_ITEM_ALREADY_EXISTS',
        'UQ_ACTIVO_SERIE_FABRICANTE' => 'SERIAL_NUMBER_ALREADY_EXISTS',
        'UQ_SOFTWARE_NOMBRE_VERSION_FABR' => 'SOFTWARE_ALREADY_EXISTS',
        'UQ_LICENCIA_CLAVE_HASH' => 'LICENSE_KEY_ALREADY_EXISTS',
    ];

    /**
     * Código para una violación de unicidad conocida (ORA-00001 informa
     * `unique constraint (ESQUEMA.NOMBRE) violated`), o null.
     */
    public static function codigo(\Throwable $e): ?string
    {
        for ($actual = $e; null !== $actual; $actual = $actual->getPrevious()) {
            if (1 === preg_match('/ORA-00001: unique constraint \(([^.()]+)\.([^()]+)\)/i', $actual->getMessage(), $m)) {
                return self::CODIGOS[strtoupper(trim($m[2], '"'))] ?? null;
            }
        }

        return null;
    }
}
