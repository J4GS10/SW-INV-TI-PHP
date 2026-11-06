import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { EmptyState } from '../../components/ui';
import { useSnackbar } from 'notistack';
import { Box, Button, Chip, CircularProgress, Divider, FormControlLabel, IconButton, Paper, Stack, Switch, TextField, Tooltip, Typography } from '@mui/material';
import CameraswitchIcon from '@mui/icons-material/Cameraswitch';
import AssignmentIndIcon from '@mui/icons-material/AssignmentInd';
import AssignmentReturnIcon from '@mui/icons-material/AssignmentReturn';
import QrCode2Icon from '@mui/icons-material/QrCode2';
import { useWorkspace } from '../../context/workspace-context';
import { guardarArchivo } from '../../api/productividadService';
import { normalizeLanguage } from '../../types/language';
import type { EstadoOperativo } from '../../types/inventory';
import QrCodeScannerIcon from '@mui/icons-material/QrCodeScanner';
import SearchIcon from '@mui/icons-material/Search';
import HistoryIcon from '@mui/icons-material/History';
import SwapHorizIcon from '@mui/icons-material/SwapHoriz';
import BuildIcon from '@mui/icons-material/Build';
import StopCircleIcon from '@mui/icons-material/StopCircle';
import { PageHeader } from '../../components/ui';
import { getApiErrorMessage } from '../../api/errors';
import { Activo } from '../../types/inventory';
import { assetService } from '../assets/assetService';
import { AssetDetailDialog } from '../assets/AssetDetailDialog';
import { usePermissions } from '../../hooks/usePermissions';
import { useQrCamera } from '../../hooks/useQrCamera';
import { BotonFotoQr } from '../../components/BotonFotoQr';
import { codigoDeQr } from '../../utils/lectorQr';
export const ScannerPage = () => {
    const { t, i18n } = useTranslation();
    const { canOperate } = usePermissions();
    const { abrirEntrega, abrirDevolucion, agregarReciente } = useWorkspace();
    // Modo lote: cada lectura se suma a una lista para operar todo junto.
    const [modoLote, setModoLote] = useState(() => {
        try { return window.localStorage.getItem('scanner-lote') === 'true'; } catch { return false; }
    });
    const [lote, setLote] = useState<Activo[]>([]);
    const [estados, setEstados] = useState<EstadoOperativo[]>([]);
    const modoLoteRef = useRef(modoLote);
    useEffect(() => {
        modoLoteRef.current = modoLote;
        try { window.localStorage.setItem('scanner-lote', String(modoLote)); } catch { /* sin storage */ }
    }, [modoLote]);
    useEffect(() => { assetService.getEstados().then(setEstados).catch(() => setEstados([])); }, []);
    const { enqueueSnackbar } = useSnackbar();
    const navigate = useNavigate();
    const lastCodeRef = useRef("");
    const [manualCode, setManualCode] = useState('');
    const [asset, setAsset] = useState<Activo | null>(null);
    const [loading, setLoading] = useState(false);
    const [detailOpen, setDetailOpen] = useState(false);
    const resolveCode = useCallback(async (rawCode: string) => {
        const code = codigoDeQr(rawCode);
        if (!code || code === lastCodeRef.current)
            return;
        lastCodeRef.current = code;
        setLoading(true);
        try {
            const found = await assetService.getActivoByCode(code);
            if (modoLoteRef.current) {
                setLote((prev) => {
                    if (prev.some((a) => a.ACT_Activo === found.ACT_Activo)) {
                        enqueueSnackbar(t('scanner.batch.already', { code: found.ACT_Codigo_Interno }), { variant: 'info' });
                        return prev;
                    }
                    enqueueSnackbar(t('scanner.batch.added', { code: found.ACT_Codigo_Interno }), { variant: 'success', autoHideDuration: 1500 });
                    return [...prev, found];
                });
                setManualCode('');
                return;
            }
            setAsset(found);
            setManualCode(found.ACT_Codigo_Interno);
            setDetailOpen(true);
            agregarReciente({ tipo: 'activo', id: found.ACT_Activo, titulo: found.ACT_Codigo_Interno, subtitulo: found.ACT_Serie_Fabricante ?? undefined, ruta: `/activos/${encodeURIComponent(found.ACT_Codigo_Interno)}` });
            enqueueSnackbar(t('scanner.found'), { variant: 'success' });
        }
        catch (error: unknown) {
            setAsset(null);
            enqueueSnackbar(getApiErrorMessage(error, t('scanner.not_found')), {
                variant: 'error',
            });
        }
        finally {
            setLoading(false);
        }
    }, [enqueueSnackbar, t, agregarReciente]);
    const { videoRef, estado: cameraState, iniciar: startCamera, detener: stopCamera, camaras, cambiarCamara, leerFoto } = useQrCamera(resolveCode);
    // Una foto es una lectura deliberada: se busca aunque repita el último código.
    const leerFotoDeNuevo = useCallback((archivo: Blob) => {
        lastCodeRef.current = '';
        return leerFoto(archivo);
    }, [leerFoto]);
    const handleManualSearch = async () => {
        lastCodeRef.current = "";
        await resolveCode(manualCode);
    };
    const esAsignado = (a: Activo) => estados.find((e) => e.EOP_Estado_Operativo === a.EOP_Estado_Operativo)?.EOP_Nombre === 'Asignado';
    const limpiarLote = () => { setLote([]); lastCodeRef.current = ''; };
    const etiquetasLote = async () => {
        try {
            const blob = await assetService.downloadLabels({ activos_ids: lote.map((a) => a.ACT_Activo) }, normalizeLanguage(i18n.resolvedLanguage));
            guardarArchivo(blob, `etiquetas-${lote.length}.pdf`);
        } catch (err) {
            enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
        }
    };
    const devolverLote = () => {
        const asignados = lote.filter(esAsignado);
        if (!asignados.length) { enqueueSnackbar(t('bulk.none_assigned'), { variant: 'info' }); return; }
        abrirDevolucion({ activos: asignados, onDone: limpiarLote });
    };
    return (<Box>
      <PageHeader
        title={t('scanner.title')}
        subtitle={t('scanner.subtitle')}
        actions={
          <Stack direction="row" spacing={1} useFlexGap flexWrap="wrap">
            {cameraState === 'active' ? (<Button startIcon={<StopCircleIcon />} variant="outlined" color="inherit" onClick={stopCamera}>
                {t('scanner.stop')}
              </Button>) : (<Button startIcon={cameraState === 'starting' ? <CircularProgress size={16} color="inherit"/> : <QrCodeScannerIcon />} variant="contained"
                disabled={cameraState === 'starting'} onClick={() => void startCamera()}>
                {t('scanner.start')}
              </Button>)}
            {cameraState === 'active' && camaras.length > 1 && (<Tooltip title={t('scanner.switch_camera')}>
                <IconButton aria-label={t('scanner.switch_camera')} onClick={() => void cambiarCamara()}><CameraswitchIcon /></IconButton>
              </Tooltip>)}
            <BotonFotoQr leerFoto={leerFotoDeNuevo} disabled={loading}/>
          </Stack>
        }
      />

      <Stack direction={{ xs: 'column', lg: 'row' }} spacing={2} alignItems="stretch">
        <Paper variant="outlined" sx={{ p: 2, flex: 1, borderRadius: 2 }}>
          <Box sx={{
            position: 'relative',
            bgcolor: 'grey.900',
            borderRadius: 1,
            overflow: 'hidden',
            aspectRatio: { xs: '4 / 3', md: '16 / 9' },
            minHeight: 260,
            display: 'grid',
            placeItems: 'center',
        }}>
            <video ref={videoRef} muted playsInline style={{ width: '100%', height: '100%', objectFit: 'cover' }}/>
            {cameraState !== 'active' && (<Stack spacing={1} alignItems="center" sx={{ position: 'absolute', color: 'common.white' }}>
                <QrCodeScannerIcon fontSize="large"/>
                <Typography variant="body2">
                  {cameraState === 'unsupported'
                ? t('scanner.unsupported')
                : cameraState === 'denied'
                    ? t('scanner.denied')
                    : cameraState === 'starting'
                        ? t('scanner.starting')
                        : t('scanner.idle')}
                </Typography>
              </Stack>)}
          </Box>
        </Paper>

        <Paper variant="outlined" sx={{ p: 2, width: { xs: '100%', lg: 380 }, borderRadius: 2 }}>
          <Stack spacing={2}>
            <FormControlLabel control={<Switch checked={modoLote} onChange={(e) => { setModoLote(e.target.checked); setAsset(null); }} />}
              label={t('scanner.batch.toggle')} />
            <Typography variant="caption" color="text.secondary" sx={{ mt: '-8px !important' }}>
              {modoLote ? t('scanner.batch.help') : t('scanner.usb_help')}
            </Typography>
            <TextField label={t('scanner.manual_code')} value={manualCode} onChange={(event) => setManualCode(event.target.value)} size="small" fullWidth autoFocus
              onKeyDown={(e) => { if (e.key === 'Enter' && manualCode.trim()) { e.preventDefault(); void handleManualSearch(); } }}/>
            <Button startIcon={loading ? <CircularProgress size={16}/> : <SearchIcon />} variant="contained" disabled={loading || !manualCode.trim()} onClick={handleManualSearch}>
              {t('common.search')}
            </Button>

            <Divider />

            {modoLote ? (<Stack spacing={1.5}>
                <Stack direction="row" alignItems="center">
                  <Typography variant="subtitle2" sx={{ flex: 1 }}>{t('scanner.batch.count', { count: lote.length })}</Typography>
                  {lote.length > 0 && <Button size="small" onClick={limpiarLote}>{t('scanner.batch.clear')}</Button>}
                </Stack>
                {lote.length === 0 ? (<EmptyState compact title={t('scanner.batch.empty')}/>) : (
                  <Stack direction="row" useFlexGap flexWrap="wrap" gap={0.5}>
                    {lote.map((a) => <Chip key={a.ACT_Activo} size="small" label={a.ACT_Codigo_Interno} color={esAsignado(a) ? 'warning' : 'default'}
                      onDelete={() => setLote((l) => l.filter((x) => x.ACT_Activo !== a.ACT_Activo))}/>)}
                  </Stack>
                )}
                {canOperate && lote.length > 0 && (<Stack spacing={1}>
                    <Button variant="contained" startIcon={<AssignmentIndIcon />} onClick={() => abrirEntrega({ activos: lote, onDone: limpiarLote })}>
                      {t('bulk.handover', { count: lote.length })}
                    </Button>
                    <Button variant="outlined" startIcon={<AssignmentReturnIcon />} onClick={devolverLote}>{t('bulk.return')}</Button>
                    <Button variant="outlined" startIcon={<QrCode2Icon />} onClick={etiquetasLote}>{t('bulk.labels')}</Button>
                  </Stack>)}
              </Stack>) : asset ? (<Stack spacing={1.5}>
                <Stack direction="row" spacing={1} alignItems="center">
                  <Typography variant="h6" sx={{ fontWeight: 700, flex: 1 }}>
                    {asset.ACT_Codigo_Interno}
                  </Typography>
                  <Chip size="small" label={asset.ACT_Hostname || t('assets.table.no_host')}/>
                </Stack>
                <Typography variant="body2" color="text.secondary">
                  {asset.ACT_Serie_Fabricante ?? t('assets.table.no_serial')}
                </Typography>
                <Stack direction={{ xs: 'column', sm: 'row', lg: 'column' }} spacing={1}>
                  <Button startIcon={<HistoryIcon />} variant="outlined" onClick={() => setDetailOpen(true)}>
                    {t('common.view')}
                  </Button>
                  {canOperate && asset.pending_completion && (<Button variant="contained" onClick={() => navigate('/ingresos/por-completar')}>
                    {t('assets.actions.complete')}
                  </Button>)}
                  {canOperate && !asset.pending_completion && (<Button startIcon={<SwapHorizIcon />} variant="outlined" onClick={() => abrirEntrega({ activos: [asset] })}>
                    {t('scanner.assign')}
                  </Button>)}
                  {canOperate && !asset.pending_completion && (<Button startIcon={<BuildIcon />} variant="outlined" onClick={() => navigate(`/mantenimiento?nuevo=${asset.ACT_Activo}`)}>
                    {t('scanner.repair')}
                  </Button>)}
                </Stack>
              </Stack>) : (<EmptyState compact title={t('scanner.empty')}/>)}
          </Stack>
        </Paper>
      </Stack>

      <AssetDetailDialog open={detailOpen} asset={asset} onClose={() => setDetailOpen(false)}/>
    </Box>);
};
