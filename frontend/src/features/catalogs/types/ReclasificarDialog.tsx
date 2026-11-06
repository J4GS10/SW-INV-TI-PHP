import { useEffect, useState } from 'react';
import { Alert, Button, CircularProgress, DialogActions, DialogContent, DialogTitle, List, ListItem, ListItemText, MenuItem, Stack, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../../api/errors';
import { catalogService } from '../catalogService';
import type { TipoActivo } from '../../../types/inventory';
import { Dialog } from '../../../components/ui/Dialog';

type Vista = Awaited<ReturnType<typeof catalogService.vistaReclasificacion>>;

/**
 * Pasa a consumible los activos de un tipo «insumo» (aire comprimido, pasta
 * térmica…) de una sede. Se solicita y lo ejecuta otro administrador de TI al aprobarlo:
 * cada activo queda de baja «Reclasificado» y el consumible recibe la entrada.
 */
export const ReclasificarDialog = ({ tipo, onClose }: { tipo: TipoActivo | null; onClose: (cambio?: boolean) => void }) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const [vista, setVista] = useState<Vista | null>(null);
  const [sede, setSede] = useState<number | ''>('');
  const [nombre, setNombre] = useState('');
  const [unidad, setUnidad] = useState('unidad');
  const [motivo, setMotivo] = useState('');
  const [enviando, setEnviando] = useState(false);

  useEffect(() => {
    if (!tipo) return;
    setVista(null);
    setNombre(tipo.TAC_Nombre);
    setMotivo('');
    catalogService.vistaReclasificacion(tipo.TAC_Tipo_Activo).then((v) => {
      setVista(v);
      setSede(v.por_sede.length === 1 && v.por_sede[0].SED_Sede != null ? v.por_sede[0].SED_Sede : '');
    }).catch((e) => enqueueSnackbar(getApiErrorMessage(e, t('common.error_loading')), { variant: 'error' }));
  }, [tipo, enqueueSnackbar, t]);

  const enviar = async () => {
    if (!tipo || sede === '') return;
    setEnviando(true);
    try {
      await catalogService.solicitarReclasificacion(tipo.TAC_Tipo_Activo, { SED_Sede: sede, nombre: nombre.trim(), CON_Unidad: unidad, motivo: motivo.trim() });
      enqueueSnackbar(t('reclassify.requested'), { variant: 'success' });
      onClose(true);
    } catch (e) {
      enqueueSnackbar(getApiErrorMessage(e, t('common.error_generic')), { variant: 'error' });
    } finally {
      setEnviando(false);
    }
  };

  const valido = sede !== '' && nombre.trim().length >= 2 && motivo.trim().length >= 5 && !!vista && vista.bloqueados.length === 0;
  return (
    <Dialog open={!!tipo} onClose={() => onClose()} maxWidth="sm" fullWidth>
      <DialogTitle>{t('reclassify.title', { tipo: tipo?.TAC_Nombre ?? '' })}</DialogTitle>
      <DialogContent>
        {!vista ? <CircularProgress size={24}/> : (
          <Stack spacing={2} sx={{ pt: 1 }}>
            <Alert severity="info">{t('reclassify.explain', { count: vista.activos })}</Alert>
            {vista.bloqueados.length > 0 && (
              <Alert severity="warning">
                {t('reclassify.blocked')}
                <List dense>
                  {vista.bloqueados.slice(0, 10).map((b) => (
                    <ListItem key={b.codigo} disableGutters>
                      <ListItemText primary={b.codigo} secondary={t(`reclassify.reasons.${b.motivo}`)}/>
                    </ListItem>
                  ))}
                </List>
              </Alert>
            )}
            <TextField select label={t('reclassify.site')} value={sede} onChange={(e) => setSede(Number(e.target.value))}>
              {vista.por_sede.filter((s) => s.SED_Sede != null).map((s) => (
                <MenuItem key={s.SED_Sede} value={s.SED_Sede as number}>{`${s.sede ?? s.SED_Sede} · ${t('reclassify.count', { count: s.activos })}`}</MenuItem>
              ))}
            </TextField>
            <TextField label={t('reclassify.consumable_name')} value={nombre} onChange={(e) => setNombre(e.target.value)}
              helperText={t('reclassify.consumable_help')} inputProps={{ maxLength: 100 }}/>
            <TextField label={t('consumables.field_unit')} value={unidad} onChange={(e) => setUnidad(e.target.value)} inputProps={{ maxLength: 20 }}/>
            <TextField label={t('reclassify.reason')} value={motivo} onChange={(e) => setMotivo(e.target.value)} multiline minRows={2}
              inputProps={{ maxLength: 500 }}/>
            <Typography variant="caption" color="text.secondary">{t('reclassify.approval_note')}</Typography>
          </Stack>
        )}
      </DialogContent>
      <DialogActions>
        <Button color="inherit" onClick={() => onClose()}>{t('common.cancel')}</Button>
        <Button variant="contained" disabled={!valido || enviando} onClick={enviar}>{t('reclassify.submit')}</Button>
      </DialogActions>
    </Dialog>
  );
};
