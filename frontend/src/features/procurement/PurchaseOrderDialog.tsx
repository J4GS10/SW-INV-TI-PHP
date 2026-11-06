import { useEffect, useMemo, useState } from 'react';
import { DialogTitle, DialogContent, DialogActions, Button, TextField, CircularProgress, Stack, MenuItem, IconButton, Typography, Box } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import DeleteIcon from '@mui/icons-material/DeleteOutline';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { getApiErrorDetail, getApiErrorMessage } from '../../api/errors';
import { procurementService } from './procurementService';
import { Proveedor, LineaOrden, OrdenCompra, TIPOS_LINEA, TipoLinea } from '../../types/procurement';
import { consumableService } from '../consumables/consumableService';
import type { Consumible } from '../../types/consumables';
import { hoyLocal } from '../../utils/dates';
import { SectionCard, SedeField, SuggestionHint } from '../../components/ui';
import { useSedes } from '../../hooks/useSedes';
import { useSuggestion } from '../../hooks/useSuggestion';
import { CURRENCIES, CURRENCY_LABEL_KEY, DEFAULT_CURRENCY, isCurrency } from '../../utils/currency';
import type { Currency } from '../../utils/currency';
import { Dialog } from '../../components/ui/Dialog';
import { DateField } from '../../components/ui/DateField';
interface Props {
    open: boolean;
    onClose: (refresh?: boolean) => void;
    /** Órdenes ya cargadas en la página: sugieren la moneda habitual del proveedor. */
    recentOrders?: OrdenCompra[];
    /** Líneas propuestas (p. ej. reponer un consumible bajo el mínimo). */
    lineasIniciales?: LineaOrden[];
}
const emptyLine = (): LineaOrden => ({ OCL_Descripcion: '', OCL_Cantidad: 1, OCL_Precio_Unitario: '0', OCL_Tipo: 'ACTIVO' });
export const PurchaseOrderDialog = ({ open, onClose, recentOrders, lineasIniciales }: Props) => {
    const { t } = useTranslation();
    const { enqueueSnackbar } = useSnackbar();
    const [proveedores, setProveedores] = useState<Proveedor[]>([]);
    const [provId, setProvId] = useState<number | ''>('');
    const [numero, setNumero] = useState('');
    const [fecha, setFecha] = useState(() => hoyLocal());
    const [consumibles, setConsumibles] = useState<Consumible[]>([]);
    const [moneda, setMoneda] = useState<string>(DEFAULT_CURRENCY);
    const [notas, setNotas] = useState('');
    const [lineas, setLineas] = useState<LineaOrden[]>([emptyLine()]);
    const [loading, setLoading] = useState(false);
    // Sede que recibe la compra: los activos recibidos nacen en ella.
    const [sede, setSede] = useState<number | ''>('');
    const { sedes, loading: loadingSedes, unica } = useSedes(open);
    const sedeHint = useSuggestion<number | ''>({
        current: sede,
        empty: '',
        enabled: open,
        apply: setSede,
        suggestion: loadingSedes ? undefined : unica
            ? { value: unica.SED_Sede, label: unica.SED_Nombre, reason: t('scope.reasons.only_sede') }
            : null,
    });
    useEffect(() => {
        if (!open)
            return;
        procurementService.listProveedores(true).then(setProveedores).catch(() => setProveedores([]));
        consumableService.list().then(setConsumibles).catch(() => setConsumibles([]));
        setProvId('');
        setNumero('');
        setFecha(hoyLocal());
        setMoneda(DEFAULT_CURRENCY);
        setNotas('');
        setLineas(lineasIniciales?.length ? lineasIniciales.map((l) => ({ ...l })) : [emptyLine()]);
        setSede('');
    }, [open, lineasIniciales]);
    // Moneda de la última orden del proveedor (si las órdenes ya están cargadas).
    const lastOrder = useMemo(() => {
        if (!provId || !recentOrders)
            return null;
        return recentOrders
            .filter((o) => o.PRV_Proveedor === provId && isCurrency(o.OCO_Moneda))
            .reduce<OrdenCompra | null>((latest, o) => (!latest || `${o.OCO_Fecha}${o.created_at}` > `${latest.OCO_Fecha}${latest.created_at}` ? o : latest), null);
    }, [provId, recentOrders]);
    const currencyHint = useSuggestion<string>({
        current: moneda,
        empty: DEFAULT_CURRENCY,
        apply: setMoneda,
        suggestion: lastOrder ? {
            value: lastOrder.OCO_Moneda,
            label: t(CURRENCY_LABEL_KEY[lastOrder.OCO_Moneda as Currency]),
            reason: t('suggestions.reasons.currency_last_order', { number: lastOrder.OCO_Numero }),
        } : null,
    });
    const updateLine = (i: number, patch: Partial<LineaOrden>) => {
        setLineas((prev) => prev.map((l, idx) => (idx === i ? { ...l, ...patch } : l)));
    };
    const total = lineas.reduce((acc, l) => acc + Number(l.OCL_Cantidad || 0) * Number(l.OCL_Precio_Unitario || 0), 0);
    const handleSubmit = async () => {
        if (!provId || !numero.trim() || sede === '') {
            enqueueSnackbar(t('procurement.err_required'), { variant: 'warning' });
            return;
        }
        const validas = lineas.filter((l) => l.OCL_Descripcion.trim());
        setLoading(true);
        try {
            await procurementService.createOrden({
                OCO_Numero: numero.trim(),
                OCO_Fecha: fecha,
                OCO_Moneda: moneda,
                OCO_Notas: notas || undefined,
                PRV_Proveedor: Number(provId),
                SED_Sede: Number(sede),
                lineas: validas.map((l) => ({
                    OCL_Descripcion: l.OCL_Descripcion.trim(),
                    OCL_Cantidad: Number(l.OCL_Cantidad),
                    OCL_Precio_Unitario: String(l.OCL_Precio_Unitario),
                    OCL_Tipo: l.OCL_Tipo ?? undefined,
                    CON_Consumible: l.OCL_Tipo === 'CONSUMIBLE' ? l.CON_Consumible ?? undefined : undefined,
                })),
            });
            enqueueSnackbar(t('procurement.order_created'), { variant: 'success' });
            onClose(true);
        }
        catch (err: unknown) {
            const code = getApiErrorDetail(err);
            enqueueSnackbar(code === 'PURCHASE_ORDER_NUMBER_EXISTS'
                ? t('procurement.err_number_exists')
                : getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
        }
        finally {
            setLoading(false);
        }
    };
    return (<Dialog open={open} onClose={() => onClose()} maxWidth="md" fullWidth>
      <DialogTitle>{t('procurement.new_order')}</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ mt: 1 }}>
          <SectionCard title={t('procurement.sections.order_data')}>
            <Stack spacing={2}>
              <Stack direction="row" spacing={2}>
                <TextField select fullWidth size="small" label={t('procurement.provider')} value={provId} onChange={(e) => {
                  const value = e.target.value;
                  setProvId(value === '' ? '' : Number(value));
              }}>
                  {proveedores.map((p) => (<MenuItem key={p.PRV_Proveedor} value={p.PRV_Proveedor}>{p.PRV_Nombre}</MenuItem>))}
                </TextField>
                <TextField fullWidth size="small" label={t('procurement.order_number')} value={numero} onChange={(e) => setNumero(e.target.value)}/>
              </Stack>
              <Stack direction="row" spacing={2}>
                <DateField size="small" label={t('procurement.date')} value={fecha} onChange={(e) => setFecha(e.target.value)} InputLabelProps={{ shrink: true }}/>
                <TextField select size="small" label={t('procurement.currency')} value={moneda} onChange={(e) => setMoneda(e.target.value)} sx={{ width: 170 }}>
                  {CURRENCIES.map((c) => <MenuItem key={c} value={c}>{t(CURRENCY_LABEL_KEY[c])}</MenuItem>)}
                </TextField>
              </Stack>
              {currencyHint && (<Box sx={{ mt: -1.5 }}>
                  <SuggestionHint hint={currencyHint}/>
                </Box>)}
              <Box>
                <SedeField size="small" required value={sede} onChange={setSede} sedes={sedes} loading={loadingSedes} helperText={sedeHint ? undefined : t('scope.order_sede_help')}/>
                <SuggestionHint hint={sedeHint}/>
              </Box>
            </Stack>
          </SectionCard>
          <SectionCard title={t('procurement.lines')}>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>{t('procurement.line_type_help')}</Typography>
            {lineas.map((l, i) => (<Stack key={i} direction={{ xs: 'column', md: 'row' }} spacing={1} sx={{ mb: 1.5 }} alignItems={{ md: 'center' }}>
                <TextField select size="small" label={t('procurement.line_type')} value={l.OCL_Tipo ?? 'ACTIVO'} onChange={(e) => updateLine(i, { OCL_Tipo: e.target.value as TipoLinea, CON_Consumible: null })} sx={{ minWidth: 150 }}>
                  {TIPOS_LINEA.map((tp) => <MenuItem key={tp} value={tp}>{t(`procurement.line_types.${tp}`)}</MenuItem>)}
                </TextField>
                {l.OCL_Tipo === 'CONSUMIBLE' && (<TextField select size="small" label={t('menu.consumables')} value={l.CON_Consumible ?? ''} onChange={(e) => {
                    const id = Number(e.target.value);
                    const con = consumibles.find((c) => c.CON_Consumible === id);
                    updateLine(i, { CON_Consumible: id, OCL_Descripcion: l.OCL_Descripcion || con?.CON_Nombre || '' });
                }} sx={{ minWidth: 180 }}>
                    {consumibles.map((c) => <MenuItem key={c.CON_Consumible} value={c.CON_Consumible}>{c.CON_Nombre}</MenuItem>)}
                  </TextField>)}
                <TextField size="small" fullWidth label={t('procurement.line_desc')} value={l.OCL_Descripcion} onChange={(e) => updateLine(i, { OCL_Descripcion: e.target.value })}/>
                <TextField size="small" type="number" label={t('procurement.line_qty')} value={l.OCL_Cantidad} onChange={(e) => updateLine(i, { OCL_Cantidad: Number(e.target.value) })} sx={{ width: 90 }}/>
                <TextField size="small" type="number" label={t('procurement.line_price')} value={l.OCL_Precio_Unitario} onChange={(e) => updateLine(i, { OCL_Precio_Unitario: e.target.value })} sx={{ width: 120 }}/>
                <Typography variant="body2" sx={{ width: 100, textAlign: 'right' }}>
                  {(Number(l.OCL_Cantidad || 0) * Number(l.OCL_Precio_Unitario || 0)).toFixed(2)}
                </Typography>
                <IconButton size="small" color="error" onClick={() => setLineas((p) => p.filter((_, idx) => idx !== i))} disabled={lineas.length === 1}>
                  <DeleteIcon fontSize="small"/>
                </IconButton>
              </Stack>))}
            <Button size="small" startIcon={<AddIcon />} onClick={() => setLineas((p) => [...p, emptyLine()])}>
              {t('procurement.add_line')}
            </Button>
            <Box sx={{ textAlign: 'right', mt: 1 }}>
              <Typography variant="h6">
                {t('procurement.total')}: {total.toFixed(2)} {moneda}
              </Typography>
            </Box>
          </SectionCard>
          <SectionCard title={t('procurement.sections.notes')}>
            <TextField fullWidth size="small" label={t('procurement.notes')} value={notas} onChange={(e) => setNotas(e.target.value)}/>
          </SectionCard>
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={() => onClose()} color="inherit">{t('common.cancel')}</Button>
        <Button variant="contained" onClick={handleSubmit} disabled={loading}>
          {loading ? <CircularProgress size={24}/> : t('common.save')}
        </Button>
      </DialogActions>
    </Dialog>);
};
