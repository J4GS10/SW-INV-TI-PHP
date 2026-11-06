import { useEffect, useState } from 'react';
import { Autocomplete, Box, Button, Chip, CircularProgress, DialogActions, DialogContent, DialogTitle, FormControlLabel, Radio, RadioGroup, Stack, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import type { CatalogoItem } from '../../api/catalogoService';
import { CatalogField } from '../../components/CatalogField';
import { pideDetalle } from '../../hooks/useCatalogo';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../api/errors';
import { productividadService, type ResultadoLote } from '../../api/productividadService';
import type { Activo } from '../../types/inventory';
import type { Area } from '../../types/geo';
import { movementService } from './movementService';
import { EntregaResultado } from './EntregaResultado';
import { Dialog } from '../../components/ui/Dialog';


interface Props {
  open: boolean;
  activos: Activo[];
  onClose: (changed: boolean) => void;
}

/** Devolución a bodega de varios equipos a la vez (todo o nada) con una sola hoja de descargo. */
export const DevolucionLoteDialog = ({ open, activos, onClose }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const [areas, setAreas] = useState<Area[]>([]);
  const [area, setArea] = useState<Area | null>(null);
  const [motivo, setMotivo] = useState<CatalogoItem | null>(null);
  const [detalle, setDetalle] = useState('');
  const [condicion, setCondicion] = useState<'BUENO' | 'CON_DANO'>('BUENO');
  const [enviando, setEnviando] = useState(false);
  const [resultado, setResultado] = useState<ResultadoLote | null>(null);

  useEffect(() => {
    if (!open) return;
    setResultado(null); setMotivo(null); setDetalle(''); setArea(null); setCondicion('BUENO');
    movementService.getAreas().then((a) => {
      setAreas(a);
      // Si hay una sola bodega para los equipos elegidos, se propone (se puede cambiar).
      const sedes = new Set(activos.map((x) => (x as { SED_Sede?: number | null }).SED_Sede).filter(Boolean));
      const bodegas = (a as (Area & { tipo_area?: string; SED_Sede?: number | null })[]).filter((x) => x.tipo_area === 'BODEGA' && (sedes.size === 0 || sedes.has(x.SED_Sede)));
      if (bodegas.length === 1) setArea(bodegas[0]);
    }).catch(() => setAreas([]));
  }, [open]);

  const enviar = async () => {
    setEnviando(true);
    try {
      setResultado(await productividadService.devolucionLote({
        activos: activos.map((a) => a.ACT_Activo), MMO_Motivo: motivo?.id ?? null, MOV_Motivo_Detalle: detalle.trim() || null,
        ARE_Area_Destino: area?.ARE_Area ?? null, condicion,
      }));
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error', autoHideDuration: 8000 });
    } finally {
      setEnviando(false);
    }
  };

  return (
    <Dialog open={open} onClose={() => onClose(!!resultado)} maxWidth="sm" fullWidth>
      <DialogTitle sx={{ fontWeight: 700 }}>{t('returns_batch.title', { count: activos.length })}</DialogTitle>
      <DialogContent dividers>
        {resultado ? (
          <EntregaResultado resultado={resultado} tipoActa="descargo"
            mensaje={t('returns_batch.done', { count: resultado.total })} />
        ) : (
          <Stack spacing={2}>
            <Box>
              <Stack direction="row" useFlexGap flexWrap="wrap" gap={0.5}>
                {activos.map((a) => <Chip key={a.ACT_Activo} size="small" label={a.ACT_Codigo_Interno} />)}
              </Stack>
            </Box>
            <CatalogField clave="motivos-movimiento" aplicaA="DEVOLUCION" label={t('movement_reason.reason')} required
              value={motivo?.id ?? null} onChange={(_v, item) => setMotivo(item)} />
            <TextField label={t('movement_reason.note')} value={detalle} onChange={(e) => setDetalle(e.target.value)}
              inputProps={{ maxLength: 200 }} helperText={pideDetalle(motivo) ? t('returns.detail_required') : undefined}
              error={pideDetalle(motivo) && !detalle.trim()} />
            <Autocomplete options={areas} value={area} onChange={(_, v) => setArea(v)}
              getOptionLabel={(a) => a.ARE_Nombre} isOptionEqualToValue={(a, b) => a.ARE_Area === b.ARE_Area}
              renderInput={(p) => <TextField {...p} label={t('returns.storage_area')} helperText={t('returns.storage_area_help')} />} />
            <Box>
              <Typography variant="body2" gutterBottom>{t('returns.condition')}</Typography>
              <RadioGroup row value={condicion} onChange={(e) => setCondicion(e.target.value as 'BUENO' | 'CON_DANO')}>
                <FormControlLabel value="BUENO" control={<Radio />} label={t('returns.condition_ok')} />
                <FormControlLabel value="CON_DANO" control={<Radio />} label={t('returns.condition_damaged')} />
              </RadioGroup>
            </Box>
            <Typography variant="caption" color="text.secondary">{t('returns_batch.all_or_nothing')}</Typography>
          </Stack>
        )}
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        {resultado ? (
          <Button variant="contained" onClick={() => onClose(true)}>{t('common.close')}</Button>
        ) : (
          <>
            <Button color="inherit" onClick={() => onClose(false)}>{t('common.cancel')}</Button>
            <Button variant="contained" onClick={enviar} disabled={enviando || !motivo || (pideDetalle(motivo) && !detalle.trim())}
              startIcon={enviando ? <CircularProgress size={16} color="inherit" /> : undefined}>
              {t('returns_batch.submit', { count: activos.length })}
            </Button>
          </>
        )}
      </DialogActions>
    </Dialog>
  );
};
