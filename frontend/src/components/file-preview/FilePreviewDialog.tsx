import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import {
  Alert, Box, Button, CircularProgress, Dialog, IconButton, Paper, Stack, Table, TableBody, TableCell, TableContainer,
  TableHead, TableRow, Tooltip, Typography, useMediaQuery, useTheme,
} from '@mui/material';
import CloseIcon from '@mui/icons-material/Close';
import DownloadIcon from '@mui/icons-material/Download';
import OpenInNewIcon from '@mui/icons-material/OpenInNew';
import ZoomInIcon from '@mui/icons-material/ZoomIn';
import ZoomOutIcon from '@mui/icons-material/ZoomOut';
import FitScreenIcon from '@mui/icons-material/FitScreen';
import RotateRightIcon from '@mui/icons-material/RotateRight';
import ChevronLeftIcon from '@mui/icons-material/ChevronLeft';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import PictureAsPdfIcon from '@mui/icons-material/PictureAsPdfOutlined';
import ImageIcon from '@mui/icons-material/ImageOutlined';
import ArticleIcon from '@mui/icons-material/ArticleOutlined';
import TableChartIcon from '@mui/icons-material/TableChartOutlined';
import FileIcon from '@mui/icons-material/InsertDriveFileOutlined';
import { useTranslation } from 'react-i18next';
import { saveBlob } from '../../api/downloadCsv';
import { FilePreviewContext, type FilePreviewContextValue } from './file-preview-context';
import { extensionOf, parseCsv, previewKind, type PreviewFile, type PreviewKind } from './filePreview';

const ICONO: Record<PreviewKind, typeof FileIcon> = {
  pdf: PictureAsPdfIcon, image: ImageIcon, text: ArticleIcon, csv: TableChartIcon, none: FileIcon,
};
const ZOOM_MIN = 0.25;
const ZOOM_MAX = 4;
const MAX_FILAS = 200;

interface Cargado { blob: Blob; url: string; kind: PreviewKind; text?: string }

interface Props {
  files: PreviewFile[];
  index: number;
  onIndexChange: (index: number) => void;
  onClose: () => void;
}

/**
 * Visor de archivos a pantalla casi completa: PDF con el visor del propio navegador, imágenes con zoom y giro,
 * texto y CSV como tabla. Lo demás (Word, Excel) se explica y se ofrece descargar. Flechas ← → pasan de archivo.
 */
