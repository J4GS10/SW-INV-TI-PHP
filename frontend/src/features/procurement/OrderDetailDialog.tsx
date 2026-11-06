import { useCallback, useEffect, useState } from 'react';
import { Alert, Box, Button, Chip, CircularProgress, DialogActions, DialogContent, DialogTitle, IconButton, LinearProgress, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Typography } from '@mui/material';
import CloseIcon from '@mui/icons-material/Close';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../api/errors';
import { procurementService } from './procurementService';
import { OrdenCompra, Recepcion } from '../../types/procurement';
import { AttachmentsSection } from '../attachments/AttachmentsSection';
import { ReceiveOrderDialog } from './ReceiveOrderDialog';
import { SectionCard } from '../../components/ui';
import { usePermissions } from '../../hooks/usePermissions';
import { formatMoney } from '../../utils/currency';
import { formatDate, formatDateTimeValue } from '../../utils/dates';
import { estadoOrdenColor, estadoOrdenLabel, ordenRecibible } from './orderStates';
import { Dialog } from '../../components/ui/Dialog';
interface Props {
    open: boolean;
    ordenId: number | null;
    onClose: (refresh?: boolean) => void;
}
type Accion = 'cancelar' | 'cerrar' | null;

/**
 * Detalle de la orden: qué se pidió, qué llegó y qué falta, cada entrega con
 * su documento, y las acciones que corresponden al estado (recibir otra
 * entrega, cerrar con faltante o cancelar).
 */
