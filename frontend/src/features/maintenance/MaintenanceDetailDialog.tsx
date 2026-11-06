import { useCallback, useEffect, useState } from 'react';
import { Box, Button, Chip, CircularProgress, DialogActions, DialogContent, DialogTitle, IconButton, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField, Tooltip, Typography } from '@mui/material';
import CloseIcon from '@mui/icons-material/Close';
import AddIcon from '@mui/icons-material/Add';
import DeleteIcon from '@mui/icons-material/DeleteOutline';
import EditIcon from '@mui/icons-material/Edit';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import SaveIcon from '@mui/icons-material/Save';
import { useTranslation } from 'react-i18next';
import type { CatalogoItem } from '../../api/catalogoService';
import { CatalogField } from '../../components/CatalogField';
import { pideDetalle, useCatalogo } from '../../hooks/useCatalogo';
import { EmptyState } from '../../components/ui';
import { useConfirm } from '../../components/confirm-context';
import { MoneyField, SectionCard } from '../../components/ui';
import { DEFAULT_CURRENCY, formatMoney } from '../../utils/currency';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../api/errors';
import { Mantenimiento, DetalleMantenimiento, DetalleCreate } from '../../types/maintenance';
import { maintenanceService } from './maintenanceService';
import { Dialog } from '../../components/ui/Dialog';
interface Props {
    open: boolean;
    ticket: Mantenimiento | null;
    onClose: () => void;
    onChanged?: () => void;
}
export const MaintenanceDetailDialog = ({ open, ticket, onClose, onChanged }: Props) => {
    const { t, i18n } = useTranslation();
    const confirm = useConfirm();
    const { enqueueSnackbar } = useSnackbar();
    const [detalles, setDetalles] = useState<DetalleMantenimiento[]>([]);
    const [loading, setLoading] = useState(false);
    // Form: nuevo detalle
    const [addingDet, setAddingDet] = useState(false);
    const [detAccion, setDetAccion] = useState('');
    const [detAccionItem, setDetAccionItem] = useState<CatalogoItem | null>(null);
    const { items: acciones } = useCatalogo('acciones-mantenimiento');
    const [detCosto, setDetCosto] = useState('');
    // Form: editar detalle
    const [editingDet, setEditingDet] = useState<DetalleMantenimiento | null>(null);
    // Form: cerrar ticket
    const [closing, setClosing] = useState(false);
    const [costoTotal, setCostoTotal] = useState('');
    const [moneda, setMoneda] = useState<string>(DEFAULT_CURRENCY);
    const isClosed = !!ticket?.MAN_Fecha_Cierre;
    const load = useCallback(async () => {
        if (!ticket)
            return;
        setLoading(true);
        try {
            const list = await maintenanceService.listDetalles(ticket.MAN_Mantenimiento);
            setDetalles(list);
        }
        catch {
            enqueueSnackbar(t('common.error_loading'), { variant: 'error' });
        }
        finally {
            setLoading(false);
        }
    }, [enqueueSnackbar, t, ticket]);
    useEffect(() => {
        if (open && ticket) {
            load();
            setAddingDet(false);
            setEditingDet(null);
            setClosing(false);
            // Sugerir costo total = suma de detalles existentes
            const suma = (ticket.detalles ?? []).reduce((acc, d) => acc + Number(d.DMA_Costo_Item ?? 0), 0);
            setCostoTotal(suma > 0 ? String(suma) : '0');
            setMoneda(ticket.MAN_Moneda ?? DEFAULT_CURRENCY);
        }
    }, [open, ticket, load]);
    if (!ticket)
        return null;
    const resetDetForm = () => {
        setDetAccion('');
        setDetAccionItem(null);
        setDetCosto('');
        setEditingDet(null);
        setAddingDet(false);
    };
    const handleSaveDetalle = async () => {
        // Acción del catálogo obligatoria; el detalle, solo si la acción lo pide.
        if (!detAccionItem || (pideDetalle(detAccionItem) && detAccion.trim().length < 5)) {
            enqueueSnackbar(t('maintenance.errors.short_action'), {
                variant: 'warning',
            });
            return;
        }
        try {
            const payload: DetalleCreate = {
                DMA_Accion_Realizada: detAccion.trim(),
                AMA_Accion: detAccionItem.id,
                DMA_Costo_Item: detCosto ? Number(detCosto) : 0,
            };
            if (editingDet) {
                await maintenanceService.updateDetalle(editingDet.DMA_Detalle_Mant, payload);
                enqueueSnackbar(t('common.success_update'), { variant: 'success' });
            }
            else {
                await maintenanceService.addDetalle(ticket.MAN_Mantenimiento, payload);
                enqueueSnackbar(t('common.success_create'), { variant: 'success' });
            }
            resetDetForm();
            load();
            onChanged?.();
        }
        catch (err: unknown) {
            enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
        }
    };
    const handleDeleteDetalle = async (det: DetalleMantenimiento) => {
        if (!(await confirm({ message: t('common.confirm_delete'), destructive: true, confirmText: t('common.delete') })))
            return;
        try {
            await maintenanceService.deleteDetalle(det.DMA_Detalle_Mant);
            enqueueSnackbar(t('common.success_update'), { variant: 'success' });
            load();
            onChanged?.();
        }
        catch (err: unknown) {
            enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
        }
    };
    const handleStartEdit = (det: DetalleMantenimiento) => {
        setEditingDet(det);
        setAddingDet(true);
        setDetAccion(det.DMA_Accion_Realizada);
        setDetAccionItem(acciones.find((a) => a.id === det.AMA_Accion) ?? null);
        setDetCosto(String(det.DMA_Costo_Item ?? 0));
    };
    const handleClose = async () => {
        if (!costoTotal || Number(costoTotal) < 0) {
            enqueueSnackbar(t('maintenance.errors.bad_cost'), { variant: 'warning' });
            return;
        }
        setClosing(true);
        try {
            await maintenanceService.close(ticket.MAN_Mantenimiento, {
                MAN_Costo_Total: Number(costoTotal),
                MAN_Moneda: moneda,
            });
            enqueueSnackbar(t('maintenance.closed_ok'), { variant: 'success' });
            onChanged?.();
            onClose();
        }
        catch (err: unknown) {
            enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
        }
        finally {
            setClosing(false);
        }
    };
    return (<Dialog open={open} onClose={onClose} maxWidth="md" fullWidth>
      <DialogTitle sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <Box>
          <Stack direction="row" spacing={1} alignItems="center">
            <Typography variant="h6">{t('maintenance.detail_title')}</Typography>
            <Chip size="small" icon={isClosed ? <CheckCircleIcon /> : undefined} label={isClosed
            ? t('common.closed')
            : t('maintenance.open')} color={isClosed ? 'success' : 'warning'}/>
          </Stack>
          <Typography variant="caption" color="text.secondary">
            {ticket.activo?.ACT_Codigo_Interno ?? '—'}
          </Typography>
        </Box>
        <IconButton onClick={onClose}><CloseIcon /></IconButton>
      </DialogTitle>

      <DialogContent dividers>
        <Stack spacing={2}>
        <SectionCard title={t('maintenance.sections.general')}>
        <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 3 }}>
          <Box sx={{ minWidth: 200, flex: '1 1 200px' }}>
            <Typography variant="caption" color="text.secondary">{t('maintenance.entry_date')}</Typography>
            <Typography>{ticket.MAN_Fecha_Ingreso?.slice(0, 19).replace('T', ' ')}</Typography>
          </Box>
          <Box sx={{ minWidth: 200, flex: '1 1 200px' }}>
            <Typography variant="caption" color="text.secondary">{t('maintenance.close_date')}</Typography>
            <Typography>{ticket.MAN_Fecha_Cierre?.slice(0, 19).replace('T', ' ') ?? '—'}</Typography>
          </Box>
          <Box sx={{ minWidth: 200, flex: '1 1 200px' }}>
            <Typography variant="caption" color="text.secondary">{t('maintenance.type')}</Typography>
            <Typography>{ticket.tipo_mantenimiento?.TMA_Nombre ?? '—'}</Typography>
          </Box>
          <Box sx={{ minWidth: 200, flex: '1 1 200px' }}>
            <Typography variant="caption" color="text.secondary">{t('maintenance.cost_total')}</Typography>
            <Typography>{formatMoney(ticket.MAN_Costo_Total ?? 0, ticket.MAN_Moneda, i18n.language)}</Typography>
          </Box>
          <Box sx={{ width: '100%' }}>
            <Typography variant="caption" color="text.secondary">{t('maintenance.description')}</Typography>
            <Typography>{ticket.MAN_Descripcion_Falla}</Typography>
          </Box>
        </Box>
        </SectionCard>

        <SectionCard noPadding title={t('maintenance.details_title')} actions={!isClosed && !addingDet ? (<Button size="small" startIcon={<AddIcon />} variant="outlined" onClick={() => { resetDetForm(); setAddingDet(true); }}>
              {t('maintenance.add_detail')}
            </Button>) : undefined}>
        {loading ? (<Box display="flex" justifyContent="center" py={3}><CircularProgress /></Box>) : detalles.length === 0 ? (<Box sx={{ p: 2.5 }}><EmptyState compact title={t('maintenance.no_details')}/></Box>) : (<TableContainer>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>{t('maintenance.col_action')}</TableCell>
                  <TableCell align="right">{t('maintenance.col_cost')}</TableCell>
                  {!isClosed && <TableCell align="right">{t('common.actions')}</TableCell>}
                </TableRow>
              </TableHead>
              <TableBody>
                {detalles.map((d) => (<TableRow key={d.DMA_Detalle_Mant} hover>
                    <TableCell>{d.DMA_Accion_Realizada}</TableCell>
                    <TableCell align="right">{formatMoney(d.DMA_Costo_Item, ticket.MAN_Moneda, i18n.language)}</TableCell>
                    {!isClosed && (<TableCell align="right">
                        <Tooltip title={t('common.edit') as string}>
                          <IconButton size="small" onClick={() => handleStartEdit(d)}>
                            <EditIcon fontSize="small"/>
                          </IconButton>
                        </Tooltip>
                        <Tooltip title={t('common.delete') as string}>
                          <IconButton size="small" color="error" onClick={() => handleDeleteDetalle(d)}>
                            <DeleteIcon fontSize="small"/>
                          </IconButton>
                        </Tooltip>
                      </TableCell>)}
                  </TableRow>))}
              </TableBody>
            </Table>
          </TableContainer>)}

        {addingDet && !isClosed && (<Box sx={{ m: 2.5, p: 2, bgcolor: 'action.hover', borderRadius: 1 }}>
            <Typography variant="subtitle2" gutterBottom>
              {editingDet ? t('common.edit') : t('maintenance.add_detail')}
            </Typography>
            <Stack direction={{ xs: 'column', md: 'row' }} spacing={2}>
              <Box sx={{ minWidth: 220 }}>
                <CatalogField size="small" clave="acciones-mantenimiento" required label={t('maintenance.col_action')}
                  value={detAccionItem?.id ?? null} onChange={(_v, item) => setDetAccionItem(item)}/>
              </Box>
              <TextField fullWidth size="small" label={t('returns.detail')} value={detAccion} onChange={(e) => setDetAccion(e.target.value)}
                error={pideDetalle(detAccionItem) && detAccion.trim().length < 5}/>
              <MoneyField size="small" label={t('maintenance.col_cost')} value={detCosto} onChange={setDetCosto} currency={ticket.MAN_Moneda ?? DEFAULT_CURRENCY} sx={{ width: 200 }}/>
              <Button onClick={resetDetForm}>{t('common.cancel')}</Button>
              <Button variant="contained" onClick={handleSaveDetalle} startIcon={<SaveIcon />}>
                {t('common.save')}
              </Button>
            </Stack>
          </Box>)}
        </SectionCard>

        {!isClosed && (<SectionCard title={t('maintenance.close_title')}>
            {!closing ? (<Button color="success" variant="contained" startIcon={<CheckCircleIcon />} onClick={() => setClosing(true)}>
                {t('maintenance.close_button')}
              </Button>) : (<Stack direction="row" spacing={2} alignItems="center">
                <MoneyField label={t('maintenance.cost_total')} value={costoTotal} onChange={setCostoTotal} currency={moneda} onCurrencyChange={setMoneda} size="small" sx={{ width: 240 }}/>
                <Button onClick={() => setClosing(false)}>{t('common.cancel')}</Button>
                <Button variant="contained" color="success" onClick={handleClose} startIcon={<CheckCircleIcon />}>
                  {t('maintenance.confirm_close')}
                </Button>
              </Stack>)}
          </SectionCard>)}
        </Stack>
      </DialogContent>

      <DialogActions>
        <Button onClick={onClose}>{t('common.cancel')}</Button>
      </DialogActions>
    </Dialog>);
};
