import { useEffect, useState } from 'react';
import { Box, Chip, CircularProgress, Stack, Table, TableBody, TableCell, TableHead, TableRow, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import client from '../../api/axios';
import { EmptyState } from '../../components/ui';
import { formatDateTimeValue } from '../../utils/dates';

interface Consumo {
  historial: { MOC_Movimiento: number; MOC_Fecha: string; consumible: string; unidad: string; MOC_Cantidad: number;
    MOC_Motivo: string | null; usuario: string | null }[];
  compatibles: { CON_Consumible: number; CON_Nombre: string; CON_Stock_Actual: number; CON_Unidad: string }[];
}

/**
 * Consumibles de un equipo (tóner, tinta, cartuchos): los compatibles con su
 * modelo y su existencia, y cada cambio registrado como salida a este equipo.
 */
export const ConsumiblesEquipo = ({ activoId }: { activoId: string }) => {
  const { t, i18n } = useTranslation();
  const [datos, setDatos] = useState<Consumo | null>(null);

  useEffect(() => {
    client.get<Consumo>(`/core/activos/${activoId}/consumibles`).then((r) => setDatos(r.data))
      .catch(() => setDatos({ historial: [], compatibles: [] }));
  }, [activoId]);

  if (!datos) return <Box display="flex" justifyContent="center" py={3}><CircularProgress/></Box>;
  return (
    <Stack spacing={2}>
      <Box>
        <Typography variant="subtitle2" gutterBottom>{t('asset_supplies.compatible')}</Typography>
        {datos.compatibles.length === 0
          ? <Typography variant="body2" color="text.secondary">{t('asset_supplies.no_compatible')}</Typography>
          : (<Stack direction="row" spacing={1} useFlexGap flexWrap="wrap">
              {datos.compatibles.map((c) => (
                <Chip key={c.CON_Consumible} variant="outlined" color={c.CON_Stock_Actual > 0 ? 'success' : 'warning'}
                  label={`${c.CON_Nombre} · ${t('asset_supplies.stock', { count: c.CON_Stock_Actual, unit: c.CON_Unidad })}`}/>
              ))}
            </Stack>)}
      </Box>
      <Box>
        <Typography variant="subtitle2" gutterBottom>{t('asset_supplies.history')}</Typography>
        {datos.historial.length === 0 ? <EmptyState compact title={t('asset_supplies.no_history')}/> : (
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>{t('asset_supplies.date')}</TableCell>
                <TableCell>{t('asset_supplies.item')}</TableCell>
                <TableCell align="right">{t('consumables.quantity')}</TableCell>
                <TableCell>{t('consumables.reason')}</TableCell>
                <TableCell>{t('asset_supplies.by')}</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {datos.historial.map((h) => (
                <TableRow key={h.MOC_Movimiento}>
                  <TableCell>{formatDateTimeValue(h.MOC_Fecha, i18n.language)}</TableCell>
                  <TableCell>{h.consumible}</TableCell>
                  <TableCell align="right">{`${h.MOC_Cantidad} ${h.unidad}`}</TableCell>
                  <TableCell>{h.MOC_Motivo ?? '—'}</TableCell>
                  <TableCell>{h.usuario ?? '—'}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </Box>
    </Stack>
  );
};
