import { useCallback, useEffect, useMemo, useState } from 'react';
import { catalogService } from '../catalogs/catalogService';
import type { TipoActivo } from '../../types/inventory';
import {
  Alert, AlertTitle, Autocomplete, Box, Button, Chip, CircularProgress, Divider, IconButton, List, ListItem, ListItemText, Stack,
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
  // Al registrar un componente obligatorio que falta, el formulario abre con su tipo ya elegido.
  const [tipoRegistro, setTipoRegistro] = useState<number | undefined>(undefined);

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
  // Instalados agrupados por tipo (Batería, Memoria RAM…) y ordenados: se lee de un vistazo qué tiene el equipo.
  const grupos = useMemo(() => {
    const porTipo = new Map<string, NonNullable<typeof data>['actuales']>();
    for (const c of [...(data?.actuales ?? [])].sort((a, b) => (a.TAC_Nombre ?? '').localeCompare(b.TAC_Nombre ?? '') || a.ACT_Codigo_Interno.localeCompare(b.ACT_Codigo_Interno))) {
      const clave = c.TAC_Nombre ?? t('components.title');
      porTipo.set(clave, [...(porTipo.get(clave) ?? []), c]);
    }
    return [...porTipo.entries()];
  }, [data, t]);
  // El historial muestra lo último; el resto, a pedido.
  const [verTodo, setVerTodo] = useState(false);
  const RECIENTES = 3;

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
      {/* Resumen y acciones arriba: lo que se hace casi siempre está a la vista, sin desplazarse. */}
      {data && (data.actuales.length > 0 || (canOperate && puedeRegistrar)) && !agregando && !registrando && (
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} alignItems={{ sm: 'center' }} justifyContent="space-between">
          <Typography variant="subtitle2" color="text.secondary">
            {data.actuales.length > 0 ? t('components.count', { count: data.actuales.length }) : t('components.empty')}
          </Typography>
          {canOperate && (
            <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
              {puedeRegistrar && <Button variant="contained" startIcon={<AddBoxIcon />} onClick={() => { setTipoRegistro(undefined); setRegistrando(true); }}>{t('components.new_button')}</Button>}
              <Button startIcon={<AddIcon />} onClick={() => setAgregando(true)}>{t('components.install_existing')}</Button>
            </Stack>
          )}
        </Stack>
      )}
      {/* Lo que el tipo del equipo pide y aún no tiene: se avisa aquí y en la bandeja de pendientes hasta registrarlo. */}
      {data?.pendientes && data.pendientes.length > 0 && !registrando && (
        <Alert severity="warning">
          <AlertTitle>{t('components.pending_title')}</AlertTitle>
          {t('components.pending_help')}
          <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap sx={{ mt: 1 }}>
            {data.pendientes.map((p) => (
              <Button key={p.TAC_Tipo_Activo} size="small" variant="outlined" color="warning" startIcon={<AddBoxIcon />}
                disabled={!canOperate || !puedeRegistrar}
                onClick={() => { setTipoRegistro(p.TAC_Tipo_Activo); setRegistrando(true); }}>
                {t('components.pending_item', { name: p.TAC_Nombre, count: p.requeridos - p.instalados })}
              </Button>
            ))}
          </Stack>
        </Alert>
      )}
      {registrando && equipo && (
        <NuevoComponenteForm key={tipoRegistro ?? 'libre'} tipoInicial={tipoRegistro} equipo={equipo} tipos={tiposAdmitidos} onCancel={() => setRegistrando(false)}
          onSaved={() => { void cargar(); onChanged?.(); }}
          onDone={() => { setRegistrando(false); void cargar(); onChanged?.(); }} />
      )}
      {grupos.map(([tipo, lista]) => (
        <Box key={tipo} component="section" aria-label={tipo}>
          <Typography variant="overline" color="text.secondary" sx={{ lineHeight: 1.6 }}>{tipo} ({lista.length})</Typography>
        <List dense disablePadding>
          {lista.map((c) => (
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
              <ListItemText sx={{ pr: 10 }}
                primary={[c.MAR_Nombre, c.MOD_Nombre].filter(Boolean).join(' ') || c.ACT_Codigo_Interno}
                secondary={<>
                  {/* Cada característica como etiqueta («Capacidad: 16 GB»): se compara sin leer una línea larga. */}
                  {c.especificaciones && (
                    <Box component="span" sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.5, my: 0.5 }}>
                      {c.especificaciones.split(' · ').map((s) => <Chip key={s} component="span" size="small" variant="outlined" label={s} />)}
                    </Box>
                  )}
                  <Typography component="span" variant="caption" color="text.secondary">
                    {[c.ACT_Codigo_Interno, c.ACT_Serie_Fabricante && t('components.serial_short', { serial: c.ACT_Serie_Fabricante })].filter(Boolean).join(' · ')}
                  </Typography>
                </>} />
              {retirando === c.ACT_Activo && (
                <Stack spacing={1} sx={{ ml: 2, flex: 1 }}>
                  {/* Motivos frecuentes con un clic; se puede escribir otro. */}
                  <Stack direction="row" spacing={0.5} flexWrap="wrap" useFlexGap>
                    {(['failure', 'upgrade', 'replacement', 'transfer'] as const).map((k) => {
                      const texto = t(`components.quick_reasons.${k}`);
                      return <Chip key={k} size="small" label={texto} color={motivo === texto ? 'primary' : 'default'} onClick={() => setMotivo(texto)} />;
                    })}
                  </Stack>
                  <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1}>
                    <TextField size="small" autoFocus label={t('components.reason')} value={motivo} onChange={(e) => setMotivo(e.target.value)}
                      onKeyDown={(e) => { if (e.key === 'Enter' && !guardando) { e.preventDefault(); void retirar(c.ACT_Activo, c.ACT_Codigo_Interno); } }} />
                    <Button size="small" onClick={() => setRetirando(null)}>{t('common.cancel')}</Button>
                    <Button size="small" variant="contained" disabled={guardando}
                      onClick={() => retirar(c.ACT_Activo, c.ACT_Codigo_Interno)}>{t('components.remove')}</Button>
                  </Stack>
                </Stack>
              )}
            </ListItem>
          ))}
        </List>
        </Box>
      ))}
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
          <Divider sx={{ mb: 2 }} />
          {/* Trazabilidad: quién instaló o retiró qué, cuándo y por qué. Lo último primero. */}
          <Typography variant="subtitle2" gutterBottom>{t('components.history')} ({data.historial.length})</Typography>
          <KardexTimeline eventos={verTodo ? data.historial : data.historial.slice(0, RECIENTES)} mostrarReferencia />
          {data.historial.length > RECIENTES && (
            <Button size="small" onClick={() => setVerTodo((v) => !v)}>
              {verTodo ? t('components.history_less') : t('components.history_all', { count: data.historial.length })}
            </Button>
          )}
        </Box>
      )}
      <AttachmentsDialog asset={archivosDe} onClose={() => setArchivosDe(null)} />
    </Stack>
  );
};
