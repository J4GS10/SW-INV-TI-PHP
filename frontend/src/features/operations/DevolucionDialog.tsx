import { useEffect, useState } from 'react';
import { Alert, Autocomplete, Button, CircularProgress, DialogActions, DialogContent, DialogTitle, FormControlLabel, Radio, RadioGroup, Stack, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { useNavigate } from 'react-router-dom';
import { inventarioService } from '../../api/inventarioService';
import { getApiErrorMessage } from '../../api/errors';
import { downloadFile } from '../../api/downloadCsv';
import { movementService } from './movementService';
import type { DevolucionResultado } from '../../types/kardex';
import type { CatalogoItem } from '../../api/catalogoService';
import { CatalogField } from '../../components/CatalogField';
import { pideDetalle } from '../../hooks/useCatalogo';
import { Dialog } from '../../components/ui/Dialog';

type AreaRuta = { ARE_Area: number; ruta: string; SED_Sede?: number | null; tipo_area?: string | null };

interface Props {
  open: boolean;
  /** Equipo a devolver (código y custodio solo para mostrar). */
  activo: { ACT_Activo: string; ACT_Codigo_Interno: string; SED_Sede?: number | null } | null;
  custodio?: string | null;
  onClose: (changed: boolean) => void;
}

/**
 * Devolución de un equipo a bodega: motivo, dónde queda y en qué estado llega.
 * Al terminar confirma lo hecho y propone el siguiente paso (hoja de descargo;
 * si llegó con daño, abrir un ticket de mantenimiento).
 */
export const DevolucionDialog = ({ open, activo, custodio, onClose }: Props) => {
  const { t, i18n } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const navigate = useNavigate();
  const [areas, setAreas] = useState<AreaRuta[]>([]);
  const [motivo, setMotivo] = useState<CatalogoItem | null>(null);
  const [detalle, setDetalle] = useState('');
  const [area, setArea] = useState<AreaRuta | null>(null);
  const [condicion, setCondicion] = useState<'BUENO' | 'CON_DANO'>('BUENO');
  const [enviando, setEnviando] = useState(false);
  const [resultado, setResultado] = useState<DevolucionResultado | null>(null);

  useEffect(() => {
    if (!open) return;
    setMotivo(null);
    setDetalle('');
    setArea(null);
    setCondicion('BUENO');
    setResultado(null);
    movementService.getAreas().then((a) => {
      const lista = a as AreaRuta[];
      setAreas(lista);
      // Si la sede tiene una sola bodega, se propone (el usuario puede cambiarla).
      const bodegas = lista.filter((x) => x.tipo_area === 'BODEGA' && (!activo?.SED_Sede || x.SED_Sede === activo.SED_Sede));
      if (bodegas.length === 1) setArea(bodegas[0]);
    }).catch(() => setAreas([]));
  }, [open, activo?.SED_Sede]);

  // Las bodegas primero: es donde queda normalmente un equipo devuelto.
  const areasSede = areas.filter((a) => !activo?.SED_Sede || a.SED_Sede === activo.SED_Sede)
    .sort((a, b) => Number(b.tipo_area === 'BODEGA') - Number(a.tipo_area === 'BODEGA'));
  const faltaDetalle = pideDetalle(motivo) && !detalle.trim();

  const devolver = async () => {
    if (!activo) return;
    setEnviando(true);
    try {
      setResultado(await inventarioService.devolver({
        ACT_Activo: activo.ACT_Activo, MMO_Motivo: motivo?.id ?? null, MOV_Motivo_Detalle: detalle.trim() || null,
        ARE_Area_Destino: area?.ARE_Area ?? null, condicion,
      }));
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
    } finally {
      setEnviando(false);
    }
  };

  const hojaDescargo = async () => {
    if (!resultado) return;
    try {
      await downloadFile(`/trazabilidad/acta/${resultado.MOV_Movimiento}`, 'descargo.pdf',
        { formato: 'pdf', tipo: 'descargo', lang: i18n.language.split('-')[0] });
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
    }
  };

  return (
    <Dialog open={open} onClose={() => onClose(!!resultado)} maxWidth="sm" fullWidth>
      <DialogTitle>{t('returns.title', { code: activo?.ACT_Codigo_Interno ?? '' })}</DialogTitle>
      <DialogContent>
        {resultado ? (
          <Stack spacing={2} sx={{ mt: 1 }}>
            <Alert severity="success">
              {t('returns.done', { code: activo?.ACT_Codigo_Interno ?? '', state: resultado.estado })}
            </Alert>
            {resultado.siguientes_pasos.includes('ABRIR_TICKET_MANTENIMIENTO') && (
              <Alert severity="warning">{t('returns.damage_hint')}</Alert>
            )}
            <Typography variant="body2" color="text.secondary">{t('returns.next')}</Typography>
          </Stack>
        ) : (
          <Stack spacing={2} sx={{ mt: 1 }}>
            {custodio && <Typography variant="body2">{t('returns.current_custodian', { name: custodio })}</Typography>}
            <CatalogField clave="motivos-movimiento" aplicaA="DEVOLUCION" label={t('movement_reason.reason')} required
              value={motivo?.id ?? null} onChange={(_v, item) => setMotivo(item)} />
            <TextField label={t('movement_reason.note')} value={detalle} onChange={(e) => setDetalle(e.target.value)}
              inputProps={{ maxLength: 200 }} helperText={faltaDetalle ? t('returns.detail_required') : undefined}
              error={faltaDetalle} />
            <Autocomplete options={areasSede} value={area} onChange={(_, v) => setArea(v)}
              getOptionLabel={(a) => a.ruta} isOptionEqualToValue={(a, b) => a.ARE_Area === b.ARE_Area}
              renderInput={(p) => <TextField {...p} label={t('returns.storage_area')} helperText={t('returns.storage_area_help')} />} />
            <div>
              <Typography variant="body2" gutterBottom>{t('returns.condition')}</Typography>
              <RadioGroup row value={condicion} onChange={(e) => setCondicion(e.target.value as 'BUENO' | 'CON_DANO')}>
                <FormControlLabel value="BUENO" control={<Radio />} label={t('returns.condition_ok')} />
                <FormControlLabel value="CON_DANO" control={<Radio />} label={t('returns.condition_damaged')} />
              </RadioGroup>
            </div>
          </Stack>
        )}
      </DialogContent>
      <DialogActions sx={{ p: 3, flexWrap: 'wrap', gap: 1 }}>
        {resultado ? (
          <>
            <Button color="inherit" onClick={() => onClose(true)}>{t('common.close')}</Button>
            {resultado.siguientes_pasos.includes('ABRIR_TICKET_MANTENIMIENTO') && (
              <Button onClick={() => { onClose(true); navigate(`/mantenimiento?nuevo=${activo?.ACT_Activo ?? ''}`); }}>
                {t('returns.open_ticket')}
              </Button>
            )}
            <Button variant="contained" onClick={hojaDescargo}>{t('returns.download_release')}</Button>
          </>
        ) : (
          <>
            <Button color="inherit" onClick={() => onClose(false)}>{t('common.cancel')}</Button>
            <Button variant="contained" onClick={devolver}
              disabled={enviando || !motivo || faltaDetalle}>
              {enviando ? <CircularProgress size={22} /> : t('returns.confirm')}
            </Button>
          </>
        )}
      </DialogActions>
    </Dialog>
  );
};
