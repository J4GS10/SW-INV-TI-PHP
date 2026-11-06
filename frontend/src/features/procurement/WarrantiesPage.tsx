import { useCallback, useEffect, useState } from 'react';
import { Box, TableCell, TableRow, Chip, MenuItem, TextField, FormControlLabel, Switch, Button, } from '@mui/material';
import NotificationsActiveIcon from '@mui/icons-material/NotificationsActive';
import { useTranslation } from 'react-i18next';
import { formatDate } from '../../utils/dates';
import { ExportMenuButton } from '../../components/ExportMenuButton';
import { downloadCsv } from '../../api/downloadCsv';
import type { TFunction } from 'i18next';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../api/errors';
import { procurementService } from './procurementService';
import type { Garantia } from '../../types/procurement';
import { CollapsibleToolbar } from '../../components/CollapsibleToolbar';
import { PageHeader, DataTableShell, StatusChip } from '../../components/ui';
import type { StatusVariant } from '../../components/ui';
import { usePermissions } from '../../hooks/usePermissions';
const estadoChip = (estado: string, t: TFunction) => {
    const map: Record<string, { variant: StatusVariant; label: string }> = {
        vencida:     { variant: 'vencido',    label: t('procurement.w_expired') },
        por_vencer:  { variant: 'por_vencer', label: t('procurement.w_expiring') },
        vigente:     { variant: 'vigente',    label: t('procurement.w_valid') },
        sin_garantia:{ variant: 'default',    label: t('procurement.w_none') },
    };
    const c = map[estado] ?? map.sin_garantia;
    return <StatusChip label={c.label} variant={c.variant} />;
};
export const WarrantiesPage = () => {
    const { t, i18n } = useTranslation();
    const { canAdminInventory , canExport } = usePermissions();
    const { enqueueSnackbar } = useSnackbar();
    const [items, setItems] = useState<Garantia[]>([]);
    const [loading, setLoading] = useState(true);
    const [dias, setDias] = useState(90);
    const [soloAlertas, setSoloAlertas] = useState(false);
    const load = useCallback(async () => {
        setLoading(true);
        try {
            setItems(await procurementService.garantias(dias, soloAlertas));
        }
        catch {
            enqueueSnackbar(t('common.error_loading'), { variant: 'error' });
        }
        finally {
            setLoading(false);
        }
    }, [dias, enqueueSnackbar, soloAlertas, t]);
    useEffect(() => { load(); }, [load]);
    const [notifying, setNotifying] = useState(false);
    const notificar = async () => {
        setNotifying(true);
        try {
            const r = await procurementService.notificarGarantias(dias);
            enqueueSnackbar(r.total === 0
                ? t('procurement.notify_none')
                : `${t('procurement.notify_sent')}: ${r.total}`, { variant: r.total === 0 ? 'info' : 'success' });
        }
        catch (err: unknown) {
            enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
        }
        finally {
            setNotifying(false);
        }
    };
    return (<Box>
      <PageHeader
        title={t('procurement.warranties_title')}
        badge={items.length}
        actions={<>
          {canExport && <ExportMenuButton onExport={(lang) => downloadCsv('/export/garantias.csv', 'garantias.csv', lang)}/>}
          {canAdminInventory && (
          <Button variant="outlined" color="warning" startIcon={<NotificationsActiveIcon />} onClick={notificar} disabled={notifying}>
            {t('procurement.notify_admins')}
          </Button>
          )}
        </>}
      />

      <CollapsibleToolbar
        storageKey="warranties"
        summary={soloAlertas && <Chip size="small" color="warning" variant="outlined" label={t('procurement.only_alerts')}/>}
      >
        <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1.5, alignItems: 'center' }}>
          <TextField select size="small" label={t('procurement.window_days')} value={dias} onChange={(e) => setDias(Number(e.target.value))} sx={{ minWidth: { xs: '100%', sm: 180 } }}>
            {[30, 60, 90, 180, 365].map((d) => <MenuItem key={d} value={d}>{d}</MenuItem>)}
          </TextField>
          <FormControlLabel control={<Switch checked={soloAlertas} onChange={(e) => setSoloAlertas(e.target.checked)} color="warning"/>} label={t('procurement.only_alerts')} sx={{ color: 'text.secondary' }}/>
        </Box>
      </CollapsibleToolbar>

      <DataTableShell
        columns={[
          { key: 'asset', label: t('procurement.asset'), sortValue: (g) => g.ACT_Codigo_Interno },
          { key: 'serial', label: t('assets.table.serial'), sortValue: (g) => g.ACT_Serie_Fabricante },
          { key: 'provider', label: t('procurement.provider'), sortValue: (g) => g.proveedor },
          { key: 'warranty_end', label: t('procurement.warranty_end'), sortValue: (g) => g.ACT_Fin_Garantia },
          { key: 'days_left', label: t('procurement.days_left'), sortValue: (g) => g.dias_restantes },
          { key: 'status', label: t('procurement.status'), sortValue: (g) => g.estado_garantia },
        ]}
        rows={items}
        loading={loading}
        emptyTitle={t('common.no_records')}
        renderRow={(g) => (<TableRow key={g.ACT_Activo} sx={{ '&:hover': { bgcolor: 'action.hover' } }}>
                  <TableCell sx={{ color: 'text.primary', fontWeight: 'bold' }}>{g.ACT_Codigo_Interno}</TableCell>
                  <TableCell sx={{ color: 'text.secondary', fontFamily: 'monospace' }}>{g.ACT_Serie_Fabricante}</TableCell>
                  <TableCell sx={{ color: 'text.secondary' }}>{g.proveedor ?? '—'}</TableCell>
                  <TableCell sx={{ color: 'text.secondary' }}>{formatDate(g.ACT_Fin_Garantia, i18n.language)}</TableCell>
                  <TableCell sx={{ color: 'text.secondary' }}>
                    {g.dias_restantes != null ? g.dias_restantes : '—'}
                  </TableCell>
                  <TableCell>{estadoChip(g.estado_garantia, t)}</TableCell>
                </TableRow>)}
      />
    </Box>);
};
