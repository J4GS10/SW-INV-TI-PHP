<?php

declare(strict_types=1);

namespace App\Consola;

/**
 * What `app:demo-data` loads: a small but complete company to try every screen. The two sites are the ones
 * `app:test-accounts` creates. Costs are in quetzales unless stated; purchase dates go from 2022 to 2026 so that
 * some assets are already fully depreciated, others half-way and others just bought.
 */
final class DatosMuestra
{
    /** Site => [building, areas]. */
    public const array SEDES = [
        'Sede Prueba Norte' => ['Torre Norte', ['Recepción', 'Finanzas', 'Operaciones', 'Tecnología', 'Centro de datos', 'Bodega TI Norte']],
        'Sede Prueba Sur' => ['Edificio Sur', ['Ventas', 'Recursos Humanos', 'Centro de datos Sur', 'Bodega TI Sur']],
    ];

    /** Name => cost centre. */
    public const array DEPARTAMENTOS = [
        'Finanzas' => 'FIN-100', 'Recursos Humanos' => 'RH-200', 'Operaciones' => 'OPE-300', 'Ventas' => 'VEN-400', 'Tecnología' => 'TEC-500',
    ];

    /** Name => is a management position. */
    public const array CARGOS = ['Analista' => false, 'Asistente' => false, 'Coordinador' => true, 'Gerente' => true];

    /** [first name, last name, second last name, department, position, site, area, workstation]; workstation: LAP or PC. */
    public const array PERSONAS = [
        ['María', 'González', 'López', 'Finanzas', 'Gerente', 'Sede Prueba Norte', 'Finanzas', 'LAP'],
        ['Carlos', 'Ramírez', 'Pérez', 'Finanzas', 'Analista', 'Sede Prueba Norte', 'Finanzas', 'PC'],
        ['Lucía', 'Morales', 'Castillo', 'Finanzas', 'Asistente', 'Sede Prueba Norte', 'Finanzas', 'PC'],
        ['José', 'Hernández', 'García', 'Operaciones', 'Coordinador', 'Sede Prueba Norte', 'Operaciones', 'LAP'],
        ['Ana', 'Martínez', 'Ruiz', 'Operaciones', 'Analista', 'Sede Prueba Norte', 'Operaciones', 'LAP'],
        ['Diego', 'Castro', 'Mendoza', 'Tecnología', 'Gerente', 'Sede Prueba Norte', 'Tecnología', 'LAP'],
        ['Sofía', 'Rodríguez', 'Juárez', 'Tecnología', 'Analista', 'Sede Prueba Norte', 'Tecnología', 'LAP'],
        ['Andrea', 'López', 'Barrios', 'Recursos Humanos', 'Coordinador', 'Sede Prueba Sur', 'Recursos Humanos', 'LAP'],
        ['Pedro', 'Sánchez', 'Ortiz', 'Recursos Humanos', 'Asistente', 'Sede Prueba Sur', 'Recursos Humanos', 'PC'],
        ['Valeria', 'Díaz', 'Cifuentes', 'Ventas', 'Gerente', 'Sede Prueba Sur', 'Ventas', 'LAP'],
        ['Jorge', 'Pérez', 'Villatoro', 'Ventas', 'Analista', 'Sede Prueba Sur', 'Ventas', 'LAP'],
        ['Gabriela', 'Flores', 'Arévalo', 'Ventas', 'Analista', 'Sede Prueba Sur', 'Ventas', 'LAP'],
        // Sin equipo todavía: para probar la entrega de puesto y el alta de empleado.
        ['Fernando', 'Reyes', 'Galindo', 'Operaciones', 'Asistente', 'Sede Prueba Norte', 'Operaciones', null],
        ['Daniela', 'Cruz', 'Monzón', 'Ventas', 'Asistente', 'Sede Prueba Sur', 'Ventas', null],
    ];

    public const array PROVEEDORES = ['Distribuidora Tecnológica, S.A.', 'Computadoras de Centroamérica', 'Suministros de Oficina GT'];

