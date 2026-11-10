import { useCallback, useEffect, useRef, useState } from 'react';
import { Alert, Box, Button, Checkbox, Chip, CircularProgress, DialogActions, DialogContent, DialogTitle, FormControlLabel, IconButton, LinearProgress, List, ListItem, ListItemText, MenuItem, Stack, TextField, Tooltip, Typography, useMediaQuery, useTheme } from '@mui/material';
import CameraswitchIcon from '@mui/icons-material/Cameraswitch';
import CloseIcon from '@mui/icons-material/Close';
import QrCodeScannerIcon from '@mui/icons-material/QrCodeScanner';
import StopCircleIcon from '@mui/icons-material/StopCircle';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { inventarioService } from '../../api/inventarioService';
import { getApiErrorMessage } from '../../api/errors';
import { useConfirm } from '../../components/confirm-context';
import { usePermissions } from '../../hooks/usePermissions';
import { useQrCamera } from '../../hooks/useQrCamera';
import { BotonFotoQr } from '../../components/BotonFotoQr';
import type { LecturaResultado, ResultadoLectura, TomaDetalle } from '../../types/kardex';
import { formatDateTimeValue } from '../../utils/dates';
import { normalizeLanguage } from '../../types/language';
import { Dialog } from '../../components/ui/Dialog';
import { useLiveRefresh } from '../../hooks/useLiveRefresh';

const COLOR: Record<ResultadoLectura, 'success' | 'warning' | 'error' | 'info' | 'default'> = {
  ENCONTRADO: 'success',
  OTRA_UBICACION: 'warning',
  OTRA_SEDE: 'warning',
  DE_BAJA: 'error',
  NO_REGISTRADO: 'error',
  REPETIDO: 'default',
};

interface Props {
  tomaId: number | null;
  areas: Array<{ ARE_Area: number; ruta: string; SED_Sede?: number | null }>;
  onClose: (changed: boolean) => void;
}

/**
 * Pantalla de lectura de una toma: se escanea (cámara o lector USB/teclado)
 * y el sistema responde al instante qué es el equipo y si está donde debería.
 */
