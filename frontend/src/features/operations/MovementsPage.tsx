import { useCallback, useEffect, useState } from 'react';
import { Stack, Box, TableCell, TableRow, Button, Chip, CircularProgress, Tooltip, Checkbox, Select, MenuItem, FormControl, InputLabel, Typography } from '@mui/material';
import SwapHorizIcon from '@mui/icons-material/SwapHoriz';
import DescriptionIcon from '@mui/icons-material/Description';
import PrintIcon from '@mui/icons-material/Print';
import PictureAsPdfIcon from '@mui/icons-material/PictureAsPdf';
import AssignmentReturnIcon from '@mui/icons-material/AssignmentReturn';
import { ActaDescargoDialog } from './ActaDescargoDialog';
import { useTranslation } from 'react-i18next';
import { RowActionsMenu } from '../../components/RowActionsMenu';
import { movementTypeLabel } from '../../utils/catalogLabels';
import { useSnackbar } from 'notistack';
import { getApiErrorDetail } from '../../api/errors';
import { movementService } from './movementService';
import type { Movimiento } from '../../types/traceability';
import { AssignmentDialog } from './AssignmentDialog';
import { DevolucionDialog } from './DevolucionDialog';
import UndoIcon from '@mui/icons-material/Undo';
import { formatDate } from '../../utils/dates';
import { ExportMenuButton } from '../../components/ExportMenuButton';
import { usePermissions } from '../../hooks/usePermissions';
import { SearchInput } from '../../components/SearchInput';
import { normalizeLanguage } from '../../types/language';
import { downloadCsv } from '../../api/downloadCsv';
import { CollapsibleToolbar } from '../../components/CollapsibleToolbar';
import { PageHeader, StatusChip, DataTableShell } from '../../components/ui';
import AssignmentIndIcon from '@mui/icons-material/AssignmentInd';
import { useSearchParams } from 'react-router-dom';
import { useAccionPagina, useWorkspace } from '../../context/workspace-context';
const SELECTION_COLUMN = new Set(['select']);

