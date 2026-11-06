import { useCallback, useEffect, useState } from 'react';
import { Box, Button, Chip, CircularProgress, DialogActions, DialogContent, DialogTitle, Table, TableBody, TableCell, TableContainer, TableHead, TableRow } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { softwareService } from './softwareService';
import { getApiErrorMessage } from '../../api/errors';
import { EmptyState } from '../../components/ui';
import type { HistorialLicencia, Licencia } from '../../types/software';
import { formatDate } from '../../utils/dates';
import { Dialog } from '../../components/ui/Dialog';

interface Props {
  licencia: Licencia | null;
  titulo?: string;
  onClose: () => void;
}

/** A quién o a qué equipo se asignó la licencia, cuándo y por qué se retiró. */
export const LicenseHistoryDialog = ({ licencia, titulo, onClose }: Props) => {
  const { t, i18n } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const [filas, setFilas] = useState<HistorialLicencia[]>([]);
  const [cargando, setCargando] = useState(false);

  const cargar = useCallback(async () => {
    if (!licencia) return;
    setCargando(true);
    try {
      setFilas(await softwareService.historialLicencia(licencia.LIC_Licencia));
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_loading')), { variant: 'error' });
    } finally {
      setCargando(false);
    }
  }, [licencia, enqueueSnackbar, t]);

  useEffect(() => { cargar(); }, [cargar]);

  return (
    <Dialog open={!!licencia} onClose={onClose} maxWidth="md" fullWidth>
      <DialogTitle>{t('software.history_title', { name: titulo ?? '' })}</DialogTitle>
      <DialogContent dividers>
        {cargando ? (<Box display="flex" justifyContent="center" py={3}><CircularProgress /></Box>)
          : filas.length === 0 ? (<EmptyState compact title={t('software.history_empty')} />)
          : (
            <TableContainer>
              <Table size="small">
                <TableHead><TableRow>
                  <TableCell>{t('software.history_target')}</TableCell>
                  <TableCell>{t('software.history_installed')}</TableCell>
                  <TableCell>{t('software.history_removed')}</TableCell>
                  <TableCell>{t('software.history_reason')}</TableCell>
                  <TableCell>{t('procurement.status')}</TableCell>
                </TableRow></TableHead>
                <TableBody>
                  {filas.map((f) => (
                    <TableRow key={f.INS_Instalacion}>
                      <TableCell>{f.destino === 'ACTIVO' ? f.activo_codigo ?? '—' : f.persona_nombre ?? '—'}</TableCell>
                      <TableCell>{formatDate(f.INS_Fecha_Instalacion, i18n.language)}</TableCell>
                      <TableCell>{f.INS_Fecha_Retiro ? formatDate(f.INS_Fecha_Retiro, i18n.language) : '—'}</TableCell>
                      <TableCell>{f.INS_Motivo_Retiro ?? '—'}</TableCell>
                      <TableCell>
                        <Chip size="small" variant="outlined" color={f.INS_Estado ? 'success' : 'default'}
                          label={f.INS_Estado ? t('software.history_active') : t('software.history_released')} />
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </TableContainer>
          )}
      </DialogContent>
      <DialogActions><Button onClick={onClose}>{t('common.close')}</Button></DialogActions>
    </Dialog>
  );
};
