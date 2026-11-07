import { useCallback, useEffect, useState } from 'react';
import { Box, Button, Card, CardContent, Chip, CircularProgress, Divider, Stack, Typography } from '@mui/material';
import CheckCircleOutlineIcon from '@mui/icons-material/CheckCircleOutline';
import NotificationsActiveIcon from '@mui/icons-material/NotificationsActive';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { inventarioService } from '../../api/inventarioService';
import { getApiErrorMessage } from '../../api/errors';
import { assetService } from '../assets/assetService';
import { AssetDetailDialog } from '../assets/AssetDetailDialog';
import type { ItemAtencion } from '../../types/kardex';
import type { Activo } from '../../types/inventory';
import { formatDate } from '../../utils/dates';
import { approvalSummaryLabel } from '../../utils/catalogLabels';
import { useAuth } from '../../context/auth-context';
import { DESTINO, pendientesDeRol } from './atencion';

type Fila = Record<string, unknown>;
const COLOR = { alta: 'error', media: 'warning', baja: 'info' } as const;


/**
 * «Requiere su atención»: lo que el sistema detecta que hay que resolver
 * (inconsistencias, préstamos vencidos, stock bajo…), con
 * acceso directo a cada caso. El tablero deja de ser solo lectura.
 */
export const AttentionInbox = ({ onLoaded }: { onLoaded?: (items: ItemAtencion[]) => void }) => {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const { enqueueSnackbar } = useSnackbar();
  const { user } = useAuth();
  const [todos, setItems] = useState<ItemAtencion[] | null>(null);
  // «Mis pendientes»: lo que corresponde al rol (técnico, jefe de TI, auditor…).
  const items = todos === null ? null : pendientesDeRol(todos, user?.role);
  const [activo, setActivo] = useState<Activo | null>(null);

  useEffect(() => {
    if (todos) onLoaded?.(todos);
  }, [todos, onLoaded]);

  const cargar = useCallback(async () => {
    try {
      setItems(await inventarioService.atencion());
    } catch {
      setItems([]);
    }
  }, []);

  useEffect(() => {
    let vivo = true;
    inventarioService.atencion()
      .then((r) => { if (vivo) setItems(r); })
      .catch(() => { if (vivo) setItems([]); });
    return () => { vivo = false; };
  }, []);

  const abrirActivo = async (id: string) => {
    try {
      setActivo(await assetService.getActivo(id));
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_loading')), { variant: 'error' });
    }
  };

  const etiqueta = (clave: string, f: Fila): string => {
    switch (clave) {
      case 'PERSONA_INACTIVA_CON_EQUIPOS': return `${f.nombre} (${f.cantidad})`;
      case 'PRESTAMO_VENCIDO': return `${f.codigo} · ${formatDate(String(f.fecha), i18n.language)}`;
      case 'STOCK_BAJO': return `${f.nombre}: ${f.stock}/${f.minimo}`;
      case 'INGRESO_POR_CONFIRMAR': return `${f.codigo} · ${f.documento}`;
      case 'APROBACION_PENDIENTE': return approvalSummaryLabel(t, String(f.resumen ?? '')).slice(0, 80);
      case 'TICKET_ANTIGUO': return `${f.codigo} · ${formatDate(String(f.desde), i18n.language)}`;
      case 'LICENCIA_POR_VENCER': return `${f.nombre} · ${formatDate(String(f.fecha), i18n.language)}`;
      case 'CUENTA_POR_CAMBIAR':
      case 'CUENTA_SIN_ROTAR': return `${f.nombre} · ${f.usuario ?? '—'}`;
      case 'CUPOS_AGOTADOS': return `${f.nombre} (${f.total})`;
      default: return String(f.codigo ?? f.nombre ?? '');
    }
  };

  const alPulsar = (clave: string, f: Fila) => {
    if (DESTINO[clave]?.activos) {
      void abrirActivo(String(f.id));
    } else if (clave === 'INGRESO_POR_CONFIRMAR') {
      navigate(`/ingresos/${f.id}`);
    } else {
      navigate(DESTINO[clave]?.ruta ?? '/');
    }
  };

  return (
    <Card variant="outlined" sx={{ mb: 3 }}>
      <CardContent>
        <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 1.5 }}>
          <NotificationsActiveIcon color="warning" />
          <Typography variant="h6" sx={{ flex: 1 }}>{t('attention.my_title')}</Typography>
          {items && items.length > 0 && <Chip size="small" label={items.reduce((a, i) => a + i.total, 0)} color="warning" />}
        </Stack>
        {items === null ? (<Box display="flex" justifyContent="center" py={2}><CircularProgress size={24} /></Box>)
          : items.length === 0 ? (
            <Stack direction="row" spacing={1} alignItems="center" color="success.main">
              <CheckCircleOutlineIcon fontSize="small" />
              <Typography variant="body2">{t('attention.all_clear')}</Typography>
            </Stack>
          ) : (
            <Stack divider={<Divider flexItem />} spacing={1.5}>
              {items.map((it) => (
                <Box key={it.clave}>
                  <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} alignItems={{ sm: 'center' }}>
                    <Chip size="small" color={COLOR[it.severidad]} label={t(`attention.severity.${it.severidad}`)} sx={{ alignSelf: 'flex-start' }} />
                    <Box sx={{ flex: 1, minWidth: 0 }}>
                      <Typography variant="subtitle2">{t(`attention.items.${it.clave}.title`, { count: it.total })}</Typography>
                      <Typography variant="caption" color="text.secondary">
                        {t(`attention.items.${it.clave}.hint`, { sin_garantia: it.sin_garantia ?? 0, sin_costo: it.sin_costo ?? 0, dias: it.dias ?? 0 })}
                      </Typography>
                    </Box>
                    <Button size="small" onClick={() => navigate(DESTINO[it.clave]?.ruta ?? '/')}>{t('attention.go')}</Button>
                  </Stack>
                  {it.items.length > 0 && (
                    <Stack direction="row" spacing={0.5} useFlexGap flexWrap="wrap" sx={{ mt: 1 }}>
                      {it.items.slice(0, 8).map((f, i) => (
                        <Chip key={i} size="small" variant="outlined" clickable label={etiqueta(it.clave, f)} onClick={() => alPulsar(it.clave, f)} />
                      ))}
                      {it.total > 8 && <Chip size="small" label={t('attention.more', { count: it.total - 8 })} />}
                    </Stack>
                  )}
                </Box>
              ))}
            </Stack>
          )}
      </CardContent>
      <AssetDetailDialog open={!!activo} asset={activo} onClose={() => setActivo(null)} onChanged={cargar} />
    </Card>
  );
};
