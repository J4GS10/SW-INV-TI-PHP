import { useCallback, useEffect, useMemo, useState } from 'react';
import { Alert, Box, Button, Card, CardContent, Chip, CircularProgress, DialogActions, DialogContent, DialogTitle, Grid, MenuItem, Stack, Tab, TableCell, TableRow, Tabs, TextField, Typography } from '@mui/material';
import TimelineIcon from '@mui/icons-material/Timeline';
import VerifiedIcon from '@mui/icons-material/VerifiedUser';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { useSearchParams } from 'react-router-dom';
import { DataTableShell, PageHeader } from '../../components/ui';
import { CollapsibleToolbar } from '../../components/CollapsibleToolbar';
import { ExportMenuButton } from '../../components/ExportMenuButton';
import { SearchInput } from '../../components/SearchInput';
import { inventarioService, type VerificacionKardex } from '../../api/inventarioService';
import { getApiErrorMessage } from '../../api/errors';
import { useSedes } from '../../hooks/useSedes';
import { usePermissions } from '../../hooks/usePermissions';
import type {
  CustodiaItem, KardexCategoria, KardexEvento, KardexFiltros, KardexObjeto, ResumenEntradasSalidas, TipoEvento,
} from '../../types/kardex';
import { TIPOS_EVENTO } from '../../types/kardex';
import { formatDate, formatDateTimeValue, hoyLocal } from '../../utils/dates';
import { formatMoneyTotals } from '../../utils/currency';
import { kardexReasonText } from '../../utils/kardexDisplay';
import { typeLabel } from '../../utils/catalogLabels';
import { ResumenMotivos, type ResumenMotivosData } from './ResumenMotivos';
import { Dialog } from '../../components/ui/Dialog';
import { DateField } from '../../components/ui/DateField';
import { useLiveRefresh } from '../../hooks/useLiveRefresh';

type Vista = 'kardex' | 'entradas_salidas' | 'reasignaciones' | 'custodia' | 'resumen' | 'motivos';
const VISTAS: Vista[] = ['kardex', 'entradas_salidas', 'reasignaciones', 'custodia', 'resumen', 'motivos'];
const CATEGORIA: Partial<Record<Vista, KardexCategoria>> = {
  entradas_salidas: 'ENTRADAS_SALIDAS',
  reasignaciones: 'REASIGNACIONES',
};

const lugar = (...partes: Array<string | null | undefined>) => partes.filter(Boolean).join(' · ') || '—';

/**
 * Trazabilidad del inventario sobre el kardex unificado: qué pasó con cada
 * activo, consumible o licencia; bitácora de entradas y salidas; reasignaciones
 * de equipos, componentes y licencias; custodia a una fecha y resumen del periodo.
 */
