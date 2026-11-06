import { useEffect, useState } from 'react';
import { Autocomplete, Button, CircularProgress, DialogActions, DialogContent, DialogTitle, FormControlLabel, Radio, RadioGroup, Stack, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../api/errors';
import { movementService } from './movementService';
import { personService } from '../persons/personService';
import type { PersonaResumen } from '../../types/users';
import type { CatalogoItem } from '../../api/catalogoService';
import { CatalogField } from '../../components/CatalogField';
import { pideDetalle } from '../../hooks/useCatalogo';
import { createSearchFilter } from '../../utils/autocomplete';
import { Dialog } from '../../components/ui/Dialog';

type AreaRuta = { ARE_Area: number; ruta: string; SED_Sede?: number | null; tipo_area?: string | null };

const nombre = (p: PersonaResumen) => [p.PER_Primer_Nombre, p.PER_Primer_Apellido].filter(Boolean).join(' ');
const filtroPersona = createSearchFilter<PersonaResumen>((p) => `${nombre(p)} ${p.PER_Email_Corporativo}`);

interface Props {
  /** «instalar» también sirve para reubicar un equipo ya instalado. */
  modo: 'instalar' | 'retirar' | null;
  activo: { ACT_Activo: string; ACT_Codigo_Interno: string; SED_Sede?: number | null; ACT_Instalado?: boolean } | null;
  onClose: (changed: boolean) => void;
}

/**
 * Equipos compartidos (impresora, switch, UPS): se instalan en un área con un
 * responsable opcional, se reubican o se retiran a bodega. No son custodia de
 * una persona. Un motivo del catálogo y una nota opcional, como todo movimiento.
 */
export const InstalacionAreaDialog = ({ modo, activo, onClose }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const [areas, setAreas] = useState<AreaRuta[]>([]);
  const [personas, setPersonas] = useState<PersonaResumen[]>([]);
  const [area, setArea] = useState<AreaRuta | null>(null);
  const [responsable, setResponsable] = useState<PersonaResumen | null>(null);
  const [motivo, setMotivo] = useState<CatalogoItem | null>(null);
  const [nota, setNota] = useState('');
  const [condicion, setCondicion] = useState<'BUENO' | 'CON_DANO'>('BUENO');
  const [enviando, setEnviando] = useState(false);

  useEffect(() => {
    if (!modo) return;
    setArea(null);
    setResponsable(null);
    setMotivo(null);
    setNota('');
    setCondicion('BUENO');
    movementService.getAreas().then((a) => setAreas(a as AreaRuta[])).catch(() => setAreas([]));
    if (modo === 'instalar') {
      personService.list().then((p) => setPersonas(p.filter((x) => x.PER_Estado !== false))).catch(() => setPersonas([]));
    }
  }, [modo]);

  const lista = modo === 'retirar'
    ? [...areas].sort((a, b) => Number(b.tipo_area === 'BODEGA') - Number(a.tipo_area === 'BODEGA'))
    : areas.filter((a) => a.tipo_area !== 'BODEGA');
  const faltaNota = pideDetalle(motivo) && !nota.trim();
  const reubicar = modo === 'instalar' && !!activo?.ACT_Instalado;

  const guardar = async () => {
    if (!activo || !modo) return;
    setEnviando(true);
    try {
      if (modo === 'instalar') {
        await movementService.instalarArea({
          ACT_Activo: activo.ACT_Activo, ARE_Area: area!.ARE_Area, PER_Responsable: responsable?.PER_Persona ?? null,
          MMO_Motivo: motivo?.id ?? null, MOV_Motivo_Detalle: nota.trim() || null,
        });
        enqueueSnackbar(t(reubicar ? 'shared.moved' : 'shared.installed', { code: activo.ACT_Codigo_Interno, area: area?.ruta }), { variant: 'success' });
      } else {
        await movementService.retirarArea({
          ACT_Activo: activo.ACT_Activo, ARE_Area_Destino: area?.ARE_Area ?? null, MMO_Motivo: motivo?.id ?? null,
          MOV_Motivo_Detalle: nota.trim() || null, condicion,
        });
        enqueueSnackbar(t('shared.removed', { code: activo.ACT_Codigo_Interno }), { variant: 'success' });
      }
      onClose(true);
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
    } finally {
      setEnviando(false);
    }
  };

  const titulo = modo === 'retirar' ? 'shared.remove_title' : reubicar ? 'shared.move_title' : 'shared.install_title';
  return (
    <Dialog open={!!modo} onClose={() => onClose(false)} maxWidth="sm" fullWidth>
      <DialogTitle>{t(titulo, { code: activo?.ACT_Codigo_Interno ?? '' })}</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ mt: 1 }}>
          <Typography variant="body2" color="text.secondary">{t(modo === 'retirar' ? 'shared.remove_help' : 'shared.install_help')}</Typography>
          <Autocomplete options={lista} value={area} onChange={(_, v) => setArea(v)}
            getOptionLabel={(a) => a.ruta} isOptionEqualToValue={(a, b) => a.ARE_Area === b.ARE_Area}
            renderInput={(p) => <TextField {...p} required={modo === 'instalar'}
              label={t(modo === 'retirar' ? 'returns.storage_area' : 'shared.area')}/>}/>
          {modo === 'instalar' && (
            <Autocomplete options={personas} value={responsable} onChange={(_, v) => setResponsable(v)}
              getOptionLabel={nombre} filterOptions={filtroPersona} isOptionEqualToValue={(a, b) => a.PER_Persona === b.PER_Persona}
              openOnFocus={false} noOptionsText={t('common.type_to_search')}
              renderInput={(p) => <TextField {...p} label={t('shared.responsible')} helperText={t('shared.responsible_help')}/>}/>
          )}
          <CatalogField clave="motivos-movimiento" aplicaA={modo === 'retirar' ? 'RETIRO' : 'INSTALACION'}
            label={t('movement_reason.reason')} required value={motivo?.id ?? null} onChange={(_v, item) => setMotivo(item)}/>
          <TextField label={t('movement_reason.note')} value={nota} onChange={(e) => setNota(e.target.value)}
            inputProps={{ maxLength: 255 }} error={faltaNota}
            helperText={faltaNota ? t('returns.detail_required') : t('movement_reason.note_help')}/>
          {modo === 'retirar' && (
            <RadioGroup row value={condicion} onChange={(e) => setCondicion(e.target.value as 'BUENO' | 'CON_DANO')}>
              <FormControlLabel value="BUENO" control={<Radio/>} label={t('returns.condition_ok')}/>
              <FormControlLabel value="CON_DANO" control={<Radio/>} label={t('returns.condition_damaged')}/>
            </RadioGroup>
          )}
        </Stack>
      </DialogContent>
      <DialogActions sx={{ p: 3 }}>
        <Button color="inherit" onClick={() => onClose(false)}>{t('common.cancel')}</Button>
        <Button variant="contained" onClick={guardar}
          disabled={enviando || !motivo || faltaNota || (modo === 'instalar' && !area)}>
          {enviando ? <CircularProgress size={22}/> : t(modo === 'retirar' ? 'shared.remove' : reubicar ? 'shared.move' : 'shared.install')}
        </Button>
      </DialogActions>
    </Dialog>
  );
};
