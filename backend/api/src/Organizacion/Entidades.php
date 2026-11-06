<?php

declare(strict_types=1);

namespace App\Organizacion;

use App\Crud\Campo;
use App\Crud\Entidad;

/** Departamentos, cargos y personas. */
final class Entidades
{
    public static function departamento(): Entidad
    {
        return new Entidad('INV_DEPARTAMENTO', 'DEP_Departamento', [
            Campo::texto('DEP_Nombre', true, 3, 100),
            Campo::texto('DEP_Codigo_Costos', max: 50),
            Campo::texto('DEP_Descripcion', max: 255),
            Campo::logico('DEP_Activo', defecto: true),
        ], orden: 't."DEP_Departamento"');
    }

    public static function cargo(): Entidad
    {
        return new Entidad('INV_CARGO', 'CAR_Cargo', [
            Campo::texto('CAR_Nombre', true, 3, 100),
            Campo::logico('CAR_Es_Jefatura', defecto: false),
            Campo::texto('CAR_Descripcion', max: 255),
        ]);
    }

    public static function persona(): Entidad
    {
        return new Entidad('INV_PERSONA', 'PER_Persona', [
            Campo::texto('PER_Primer_Nombre', true, 2, 50),
            Campo::texto('PER_Segundo_Nombre', max: 50),
            Campo::texto('PER_Primer_Apellido', true, 2, 50),
            Campo::texto('PER_Segundo_Apellido', max: 50),
            new Campo('PER_Email_Corporativo', Campo::CORREO, true),
            Campo::texto('PER_Telefono', max: 20),
            Campo::entero('DEP_Departamento', true),
            Campo::entero('CAR_Cargo', true),
            new Campo('PER_Jefe', Campo::UUID),
            Campo::entero('SED_Sede'),
            Campo::logico('PER_Estado', defecto: true),
            Campo::salida('PER_AD_GUID'),
            Campo::salida('PER_AD_Sincronizado_En', Campo::FECHAHORA),
            Campo::salida('created_at', Campo::FECHAHORA, 'CREATED_AT'),
        ], claveUuid: true);
    }
}
