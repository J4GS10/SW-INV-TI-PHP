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
 * - COMPRAS          compras y contabilidad: proveedores, ingresos a bodega, valores y cierre; sin inventario operativo.
 * - ESCANER          teléfono o tableta de escaneo: ve el equipo (sin costos); sus cambios los autoriza un técnico con su código.
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
    /**
     * La cuenta de un teléfono o tableta de escaneo: ve el equipo escaneado (sin costos) y no cambia nada por sí
     * misma; cada cambio lo autoriza un técnico con su código de operador y queda a su nombre.
     */
    public const string ESCANER = 'ESCANER';

    public const array TODOS = [self::SUPER_ADMIN, self::ADMIN_SEGURIDAD, self::ADMIN_TI, self::TECNICO,
        self::AUDITOR, self::CONSULTA, self::COMPRAS, self::ESCANER];

    public const array IAM = [self::SUPER_ADMIN, self::ADMIN_SEGURIDAD];
    public const array LECTORES_AUDITORIA = [self::SUPER_ADMIN, self::ADMIN_SEGURIDAD, self::AUDITOR];
    /** Alcance global implícito (no se limitan por sede). */
    public const array SIEMPRE_GLOBALES = [self::SUPER_ADMIN, self::ADMIN_SEGURIDAD];
    /** Administran el sistema: la base les oculta los datos de negocio aunque tengan alcance global. */
    public const array SIN_DATOS_DE_NEGOCIO = [self::SUPER_ADMIN, self::ADMIN_SEGURIDAD];
    public const array CON_ALCANCE = [self::ADMIN_TI, self::TECNICO, self::AUDITOR, self::CONSULTA, self::COMPRAS, self::ESCANER];
    public const array NEGOCIO = [self::ADMIN_TI, self::TECNICO, self::AUDITOR, self::CONSULTA, self::ESCANER];
    public const array APP = [...self::NEGOCIO, self::COMPRAS];
    public const array LECTURA_INVENTARIO = self::NEGOCIO;
    public const array LECTURA_EXISTENCIAS = self::NEGOCIO;
    /** El escáner ve quién tiene el equipo y a quién entregarlo, y qué software lleva instalado. */
    public const array LECTURA_PERSONAS = [self::ADMIN_TI, self::TECNICO, self::AUDITOR, self::ESCANER];
    public const array LECTURA_SOFTWARE = [self::ADMIN_TI, self::TECNICO, self::AUDITOR, self::ESCANER];
    public const array ESCRITURA_COMPRAS = [self::ADMIN_TI, self::COMPRAS];
    public const array LECTURA_COMPRAS = [...self::ESCRITURA_COMPRAS, self::AUDITOR];
    /** Qué archivos trae un ingreso (nombre, tipo, fecha): también el técnico que lo confirma. Abrirlos sigue en LECTURA_COMPRAS (la factura muestra precios). */
    public const array LISTADO_ADJUNTOS_INGRESO = [...self::LECTURA_COMPRAS, self::TECNICO];
    public const array LECTURA_CONTROL = [self::ADMIN_TI, self::AUDITOR];
    public const array EXPORTACION_FINANZAS = [self::ADMIN_TI, self::AUDITOR, self::COMPRAS];
    /** Exportes de control (kardex, custodia, movimientos, tomas). */
    public const array EXPORTACION = [self::ADMIN_TI, self::AUDITOR];
    public const array EXPORTACION_COMPRAS = self::EXPORTACION_FINANZAS;
    /** No reciben costos ni montos. */
    public const array SIN_COSTOS = [self::TECNICO, self::CONSULTA, self::ESCANER];
    /** Valor contable (depreciación, valor en libros): quienes ven costos y llevan las finanzas. */
    public const array VALORACION = self::EXPORTACION_FINANZAS;
    /** Valor de un equipo concreto o de la estación de una persona: además del costo, datos de inventario y de personas. */
    public const array VALORACION_DETALLE = self::LECTURA_CONTROL;
    /** Categorías contables (tasa y método de depreciación): TI y Contabilidad. */
    public const array CONFIGURACION_CONTABLE = self::ESCRITURA_COMPRAS;
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
