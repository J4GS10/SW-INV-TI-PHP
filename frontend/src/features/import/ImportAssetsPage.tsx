import { useRef, useState } from 'react';
import {
  Alert, Box, Button, Chip, CircularProgress, FormControlLabel, Grid, Paper, Stack, Switch, TableCell, TableRow, Typography,
} from '@mui/material';
import UploadFileIcon from '@mui/icons-material/UploadFile';
import TableRowsIcon from '@mui/icons-material/TableRows';
import TaskAltIcon from '@mui/icons-material/TaskAlt';
import ErrorOutlineIcon from '@mui/icons-material/ErrorOutline';
import CategoryIcon from '@mui/icons-material/Category';
import FileDownloadIcon from '@mui/icons-material/FileDownload';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { DataTableShell, KpiCard, PageHeader, SectionCard } from '../../components/ui';
import { getApiErrorMessage } from '../../api/errors';
import {
  guardarArchivo, productividadService, type FilaImportacion, type PrevisualizacionImportacion,
} from '../../api/productividadService';

const COLOR: Record<FilaImportacion['estado'], 'success' | 'warning' | 'error'> = { ok: 'success', aviso: 'warning', error: 'error' };

/**
 * Importar activos desde Excel: plantilla → subir → revisar fila por fila → confirmar.
 * Nada se guarda hasta confirmar, y la confirmación vuelve a validar el archivo.
 */
