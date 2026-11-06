import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Box, TableCell, TableRow, Button, Chip, MenuItem, TextField } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import VisibilityIcon from '@mui/icons-material/Visibility';
import { useTranslation } from 'react-i18next';
import { emptyStateProps } from '../../utils/emptyState';
import { useSnackbar } from 'notistack';
import { procurementService } from './procurementService';
import type { OrdenCompra } from '../../types/procurement';
import { PurchaseOrderDialog } from './PurchaseOrderDialog';
import { OrderDetailDialog } from './OrderDetailDialog';
import { downloadCsv } from '../../api/downloadCsv';
import { ExportMenuButton } from '../../components/ExportMenuButton';
import { usePermissions } from '../../hooks/usePermissions';
import { CollapsibleToolbar } from '../../components/CollapsibleToolbar';
import { PageHeader, DataTableShell } from '../../components/ui';
import { formatMoney } from '../../utils/currency';
import { formatDate } from '../../utils/dates';
import { ESTADOS_ORDEN } from '../../types/procurement';
import { estadoOrdenColor, estadoOrdenLabel, ordenRecibible } from './orderStates';
export const PurchaseOrdersPage = () => {
    const { t, i18n } = useTranslation();
    const { canPurchase, canExportPurchases: canExport } = usePermissions();
    const { enqueueSnackbar } = useSnackbar();
    const [items, setItems] = useState<OrdenCompra[]>([]);
    const [loading, setLoading] = useState(true);
    const [estado, setEstado] = useState('');
    const [dialogOpen, setDialogOpen] = useState(false);
    const [detalleId, setDetalleId] = useState<number | null>(null);
    const load = useCallback(async () => {
        setLoading(true);
        try {
            setItems(await procurementService.listOrdenes(estado || undefined));
        }
        catch {
            enqueueSnackbar(t('common.error_loading'), { variant: 'error' });
        }
        finally {
            setLoading(false);
        }
    }, [enqueueSnackbar, estado, t]);
    useEffect(() => { load(); }, [load]);
    const estadoLabels: Record<string, string> = Object.fromEntries(ESTADOS_ORDEN.map((e) => [e, estadoOrdenLabel(t, e)]));
    // ?orden=<id>: abre el detalle (enlaces desde «Requiere su atención»).
    const [searchParams, setSearchParams] = useSearchParams();
    useEffect(() => {
        const id = Number(searchParams.get('orden'));
        if (id) {
            setDetalleId(id);
            setSearchParams({}, { replace: true });
        }
    }, [searchParams, setSearchParams]);
    return (<Box>
      <PageHeader
        title={t('procurement.orders_title')}
        badge={items.length}
        actions={<>
          {canExport && <ExportMenuButton onExport={(lang) => downloadCsv('/export/ordenes.csv', 'ordenes.csv', lang)}/>}
          {canPurchase && (<Button variant="contained" startIcon={<AddIcon />} onClick={() => setDialogOpen(true)}>
            {t('procurement.new_order')}
          </Button>)}
        </>}
      />

      <CollapsibleToolbar
        storageKey="purchase-orders"
        summary={estado && <Chip size="small" color="primary" variant="outlined" label={estadoLabels[estado] ?? estado}/>}
      >
        <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1.5, alignItems: 'center' }}>
          <TextField select size="small" label={t('procurement.status')} value={estado} onChange={(e) => setEstado(e.target.value)} sx={{ minWidth: { xs: '100%', sm: 180 } }}>
            <MenuItem value="">{t('procurement.all')}</MenuItem>
            {Object.entries(estadoLabels).map(([value, label]) => <MenuItem key={value} value={value}>{label}</MenuItem>)}
          </TextField>
        </Box>
      </CollapsibleToolbar>

      <PurchaseOrderDialog open={dialogOpen} recentOrders={items} onClose={(r) => { setDialogOpen(false); if (r)
        load(); }}/>
      <OrderDetailDialog open={detalleId != null} ordenId={detalleId} onClose={(r) => { setDetalleId(null); if (r)
        load(); }}/>

      <DataTableShell
        columns={[
          { key: 'number', label: t('procurement.order_number'), sortValue: (o) => o.OCO_Numero },
          { key: 'provider', label: t('procurement.provider'), sortValue: (o) => o.proveedor?.PRV_Nombre },
          { key: 'date', label: t('procurement.date'), sortValue: (o) => o.OCO_Fecha },
          { key: 'total', label: t('procurement.total'), sortValue: (o) => o.OCO_Total },
          { key: 'status', label: t('procurement.status'), sortValue: (o) => estadoOrdenLabel(t, o.OCO_Estado) },
          { key: 'actions', label: t('common.actions'), align: 'right' },
        ]}
        rows={items}
        loading={loading}
        {...emptyStateProps(t, 'orders', { buscando: !!estado, onLimpiar: () => setEstado(''), onCrear: canPurchase ? () => setDialogOpen(true) : undefined })}
        renderRow={(o) => (<TableRow key={o.OCO_Orden} hover sx={{ cursor: 'pointer', '&:hover': { bgcolor: 'action.hover' } }} onClick={() => setDetalleId(o.OCO_Orden)}>
                  <TableCell sx={{ color: 'text.primary', fontWeight: 'bold' }}>{o.OCO_Numero}</TableCell>
                  <TableCell sx={{ color: 'text.primary' }}>{o.proveedor?.PRV_Nombre ?? '—'}</TableCell>
                  <TableCell sx={{ color: 'text.secondary' }}>{formatDate(o.OCO_Fecha, i18n.language)}</TableCell>
                  <TableCell sx={{ color: 'text.primary' }}>{formatMoney(o.OCO_Total, o.OCO_Moneda, i18n.language)}</TableCell>
                  <TableCell><Chip size="small" label={estadoOrdenLabel(t, o.OCO_Estado)} color={estadoOrdenColor(o.OCO_Estado)} variant="outlined"/></TableCell>
                  <TableCell align="right">
                    <Button size="small" startIcon={<VisibilityIcon fontSize="small"/>} onClick={(e) => { e.stopPropagation(); setDetalleId(o.OCO_Orden); }}>
                      {ordenRecibible(o.OCO_Estado)
                    ? t('procurement.view_receive')
                    : t('common.view')}
                    </Button>
                  </TableCell>
                </TableRow>)}
      />
    </Box>);
};
