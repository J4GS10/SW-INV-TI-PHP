import { useEffect, useState } from 'react';
import { Box, TableCell, TableRow, Button, Chip, IconButton, Tooltip, Typography } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import { PageHeader, DataTableShell } from '../../../components/ui';
import EditIcon from '@mui/icons-material/Edit';
import DeleteIcon from '@mui/icons-material/Delete';
import { useTranslation } from 'react-i18next';
import { emptyStateProps } from '../../../utils/emptyState';
import { useSnackbar } from 'notistack';
import { useConfirm } from '../../../components/confirm-context';
import { catalogService } from '../catalogService';
import { TipoActivo } from '../../../types/inventory';
import { AssetTypeDialog } from './AssetTypeDialog';
import { ReclasificarDialog } from './ReclasificarDialog';
import SwapVertIcon from '@mui/icons-material/SwapVert';
import { useLiveTick } from '../../../hooks/useLiveRefresh';
export const AssetTypesPage = () => {
    const { t } = useTranslation();
    const confirm = useConfirm();
    const { enqueueSnackbar } = useSnackbar();
    const [tipos, setTipos] = useState<TipoActivo[]>([]);
    const [loading, setLoading] = useState(true);
    const [isDialogOpen, setIsDialogOpen] = useState(false);
    const [typeToEdit, setTypeToEdit] = useState<TipoActivo | undefined>(undefined);
    const [reclasificar, setReclasificar] = useState<TipoActivo | null>(null);
    const loadData = async () => {
        try {
            const data = await catalogService.getTiposActivo();
            setTipos(data);
        }
        catch (error) {
            console.error("Error cargando tipos", error);
        }
        finally {
            setLoading(false);
        }
    };
    const liveTick = useLiveTick();
    useEffect(() => {
        loadData();
    }, [liveTick]);
    const handleEdit = (type: TipoActivo) => {
        setTypeToEdit(type);
        setIsDialogOpen(true);
    };
    const handleDelete = async (id: number) => {
        if (!(await confirm({ message: t('common.confirm_delete'), destructive: true, confirmText: t('common.delete') })))
            return;
        try {
            await catalogService.deleteTipoActivo(id);
            loadData();
        }
        catch (error) {
            console.error(error);
            enqueueSnackbar(t('common.error_delete_dependencies'), { variant: 'error' });
        }
    };
    const handleCloseDialog = (refresh?: boolean) => {
        setIsDialogOpen(false);
        setTypeToEdit(undefined);
        if (refresh)
            loadData();
    };
    return (<Box>
      <PageHeader
        title={t('catalogs.types.title')}
        badge={tipos.length}
        actions={<Button variant="contained" startIcon={<AddIcon />} onClick={() => { setTypeToEdit(undefined); setIsDialogOpen(true); }}>{t('catalogs.types.new_btn')}</Button>}
      />

      <AssetTypeDialog open={isDialogOpen} onClose={handleCloseDialog} typeToEdit={typeToEdit} tipos={tipos}/>
      <ReclasificarDialog tipo={reclasificar} onClose={() => setReclasificar(null)}/>

      <DataTableShell
        columns={[
          { key: 'name', label: t('catalogs.types.table_name') },
          { key: 'prefix', label: t('catalogs.types.table_prefix') },
          { key: 'class', label: t('asset_class.field') },
          { key: 'actions', label: t('common.actions'), align: 'right' },
        ]}
        rows={tipos}
        loading={loading}
        {...emptyStateProps(t, 'asset_types', { buscando: false, onCrear: () => { setTypeToEdit(undefined); setIsDialogOpen(true); } })}
        renderRow={(row) => (<TableRow key={row.TAC_Tipo_Activo} sx={{ '&:hover': { bgcolor: 'action.hover' } }}>
            <TableCell sx={{ color: 'text.primary', fontWeight: 'bold' }}>{row.TAC_Nombre}</TableCell>
            <TableCell>
              {row.TAC_Prefijo ? (<Chip label={row.TAC_Prefijo} color="primary" size="small"/>) : (<Typography variant="caption" color="text.secondary">{t('catalogs.types.manual')}</Typography>)}
            </TableCell>
            <TableCell>
              <Tooltip title={row.TAC_Clase ? t(`asset_class.help.${row.TAC_Clase}`) : ''}>
                <Chip size="small" variant="outlined" color={row.TAC_Clase === 'INSUMO' ? 'warning' : 'default'}
                  label={t(`asset_class.names.${row.TAC_Clase ?? 'OTRO'}`)}/>
              </Tooltip>
            </TableCell>
            <TableCell align="right">
              {row.TAC_Clase === 'INSUMO' && (<Tooltip title={t('reclassify.action')}>
                <IconButton size="small" onClick={() => setReclasificar(row)} sx={{ color: 'warning.main' }}>
                  <SwapVertIcon fontSize="small"/>
                </IconButton>
              </Tooltip>)}
              <Tooltip title={t('common.edit')}>
                <IconButton size="small" onClick={() => handleEdit(row)} sx={{ color: 'primary.main' }}>
                  <EditIcon fontSize="small"/>
                </IconButton>
              </Tooltip>
              <Tooltip title={t('common.delete')}>
                <IconButton size="small" onClick={() => handleDelete(row.TAC_Tipo_Activo)} sx={{ color: 'error.main' }}>
                  <DeleteIcon fontSize="small"/>
                </IconButton>
              </Tooltip>
            </TableCell>
          </TableRow>)}
      />
    </Box>);
};
