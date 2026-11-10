import { useCallback, useEffect, useState } from 'react';
import { Alert, Box, Button, Chip, CircularProgress, DialogActions, DialogContent, DialogTitle, Stack, Tab, TableCell, TableRow, Tabs, TextField, Typography } from '@mui/material';
import CheckIcon from '@mui/icons-material/Check';
import CloseIcon from '@mui/icons-material/Close';
import UndoIcon from '@mui/icons-material/Undo';
import { useTranslation } from 'react-i18next';
import { useCatalogLabel } from '../../hooks/useCatalogo';
import { useSnackbar } from 'notistack';
import { DataTableShell, PageHeader } from '../../components/ui';
import { aprobacionService } from '../../api/aprobacionService';
import { getApiErrorMessage } from '../../api/errors';
import { usePermissions } from '../../hooks/usePermissions';
import type { Aprobacion, EstadoAprobacion } from '../../types/aprobaciones';
import { formatDateTimeValue } from '../../utils/dates';
import { approvalSummaryLabel } from '../../utils/catalogLabels';
import { Dialog } from '../../components/ui/Dialog';
import { useLiveRefresh } from '../../hooks/useLiveRefresh';

const COLOR: Record<EstadoAprobacion, 'warning' | 'success' | 'error' | 'default'> = {
  PENDIENTE: 'warning', APROBADA: 'success', RECHAZADA: 'error', ANULADA: 'default',
};
type Accion = { tipo: 'aprobar' | 'rechazar'; solicitud: Aprobacion } | null;

/**
 * Bandeja de aprobaciones: bajas de activos y sobrantes de compras quedan
 * pendientes hasta que otro administrador de TI (otra persona) las aprueba o rechaza.
 * Al aprobar, el sistema ejecuta la operación tal como se pidió.
 */