export const TrazabilidadPage = () => {
  const { t, i18n } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const { canExport, canVerifyKardex } = usePermissions();
  const [verificacion, setVerificacion] = useState<VerificacionKardex | null>(null);
  const [verificando, setVerificando] = useState(false);
  const verificar = async () => {
    setVerificando(true);
    try {
      setVerificacion(await inventarioService.verificarKardex());
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
    } finally {
      setVerificando(false);
    }
  };
  const { sedes } = useSedes();
  const [searchParams, setSearchParams] = useSearchParams();
  const vista = (VISTAS.includes(searchParams.get('vista') as Vista) ? searchParams.get('vista') : 'kardex') as Vista;

  const [objeto, setObjeto] = useState<KardexObjeto | ''>('');
  const [tipo, setTipo] = useState<TipoEvento | ''>('');
  const [sede, setSede] = useState<number | ''>('');
  const [desde, setDesde] = useState(hoyLocal(-30));
  const [hasta, setHasta] = useState(hoyLocal());
  const [texto, setTexto] = useState('');
  const [fecha, setFecha] = useState(hoyLocal());
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(50);

  const [eventos, setEventos] = useState<KardexEvento[]>([]);
  const [total, setTotal] = useState(0);
  const [custodia, setCustodia] = useState<CustodiaItem[]>([]);
  const [resumen, setResumen] = useState<ResumenEntradasSalidas | null>(null);
  const [motivos, setMotivos] = useState<ResumenMotivosData | null>(null);
  const [loading, setLoading] = useState(true);

  const filtros = useMemo<KardexFiltros>(() => ({
    objeto: objeto || undefined,
    tipos: tipo ? [tipo] : undefined,
    categoria: CATEGORIA[vista],
    sede_id: sede || undefined,
    desde: desde || undefined,
    hasta: hasta || undefined,
    texto: texto.trim() || undefined,
  }), [desde, hasta, objeto, sede, texto, tipo, vista]);

  const cargar = useCallback(async () => {
    setLoading(true);
    try {
      if (vista === 'custodia') {
        setCustodia(await inventarioService.custodia({ fecha, sede_id: sede || undefined }));
      } else if (vista === 'resumen') {
        setResumen(await inventarioService.entradasSalidas({ desde, hasta, sede_id: sede || undefined }));
      } else if (vista === 'motivos') {
        setMotivos(await inventarioService.resumenMotivos({ desde, hasta, sede_id: sede || undefined }));
      } else {
        const data = await inventarioService.kardex(filtros, rowsPerPage, page * rowsPerPage);
        setEventos(data.items);
        setTotal(data.total);
      }
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_loading')), { variant: 'error' });
    } finally {
      setLoading(false);
    }
  }, [desde, enqueueSnackbar, fecha, filtros, hasta, page, rowsPerPage, sede, t, vista]);

  useEffect(() => { cargar(); }, [cargar]);
  useLiveRefresh(cargar);

  const cambiarVista = (_: unknown, nueva: Vista) => {
    setPage(0);
    setSearchParams(nueva === 'kardex' ? {} : { vista: nueva });
  };

  const exportar = async (lang: 'es' | 'en' | 'it') => {
    try {
      if (vista === 'custodia') await inventarioService.custodiaCsv({ fecha, sede_id: sede || undefined }, lang);
      else await inventarioService.kardexCsv(filtros, lang);
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
    }
  };

  const filtrosActivos = [objeto, tipo, sede, texto].filter(Boolean).length;
  const filterSx = { minWidth: { xs: '100%', sm: 170 } };

  return (
    <Box>
      <PageHeader
        title={t('traceability.title')}
        subtitle={t('traceability.subtitle')}
        badge={vista === 'custodia' ? custodia.length : vista === 'resumen' || vista === 'motivos' ? undefined : total}
        actions={<>
          {canVerifyKardex && (
            <Button variant="outlined" startIcon={verificando ? <CircularProgress size={16} /> : <VerifiedIcon />} onClick={verificar} disabled={verificando}>
              {t('traceability.verify.button')}
            </Button>
          )}
          {canExport && vista !== 'resumen' && vista !== 'motivos' && (
            <ExportMenuButton onExport={exportar} disabled={vista === 'custodia' ? custodia.length === 0 : total === 0} />
          )}
        </>}
      />
      <Dialog open={!!verificacion} onClose={() => setVerificacion(null)} maxWidth="sm" fullWidth>
        <DialogTitle>{t('traceability.verify.title')}</DialogTitle>
        <DialogContent>
          {verificacion && (verificacion.integro ? (
            <Alert severity="success">{t('traceability.verify.ok', { count: verificacion.total })}</Alert>
          ) : (
            <Alert severity="error">
              {t('traceability.verify.broken', { count: verificacion.problemas.length })}
              {verificacion.problemas.slice(0, 10).map((p) => (
                <Typography key={`${p.evento}-${p.problema}`} variant="body2">
                  #{p.secuencia ?? '—'} · {t(`traceability.verify.problems.${p.problema}`)}
                </Typography>
              ))}
            </Alert>
          ))}
          {verificacion?.ultimo_hash && (
            <Box sx={{ mt: 2 }}>
              <Typography variant="subtitle2">{t('traceability.verify.last_seal')}</Typography>
              <Typography variant="body2">{t('traceability.verify.sequence', { n: verificacion.ultima_secuencia })} · {formatDateTimeValue(verificacion.ultima_fecha, i18n.language)}</Typography>
              <Typography variant="body2" sx={{ fontFamily: 'monospace', overflowWrap: 'anywhere' }}>{verificacion.ultimo_hash}</Typography>
              <Typography variant="caption" color="text.secondary">{t('traceability.verify.anchor_hint')}</Typography>
            </Box>
          )}
        </DialogContent>
        <DialogActions><Button onClick={() => setVerificacion(null)}>{t('common.close')}</Button></DialogActions>
      </Dialog>

      <Tabs value={vista} onChange={cambiarVista} variant="scrollable" allowScrollButtonsMobile sx={{ mb: 2 }}>
        {VISTAS.map((v) => <Tab key={v} value={v} label={t(`traceability.tabs.${v}`)} />)}
      </Tabs>

      <CollapsibleToolbar
        storageKey="trazabilidad"
        summary={filtrosActivos > 0 && <Chip size="small" color="primary" variant="outlined"
          label={t('common.active_filters_count', { count: filtrosActivos })} />}
        search={vista !== 'custodia' && vista !== 'resumen' && vista !== 'motivos'
          ? <SearchInput value={texto} onChange={(v) => { setTexto(v); setPage(0); }} placeholder={t('traceability.search')} />
          : undefined}
      >
        <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1.5, alignItems: 'center' }}>
          {vista === 'custodia' ? (
            <DateField size="small" label={t('traceability.as_of')} value={fecha}
              onChange={(e) => setFecha(e.target.value)} InputLabelProps={{ shrink: true }} sx={filterSx} />
          ) : (
            <>
              <DateField size="small" label={t('traceability.from_date')} value={desde}
                onChange={(e) => { setDesde(e.target.value); setPage(0); }} InputLabelProps={{ shrink: true }} sx={filterSx} />
              <DateField size="small" label={t('traceability.to_date')} value={hasta}
                onChange={(e) => { setHasta(e.target.value); setPage(0); }} InputLabelProps={{ shrink: true }} sx={filterSx} />
            </>
          )}
          {vista !== 'custodia' && vista !== 'resumen' && vista !== 'motivos' && (
            <>
              <TextField select size="small" label={t('traceability.object')} value={objeto}
                onChange={(e) => { setObjeto(e.target.value as KardexObjeto | ''); setPage(0); }} sx={filterSx}>
                <MenuItem value="">{t('common.all')}</MenuItem>
                {(['ACTIVO', 'CONSUMIBLE', 'LICENCIA'] as const).map((o) => (
                  <MenuItem key={o} value={o}>{t(`traceability.objects.${o}`)}</MenuItem>))}
              </TextField>
              <TextField select size="small" label={t('traceability.event')} value={tipo}
                onChange={(e) => { setTipo(e.target.value as TipoEvento | ''); setPage(0); }} sx={filterSx}>
                <MenuItem value="">{t('common.all')}</MenuItem>
                {TIPOS_EVENTO.map((tp) => <MenuItem key={tp} value={tp}>{t(`kardex.tipos.${tp}`)}</MenuItem>)}
              </TextField>
            </>
          )}
          {sedes.length > 1 && (
            <TextField select size="small" label={t('traceability.site')} value={sede}
              onChange={(e) => { setSede(e.target.value === '' ? '' : Number(e.target.value)); setPage(0); }} sx={filterSx}>
              <MenuItem value="">{t('common.all')}</MenuItem>
              {sedes.map((s) => <MenuItem key={s.SED_Sede} value={s.SED_Sede}>{s.SED_Nombre}</MenuItem>)}
            </TextField>
          )}
        </Box>
      </CollapsibleToolbar>

      {vista === 'resumen' && (
        <Grid container spacing={2}>
          {(resumen?.por_tipo ?? []).map((r) => (
            <Grid key={r.tipo} size={{ xs: 12, sm: 6, md: 4 }}>
              <Card variant="outlined">
                <CardContent>
                  <Stack direction="row" justifyContent="space-between" alignItems="center">
                    <Typography variant="subtitle1">{t(`kardex.tipos.${r.tipo}`)}</Typography>
                    <Chip size="small" color={r.clase === 'ENTRADA' ? 'success' : 'error'}
                      label={t(`traceability.classes.${r.clase}`)} />
                  </Stack>
                  <Typography variant="h5" sx={{ mt: 1 }}>{t('traceability.events_count', { count: r.eventos })}</Typography>
                  <Typography variant="body2" color="text.secondary">
                    {t('traceability.units_count', { count: r.cantidad })}
                    {r.valor_por_moneda && Object.keys(r.valor_por_moneda).length > 0 && ` · ${formatMoneyTotals(r.valor_por_moneda, i18n.language)}`}
                  </Typography>
                </CardContent>
              </Card>
            </Grid>
          ))}
          {!loading && (resumen?.por_tipo ?? []).length === 0 && (
            <Grid size={12}><Typography color="text.secondary">{t('traceability.no_movements_period')}</Typography></Grid>
          )}
        </Grid>
      )}

      {vista === 'motivos' && <ResumenMotivos data={motivos} desde={desde} hasta={hasta} />}

      {vista === 'custodia' && (
        <DataTableShell
          loading={loading}
          rows={custodia}
          emptyTitle={t('traceability.custody_empty')}
          emptyIcon={TimelineIcon}
          columns={[
            { key: 'codigo', label: t('traceability.cols.asset') },
            { key: 'tipo', label: t('traceability.cols.type') },
            { key: 'persona', label: t('traceability.cols.custodian') },
            { key: 'area', label: t('traceability.cols.area') },
            { key: 'desde', label: t('traceability.cols.since') },
            { key: 'devolucion', label: t('traceability.cols.expected_return') },
          ]}
          renderRow={(c) => (
            <TableRow key={`${c.ACT_Activo}-${c.desde}`} hover>
              <TableCell sx={{ fontWeight: 600 }}>{c.activo_codigo}<Typography variant="caption" display="block" color="text.secondary">{c.activo_serie}</Typography></TableCell>
              <TableCell>{c.activo_tipo ? typeLabel(t, c.activo_tipo) : '—'}</TableCell>
              <TableCell>{c.persona}</TableCell>
              <TableCell>{c.area ?? '—'}</TableCell>
              <TableCell>{formatDate(c.desde, i18n.language)}</TableCell>
              <TableCell>{c.devolucion_prevista ? formatDate(c.devolucion_prevista, i18n.language) : '—'}</TableCell>
            </TableRow>
          )}
        />
      )}

      {vista !== 'custodia' && vista !== 'resumen' && vista !== 'motivos' && (
        <DataTableShell
          loading={loading}
          rows={eventos}
          total={total}
          page={page}
          rowsPerPage={rowsPerPage}
          onPageChange={setPage}
          onRowsPerPageChange={(n) => { setRowsPerPage(n); setPage(0); }}
          emptyTitle={t('kardex.empty')}
          emptyDescription={t('traceability.empty_hint')}
          emptyIcon={TimelineIcon}
          columns={[
            { key: 'fecha', label: t('traceability.cols.date') },
            { key: 'evento', label: t('traceability.cols.event') },
            { key: 'objeto', label: t('traceability.cols.item') },
            { key: 'desde', label: t('traceability.cols.from') },
            { key: 'hacia', label: t('traceability.cols.to') },
            { key: 'motivo', label: t('traceability.cols.reason') },
            { key: 'usuario', label: t('traceability.cols.user') },
          ]}
          renderRow={(e) => (
            <TableRow key={e.KDX_Evento} hover>
              <TableCell sx={{ whiteSpace: 'nowrap' }}>{formatDateTimeValue(e.KDX_Fecha, i18n.language)}</TableCell>
              <TableCell>
                {t(`kardex.tipos.${e.KDX_Tipo}`)}
                {e.KDX_Migrado && <Chip size="small" label={t('kardex.migrated')} sx={{ ml: 1 }} />}
              </TableCell>
              <TableCell>
                <Typography variant="body2" fontWeight={600}>{e.KDX_Referencia ?? '—'}</Typography>
                <Typography variant="caption" color="text.secondary">
                  {t(`traceability.objects.${e.KDX_Objeto}`)}
                  {e.KDX_Cantidad != null && ` · ${t('kardex.quantity', { count: e.KDX_Cantidad })}`}
                </Typography>
              </TableCell>
              <TableCell>{lugar(e.origen_persona, e.origen_area, e.origen_sede, e.origen_activo)}</TableCell>
              <TableCell>{lugar(e.destino_persona, e.destino_area, e.destino_sede, e.destino_activo)}</TableCell>
              <TableCell sx={{ maxWidth: 260 }}>
                {kardexReasonText({ motivo: e.KDX_Motivo, documentType: e.KDX_Documento_Tipo, documentRef: e.KDX_Documento_Ref },
                  (type) => t(`kardex.documentos.${type}`, { defaultValue: type })) || '—'}
              </TableCell>
              <TableCell>{e.usuario ?? '—'}</TableCell>
            </TableRow>
          )}
        />
      )}
    </Box>
  );
};
