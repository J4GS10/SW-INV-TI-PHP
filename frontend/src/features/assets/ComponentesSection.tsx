import { useCallback, useEffect, useState } from 'react';
import { catalogService } from '../catalogs/catalogService';
import type { TipoActivo } from '../../types/inventory';
import {
  Alert, Autocomplete, Box, Button, CircularProgress, IconButton, List, ListItem, ListItemText, Stack,
  TextField, Tooltip, Typography,
} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import LinkOffIcon from '@mui/icons-material/LinkOff';
import AttachFileIcon from '@mui/icons-material/AttachFile';
import { AttachmentsDialog } from '../attachments/AttachmentsDialog';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { inventarioService } from '../../api/inventarioService';
import { getApiErrorMessage } from '../../api/errors';
import { assetService } from './assetService';
import { EmptyState, KardexTimeline } from '../../components/ui';
import type { Activo } from '../../types/inventory';
import AddBoxIcon from '@mui/icons-material/AddBox';
import { NuevoComponenteForm } from './NuevoComponenteForm';
import type { ComponentesActivo } from '../../types/kardex';
import { useLiveRefresh } from '../../hooks/useLiveRefresh';

interface Props {
  activoId: string;
  canOperate: boolean;
  onChanged?: () => void;
  /** Tipo del equipo y catálogo de tipos: solo se ofrecen los componentes que admite. */
  tipoEquipo?: number;
  tipos?: TipoActivo[];
  /** El equipo (para registrar un componente nuevo ya instalado, heredando sus datos) y si el rol puede dar altas. */
  equipo?: Activo;
  canCreate?: boolean;
}

/**
 * Componentes instalados en el equipo (disco, memoria, monitor, docking…) y su
 * historial. Instalar o retirar deja el evento en el kardex de ambos equipos.
 */
export const ComponentesSection = ({ activoId, canOperate, onChanged, tipoEquipo, tipos = [], equipo, canCreate = false }: Props) => {
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
  // Foto o factura de un componente (la RAM, el disco…), sin salir del equipo.
  const [archivosDe, setArchivosDe] = useState<{ id: string; code: string } | null>(null);
  const [registrando, setRegistrando] = useState(false);

  useEffect(() => {
    if (tipoEquipo == null) { setPermitidos(null); return; }
    catalogService.getComponentesTipo(tipoEquipo).then(setPermitidos).catch(() => setPermitidos(null));
  }, [tipoEquipo]);
  // Sin lista propia del tipo: cualquier tipo de clase «Componente».
  const admite = (a: Activo) => (permitidos && permitidos.length
    ? permitidos.includes(a.TAC_Tipo_Activo)
    : tipos.length === 0 || tipos.find((x) => x.TAC_Tipo_Activo === a.TAC_Tipo_Activo)?.TAC_Clase === 'COMPONENTE');
  // Tipos que se pueden registrar como componente nuevo de este equipo.
  const tiposAdmitidos = tipos.filter((x) => (permitidos && permitidos.length
    ? permitidos.includes(x.TAC_Tipo_Activo) : x.TAC_Clase === 'COMPONENTE'));
  const puedeRegistrar = canOperate && canCreate && !!equipo && tiposAdmitidos.length > 0;

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
  useLiveRefresh(cargar);

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
      {data && data.actuales.length === 0 && !agregando && !registrando && !puedeRegistrar && (
        <EmptyState compact title={t('components.empty')} description={t('components.empty_hint')}
          action={canOperate ? { label: t('components.install'), onClick: () => setAgregando(true) } : undefined} />
      )}
      {data && data.actuales.length > 0 && (
        <List dense>
          {data.actuales.map((c) => (
            <ListItem key={c.ACT_Activo} disableGutters secondaryAction={retirando !== c.ACT_Activo && (
              <Stack direction="row" spacing={0.5}>
                <Tooltip title={t('components.files')}>
                  <IconButton aria-label={t('components.files_of', { code: c.ACT_Codigo_Interno })}
                    onClick={() => setArchivosDe({ id: c.ACT_Activo, code: c.ACT_Codigo_Interno })}><AttachFileIcon /></IconButton>
                </Tooltip>
                {canOperate && (
                  <Tooltip title={t('components.remove')}>
                    <IconButton edge="end" onClick={() => { setRetirando(c.ACT_Activo); setMotivo(''); }}><LinkOffIcon /></IconButton>
                  </Tooltip>)}
              </Stack>)}>
              {/* Qué es, de qué marca y modelo y cuánto tiene: se lee sin abrir el componente. */}
              <ListItemText
                primary={[c.TAC_Nombre, [c.MAR_Nombre, c.MOD_Nombre].filter(Boolean).join(' ')].filter(Boolean).join(' · ') || c.ACT_Codigo_Interno}
                secondary={<>
                  {c.especificaciones && <Typography component="span" variant="body2" color="text.primary" display="block">{c.especificaciones}</Typography>}
                  <Typography component="span" variant="caption" color="text.secondary">
                    {[c.ACT_Codigo_Interno, c.ACT_Serie_Fabricante && t('components.serial_short', { serial: c.ACT_Serie_Fabricante })].filter(Boolean).join(' · ')}
                  </Typography>
                </>} />
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
      {canOperate && data && !agregando && !registrando && (data.actuales.length > 0 || puedeRegistrar) && (
        <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
          {puedeRegistrar && <Button variant="contained" startIcon={<AddBoxIcon />} onClick={() => setRegistrando(true)}>{t('components.new_button')}</Button>}
          <Button startIcon={<AddIcon />} onClick={() => setAgregando(true)}>{t('components.install_existing')}</Button>
        </Stack>
      )}
      {registrando && equipo && (
        <NuevoComponenteForm equipo={equipo} tipos={tiposAdmitidos} onCancel={() => setRegistrando(false)}
          onDone={() => { setRegistrando(false); void cargar(); onChanged?.(); }} />
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
      <AttachmentsDialog asset={archivosDe} onClose={() => setArchivosDe(null)} />
    </Stack>
  );
};
