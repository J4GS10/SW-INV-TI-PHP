import AssignmentIndIcon from '@mui/icons-material/AssignmentInd';
import { useWorkspace } from '../../context/workspace-context';
import { usePermissions } from '../../hooks/usePermissions';
import { useEffect, useMemo, useState } from 'react';
import { Alert, Box, Button, Checkbox, Chip, CircularProgress, DialogActions, DialogContent, DialogTitle, FormControlLabel, List, ListItem, ListItemText, MenuItem, Stack, TextField, Typography } from '@mui/material';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import QrCode2Icon from '@mui/icons-material/QrCode2';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import client from '../../api/axios';
import { getApiErrorCode, getApiErrorMessage, getApiErrorParams } from '../../api/errors';
import {
    procurementService, RecibirOrdenActivoPayload, RecibirOrdenConsumiblePayload, RecibirOrdenLicenciaPayload,
    RecibirOrdenPayload, RecibirOrdenResponse,
} from './procurementService';
import { consumableService } from '../consumables/consumableService';
import { assetService } from '../assets/assetService';
import { softwareService } from '../software/softwareService';
import { OrdenCompra, LineaOrden, TipoLinea } from '../../types/procurement';
import { Consumible } from '../../types/consumables';
import { TipoActivo } from '../../types/inventory';
import { Software, TipoLicencia } from '../../types/software';
import { MoneyField, SectionCard } from '../../components/ui';
import { normalizeLanguage } from '../../types/language';
import { hoyLocal } from '../../utils/dates';
import { typeLabel } from '../../utils/catalogLabels';
import { estadoOrdenLabel } from './orderStates';
import { Dialog } from '../../components/ui/Dialog';
import { DateField } from '../../components/ui/DateField';

/** Qué se hace con cada línea en esta entrega. */
type Modo = 'omitir' | 'consumible' | 'activo' | 'licencia' | 'otro';
const MODO_DE_TIPO: Record<TipoLinea, Modo> = {
    ACTIVO: 'activo', CONSUMIBLE: 'consumible', LICENCIA: 'licencia', OTRO: 'otro',
};
interface LineConfig {
    modo: Modo;
    con_id?: number | '';
    cantidad?: number;
    precio?: string;
    series?: string;
    hostnames?: string;
    tipo?: number | '';
    modelo?: number | '';
    fecha?: string;
    garantia?: string;
    software?: number | '';
    tipoLicencia?: number | '';
    vencimientoLicencia?: string;
    clavesLicencia?: string;
    referenciasLicencia?: string;
    /** Justificación de sobrante o de precio distinto al de la orden. */
    motivo?: string;
    aceptarSobrante?: boolean;
}
interface ModeloFlat {
    MOD_Modelo: number;
    MOD_Nombre: string;
    MAR_Nombre?: string | null;
    TAC_Tipo_Activo?: number | null;
}
interface Props {
    open: boolean;
    orden: OrdenCompra | null;
    onClose: (refresh?: boolean) => void;
}
const pendienteDe = (l: LineaOrden) => l.OCL_Pendiente ?? Math.max(l.OCL_Cantidad - (l.OCL_Cantidad_Recibida ?? 0), 0);
const splitLines = (value?: string) => (value ?? '').split(/\r?\n/).map((line) => line.trim()).filter(Boolean);

/**
 * Registra una ENTREGA del proveedor. Solo muestra lo pendiente; permite
 * recibir en parcialidades, registrar el precio real facturado y el documento
 * (guía o factura). Si la entrega no cuadra con la orden (sobrante o precio
 * distinto) el sistema pide la justificación en vez de rechazar sin más, y al
 * final resume qué entró, qué falta y qué sigue.
 */
