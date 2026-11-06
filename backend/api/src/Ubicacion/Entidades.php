<?php

declare(strict_types=1);

namespace App\Ubicacion;

use App\Crud\Campo;
use App\Crud\Entidad;

/** Jerarquía geográfica: País › Estado › Municipio › Sede › Edificio › Nivel › Área. */
final class Entidades
{
    public static function pais(): Entidad
    {
        return new Entidad('INV_PAIS', 'PAI_Pais', [
            Campo::texto('PAI_Nombre', true, 2, 100),
            Campo::texto('PAI_Codigo_ISO', true, 2, 5),
        ]);
    }

    public static function estado(): Entidad
    {
        return new Entidad('INV_ESTADO', 'EST_Estado', [
            Campo::texto('EST_Nombre', true, 2, 100),
            Campo::entero('PAI_Pais', true, editable: false),
        ]);
    }

    public static function municipio(): Entidad
    {
        return new Entidad('INV_MUNICIPIO', 'MUN_Municipio', [
            Campo::texto('MUN_Nombre', true, 2, 100),
            Campo::entero('EST_Estado', true, editable: false),
        ]);
    }

    public static function sede(): Entidad
    {
        return new Entidad('INV_SEDE', 'SED_Sede', [
            Campo::texto('SED_Nombre', true, 3, 150),
            Campo::texto('SED_Direccion_Calle', max: 200),
            Campo::texto('SED_Direccion_Numero', max: 50),
            Campo::entero('MUN_Municipio', true, editable: false),
        ]);
    }

    public static function edificio(): Entidad
    {
        return new Entidad('INV_EDIFICIO', 'EDI_Edificio', [
            Campo::texto('EDI_Nombre', true, 1, 100),
            Campo::entero('SED_Sede', true, editable: false),
        ]);
    }

    public static function nivel(): Entidad
    {
        return new Entidad('INV_NIVEL', 'NIV_Nivel', [
            Campo::texto('NIV_Numero_Piso', true, 1, 50),
            Campo::texto('NIV_Alias', max: 100),
            Campo::entero('EDI_Edificio', true, editable: false),
        ]);
    }

    public static function area(): Entidad
    {
        return new Entidad('INV_AREA', 'ARE_Area', [
            Campo::texto('ARE_Nombre', true, 2, 150),
            Campo::texto('ARE_Tipo_Acceso', defecto: 'General'),
            Campo::texto('ARE_Descripcion', max: 255),
            Campo::entero('NIV_Nivel', true, editable: false),
            Campo::entero('TAR_Tipo_Area'),
        ]);
    }
}
