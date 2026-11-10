import { useCallback, useEffect, useState } from 'react';
import { Box, Button, Chip, CircularProgress, DialogActions, DialogContent, DialogTitle, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, Typography } from '@mui/material';
import { alpha } from '@mui/material/styles';
import ArrowDownwardIcon from '@mui/icons-material/ArrowDownward';
import ArrowUpwardIcon from '@mui/icons-material/ArrowUpward';
import SwapVertIcon from '@mui/icons-material/SwapVert';
import { useTranslation } from 'react-i18next';
import { EmptyState } from '../../components/ui';
import { SectionCard } from '../../components/ui';
import { useSnackbar } from 'notistack';
import { consumableService } from './consumableService';
import { Consumible, MovimientoConsumible } from '../../types/consumables';
import { Dialog } from '../../components/ui/Dialog';
import { useLiveRefresh } from '../../hooks/useLiveRefresh';
interface Props {
    open: boolean;
    consumible: Consumible | null;
    onClose: () => void;
}
const formatDate = (value: string) => {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
};
const movementColor = (type: MovimientoConsumible['MOC_Tipo']): 'success' | 'warning' | 'default' => {
    if (type === 'ENTRADA')
        return 'success';
    if (type === 'SALIDA')
        return 'warning';
    return 'default';
};
const movementIcon = (type: MovimientoConsumible['MOC_Tipo']) => {
    if (type === 'ENTRADA')
        return <ArrowUpwardIcon />;
    if (type === 'SALIDA')
        return <ArrowDownwardIcon />;
    return <SwapVertIcon />;
};
const quantityDisplay = (movement: MovimientoConsumible) => {
    const prefix = movement.MOC_Tipo === 'SALIDA' ? '-' : movement.MOC_Tipo === 'ENTRADA' ? '+' : '';
    return `${prefix}${movement.MOC_Cantidad}`;
};
export const ConsumableHistoryDialog = ({ open, consumible, onClose }: Props) => {
    const { t } = useTranslation();
    const { enqueueSnackbar } = useSnackbar();
    const [items, setItems] = useState<MovimientoConsumible[]>([]);
    const [loading, setLoading] = useState(false);
    const load = useCallback(async () => {
        if (!open || !consumible)
            return;
        setLoading(true);
        try {
            setItems(await consumableService.movimientos(consumible.CON_Consumible));
        }
        catch {
            enqueueSnackbar(t('common.error_loading'), { variant: 'error' });
        }
        finally {
            setLoading(false);
        }
    }, [consumible, enqueueSnackbar, open, t]);
    useEffect(() => { load(); }, [load]);
    useLiveRefresh(load);
    const entriesTotal = items
        .filter((item) => item.MOC_Tipo === 'ENTRADA')
        .reduce((total, item) => total + item.MOC_Cantidad, 0);
    const outputsTotal = items
        .filter((item) => item.MOC_Tipo === 'SALIDA')
        .reduce((total, item) => total + item.MOC_Cantidad, 0);
    const latestMovement = [...items].sort((a, b) => {
        const byDate = new Date(b.MOC_Fecha).getTime() - new Date(a.MOC_Fecha).getTime();
        return byDate || b.MOC_Movimiento - a.MOC_Movimiento;
    })[0];
    const summary = [
        { label: t('consumables.movements_count'), value: items.length },
        { label: t('consumables.entries_total'), value: `+${entriesTotal}` },
        { label: t('consumables.outputs_total'), value: `-${outputsTotal}` },
        {
            label: t('consumables.current_stock_short'),
            value: latestMovement?.MOC_Stock_Resultante ?? consumible?.CON_Stock_Actual ?? '-',
        },
    ];
    return (<Dialog open={open} onClose={onClose} maxWidth="md" fullWidth>
      <DialogTitle sx={{ pb: 1.5 }}>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} alignItems={{ xs: 'flex-start', sm: 'center' }} justifyContent="space-between">
          <Box sx={{ minWidth: 0 }}>
            <Typography variant="h6">
              {t('consumables.history')}
            </Typography>
            {consumible && (<Typography variant="body2" color="text.secondary" sx={{ overflowWrap: 'anywhere' }}>
                {consumible.CON_Nombre}
              </Typography>)}
          </Box>
          {consumible && (<Chip variant="outlined" label={`${t('consumables.current_stock_short')}: ${consumible.CON_Stock_Actual} ${consumible.CON_Unidad}`}/>)}
        </Stack>
      </DialogTitle>
      <DialogContent dividers sx={{ p: { xs: 1.5, sm: 2 } }}>
        {loading ? (<Box display="flex" justifyContent="center" py={4}><CircularProgress /></Box>) : items.length === 0 ? (<EmptyState compact title={t('consumables.no_movements')}/>) : (<Stack spacing={2}>
            <SectionCard noPadding title={t('consumables.sections.summary')}>
            <Box sx={(theme) => ({
                display: 'grid',
                gridTemplateColumns: { xs: '1fr 1fr', md: 'repeat(4, minmax(0, 1fr))' },
                overflow: 'hidden',
                bgcolor: alpha(theme.palette.background.default, theme.palette.mode === 'light' ? 0.58 : 0.32),
            })}>
              {summary.map((item, index) => (<Box key={item.label} sx={{
                    p: 1.25,
                    minWidth: 0,
                    borderRight: { md: index < summary.length - 1 ? '1px solid' : 0 },
                    borderBottom: { xs: index < 2 ? '1px solid' : 0, md: 0 },
                    borderColor: 'divider',
                }}>
                  <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
                    {item.label}
                  </Typography>
                  <Typography variant="subtitle1" sx={{ fontWeight: 700, lineHeight: 1.3 }}>
                    {item.value}
                  </Typography>
                </Box>))}
            </Box>
            </SectionCard>

            <SectionCard noPadding title={t('consumables.sections.movements')}>
            <TableContainer component={Box} sx={{
                overflow: 'hidden',
            }}>
              <Table size="small" sx={{
                width: '100%',
                tableLayout: 'fixed',
                '& th, & td': {
                    verticalAlign: 'top',
                },
            }} aria-label={t('consumables.history')}>
                <TableHead>
                  <TableRow>
                    <TableCell sx={{ width: '19%' }}>{t('consumables.date')}</TableCell>
                    <TableCell sx={{ width: '14%' }}>{t('consumables.movement')}</TableCell>
                    <TableCell sx={{ width: '10%' }} align="right">{t('consumables.quantity')}</TableCell>
                    <TableCell sx={{ width: '12%' }} align="right">{t('consumables.resulting')}</TableCell>
                    <TableCell sx={{ width: '20%' }}>{t('consumables.responsible')}</TableCell>
                    <TableCell sx={{ width: '25%' }}>{t('consumables.reason')}</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {items.map((movement) => (<TableRow key={movement.MOC_Movimiento} hover>
                      <TableCell sx={{ fontFamily: '"Roboto Mono", "Consolas", monospace', fontSize: '0.8rem', overflowWrap: 'anywhere' }}>
                        {formatDate(movement.MOC_Fecha)}
                      </TableCell>
                      <TableCell>
                        <Chip size="small" icon={movementIcon(movement.MOC_Tipo)} color={movementColor(movement.MOC_Tipo)} variant="outlined" label={t(`consumables.movement_${movement.MOC_Tipo}`, movement.MOC_Tipo)}/>
                      </TableCell>
                      <TableCell align="right" sx={{ fontFamily: '"Roboto Mono", "Consolas", monospace', fontWeight: 700 }}>
                        {quantityDisplay(movement)}
                      </TableCell>
                      <TableCell align="right" sx={{ fontFamily: '"Roboto Mono", "Consolas", monospace' }}>
                        {movement.MOC_Stock_Resultante}
                      </TableCell>
                      <TableCell sx={{ minWidth: 0 }}>
                        <Stack spacing={0.25}>
                          <Typography variant="body2" sx={{ overflowWrap: 'anywhere' }}>
                            {movement.PER_Persona || t('consumables.no_responsible')}
                          </Typography>
                          {movement.USU_Usuario && (<Typography variant="caption" color="text.secondary" sx={{ overflowWrap: 'anywhere' }}>
                              {movement.USU_Usuario}
                            </Typography>)}
                        </Stack>
                      </TableCell>
                      <TableCell>
                        <Typography variant="body2" sx={{ overflowWrap: 'anywhere' }}>
                          {movement.MOC_Motivo || t('consumables.no_reason')}
                        </Typography>
                      </TableCell>
                    </TableRow>))}
                </TableBody>
              </Table>
            </TableContainer>
            </SectionCard>
          </Stack>)}
      </DialogContent>
      <DialogActions sx={{ px: 2, py: 1.5 }}>
        <Button onClick={onClose}>{t('common.close')}</Button>
      </DialogActions>
    </Dialog>);
};