export const TomaDialog = ({ tomaId, areas, onClose }: Props) => {
  const { t, i18n } = useTranslation();
  const theme = useTheme();
  const fullScreen = useMediaQuery(theme.breakpoints.down('sm'));
  const { enqueueSnackbar } = useSnackbar();
  const confirm = useConfirm();
  const { canOperate, canExport } = usePermissions();
  const [toma, setToma] = useState<TomaDetalle | null>(null);
  const [codigo, setCodigo] = useState('');
  const [areaActual, setAreaActual] = useState<number | ''>('');
  const [ultima, setUltima] = useState<LecturaResultado | null>(null);
  const [leyendo, setLeyendo] = useState(false);
  const [cerrando, setCerrando] = useState(false);
  const [actualizarUbicacion, setActualizarUbicacion] = useState(true);
  const [changed, setChanged] = useState(false);
  const inputRef = useRef<HTMLInputElement | null>(null);

  const cargar = useCallback(async () => {
    if (tomaId == null) return;
    try {
      setToma(await inventarioService.toma(tomaId));
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_loading')), { variant: 'error' });
    }
  }, [enqueueSnackbar, t, tomaId]);
  useLiveRefresh(cargar);

  useEffect(() => {
    setToma(null);
    setUltima(null);
    setCodigo('');
    setChanged(false);
    cargar();
  }, [cargar]);

  const abierta = toma?.TOM_Estado === 'ABIERTA';

  const leer = useCallback(async (valor: string) => {
    const limpio = valor.trim();
    if (!limpio || tomaId == null) return;
    setLeyendo(true);
    try {
      const r = await inventarioService.leer(tomaId, limpio, areaActual === '' ? null : areaActual);
      setUltima(r);
      setChanged(true);
      setCodigo('');
      await cargar();
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
    } finally {
      setLeyendo(false);
      inputRef.current?.focus();
    }
  }, [areaActual, cargar, enqueueSnackbar, t, tomaId]);

  const { videoRef, estado: camara, iniciar, detener, camaras, cambiarCamara, leerFoto } = useQrCamera(leer);

  const cerrarToma = async () => {
    if (tomaId == null || !toma) return;
    const ok = await confirm({
      title: t('inventory_count.close_title'),
      message: t('inventory_count.close_message', { count: toma.resumen.faltantes.length }),
      confirmText: t('inventory_count.close_confirm'),
    });
    if (!ok) return;
    setCerrando(true);
    try {
      detener();
      setToma(await inventarioService.cerrarToma(tomaId, actualizarUbicacion));
      setChanged(true);
      enqueueSnackbar(t('inventory_count.closed_ok'), { variant: 'success' });
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
    } finally {
      setCerrando(false);
    }
  };

  const r = toma?.resumen;
  const areasDeSede = areas.filter((a) => !toma || a.SED_Sede === toma.SED_Sede);

  return (
    <Dialog open={tomaId != null} onClose={() => { detener(); onClose(changed); }} fullScreen={fullScreen} maxWidth="md" fullWidth>
      <DialogTitle sx={{ pr: 6 }}>
        {t('inventory_count.dialog_title', { id: tomaId ?? '' })}
        {toma && <Chip size="small" sx={{ ml: 1 }} label={t(`inventory_count.states.${toma.TOM_Estado}`)}
          color={abierta ? 'primary' : toma.TOM_Estado === 'CERRADA' ? 'success' : 'default'} />}
        <IconButton aria-label={t('common.close')} onClick={() => { detener(); onClose(changed); }}
          sx={{ position: 'absolute', right: 8, top: 8 }}><CloseIcon /></IconButton>
      </DialogTitle>
      <DialogContent dividers>
        {!toma ? <LinearProgress /> : (
          <Stack spacing={2}>
            <Box>
              <Stack direction="row" justifyContent="space-between">
                <Typography variant="body2">{t('inventory_count.progress', { found: r!.esperados - r!.faltantes.length, expected: r!.esperados })}</Typography>
                <Typography variant="body2" fontWeight={600}>{r!.avance_pct}%</Typography>
              </Stack>
              <LinearProgress variant="determinate" value={Math.min(100, r!.avance_pct)} sx={{ height: 8, borderRadius: 4, mt: 0.5 }} />
            </Box>

            {abierta && canOperate && (
              <>
                <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5}>
                  <TextField
                    inputRef={inputRef}
                    autoFocus
                    fullWidth
                    label={t('inventory_count.scan_label')}
                    helperText={t('inventory_count.scan_help')}
                    value={codigo}
                    disabled={leyendo}
                    onChange={(e) => setCodigo(e.target.value)}
                    onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); leer(codigo); } }}
                  />
                  {areasDeSede.length > 0 && (
                    <TextField select label={t('inventory_count.current_area')} value={areaActual} sx={{ minWidth: 220 }}
                      onChange={(e) => setAreaActual(e.target.value === '' ? '' : Number(e.target.value))}
                      helperText={t('inventory_count.current_area_help')}>
                      <MenuItem value="">{t('inventory_count.any_area')}</MenuItem>
                      {areasDeSede.map((a) => <MenuItem key={a.ARE_Area} value={a.ARE_Area}>{a.ruta}</MenuItem>)}
                    </TextField>
                  )}
                </Stack>
                <Stack direction="row" spacing={1} useFlexGap flexWrap="wrap" alignItems="center">
                  {camara === 'active'
                    ? <Button startIcon={<StopCircleIcon />} onClick={detener}>{t('inventory_count.stop_camera')}</Button>
                    : <Button startIcon={camara === 'starting' ? <CircularProgress size={16} /> : <QrCodeScannerIcon />}
                        disabled={camara === 'starting'} onClick={() => void iniciar()}>{t('inventory_count.use_camera')}</Button>}
                  {camara === 'active' && camaras.length > 1 && (
                    <Tooltip title={t('scanner.switch_camera')}>
                      <IconButton aria-label={t('scanner.switch_camera')} onClick={() => void cambiarCamara()}><CameraswitchIcon /></IconButton>
                    </Tooltip>
                  )}
                  <BotonFotoQr leerFoto={leerFoto} disabled={leyendo} />
                </Stack>
                {camara === 'unsupported' && <Alert severity="info">{t('inventory_count.camera_unsupported')}</Alert>}
                {camara === 'denied' && <Alert severity="warning">{t('inventory_count.camera_denied')}</Alert>}
                <Box sx={{ display: camara === 'active' ? 'block' : 'none', borderRadius: 2, overflow: 'hidden', bgcolor: 'black' }}>
                  <video ref={videoRef} muted playsInline style={{ width: '100%', maxHeight: 280, objectFit: 'cover' }} />
                </Box>
              </>
            )}

            {ultima && (
              <Alert severity={COLOR[ultima.TLE_Resultado] === 'success' ? 'success'
                : COLOR[ultima.TLE_Resultado] === 'error' ? 'error' : COLOR[ultima.TLE_Resultado] === 'warning' ? 'warning' : 'info'}>
                <Typography fontWeight={600}>{ultima.activo_codigo ?? ultima.TLE_Codigo_Leido}</Typography>
                {t(`inventory_count.results_msg.${ultima.TLE_Resultado}`)}
                {' '}{t('inventory_count.remaining', { count: Math.max(ultima.esperados - ultima.leidos, 0) })}
              </Alert>
            )}

            {!abierta && r && (
              <Alert severity={r.faltantes.length ? 'warning' : 'success'}>
                {t('inventory_count.final_summary', {
                  expected: r.esperados, found: r.encontrados, missing: r.faltantes.length,
                  other: r.otra_ubicacion.length, unknown: r.no_registrados.length,
                })}
              </Alert>
            )}

            <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' } }}>
              {([
                ['faltantes', r?.faltantes ?? []], ['otra_ubicacion', r?.otra_ubicacion ?? []],
                ['otra_sede', r?.otra_sede ?? []], ['de_baja', r?.de_baja ?? []], ['no_registrados', r?.no_registrados ?? []],
              ] as const).filter(([, lista]) => lista.length > 0).map(([clave, lista]) => (
                <Box key={clave}>
                  <Typography variant="subtitle2">{t(`inventory_count.groups.${clave}`)} ({lista.length})</Typography>
                  <List dense>
                    {lista.slice(0, 50).map((i) => (
                      <ListItem key={`${clave}-${i.codigo}`} disableGutters>
                        <ListItemText primary={i.codigo}
                          secondary={i.area ? areas.find((a) => a.ARE_Area === i.area)?.ruta : undefined} />
                      </ListItem>
                    ))}
                  </List>
                </Box>
              ))}
            </Box>

            <Box>
              <Typography variant="subtitle2" gutterBottom>{t('inventory_count.readings', { count: toma.lecturas.length })}</Typography>
              <List dense sx={{ maxHeight: 220, overflow: 'auto' }}>
                {[...toma.lecturas].reverse().slice(0, 100).map((l) => (
                  <ListItem key={l.TLE_Lectura} disableGutters
                    secondaryAction={<Chip size="small" color={COLOR[l.TLE_Resultado]} label={t(`inventory_count.results.${l.TLE_Resultado}`)} />}>
                    <ListItemText primary={l.TLE_Codigo_Leido} secondary={formatDateTimeValue(l.TLE_Fecha, i18n.language)} />
                  </ListItem>
                ))}
              </List>
            </Box>
          </Stack>
        )}
      </DialogContent>
      <DialogActions sx={{ flexWrap: 'wrap', gap: 1 }}>
        {toma && canExport && (
          <Button onClick={() => inventarioService.reporteToma(toma.TOM_Toma, normalizeLanguage(i18n.language))}>
            {t('inventory_count.download_report')}
          </Button>
        )}
        {abierta && canOperate && (
          <>
            <FormControlLabel control={<Checkbox checked={actualizarUbicacion} onChange={(e) => setActualizarUbicacion(e.target.checked)} />}
              label={t('inventory_count.update_locations')} />
            <Button variant="contained" onClick={cerrarToma} disabled={cerrando}>{t('inventory_count.close_count')}</Button>
          </>
        )}
      </DialogActions>
    </Dialog>
  );
};
