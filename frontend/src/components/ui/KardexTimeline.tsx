import { Box, Chip, Stack, Tooltip, Typography } from '@mui/material';
import { alpha, useTheme } from '@mui/material/styles';
import AddBoxIcon from '@mui/icons-material/AddBox';
import AssignmentIndIcon from '@mui/icons-material/AssignmentInd';
import BuildIcon from '@mui/icons-material/Build';
import DeleteForeverIcon from '@mui/icons-material/DeleteForever';
import ExtensionIcon from '@mui/icons-material/Extension';
import FactCheckIcon from '@mui/icons-material/FactCheck';
import InventoryIcon from '@mui/icons-material/Inventory';
import KeyIcon from '@mui/icons-material/Key';
import LocalShippingIcon from '@mui/icons-material/LocalShipping';
import MemoryIcon from '@mui/icons-material/Memory';
import SwapHorizIcon from '@mui/icons-material/SwapHoriz';
import UndoIcon from '@mui/icons-material/Undo';
import TuneIcon from '@mui/icons-material/Tune';
import type { SvgIconComponent } from '@mui/icons-material';
import { useTranslation } from 'react-i18next';
import type { KardexEvento, TipoEvento } from '../../types/kardex';
import { formatDateTimeValue } from '../../utils/dates';
import { formatMoney } from '../../utils/currency';
import { kardexReasonText } from '../../utils/kardexDisplay';
import { EmptyState } from './EmptyState';

type Tono = 'primary' | 'success' | 'warning' | 'error' | 'info' | 'secondary';

const ESTILO: Record<TipoEvento, { icon: SvgIconComponent; tono: Tono }> = {
  ALTA: { icon: AddBoxIcon, tono: 'success' },
  RECEPCION: { icon: LocalShippingIcon, tono: 'success' },
  ASIGNACION: { icon: AssignmentIndIcon, tono: 'primary' },
  PRESTAMO: { icon: AssignmentIndIcon, tono: 'info' },
  DEVOLUCION: { icon: UndoIcon, tono: 'secondary' },
  TRANSFERENCIA: { icon: SwapHorizIcon, tono: 'primary' },
  TRASLADO_SEDE: { icon: LocalShippingIcon, tono: 'info' },
  MANTENIMIENTO_ENTRADA: { icon: BuildIcon, tono: 'warning' },
  MANTENIMIENTO_SALIDA: { icon: BuildIcon, tono: 'success' },
  COMPONENTE_INSTALADO: { icon: ExtensionIcon, tono: 'primary' },
  COMPONENTE_RETIRADO: { icon: ExtensionIcon, tono: 'secondary' },
  ESPECIFICACION: { icon: MemoryIcon, tono: 'info' },
  CAMBIO_DATOS: { icon: MemoryIcon, tono: 'info' },
  CAMBIO_COSTO: { icon: MemoryIcon, tono: 'warning' },
  CAMBIO_ESTADO: { icon: TuneIcon, tono: 'info' },
  BAJA: { icon: DeleteForeverIcon, tono: 'error' },
  INVENTARIO_FISICO: { icon: FactCheckIcon, tono: 'warning' },
  CONSUMIBLE_ENTRADA: { icon: InventoryIcon, tono: 'success' },
  CONSUMIBLE_SALIDA: { icon: InventoryIcon, tono: 'warning' },
  CONSUMIBLE_AJUSTE: { icon: TuneIcon, tono: 'info' },
  CONSUMIBLE_TRASLADO: { icon: SwapHorizIcon, tono: 'info' },
  LICENCIA_ALTA: { icon: KeyIcon, tono: 'success' },
  LICENCIA_ASIGNADA: { icon: KeyIcon, tono: 'primary' },
  LICENCIA_RETIRADA: { icon: KeyIcon, tono: 'secondary' },
  LICENCIA_AJUSTE: { icon: TuneIcon, tono: 'info' },
  LICENCIA_BAJA: { icon: DeleteForeverIcon, tono: 'error' },
};

const lugar = (...partes: Array<string | null | undefined>) => partes.filter(Boolean).join(' · ');

interface Props {
  eventos: KardexEvento[];
  loading?: boolean;
  /** Muestra la referencia del objeto (útil cuando la lista mezcla objetos). */
  mostrarReferencia?: boolean;
}

