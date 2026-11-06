import { useEffect, useState } from 'react';
import {
  Box, ButtonBase, Card, CardContent, Chip, CircularProgress, Collapse, Divider, Stack, Typography,
} from '@mui/material';
import FactCheckIcon from '@mui/icons-material/FactCheck';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import CheckCircleOutlineIcon from '@mui/icons-material/CheckCircleOutline';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { productividadService, type CalidadDatos, type ItemVerificacion } from '../../api/productividadService';

const COLOR = { alta: 'error', media: 'warning', baja: 'info' } as const;

const rutaDe = (i: ItemVerificacion): string => {
  if (i.tipo === 'activo') return `/activos/${encodeURIComponent(i.codigo ?? i.titulo)}`;
  if (i.tipo === 'persona') return `/empleados/${i.id}`;
  // Marcas casi iguales: se abre el catálogo de marcas para fusionarlas.
  if (i.tipo === 'marca') return '/config/catalogos?c=marcas';
  return '/config/modelos';
};

/**
 * Calidad de datos: qué registros faltan por completar o no cuadran, con
 * acceso directo a cada uno. Ayuda a que el inventario quede en orden.
 */
export const DataQualityCard = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [datos, setDatos] = useState<CalidadDatos | null>(null);
  const [abierta, setAbierta] = useState<string | null>(null);

  useEffect(() => {
    productividadService.calidadDatos().then(setDatos).catch(() => setDatos({ pendientes: 0, verificaciones: [] }));
  }, []);

  const conHallazgos = datos?.verificaciones.filter((v) => v.total > 0) ?? [];

  return (
    <Card sx={{ mb: 3 }}>
      <CardContent>
        <Stack direction="row" alignItems="center" spacing={1} sx={{ mb: 1 }}>
          <FactCheckIcon color="primary" fontSize="small" />
          <Typography variant="h6" sx={{ flex: 1 }}>{t('quality.title')}</Typography>
          {datos && datos.pendientes > 0 && <Chip size="small" color="warning" label={t('quality.pending', { count: datos.pendientes })} />}
        </Stack>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>{t('quality.subtitle')}</Typography>
        {!datos ? (
          <Box sx={{ display: 'flex', justifyContent: 'center', py: 2 }}><CircularProgress size={24} /></Box>
        ) : conHallazgos.length === 0 ? (
          <Stack direction="row" spacing={1} alignItems="center" sx={{ color: 'success.main' }}>
            <CheckCircleOutlineIcon fontSize="small" />
            <Typography variant="body2">{t('quality.all_good')}</Typography>
          </Stack>
        ) : (
          <Stack divider={<Divider flexItem />}>
            {conHallazgos.map((v) => {
              const abiertaEsta = abierta === v.clave;
              return (
                <Box key={v.clave}>
                  <ButtonBase onClick={() => setAbierta(abiertaEsta ? null : v.clave)} aria-expanded={abiertaEsta}
                    sx={{ width: '100%', justifyContent: 'flex-start', gap: 1.5, py: 1, px: 0.5, borderRadius: 1, textAlign: 'left' }}>
                    <Chip size="small" color={COLOR[v.severidad]} label={v.total} sx={{ minWidth: 40, fontWeight: 700 }} />
                    <Box sx={{ flex: 1, minWidth: 0 }}>
                      <Typography variant="body2" fontWeight={600}>{t(`quality.checks.${v.clave}.title`)}</Typography>
                      <Typography variant="caption" color="text.secondary">{t(`quality.checks.${v.clave}.help`)}</Typography>
                    </Box>
                    <ExpandMoreIcon fontSize="small" sx={{ color: 'text.secondary', transition: 'transform 150ms', transform: abiertaEsta ? 'rotate(180deg)' : 'none' }} />
                  </ButtonBase>
                  <Collapse in={abiertaEsta} unmountOnExit>
                    <Stack direction="row" useFlexGap flexWrap="wrap" gap={0.75} sx={{ pb: 1.5, pl: 6.5 }}>
                      {v.items.map((i) => (
                        <Chip key={`${i.tipo}-${i.id}`} size="small" variant="outlined" clickable label={i.titulo}
                          title={i.detalle ?? undefined} onClick={() => navigate(rutaDe(i))} />
                      ))}
                      {v.total > v.items.length && (
                        <Typography variant="caption" color="text.secondary" sx={{ alignSelf: 'center' }}>
                          {t('quality.more', { count: v.total - v.items.length })}
                        </Typography>
                      )}
                    </Stack>
                  </Collapse>
                </Box>
              );
            })}
          </Stack>
        )}
      </CardContent>
    </Card>
  );
};