export const OrderDetailDialog = ({ open, ordenId, onClose }: Props) => {
    const { t, i18n } = useTranslation();
    // Compras cancela un borrador suyo; recibir y cerrar con faltante es de TI.
    const { canAdminInventory, canPurchase } = usePermissions();
    const { enqueueSnackbar } = useSnackbar();
    const [orden, setOrden] = useState<OrdenCompra | null>(null);
    const [recepciones, setRecepciones] = useState<Recepcion[]>([]);
    const [loading, setLoading] = useState(false);
    const [receiveOpen, setReceiveOpen] = useState(false);
    const [changed, setChanged] = useState(false);
    const [accion, setAccion] = useState<Accion>(null);
    const [motivo, setMotivo] = useState('');
    const [enviando, setEnviando] = useState(false);
    const load = useCallback(async () => {
        if (ordenId == null)
            return;
        setLoading(true);
        try {
            const [o, r] = await Promise.all([procurementService.getOrden(ordenId), procurementService.recepciones(ordenId)]);
            setOrden(o);
            setRecepciones(r);
        }
        catch {
            enqueueSnackbar(t('common.error_loading'), { variant: 'error' });
        }
        finally {
            setLoading(false);
        }
    }, [enqueueSnackbar, ordenId, t]);
    useEffect(() => { if (open) {
        setChanged(false);
        load();
    } }, [open, load]);

    const confirmarAccion = async () => {
        if (!orden || !accion)
            return;
        setEnviando(true);
        try {
            if (accion === 'cerrar')
                await procurementService.cerrarConFaltante(orden.OCO_Orden, motivo.trim());
            else
                await procurementService.cambiarEstado(orden.OCO_Orden, 'CANCELADA', motivo.trim() || undefined);
            // Ambas se solicitan: las ejecuta otro administrador de TI al aprobarlas.
            enqueueSnackbar(t(accion === 'cerrar' ? 'procurement.close_requested' : 'procurement.cancel_requested'), { variant: 'info' });
            setAccion(null);
            setMotivo('');
            setChanged(true);
            load();
        }
        catch (err: unknown) {
            enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
        }
        finally {
            setEnviando(false);
        }
    };

    const lineas = orden?.lineas ?? [];
    const pedido = lineas.reduce((a, l) => a + Number(l.OCL_Cantidad || 0), 0);
    const recibido = lineas.reduce((a, l) => a + Math.min(Number(l.OCL_Cantidad_Recibida || 0), Number(l.OCL_Cantidad || 0)), 0);
    const avance = pedido ? Math.round((recibido / pedido) * 100) : 0;
    const descripcionLinea = (id: number) => lineas.find((l) => l.OCL_Linea === id)?.OCL_Descripcion ?? `#${id}`;
    const recibible = !!orden && ordenRecibible(orden.OCO_Estado);
    const sinRecepciones = recepciones.length === 0;

    return (<>
      <Dialog open={open} onClose={() => onClose(changed)} maxWidth="md" fullWidth>
        <DialogTitle sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <span>{t('procurement.order')} {orden?.OCO_Numero ?? ''}</span>
          <IconButton onClick={() => onClose(changed)} aria-label={t('common.close')}><CloseIcon /></IconButton>
        </DialogTitle>
        <DialogContent dividers>
          {loading || !orden ? (<Box display="flex" justifyContent="center" py={4}><CircularProgress /></Box>) : (<Stack spacing={2}>
                <SectionCard title={t('procurement.sections.order_data')}>
                  <Stack direction="row" spacing={3} useFlexGap flexWrap="wrap">
                    <Box><Typography variant="caption" color="text.secondary">{t('procurement.provider')}</Typography>
                      <Typography>{orden.proveedor?.PRV_Nombre ?? '—'}</Typography></Box>
                    <Box><Typography variant="caption" color="text.secondary">{t('procurement.date')}</Typography>
                      <Typography>{formatDate(orden.OCO_Fecha, i18n.language)}</Typography></Box>
                    <Box><Typography variant="caption" color="text.secondary">{t('procurement.status')}</Typography>
                      <Box><Chip size="small" label={estadoOrdenLabel(t, orden.OCO_Estado)} color={estadoOrdenColor(orden.OCO_Estado)}/></Box></Box>
                    <Box><Typography variant="caption" color="text.secondary">{t('procurement.total')}</Typography>
                      <Typography>{formatMoney(orden.OCO_Total, orden.OCO_Moneda, i18n.language)}</Typography></Box>
                  </Stack>
                  {pedido > 0 && orden.OCO_Estado !== 'CANCELADA' && (<Box sx={{ mt: 2 }}>
                      <Typography variant="caption" color="text.secondary">{t('procurement.progress', { received: recibido, ordered: pedido })}</Typography>
                      <LinearProgress variant="determinate" value={avance} color={avance === 100 ? 'success' : 'primary'} sx={{ height: 8, borderRadius: 4 }}/>
                    </Box>)}
                  {orden.OCO_Notas && (<Typography variant="body2" color="text.secondary" sx={{ mt: 2 }}>{orden.OCO_Notas}</Typography>)}
                  {orden.OCO_Motivo_Cierre && (<Alert severity="info" sx={{ mt: 2 }}>{t('procurement.close_reason_label')}: {orden.OCO_Motivo_Cierre}</Alert>)}
                  {orden.OCO_Estado === 'PARCIAL' && (<Alert severity="warning" sx={{ mt: 2 }}>{t('procurement.partial_hint')}</Alert>)}
                </SectionCard>
                <SectionCard title={t('procurement.lines')} noPadding>
                  <Box sx={{ overflowX: 'auto' }}>
                  <Table size="small">
                    <TableHead><TableRow>
                      <TableCell>{t('procurement.line_desc')}</TableCell>
                      <TableCell>{t('procurement.line_type')}</TableCell>
                      <TableCell align="right">{t('procurement.ordered')}</TableCell>
                      <TableCell align="right">{t('procurement.received_qty')}</TableCell>
                      <TableCell align="right">{t('procurement.pending')}</TableCell>
                      <TableCell align="right">{t('procurement.line_price')}</TableCell>
                      <TableCell align="right">{t('procurement.subtotal')}</TableCell>
                    </TableRow></TableHead>
                    <TableBody>
                      {lineas.map((l) => {
                        const pend = l.OCL_Pendiente ?? Math.max(l.OCL_Cantidad - (l.OCL_Cantidad_Recibida ?? 0), 0);
                        return (<TableRow key={l.OCL_Linea}>
                          <TableCell>{l.OCL_Descripcion}</TableCell>
                          <TableCell>{l.OCL_Tipo ? t(`procurement.line_types.${l.OCL_Tipo}`) : '—'}</TableCell>
                          <TableCell align="right">{l.OCL_Cantidad}</TableCell>
                          <TableCell align="right">{l.OCL_Cantidad_Recibida ?? 0}</TableCell>
                          <TableCell align="right">{pend > 0 ? <Chip size="small" color="warning" variant="outlined" label={pend}/> : '0'}</TableCell>
                          <TableCell align="right">{formatMoney(l.OCL_Precio_Unitario, orden.OCO_Moneda, i18n.language)}</TableCell>
                          <TableCell align="right">{formatMoney(l.OCL_Subtotal ?? 0, orden.OCO_Moneda, i18n.language)}</TableCell>
                        </TableRow>);
                      })}
                      {lineas.length === 0 && (<TableRow><TableCell colSpan={7} sx={{ color: 'text.secondary' }}>
                          {t('procurement.no_lines')}</TableCell></TableRow>)}
                    </TableBody>
                  </Table>
                  </Box>
                </SectionCard>
                <SectionCard title={t('procurement.receipts_title')} subtitle={t('procurement.receipts_subtitle')}>
                  {recepciones.length === 0 ? (<Typography variant="body2" color="text.secondary">{t('procurement.no_receipts')}</Typography>) : (<Stack spacing={1.5}>
                      {recepciones.map((r) => (<Box key={r.REC_Recepcion} sx={{ borderLeft: 3, borderColor: r.REC_Resultado === 'COMPLETA' ? 'success.main' : 'warning.main', pl: 1.5 }}>
                          <Typography variant="body2" fontWeight={600}>
                            {formatDateTimeValue(r.REC_Fecha, i18n.language)} · {r.REC_Documento || t('procurement.no_document')}
                            {' · '}{t(`procurement.receipt_results.${r.REC_Resultado}`, { defaultValue: r.REC_Resultado })}
                          </Typography>
                          {r.lineas.map((rl, i) => (<Typography key={i} variant="caption" display="block" color={rl.RCL_Diferencia ? 'warning.main' : 'text.secondary'}>
                              {descripcionLinea(rl.OCL_Linea)}: {rl.RCL_Cantidad}
                              {rl.RCL_Precio_Real != null ? ` × ${formatMoney(rl.RCL_Precio_Real, orden.OCO_Moneda, i18n.language)}` : ''}
                              {rl.RCL_Diferencia ? ` · ${t(`procurement.diff_types.${rl.RCL_Diferencia}`, { defaultValue: rl.RCL_Diferencia })}${rl.RCL_Motivo ? `: ${rl.RCL_Motivo}` : ''}` : ''}
                            </Typography>))}
                          {r.REC_Observacion && <Typography variant="caption" display="block">{r.REC_Observacion}</Typography>}
                        </Box>))}
                    </Stack>)}
                </SectionCard>
                <SectionCard title={t('procurement.invoice')}>
                  <AttachmentsSection ordenId={orden.OCO_Orden}/>
                </SectionCard>
              </Stack>)}
        </DialogContent>
        <DialogActions sx={{ flexWrap: 'wrap', gap: 1 }}>
          {recibible && canPurchase && sinRecepciones && (<Button color="error" onClick={() => { setMotivo(''); setAccion('cancelar'); }}>{t('procurement.cancel_order')}</Button>)}
          {orden?.OCO_Estado === 'PARCIAL' && canAdminInventory && (<Button color="warning" onClick={() => { setMotivo(''); setAccion('cerrar'); }}>{t('procurement.close_with_shortage')}</Button>)}
          <Box sx={{ flex: 1 }}/>
          <Button onClick={() => onClose(changed)} color="inherit">{t('common.close')}</Button>
          {recibible && canAdminInventory && (<Button variant="contained" color="success" onClick={() => setReceiveOpen(true)}>
              {orden?.OCO_Estado === 'PARCIAL' ? t('procurement.receive_next') : t('procurement.receive')}
            </Button>)}
        </DialogActions>
      </Dialog>

      <Dialog open={accion != null} onClose={() => setAccion(null)} maxWidth="xs" fullWidth>
        <DialogTitle>{accion === 'cerrar' ? t('procurement.close_with_shortage') : t('procurement.cancel_order')}</DialogTitle>
        <DialogContent>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
            {accion === 'cerrar' ? t('procurement.close_help') : t('procurement.confirm_cancel')} {t('procurement.needs_approval')}
          </Typography>
          <TextField autoFocus fullWidth multiline minRows={2} label={accion === 'cerrar' ? t('procurement.close_reason') : t('procurement.cancel_reason')}
            value={motivo} onChange={(e) => setMotivo(e.target.value)} inputProps={{ maxLength: 500 }}
            helperText={accion === 'cerrar' ? t('procurement.recv_justification_help') : undefined}/>
        </DialogContent>
        <DialogActions>
          <Button color="inherit" onClick={() => setAccion(null)}>{t('common.cancel')}</Button>
          <Button variant="contained" color={accion === 'cerrar' ? 'warning' : 'error'} onClick={confirmarAccion}
            disabled={enviando || motivo.trim().length < 5}>
            {enviando ? <CircularProgress size={20}/> : accion === 'cerrar' ? t('procurement.close_with_shortage') : t('procurement.cancel_order')}
          </Button>
        </DialogActions>
      </Dialog>

      <ReceiveOrderDialog open={receiveOpen} orden={orden} onClose={(r) => { setReceiveOpen(false); if (r) {
        setChanged(true);
        load();
    } }}/>
    </>);
};
