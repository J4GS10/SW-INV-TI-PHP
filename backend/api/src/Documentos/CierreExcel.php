<?php

declare(strict_types=1);

namespace App\Documentos;

use App\Inventario\CierreMensual;

/**
 * Libro del cierre mensual para Contabilidad: una hoja por sección (activos por
 * sede y moneda, altas, bajas, consumibles, compras recibidas, licencias vigentes
 * y mantenimiento), con valores numéricos y fechas nativas de Excel.
 */
final readonly class CierreExcel
{
    private const array TEXTOS = [
        'es' => [
            'hoja_activos' => 'Activos', 'hoja_altas' => 'Altas del mes', 'hoja_bajas' => 'Bajas del mes', 'hoja_consumibles' => 'Consumibles',
            'hoja_compras' => 'Compras recibidas', 'hoja_licencias' => 'Licencias vigentes', 'hoja_mantenimiento' => 'Mantenimiento',
            'col_sede' => 'Sede', 'col_moneda' => 'Moneda', 'col_ini_n' => 'Cantidad inicial', 'col_ini_v' => 'Valor inicial', 'col_altas_n' => 'Altas',
            'col_altas_v' => 'Valor de altas', 'col_bajas_n' => 'Bajas', 'col_bajas_v' => 'Valor de bajas', 'col_fin_n' => 'Cantidad final',
            'col_fin_v' => 'Valor final', 'col_sin_costo' => 'Sin costo registrado', 'col_codigo' => 'Código', 'col_serie' => 'Serie', 'col_tipo' => 'Tipo',
            'col_modelo' => 'Modelo', 'col_fecha_compra' => 'Fecha de compra', 'col_fecha_baja' => 'Fecha de baja', 'col_motivo_baja' => 'Motivo de baja',
            'col_costo' => 'Costo', 'col_factura' => 'Factura', 'col_nombre' => 'Consumible', 'col_ent_n' => 'Entradas', 'col_ent_v' => 'Valor de entradas',
            'col_sal_n' => 'Salidas', 'col_sal_v' => 'Valor de salidas', 'col_aju_n' => 'Ajustes (neto)', 'col_orden' => 'Orden', 'col_proveedor' => 'Proveedor',
            'col_fecha' => 'Fecha', 'col_valor' => 'Valor', 'col_software' => 'Software', 'col_cantidad' => 'Cantidad', 'col_vencimiento' => 'Vencimiento',
            'col_tickets' => 'Tickets cerrados', 'archivo' => 'cierre',
        ],
        'en' => [
            'hoja_activos' => 'Assets', 'hoja_altas' => 'Additions', 'hoja_bajas' => 'Disposals', 'hoja_consumibles' => 'Consumables',
            'hoja_compras' => 'Purchases received', 'hoja_licencias' => 'Active licenses', 'hoja_mantenimiento' => 'Maintenance',
            'col_sede' => 'Site', 'col_moneda' => 'Currency', 'col_ini_n' => 'Opening quantity', 'col_ini_v' => 'Opening value', 'col_altas_n' => 'Additions',
            'col_altas_v' => 'Additions value', 'col_bajas_n' => 'Disposals', 'col_bajas_v' => 'Disposals value', 'col_fin_n' => 'Closing quantity',
            'col_fin_v' => 'Closing value', 'col_sin_costo' => 'Without recorded cost', 'col_codigo' => 'Code', 'col_serie' => 'Serial', 'col_tipo' => 'Type',
            'col_modelo' => 'Model', 'col_fecha_compra' => 'Purchase date', 'col_fecha_baja' => 'Disposal date', 'col_motivo_baja' => 'Disposal reason',
            'col_costo' => 'Cost', 'col_factura' => 'Invoice', 'col_nombre' => 'Consumable', 'col_ent_n' => 'Inflows', 'col_ent_v' => 'Inflows value',
            'col_sal_n' => 'Outflows', 'col_sal_v' => 'Outflows value', 'col_aju_n' => 'Adjustments (net)', 'col_orden' => 'Order', 'col_proveedor' => 'Supplier',
            'col_fecha' => 'Date', 'col_valor' => 'Value', 'col_software' => 'Software', 'col_cantidad' => 'Quantity', 'col_vencimiento' => 'Expiry',
            'col_tickets' => 'Closed tickets', 'archivo' => 'month_end',
        ],
        'it' => [
            'hoja_activos' => 'Asset', 'hoja_altas' => 'Acquisizioni del mese', 'hoja_bajas' => 'Dismissioni del mese', 'hoja_consumibles' => 'Materiali di consumo',
            'hoja_compras' => 'Acquisti ricevuti', 'hoja_licencias' => 'Licenze attive', 'hoja_mantenimiento' => 'Manutenzione',
            'col_sede' => 'Sede', 'col_moneda' => 'Valuta', 'col_ini_n' => 'Quantità iniziale', 'col_ini_v' => 'Valore iniziale', 'col_altas_n' => 'Acquisizioni',
            'col_altas_v' => 'Valore acquisizioni', 'col_bajas_n' => 'Dismissioni', 'col_bajas_v' => 'Valore dismissioni', 'col_fin_n' => 'Quantità finale',
            'col_fin_v' => 'Valore finale', 'col_sin_costo' => 'Senza costo registrato', 'col_codigo' => 'Codice', 'col_serie' => 'Seriale', 'col_tipo' => 'Tipo',
            'col_modelo' => 'Modello', 'col_fecha_compra' => 'Data di acquisto', 'col_fecha_baja' => 'Data di dismissione',
            'col_motivo_baja' => 'Motivo della dismissione', 'col_costo' => 'Costo', 'col_factura' => 'Fattura', 'col_nombre' => 'Materiale',
            'col_ent_n' => 'Entrate', 'col_ent_v' => 'Valore entrate', 'col_sal_n' => 'Uscite', 'col_sal_v' => 'Valore uscite',
            'col_aju_n' => 'Rettifiche (netto)', 'col_orden' => 'Ordine', 'col_proveedor' => 'Fornitore', 'col_fecha' => 'Data', 'col_valor' => 'Valore',
            'col_software' => 'Software', 'col_cantidad' => 'Quantità', 'col_vencimiento' => 'Scadenza', 'col_tickets' => 'Ticket chiusi',
            'archivo' => 'chiusura_mensile',
        ],
    ];

    /** Hoja => [sección de los datos, [campo => columna]]. */
    private const array HOJAS = [
        'hoja_activos' => ['activos', ['sede' => 'col_sede', 'moneda' => 'col_moneda', 'inicial_cantidad' => 'col_ini_n', 'inicial_valor' => 'col_ini_v',
            'altas_cantidad' => 'col_altas_n', 'altas_valor' => 'col_altas_v', 'bajas_cantidad' => 'col_bajas_n', 'bajas_valor' => 'col_bajas_v',
            'final_cantidad' => 'col_fin_n', 'final_valor' => 'col_fin_v', 'final_sin_costo' => 'col_sin_costo']],
        'hoja_altas' => ['altas', self::DETALLE],
        'hoja_bajas' => ['bajas', self::DETALLE],
        'hoja_consumibles' => ['consumibles', ['nombre' => 'col_nombre', 'sede' => 'col_sede', 'moneda' => 'col_moneda', 'inicial_cantidad' => 'col_ini_n',
            'inicial_valor' => 'col_ini_v', 'entradas_cantidad' => 'col_ent_n', 'entradas_valor' => 'col_ent_v', 'salidas_cantidad' => 'col_sal_n',
            'salidas_valor' => 'col_sal_v', 'ajustes_cantidad' => 'col_aju_n', 'final_cantidad' => 'col_fin_n', 'final_valor' => 'col_fin_v']],
        'hoja_compras' => ['compras', ['orden' => 'col_orden', 'proveedor' => 'col_proveedor', 'sede' => 'col_sede', 'fecha' => 'col_fecha',
            'moneda' => 'col_moneda', 'valor' => 'col_valor']],
        'hoja_licencias' => ['licencias', ['software' => 'col_software', 'cantidad' => 'col_cantidad', 'costo' => 'col_costo', 'moneda' => 'col_moneda',
            'vencimiento' => 'col_vencimiento']],
        'hoja_mantenimiento' => ['mantenimiento', ['sede' => 'col_sede', 'moneda' => 'col_moneda', 'tickets' => 'col_tickets', 'valor' => 'col_valor']],
    ];
    private const array DETALLE = ['codigo' => 'col_codigo', 'serie' => 'col_serie', 'tipo' => 'col_tipo', 'modelo' => 'col_modelo', 'sede' => 'col_sede',
        'fecha_compra' => 'col_fecha_compra', 'fecha_baja' => 'col_fecha_baja', 'motivo_baja' => 'col_motivo_baja', 'costo' => 'col_costo',
        'moneda' => 'col_moneda', 'factura' => 'col_factura'];
    /** Campos de día (DATE) y de momento (TIMESTAMP en UTC). */
    private const array DIAS = ['fecha_compra', 'fecha_baja', 'vencimiento'];
    private const array MOMENTOS = ['fecha'];

    public function __construct(private CierreMensual $cierre)
    {
    }

    public static function nombreArchivo(int $anio, int $mes, Idioma $idioma): string
    {
        return \sprintf('%s_%d%02d.xlsx', $idioma->de(self::TEXTOS)['archivo'], $anio, $mes);
    }

    public function generar(int $anio, int $mes, ?int $sede, Idioma $idioma): string
    {
        $datos = $this->cierre->cierre($anio, $mes, $sede);
        $t = $idioma->de(self::TEXTOS);
        $libro = new LibroExcel();
        foreach (self::HOJAS as $nombre => [$seccion, $columnas]) {
            $encabezados = array_map(static fn (string $c): string => $t[$c], array_values($columnas));
            $hoja = $libro->hoja($t[$nombre], $encabezados);
            foreach ($datos[$seccion] as $fila) {
                $libro->fila($hoja, array_map(static fn (string $campo): mixed => self::celda($campo, $fila[$campo] ?? null), array_keys($columnas)));
            }
            LibroExcel::anchos($hoja, array_map(static fn (string $e): int => max(12, mb_strlen($e) + 2), $encabezados));
        }

        return $libro->contenido();
    }

    /** Valores y costos como número; fechas como fecha de Excel; el resto tal cual. */
    private static function celda(string $campo, mixed $valor): mixed
    {
        return match (true) {
            null === $valor => null,
            (str_ends_with($campo, 'valor') || 'costo' === $campo) && \is_string($valor) && is_numeric($valor) => (float) $valor,
            \in_array($campo, self::DIAS, true) && \is_string($valor) => DiaCalendario::desde($valor),
            \in_array($campo, self::MOMENTOS, true) && \is_string($valor) => new \DateTimeImmutable(substr($valor, 0, 19), new \DateTimeZone('UTC')), // sin microsegundos
            default => $valor,
        };
    }
}
