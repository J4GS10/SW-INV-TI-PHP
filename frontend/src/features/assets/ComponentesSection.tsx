import { useCallback, useEffect, useState } from 'react';
import { catalogService } from '../catalogs/catalogService';
import type { TipoActivo } from '../../types/inventory';
import {
  Alert, Autocomplete, Box, Button, CircularProgress, IconButton, List, ListItem, ListItemText, Stack,
  TextField, Tooltip, Typography,
} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import LinkOffIcon from '@mui/icons-material/LinkOff';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { inventarioService } from '../../api/inventarioService';
import { getApiErrorMessage } from '../../api/errors';
import { assetService } from './assetService';
import { EmptyState, KardexTimeline } from '../../components/ui';
import type { Activo } from '../../types/inventory';
import type { ComponentesActivo } from '../../types/kardex';

interface Props {
  activoId: string;
  canOperate: boolean;
  onChanged?: () => void;
  /** Tipo del equipo y catálogo de tipos: solo se ofrecen los componentes que admite. */
  tipoEquipo?: number;
  tipos?: TipoActivo[];
}

/**
 * Componentes instalados en el equipo (disco, memoria, monitor, docking…) y su
 * historial. Instalar o retirar deja el evento en el kardex de ambos equipos.
 */
export const ComponentesSection = ({ activoId, canOperate, onChanged, tipoEquipo, tipos = [] }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const [data, setData] = useState<ComponentesActivo | null>(null);
  const [cargando, setCargando] = useState(true);
  const [agregando, setAgregando] = useState(false);
  const [busqueda, setBusqueda] = useState('');
  const [opciones, setOpciones] = useState<Activo[]>([]);
  const [elegido, setElegido] = useState<Activo | null>(null);
  const [motivo, setMotivo] = useState('');
  const [retirando, setRetirando] = useState<string | null>(null);
  const [guardando, setGuardando] = useState(false);
  const [permitidos, setPermitidos] = useState<number[] | null>(null);

  useEffect(() => {
    if (tipoEquipo == null) { setPermitidos(null); return; }
    catalogService.getComponentesTipo(tipoEquipo).then(setPermitidos).catch(() => setPermitidos(null));
  }, [tipoEquipo]);
  // Sin lista propia del tipo: cualquier tipo de clase «Componente».
  const admite = (a: Activo) => (permitidos && permitidos.length
    ? permitidos.includes(a.TAC_Tipo_Activo)
    : tipos.length === 0 || tipos.find((x) => x.TAC_Tipo_Activo === a.TAC_Tipo_Activo)?.TAC_Clase === 'COMPONENTE');

  const cargar = useCallback(async () => {
    setCargando(true);
    try {
      setData(await inventarioService.componentes(activoId));
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_loading')), { variant: 'error' });
    } finally {
      setCargando(false);
    }
  }, [activoId, enqueueSnackbar, t]);

  useEffect(() => { cargar(); }, [cargar]);

  useEffect(() => {
    const q = busqueda.trim();
    if (!agregando || q.length < 2) return;
    let vivo = true;
    const tm = window.setTimeout(async () => {
      try {
        const r = await assetService.searchActivos({ q, per_page: 20 });
        if (vivo) setOpciones(r.items.filter((a) => a.ACT_Activo !== activoId && !a.ACT_Activo_Padre && admite(a)));
      } catch {
        if (vivo) setOpciones([]);
      }
    }, 250);
    return () => { vivo = false; window.clearTimeout(tm); };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- admite depende de permitidos/tipos, ya listados
  }, [activoId, agregando, busqueda, permitidos, tipos]);

  const instalar = async () => {
    if (!elegido) return;
    setGuardando(true);
    try {
      setData(await inventarioService.instalarComponente(activoId, elegido.ACT_Activo, motivo));
      enqueueSnackbar(t('components.installed_ok', { code: elegido.ACT_Codigo_Interno }), { variant: 'success' });
      setAgregando(false);
      setElegido(null);
      setMotivo('');
      onChanged?.();
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
    } finally {
      setGuardando(false);
    }
  };

  const retirar = async (componenteId: string, codigo: string) => {
    setGuardando(true);
    try {
      setData(await inventarioService.retirarComponente(activoId, componenteId, motivo));
      enqueueSnackbar(t('components.removed_ok', { code: codigo }), { variant: 'success' });
      setRetirando(null);
      setMotivo('');
      onChanged?.();
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
    } finally {
      setGuardando(false);
    }
  };

  if (cargando && !data) return <Box display="flex" justifyContent="center" py={3}><CircularProgress /></Box>;

  return (
    <Stack spacing={2}>
      {data?.instalado_en && <Alert severity="info">{t('components.is_component')}</Alert>}
      {data && data.actuales.length === 0 && !agregando && (
        <EmptyState compact title={t('components.empty')} description={t('components.empty_hint')}
          action={canOperate ? { label: t('components.install'), onClick: () => setAgregando(true) } : undefined} />
      )}
      {data && data.actuales.length > 0 && (
        <List dense>
          {data.actuales.map((c) => (
            <ListItem key={c.ACT_Activo} disableGutters secondaryAction={canOperate && retirando !== c.ACT_Activo && (
              <Tooltip title={t('components.remove')}>
                <IconButton edge="end" onClick={() => { setRetirando(c.ACT_Activo); setMotivo(''); }}><LinkOffIcon /></IconButton>
              </Tooltip>)}>
              <ListItemText primary={c.ACT_Codigo_Interno} secondary={c.ACT_Serie_Fabricante} />
              {retirando === c.ACT_Activo && (
                <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} sx={{ ml: 2, flex: 1 }}>
                  <TextField size="small" label={t('components.reason')} value={motivo} onChange={(e) => setMotivo(e.target.value)} />
                  <Button size="small" onClick={() => setRetirando(null)}>{t('common.cancel')}</Button>
                  <Button size="small" variant="contained" disabled={guardando}
                    onClick={() => retirar(c.ACT_Activo, c.ACT_Codigo_Interno)}>{t('components.remove')}</Button>
                </Stack>
              )}
            </ListItem>
          ))}
        </List>
      )}
      {canOperate && data && data.actuales.length > 0 && !agregando && (
        <Box><Button startIcon={<AddIcon />} onClick={() => setAgregando(true)}>{t('components.install')}</Button></Box>
      )}
      {agregando && (
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} alignItems={{ sm: 'center' }}>
          <Autocomplete sx={{ flex: 1, minWidth: 240 }} options={opciones} value={elegido} onChange={(_, v) => setElegido(v)}
            onInputChange={(_, v, r) => { if (r === 'input') setBusqueda(v); }} filterOptions={(x) => x}
            getOptionLabel={(a) => [a.ACT_Codigo_Interno, a.ACT_Serie_Fabricante].filter(Boolean).join(' · ')}
            isOptionEqualToValue={(a, b) => a.ACT_Activo === b.ACT_Activo}
            noOptionsText={busqueda.trim().length < 2 ? t('operations.dialog.type_to_search_asset') : t('operations.dialog.no_asset_found', { q: busqueda.trim() })}
            renderInput={(p) => <TextField {...p} size="small" label={t('components.component')} />} />
          <TextField size="small" label={t('components.reason')} value={motivo} onChange={(e) => setMotivo(e.target.value)} />
          <Button onClick={() => { setAgregando(false); setElegido(null); }}>{t('common.cancel')}</Button>
          <Button variant="contained" onClick={instalar} disabled={!elegido || guardando}>{t('components.install')}</Button>
        </Stack>
      )}
      {data && data.historial.length > 0 && (
        <Box>
          <Typography variant="subtitle2" gutterBottom>{t('components.history')}</Typography>
          <KardexTimeline eventos={data.historial} mostrarReferencia />
        </Box>
      )}
    </Stack>
  );
};
