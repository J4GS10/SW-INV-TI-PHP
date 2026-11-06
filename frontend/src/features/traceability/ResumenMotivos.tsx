import { Box, Button, Card, CardContent, Grid, LinearProgress, Stack, Typography } from '@mui/material';
import FileDownloadIcon from '@mui/icons-material/FileDownload';
import { useTranslation } from 'react-i18next';
import { guardarArchivo } from '../../api/productividadService';
import type { CatalogoClave } from '../../api/catalogoService';
import { useCatalogLabel } from '../../hooks/useCatalogo';
import { catalogCodeLabel, movementTypeLabel } from '../../utils/catalogLabels';
import { formatMoneyTotals } from '../../utils/currency';

export interface ResumenMotivosData {
  movimientos: Array<{ codigo: string | null; nombre: string | null; tipo: string | null; total: number }>;
  fallas: Array<{ codigo: string | null; nombre: string | null; total: number; costo_por_moneda: Record<string, string> }>;
  acciones: Array<{ codigo: string | null; nombre: string | null; total: number }>;
  bajas_motivo: Array<{ codigo: string | null; total: number }>;
  bajas_destino: Array<{ codigo: string | null; total: number }>;
}

type Fila = { etiqueta: string; detalle?: string; total: number };
// Marca de orden de bytes: Excel abre el CSV en UTF-8 (acentos correctos).
const BOM = String.fromCharCode(0xfeff);
const SALTO = String.fromCharCode(13, 10);

/**
 * Reporte agrupado por catálogo: movimientos por motivo, mantenimientos por
 * falla y acción, y bajas por motivo y destino, en el periodo y sede elegidos.
 * El CSV se arma aquí para que los nombres salgan en el idioma del usuario.
 */
export const ResumenMotivos = ({ data, desde, hasta }: { data: ResumenMotivosData | null; desde: string; hasta: string }) => {
  const { t, i18n } = useTranslation();
  const motivoBaja = useCatalogLabel('motivos-baja');
  const destinoBaja = useCatalogLabel('destinos-baja');
  const sinMotivo = t('traceability.reasons.unclassified');
  const etiqueta = (clave: CatalogoClave, codigo: string | null, nombre: string | null) =>
    (codigo ? catalogCodeLabel(t, clave, codigo, nombre) : sinMotivo);

  const secciones: { clave: string; filas: Fila[] }[] = data ? [
    {
      clave: 'movements',
      filas: data.movimientos.map((m) => ({
        etiqueta: etiqueta('motivos-movimiento', m.codigo, m.nombre), detalle: movementTypeLabel(t, m.tipo), total: m.total,
      })),
    },
    {
      clave: 'faults',
      filas: data.fallas.map((f) => ({
        etiqueta: etiqueta('tipos-falla', f.codigo, f.nombre),
        detalle: Object.keys(f.costo_por_moneda).length
          ? formatMoneyTotals(Object.fromEntries(Object.entries(f.costo_por_moneda).map(([m, v]) => [m, Number(v)])), i18n.language)
          : undefined,
        total: f.total,
      })),
    },
    { clave: 'actions', filas: data.acciones.map((a) => ({ etiqueta: etiqueta('acciones-mantenimiento', a.codigo, a.nombre), total: a.total })) },
    { clave: 'disposal_reasons', filas: data.bajas_motivo.map((b) => ({ etiqueta: b.codigo ? motivoBaja(b.codigo) : sinMotivo, total: b.total })) },
    { clave: 'disposal_destinations', filas: data.bajas_destino.map((b) => ({ etiqueta: b.codigo ? destinoBaja(b.codigo) : sinMotivo, total: b.total })) },
  ] : [];

  const exportar = () => {
    const celda = (v: string | number) => `"${String(v).replace(/"/g, '""')}"`;
    const lineas = [[t('traceability.reasons.section'), t('traceability.reasons.item'), t('traceability.reasons.detail'),
      t('traceability.reasons.total')].map(celda).join(',')];
    secciones.forEach((s) => s.filas.forEach((f) => lineas.push(
      [t(`traceability.reasons.${s.clave}`), f.etiqueta, f.detalle ?? '', f.total].map(celda).join(','))));
    guardarArchivo(new Blob([BOM + lineas.join(SALTO)], { type: 'text/csv;charset=utf-8' }),
      `${t('traceability.reasons.file')}_${desde}_${hasta}.csv`);
  };

  return (
    <Box>
      <Stack direction="row" justifyContent="flex-end" sx={{ mb: 1.5 }}>
        <Button variant="outlined" startIcon={<FileDownloadIcon />} onClick={exportar} disabled={!data}>
          {t('traceability.reasons.export')}
        </Button>
      </Stack>
      <Grid container spacing={2}>
        {secciones.map((s) => {
          const maximo = Math.max(1, ...s.filas.map((f) => f.total));
          const suma = s.filas.reduce((a, f) => a + f.total, 0);
          return (
            <Grid key={s.clave} size={{ xs: 12, md: 6 }}>
              <Card variant="outlined" sx={{ height: '100%' }}>
                <CardContent>
                  <Stack direction="row" justifyContent="space-between" alignItems="baseline" sx={{ mb: 1 }}>
                    <Typography variant="subtitle1">{t(`traceability.reasons.${s.clave}`)}</Typography>
                    <Typography variant="caption" color="text.secondary">{t('traceability.reasons.count', { count: suma })}</Typography>
                  </Stack>
                  {s.filas.length === 0 ? (
                    <Typography variant="body2" color="text.secondary">{t('traceability.no_movements_period')}</Typography>
                  ) : s.filas.map((f, i) => (
                    <Box key={`${f.etiqueta}-${f.detalle ?? ''}-${i}`} sx={{ mb: 1 }}>
                      <Stack direction="row" justifyContent="space-between" spacing={1}>
                        <Typography variant="body2" sx={{ minWidth: 0 }} noWrap>
                          {f.etiqueta}
                          {f.detalle && <Typography component="span" variant="caption" color="text.secondary"> · {f.detalle}</Typography>}
                        </Typography>
                        <Typography variant="body2" fontWeight={600}>{f.total}</Typography>
                      </Stack>
                      <LinearProgress variant="determinate" value={(f.total / maximo) * 100} sx={{ height: 6, borderRadius: 3 }} />
                    </Box>
                  ))}
                </CardContent>
              </Card>
            </Grid>
          );
        })}
      </Grid>
    </Box>
  );
};
