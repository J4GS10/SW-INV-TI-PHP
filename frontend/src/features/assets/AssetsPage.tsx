import { useCallback, useEffect, useMemo, useState } from 'react';
import {
    Box, Typography, TableCell, TableRow, Chip, IconButton, CircularProgress, Button, Tooltip, Select, MenuItem, FormControl, InputLabel, Checkbox,
} from '@mui/material';
import { useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { PageHeader, StatusChip, DataTableShell } from '../../components/ui';
import EditIcon from '@mui/icons-material/Edit';
import VisibilityIcon from '@mui/icons-material/Visibility';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutline';
import AddIcon from '@mui/icons-material/Add';
import QrCodeScannerIcon from '@mui/icons-material/QrCodeScanner';
import AssignmentIndIcon from '@mui/icons-material/AssignmentInd';
import AssignmentReturnIcon from '@mui/icons-material/AssignmentReturn';
import FileDownloadIcon from '@mui/icons-material/FileDownload';
import { useTranslation } from 'react-i18next';
import { stateLabel, stateVariant, catalogSlug } from '../../utils/catalogLabels';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../api/errors';
import { downloadCsv } from '../../api/downloadCsv';
import { guardarArchivo } from '../../api/productividadService';
import { AssetDialog } from './AssetDialog';
import { BajaDialog } from './BajaDialog';
import { AssetDetailDialog } from './AssetDetailDialog';
import { assetService } from './assetService';
import { catalogService } from '../catalogs/catalogService';
import { Activo, EstadoOperativo, Marca, Modelo, TipoActivo } from '../../types/inventory';
import { ExportMenuButton } from '../../components/ExportMenuButton';
import { SavedViewsMenu } from '../../components/SavedViewsMenu';
import PlaceIcon from '@mui/icons-material/Place';
import { InstalacionAreaDialog } from '../operations/InstalacionAreaDialog';
import { reglasDe } from '../../utils/clasesActivo';
import { usePermissions } from '../../hooks/usePermissions';
import { SearchInput } from '../../components/SearchInput';
import { normalizeLanguage } from '../../types/language';
import { CollapsibleToolbar } from '../../components/CollapsibleToolbar';
import { useAccionPagina, useWorkspace } from '../../context/workspace-context';
import { RowActionsMenu, type RowAction } from '../../components/RowActionsMenu';
import { accionesDisponibles, type AccionActivo } from '../../utils/acciones';
import { serialLabel } from '../../utils/assetDisplay';
import type { SortState } from '../../components/ui/tableSort';
import { BatchEditDialog } from './BatchEditDialog';
import { TableSettingsMenu } from '../../components/TableSettingsMenu';
import { useTableConfig } from '../../hooks/useTableConfig';
import { useAuth } from '../../context/auth-context';
import { useMediaQuery, type Theme } from '@mui/material';
import BuildIcon from '@mui/icons-material/Build';
import HistoryIcon from '@mui/icons-material/History';
import SwapHorizIcon from '@mui/icons-material/SwapHoriz';
import EditNoteIcon from '@mui/icons-material/EditNote';

/** Parámetros de la URL que forman una vista (la página no cuenta). */
const CLAVES_VISTA = ['q', 'tipo', 'estado'];

export const AssetsPage = () => {
    const { t, i18n } = useTranslation();
    const { canOperate, canAdminInventory, canExportFinance: canExport } = usePermissions();
    const { enqueueSnackbar } = useSnackbar();
    const { abrirEntrega, abrirDevolucion, agregarReciente } = useWorkspace();
    const navigate = useNavigate();
    const location = useLocation();
    const { codigo } = useParams<{ codigo?: string }>();
    const [params, setParams] = useSearchParams();

    // ── Catálogos (para dropdowns) ──────────────────────────────────────────
    const [marcas, setMarcas] = useState<Marca[]>([]);
    const [modelos, setModelos] = useState<Modelo[]>([]);
    const [tipos, setTipos] = useState<TipoActivo[]>([]);
    const [estados, setEstados] = useState<EstadoOperativo[]>([]);

    // ── Datos de la página actual (server-side) ─────────────────────────────
    const [activos, setActivos] = useState<Activo[]>([]);
    const [total, setTotal] = useState(0);
    const [loading, setLoading] = useState(true);

    // ── Filtros y paginación: viven en la URL (se comparten y sobreviven a la navegación) ──
    const q = params.get('q') ?? '';
    const tipoFilter: number | 'all' = params.get('tipo') ? Number(params.get('tipo')) : 'all';
    const estadoFilter: number | 'all' = params.get('estado') ? Number(params.get('estado')) : 'all';
    const page = Math.max(0, Number(params.get('p') ?? 0) || 0);
    const rowsPerPage = Number(params.get('n') ?? 25) || 25;
    const [searchTerm, setSearchTerm] = useState(q);

    const setFiltros = useCallback((cambios: Record<string, string | number | null>, reiniciarPagina = true) => {
        setParams((prev) => {
            const next = new URLSearchParams(prev);
            Object.entries(cambios).forEach(([k, v]) => {
                if (v === null || v === '' || v === 'all') next.delete(k);
                else next.set(k, String(v));
            });
            if (reiniciarPagina) next.delete('p');
            return next;
        }, { replace: true });
    }, [setParams]);

    const consultaVista = useMemo(() => {
        const v = new URLSearchParams();
        CLAVES_VISTA.forEach((k) => { const val = params.get(k); if (val) v.set(k, val); });
        return v.toString();
    }, [params]);

    // Texto: debounce hacia la URL, y sincronía si la URL cambia (vista aplicada, atrás/adelante).
    useEffect(() => { setSearchTerm(q); }, [q]);
    useEffect(() => {
        if (searchTerm === q) return undefined;
        const timer = setTimeout(() => setFiltros({ q: searchTerm.trim() || null }), 400);
        return () => clearTimeout(timer);
    }, [searchTerm, q, setFiltros]);

    // ── Selección (persiste entre páginas) ──────────────────────────────────
    const [seleccion, setSeleccion] = useState<Map<string, Activo>>(new Map());
    const [downloadingLabels, setDownloadingLabels] = useState(false);
    const [bajaLote, setBajaLote] = useState<Activo[] | null>(null);
    const [edicionLote, setEdicionLote] = useState<Activo[] | null>(null);
    // Columnas visibles y densidad: preferencia del usuario en esta página.
    const { user } = useAuth();
    const tabla = useTableConfig('activos', user?.sub);
    const [orden, setOrden] = useState<SortState | null>(null);
    const verCustodio = !!user && ['ADMIN_TI', 'TECNICO', 'AUDITOR'].includes(user.role);
    // En un celular se dejan código, quién lo tiene, estado y acciones: lo demás está en la ficha.
    const esMovil = useMediaQuery((th: Theme) => th.breakpoints.down('sm'));
    const columnasOcultas = useMemo(() => (esMovil ? new Set([...tabla.ocultas, 'select', 'type', 'brand', 'model', 'serial', 'host']) : tabla.ocultas), [esMovil, tabla.ocultas]);
    const columnasConfigurables = [
        { key: 'type', label: t('assets.table.type') }, { key: 'brand', label: t('assets.dialog.brand') },
        { key: 'model', label: t('assets.dialog.model') }, { key: 'serial', label: t('assets.table.serial') },
        { key: 'host', label: t('assets.table.host') }, { key: 'status', label: t('assets.table.status') },
    ];

    // ── Diálogos ────────────────────────────────────────────────────────────
    const [editOpen, setEditOpen] = useState(false);
    const [detailOpen, setDetailOpen] = useState(false);
    const [selected, setSelected] = useState<Activo | null>(null);
    const [bajaTarget, setBajaTarget] = useState<Activo | null>(null);
    const [instalacion, setInstalacion] = useState<{ modo: 'instalar' | 'retirar'; activo: Activo } | null>(null);

    // ── Cargar catálogos (en caché: navegar de vuelta es inmediato) ─────────
    useEffect(() => {
        Promise.all([
            assetService.getMarcas().catch(() => [] as Marca[]),
            assetService.getTipos().catch(() => [] as TipoActivo[]),
            assetService.getEstados().catch(() => [] as EstadoOperativo[]),
            catalogService.getModelosFlat().catch(() => [] as Modelo[]),
        ]).then(([marcasData, tiposData, estadosData, modelosFlat]) => {
            setMarcas(marcasData);
            setTipos(tiposData);
            setEstados(estadosData);
            setModelos(modelosFlat);
        });
    }, []);

    // ── Cargar página de activos (server-side) ──────────────────────────────
    const loadActivos = useCallback(async () => {
        setLoading(true);
        try {
            const result = await assetService.searchActivos({
                q: q.trim() || undefined,
                tipo_activo_id: tipoFilter !== 'all' ? tipoFilter : undefined,
                estado_operativo_id: estadoFilter !== 'all' ? estadoFilter : undefined,
                page: page + 1,
                per_page: rowsPerPage,
                sort: orden?.key,
                sort_dir: orden?.direction,
            });
            setActivos(result.items);
            setTotal(result.total);
        } catch {
            enqueueSnackbar(t('common.error_loading'), { variant: 'error' });
        } finally {
            setLoading(false);
        }
    }, [q, tipoFilter, estadoFilter, page, rowsPerPage, orden, enqueueSnackbar, t]);

    useEffect(() => { loadActivos(); }, [loadActivos]);

    // ── Ficha con dirección propia: /activos/LAP-00001 ──────────────────────
    useEffect(() => {
        if (!codigo) { setDetailOpen(false); return undefined; }
        let vivo = true;
        assetService.getActivoByCode(codigo).then((a) => {
            if (!vivo) return;
            setSelected(a);
            setDetailOpen(true);
            agregarReciente({ tipo: 'activo', id: a.ACT_Activo, titulo: a.ACT_Codigo_Interno, subtitulo: a.ACT_Serie_Fabricante ?? undefined, ruta: `/activos/${encodeURIComponent(a.ACT_Codigo_Interno)}` });
        }).catch(() => {
            if (!vivo) return;
            enqueueSnackbar(t('scanner.not_found'), { variant: 'warning' });
            navigate({ pathname: '/activos', search: location.search }, { replace: true });
        });
        return () => { vivo = false; };
        // location.search solo se usa para volver; no debe recargar la ficha.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [codigo, agregarReciente, enqueueSnackbar, navigate, t]);

    // «?nuevo=1» (paleta de comandos o atajo) abre el alta.
    useEffect(() => {
        if (params.get('nuevo') && canAdminInventory) {
            setSelected(null);
            setEditOpen(true);
            setFiltros({ nuevo: null }, false);
        }
    }, [params, canAdminInventory, setFiltros]);

    const handleNew = useCallback(() => { setSelected(null); setEditOpen(true); }, []);
    useAccionPagina('nuevo', canAdminInventory ? handleNew : null);

    // ── Métricas de filtros activos ─────────────────────────────────────────
    const activeFilters = [q.trim(), estadoFilter !== 'all', tipoFilter !== 'all'].filter(Boolean).length;

    // ── Selección ───────────────────────────────────────────────────────────
    const selectedCount = seleccion.size;
    const selectedVisibleCount = activos.filter((a) => seleccion.has(a.ACT_Activo)).length;
    const allVisibleSelected = activos.length > 0 && selectedVisibleCount === activos.length;
    const someVisibleSelected = selectedVisibleCount > 0 && selectedVisibleCount < activos.length;
    const handleToggleVisibleSelection = () => {
        setSeleccion((prev) => {
            const next = new Map(prev);
            if (allVisibleSelected) activos.forEach((a) => next.delete(a.ACT_Activo));
            else activos.forEach((a) => next.set(a.ACT_Activo, a));
            return next;
        });
    };
    const handleToggleAssetSelection = (asset: Activo) => {
        setSeleccion((prev) => {
            const next = new Map(prev);
            if (next.has(asset.ACT_Activo)) next.delete(asset.ACT_Activo); else next.set(asset.ACT_Activo, asset);
            return next;
        });
    };
    const limpiarSeleccion = () => setSeleccion(new Map());
    const tras = () => { limpiarSeleccion(); loadActivos(); };

    // ── Resolución de nombres desde catálogos ───────────────────────────────
    const getTipoName = (id: number) => tipos.find((tp) => tp.TAC_Tipo_Activo === id)?.TAC_Nombre ?? '—';
    const getEstadoInfo = (id: number) => estados.find((e) => e.EOP_Estado_Operativo === id);
    const getModelo = (id: number) => modelos.find((m) => m.MOD_Modelo === id);
    const getMarcaName = (modeloId: number) => {
        const mod = getModelo(modeloId);
        if (!mod) return '—';
        return marcas.find((m) => m.MAR_Marca === mod.MAR_Marca)?.MAR_Nombre ?? '—';
    };
    const getModeloName = (id: number) => getModelo(id)?.MOD_Nombre ?? '—';

    // ── Handlers ────────────────────────────────────────────────────────────
    const handleEdit = (asset: Activo) => { setSelected(asset); setEditOpen(true); };
    const handleView = (asset: Activo) => navigate({ pathname: `/activos/${encodeURIComponent(asset.ACT_Codigo_Interno)}`, search: location.search });
    const cerrarFicha = () => { setDetailOpen(false); setSelected(null); navigate({ pathname: '/activos', search: location.search }); };

    const etiquetaDe = async (row: Activo) => {
        try {
            const blob = await assetService.downloadLabels({ activos_ids: [row.ACT_Activo] }, normalizeLanguage(i18n.resolvedLanguage));
            guardarArchivo(blob, `etiqueta-${row.ACT_Codigo_Interno}.pdf`);
        } catch (error: unknown) {
            enqueueSnackbar(getApiErrorMessage(error, t('common.error_generic')), { variant: 'error' });
        }
    };

    // Menú «⋯»: solo las acciones válidas para el estado del activo y el rol.
    const accionesFila = (row: Activo): RowAction[] => {
        const def: Record<AccionActivo, Omit<RowAction, 'key'>> = {
            ver: { label: t('common.view'), icon: <VisibilityIcon fontSize="small" />, onClick: () => handleView(row) },
            editar: { label: t('common.edit'), icon: <EditIcon fontSize="small" />, onClick: () => handleEdit(row) },
            asignar: { label: t('row_actions.assign'), icon: <AssignmentIndIcon fontSize="small" />,
                onClick: () => abrirEntrega({ activos: [row], onDone: loadActivos }) },
            transferir: { label: t('row_actions.transfer'), icon: <SwapHorizIcon fontSize="small" />,
                onClick: () => abrirEntrega({ activos: [row], onDone: loadActivos }) },
            devolver: { label: t('row_actions.return'), icon: <AssignmentReturnIcon fontSize="small" />,
                onClick: () => abrirDevolucion({ activos: [row], onDone: loadActivos }) },
            mantenimiento: { label: t('row_actions.maintenance'), icon: <BuildIcon fontSize="small" />,
                onClick: () => navigate(`/mantenimiento?nuevo=${row.ACT_Activo}`) },
            ver_ticket: { label: t('row_actions.open_ticket'), icon: <BuildIcon fontSize="small" />, onClick: () => navigate('/mantenimiento') },
            hoja_vida: { label: t('row_actions.history'), icon: <HistoryIcon fontSize="small" />, onClick: () => handleView(row) },
            etiqueta: { label: t('row_actions.label'), icon: <QrCodeScannerIcon fontSize="small" />, onClick: () => void etiquetaDe(row) },
            baja: { label: t('assets.actions.decommission'), icon: <DeleteOutlineIcon fontSize="small" />,
                onClick: () => setBajaTarget(row), danger: true },
            instalar: { label: t('shared.install'), icon: <PlaceIcon fontSize="small" />, onClick: () => setInstalacion({ modo: 'instalar', activo: row }) },
            reubicar: { label: t('shared.move'), icon: <PlaceIcon fontSize="small" />, onClick: () => setInstalacion({ modo: 'instalar', activo: row }) },
            retirar: { label: t('shared.remove'), icon: <AssignmentReturnIcon fontSize="small" />, onClick: () => setInstalacion({ modo: 'retirar', activo: row }) },
            reportar_perdida: { label: t('row_actions.report_lost'), icon: <DeleteOutlineIcon fontSize="small" />,
                onClick: () => setBajaTarget(row), danger: true },
            completar: { label: t('assets.actions.complete'), icon: <EditIcon fontSize="small" />, onClick: () => navigate('/ingresos/por-completar') },
        };
        const uso = reglasDe(tipos.find((x) => x.TAC_Tipo_Activo === row.TAC_Tipo_Activo)).uso;
        return accionesDisponibles(getEstadoInfo(row.EOP_Estado_Operativo)?.EOP_Nombre, { canOperate, canAdminInventory }, uso, row.pending_completion)
            .filter((a) => a !== 'ver')
            .map((a) => ({ key: a, ...def[a] }));
    };

    const handleDownloadLabels = async () => {
        const ids = [...seleccion.keys()];
        if (ids.length > 200) {
            enqueueSnackbar(t('assets.labels.too_many_selected'), { variant: 'warning' });
            return;
        }
        setDownloadingLabels(true);
        try {
            const blob = await assetService.downloadLabels({ activos_ids: ids }, normalizeLanguage(i18n.resolvedLanguage));
            guardarArchivo(blob, `etiquetas-activos-${ids.length}.pdf`);
            enqueueSnackbar(t('assets.labels.downloaded'), { variant: 'success' });
        } catch (error: unknown) {
            enqueueSnackbar(getApiErrorMessage(error, t('common.error_generic')), { variant: 'error' });
        } finally {
            setDownloadingLabels(false);
        }
    };

    const esAsignado = (a: Activo) => getEstadoInfo(a.EOP_Estado_Operativo)?.EOP_Nombre === 'Asignado';
    const devolverSeleccion = () => {
        const asignados = [...seleccion.values()].filter(esAsignado);
        if (asignados.length === 0) {
            enqueueSnackbar(t('bulk.none_assigned'), { variant: 'info' });
            return;
        }
        abrirDevolucion({ activos: asignados, onDone: tras });
    };

    const exportarSeleccion = () => {
        const celda = (v: string | null | undefined) => `"${String(v ?? '').replace(/"/g, '""')}"`;
        const filas = [
            [t('assets.table.code'), t('assets.table.type'), t('assets.dialog.brand'), t('assets.dialog.model'),
                t('assets.table.serial'), t('assets.table.host'), t('assets.table.status')],
            ...[...seleccion.values()].map((a) => [a.ACT_Codigo_Interno, getTipoName(a.TAC_Tipo_Activo), getMarcaName(a.MOD_Modelo),
                getModeloName(a.MOD_Modelo), a.ACT_Serie_Fabricante, a.ACT_Hostname ?? '',
                stateLabel(t, getEstadoInfo(a.EOP_Estado_Operativo)?.EOP_Nombre)]),
        ];
        const csv = '﻿' + filas.map((f) => f.map(celda).join(',')).join('\r\n');
        guardarArchivo(new Blob([csv], { type: 'text/csv;charset=utf-8' }), `activos-seleccion-${seleccion.size}.csv`);
    };

    // ── Ficha como página (/activos/:codigo); el diálogo queda como vista rápida ──
    const fichaComoPagina = !!codigo && detailOpen && !!selected;
    const presets = useMemo(() => ['En Bodega', 'Asignado', 'En Reparación']
        .map((nombre) => estados.find((e) => e.EOP_Nombre === nombre))
        .filter((e): e is EstadoOperativo => !!e)
        .map((e) => ({ nombre: stateLabel(t, e.EOP_Nombre), query: `estado=${e.EOP_Estado_Operativo}` })), [estados, t]);

    if (fichaComoPagina) {
        return (
            <>
                <AssetDetailDialog asPage open asset={selected} marcas={marcas} modelos={modelos} tipos={tipos} estados={estados}
                    onClose={cerrarFicha} onChanged={loadActivos} />
                <AssetDialog open={editOpen} onClose={(refresh) => { setEditOpen(false); if (refresh) loadActivos(); }}
                    assetToEdit={selected} />
            </>
        );
    }

    return (
        <Box>
            <PageHeader help="assets"
                title={t('assets.title')}
                badge={total}
                crumb={detailOpen && selected ? selected.ACT_Codigo_Interno : undefined}
                actions={(
                    <>
                        {/* En un teléfono la lista son tarjetas: columnas y vistas guardadas solo estorban. */}
                        <Box sx={{ display: { xs: 'none', sm: 'contents' } }}>
                        <TableSettingsMenu columnas={columnasConfigurables} ocultas={tabla.ocultas} densidad={tabla.densidad}
                            onAlternar={tabla.alternarColumna} onDensidad={tabla.cambiarDensidad} onRestablecer={tabla.restablecer} />
                        <SavedViewsMenu storageKey="activos" query={consultaVista} presets={presets}
                            onApply={(query) => setParams(new URLSearchParams(query), { replace: true })} />
                        </Box>
                        {canExport && <ExportMenuButton onExport={(lang) => downloadCsv(`/export/activos.csv?${new URLSearchParams(Object.entries({ q: q.trim(), tipo_activo_id: tipoFilter === 'all' ? '' : String(tipoFilter), estado_operativo_id: estadoFilter === 'all' ? '' : String(estadoFilter) }).filter(([, v]) => v !== '')).toString()}`, 'activos.csv', lang)} />}
                        {canOperate && (
                            <Button variant="outlined" startIcon={<AssignmentIndIcon />} onClick={() => abrirEntrega({ onDone: loadActivos })}>
                                {t('handover.button')}
                            </Button>
                        )}
                        {canAdminInventory && (
                            <Tooltip title={t('shortcuts.hint_new')} describeChild>
                                <Button variant="contained" startIcon={<AddIcon />} onClick={handleNew}>
                                    {t('assets.new_btn')}
                                </Button>
                            </Tooltip>
                        )}
                    </>
                )}
            />

            <CollapsibleToolbar
                storageKey="assets-toolbar"
                summary={activeFilters ? <Chip size="small" color="primary" variant="outlined" label={t('common.active_filters_count', { count: activeFilters })} /> : undefined}
                search={<SearchInput value={searchTerm} onChange={setSearchTerm} placeholder={t('assets.search_placeholder')} />}
            >
                <Box sx={{ display: 'flex', gap: 1.5, flexWrap: 'wrap', alignItems: 'center' }}>
                    <FormControl size="small" sx={{ minWidth: { xs: '100%', sm: 200 } }}>
                        <InputLabel id="filtro-tipo-etiqueta">{t('assets.filter_type')}</InputLabel>
                        <Select labelId="filtro-tipo-etiqueta" label={t('assets.filter_type')} value={tipos.length ? tipoFilter : 'all'}
                            onChange={(e) => setFiltros({ tipo: e.target.value as number | 'all' })}>
                            <MenuItem value="all">{t('assets.all_types')}</MenuItem>
                            {tipos.map((tp) => (
                                <MenuItem key={tp.TAC_Tipo_Activo} value={tp.TAC_Tipo_Activo}>{tp.TAC_Nombre}</MenuItem>
                            ))}
                        </Select>
                    </FormControl>
                    <FormControl size="small" sx={{ minWidth: { xs: '100%', sm: 200 } }}>
                        <InputLabel id="filtro-estado-etiqueta">{t('assets.filter_state')}</InputLabel>
                        <Select labelId="filtro-estado-etiqueta" label={t('assets.filter_state')} value={estados.length ? estadoFilter : 'all'}
                            onChange={(e) => setFiltros({ estado: e.target.value as number | 'all' })}>
                            <MenuItem value="all">{t('assets.all_states')}</MenuItem>
                            {estados.map((e) => (
                                <MenuItem key={e.EOP_Estado_Operativo} value={e.EOP_Estado_Operativo}>{stateLabel(t, e.EOP_Nombre)}</MenuItem>
                            ))}
                        </Select>
                    </FormControl>
                </Box>
            </CollapsibleToolbar>

            <AssetDialog
                open={editOpen}
                assetToEdit={selected && !detailOpen ? selected : null}
                onClose={(refresh) => { setEditOpen(false); if (!detailOpen) setSelected(null); if (refresh) loadActivos(); }}
            />
            <AssetDetailDialog
                open={detailOpen}
                asset={selected}
                marcas={marcas}
                modelos={modelos}
                tipos={tipos}
                estados={estados}
                onClose={cerrarFicha}
                onChanged={loadActivos}
            />

            <DataTableShell
                columns={[
                    {
                        key: 'select',
                        padding: 'checkbox',
                        label: (
                            <Checkbox color="primary" indeterminate={someVisibleSelected} checked={allVisibleSelected}
                                onChange={handleToggleVisibleSelection} inputProps={{ 'aria-label': t('assets.labels.select_visible') }} />
                        ),
                    },
                    { key: 'code', label: t('assets.table.code'), serverSortable: true },
                    { key: 'type', label: t('assets.table.type') },
                    { key: 'brand', label: t('assets.dialog.brand') },
                    { key: 'model', label: t('assets.dialog.model') },
                    { key: 'serial', label: t('assets.table.serial'), serverSortable: true },
                    { key: 'host', label: t('assets.table.host'), serverSortable: true },
                    ...(verCustodio ? [{ key: 'custodian', label: t('assets.table.custodian') }] : []),
                    { key: 'status', label: t('assets.table.status') },
                    { key: 'actions', label: t('assets.table.actions'), align: 'right' },
                ]}
                rows={activos}
                loading={loading}
                hiddenColumns={columnasOcultas}
                sort={orden}
                onSortChange={(s) => { setOrden(s); setFiltros({}, true); }}
                density={tabla.densidad}
                total={total}
                page={page}
                rowsPerPage={rowsPerPage}
                onPageChange={(p) => setFiltros({ p: p || null }, false)}
                onRowsPerPageChange={(n) => setFiltros({ n: n === 25 ? null : n })}
                emptyTitle={activeFilters ? t('bulk.no_matches') : t('assets.table.empty')}
                emptyAction={activeFilters ? { label: t('views.clear'), onClick: () => setParams(new URLSearchParams(), { replace: true }) } : undefined}
                toolbar={selectedCount > 0 ? (
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, flexWrap: 'wrap' }}>
                        <Typography variant="body2" sx={{ fontWeight: 700, mr: 'auto' }}>
                            {t('assets.labels.selected_count', { count: selectedCount })}
                        </Typography>
                        <Button size="small" onClick={limpiarSeleccion}>{t('assets.labels.clear_selection')}</Button>
                        {canOperate && (
                            <Button size="small" variant="contained" startIcon={<AssignmentIndIcon />}
                                onClick={() => abrirEntrega({ activos: [...seleccion.values()], onDone: tras })}>
                                {t('bulk.handover', { count: selectedCount })}
                            </Button>
                        )}
                        {canOperate && (
                            <Button size="small" variant="outlined" startIcon={<AssignmentReturnIcon />} onClick={devolverSeleccion}>
                                {t('bulk.return')}
                            </Button>
                        )}
                        {canOperate && (
                            <Button size="small" variant="outlined" onClick={handleDownloadLabels} disabled={downloadingLabels}
                                startIcon={downloadingLabels ? <CircularProgress size={16} color="inherit" /> : <QrCodeScannerIcon />}>
                                {t('bulk.labels')}
                            </Button>
                        )}
                        {canExport && (
                            <Button size="small" variant="outlined" startIcon={<FileDownloadIcon />} onClick={exportarSeleccion}>
                                {t('bulk.export')}
                            </Button>
                        )}
                        {canAdminInventory && (
                            <Button size="small" variant="outlined" startIcon={<EditNoteIcon />} onClick={() => setEdicionLote([...seleccion.values()])}>
                                {t('bulk.edit')}
                            </Button>
                        )}
                        {canAdminInventory && (
                            <Button size="small" color="error" startIcon={<DeleteOutlineIcon />} onClick={() => setBajaLote([...seleccion.values()])}>
                                {t('bulk.decommission')}
                            </Button>
                        )}
                    </Box>
                ) : undefined}
                renderRow={(row) => {
                    const isSelected = seleccion.has(row.ACT_Activo);
                    return (
                        <TableRow key={row.ACT_Activo} hover selected={isSelected} sx={{ cursor: 'pointer' }}
                            onClick={(e) => { if (!(e.target as HTMLElement).closest('button, input')) handleView(row); }}>
                            <TableCell padding="checkbox">
                                <Checkbox color="primary" checked={isSelected} onChange={() => handleToggleAssetSelection(row)}
                                    inputProps={{ 'aria-label': t('assets.labels.select_asset', { code: row.ACT_Codigo_Interno }) }} />
                            </TableCell>
                            <TableCell sx={{ fontWeight: 'bold' }}>{row.ACT_Codigo_Interno}</TableCell>
                            <TableCell>{getTipoName(row.TAC_Tipo_Activo)}</TableCell>
                            <TableCell>{getMarcaName(row.MOD_Modelo)}</TableCell>
                            <TableCell>{getModeloName(row.MOD_Modelo)}</TableCell>
                            <TableCell>{serialLabel(row.ACT_Serie_Fabricante, t('assets.table.no_serial'))}</TableCell>
                            <TableCell>{row.ACT_Hostname || '—'}</TableCell>
                            {verCustodio && <TableCell>{row.custodio || '—'}</TableCell>}
                            <TableCell>
                                <StatusChip label={stateLabel(t, getEstadoInfo(row.EOP_Estado_Operativo)?.EOP_Nombre)} variant={stateVariant(getEstadoInfo(row.EOP_Estado_Operativo)?.EOP_Nombre)} />
                            </TableCell>
                            <TableCell align="right">
                                <Tooltip title={t('common.view') as string}>
                                    <IconButton size="small" onClick={() => handleView(row)} aria-label={t('common.view')}>
                                        <VisibilityIcon fontSize="small" />
                                    </IconButton>
                                </Tooltip>
                                <RowActionsMenu actions={accionesFila(row)} label={t('row_actions.menu', { code: row.ACT_Codigo_Interno })} />
                            </TableCell>
                        </TableRow>
                    );
                }}
            />
            <BajaDialog open={!!bajaTarget} activo={bajaTarget} lostOnly={catalogSlug(getEstadoInfo(bajaTarget?.EOP_Estado_Operativo ?? 0)?.EOP_Nombre ?? '') === 'asignado'} onClose={(changed) => { setBajaTarget(null); if (changed) loadActivos(); }} />
            <InstalacionAreaDialog modo={instalacion?.modo ?? null} activo={instalacion?.activo ?? null}
                onClose={(changed) => { setInstalacion(null); if (changed) loadActivos(); }} />
            <BatchEditDialog open={!!edicionLote} activos={edicionLote ?? []}
                onClose={(changed) => { setEdicionLote(null); if (changed) loadActivos(); }} />
            <BajaDialog open={!!bajaLote} activo={null} activos={bajaLote ?? []} onClose={(changed) => { setBajaLote(null); if (changed) tras(); }} />
        </Box>
    );
};