/**
 * Historial legible del kardex: qué pasó, cuándo, quién lo hizo, desde dónde
 * hacia dónde, por qué y con qué documento.
 */
export const KardexTimeline = ({ eventos, loading, mostrarReferencia }: Props) => {
  const { t, i18n } = useTranslation();
  const theme = useTheme();
  if (!loading && eventos.length === 0) {
    return <EmptyState compact title={t('kardex.empty')} description={t('kardex.empty_hint')} />;
  }
  return (
    <Stack spacing={0} component="ol" sx={{ listStyle: 'none', m: 0, p: 0 }}>
      {eventos.map((e, i) => {
        const { icon: Icon, tono } = ESTILO[e.KDX_Tipo] ?? { icon: TuneIcon, tono: 'info' as Tono };
        const color = theme.palette[tono].main;
        const desde = lugar(e.origen_persona, e.origen_area, e.origen_sede, e.origen_activo);
        const hacia = lugar(e.destino_persona, e.destino_area, e.destino_sede, e.destino_activo);
        const detalle = e.KDX_Detalle ?? {};
        const cambioEspec = e.KDX_Tipo === 'ESPECIFICACION' && ('antes' in detalle || 'despues' in detalle);
        const motivo = kardexReasonText({ motivo: e.KDX_Motivo, documentType: e.KDX_Documento_Tipo, documentRef: e.KDX_Documento_Ref },
          (type) => t(`kardex.documentos.${type}`, { defaultValue: type }));
        return (
          <Box component="li" key={e.KDX_Evento} sx={{ display: 'flex', gap: 1.5, position: 'relative', pb: 2 }}>
            <Box sx={{ display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
              <Box sx={{
                width: 32, height: 32, borderRadius: '50%', display: 'grid', placeItems: 'center', flexShrink: 0,
                bgcolor: alpha(color, 0.14), color,
              }}>
                <Icon fontSize="small" />
              </Box>
              {i < eventos.length - 1 && <Box sx={{ flex: 1, width: 2, bgcolor: 'divider', mt: 0.5 }} />}
            </Box>
            <Box sx={{ minWidth: 0, flex: 1 }}>
              <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
                <Typography variant="subtitle2">{t(`kardex.tipos.${e.KDX_Tipo}`)}</Typography>
                {mostrarReferencia && e.KDX_Referencia && <Chip size="small" variant="outlined" label={e.KDX_Referencia} />}
                {e.KDX_Migrado && (
                  <Tooltip title={t('kardex.migrated_hint')}>
                    <Chip size="small" label={t('kardex.migrated')} />
                  </Tooltip>
                )}
                <Typography variant="caption" color="text.secondary">
                  {formatDateTimeValue(e.KDX_Fecha, i18n.language)}{e.usuario ? ` · ${t('kardex.by', { user: e.usuario })}` : ''}
                </Typography>
              </Stack>
              {(desde || hacia) && (
                <Typography variant="body2" color="text.secondary">
                  {desde && <>{t('kardex.from')}: <b>{desde}</b></>}
                  {desde && hacia && ' → '}
                  {hacia && <>{t('kardex.to')}: <b>{hacia}</b></>}
                </Typography>
              )}
              {cambioEspec && (
                <Typography variant="body2" color="text.secondary">
                  {t('kardex.spec_change', { antes: String(detalle.antes ?? '—'), despues: String(detalle.despues ?? '—') })}
                </Typography>
              )}
              {(e.KDX_Cantidad != null || e.KDX_Saldo != null || e.KDX_Costo != null) && (
                <Typography variant="body2" color="text.secondary">
                  {[e.KDX_Cantidad != null ? t('kardex.quantity', { count: e.KDX_Cantidad }) : '',
                    e.KDX_Saldo != null ? t('kardex.balance', { value: e.KDX_Saldo }) : '',
                    e.KDX_Costo != null ? formatMoney(e.KDX_Costo, e.KDX_Moneda ?? undefined, i18n.language) : '']
                    .filter(Boolean).join(' · ')}
                </Typography>
              )}
              {motivo && <Typography variant="body2">{motivo}</Typography>}
            </Box>
          </Box>
        );
      })}
    </Stack>
  );
};