export const AprobacionesPage = () => {
  const { t, i18n } = useTranslation();
  const motivoBaja = useCatalogLabel('motivos-baja');
  const destinoBaja = useCatalogLabel('destinos-baja');
  const { enqueueSnackbar } = useSnackbar();
  const { canApprove, canAdminInventory } = usePermissions();
  const [vista, setVista] = useState<'PENDIENTE' | 'HISTORIAL'>('PENDIENTE');
  const [filas, setFilas] = useState<Aprobacion[]>([]);
  const [loading, setLoading] = useState(true);
  const [accion, setAccion] = useState<Accion>(null);
  const [comentario, setComentario] = useState('');
  const [enviando, setEnviando] = useState(false);

  const cargar = useCallback(async () => {
    setLoading(true);
    try {
      const todas = await aprobacionService.listar(vista === 'PENDIENTE' ? { estado: 'PENDIENTE' } : {});
      setFilas(vista === 'PENDIENTE' ? todas : todas.filter((a) => a.APR_Estado !== 'PENDIENTE'));
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_loading')), { variant: 'error' });
    } finally {
      setLoading(false);
    }
  }, [enqueueSnackbar, t, vista]);

  useEffect(() => { cargar(); }, [cargar]);
  useLiveRefresh(cargar);

  const resolver = async () => {
    if (!accion) return;
    setEnviando(true);
    try {
      if (accion.tipo === 'aprobar') {
        await aprobacionService.aprobar(accion.solicitud.APR_Aprobacion, comentario.trim());
        enqueueSnackbar(t('approvals.approved_ok'), { variant: 'success' });
      } else {
        await aprobacionService.rechazar(accion.solicitud.APR_Aprobacion, comentario.trim());
        enqueueSnackbar(t('approvals.rejected_ok'), { variant: 'info' });
      }
      setAccion(null);
      setComentario('');
      cargar();
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
    } finally {
      setEnviando(false);
    }
  };

  const anular = async (a: Aprobacion) => {
    try {
      await aprobacionService.anular(a.APR_Aprobacion);
      enqueueSnackbar(t('approvals.cancelled_ok'), { variant: 'info' });
      cargar();
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
    }
  };

  const detalle = (a: Aprobacion) => {
    const p = a.APR_Payload as Record<string, unknown>;
    if (a.APR_Tipo === 'BAJA') {
      return [
        p.motivo ? motivoBaja(String(p.motivo)) : null,
        p.destino ? destinoBaja(String(p.destino)) : null,
        p.documento ? `${t('decommission.document')}: ${p.documento}` : null,
      ].filter(Boolean).join(' · ');
    }
    return a.APR_Motivo ?? '';
  };

  return (
    <Box>
      <PageHeader title={t('approvals.title')} subtitle={t('approvals.subtitle')} badge={vista === 'PENDIENTE' ? filas.length : undefined} />
      <Tabs value={vista} onChange={(_, v) => setVista(v)} sx={{ mb: 2 }}>
        <Tab value="PENDIENTE" label={t('approvals.tab_pending')} />
        <Tab value="HISTORIAL" label={t('approvals.tab_history')} />
      </Tabs>
      <DataTableShell
        columns={[
          { key: 'tipo', label: t('approvals.col_type'), sortValue: (a) => t(`approvals.types.${a.APR_Tipo}`) },
          { key: 'resumen', label: t('approvals.col_summary') },
          { key: 'solicitante', label: t('approvals.col_requester'), sortValue: (a) => a.solicitante_nombre },
          { key: 'estado', label: t('approvals.col_status'), sortValue: (a) => a.APR_Estado },
          { key: 'acciones', label: t('common.actions'), align: 'right' },
        ]}
        rows={filas}
        loading={loading}
        emptyTitle={vista === 'PENDIENTE' ? t('approvals.empty_pending') : t('common.no_records')}
        renderRow={(a) => (
          <TableRow key={a.APR_Aprobacion} hover>
            <TableCell><Chip size="small" label={t(`approvals.types.${a.APR_Tipo}`)} /></TableCell>
            <TableCell sx={{ maxWidth: 420 }}>
              <Typography variant="body2" fontWeight={600}>{approvalSummaryLabel(t, a.APR_Resumen)}</Typography>
              <Typography variant="caption" color="text.secondary" display="block">{detalle(a)}</Typography>
              {a.APR_Tipo === 'BAJA' && a.APR_Motivo && <Typography variant="caption" display="block">{a.APR_Motivo}</Typography>}
              {a.APR_Comentario && (
                <Typography variant="caption" display="block" color="text.secondary">
                  {t('approvals.resolution_note', { name: a.aprobador_nombre ?? '—' })}: {a.APR_Comentario}
                </Typography>
              )}
            </TableCell>
            <TableCell>
              <Typography variant="body2">{a.solicitante_nombre ?? '—'}</Typography>
              <Typography variant="caption" color="text.secondary">{formatDateTimeValue(a.APR_Fecha_Solicitud, i18n.language)}</Typography>
            </TableCell>
            <TableCell>
              <Chip size="small" variant="outlined" color={COLOR[a.APR_Estado]} label={t(`approvals.states.${a.APR_Estado}`)} />
              {a.APR_Fecha_Resolucion && (
                <Typography variant="caption" color="text.secondary" display="block">
                  {a.aprobador_nombre ? `${a.aprobador_nombre} · ` : ''}{formatDateTimeValue(a.APR_Fecha_Resolucion, i18n.language)}
                </Typography>
              )}
            </TableCell>
            <TableCell align="right">
              {a.APR_Estado === 'PENDIENTE' && (
                <Stack direction="row" spacing={1} justifyContent="flex-end">
                  {canApprove && !a.es_mia && (<>
                    <Button size="small" color="error" startIcon={<CloseIcon />} onClick={() => { setComentario(''); setAccion({ tipo: 'rechazar', solicitud: a }); }}>
                      {t('approvals.reject')}
                    </Button>
                    <Button size="small" variant="contained" color="success" startIcon={<CheckIcon />} onClick={() => { setComentario(''); setAccion({ tipo: 'aprobar', solicitud: a }); }}>
                      {t('approvals.approve')}
                    </Button>
                  </>)}
                  {a.es_mia && canAdminInventory && (
                    <Button size="small" startIcon={<UndoIcon />} onClick={() => anular(a)}>{t('approvals.cancel_request')}</Button>
                  )}
                  {a.es_mia && canApprove && (
                    <Typography variant="caption" color="text.secondary" sx={{ alignSelf: 'center' }}>{t('approvals.own_request')}</Typography>
                  )}
                </Stack>
              )}
            </TableCell>
          </TableRow>
        )}
      />

      <Dialog open={!!accion} onClose={() => setAccion(null)} maxWidth="sm" fullWidth>
        <DialogTitle>{accion?.tipo === 'aprobar' ? t('approvals.approve_title') : t('approvals.reject_title')}</DialogTitle>
        <DialogContent>
          <Typography variant="body2" sx={{ mb: 1 }}>{approvalSummaryLabel(t, accion?.solicitud.APR_Resumen)}</Typography>
          {accion?.tipo === 'aprobar' && (
            <Alert severity={accion.solicitud.APR_Tipo === 'BAJA' ? 'warning' : 'info'} sx={{ mb: 2 }}>
              {t(`approvals.approve_effect.${accion.solicitud.APR_Tipo}`)}
            </Alert>
          )}
          <TextField fullWidth multiline minRows={2} autoFocus
            label={accion?.tipo === 'aprobar' ? t('approvals.comment_optional') : t('approvals.reject_reason')}
            value={comentario} onChange={(e) => setComentario(e.target.value)} inputProps={{ maxLength: 500 }}
            helperText={accion?.tipo === 'rechazar' ? t('procurement.recv_justification_help') : undefined} />
        </DialogContent>
        <DialogActions>
          <Button color="inherit" onClick={() => setAccion(null)}>{t('common.cancel')}</Button>
          <Button variant="contained" color={accion?.tipo === 'aprobar' ? 'success' : 'error'} onClick={resolver}
            disabled={enviando || (accion?.tipo === 'rechazar' && comentario.trim().length < 5)}>
            {enviando ? <CircularProgress size={20} /> : accion?.tipo === 'aprobar' ? t('approvals.approve') : t('approvals.reject')}
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
};
