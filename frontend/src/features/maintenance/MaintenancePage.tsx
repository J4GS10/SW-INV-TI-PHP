import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Box, TableCell, TableRow, Button, Chip, IconButton, Tooltip, FormControl, InputLabel, MenuItem, Select } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import { PageHeader, DataTableShell } from '../../components/ui';
import VisibilityIcon from '@mui/icons-material/Visibility';
import DeleteIcon from '@mui/icons-material/DeleteOutline';
import { useTranslation } from 'react-i18next';
import { formatDate } from '../../utils/dates';
import { emptyStateProps } from '../../utils/emptyState';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../api/errors';
import { maintenanceService } from './maintenanceService';
import { Mantenimiento } from '../../types/maintenance';
import { MaintenanceDialog } from './MaintenanceDialog';
import { MaintenanceDetailDialog } from './MaintenanceDetailDialog';
import { useConfirm } from '../../components/confirm-context';
import { ExportMenuButton } from '../../components/ExportMenuButton';
import { downloadCsv } from '../../api/downloadCsv';
import { usePermissions } from '../../hooks/usePermissions';
import { formatMoney } from '../../utils/currency';
import { useLiveRefresh } from '../../hooks/useLiveRefresh';
import { useServerPaging } from '../../hooks/useServerPaging';
import { useDebouncedValue } from '../../hooks/useDebouncedValue';
import { CollapsibleToolbar } from '../../components/CollapsibleToolbar';
import { SearchInput } from '../../components/SearchInput';
export const MaintenancePage = () => {
    const { t, i18n } = useTranslation();
    const { canOperate, canAdminInventory, canExport } = usePermissions();
    const confirm = useConfirm();
    const { enqueueSnackbar } = useSnackbar();
    const [tickets, setTickets] = useState<Mantenimiento[]>([]);
    const [total, setTotal] = useState(0);
    const [loading, setLoading] = useState(true);
    const [texto, setTexto] = useState('');
    const [estado, setEstado] = useState<'' | 'abierto' | 'cerrado'>('');
    const busqueda = useDebouncedValue(texto.trim());
    const { pedido, tabla } = useServerPaging(`${busqueda}|${estado}`);
    const [searchParams, setSearchParams] = useSearchParams();
    // ?nuevo=<activo>: llega desde la ficha del activo o una devolución con daño.
    const activoNuevo = searchParams.get('nuevo');
    const [createOpen, setCreateOpen] = useState(!!activoNuevo);
    const [detailOpen, setDetailOpen] = useState(false);
    const [selected, setSelected] = useState<Mantenimiento | null>(null);
    const loadData = useCallback(() => {
        setLoading(true);
        maintenanceService.list({ ...pedido, q: busqueda || undefined, estado: estado || undefined })
            .then((pagina) => { setTickets(pagina.items); setTotal(pagina.total); })
            .catch(() => enqueueSnackbar(t('common.error_loading'), { variant: 'error' }))
            .finally(() => setLoading(false));
    }, [pedido, busqueda, estado, enqueueSnackbar, t]);
    useLiveRefresh(loadData);
    useEffect(() => { void Promise.resolve().then(loadData); }, [loadData]);
    const handleView = (row: Mantenimiento) => { setSelected(row); setDetailOpen(true); };
    const handleDelete = async (row: Mantenimiento) => {
        if (!row.MAN_Fecha_Cierre) {
            enqueueSnackbar(t('maintenance.cant_delete_open'), {
                variant: 'warning',
            });
            return;
        }
        if (!(await confirm({ message: t('common.confirm_delete'), destructive: true, confirmText: t('common.delete') })))
            return;
        try {
            await maintenanceService.remove(row.MAN_Mantenimiento);
            enqueueSnackbar(t('common.success_update'), { variant: 'success' });
            loadData();
        }
        catch (err: unknown) {
            enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
        }
    };
    return (<Box>
      <PageHeader help="maintenance"
        title={t('maintenance.title')}
        badge={total}
        actions={canExport || canOperate ? <>{canExport && <ExportMenuButton onExport={(lang) => downloadCsv('/export/mantenimientos.csv', 'mantenimientos.csv', lang)}/>}{canOperate && <Button variant="contained" startIcon={<AddIcon />} onClick={() => setCreateOpen(true)}>{t('maintenance.new_ticket')}</Button>}</> : undefined}
      />

      <MaintenanceDialog open={createOpen} activoInicialId={activoNuevo} onClose={(refresh) => { setCreateOpen(false); if (activoNuevo) setSearchParams({}); if (refresh)
        loadData(); }}/>
      <MaintenanceDetailDialog open={detailOpen} ticket={selected} onClose={() => { setDetailOpen(false); setSelected(null); }} onChanged={loadData}/>

      <CollapsibleToolbar storageKey="maintenance-toolbar" search={<SearchInput value={texto} onChange={setTexto}/>}>
        <FormControl size="small" sx={{ minWidth: { xs: '100%', sm: 180 } }}>
          <InputLabel id="man-filtro-estado">{t('operations.table.status')}</InputLabel>
          <Select labelId="man-filtro-estado" label={t('operations.table.status')} value={estado} onChange={(e) => setEstado(e.target.value as '' | 'abierto' | 'cerrado')}>
            <MenuItem value="">{t('common.all')}</MenuItem>
            <MenuItem value="abierto">{t('maintenance.open')}</MenuItem>
            <MenuItem value="cerrado">{t('common.closed')}</MenuItem>
          </Select>
        </FormControl>
      </CollapsibleToolbar>

      <DataTableShell
        columns={[
          { key: 'date', label: t('maintenance.col_date'), serverSortable: true },
          { key: 'asset', label: t('maintenance.col_asset'), serverSortable: true },
          { key: 'type', label: t('maintenance.col_type'), serverSortable: true },
          { key: 'failure', label: t('maintenance.col_failure') },
          { key: 'cost', label: t('maintenance.col_cost'), serverSortable: true },
          { key: 'status', label: t('operations.table.status'), serverSortable: true },
          { key: 'actions', label: t('common.actions'), align: 'right' },
        ]}
        rows={tickets}
        loading={loading}
        total={total}
        {...tabla}
        {...emptyStateProps(t, 'maintenance', { buscando: !!busqueda || !!estado, onCrear: canOperate ? () => setCreateOpen(true) : undefined })}
        renderRow={(row) => {
            const cerrado = !!row.MAN_Fecha_Cierre;
            return (<TableRow key={row.MAN_Mantenimiento} hover>
                <TableCell>{formatDate(row.MAN_Fecha_Ingreso, i18n.language)}</TableCell>
                <TableCell sx={{ fontWeight: 'bold' }}>{row.activo?.ACT_Codigo_Interno ?? '—'}</TableCell>
                <TableCell>{row.tipo_mantenimiento?.TMA_Nombre ?? '—'}</TableCell>
                <TableCell sx={{ maxWidth: 280, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                  {row.MAN_Descripcion_Falla}
                </TableCell>
                <TableCell sx={{ whiteSpace: 'nowrap' }}>{formatMoney(row.MAN_Costo_Total, row.MAN_Moneda, i18n.language)}</TableCell>
                <TableCell>
                  <Chip label={cerrado ? t('common.closed') : t('maintenance.open')} color={cerrado ? 'default' : 'warning'} size="small"/>
                </TableCell>
                <TableCell align="right">
                  <Tooltip title={t('common.view') as string}>
                    <IconButton size="small" onClick={() => handleView(row)}>
                      <VisibilityIcon fontSize="small"/>
                    </IconButton>
                  </Tooltip>
                  {cerrado && canAdminInventory && (<Tooltip title={t('common.delete') as string}>
                      <IconButton size="small" color="error" onClick={() => handleDelete(row)}>
                        <DeleteIcon fontSize="small"/>
                      </IconButton>
                    </Tooltip>)}
                </TableCell>
              </TableRow>);
        }}
      />
    </Box>);
};
