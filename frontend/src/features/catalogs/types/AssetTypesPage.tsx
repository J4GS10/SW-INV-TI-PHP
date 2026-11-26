import { useEffect, useMemo, useState } from 'react';
import { SearchInput } from '../../../components/SearchInput';
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
import { apiCache } from '../../../api/cache';
import { catalogoConfigService, type EspecificacionTipo } from '../../../api/catalogoService';
import { accountingService, type AccountingCategory } from '../../accounting/accountingService';
import type { ComponenteDeTipo } from '../catalogService';
import type { ClaseActivo } from '../../../utils/clasesActivo';
import { CON_COMPONENTES, isReady, typeReadiness } from './typeReadiness';
export const AssetTypesPage = () => {
    const { t } = useTranslation();
    const confirm = useConfirm();
    const { enqueueSnackbar } = useSnackbar();
    const [tipos, setTipos] = useState<TipoActivo[]>([]);
    const [loading, setLoading] = useState(true);
    const [isDialogOpen, setIsDialogOpen] = useState(false);
    const [typeToEdit, setTypeToEdit] = useState<TipoActivo | undefined>(undefined);
    const [reclasificar, setReclasificar] = useState<TipoActivo | null>(null);
    // Lo que define cada tipo, para leer de un vistazo si está listo: características, componentes y categoría.
    const [specsPorTipo, setSpecsPorTipo] = useState<Record<string, EspecificacionTipo[]>>({});
    const [compsPorTipo, setCompsPorTipo] = useState<Record<number, ComponenteDeTipo[]>>({});
    const [categorias, setCategorias] = useState<AccountingCategory[]>([]);
    const [busqueda, setBusqueda] = useState('');
    const visibles = useMemo(() => {
        const q = busqueda.trim().toLowerCase();
        return q ? tipos.filter((x) => x.TAC_Nombre.toLowerCase().includes(q) || (x.TAC_Prefijo ?? '').toLowerCase().includes(q)) : tipos;
    }, [tipos, busqueda]);
    const loadData = async () => {
        try {
            apiCache.clear();
            const data = await catalogService.getTiposActivo();
            setTipos(data);
            const [specs, cats, comps] = await Promise.all([
                catalogoConfigService.especificacionesPorTipo().catch((): Record<string, EspecificacionTipo[]> => ({})),
                accountingService.categories().catch((): AccountingCategory[] => []),
                Promise.all(data.filter((x) => CON_COMPONENTES.includes(x.TAC_Clase as ClaseActivo))
                    .map(async (x) => [x.TAC_Tipo_Activo, await catalogService.getComponentesDetalle(x.TAC_Tipo_Activo).catch((): ComponenteDeTipo[] => [])] as const)),
            ]);
            setSpecsPorTipo(specs);
            setCategorias(cats);
            setCompsPorTipo(Object.fromEntries(comps));
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
          { key: 'specs', label: t('asset_type_setup.col_specs') },
          { key: 'components', label: t('asset_type_setup.col_components') },
          { key: 'category', label: t('accounting.category') },
          { key: 'status', label: t('asset_type_setup.col_status') },
          { key: 'actions', label: t('common.actions'), align: 'right' },
        ]}
        rows={visibles}
        toolbar={<SearchInput value={busqueda} onChange={setBusqueda} placeholder={t('catalogs.types.search_placeholder')} />}
        loading={loading}
        {...emptyStateProps(t, 'asset_types', { buscando: !!busqueda.trim(), onCrear: () => { setTypeToEdit(undefined); setIsDialogOpen(true); } })}
        renderRow={(row) => {
          const specs = specsPorTipo[String(row.TAC_Tipo_Activo)] ?? [];
          const comps = compsPorTipo[row.TAC_Tipo_Activo] ?? [];
          const conComps = CON_COMPONENTES.includes(row.TAC_Clase as ClaseActivo);
          const categoria = categorias.find((c) => c.id === row.FAC_ASSET_CATEGORY);
          const revision = typeReadiness({ name: row.TAC_Nombre, prefix: row.TAC_Prefijo ?? '', assetClass: row.TAC_Clase ?? '', classHasComponents: conComps,
            isSupply: row.TAC_Clase === 'INSUMO', hasCategory: !!categoria, specs: specs.map((s) => ({ required: s.obligatoria })),
            components: comps.map((c) => ({ required: c.obligatorio })) });
          const pendientes = revision.filter((r) => r.level !== 'ok').map((r) => t(`asset_type_setup.short.${r.key}`));
          return (<TableRow key={row.TAC_Tipo_Activo} hover sx={{ cursor: 'pointer' }} onClick={() => handleEdit(row)}>
            <TableCell>
              <Typography variant="body2" fontWeight={700}>{row.TAC_Nombre}</Typography>
              <Tooltip title={row.TAC_Clase ? t(`asset_class.help.${row.TAC_Clase}`) : ''}>
                <Typography variant="caption" color={row.TAC_Clase === 'INSUMO' ? 'warning.main' : 'text.secondary'}>{t(`asset_class.names.${row.TAC_Clase ?? 'OTRO'}`)}</Typography>
              </Tooltip>
            </TableCell>
            <TableCell>
              {row.TAC_Prefijo ? (<Chip label={row.TAC_Prefijo} color="primary" size="small"/>) : (<Typography variant="caption" color="text.secondary">{t('catalogs.types.manual')}</Typography>)}
            </TableCell>
            <TableCell>
              <Tooltip title={specs.map((s) => `${s.nombre ?? ''}${s.obligatoria ? ' *' : ''}`).join(' · ')}>
                <Typography variant="body2">{specs.length ? t('asset_type_setup.count_specs', { count: specs.length, required: specs.filter((s) => s.obligatoria).length }) : '—'}</Typography>
              </Tooltip>
            </TableCell>
            <TableCell>
              {conComps ? (
                <Tooltip title={comps.map((c) => `${c.nombre}${c.obligatorio ? ` (${t('asset_type_setup.components.required_short', { count: c.cantidad })})` : ''}`).join(' · ')}>
                  <Typography variant="body2">{comps.length ? t('asset_type_setup.count_components', { count: comps.length, required: comps.filter((c) => c.obligatorio).length }) : '—'}</Typography>
                </Tooltip>
              ) : <Typography variant="caption" color="text.secondary">{t('asset_type_setup.no_components_class')}</Typography>}
            </TableCell>
            <TableCell>
              {categoria ? <Typography variant="body2">{categoria.name}</Typography>
                : <Typography variant="caption" color="warning.main">{t('accounting.without_category')}</Typography>}
            </TableCell>
            <TableCell>
              <Tooltip title={pendientes.join(' · ')}>
                <Chip size="small" variant={pendientes.length ? 'outlined' : 'filled'}
                  color={!isReady(revision) ? 'error' : pendientes.length ? 'warning' : 'success'}
                  label={!isReady(revision) ? t('asset_type_setup.status_blocked') : pendientes.length ? t('asset_type_setup.status_review', { count: pendientes.length }) : t('asset_type_setup.status_ready')}/>
              </Tooltip>
            </TableCell>
            <TableCell align="right" onClick={(e) => e.stopPropagation()} sx={{ whiteSpace: 'nowrap' }}>
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
          </TableRow>);
        }}
      />
    </Box>);
};