export const ImportAssetsPage = () => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const navigate = useNavigate();
  const entrada = useRef<HTMLInputElement | null>(null);
  const [archivo, setArchivo] = useState<File | null>(null);
  const [prev, setPrev] = useState<PrevisualizacionImportacion | null>(null);
  const [cargando, setCargando] = useState<'previa' | 'confirmar' | 'plantilla' | null>(null);
  const [omitir, setOmitir] = useState(false);
  const [soloProblemas, setSoloProblemas] = useState(false);
  const [hecho, setHecho] = useState<{ creados: number; omitidos: number; codigos: string[] } | null>(null);

  const elegir = async (f: File | null) => {
    if (!f) return;
    setArchivo(f); setPrev(null); setHecho(null); setCargando('previa');
    try {
      const p = await productividadService.previsualizarImportacion(f);
      setPrev(p);
      setSoloProblemas(p.resumen.con_error > 0);
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
      setArchivo(null);
    } finally {
      setCargando(null);
      if (entrada.current) entrada.current.value = '';
    }
  };

  const confirmar = async () => {
    if (!archivo) return;
    setCargando('confirmar');
    try {
      setHecho(await productividadService.confirmarImportacion(archivo, omitir));
      setPrev(null);
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error', autoHideDuration: 8000 });
    } finally {
      setCargando(null);
    }
  };

  const plantilla = async () => {
    setCargando('plantilla');
    try {
      guardarArchivo(await productividadService.plantillaImportacion(), 'plantilla_activos.xlsx');
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
    } finally {
      setCargando(null);
    }
  };

  const filas = prev ? prev.filas.filter((f) => !soloProblemas || f.estado !== 'ok') : [];
  const r = prev?.resumen;
  const puedeConfirmar = !!r && r.validas > 0 && (r.con_error === 0 || omitir);

  return (
    <Box>
      <PageHeader
        title={t('import.title')}
        subtitle={t('import.subtitle')}
        actions={(
          <Button variant="outlined" onClick={plantilla} disabled={cargando === 'plantilla'}
            startIcon={cargando === 'plantilla' ? <CircularProgress size={16} /> : <FileDownloadIcon />}>
            {t('import.template')}
          </Button>
        )}
      />

      <SectionCard title={t('import.step_upload')}>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} alignItems={{ sm: 'center' }}>
          <input ref={entrada} type="file" hidden accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            onChange={(e) => void elegir(e.target.files?.[0] ?? null)} />
          <Button variant="contained" onClick={() => entrada.current?.click()} disabled={!!cargando}
            startIcon={cargando === 'previa' ? <CircularProgress size={16} color="inherit" /> : <UploadFileIcon />}>
            {archivo ? t('import.choose_another') : t('import.choose')}
          </Button>
          <Typography variant="body2" color="text.secondary">
            {archivo ? archivo.name : t('import.formats')}
          </Typography>
        </Stack>
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1.5 }}>{t('import.columns_help')}</Typography>
      </SectionCard>

      {hecho && (
        <Alert severity="success" icon={<CheckCircleIcon />} sx={{ mt: 2 }}
          action={<Button color="inherit" size="small" onClick={() => navigate('/activos')}>{t('import.view_assets')}</Button>}>
          {t('import.done', { count: hecho.creados })}{hecho.omitidos ? ` ${t('import.done_skipped', { count: hecho.omitidos })}` : ''}
        </Alert>
      )}

      {prev && r && (
        <Box sx={{ mt: 2 }}>
          <Grid container spacing={2} sx={{ mb: 2 }}>
            <Grid size={{ xs: 6, md: 3 }}><KpiCard title={t('import.kpi.rows')} value={r.filas} icon={TableRowsIcon} tone="info" /></Grid>
            <Grid size={{ xs: 6, md: 3 }}><KpiCard title={t('import.kpi.valid')} value={r.validas} icon={TaskAltIcon} tone="success" /></Grid>
            <Grid size={{ xs: 6, md: 3 }}><KpiCard title={t('import.kpi.errors')} value={r.con_error} icon={ErrorOutlineIcon} tone={r.con_error ? 'error' : 'success'} /></Grid>
            <Grid size={{ xs: 6, md: 3 }}><KpiCard title={t('import.kpi.new_catalog')} value={`${r.marcas_nuevas} / ${r.modelos_nuevos}`} icon={CategoryIcon} tone="secondary" /></Grid>
          </Grid>

          <DataTableShell
            columns={[
              { key: 'row', label: t('import.col.row'), width: 70 },
              { key: 'code', label: t('assets.table.code') },
              { key: 'type', label: t('assets.table.type') },
              { key: 'model', label: t('assets.dialog.model') },
              { key: 'serial', label: t('assets.table.serial') },
              { key: 'area', label: t('import.col.area') },
              { key: 'status', label: t('import.col.result') },
            ]}
            rows={filas}
            emptyTitle={t('import.no_problems')}
            toolbar={(
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, flexWrap: 'wrap' }}>
                <FormControlLabel control={<Switch checked={soloProblemas} onChange={(e) => setSoloProblemas(e.target.checked)} />}
                  label={t('import.only_problems')} />
                {r.con_error > 0 && (
                  <FormControlLabel control={<Switch checked={omitir} onChange={(e) => setOmitir(e.target.checked)} />}
                    label={t('import.skip_errors', { count: r.con_error })} />
                )}
                <Box sx={{ ml: 'auto' }}>
                  <Button variant="contained" onClick={confirmar} disabled={!puedeConfirmar || !!cargando}
                    startIcon={cargando === 'confirmar' ? <CircularProgress size={16} color="inherit" /> : undefined}>
                    {t('import.confirm', { count: r.validas })}
                  </Button>
                </Box>
              </Box>
            )}
            renderRow={(f) => (
              <TableRow key={f.fila}>
                <TableCell>{f.fila}</TableCell>
                <TableCell>{f.codigo ?? <Typography variant="caption" color="text.secondary">{t('import.auto')}</Typography>}</TableCell>
                <TableCell>{f.categoria ?? '—'}</TableCell>
                <TableCell sx={{ maxWidth: 260 }}>
                  <Typography variant="body2" noWrap title={`${f.marca ?? ''} ${f.modelo ?? ''}`}>{[f.marca, f.modelo].filter(Boolean).join(' · ') || '—'}</Typography>
                </TableCell>
                <TableCell>{f.serie ?? <Typography variant="caption" color="text.secondary">{t('import.no_serial')}</Typography>}</TableCell>
                <TableCell>{f.ubicacion ?? '—'}</TableCell>
                <TableCell>
                  <Stack direction="row" useFlexGap flexWrap="wrap" gap={0.5}>
                    {f.estado === 'ok' && <Chip size="small" color="success" variant="outlined" label={t('import.status.ok')} />}
                    {f.errores.map((c) => <Chip key={c} size="small" color={COLOR.error} label={t(`import.msg.${c}`)} />)}
                    {f.avisos.map((c) => <Chip key={c} size="small" color={COLOR.aviso} variant="outlined" label={t(`import.msg.${c}`)} />)}
                  </Stack>
                </TableCell>
              </TableRow>
            )}
          />
        </Box>
      )}

      {!prev && !hecho && (
        <Paper variant="outlined" sx={{ mt: 2, p: 2, borderRadius: 2 }}>
          <Typography variant="subtitle2" gutterBottom>{t('import.how_title')}</Typography>
          <Stack component="ol" spacing={0.5} sx={{ pl: 2.5, m: 0 }}>
            {(['1', '2', '3', '4'] as const).map((n) => <Typography key={n} component="li" variant="body2">{t(`import.how.${n}`)}</Typography>)}
          </Stack>
        </Paper>
      )}
    </Box>
  );
};
