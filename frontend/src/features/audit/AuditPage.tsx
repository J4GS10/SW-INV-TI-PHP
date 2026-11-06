import { useCallback, useEffect, useState } from 'react';
import { Box, Button, Chip, IconButton, MenuItem, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField, Tooltip, Typography } from '@mui/material';
import { PageHeader, DataTableShell } from '../../components/ui';
import { alpha } from '@mui/material/styles';
import type { SxProps, Theme } from '@mui/material/styles';
import RefreshIcon from '@mui/icons-material/Refresh';
import VisibilityIcon from '@mui/icons-material/Visibility';
import { useTranslation } from 'react-i18next';
import { rowLink } from '../../components/ui/rowLink';
import { useSnackbar } from 'notistack';
import { AuditEvent, auditService } from './auditService';
import type { ChipColor } from '../../types/ui';
import { ExportMenuButton } from '../../components/ExportMenuButton';
import { downloadCsv } from '../../api/downloadCsv';
import type { SupportedLanguage } from '../../types/language';
import { CollapsibleToolbar } from '../../components/CollapsibleToolbar';
import { Dialog } from '../../components/ui/Dialog';
const ACCIONES = [
    '', 'CREATE', 'UPDATE', 'DELETE', 'DELETE_LOGIC',
    'ASSIGN', 'RETURN', 'TRANSFER', 'INSTALL', 'UNINSTALL',
    'OFFBOARDING', 'PASSWORD_CHANGE', 'CLOSE',
    'STOCK_IN', 'STOCK_OUT',
    'LOGIN_SUCCESS', 'LOGIN_FAILED', 'LOGOUT', 'LOGOUT_ALL', 'PASSWORD_RESET_REQUEST', 'PASSWORD_RESET',
    'PASSWORD_RESET_BY_ADMIN', '2FA_RESET_BY_ADMIN', 'ACCOUNT_LOCKED', 'ACCOUNT_UNLOCKED_BY_ADMIN', 'SESSIONS_REVOKED_BY_ADMIN',
    'REFRESH_TOKEN_REUSE',
    'RECEIVE', 'CLOSE_WITH_SHORTAGE', 'COMPONENT_INSTALL', 'COMPONENT_REMOVE', 'STOCK_ADJUST',
    'INVENTORY_COUNT_OPEN', 'INVENTORY_COUNT_CLOSE', 'INVENTORY_COUNT_CANCEL',
    'ASSET_SEDE_CHANGE', 'VIEW_KEYS', 'DATA_FIX', 'AD_SYNC', 'AUDIT_EXPORT',
    'APPROVAL_REQUEST', 'APPROVAL_APPROVE', 'APPROVAL_REJECT', 'APPROVAL_CANCEL',
];
const ENTIDADES = [
    '', 'INV_ACTIVO', 'INV_MOVIMIENTO', 'INV_INSTALACION', 'INV_LICENCIA',
    'INV_SOFTWARE', 'INV_MANTENIMIENTO', 'INV_DETALLE_MANT',
    'INV_PERSONA', 'INV_USUARIO', 'INV_DEPARTAMENTO', 'INV_CARGO',
    'INV_MARCA', 'INV_MODELO', 'INV_TIPO_ACTIVO',
    'INV_PAIS', 'INV_ESTADO', 'INV_MUNICIPIO', 'INV_SEDE',
    'INV_EDIFICIO', 'INV_NIVEL', 'INV_AREA', 'SYS_CONFIGURACION',
    'INV_CONSUMIBLE', 'INV_ADJUNTO', 'INV_PROVEEDOR', 'INV_ORDEN_COMPRA',
    'INV_ESPECIFICACION', 'INV_LICENCIA_CLAVE', 'INV_TOMA_INVENTARIO', 'INV_TIPO_LICENCIA',
    'INV_TIPO_MANTENIMIENTO', 'INV_ESTADO_OPERATIVO', 'INV_APROBACION',
];
const formatAuditDate = (value: string) => {
    const date = new Date(value);
    if (Number.isNaN(date.getTime()))
        return value;
    return date.toLocaleString();
};
const formatSnapshotValue = (value: unknown) => {
    if (value == null || value === '')
        return '—';
    if (typeof value === 'object')
        return JSON.stringify(value);
    return String(value);
};
const evidenceBoxSx: SxProps<Theme> = (theme) => ({
    mt: 1,
    p: 2,
    border: '1px solid',
    borderColor: 'divider',
    borderRadius: 2,
    bgcolor: alpha(theme.palette.background.default, theme.palette.mode === 'light' ? 0.68 : 0.5),
    color: 'text.primary',
    fontFamily: '"Roboto Mono", "Consolas", monospace',
    fontSize: '0.78rem',
    lineHeight: 1.55,
    overflow: 'auto',
    maxHeight: 360,
    whiteSpace: 'pre-wrap',
    wordBreak: 'break-word',
});
export const AuditPage = () => {
    const { t } = useTranslation();
    const { enqueueSnackbar } = useSnackbar();
    const [items, setItems] = useState<AuditEvent[]>([]);
    const [total, setTotal] = useState(0);
    const [page, setPage] = useState(0);
    const [rowsPerPage, setRowsPerPage] = useState(50);
    const [accion, setAccion] = useState('');
    const [entidad, setEntidad] = useState('');
    const [fromDate, setFromDate] = useState('');
    const [toDate, setToDate] = useState('');
    const [loading, setLoading] = useState(true);
    const [selected, setSelected] = useState<AuditEvent | null>(null);
    const load = useCallback(async () => {
        setLoading(true);
        try {
            const data = await auditService.list({
                skip: page * rowsPerPage,
                limit: rowsPerPage,
                accion: accion || undefined,
                entidad: entidad || undefined,
                from_date: fromDate || undefined,
                to_date: toDate || undefined,
            });
            setItems(data.items);
            setTotal(data.total);
        }
        catch {
            enqueueSnackbar(t('common.error_loading'), { variant: 'error' });
        }
        finally {
            setLoading(false);
        }
    }, [accion, entidad, enqueueSnackbar, fromDate, page, rowsPerPage, t, toDate]);
    useEffect(() => { load(); }, [load]);
    // Exportación del servidor: todo el rango filtrado (no solo la página visible),
    // limitada al alcance del lector y registrada en la propia bitácora.
    const exportCSV = async (exportLanguage: SupportedLanguage) => {
        const params = new URLSearchParams();
        if (fromDate) params.set('from_date', fromDate);
        if (toDate) params.set('to_date', toDate);
        // Exporta exactamente lo filtrado en pantalla.
        if (accion) params.set('accion', accion);
        if (entidad) params.set('entidad', entidad);
        const query = params.toString();
        try {
            await downloadCsv(`/gov/auditoria.csv${query ? `?${query}` : ''}`, `auditoria_${new Date().toISOString().slice(0, 10)}.csv`, exportLanguage);
        }
        catch {
            enqueueSnackbar(t('common.error_generic'), { variant: 'error' });
        }
    };
    const accionLabel = (a: string) => t(`audit.actions.${a}`, { defaultValue: a });
    const entidadLabel = (e: string) => t(`audit.entities.${e}`, { defaultValue: e });
    const colorFor = (acc: string): ChipColor => {
        if (acc.startsWith('DELETE') || acc === 'OFFBOARDING')
            return 'error';
        if (acc === 'CREATE' || acc === 'ASSIGN' || acc === 'INSTALL' || acc === 'STOCK_IN')
            return 'success';
        if (acc === 'UPDATE' || acc === 'CLOSE')
            return 'primary';
        if (acc === 'PASSWORD_CHANGE' || acc === 'STOCK_OUT')
            return 'warning';
        return 'default';
    };
    const activeFilters = [accion, entidad, fromDate, toDate].filter(Boolean).length;
    const filterSx = { minWidth: { xs: '100%', sm: 180 } };
    return (<Box>
      <PageHeader
        title={t('audit.title')}
        subtitle={t('audit.subtitle')}
        badge={total}
        actions={<>
          <Tooltip title={t('audit.refresh')}>
            <IconButton onClick={load} aria-label={t('audit.refresh')}>
              <RefreshIcon />
            </IconButton>
          </Tooltip>
          <ExportMenuButton onExport={exportCSV} disabled={items.length === 0}/>
        </>}
      />

      <CollapsibleToolbar
        storageKey="audit"
        summary={activeFilters > 0 && <Chip size="small" color="primary" variant="outlined" label={t('common.active_filters_count', { count: activeFilters })}/>}
      >
        <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1.5, alignItems: 'center' }}>
          <TextField select size="small" label={t('audit.action')} value={accion} onChange={(e) => { setAccion(e.target.value); setPage(0); }} sx={filterSx}>
            {ACCIONES.map((a) => <MenuItem key={a || 'all'} value={a}>{a ? accionLabel(a) : t('audit.all')}</MenuItem>)}
          </TextField>
          <TextField select size="small" label={t('audit.entity')} value={entidad} onChange={(e) => { setEntidad(e.target.value); setPage(0); }} sx={filterSx}>
            {ENTIDADES.map((e) => <MenuItem key={e || 'all'} value={e}>{e ? entidadLabel(e) : t('audit.all')}</MenuItem>)}
          </TextField>
          <TextField size="small" type="datetime-local" label={t('audit.from')} value={fromDate} onChange={(e) => { setFromDate(e.target.value); setPage(0); }} InputLabelProps={{ shrink: true }} sx={filterSx}/>
          <TextField size="small" type="datetime-local" label={t('audit.to')} value={toDate} onChange={(e) => { setToDate(e.target.value); setPage(0); }} InputLabelProps={{ shrink: true }} sx={filterSx}/>
        </Box>
      </CollapsibleToolbar>

      <DataTableShell
        columns={[
          { key: 'date', label: t('audit.col_date') },
          { key: 'action', label: t('audit.action') },
          { key: 'entity', label: t('audit.entity') },
          { key: 'ip', label: t('audit.col_ip') },
          { key: 'user', label: t('audit.col_user') },
          { key: 'actions', label: t('common.actions'), align: 'right' },
        ]}
        rows={items}
        loading={loading}
        total={total}
        page={page}
        rowsPerPage={rowsPerPage}
        onPageChange={setPage}
        onRowsPerPageChange={(r) => { setRowsPerPage(r); setPage(0); }}
        emptyTitle={t('audit.empty')}
        renderRow={(ev) => (<TableRow key={ev.AUD_Auditoria} hover {...rowLink(() => setSelected(ev))} sx={{ cursor: 'pointer', '&:focus-visible': { outline: '2px solid', outlineColor: 'primary.main', outlineOffset: -2 } }} tabIndex={0} onKeyDown={(event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); setSelected(ev); } }}>
            <TableCell sx={{ fontFamily: '"Roboto Mono", "Consolas", monospace', fontSize: '0.8rem', whiteSpace: 'nowrap' }}>
              {formatAuditDate(ev.AUD_Fecha_Hora)}
            </TableCell>
            <TableCell><Tooltip title={ev.AUD_Accion}><Chip label={accionLabel(ev.AUD_Accion)} size="small" color={colorFor(ev.AUD_Accion)}/></Tooltip></TableCell>
            <TableCell>
              <Typography variant="body2" sx={{ fontFamily: '"Roboto Mono", "Consolas", monospace', overflowWrap: 'anywhere' }}>
                {entidadLabel(ev.AUD_Entidad_Afectada)}
              </Typography>
            </TableCell>
            <TableCell sx={{ fontFamily: '"Roboto Mono", "Consolas", monospace', fontSize: '0.8rem' }}>
              {ev.AUD_IP_Origen ?? '—'}
            </TableCell>
            <TableCell sx={{ fontFamily: '"Roboto Mono", "Consolas", monospace', fontSize: '0.8rem', overflowWrap: 'anywhere' }}>
              {ev.usuario_nombre || ev.USU_Usuario || t('audit.system_user')}
            </TableCell>
            <TableCell align="right">
              <Tooltip title={t('audit.view_snapshot') as string}>
                <IconButton size="small" onClick={(event) => { event.stopPropagation(); setSelected(ev); }} aria-label={t('audit.view_snapshot')}>
                  <VisibilityIcon fontSize="small"/>
                </IconButton>
              </Tooltip>
            </TableCell>
          </TableRow>)}
      />

      {selected && (<Dialog open onClose={() => setSelected(null)} fullWidth maxWidth="md" scroll="paper">
        <Box sx={{ p: { xs: 2, sm: 3 }, bgcolor: 'background.paper' }}>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} alignItems={{ xs: 'flex-start', sm: 'center' }} justifyContent="space-between">
            <Box>
              <Typography variant="subtitle1">
                {t('audit.snapshot_for')} {entidadLabel(selected.AUD_Entidad_Afectada)}
              </Typography>
              <Typography variant="caption" color="text.secondary">
                {formatAuditDate(selected.AUD_Fecha_Hora)}
              </Typography>
            </Box>
            <Chip label={accionLabel(selected.AUD_Accion)} size="small" color={colorFor(selected.AUD_Accion)}/>
          </Stack>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} flexWrap="wrap" useFlexGap sx={{ mt: 1.5 }}>
            <Chip variant="outlined" label={`${t('audit.col_ip')}: ${selected.AUD_IP_Origen ?? '—'}`}/>
            <Chip variant="outlined" label={`${t('audit.col_user')}: ${selected.usuario_nombre || selected.USU_Usuario || t('audit.system_user')}`}/>
          </Stack>
          {selected.AUD_User_Agent && (<Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1, overflowWrap: 'anywhere' }}>
              {t('audit.user_agent')}: {selected.AUD_User_Agent}
            </Typography>)}

          {selected.AUD_Snapshot_JSON?.diff && Object.keys(selected.AUD_Snapshot_JSON.diff).length > 0 && (<TableContainer component={Box} sx={{ mt: 1.5 }}>
              <Table size="small" aria-label={t('audit.diff')}>
                <TableHead>
                  <TableRow>
                    <TableCell>{t('audit.field')}</TableCell>
                    <TableCell>{t('audit.before')}</TableCell>
                    <TableCell>{t('audit.after')}</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {Object.entries(selected.AUD_Snapshot_JSON.diff as Record<string, {
                    antes: unknown;
                    despues: unknown;
                }>)
                    .map(([campo, { antes, despues }]) => (<TableRow key={campo}>
                        <TableCell sx={{ fontWeight: 700 }}>{campo}</TableCell>
                        <TableCell sx={{ overflowWrap: 'anywhere' }}>{formatSnapshotValue(antes)}</TableCell>
                        <TableCell sx={{ overflowWrap: 'anywhere' }}>{formatSnapshotValue(despues)}</TableCell>
                      </TableRow>))}
                </TableBody>
              </Table>
            </TableContainer>)}

          <Typography variant="subtitle2" sx={{ mt: 2 }}>
            {t('audit.raw_snapshot')}
          </Typography>
          <Box component="pre" sx={evidenceBoxSx}>
            {JSON.stringify(selected.AUD_Snapshot_JSON ?? {}, null, 2)}
          </Box>
          <Button size="small" onClick={() => setSelected(null)} sx={{ mt: 2 }}>
            {t('common.close')}
          </Button>
        </Box>
      </Dialog>)}
    </Box>);
};
