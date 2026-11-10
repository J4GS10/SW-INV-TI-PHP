import { useCallback, useEffect, useState } from 'react';
import { Box, TableCell, TableRow, Button, Chip, IconButton, TextField, MenuItem, Tooltip } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import EditIcon from '@mui/icons-material/Edit';
import DeleteIcon from '@mui/icons-material/Delete';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { catalogService } from '../catalogService';
import { Marca, Modelo, TipoConexion } from '../../../types/catalogs';
import { ModelDialog } from './ModelDialog';
import { assetService } from '../../assets/assetService';
import { ModelSpecDefaultsDialog } from './ModelSpecDefaultsDialog';
import TuneIcon from '@mui/icons-material/Tune';
import { PageHeader, DataTableShell } from '../../../components/ui';
import { SearchInput } from '../../../components/SearchInput';
import { CollapsibleToolbar } from '../../../components/CollapsibleToolbar';
import { useConfirm } from '../../../components/confirm-context';
import { useLiveRefresh } from '../../../hooks/useLiveRefresh';
export const ModelsPage = () => {
    const { t } = useTranslation();
    const confirm = useConfirm();
    const { enqueueSnackbar } = useSnackbar();
    const [searchTerm, setSearchTerm] = useState('');
    const [defaultsFor, setDefaultsFor] = useState<{ id: number; name: string } | null>(null);
    const [marcas, setMarcas] = useState<Marca[]>([]);
    const [modelos, setModelos] = useState<Modelo[]>([]);
    const [conexiones, setConexiones] = useState<TipoConexion[]>([]);
    const [selectedMarca, setSelectedMarca] = useState<number | ''>(0);
    const [loading, setLoading] = useState(false);
    const [isDialogOpen, setIsDialogOpen] = useState(false);
    const [modelToEdit, setModelToEdit] = useState<Modelo | undefined>(undefined);
    // Carga Inicial
    useEffect(() => {
        const init = async () => {
            try {
                const [m, c] = await Promise.all([
                    catalogService.getMarcas(),
                    catalogService.getTiposConexion()
                ]);
                setMarcas(m);
                setConexiones(c);

            }
            catch {
                enqueueSnackbar(t('common.error_loading'), { variant: 'error' });
            }
        };
        init();
    }, []);
    // Carga Modelos
    const [loadError, setLoadError] = useState(false);
    const loadModelos = useCallback(async () => {
        setLoading(true);
        try {
            const data = Number(selectedMarca) === 0
                ? await assetService.getModelosFlat() as unknown as Modelo[]
                : await catalogService.getModelos(Number(selectedMarca));
            setModelos(data);
            setLoadError(false);
        }
        catch {
            setLoadError(true);
        }
        finally {
            setLoading(false);
        }
    }, [selectedMarca]);
    useLiveRefresh(loadModelos);
    useEffect(() => {
        loadModelos();
    }, [loadModelos]);
    const handleEdit = (model: Modelo) => {
        setModelToEdit(model);
        setIsDialogOpen(true);
    };
    const handleDelete = async (id: number) => {
        if (!(await confirm({ message: t('common.confirm_delete'), destructive: true, confirmText: t('common.delete') })))
            return;
        try {
            await catalogService.deleteModelo(id);
            loadModelos();
        }
        catch (error) {
            console.error(error);
            enqueueSnackbar(t('common.error_delete_dependencies'), { variant: 'error' });
        }
    };
    const handleCloseDialog = (refresh?: boolean) => {
        setIsDialogOpen(false);
        setModelToEdit(undefined);
        if (refresh)
            loadModelos();
    };
    const getConnName = (id?: number | null) => conexiones.find(c => c.TCN_Tipo_Conexion === id)?.TCN_Nombre || '---';
    const filteredModelos = modelos.filter(m => m.MOD_Nombre.toLowerCase().includes(searchTerm.toLowerCase()));
    const selectedMarcaName = marcas.find(m => m.MAR_Marca === selectedMarca)?.MAR_Nombre;
    return (<Box>
      <PageHeader
        title={t('catalogs.models.title')}
        badge={filteredModelos.length}
        actions={
          <Button variant="contained" startIcon={<AddIcon />} onClick={() => { setModelToEdit(undefined); setIsDialogOpen(true); }}>
            {t('catalogs.models.new_btn')}
          </Button>
        }
      />

      <CollapsibleToolbar
        storageKey="models"
        search={<SearchInput value={searchTerm} onChange={setSearchTerm}/>}
        summary={selectedMarcaName && <Chip size="small" label={selectedMarcaName}/>}
      >
        <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1.5, alignItems: 'center' }}>
          <TextField select size="small" label={t('catalogs.models.filter_brand')} value={selectedMarca} onChange={(e) => setSelectedMarca(Number(e.target.value))} sx={{ minWidth: { xs: '100%', sm: 180 } }}>
            <MenuItem value={0}>{t('common.all')}</MenuItem>
            {marcas.map((m) => (<MenuItem key={m.MAR_Marca} value={m.MAR_Marca}>{m.MAR_Nombre}</MenuItem>))}
          </TextField>
        </Box>
      </CollapsibleToolbar>

      <ModelSpecDefaultsDialog model={defaultsFor} onClose={() => setDefaultsFor(null)}/>
      <ModelDialog open={isDialogOpen} defaultMarcaId={Number(selectedMarca) || undefined} onClose={handleCloseDialog} modelToEdit={modelToEdit}/>

      <DataTableShell
        error={loadError}
        onRetry={() => { void loadModelos(); }}
        columns={[
          { key: 'name', label: t('catalogs.models.table_name') },
          { key: 'connection', label: t('catalogs.models.table_connection') },
          { key: 'actions', label: t('common.actions'), align: 'right' },
        ]}
        rows={filteredModelos}
        loading={loading}
        emptyTitle={t('common.no_records')}
        renderRow={(row) => (<TableRow key={row.MOD_Modelo} sx={{ '&:hover': { bgcolor: 'action.hover' } }}>
            <TableCell sx={{ color: 'text.primary', fontWeight: 'bold' }}>{row.MOD_Nombre}</TableCell>
            <TableCell>
              <Chip label={getConnName(row.TCN_Tipo_Conexion)} size="small" variant="outlined" sx={{ color: 'text.secondary' }}/>
            </TableCell>
            <TableCell align="right">
              <Button size="small" color="inherit" startIcon={<TuneIcon />} onClick={() => setDefaultsFor({ id: row.MOD_Modelo, name: row.MOD_Nombre })} sx={{ mr: 0.5, whiteSpace: 'nowrap' }}>
                {t('catalogs.models.defaults_button')}
              </Button>

              <Tooltip title={t('common.edit')}>
                <IconButton size="small" onClick={() => handleEdit(row)} sx={{ color: 'primary.main' }}>
                  <EditIcon fontSize="small"/>
                </IconButton>
              </Tooltip>
              <Tooltip title={t('common.delete')}>
                <IconButton size="small" onClick={() => handleDelete(row.MOD_Modelo)} sx={{ color: 'error.main' }}>
                  <DeleteIcon fontSize="small"/>
                </IconButton>
              </Tooltip>
            </TableCell>
          </TableRow>)}
      />
    </Box>);
};
