<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Roles del sistema y grupos por módulo (fuente única, igual que el backend
 * actual). Separación de funciones:
 * - SUPER_ADMIN      administra el sistema (cuentas, configuración, directorio); no ve datos de negocio.
 * - ADMIN_SEGURIDAD  vigila: consulta cuentas, sesiones y auditoría y puede cerrar sesiones. No modifica cuentas ni ve datos de negocio.
 * - ADMIN_TI         administra el inventario y aprueba lo que pidió otra persona.
 * - TECNICO / CONSULTA  operación y consulta del inventario.
 * - AUDITOR          solo lectura del inventario y la bitácora.
 * - COMPRAS          compras y contabilidad: proveedores, órdenes, valores y cierre; sin inventario operativo.
 */
final class Roles
{
    public const string SUPER_ADMIN = 'SUPER_ADMIN';
    public const string ADMIN_SEGURIDAD = 'ADMIN_SEGURIDAD';
    public const string ADMIN_TI = 'ADMIN_TI';
    public const string TECNICO = 'TECNICO';
    public const string AUDITOR = 'AUDITOR';
    public const string CONSULTA = 'CONSULTA';
    public const string COMPRAS = 'COMPRAS';

    public const array TODOS = [self::SUPER_ADMIN, self::ADMIN_SEGURIDAD, self::ADMIN_TI, self::TECNICO,
        self::AUDITOR, self::CONSULTA, self::COMPRAS];

    public const array IAM = [self::SUPER_ADMIN, self::ADMIN_SEGURIDAD];
    public const array LECTORES_AUDITORIA = [self::SUPER_ADMIN, self::ADMIN_SEGURIDAD, self::AUDITOR];
    /** Alcance global implícito (no se limitan por sede). */
    public const array SIEMPRE_GLOBALES = [self::SUPER_ADMIN, self::ADMIN_SEGURIDAD];
    /** Administran el sistema: la base les oculta los datos de negocio aunque tengan alcance global. */
    public const array SIN_DATOS_DE_NEGOCIO = [self::SUPER_ADMIN, self::ADMIN_SEGURIDAD];
    public const array CON_ALCANCE = [self::ADMIN_TI, self::TECNICO, self::AUDITOR, self::CONSULTA, self::COMPRAS];
    public const array NEGOCIO = [self::ADMIN_TI, self::TECNICO, self::AUDITOR, self::CONSULTA];
    public const array APP = [...self::NEGOCIO, self::COMPRAS];
    public const array LECTURA_INVENTARIO = self::NEGOCIO;
    public const array LECTURA_EXISTENCIAS = self::NEGOCIO;
    public const array LECTURA_PERSONAS = [self::ADMIN_TI, self::TECNICO, self::AUDITOR];
    public const array LECTURA_SOFTWARE = [self::ADMIN_TI, self::TECNICO, self::AUDITOR];
    public const array ESCRITURA_COMPRAS = [self::ADMIN_TI, self::COMPRAS];
    public const array LECTURA_COMPRAS = [...self::ESCRITURA_COMPRAS, self::AUDITOR];
    public const array LECTURA_CONTROL = [self::ADMIN_TI, self::AUDITOR];
    public const array EXPORTACION_FINANZAS = [self::ADMIN_TI, self::AUDITOR, self::COMPRAS];
    /** Exportes de control (kardex, custodia, movimientos, tomas). */
    public const array EXPORTACION = [self::ADMIN_TI, self::AUDITOR];
    public const array EXPORTACION_COMPRAS = self::EXPORTACION_FINANZAS;
    /** No reciben costos ni montos. */
    public const array SIN_COSTOS = [self::TECNICO, self::CONSULTA];
    public const array APROBADORES = [self::ADMIN_TI];
    public const array PROTEGIDOS = [self::SUPER_ADMIN, self::ADMIN_SEGURIDAD];
    public const array ADMIN = [self::ADMIN_TI];
    public const array OPERATIVO = [self::ADMIN_TI, self::TECNICO];

    /** ¿Puede `$solicitante` administrar una cuenta con `$objetivo`? */
    public static function puedeAdministrar(string $solicitante, string $objetivo): bool
    {
        return self::SUPER_ADMIN === $solicitante;
    }

    /** ¿Puede `$solicitante` cerrar las sesiones de una cuenta con `$objetivo`? Contiene un incidente sin tocar la cuenta. */
    public static function puedeCerrarSesiones(string $solicitante, string $objetivo): bool
    {
        return match ($solicitante) {
            self::SUPER_ADMIN => true,
            self::ADMIN_SEGURIDAD => !\in_array($objetivo, self::PROTEGIDOS, true),
            default => false,
        };
    }

    public static function puedeOtorgarAlcanceGlobal(string $solicitante): bool
    {
        return self::SUPER_ADMIN === $solicitante;
    }
}
