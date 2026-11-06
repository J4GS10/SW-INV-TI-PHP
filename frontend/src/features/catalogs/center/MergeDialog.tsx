import { useEffect, useState } from 'react';
import { Autocomplete, Button, CircularProgress, DialogActions, DialogContent, DialogTitle, List, ListItem, ListItemText, Stack, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { catalogoService, type CatalogoClave, type CatalogoItem, type FusionPrevia } from '../../../api/catalogoService';
import { getApiErrorMessage } from '../../../api/errors';
import { catalogItemLabel } from '../../../utils/catalogLabels';
import { Dialog } from '../../../components/ui/Dialog';

interface Props {
  open: boolean;
  clave: CatalogoClave;
  origen: CatalogoItem | null;
  items: CatalogoItem[];
  onClose: (hecho: boolean) => void;
}

/**
 * Fusionar un duplicado («Kingstom» en «Kingston»): se elige el destino, se
 * muestra cuántos registros se moverán y, al confirmar, el origen queda
 * inactivo con la referencia al destino.
 */
export const MergeDialog = ({ open, clave, origen, items, onClose }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const [destino, setDestino] = useState<CatalogoItem | null>(null);
  const [previa, setPrevia] = useState<FusionPrevia | null>(null);
  const [cargando, setCargando] = useState(false);
  const [enviando, setEnviando] = useState(false);

  useEffect(() => {
    if (!open) return;
    setDestino(null);
    setPrevia(null);
  }, [open]);

  useEffect(() => {
    if (!origen || !destino) {
      setPrevia(null);
      return;
    }
    setCargando(true);
    catalogoService.previaFusion(clave, origen.id, destino.id)
      .then(setPrevia)
      .catch((err) => enqueueSnackbar(getApiErrorMessage(err, t('common.error_loading')), { variant: 'error' }))
      .finally(() => setCargando(false));
  }, [clave, origen, destino, enqueueSnackbar, t]);

  const fusionar = async () => {
    if (!origen || !destino) return;
    setEnviando(true);
    try {
      await catalogoService.fusionar(clave, origen.id, destino.id);
      enqueueSnackbar(t('catalog_center.merge_done', {
        from: catalogItemLabel(t, clave, origen), to: catalogItemLabel(t, clave, destino),
      }), { variant: 'success' });
      onClose(true);
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
    } finally {
      setEnviando(false);
    }
  };

  return (
    <Dialog open={open} onClose={() => onClose(false)} maxWidth="sm" fullWidth>
      <DialogTitle>{t('catalog_center.merge_title', { name: origen ? catalogItemLabel(t, clave, origen) : '' })}</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ mt: 1 }}>
          <Typography variant="body2" color="text.secondary">{t('catalog_center.merge_help')}</Typography>
          {clave === 'tipos-especificacion' && (
            <Typography variant="body2" color="text.secondary">{t('catalog_center.merge_help_specs')}</Typography>
          )}
          <Autocomplete
            options={items.filter((i) => i.id !== origen?.id)}
            value={destino}
            onChange={(_e, v) => setDestino(v)}
            getOptionLabel={(o) => catalogItemLabel(t, clave, o)}
            isOptionEqualToValue={(a, b) => a.id === b.id}
            noOptionsText={t('catalog_center.merge_no_target')}
            renderInput={(params) => <TextField {...params} label={t('catalog_center.merge_target')} autoFocus />}
          />
          {cargando && <CircularProgress size={20} />}
          {previa && !cargando && (
            previa.total === 0 ? (
              <Typography variant="body2">{t('catalog_center.merge_nothing')}</Typography>
            ) : (
              <>
                <Typography variant="body2" fontWeight={600}>
                  {t('catalog_center.merge_preview', { count: previa.total })}
                </Typography>
                <List dense disablePadding>
                  {Object.entries(previa.conteos).map(([donde, n]) => (
                    <ListItem key={donde} disableGutters>
                      <ListItemText primary={t(`catalog_center.refs.${donde.replace('.', '__')}`, { defaultValue: donde })}
                        secondary={n} />
                    </ListItem>
                  ))}
                </List>
              </>
            )
          )}
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={() => onClose(false)}>{t('common.cancel')}</Button>
        <Button variant="contained" color="warning" onClick={() => void fusionar()} disabled={!destino || enviando || cargando}>
          {t('catalog_center.merge_confirm')}
        </Button>
      </DialogActions>
    </Dialog>
  );
};
