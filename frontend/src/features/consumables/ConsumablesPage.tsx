import { useCallback, useEffect, useState } from 'react';
import { Box, TableCell, TableRow, Button, IconButton, Chip, Tooltip, FormControlLabel, Switch, } from '@mui/material';
import { PageHeader, DataTableShell } from '../../components/ui';
import AddIcon from '@mui/icons-material/Add';
import EditIcon from '@mui/icons-material/Edit';
import DeleteIcon from '@mui/icons-material/Delete';
import ArrowDownwardIcon from '@mui/icons-material/ArrowDownward';
import ArrowUpwardIcon from '@mui/icons-material/ArrowUpward';
import WarningAmberIcon from '@mui/icons-material/WarningAmber';
import HistoryIcon from '@mui/icons-material/History';
import TuneIcon from '@mui/icons-material/Tune';
import { PurchaseOrderDialog } from '../procurement/PurchaseOrderDialog';
import type { LineaOrden } from '../../types/procurement';
import { formatMoney } from '../../utils/currency';
import { useTranslation } from 'react-i18next';
import { emptyStateProps } from '../../utils/emptyState';
import { useSnackbar } from 'notistack';
import { SearchInput } from '../../components/SearchInput';
import { consumableService } from './consumableService';
import { Consumible } from '../../types/consumables';
import { ConsumableDialog } from './ConsumableDialog';
import { StockDialog, type StockModo } from './StockDialog';
import { ConsumableHistoryDialog } from './ConsumableHistoryDialog';
import { downloadCsv } from '../../api/downloadCsv';
import { getApiErrorDetail } from '../../api/errors';
import { ExportMenuButton } from '../../components/ExportMenuButton';
import { usePermissions } from '../../hooks/usePermissions';
import { CollapsibleToolbar } from '../../components/CollapsibleToolbar';
import { useConfirm } from '../../components/confirm-context';
export const ConsumablesPage = () => {
    const { t, i18n } = useTranslation();
    const { canOperate, canAdminInventory, canExportFinance: canExport, canSeeCosts } = usePermissions();
    const confirm = useConfirm();
    const { enqueueSnackbar } = useSnackbar();
    const [items, setItems] = useState<Consumible[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [soloBajo, setSoloBajo] = useState(false);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [toEdit, setToEdit] = useState<Consumible | null>(null);
    const [stockModo, setStockModo] = useState<StockModo | null>(null);
    // Orden de reposición propuesta tras una salida que dejó el stock bajo el mínimo.
    const [reposicion, setReposicion] = useState<LineaOrden[] | null>(null);
    const [stockTarget, setStockTarget] = useState<Consumible | null>(null);
    const [histTarget, setHistTarget] = useState<Consumible | null>(null);
    const load = useCallback(async () => {
        setLoading(true);
        try {
            setItems(await consumableService.list(soloBajo));
        }
        catch {
            enqueueSnackbar(t('common.error_loading'), { variant: 'error' });
        }
        finally {
            setLoading(false);
        }
    }, [enqueueSnackbar, soloBajo, t]);
    useEffect(() => { load(); }, [load]);
    const handleDelete = async (c: Consumible) => {
        if (!(await confirm({ message: t('common.confirm_delete'), destructive: true, confirmText: t('common.delete') })))
            return;
        try {
            await consumableService.remove(c.CON_Consumible);
            load();
        }
        catch (err: unknown) {
            const code = getApiErrorDetail(err);
            enqueueSnackbar(code === 'CANNOT_DELETE_CONSUMABLE_HAS_MOVEMENTS'
                ? t('consumables.error_has_movements')
                : t('common.error_generic'), { variant: 'error' });
        }
    };
    const filtered = items.filter((c) => c.CON_Nombre.toLowerCase().includes(search.toLowerCase()) ||
        (c.CON_Categoria ?? '').toLowerCase().includes(search.toLowerCase()));
    return (<Box>
      <PageHeader
        title={t('consumables.title')}
        badge={filtered.length}
        actions={<>
          {canExport && <ExportMenuButton onExport={(lang) => downloadCsv(`/export/consumibles.csv?${new URLSearchParams(Object.entries({ q: search.trim(), solo_bajo: soloBajo ? 'true' : '' }).filter(([, v]) => v !== '')).toString()}`, 'consumibles.csv', lang)}/>}
          {canAdminInventory && (<Button variant="contained" startIcon={<AddIcon />} onClick={() => { setToEdit(null); setDialogOpen(true); }}>
            {t('consumables.new_btn')}
          </Button>)}
        </>}
      />

      <CollapsibleToolbar
        storageKey="consumables"
        search={<SearchInput value={search} onChange={setSearch}/>}
        summary={soloBajo && <Chip size="small" color="warning" variant="outlined" label={t('consumables.only_low')}/>}
      >
        <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1.5, alignItems: 'center' }}>
          <FormControlLabel control={<Switch checked={soloBajo} onChange={(e) => setSoloBajo(e.target.checked)} color="warning"/>} label={t('consumables.only_low')} sx={{ color: 'text.secondary' }}/>
        </Box>
      </CollapsibleToolbar>

      <ConsumableDialog open={dialogOpen} toEdit={toEdit} onClose={(r) => { setDialogOpen(false); setToEdit(null); if (r)
        load(); }}/>
      <StockDialog open={!!stockModo} modo={stockModo ?? 'entrada'} consumible={stockTarget} onClose={(r, reponer) => {
            setStockModo(null);
            setStockTarget(null);
            if (r)
                load();
            if (reponer)
                setReposicion([{
                    OCL_Tipo: 'CONSUMIBLE', CON_Consumible: reponer.consumible.CON_Consumible,
                    OCL_Descripcion: reponer.consumible.CON_Nombre, OCL_Cantidad: reponer.sugerido,
                    OCL_Precio_Unitario: String(reponer.consumible.CON_Costo_Unitario ?? '0'),
                }]);
        }}/>
      <PurchaseOrderDialog open={!!reposicion} lineasIniciales={reposicion ?? undefined} onClose={(r) => {
            setReposicion(null);
            if (r)
                enqueueSnackbar(t('consumables.order_created_hint'), { variant: 'info' });
        }}/>
      <ConsumableHistoryDialog open={!!histTarget} consumible={histTarget} onClose={() => setHistTarget(null)}/>

      <DataTableShell
        columns={[
          { key: 'name', label: t('consumables.field_name'), sortValue: (c: Consumible) => c.CON_Nombre },
          { key: 'category', label: t('consumables.field_category'), sortValue: (c: Consumible) => c.CON_Categoria },
          { key: 'stock', label: t('consumables.stock'), sortValue: (c: Consumible) => c.CON_Stock_Actual },
          { key: 'min', label: t('consumables.field_min'), sortValue: (c: Consumible) => c.CON_Stock_Minimo },
          ...(canSeeCosts ? [{ key: 'cost', label: t('consumables.avg_cost'), align: 'right' as const, sortValue: (c: Consumible) => c.CON_Costo_Unitario }] : []),
          { key: 'actions', label: t('common.actions'), align: 'right' },
        ]}
        rows={filtered}
        loading={loading}
        {...emptyStateProps(t, 'consumables', { buscando: !!search.trim(), onLimpiar: () => setSearch(''), onCrear: canAdminInventory ? () => { setToEdit(null); setDialogOpen(true); } : undefined })}
        renderRow={(c) => (<TableRow key={c.CON_Consumible} sx={{ '&:hover': { bgcolor: 'action.hover' } }}>
            <TableCell sx={{ color: 'text.primary', fontWeight: 'bold' }}>{c.CON_Nombre}</TableCell>
            <TableCell sx={{ color: 'text.secondary' }}>{c.CON_Categoria ?? '—'}</TableCell>
            <TableCell>
              <Chip icon={c.bajo_stock ? <WarningAmberIcon /> : undefined} label={`${c.CON_Stock_Actual} ${c.CON_Unidad}`} size="small" color={c.bajo_stock ? 'warning' : 'primary'} variant={c.bajo_stock ? 'filled' : 'outlined'}/>
            </TableCell>
            <TableCell sx={{ color: 'text.secondary' }}>{c.CON_Stock_Minimo}</TableCell>
            {canSeeCosts && <TableCell align="right" sx={{ color: 'text.secondary' }}>{c.CON_Costo_Unitario != null ? formatMoney(c.CON_Costo_Unitario, c.CON_Moneda ?? 'GTQ', i18n.language) : '—'}</TableCell>}
            <TableCell align="right">
              {canOperate && (<>
              <Tooltip title={t('consumables.entry_title') as string}>
                <IconButton size="small" sx={{ color: 'success.main' }} onClick={() => { setStockTarget(c); setStockModo('entrada'); }}>
                  <ArrowUpwardIcon fontSize="small"/>
                </IconButton>
              </Tooltip>
              <Tooltip title={t('consumables.exit_title') as string}>
                <IconButton size="small" sx={{ color: 'warning.main' }} onClick={() => { setStockTarget(c); setStockModo('salida'); }}>
                  <ArrowDownwardIcon fontSize="small"/>
                </IconButton>
              </Tooltip>
              </>)}
              <Tooltip title={t('consumables.history') as string}>
                <IconButton size="small" sx={{ color: 'text.secondary' }} onClick={() => setHistTarget(c)}>
                  <HistoryIcon fontSize="small"/>
                </IconButton>
              </Tooltip>
              {canAdminInventory && (<>
              <Tooltip title={t('consumables.adjust_title')}>
                <IconButton size="small" sx={{ color: 'info.main' }} onClick={() => { setStockTarget(c); setStockModo('ajuste'); }}>
                  <TuneIcon fontSize="small"/>
                </IconButton>
              </Tooltip>
              <Tooltip title={t('common.edit')}>
                <IconButton size="small" sx={{ color: 'primary.main' }} onClick={() => { setToEdit(c); setDialogOpen(true); }}>
                  <EditIcon fontSize="small"/>
                </IconButton>
              </Tooltip>
              <Tooltip title={t('common.delete')}>
                <IconButton size="small" sx={{ color: 'error.main' }} onClick={() => handleDelete(c)}>
                  <DeleteIcon fontSize="small"/>
                </IconButton>
              </Tooltip>
              </>)}
            </TableCell>
          </TableRow>)}
      />
    </Box>);
};
