import { useCallback, useEffect, useState } from 'react';
import { Box, Typography, Paper, List, ListItemButton, ListItemText, ListItemSecondaryAction, Breadcrumbs, Link, Button, TextField, DialogTitle, DialogContent, DialogActions, CircularProgress, IconButton, Tooltip } from '@mui/material';
import NavigateNextIcon from '@mui/icons-material/NavigateNext';
import AddIcon from '@mui/icons-material/Add';
import FolderIcon from '@mui/icons-material/Folder';
import EditIcon from '@mui/icons-material/Edit';
import DeleteIcon from '@mui/icons-material/DeleteOutline';
import { useTranslation } from 'react-i18next';
import { CatalogField } from '../../components/CatalogField';
import { useSnackbar } from 'notistack';
import { PageHeader, EmptyState } from '../../components/ui';
import { getApiErrorMessage } from '../../api/errors';
import { locationService } from './locationService';
import { useConfirm } from '../../components/confirm-context';
import { Dialog } from '../../components/ui/Dialog';
import { useLiveRefresh } from '../../hooks/useLiveRefresh';
type LevelType = 'PAIS' | 'ESTADO' | 'MUNICIPIO' | 'SEDE' | 'EDIFICIO' | 'NIVEL' | 'AREA';
interface BreadcrumbItem {
    type: LevelType;
    id: number;
    name: string;
}
type LocationFieldValue = string | number | null | undefined;
type LocationItem = Record<string, LocationFieldValue>;
type LocationPayload = Record<string, string | number | null>;
interface LocationApi {
    list: (parentId?: number) => Promise<LocationItem[]>;
    create: (payload: LocationPayload) => Promise<unknown>;
    update: (id: number, payload: LocationPayload) => Promise<unknown>;
    remove: (id: number) => Promise<void>;
}
const asItems = <T,>(items: T[]): LocationItem[] => items as LocationItem[];
// Mapeo de cada nivel a sus campos
const LEVEL_META: Record<LevelType, {
    next: LevelType | null;
    idField: string;
    nameField: string;
    extra?: string; // segundo campo del form (ej: ISO)
    /** Clave i18n de la etiqueta del segundo campo. */
    extraLabelKey?: string;
    parentField?: string; // FK al padre
}> = {
    PAIS: { next: 'ESTADO', idField: 'PAI_Pais', nameField: 'PAI_Nombre', extra: 'PAI_Codigo_ISO', extraLabelKey: 'locations.field_iso' },
    ESTADO: { next: 'MUNICIPIO', idField: 'EST_Estado', nameField: 'EST_Nombre', parentField: 'PAI_Pais' },
    MUNICIPIO: { next: 'SEDE', idField: 'MUN_Municipio', nameField: 'MUN_Nombre', parentField: 'EST_Estado' },
    SEDE: { next: 'EDIFICIO', idField: 'SED_Sede', nameField: 'SED_Nombre', parentField: 'MUN_Municipio' },
    EDIFICIO: { next: 'NIVEL', idField: 'EDI_Edificio', nameField: 'EDI_Nombre', parentField: 'SED_Sede' },
    NIVEL: { next: 'AREA', idField: 'NIV_Nivel', nameField: 'NIV_Numero_Piso', extra: 'NIV_Alias', extraLabelKey: 'locations.field_alias', parentField: 'EDI_Edificio' },
    AREA: { next: null, idField: 'ARE_Area', nameField: 'ARE_Nombre', extra: 'ARE_Tipo_Acceso', extraLabelKey: 'locations.field_access_type', parentField: 'NIV_Nivel' },
};
const apiByLevel: Record<LevelType, LocationApi> = {
    PAIS: {
        list: async () => asItems(await locationService.getPaises()),
        create: locationService.createPais,
        update: locationService.updatePais,
        remove: locationService.deletePais,
    },
    ESTADO: {
        list: async (parentId = 0) => asItems(await locationService.getEstados(parentId)),
        create: locationService.createEstado,
        update: locationService.updateEstado,
        remove: locationService.deleteEstado,
    },
    MUNICIPIO: {
        list: async (parentId = 0) => asItems(await locationService.getMunicipios(parentId)),
        create: locationService.createMunicipio,
        update: locationService.updateMunicipio,
        remove: locationService.deleteMunicipio,
    },
    SEDE: {
        list: async (parentId = 0) => asItems(await locationService.getSedes(parentId)),
        create: locationService.createSede,
        update: locationService.updateSede,
        remove: locationService.deleteSede,
    },
    EDIFICIO: {
        list: async (parentId = 0) => asItems(await locationService.getEdificios(parentId)),
        create: locationService.createEdificio,
        update: locationService.updateEdificio,
        remove: locationService.deleteEdificio,
    },
    NIVEL: {
        list: async (parentId = 0) => asItems(await locationService.getNiveles(parentId)),
        create: locationService.createNivel,
        update: locationService.updateNivel,
        remove: locationService.deleteNivel,
    },
    AREA: {
        list: async (parentId = 0) => asItems(await locationService.getAreas(parentId)),
        create: locationService.createArea,
        update: locationService.updateArea,
        remove: locationService.deleteArea,
    },
};
const fieldAsNumber = (item: LocationItem, field: string): number => Number(item[field] ?? 0);
const fieldAsString = (item: LocationItem, field: string): string => String(item[field] ?? '');
export const LocationsPage = () => {
    const { t, i18n } = useTranslation();
    const confirm = useConfirm();
    const { enqueueSnackbar } = useSnackbar();
    const [breadcrumbs, setBreadcrumbs] = useState<BreadcrumbItem[]>([]);
    const [currentLevel, setCurrentLevel] = useState<LevelType>('PAIS');
    const [items, setItems] = useState<LocationItem[]>([]);
    const [loading, setLoading] = useState(false);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editing, setEditing] = useState<LocationItem | null>(null);
    const [name, setName] = useState('');
    const [extra, setExtra] = useState('');
    // Áreas: tipo del catálogo (Bodega, Oficina…); el nivel de acceso es General o Restringido.
    const [tipoArea, setTipoArea] = useState<number | null>(null);
    const meta = LEVEL_META[currentLevel];
    const api = apiByLevel[currentLevel];
    const loadItems = useCallback(async () => {
        setLoading(true);
        try {
            const parentId = breadcrumbs.length > 0 ? breadcrumbs[breadcrumbs.length - 1].id : 0;
            const data = currentLevel === 'PAIS'
                ? await api.list()
                : await api.list(parentId);
            setItems(data);
        }
        catch {
            enqueueSnackbar(t('common.error_loading'), { variant: 'error' });
        }
        finally {
            setLoading(false);
        }
    }, [api, breadcrumbs, currentLevel, enqueueSnackbar, t]);
    useEffect(() => { loadItems(); }, [loadItems]);
    useLiveRefresh(loadItems);
    const navigateInto = (item: LocationItem) => {
        const next = meta.next;
        if (!next)
            return;
        setBreadcrumbs([...breadcrumbs, {
                type: currentLevel,
                id: fieldAsNumber(item, meta.idField),
                name: fieldAsString(item, meta.nameField),
            }]);
        setCurrentLevel(next);
    };
    const goBreadcrumb = (index: number) => {
        if (index === -1) {
            setBreadcrumbs([]);
            setCurrentLevel('PAIS');
        }
        else {
            const newCrumbs = breadcrumbs.slice(0, index + 1);
            setBreadcrumbs(newCrumbs);
            setCurrentLevel(LEVEL_META[newCrumbs[newCrumbs.length - 1].type].next as LevelType);
        }
    };
    const openCreate = () => {
        setEditing(null);
        setName('');
        setExtra(currentLevel === 'AREA' ? 'General' : '');
        setTipoArea(null);
        setDialogOpen(true);
    };
    const openEdit = (item: LocationItem) => {
        setEditing(item);
        setName(fieldAsString(item, meta.nameField));
        setExtra(meta.extra ? fieldAsString(item, meta.extra) : '');
        setTipoArea(currentLevel === 'AREA' ? (fieldAsNumber(item, 'TAR_Tipo_Area') || null) : null);
        setDialogOpen(true);
    };
    const handleSubmit = async () => {
        if (!name.trim()) {
            enqueueSnackbar(t('common.required'), { variant: 'warning' });
            return;
        }
        const parentId = breadcrumbs.length > 0 ? breadcrumbs[breadcrumbs.length - 1].id : 0;
        const payload: LocationPayload = { [meta.nameField]: name.trim() };
        if (meta.extra)
            payload[meta.extra] = extra.trim();
        if (meta.parentField && !editing)
            payload[meta.parentField] = parentId;
        if (currentLevel === 'AREA')
            payload.TAR_Tipo_Area = tipoArea;
        // Validación específica de PAIS: ISO requerido
        if (currentLevel === 'PAIS' && !extra.trim()) {
            enqueueSnackbar(t('locations.iso_required'), { variant: 'warning' });
            return;
        }
        try {
            if (editing) {
                await api.update(fieldAsNumber(editing, meta.idField), payload);
                enqueueSnackbar(t('common.success_update'), { variant: 'success' });
            }
            else {
                await api.create(payload);
                enqueueSnackbar(t('common.success_create'), { variant: 'success' });
            }
            setDialogOpen(false);
            loadItems();
        }
        catch (err: unknown) {
            enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
        }
    };
    const handleDelete = async (item: LocationItem) => {
        if (!(await confirm({ message: t('common.confirm_delete'), destructive: true, confirmText: t('common.delete') })))
            return;
        try {
            await api.remove(fieldAsNumber(item, meta.idField));
            enqueueSnackbar(t('common.success_update'), { variant: 'success' });
            loadItems();
        }
        catch (err: unknown) {
            enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
        }
    };
    const levelLabel = (lvl: LevelType) => t(`locations.levels.${lvl}`);
    // El nivel va en minúscula dentro de la frase ("Agregar país", "Edit building").
    const levelInSentence = (lvl: LevelType) => levelLabel(lvl).toLocaleLowerCase(i18n.language);
    return (<Box>
      <PageHeader
        title={t('locations.title')}
        badge={loading ? undefined : items.length}
        actions={
          <Button variant="contained" startIcon={<AddIcon />} onClick={openCreate}>
            {t('locations.new_title', { level: levelInSentence(currentLevel) })}
          </Button>
        }
      />

      <Breadcrumbs separator={<NavigateNextIcon />} sx={{ mb: 3 }}>
        <Link component="button" onClick={() => goBreadcrumb(-1)}>
          {t('locations.levels.ROOT')}
        </Link>
        {breadcrumbs.map((bc, index) => (<Link key={bc.type + bc.id} component="button" onClick={() => goBreadcrumb(index)}>
            {bc.name}
          </Link>))}
        <Typography color="primary" fontWeight={600}>{levelLabel(currentLevel)}</Typography>
      </Breadcrumbs>

      <Paper variant="outlined" sx={{ borderRadius: 2, overflow: 'hidden' }}>
        {loading ? (<Box display="flex" justifyContent="center" py={3}><CircularProgress /></Box>) : (<List disablePadding>
            {items.map((item) => {
                const id = fieldAsNumber(item, meta.idField);
                const itemName = fieldAsString(item, meta.nameField);
                const sub = meta.extra ? fieldAsString(item, meta.extra) : null;
                return (<ListItemButton key={id} onClick={() => meta.next && navigateInto(item)} sx={{ borderBottom: '1px solid', borderColor: 'divider', pr: 12 }}>
                  <FolderIcon sx={{ mr: 2, color: 'primary.main' }}/>
                  <ListItemText primary={itemName} secondary={sub}/>
                  <ListItemSecondaryAction>
                    <Tooltip title={t('common.edit') as string}>
                      <IconButton size="small" onClick={(e) => { e.stopPropagation(); openEdit(item); }}>
                        <EditIcon fontSize="small"/>
                      </IconButton>
                    </Tooltip>
                    <Tooltip title={t('common.delete') as string}>
                      <IconButton size="small" color="error" onClick={(e) => { e.stopPropagation(); handleDelete(item); }}>
                        <DeleteIcon fontSize="small"/>
                      </IconButton>
                    </Tooltip>
                    {meta.next && <NavigateNextIcon sx={{ color: 'text.secondary' }}/>}
                  </ListItemSecondaryAction>
                </ListItemButton>);
            })}
            {items.length === 0 && (
              <EmptyState title={t('common.no_records')} compact />
            )}
          </List>)}
      </Paper>

      <Dialog open={dialogOpen} onClose={() => setDialogOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>
          {t(editing ? 'locations.edit_title' : 'locations.new_title', { level: levelInSentence(currentLevel) })}
        </DialogTitle>
        <DialogContent>
          <TextField autoFocus margin="dense" fullWidth label={t('common.name')} value={name} onChange={(e) => setName(e.target.value)}/>
          {currentLevel === 'AREA' ? (<>
              <TextField select margin="dense" fullWidth label={t('locations.field_access_type')} value={extra || 'General'}
                onChange={(e) => setExtra(e.target.value)} SelectProps={{ native: true }}>
                <option value="General">{t('locations.access.General')}</option>
                <option value="Restringido">{t('locations.access.Restringido')}</option>
              </TextField>
              <Box sx={{ mt: 1 }}>
                <CatalogField clave="tipos-area" label={t('locations.field_area_type')} value={tipoArea}
                  onChange={(v) => setTipoArea(v as number | null)}/>
              </Box>
            </>) : meta.extra && meta.extraLabelKey && (<TextField margin="dense" fullWidth label={t(meta.extraLabelKey)} value={extra} onChange={(e) => setExtra(e.target.value)}/>)}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDialogOpen(false)}>{t('common.cancel')}</Button>
          <Button onClick={handleSubmit} variant="contained">{t('common.save')}</Button>
        </DialogActions>
      </Dialog>
    </Box>);
};
