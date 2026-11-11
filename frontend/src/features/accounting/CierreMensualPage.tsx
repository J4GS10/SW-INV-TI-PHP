import { useCallback, useEffect, useState } from 'react';
import { Box, Button, Chip, MenuItem, Skeleton, Stack, TableCell, TableRow, TextField } from '@mui/material';
import FileDownloadIcon from '@mui/icons-material/FileDownload';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { DataTableShell, PageHeader, PageTabs } from '../../components/ui';
import { usePageTab } from '../../hooks/usePageTab';
import { inventarioService } from '../../api/inventarioService';
import { getApiErrorMessage } from '../../api/errors';
import { useSedes } from '../../hooks/useSedes';
import { usePermissions } from '../../hooks/usePermissions';
import { formatMoney } from '../../utils/currency';
import { formatDate } from '../../utils/dates';
import { useConfirm } from '../../components/confirm-context';
import { normalizeLanguage } from '../../types/language';
import { useLiveRefresh } from '../../hooks/useLiveRefresh';

type Fila = Record<string, string | number | null>;
interface Cierre {
  frozen?: boolean;
  closed_by?: string | null;
  /** Solo en un mes sin cerrar: trabajo sin resolver que ya no entraría en sus cifras. */
  pendientes?: { aprobaciones: number; ingresos: number };
  activos: Fila[]; altas: Fila[]; bajas: Fila[]; consumibles: Fila[]; licencias: Fila[];
  mantenimiento: Fila[];
}

const VISTAS = ['activos', 'altas', 'bajas', 'consumibles', 'mantenimiento', 'licencias'] as const;
type Vista = (typeof VISTAS)[number];

/**
 * Cierre mensual para Contabilidad: valor del inventario al inicio y al cierre
 * del mes, altas y bajas, consumibles valorizados,
 * licencias vigentes y mantenimiento, por sede y moneda (sin convertir).
 */
