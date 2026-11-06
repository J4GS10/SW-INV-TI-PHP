<?php

declare(strict_types=1);

namespace App\Catalogo;

use App\Crud\Campo;
use App\Crud\Entidad;

/** Catálogos técnicos (/cat): tipos de activo, marcas, conexiones, modelos, estados y especificaciones. */
final class EntidadesTecnicas
{
    public static function tipoActivo(): Entidad
    {
        return new Entidad('INV_TIPO_ACTIVO', 'TAC_Tipo_Activo', [
            Campo::texto('TAC_Nombre', true, 2, 100),
            Campo::texto('TAC_Prefijo', max: 10),
            Campo::logico('TAC_Aplica_Depreciacion', defecto: true),
            new Campo('TAC_Clase', opciones: array_keys(ClasesActivo::CLASES)),
        ]);
    }

    public static function marca(): Entidad
    {
        return new Entidad('INV_MARCA', 'MAR_Marca', [Campo::texto('MAR_Nombre', true, 2, 100)]);
    }

    public static function tipoConexion(): Entidad
    {
        return new Entidad('INV_TIPO_CONEXION', 'TCN_Tipo_Conexion', [
            Campo::texto('TCN_Nombre', true, 2, 50),
            Campo::texto('TCN_Descripcion', max: 200),
        ]);
    }

    public static function modelo(): Entidad
    {
        return new Entidad('INV_MODELO', 'MOD_Modelo', [
            Campo::texto('MOD_Nombre', true, 2, 150),
            // Rango solo en la entrada: los modelos legados pueden tener años fuera de rango.
            Campo::entero('MOD_Anio_Lanzamiento', minimo: 1970, maximo: 2100),
            Campo::entero('MAR_Marca', true),
            Campo::entero('TCN_Tipo_Conexion'),
            Campo::entero('TAC_Tipo_Activo'),
        ]);
    }

    public static function estadoOperativo(): Entidad
    {
        return new Entidad('INV_ESTADO_OPERATIVO', 'EOP_Estado_Operativo', [
            Campo::texto('EOP_Nombre', true, 2, 50),
            Campo::texto('EOP_Descripcion', max: 200),
        ]);
    }

    public static function tipoEspecificacion(): Entidad
    {
        return new Entidad('INV_TIPO_ESPECIFICACION', 'TES_Tipo_Especificacion', [
            Campo::texto('TES_Nombre', true, 2, 100),
            Campo::texto('TES_Unidad_Medida', max: 20),
        ]);
    }
}