export const ReceiveOrderDialog = ({ open, orden, onClose }: Props) => {
    const { t, i18n } = useTranslation();
    const { enqueueSnackbar } = useSnackbar();
    const { abrirEntrega } = useWorkspace();
    const { canOperate } = usePermissions();
    const [consumibles, setConsumibles] = useState<Consumible[]>([]);
    const [tipos, setTipos] = useState<TipoActivo[]>([]);
    const [modelos, setModelos] = useState<ModeloFlat[]>([]);
    const [software, setSoftware] = useState<Software[]>([]);
    const [tiposLicencia, setTiposLicencia] = useState<TipoLicencia[]>([]);
    const [cfg, setCfg] = useState<Record<number, LineConfig>>({});
    const [documento, setDocumento] = useState('');
    const [observacion, setObservacion] = useState('');
    const [loading, setLoading] = useState(false);
    // Línea señalada por el servidor (sobrante o precio sin justificar).
    const [lineaConError, setLineaConError] = useState<number | null>(null);
    const [resultado, setResultado] = useState<RecibirOrdenResponse | null>(null);
    const today = useMemo(() => hoyLocal(), []);
    const lineasPendientes = useMemo(() => (orden?.lineas ?? []).filter((l) => pendienteDe(l) > 0), [orden]);

    useEffect(() => {
        if (!open || !orden)
            return;
        consumableService.list().then(setConsumibles).catch(() => setConsumibles([]));
        assetService.getTipos().then(setTipos).catch(() => setTipos([]));
        client.get<ModeloFlat[]>('/cat/modelos-flat').then((r) => setModelos(r.data)).catch(() => setModelos([]));
        softwareService.getSoftware().then(setSoftware).catch(() => setSoftware([]));
        softwareService.getTiposLicencia().then(setTiposLicencia).catch(() => setTiposLicencia([]));
        const initial: Record<number, LineConfig> = {};
        (orden.lineas ?? []).forEach((l) => {
            initial[l.OCL_Linea as number] = {
                // La línea ya dice qué es: se propone recibirla como tal.
                modo: l.OCL_Tipo ? MODO_DE_TIPO[l.OCL_Tipo] : 'omitir',
                con_id: l.CON_Consumible ?? '',
                cantidad: pendienteDe(l),
                precio: String(l.OCL_Precio_Unitario ?? ''),
                fecha: orden.OCO_Fecha ?? today,
            };
        });
        setCfg(initial);
        setDocumento('');
        setObservacion('');
        setLineaConError(null);
        setResultado(null);
    }, [open, orden, today]);

    const upd = (linea: number, patch: Partial<LineConfig>) => setCfg((p) => ({ ...p, [linea]: { ...p[linea], ...patch } }));
    const modelosDeTipo = (tipo?: number | '') => !tipo ? modelos : modelos.filter((m) => m.TAC_Tipo_Activo == null || m.TAC_Tipo_Activo === Number(tipo));
    const modosPermitidos = (l: LineaOrden): Modo[] => l.OCL_Tipo
        ? ['omitir', MODO_DE_TIPO[l.OCL_Tipo]]
        : ['omitir', 'activo', 'consumible', 'licencia', 'otro'];

    /** Unidades que entran en esta entrega según lo capturado. */
    const unidades = (c: LineConfig): number => {
        if (c.modo === 'activo') return splitLines(c.series).length;
        if (c.modo === 'licencia') {
            const claves = splitLines(c.clavesLicencia).length;
            return claves || Number(c.cantidad || 0);
        }
        if (c.modo === 'omitir') return 0;
        return Number(c.cantidad || 0);
    };
    const precioDistinto = (l: LineaOrden, c: LineConfig) => c.modo !== 'omitir' && c.precio !== undefined && c.precio !== ''
        && Number(c.precio) !== Number(l.OCL_Precio_Unitario);

    const handleSubmit = async () => {
        if (!orden)
            return;
        const payload: RecibirOrdenPayload = { consumibles: [], activos: [], licencias: [], otros: [], diferencias: [] };
        const consumiblesPayload: RecibirOrdenConsumiblePayload[] = payload.consumibles;
        const activosPayload: RecibirOrdenActivoPayload[] = payload.activos;
        const licenciasPayload: RecibirOrdenLicenciaPayload[] = payload.licencias;
        for (const l of lineasPendientes) {
            const linea = l.OCL_Linea as number;
            const c = cfg[linea];
            if (!c || c.modo === 'omitir')
                continue;
            const n = unidades(c);
            if (n <= 0) {
                enqueueSnackbar(t('procurement.recv_err_quantity', { line: l.OCL_Descripcion }), { variant: 'warning' });
                return;
            }
            const sobrante = n > pendienteDe(l);
            if (sobrante && !(c.aceptarSobrante && (c.motivo ?? '').trim().length >= 5)) {
                setLineaConError(linea);
                enqueueSnackbar(t('procurement.recv_err_surplus', { line: l.OCL_Descripcion, count: pendienteDe(l) }), { variant: 'warning' });
                return;
            }
            const precio = c.precio !== undefined && c.precio !== '' ? String(c.precio) : null;
            if (c.modo === 'consumible') {
                if (!c.con_id) {
                    enqueueSnackbar(t('procurement.recv_err_consumible'), { variant: 'warning' });
                    return;
                }
                consumiblesPayload.push({ OCL_Linea: linea, CON_Consumible: Number(c.con_id), cantidad: n, precio_unitario: precio });
            }
            else if (c.modo === 'activo') {
                const series = splitLines(c.series);
                const hostnames = splitLines(c.hostnames);
                if (!c.tipo || !c.modelo || !c.fecha) {
                    enqueueSnackbar(t('procurement.recv_err_activo'), { variant: 'warning' });
                    return;
                }
                series.forEach((serie, index) => {
                    activosPayload.push({
                        OCL_Linea: linea,
                        ACT_Serie_Fabricante: serie,
                        MOD_Modelo: Number(c.modelo),
                        TAC_Tipo_Activo: Number(c.tipo),
                        ACT_Hostname: hostnames[index] || null,
                        ACT_Fecha_Compra: c.fecha as string,
                        ACT_Fin_Garantia: c.garantia || null,
                        ACT_Costo: precio != null ? Number(precio) : null,
                    });
                });
            }
            else if (c.modo === 'licencia') {
                const claves = splitLines(c.clavesLicencia);
                const referencias = splitLines(c.referenciasLicencia);
                if (!c.software || !c.tipoLicencia) {
                    enqueueSnackbar(t('procurement.recv_err_license_catalog'), { variant: 'warning' });
                    return;
                }
                licenciasPayload.push({
                    OCL_Linea: linea,
                    SOF_Software: Number(c.software),
                    TLI_Tipo_Licencia: Number(c.tipoLicencia),
                    cantidad: n,
                    precio_unitario: precio,
                    LIC_Fecha_Vencimiento: c.vencimientoLicencia || null,
                    claves: claves.map((clave, index) => ({
                        LCL_Clave_Activacion: clave,
                        LCL_Referencia: referencias[index] || null,
                    })),
                });
            }
            else if (c.modo === 'otro') {
                payload.otros?.push({ OCL_Linea: linea, cantidad: n, precio_unitario: precio });
            }
            const motivo = (c.motivo ?? '').trim();
            if (motivo.length >= 5 && (sobrante || precioDistinto(l, c)))
                payload.diferencias?.push({ OCL_Linea: linea, motivo, aceptar_sobrante: sobrante });
        }
        if (!consumiblesPayload.length && !activosPayload.length && !licenciasPayload.length && !payload.otros?.length) {
            enqueueSnackbar(t('api_errors.EMPTY_RECEIPT'), { variant: 'warning' });
            return;
        }
        payload.documento = documento.trim() || undefined;
        payload.observacion = observacion.trim() || undefined;
        setLoading(true);
        setLineaConError(null);
        try {
            setResultado(await procurementService.recibir(orden.OCO_Orden, payload));
        }
        catch (err: unknown) {
            const code = getApiErrorCode(err);
            // El servidor señala la línea: se resalta para que se justifique ahí mismo.
            if (code === 'PRICE_DIFFERENCE_REQUIRES_JUSTIFICATION' || code === 'RECEIPT_EXCEEDS_PENDING') {
                setLineaConError(Number(getApiErrorParams(err)[0]) || null);
            }
            enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
        }
        finally {
            setLoading(false);
        }
    };

    const imprimirEtiquetas = async () => {
        if (!resultado?.activos_codigos.length) return;
        try {
            const blob = await assetService.downloadLabels({ codigos: resultado.activos_codigos }, normalizeLanguage(i18n.language));
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `etiquetas-${orden?.OCO_Numero ?? ''}.pdf`;
            a.click();
            window.setTimeout(() => URL.revokeObjectURL(url), 10_000);
        }
        catch (err) {
            enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
        }
    };

    // Recepción → etiquetas → entrega: lo recibido se entrega sin buscarlo de nuevo.
    const entregarRecibidos = async () => {
        if (!resultado) return;
        try {
            const activos = await Promise.all(resultado.activos_codigos.map((c) => assetService.getActivoByCode(c)));
            onClose(true);
            abrirEntrega({ activos });
        }
        catch (err) {
            enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
        }
    };

    if (!orden)
        return null;

    if (resultado) {
        return (<Dialog open={open} onClose={() => onClose(true)} maxWidth="sm" fullWidth>
          <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
            <CheckCircleIcon color={resultado.aprobacion_pendiente ? 'warning' : 'success'}/> {resultado.aprobacion_pendiente ? t('procurement.recv_sent_title', { number: orden.OCO_Numero }) : t('procurement.recv_done_title', { number: orden.OCO_Numero })}
          </DialogTitle>
          <DialogContent>
            <Stack spacing={2}>
              {resultado.aprobacion_pendiente ? (<Alert severity="warning">
                  {t('procurement.recv_pending_approval', { id: resultado.aprobacion_pendiente })}
                </Alert>) : (<Alert severity={resultado.OCO_Estado === 'RECIBIDA' ? 'success' : 'info'}>
                {t('procurement.recv_done_state', { state: estadoOrdenLabel(t, resultado.OCO_Estado) })}
              </Alert>)}
              <Typography variant="body2">
                {[
                    resultado.activos_creados ? t('procurement.recv_created', { count: resultado.activos_creados }) : null,
                    resultado.consumibles_reabastecidos ? t('procurement.recv_restocked', { count: resultado.consumibles_reabastecidos }) : null,
                    resultado.licencias_creadas ? t('procurement.recv_licenses', { count: resultado.licencias_creadas }) : null,
                    resultado.licencias_claves_creadas ? t('procurement.recv_license_keys', { count: resultado.licencias_claves_creadas }) : null,
                ].filter(Boolean).join(' · ') || (resultado.aprobacion_pendiente ? t('procurement.recv_nothing_yet') : t('procurement.recv_only_services'))}
              </Typography>
              {resultado.activos_codigos.length > 0 && (<Box>
                  <Typography variant="subtitle2" gutterBottom>{t('procurement.recv_new_codes')}</Typography>
                  <Stack direction="row" spacing={0.5} useFlexGap flexWrap="wrap">
                    {resultado.activos_codigos.map((c) => <Chip key={c} size="small" label={c}/>)}
                  </Stack>
                </Box>)}
              {resultado.diferencias.length > 0 && (<Alert severity="warning">
                  <Typography variant="subtitle2">{t('procurement.recv_differences')}</Typography>
                  {resultado.diferencias.map((d, i) => (<Typography key={i} variant="body2">
                      {t(`procurement.diff_types.${d.tipo}`, { defaultValue: d.tipo })}: {d.detalle}{d.motivo ? ` — ${d.motivo}` : ''}
                    </Typography>))}
                  <Typography variant="caption">{t('procurement.recv_differences_notified')}</Typography>
                </Alert>)}
              {resultado.pendientes.length > 0 && (<Box>
                  <Typography variant="subtitle2">{t('procurement.recv_still_pending')}</Typography>
                  <List dense disablePadding>
                    {resultado.pendientes.map((p) => (<ListItem key={p.OCL_Linea} disableGutters>
                        <ListItemText primary={p.descripcion} secondary={t('procurement.pending_detail', { ordered: p.pedido, received: p.recibido, pending: p.pendiente })}/>
                      </ListItem>))}
                  </List>
                  <Typography variant="caption" color="text.secondary">{t('procurement.recv_pending_hint')}</Typography>
                </Box>)}
            </Stack>
          </DialogContent>
          <DialogActions>
            {resultado.activos_codigos.length > 0 && (<Button startIcon={<QrCode2Icon />} onClick={imprimirEtiquetas}>
                {t('procurement.recv_print_labels')}
              </Button>)}
            {canOperate && resultado.activos_codigos.length > 0 && (<Button startIcon={<AssignmentIndIcon />} onClick={entregarRecibidos}>
                {t('procurement.recv_handover')}
              </Button>)}
            <Button variant="contained" onClick={() => onClose(true)}>{t('common.close')}</Button>
          </DialogActions>
        </Dialog>);
    }

    return (<Dialog open={open} onClose={() => onClose()} maxWidth="md" fullWidth>
      <DialogTitle>{t('procurement.receive_title')} {orden.OCO_Numero}</DialogTitle>
      <DialogContent>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>{t('procurement.receive_help_partial')}</Typography>
        <Stack spacing={2}>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            <TextField size="small" fullWidth label={t('procurement.recv_document')} helperText={t('procurement.recv_document_help')} value={documento} onChange={(e) => setDocumento(e.target.value)} inputProps={{ maxLength: 100 }}/>
            <TextField size="small" fullWidth label={t('procurement.notes')} value={observacion} onChange={(e) => setObservacion(e.target.value)} inputProps={{ maxLength: 500 }}/>
          </Stack>
        {lineasPendientes.map((l: LineaOrden) => {
            const linea = l.OCL_Linea as number;
            const c = cfg[linea] ?? { modo: 'omitir' };
            const pendiente = pendienteDe(l);
            const n = unidades(c);
            const sobrante = c.modo !== 'omitir' && n > pendiente;
            const difPrecio = precioDistinto(l, c);
            const conError = lineaConError === linea;
            const subtitulo = t('procurement.pending_detail', { ordered: l.OCL_Cantidad, received: l.OCL_Cantidad_Recibida ?? 0, pending: pendiente });
            return (<SectionCard key={linea} title={l.OCL_Descripcion} subtitle={subtitulo} noPadding={c.modo === 'omitir'}
                actions={(<TextField select size="small" label={t('procurement.recv_action')} value={c.modo} onChange={(e) => upd(linea, { modo: e.target.value as Modo })} sx={{ minWidth: 190 }}>
                  {modosPermitidos(l).map((m) => (<MenuItem key={m} value={m}>{t(`procurement.recv_modes.${m}`)}</MenuItem>))}
                </TextField>)}>

              {c.modo === 'consumible' && (<Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                  <TextField select size="small" fullWidth label={t('menu.consumables')} value={c.con_id ?? ''} disabled={!!l.CON_Consumible} onChange={(e) => upd(linea, { con_id: Number(e.target.value) })}>
                    {consumibles.map((cn) => (<MenuItem key={cn.CON_Consumible} value={cn.CON_Consumible}>{cn.CON_Nombre}</MenuItem>))}
                  </TextField>
                  <TextField size="small" type="number" label={t('procurement.recv_quantity')} value={c.cantidad ?? ''} onChange={(e) => upd(linea, { cantidad: Number(e.target.value) })} inputProps={{ min: 1 }} sx={{ width: 150 }}/>
                  <MoneyField size="small" label={t('procurement.recv_real_price')} value={c.precio ?? ''} onChange={(value) => upd(linea, { precio: value })} currency={orden.OCO_Moneda}/>
                </Stack>)}

              {c.modo === 'otro' && (<Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} alignItems={{ sm: 'center' }}>
                  <Typography variant="body2" color="text.secondary" sx={{ flex: 1 }}>{t('procurement.recv_other_help')}</Typography>
                  <TextField size="small" type="number" label={t('procurement.recv_quantity')} value={c.cantidad ?? ''} onChange={(e) => upd(linea, { cantidad: Number(e.target.value) })} inputProps={{ min: 1 }} sx={{ width: 150 }}/>
                  <MoneyField size="small" label={t('procurement.recv_real_price')} value={c.precio ?? ''} onChange={(value) => upd(linea, { precio: value })} currency={orden.OCO_Moneda}/>
                </Stack>)}

              {c.modo === 'activo' && (<Stack spacing={1.5}>
                  <TextField size="small" fullWidth multiline minRows={3} label={t('procurement.serials_one_per_unit')} helperText={t('procurement.units_captured', { count: n, pending: pendiente })} value={c.series ?? ''} onChange={(e) => upd(linea, { series: e.target.value })}/>
                  <TextField size="small" fullWidth multiline minRows={2} label={t('procurement.hostnames_optional')} value={c.hostnames ?? ''} onChange={(e) => upd(linea, { hostnames: e.target.value })}/>
                  <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                    <TextField select size="small" fullWidth label={t('assets.dialog.type')} value={c.tipo ?? ''} onChange={(e) => upd(linea, { tipo: Number(e.target.value), modelo: '' })}>
                      {tipos.map((tp) => (<MenuItem key={tp.TAC_Tipo_Activo} value={tp.TAC_Tipo_Activo}>
                          {typeLabel(t, tp.TAC_Nombre)}{tp.TAC_Prefijo ? ` (${tp.TAC_Prefijo})` : ''}
                        </MenuItem>))}
                    </TextField>
                    <TextField select size="small" fullWidth label={t('assets.dialog.model')} value={c.modelo ?? ''} disabled={!c.tipo} onChange={(e) => upd(linea, { modelo: Number(e.target.value) })}>
                      {modelosDeTipo(c.tipo).map((m) => (<MenuItem key={m.MOD_Modelo} value={m.MOD_Modelo}>
                          {m.MAR_Nombre ? `${m.MAR_Nombre} ` : ''}{m.MOD_Nombre}
                        </MenuItem>))}
                    </TextField>
                  </Stack>
                  <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                    <DateField size="small" label={t('assets.dialog.purchase_date')} value={c.fecha ?? today} onChange={(e) => upd(linea, { fecha: e.target.value })} InputLabelProps={{ shrink: true }} inputProps={{ max: today }}/>
                    <DateField size="small" label={t('procurement.warranty_end')} value={c.garantia ?? ''} onChange={(e) => upd(linea, { garantia: e.target.value })} InputLabelProps={{ shrink: true }}/>
                    <MoneyField size="small" label={t('procurement.recv_real_cost')} value={c.precio ?? ''} onChange={(value) => upd(linea, { precio: value })} currency={orden.OCO_Moneda}/>
                  </Stack>
                  {pendiente > 1 && (<Chip size="small" color="info" variant="outlined" label={t('procurement.assets_bulk_hint')}/>)}
                </Stack>)}

              {c.modo === 'licencia' && (<Stack spacing={1.5}>
                  <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                    <TextField select size="small" fullWidth label={t('software.col_software')} value={c.software ?? ''} onChange={(e) => upd(linea, { software: Number(e.target.value) })}>
                      {software.map((sw) => (<MenuItem key={sw.SOF_Software} value={sw.SOF_Software}>
                          {sw.SOF_Nombre}{sw.SOF_Version ? ` ${sw.SOF_Version}` : ''}
                        </MenuItem>))}
                    </TextField>
                    <TextField select size="small" fullWidth label={t('software.license_type')} value={c.tipoLicencia ?? ''} onChange={(e) => upd(linea, { tipoLicencia: Number(e.target.value) })}>
                      {tiposLicencia.map((tl) => (<MenuItem key={tl.TLI_Tipo_Licencia} value={tl.TLI_Tipo_Licencia}>{tl.TLI_Nombre}</MenuItem>))}
                    </TextField>
                  </Stack>
                  <TextField size="small" fullWidth multiline minRows={3} label={t('procurement.license_keys_optional')} helperText={t('procurement.license_keys_help')} value={c.clavesLicencia ?? ''} onChange={(e) => upd(linea, { clavesLicencia: e.target.value })}/>
                  {splitLines(c.clavesLicencia).length > 0 && (<TextField size="small" fullWidth multiline minRows={2} label={t('procurement.license_refs_optional')} value={c.referenciasLicencia ?? ''} onChange={(e) => upd(linea, { referenciasLicencia: e.target.value })}/>)}
                  <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                    {splitLines(c.clavesLicencia).length === 0 && (<TextField size="small" type="number" label={t('procurement.recv_quantity')} value={c.cantidad ?? ''} onChange={(e) => upd(linea, { cantidad: Number(e.target.value) })} inputProps={{ min: 1 }} sx={{ width: 150 }}/>)}
                    <DateField size="small" label={t('software.expiry')} value={c.vencimientoLicencia ?? ''} onChange={(e) => upd(linea, { vencimientoLicencia: e.target.value })} InputLabelProps={{ shrink: true }}/>
                    <MoneyField size="small" label={t('procurement.recv_real_price')} value={c.precio ?? ''} onChange={(value) => upd(linea, { precio: value })} currency={orden.OCO_Moneda}/>
                  </Stack>
                </Stack>)}

              {(sobrante || difPrecio || conError) && c.modo !== 'omitir' && (<Alert severity={conError ? 'error' : 'warning'} sx={{ mt: 1.5 }}>
                  {sobrante && <Typography variant="body2">{t('procurement.recv_surplus_warning', { count: n, pending: pendiente })}</Typography>}
                  {difPrecio && <Typography variant="body2">{t('procurement.recv_price_warning')}</Typography>}
                  <TextField size="small" fullWidth sx={{ mt: 1 }} label={t('procurement.recv_justification')} value={c.motivo ?? ''} onChange={(e) => upd(linea, { motivo: e.target.value })}
                    error={conError && (c.motivo ?? '').trim().length < 5} helperText={t('procurement.recv_justification_help')} inputProps={{ maxLength: 500 }}/>
                  {sobrante && (<FormControlLabel control={<Checkbox size="small" checked={!!c.aceptarSobrante} onChange={(e) => upd(linea, { aceptarSobrante: e.target.checked })}/>} label={t('procurement.recv_accept_surplus')}/>)}
                  {sobrante && (<Typography variant="caption" display="block">{t('procurement.recv_surplus_needs_approval')}</Typography>)}
                </Alert>)}
            </SectionCard>);
        })}
        </Stack>
        {lineasPendientes.length === 0 && (<Typography color="text.secondary">
            {t('procurement.recv_nothing_pending')}
          </Typography>)}
      </DialogContent>
      <DialogActions>
        <Button onClick={() => onClose()} color="inherit">{t('common.cancel')}</Button>
        <Button variant="contained" color="success" onClick={handleSubmit} disabled={loading || lineasPendientes.length === 0}>
          {loading ? <CircularProgress size={24}/> : t('procurement.confirm_receive')}
        </Button>
      </DialogActions>
    </Dialog>);
};