export const FilePreviewDialog = ({ files, index, onIndexChange, onClose }: Props) => {
  const { t } = useTranslation();
  const theme = useTheme();
  const telefono = useMediaQuery(theme.breakpoints.down('sm'));
  const archivo = files[index];
  // Lo cargado y el fallo quedan atados al archivo y al intento: al pasar a otro, lo anterior deja de valer solo.
  const [carga, setCarga] = useState<(Cargado & { file: PreviewFile; intento: number }) | null>(null);
  const [fallo, setFallo] = useState<{ file: PreviewFile; intento: number } | null>(null);
  const [intento, setIntento] = useState(0);
  const cargado = carga && carga.file === archivo && carga.intento === intento ? carga : null;
  const error = !!fallo && fallo.file === archivo && fallo.intento === intento;
  const [zoom, setZoom] = useState<number | null>(null); // null = ajustado a la ventana
  const [giro, setGiro] = useState(0);
  // Chrome en Android y algunos navegadores no muestran PDF dentro de la página.
  const pdfEnLinea = typeof navigator === 'undefined' || (navigator as Navigator & { pdfViewerEnabled?: boolean }).pdfViewerEnabled !== false;

  useEffect(() => {
    if (!archivo) return;
    let vivo = true;
    let url: string | null = null;
    archivo.load().then(async (blob) => {
      const kind = previewKind(archivo.name, blob.type || archivo.mime);
      const text = kind === 'text' || kind === 'csv' ? await blob.text() : undefined;
      if (!vivo) return;
      url = URL.createObjectURL(blob);
      setCarga({ blob, url, kind, text, file: archivo, intento });
    }).catch(() => { if (vivo) setFallo({ file: archivo, intento }); });
    return () => { vivo = false; if (url) URL.revokeObjectURL(url); };
  }, [archivo, intento]);

  const total = files.length;
  const ir = useCallback((paso: number) => {
    if (total < 2) return;
    setZoom(null); setGiro(0);
    onIndexChange((index + paso + total) % total);
  }, [index, total, onIndexChange]);
  const acercar = useCallback((f: number) => setZoom((z) => Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, (z ?? 1) * f))), []);

  useEffect(() => {
    const tecla = (e: KeyboardEvent) => {
      if (e.key === 'ArrowLeft') ir(-1);
      else if (e.key === 'ArrowRight') ir(1);
      else if (cargado?.kind === 'image' && (e.key === '+' || e.key === '=')) acercar(1.25);
      else if (cargado?.kind === 'image' && e.key === '-') acercar(0.8);
    };
    window.addEventListener('keydown', tecla);
    return () => window.removeEventListener('keydown', tecla);
  }, [ir, acercar, cargado?.kind]);

  const csv = useMemo(() => (cargado?.kind === 'csv' && cargado.text !== undefined ? parseCsv(cargado.text, MAX_FILAS) : null), [cargado]);
  if (!archivo) return null;

  const kind = cargado?.kind ?? previewKind(archivo.name, archivo.mime);
  const Icono = ICONO[kind];
  const descargar = () => { if (cargado) saveBlob(cargado.blob, archivo.name); else void archivo.load().then((b) => saveBlob(b, archivo.name)); };
  const abrir = () => { if (cargado) window.open(cargado.url, '_blank', 'noopener'); };
  const ext = extensionOf(archivo.name).toUpperCase();

  const boton = (titulo: string, icono: ReactNode, accion: () => void, deshabilitado = false) => (
    <Tooltip title={titulo}>
      <span><IconButton color="inherit" onClick={accion} disabled={deshabilitado} aria-label={titulo}>{icono}</IconButton></span>
    </Tooltip>
  );

  const aviso = (titulo: string, texto: string, abrirTambien = false) => (
    <Paper sx={{ p: 4, maxWidth: 440, textAlign: 'center', borderRadius: 3 }}>
      <Icono sx={{ fontSize: 56, color: 'text.secondary', mb: 1 }} />
      <Typography variant="h6" gutterBottom>{titulo}</Typography>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 3 }}>{texto}</Typography>
      <Stack direction="row" spacing={1} justifyContent="center">
        {abrirTambien && <Button variant="outlined" startIcon={<OpenInNewIcon />} onClick={abrir}>{t('file_preview.open_new_tab')}</Button>}
        <Button variant="contained" startIcon={<DownloadIcon />} onClick={descargar}>{t('file_preview.download')}</Button>
      </Stack>
    </Paper>
  );

  const contenido = () => {
    if (error) {
      return (
        <Alert severity="error" action={<Button color="inherit" size="small" onClick={() => setIntento((n) => n + 1)}>{t('file_preview.retry')}</Button>}>
          {t('file_preview.error')}
        </Alert>
      );
    }
    if (!cargado) {
      return <Stack alignItems="center" spacing={2} sx={{ color: 'grey.300' }}><CircularProgress color="inherit" /><Typography variant="body2">{t('file_preview.loading')}</Typography></Stack>;
    }
    switch (cargado.kind) {
      case 'pdf':
        return pdfEnLinea
          ? <Box component="iframe" title={archivo.name} src={`${cargado.url}#view=FitH`} sx={{ border: 0, width: '100%', height: '100%', bgcolor: 'grey.100' }} />
          : aviso(t('file_preview.pdf_unavailable_title'), t('file_preview.pdf_unavailable_body'), true);
      case 'image': {
        const ajustada = zoom === null;
        return (
          <Box sx={{ width: '100%', height: '100%', overflow: 'auto', display: 'flex', p: 2 }} onDoubleClick={() => setZoom((z) => (z === null ? 2 : null))}>
            <Box component="img" src={cargado.url} alt={archivo.name} draggable={false} sx={{
              m: 'auto', display: 'block', borderRadius: 1, boxShadow: 6, bgcolor: '#fff',
              transform: `rotate(${giro}deg) scale(${ajustada ? 1 : zoom})`, transformOrigin: 'center', transition: 'transform .15s ease',
              ...(ajustada ? { maxWidth: '100%', maxHeight: '100%', objectFit: 'contain' } : {}),
              cursor: ajustada ? 'zoom-in' : 'zoom-out',
            }} />
          </Box>
        );
      }
      case 'text':
        return (
          <Paper sx={{ width: '100%', maxWidth: 960, height: '100%', overflow: 'auto', p: 3, borderRadius: 2 }}>
            <Box component="pre" sx={{ m: 0, fontFamily: 'ui-monospace, SFMono-Regular, Consolas, monospace', fontSize: 13, whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>
              {cargado.text}
            </Box>
          </Paper>
        );
      case 'csv':
        return (
          <Paper sx={{ width: '100%', height: '100%', display: 'flex', flexDirection: 'column', borderRadius: 2, overflow: 'hidden' }}>
            <TableContainer sx={{ flex: 1 }}>
              <Table size="small" stickyHeader>
                <TableHead>
                  <TableRow>{(csv?.rows[0] ?? []).map((c, i) => <TableCell key={i} sx={{ fontWeight: 600, whiteSpace: 'nowrap' }}>{c}</TableCell>)}</TableRow>
                </TableHead>
                <TableBody>
                  {(csv?.rows.slice(1) ?? []).map((f, i) => (
                    <TableRow key={i} hover>{f.map((c, j) => <TableCell key={j} sx={{ whiteSpace: 'nowrap' }}>{c}</TableCell>)}</TableRow>
                  ))}
                </TableBody>
              </Table>
            </TableContainer>
            {csv?.truncated && <Typography variant="caption" color="text.secondary" sx={{ px: 2, py: 1, borderTop: 1, borderColor: 'divider' }}>{t('file_preview.csv_truncated', { count: MAX_FILAS })}</Typography>}
          </Paper>
        );
      default:
        return aviso(t('file_preview.unavailable_title'), t('file_preview.unavailable_body', { ext: ext || t('file_preview.this_type') }));
    }
  };

  return (
    <Dialog open onClose={onClose} fullScreen={telefono} maxWidth={false}
      slotProps={{ paper: { sx: { bgcolor: 'grey.900', color: 'common.white', overflow: 'hidden',
        ...(telefono ? {} : { width: 'min(1200px, 94vw)', height: '92vh', borderRadius: 3 }) } } }}
      aria-labelledby="file-preview-title">
      <Stack direction="row" alignItems="center" spacing={1} sx={{ px: 1.5, py: 1, borderBottom: 1, borderColor: 'rgba(255,255,255,.12)', bgcolor: 'rgba(0,0,0,.25)' }}>
        <Icono sx={{ color: 'grey.400', ml: 0.5 }} />
        <Box sx={{ minWidth: 0, flex: 1 }}>
          <Typography id="file-preview-title" variant="subtitle1" fontWeight={600} noWrap title={archivo.name}>{archivo.name}</Typography>
          <Typography variant="caption" sx={{ color: 'grey.400' }} noWrap component="p">
            {[ext, archivo.subtitle, total > 1 ? t('file_preview.counter', { current: index + 1, total }) : null].filter(Boolean).join(' · ')}
          </Typography>
        </Box>
        {kind === 'image' && cargado && !telefono && (
          <>
            {boton(t('file_preview.zoom_out'), <ZoomOutIcon />, () => acercar(0.8))}
            <Typography variant="body2" sx={{ minWidth: 48, textAlign: 'center', color: 'grey.300' }}>{zoom === null ? t('file_preview.fit') : `${Math.round(zoom * 100)} %`}</Typography>
            {boton(t('file_preview.zoom_in'), <ZoomInIcon />, () => acercar(1.25))}
            {boton(t('file_preview.zoom_fit'), <FitScreenIcon />, () => setZoom(null), zoom === null)}
            {boton(t('file_preview.rotate'), <RotateRightIcon />, () => setGiro((g) => (g + 90) % 360))}
          </>
        )}
        {kind !== 'none' && boton(t('file_preview.open_new_tab'), <OpenInNewIcon />, abrir, !cargado)}
        {boton(t('file_preview.download'), <DownloadIcon />, descargar)}
        {boton(t('common.close'), <CloseIcon />, onClose)}
      </Stack>
      <Box sx={{ position: 'relative', flex: 1, minHeight: 0, display: 'flex', alignItems: 'center', justifyContent: 'center',
        p: kind === 'pdf' && cargado && pdfEnLinea ? 0 : { xs: 1.5, sm: 3 } }}>
        {contenido()}
        {total > 1 && (['left', 'right'] as const).map((lado) => (
          <IconButton key={lado} onClick={() => ir(lado === 'left' ? -1 : 1)}
            aria-label={t(lado === 'left' ? 'file_preview.previous' : 'file_preview.next')}
            sx={{ position: 'absolute', top: '50%', transform: 'translateY(-50%)', [lado]: 12, color: 'common.white',
              bgcolor: 'rgba(0,0,0,.55)', '&:hover': { bgcolor: 'rgba(0,0,0,.75)' }, boxShadow: 3 }}>
            {lado === 'left' ? <ChevronLeftIcon /> : <ChevronRightIcon />}
          </IconButton>
        ))}
      </Box>
    </Dialog>
  );
};

/** Proveedor del visor: una sola instancia para toda la aplicación. */
export const FilePreviewProvider = ({ children }: { children: ReactNode }) => {
  const [estado, setEstado] = useState<{ files: PreviewFile[]; index: number } | null>(null);
  const valor = useMemo<FilePreviewContextValue>(() => ({
    preview: (files, index = 0) => { if (files.length) setEstado({ files, index }); },
    showBlob: (blob, name) => {
      if (previewKind(name, blob.type) === 'none') saveBlob(blob, name);
      else setEstado({ files: [{ name, mime: blob.type, load: () => Promise.resolve(blob) }], index: 0 });
    },
  }), []);
  return (
    <FilePreviewContext.Provider value={valor}>
      {children}
      {estado && <FilePreviewDialog files={estado.files} index={estado.index}
        onIndexChange={(index) => setEstado((e) => (e ? { ...e, index } : e))} onClose={() => setEstado(null)} />}
    </FilePreviewContext.Provider>
  );
};
