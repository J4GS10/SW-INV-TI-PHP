import { useCallback, useEffect, useState } from 'react';
import { Box, TableCell, TableRow, Button, IconButton, Chip } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import EditIcon from '@mui/icons-material/Edit';
import DeleteIcon from '@mui/icons-material/Delete';
import { useTranslation } from 'react-i18next';
import { emptyStateProps } from '../../utils/emptyState';
import { useSnackbar } from 'notistack';
import { PageHeader, DataTableShell } from '../../components/ui';
import { SearchInput } from '../../components/SearchInput';
import { procurementService } from './procurementService';
import type { Proveedor } from '../../types/procurement';
import { ProviderDialog } from './ProviderDialog';
import { downloadCsv } from '../../api/downloadCsv';
import { getApiErrorDetail } from '../../api/errors';
import { ExportMenuButton } from '../../components/ExportMenuButton';
import { usePermissions } from '../../hooks/usePermissions';
import { CollapsibleToolbar } from '../../components/CollapsibleToolbar';
import { useConfirm } from '../../components/confirm-context';
import { useLiveRefresh } from '../../hooks/useLiveRefresh';
export const ProvidersPage = () => {
    const { t } = useTranslation();
    // Compras crea y edita proveedores; borrarlos es de TI.
    const { canAdminInventory, canPurchase, canExportPurchases: canExport } = usePermissions();
    const confirm = useConfirm();
    const { enqueueSnackbar } = useSnackbar();
    const [items, setItems] = useState<Proveedor[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [dialogOpen, setDialogOpen] = useState(false);
    const [toEdit, setToEdit] = useState<Proveedor | null>(null);
    const load = useCallback(async () => {
        setLoading(true);
        try {
            setItems(await procurementService.listProveedores());
        }
        catch {
            enqueueSnackbar(t('common.error_loading'), { variant: 'error' });
        }
        finally {
            setLoading(false);
        }
    }, [enqueueSnackbar, t]);
    useEffect(() => { load(); }, [load]);
    useLiveRefresh(load);
    const handleDelete = async (p: Proveedor) => {
        if (!(await confirm({ message: t('common.confirm_delete'), destructive: true, confirmText: t('common.delete') })))
            return;
        try {
            await procurementService.deleteProveedor(p.PRV_Proveedor);
            load();
        }
        catch (err: unknown) {
            const code = getApiErrorDetail(err);
            enqueueSnackbar(code === 'CANNOT_DELETE_SUPPLIER_HAS_RECEIPTS'
                ? t('procurement.err_provider_has_receipts')
                : t('common.error_generic'), { variant: 'error' });
        }
    };
    const filtered = items.filter((p) => p.PRV_Nombre.toLowerCase().includes(search.toLowerCase()) ||
        (p.PRV_Contacto ?? '').toLowerCase().includes(search.toLowerCase()));
    return (<Box>
      <PageHeader
        title={t('procurement.providers_title')}
        badge={filtered.length}
        actions={<>
          {canExport && <ExportMenuButton onExport={(lang) => downloadCsv('/export/proveedores.csv', 'proveedores.csv', lang)}/>}
          {canPurchase && (<Button variant="contained" startIcon={<AddIcon />} onClick={() => { setToEdit(null); setDialogOpen(true); }}>
            {t('procurement.new_provider')}
          </Button>)}
        </>}
      />

      <CollapsibleToolbar storageKey="providers" search={<SearchInput value={search} onChange={setSearch}/>}/>

      <ProviderDialog open={dialogOpen} toEdit={toEdit} onClose={(r) => { setDialogOpen(false); setToEdit(null); if (r)
        load(); }}/>

      <DataTableShell
        columns={[
          { key: 'name', label: t('procurement.provider_name'), sortValue: (p) => p.PRV_Nombre },
          { key: 'tax_id', label: t('procurement.tax_id'), sortValue: (p) => p.PRV_Identificacion_Fiscal },
          { key: 'contact', label: t('procurement.contact'), sortValue: (p) => p.PRV_Contacto },
          { key: 'email', label: t('procurement.email'), sortValue: (p) => p.PRV_Email },
          { key: 'phone', label: t('procurement.phone'), sortValue: (p) => p.PRV_Telefono },
          { key: 'actions', label: t('common.actions'), align: 'right' },
        ]}
        rows={filtered}
        loading={loading}
        {...emptyStateProps(t, 'providers', { buscando: !!search.trim(), onLimpiar: () => setSearch(''), onCrear: canPurchase ? () => { setToEdit(null); setDialogOpen(true); } : undefined })}
        renderRow={(p) => (<TableRow key={p.PRV_Proveedor} sx={{ '&:hover': { bgcolor: 'action.hover' } }}>
                  <TableCell sx={{ color: 'text.primary', fontWeight: 'bold' }}>
                    {p.PRV_Nombre}
                    {!p.PRV_Activo && <Chip size="small" label={t('common.inactive')} sx={{ ml: 1 }}/>}
                  </TableCell>
                  <TableCell sx={{ color: 'text.secondary' }}>{p.PRV_Identificacion_Fiscal ?? '—'}</TableCell>
                  <TableCell sx={{ color: 'text.secondary' }}>{p.PRV_Contacto ?? '—'}</TableCell>
                  <TableCell sx={{ color: 'text.secondary' }}>{p.PRV_Email ?? '—'}</TableCell>
                  <TableCell sx={{ color: 'text.secondary' }}>{p.PRV_Telefono ?? '—'}</TableCell>
                  <TableCell align="right">
                    {canPurchase && (<IconButton size="small" aria-label={`${t('common.edit')}: ${p.PRV_Nombre}`} sx={{ color: 'primary.main' }} onClick={() => { setToEdit(p); setDialogOpen(true); }}>
                      <EditIcon fontSize="small"/>
                    </IconButton>)}
                    {canAdminInventory && (<IconButton size="small" aria-label={`${t('common.delete')}: ${p.PRV_Nombre}`} sx={{ color: 'error.main' }} onClick={() => handleDelete(p)}>
                      <DeleteIcon fontSize="small"/>
                    </IconButton>)}
                  </TableCell>
                </TableRow>)}
      />
    </Box>);
};