export const CierreMensualPage = () => {
  const { t, i18n } = useTranslation();
  const confirm = useConfirm();
  const { enqueueSnackbar } = useSnackbar();
  const { sedes } = useSedes();
  const hoy = new Date();
  const { canAdminInventory } = usePermissions();
  const [anio, setAnio] = useState(hoy.getFullYear());
  const [mes, setMes] = useState(hoy.getMonth() + 1);
  const [sede, setSede] = useState<number | ''>('');
  const [datos, setDatos] = useState<Cierre | null>(null);
  const [vista, setVista] = usePageTab(VISTAS);
  const [cargando, setCargando] = useState(true);

  const cargar = useCallback(async () => {
    setCargando(true);
    try {
      setDatos(await inventarioService.cierreMensual(anio, mes, sede === '' ? undefined : sede));
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_loading')), { variant: 'error' });
    } finally {
      setCargando(false);
    }
  }, [anio, mes, sede, enqueueSnackbar, t]);

  useEffect(() => { cargar(); }, [cargar]);
  useLiveRefresh(cargar);

  const dinero = (v: string | number | null, moneda: string | number | null) =>
    (v == null ? '—' : formatMoney(Number(v), String(moneda ?? 'GTQ'), i18n.language));
  const nombreMes = new Date(2000, mes - 1, 1).toLocaleDateString(i18n.language, { month: 'long' });
  // Solo se congela un mes terminado; la API rechaza los demás, así que no se ofrece.
  const mesTerminado = anio < hoy.getFullYear() || (anio === hoy.getFullYear() && mes < hoy.getMonth() + 1);
  const pendientes = datos?.pendientes ?? { aprobaciones: 0, ingresos: 0 };
  const cerrarMes = async () => {
    const seguro = await confirm({
      title: t('month_end.close_confirm_title', { month: nombreMes, year: anio }),
      // Lo que sigue sin resolver ya no entrará en las cifras de este mes: se avisa antes de congelar.
      message: [
        pendientes.ingresos > 0 ? t('month_end.pending_receipts', { count: pendientes.ingresos }) : '',
        pendientes.aprobaciones > 0 ? t('month_end.pending_approvals', { count: pendientes.aprobaciones }) : '',
        t('month_end.close_confirm_message'),
      ].filter(Boolean).join(' '),
      confirmText: t('month_end.close'),
      destructive: true,
    });
    if (!seguro) return;
    try {
      setDatos(await inventarioService.cerrarMes(anio, mes, sede === '' ? undefined : sede));
      enqueueSnackbar(t('month_end.closed_ok'), { variant: 'success' });
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
    }
  };
  const exportar = () => inventarioService.cierreMensualXlsx(anio, mes, sede === '' ? undefined : sede, normalizeLanguage(i18n.language))
    .catch((err) => enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' }));

  type Columna = { k: string; label: string; dinero?: boolean; derecha?: boolean; fecha?: boolean };
  const tablas: Record<Vista, { columnas: Columna[]; filas: Fila[] }> | null = datos && {
    activos: { filas: datos.activos, columnas: [
      { k: 'sede', label: t('inventory_count.site') }, { k: 'moneda', label: t('month_end.currency') },
      { k: 'inicial_cantidad', label: t('month_end.opening_qty'), derecha: true }, { k: 'inicial_valor', label: t('month_end.opening'), dinero: true },
      { k: 'altas_cantidad', label: t('month_end.additions_qty'), derecha: true }, { k: 'altas_valor', label: t('month_end.additions'), dinero: true },
      { k: 'bajas_cantidad', label: t('month_end.disposals_qty'), derecha: true }, { k: 'bajas_valor', label: t('month_end.disposals'), dinero: true },
      { k: 'final_cantidad', label: t('month_end.closing_qty'), derecha: true }, { k: 'final_valor', label: t('month_end.closing'), dinero: true },
      { k: 'final_sin_costo', label: t('month_end.no_cost'), derecha: true },
    ] },
    altas: { filas: datos.altas, columnas: [
      { k: 'codigo', label: t('assets.table.code') }, { k: 'tipo', label: t('assets.table.type') },
      { k: 'fecha_compra', label: t('assets.dialog.purchase_date'), fecha: true }, { k: 'factura', label: t('month_end.invoice') },
      { k: 'costo', label: t('assets.dialog.cost'), dinero: true },
    ] },
    bajas: { filas: datos.bajas, columnas: [
      { k: 'codigo', label: t('assets.table.code') }, { k: 'tipo', label: t('assets.table.type') },
      { k: 'fecha_baja', label: t('month_end.disposal_date'), fecha: true }, { k: 'motivo_baja', label: t('decommission.reason') },
      { k: 'costo', label: t('assets.dialog.cost'), dinero: true },
    ] },
    consumibles: { filas: datos.consumibles, columnas: [
      { k: 'nombre', label: t('consumables.field_name') }, { k: 'sede', label: t('inventory_count.site') },
      { k: 'inicial_cantidad', label: t('month_end.opening_qty'), derecha: true }, { k: 'inicial_valor', label: t('month_end.opening'), dinero: true },
      { k: 'entradas_cantidad', label: t('month_end.in_qty'), derecha: true }, { k: 'entradas_valor', label: t('month_end.in_value'), dinero: true },
      { k: 'salidas_cantidad', label: t('month_end.out_qty'), derecha: true }, { k: 'salidas_valor', label: t('month_end.out_value'), dinero: true },
      { k: 'ajustes_cantidad', label: t('month_end.adjustments'), derecha: true },
      { k: 'final_cantidad', label: t('month_end.closing_qty'), derecha: true }, { k: 'final_valor', label: t('month_end.closing'), dinero: true },
    ] },
    mantenimiento: { filas: datos.mantenimiento, columnas: [
      { k: 'sede', label: t('inventory_count.site') }, { k: 'tickets', label: t('month_end.tickets'), derecha: true },
      { k: 'valor', label: t('month_end.value'), dinero: true },
    ] },
    licencias: { filas: datos.licencias, columnas: [
      { k: 'software', label: t('software.col_software') }, { k: 'cantidad', label: t('month_end.quantity'), derecha: true },
      { k: 'vencimiento', label: t('month_end.expiry'), fecha: true }, { k: 'costo', label: t('assets.dialog.cost'), dinero: true },
    ] },
  };
  const titulos: Record<Vista, string> = {
    activos: t('month_end.assets'), altas: t('month_end.additions_detail'), bajas: t('month_end.disposals_detail'),
    consumibles: t('month_end.consumables'), mantenimiento: t('month_end.maintenance'), licencias: t('month_end.licenses'),
  };
  const actual = tablas?.[vista];
  const aLaDerecha = (c: Columna) => !!(c.dinero || c.derecha);

  const meses = Array.from({ length: 12 }, (_, i) => i + 1);
  return (
    <Box>
      <PageHeader title={t('month_end.title')} subtitle={t('month_end.subtitle')}
        actions={<Stack direction="row" spacing={1} alignItems="center">
          {datos?.frozen && <Chip color="success" label={t('month_end.frozen', { user: datos.closed_by ?? '' })}/>}
          <Button variant={mesTerminado && !datos?.frozen && canAdminInventory ? 'outlined' : 'contained'} startIcon={<FileDownloadIcon/>} onClick={exportar} disabled={!datos}>{t('month_end.export')}</Button>
          {mesTerminado && !datos?.frozen && canAdminInventory && <Button variant="contained" onClick={() => { void cerrarMes(); }}>{t('month_end.close')}</Button>}</Stack>}/>
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} sx={{ mb: 2 }}>
        <TextField select size="small" label={t('month_end.month')} value={mes} onChange={(e) => setMes(Number(e.target.value))} sx={{ minWidth: 160 }}>
          {meses.map((m) => <MenuItem key={m} value={m}>{new Date(2000, m - 1, 1).toLocaleDateString(i18n.language, { month: 'long' })}</MenuItem>)}
        </TextField>
        <TextField type="number" size="small" label={t('month_end.year')} value={anio} onChange={(e) => setAnio(Number(e.target.value))}
          inputProps={{ min: 2000, max: 2100 }} sx={{ width: 120 }}/>
        <TextField select size="small" label={t('inventory_count.site')} value={sede}
          onChange={(e) => setSede(e.target.value === '' ? '' : Number(e.target.value))} sx={{ minWidth: 200 }}>
          <MenuItem value="">{t('month_end.all_sites')}</MenuItem>
          {sedes.map((s) => <MenuItem key={s.SED_Sede} value={s.SED_Sede}>{s.SED_Nombre}</MenuItem>)}
        </TextField>
      </Stack>
      <PageTabs
        value={vista}
        onChange={setVista}
        tabs={VISTAS.map((v) => ({ value: v, label: titulos[v], count: tablas?.[v].filas.length }))}
      />
      {cargando || !actual ? <Skeleton variant="rounded" height={240}/> : (
        <DataTableShell
          columns={actual.columnas.map((c) => ({ key: c.k, label: c.label, align: aLaDerecha(c) ? 'right' as const : 'left' as const }))}
          rows={actual.filas}
          emptyTitle={t('month_end.empty')}
          renderRow={(f, i) => (
            <TableRow key={i} hover>
              {actual.columnas.map((c) => (
                <TableCell key={c.k} align={aLaDerecha(c) ? 'right' : 'left'} sx={aLaDerecha(c) ? { fontVariantNumeric: 'tabular-nums' } : undefined}>
                  {c.dinero ? dinero(f[c.k], f.moneda) : c.fecha ? formatDate(f[c.k] == null ? null : String(f[c.k]), i18n.language) : (f[c.k] ?? '—')}
                </TableCell>
              ))}
            </TableRow>
          )}
        />
      )}
    </Box>
  );
};