export const MovementsPage = () => {
    const { t, i18n } = useTranslation();
    const { canOperate, canExport } = usePermissions();
    const { enqueueSnackbar } = useSnackbar();
    const [movimientos, setMovimientos] = useState<Movimiento[]>([]);
    const [loading, setLoading] = useState(true);
    const [isDialogOpen, setIsDialogOpen] = useState(false);
    const [devolucion, setDevolucion] = useState<Movimiento | null>(null);
    // Filtros en la URL: se comparten con un enlace y sobreviven a la navegación.
    const [params, setParams] = useSearchParams();
    const { abrirEntrega } = useWorkspace();
    const setParam = useCallback((k: string, v: string) => setParams((prev) => {
        const next = new URLSearchParams(prev);
        if (v && v !== 'all') next.set(k, v); else next.delete(k);
        return next;
    }, { replace: true }), [setParams]);
    const searchTerm = params.get('q') ?? '';
    const setSearchTerm = useCallback((v: string) => setParam('q', v), [setParam]);
    const tipoFiltro = params.get('tipo') ?? 'all';
    const setTipoFiltro = (v: string) => setParam('tipo', v);
    const estadoFiltro = (params.get('estado') ?? 'all') as 'all' | 'vigente' | 'cerrado';
    const setEstadoFiltro = (v: 'all' | 'vigente' | 'cerrado') => setParam('estado', v);
    // --- ESTADOS DE PRODUCCIÓN (Mantenidos para funcionalidad de Lote) ---
    const [selected, setSelected] = useState<string[]>([]);
    const [downloading, setDownloading] = useState(false);
    const [descargo, setDescargo] = useState<{
        open: boolean;
        id: string | null;
        codigo: string;
    }>({ open: false, id: null, codigo: '' });
    // Las actas salen en el idioma de la interfaz (el diálogo de descargo permite elegir otro).
    const documentLanguage = normalizeLanguage(i18n.resolvedLanguage ?? i18n.language);
    const loadData = useCallback(async () => {
        setLoading(true);
        try {
            const data = await movementService.getMovements();
            setMovimientos(data);
        }
        catch (error) {
            console.error(error);
            enqueueSnackbar(t('common.error_loading'), { variant: 'error' });
        }
        finally {
            setLoading(false);
        }
    }, [enqueueSnackbar, t]);
    useEffect(() => {
        loadData();
    }, [loadData]);
    useAccionPagina('nuevo', canOperate ? () => setIsDialogOpen(true) : null);
    // «?entrega=1» abre la entrega de puesto (enlace desde otras pantallas).
    useEffect(() => {
        if (params.get('entrega') && canOperate) {
            abrirEntrega({ onDone: loadData });
            setParam('entrega', '');
        }
    }, [params, canOperate, abrirEntrega, loadData, setParam]);
    // Tipos de movimiento presentes (para el filtro de bitácora).
    const tiposPresentes = Array.from(new Set(movimientos.map((m) => m.tipo_movimiento?.TMO_Nombre).filter(Boolean) as string[])).sort();
    // --- LÓGICA DE FILTRADO (búsqueda + tipo + estado del movimiento) ---
    const filteredMovs = movimientos.filter(m => {
        const search = searchTerm.toLowerCase();
        const codigo = m.activo?.ACT_Codigo_Interno?.toLowerCase() || '';
        const persona = m.persona
            ? `${m.persona.PER_Primer_Nombre} ${m.persona.PER_Primer_Apellido}`.toLowerCase()
            : '';
        const matchSearch = codigo.includes(search) || persona.includes(search);
        const matchTipo = tipoFiltro === 'all' || m.tipo_movimiento?.TMO_Nombre === tipoFiltro;
        const vigente = !m.MOV_Fecha_Devolucion;
        const matchEstado = estadoFiltro === 'all'
            || (estadoFiltro === 'vigente' ? vigente : !vigente);
        return matchSearch && matchTipo && matchEstado;
    });
    const activeFilters = [
        searchTerm.trim(),
        tipoFiltro !== 'all',
        estadoFiltro !== 'all',
    ].filter(Boolean).length;
    // --- LÓGICA DE SELECCIÓN (Mantenida de Producción) ---
    const handleSelectAllClick = (event: React.ChangeEvent<HTMLInputElement>) => {
        if (event.target.checked) {
            const newSelected = filteredMovs.map((n) => n.MOV_Movimiento);
            setSelected(newSelected);
            return;
        }
        setSelected([]);
    };
    const handleClick = (_event: React.MouseEvent<unknown>, id: string) => {
        const selectedIndex = selected.indexOf(id);
        let newSelected: string[] = [];
        if (selectedIndex === -1) {
            newSelected = newSelected.concat(selected, id);
        }
        else if (selectedIndex === 0) {
            newSelected = newSelected.concat(selected.slice(1));
        }
        else if (selectedIndex === selected.length - 1) {
            newSelected = newSelected.concat(selected.slice(0, -1));
        }
        else if (selectedIndex > 0) {
            newSelected = newSelected.concat(selected.slice(0, selectedIndex), selected.slice(selectedIndex + 1));
        }
        setSelected(newSelected);
    };
    const isSelected = (id: string) => selected.indexOf(id) !== -1;
    // --- LÓGICA DE DESCARGAS (Helper Mantenido) ---
    const downloadBlob = (blob: Blob, filename: string) => {
        const url = window.URL.createObjectURL(new Blob([blob]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', filename);
        document.body.appendChild(link);
        link.click();
        link.parentNode?.removeChild(link);
    };
    // Descarga Individual (Word o PDF; entrega o descargo/devolución)
    const handleDownloadIndividual = async (id: string, codigo: string, formato: 'docx' | 'pdf' = 'docx', tipo: 'entrega' | 'descargo' = 'entrega') => {
        try {
            const blob = await movementService.downloadActa(id, formato, tipo, undefined, documentLanguage);
            const fecha = new Date().toISOString().split('T')[0];
            const nombre = tipo === 'descargo' ? 'Descargo' : 'Acta';
            downloadBlob(blob, `${nombre}_${codigo}_${fecha}.${formato}`);
            enqueueSnackbar(t('operations.messages.doc_generated'), { variant: 'success' });
        }
        catch {
            enqueueSnackbar(t('common.error_generic'), { variant: 'error' });
        }
    };
    // Descarga por Lote (Word o PDF)
    const handleDownloadLote = async (formato: 'docx' | 'pdf' = 'docx') => {
        if (selected.length === 0)
            return;
        setDownloading(true);
        try {
            const blob = await movementService.downloadActaLote(selected, formato, 'entrega', documentLanguage);
            const fecha = new Date().toISOString().split('T')[0];
            downloadBlob(blob, `Acta_Unificada_${fecha}.${formato}`);
            enqueueSnackbar(t('operations.messages.batch_success'), { variant: 'success' });
            setSelected([]);
        }
        catch (error: unknown) {
            console.error(error);
            const msg = getApiErrorDetail(error);
            if (msg === 'CANNOT_MIX_DIFFERENT_PEOPLE_IN_SAME_DOCUMENT') {
                enqueueSnackbar(t('operations.messages.error_mix_people'), { variant: 'error' });
            }
            else {
                enqueueSnackbar(t('common.error_generic'), { variant: 'error' });
            }
        }
        finally {
            setDownloading(false);
        }
    };
    return (<Box>
      <PageHeader help="movements"
        title={t('operations.title')}
        badge={filteredMovs.length}
        actions={<>
          {canExport && <ExportMenuButton onExport={(lang) => downloadCsv(`/export/movimientos.csv?${new URLSearchParams(Object.entries({ q: searchTerm.trim(), tipo: tipoFiltro === 'all' ? '' : tipoFiltro, estado: estadoFiltro === 'all' ? '' : estadoFiltro }).filter(([, v]) => v !== '')).toString()}`, 'movimientos.csv', lang)}/>}
          {canOperate && (<Tooltip title={t('handover.loan_help')} describeChild><Button variant="outlined" startIcon={<SwapHorizIcon />} onClick={() => setIsDialogOpen(true)}>
            {t('operations.new_btn')}
          </Button></Tooltip>)}
          {canOperate && (<Tooltip title={t('handover.button_help')} describeChild><Button variant="contained" startIcon={<AssignmentIndIcon />} onClick={() => abrirEntrega({ onDone: loadData })}>
            {t('handover.button')}
          </Button></Tooltip>)}
        </>}
      />

      <CollapsibleToolbar storageKey="movements-toolbar" summary={activeFilters ? <Chip size="small" color="primary" variant="outlined" label={t('common.active_filters_count', { count: activeFilters })}/> : undefined} search={<SearchInput value={searchTerm} onChange={setSearchTerm}/>}>
        <Box sx={{ display: 'flex', gap: 1.5, flexWrap: 'wrap', alignItems: 'center' }}>
          <FormControl size="small" sx={{ minWidth: { xs: '100%', sm: 200 } }}>
            <InputLabel id="mov-filtro-tipo">{t('operations.filter_type')}</InputLabel>
            <Select labelId="mov-filtro-tipo" label={t('operations.filter_type')} value={tipoFiltro} onChange={(e) => setTipoFiltro(e.target.value)}>
              <MenuItem value="all">{t('common.all')}</MenuItem>
              {tiposPresentes.map((tp) => (<MenuItem key={tp} value={tp}>{movementTypeLabel(t, tp)}</MenuItem>))}
            </Select>
          </FormControl>
          <FormControl size="small" sx={{ minWidth: { xs: '100%', sm: 180 } }}>
            <InputLabel id="mov-filtro-estado">{t('operations.filter_status')}</InputLabel>
            <Select labelId="mov-filtro-estado" label={t('operations.filter_status')} value={estadoFiltro} onChange={(e) => setEstadoFiltro(e.target.value as 'all' | 'vigente' | 'cerrado')}>
              <MenuItem value="all">{t('common.all')}</MenuItem>
              <MenuItem value="vigente">{t('common.active')}</MenuItem>
              <MenuItem value="cerrado">{t('common.closed')}</MenuItem>
            </Select>
          </FormControl>
        </Box>
      </CollapsibleToolbar>

      <DataTableShell
        hiddenColumns={canOperate ? undefined : SELECTION_COLUMN}
        toolbar={canOperate && selected.length > 0 ? (<Box sx={{ display: 'flex', alignItems: 'center', gap: 1, flexWrap: 'wrap' }}>
            <Typography variant="body2" sx={{ fontWeight: 700, mr: 'auto' }}>
              {t('operations.selected_count', { count: selected.length })}
            </Typography>
            <Button size="small" onClick={() => setSelected([])}>{t('common.clear_selection')}</Button>
            <Button size="small" variant="outlined" startIcon={<PictureAsPdfIcon />} onClick={() => handleDownloadLote('pdf')} disabled={downloading}>
              {t('operations.batch_btn_pdf')}
            </Button>
            <Button size="small" variant="contained" startIcon={downloading ? <CircularProgress size={16} color="inherit"/> : <PrintIcon />} onClick={() => handleDownloadLote('docx')} disabled={downloading}>
              {t('operations.batch_btn_word')}
            </Button>
          </Box>) : undefined}
        columns={[
            {
                key: 'select',
                padding: 'checkbox',
                label: (<Checkbox color="primary" indeterminate={selected.length > 0 && selected.length < filteredMovs.length} checked={filteredMovs.length > 0 && selected.length === filteredMovs.length} onChange={handleSelectAllClick} inputProps={{ 'aria-label': t('common.select_all') }}/>),
            },
            { key: 'date', label: t('operations.table.date'), sortValue: (r) => r.MOV_Fecha_Asignacion },
            { key: 'type', label: t('operations.table.type'), sortValue: (r) => movementTypeLabel(t, r.tipo_movimiento?.TMO_Nombre) },
            { key: 'asset', label: t('operations.table.asset'), sortValue: (r) => r.activo?.ACT_Codigo_Interno },
            { key: 'responsible', label: t('operations.table.responsible'), sortValue: (r) => (r.persona ? `${r.persona.PER_Primer_Nombre} ${r.persona.PER_Primer_Apellido}` : null) },
            { key: 'status', label: t('operations.table.status'), sortValue: (r) => (r.MOV_Fecha_Devolucion ? 1 : 0) },
            { key: 'doc', label: t('operations.table.doc'), align: 'right' },
        ]}
        rows={filteredMovs}
        loading={loading}
        renderRow={(row) => {
            const isItemSelected = isSelected(row.MOV_Movimiento);
            const isVigente = !row.MOV_Fecha_Devolucion;
            const tipoNombre = movementTypeLabel(t, row.tipo_movimiento?.TMO_Nombre);
            return (<TableRow key={row.MOV_Movimiento} hover selected={isItemSelected} sx={{ '&:hover': { bgcolor: 'action.hover' } }}>
                  {/* CHECKBOX CELL (Mantenido) */}
                  <TableCell padding="checkbox">
                    <Checkbox color="primary" checked={isItemSelected} onClick={(event) => handleClick(event, row.MOV_Movimiento)} inputProps={{ 'aria-label': t('operations.select_movement', { code: row.activo?.ACT_Codigo_Interno ?? row.MOV_Movimiento }) }} sx={{ color: 'text.secondary', '&.Mui-checked': { color: 'primary.main' } }}/>
                  </TableCell>

                  <TableCell sx={{ color: 'text.primary' }}>
                    {formatDate(row.MOV_Fecha_Asignacion, i18n.language)}
                  </TableCell>
                  <TableCell sx={{ color: 'text.primary' }}>
                    {tipoNombre}
                  </TableCell>
                  <TableCell sx={{ color: 'text.primary', fontWeight: 'bold' }}>
                    {row.activo?.ACT_Codigo_Interno || '---'}
                  </TableCell>
                  <TableCell sx={{ color: 'text.primary' }}>
                    {row.persona ? `${row.persona.PER_Primer_Nombre} ${row.persona.PER_Primer_Apellido}` : '---'}
                  </TableCell>
                  <TableCell>
                    <StatusChip label={isVigente ? t('common.active') : t('common.closed')} variant={isVigente ? 'activo' : 'cerrado'} />
                  </TableCell>
                  <TableCell align="right">
                    {canOperate && (<Stack direction="row" spacing={0.5} justifyContent="flex-end" alignItems="center">
                      {isVigente && <Button size="small" startIcon={<UndoIcon fontSize="small"/>} onClick={() => setDevolucion(row)}>{t('assets.actions.return')}</Button>}
                      <RowActionsMenu actions={[
                        { key: 'word', label: t('operations.download_word'), icon: <DescriptionIcon fontSize="small"/>, onClick: () => { void handleDownloadIndividual(row.MOV_Movimiento, row.activo?.ACT_Codigo_Interno || 'DOC', 'docx'); } },
                        { key: 'pdf', label: t('operations.download_pdf'), icon: <PictureAsPdfIcon fontSize="small"/>, onClick: () => { void handleDownloadIndividual(row.MOV_Movimiento, row.activo?.ACT_Codigo_Interno || 'DOC', 'pdf'); } },
                        ...(!isVigente ? [{ key: 'descargo', label: t('operations.download_descargo'), icon: <AssignmentReturnIcon fontSize="small"/>, onClick: () => setDescargo({ open: true, id: row.MOV_Movimiento, codigo: row.activo?.ACT_Codigo_Interno || 'DOC' }) }] : []),
                      ]}/>
                    </Stack>)}
                  </TableCell>
                </TableRow>);
        }}
      />

      <AssignmentDialog open={isDialogOpen} onClose={(refresh) => {
            setIsDialogOpen(false);
            if (refresh)
                loadData();
        }}/>

      <DevolucionDialog open={!!devolucion} activo={devolucion ? { ACT_Activo: devolucion.ACT_Activo, ACT_Codigo_Interno: devolucion.activo?.ACT_Codigo_Interno ?? '' } : null} custodio={devolucion?.persona ? `${devolucion.persona.PER_Primer_Nombre} ${devolucion.persona.PER_Primer_Apellido}` : null} onClose={(changed) => {
            setDevolucion(null);
            if (changed)
                loadData();
        }}/>

      <ActaDescargoDialog open={descargo.open} movimientoId={descargo.id} codigo={descargo.codigo} defaultLanguage={documentLanguage} onClose={() => setDescargo({ open: false, id: null, codigo: '' })}/>
    </Box>);
};