    /** Key => [name, code prefix, class, accounting category (Guatemala)]. */
    public const array TIPOS = [
        'LAP' => ['Laptop', 'LAP', 'COMPUTO', 'GT_COMPUTO'],
        'PC' => ['Computadora de escritorio', 'PC', 'COMPUTO', 'GT_COMPUTO'],
        'MON' => ['Monitor', 'MON', 'PERIFERICO', 'GT_COMPUTO'],
        'TEC' => ['Teclado', 'TEC', 'PERIFERICO', 'GT_COMPUTO'],
        'MOU' => ['Mouse', 'MOU', 'PERIFERICO', 'GT_COMPUTO'],
        'RAM' => ['Memoria RAM', 'RAM', 'COMPONENTE', 'GT_COMPUTO'],
        'SSD' => ['Disco SSD', 'SSD', 'COMPONENTE', 'GT_COMPUTO'],
        'IMP' => ['Impresora', 'IMP', 'IMPRESION', 'GT_COMPUTO'],
        'SW' => ['Switch de red', 'SW', 'RED', 'GT_COMPUTO'],
        'UPS' => ['UPS', 'UPS', 'ENERGIA', 'GT_MAQUINARIA'],
    ];

    /** Key => [brand, model, type, launch year, unit cost, currency]. */
    public const array MODELOS = [
        'LAP_DELL' => ['Dell', 'Latitude 5440', 'LAP', 2023, '11500.00', 'GTQ'],
        'LAP_LENOVO' => ['Lenovo', 'ThinkPad T14 Gen 4', 'LAP', 2023, '12200.00', 'GTQ'],
        'LAP_HP' => ['HP', 'EliteBook 840 G9', 'LAP', 2022, '10900.00', 'GTQ'],
        'PC_DELL' => ['Dell', 'OptiPlex 7010', 'PC', 2023, '8200.00', 'GTQ'],
        'MON_DELL' => ['Dell', 'P2423', 'MON', 2022, '1850.00', 'GTQ'],
        'TEC_LOGI' => ['Logitech', 'K120', 'TEC', 2021, '135.00', 'GTQ'],
        'MOU_LOGI' => ['Logitech', 'M90', 'MOU', 2021, '85.00', 'GTQ'],
        'RAM_KING' => ['Kingston', 'Fury Impact 16 GB DDR5', 'RAM', 2023, '720.00', 'GTQ'],
        'SSD_SAMS' => ['Samsung', '990 PRO 1 TB', 'SSD', 2023, '1150.00', 'GTQ'],
        'IMP_HP' => ['HP', 'LaserJet Pro M404dn', 'IMP', 2021, '3900.00', 'GTQ'],
        'SW_CISCO' => ['Cisco', 'CBS250-24T-4G', 'SW', 2022, '465.00', 'USD'],
        'UPS_APC' => ['APC', 'Back-UPS Pro 1500', 'UPS', 2022, '2600.00', 'GTQ'],
    ];

    /** Purchase dates the workstations cycle through (some fully depreciated by now, some new). */
    public const array FECHAS = ['2022-04-18', '2023-02-06', '2023-09-11', '2024-03-04', '2024-10-21', '2025-05-12', '2026-02-09'];

    /** Shared equipment per site: [model, area, quantity]. */
    public const array COMPARTIDOS = [
        'Sede Prueba Norte' => [['IMP_HP', 'Finanzas', 1], ['IMP_HP', 'Operaciones', 1], ['SW_CISCO', 'Centro de datos', 2], ['UPS_APC', 'Centro de datos', 1]],
        'Sede Prueba Sur' => [['IMP_HP', 'Ventas', 1], ['SW_CISCO', 'Centro de datos Sur', 1], ['UPS_APC', 'Centro de datos Sur', 1]],
    ];

    /** In storage, ready to hand over: [model, site, quantity]. */
    public const array EN_BODEGA = [
        ['LAP_LENOVO', 'Sede Prueba Norte', 2], ['MON_DELL', 'Sede Prueba Norte', 2], ['LAP_DELL', 'Sede Prueba Sur', 1],
        ['RAM_KING', 'Sede Prueba Norte', 2], ['TEC_LOGI', 'Sede Prueba Sur', 3], ['MOU_LOGI', 'Sede Prueba Sur', 3],
    ];

    /** [name, unit, minimum stock, site, quantity in, unit price]. */
    public const array CONSUMIBLES = [
        ['Tóner HP 58A', 'unidad', 4, 'Sede Prueba Norte', 10, '650.00'],
        ['Papel bond carta (resma)', 'resma', 20, 'Sede Prueba Norte', 60, '42.50'],
        ['Cable de red Cat6 2 m', 'unidad', 10, 'Sede Prueba Sur', 40, '28.00'],
        // Por debajo del mínimo: para ver la alerta de existencias bajas.
        ['Tinta Epson 664 negra', 'unidad', 5, 'Sede Prueba Sur', 3, '95.00'],
    ];
}
