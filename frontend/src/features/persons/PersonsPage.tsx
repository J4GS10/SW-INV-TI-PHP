import { useCallback, useEffect, useMemo, useState } from 'react';
import { Stack, Box, Button, Chip, TableCell, TableRow, Tooltip, Typography, } from '@mui/material';
import VisibilityIcon from '@mui/icons-material/Visibility';
import EditIcon from '@mui/icons-material/Edit';
import LogoutIcon from '@mui/icons-material/Logout';
import HowToRegIcon from '@mui/icons-material/HowToReg';
import AddIcon from '@mui/icons-material/Add';
import AssignmentIndIcon from '@mui/icons-material/AssignmentInd';
import PersonAddIcon from '@mui/icons-material/PersonAdd';
import { useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useAccionPagina, useWorkspace } from '../../context/workspace-context';
import { useTranslation } from 'react-i18next';
import { RowActionsMenu } from '../../components/RowActionsMenu';
import { ExportMenuButton } from '../../components/ExportMenuButton';
import { downloadCsv } from '../../api/downloadCsv';
import { emptyStateProps } from '../../utils/emptyState';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../api/errors';
import { PageHeader, DataTableShell } from '../../components/ui';
import { SearchInput } from '../../components/SearchInput';
import { CollapsibleToolbar } from '../../components/CollapsibleToolbar';
import type { PersonaResumen } from '../../types/users';
import { personService } from './personService';
import { PersonDetailDialog } from './PersonDetailDialog';
import { PersonDialog } from './PersonDialog';
import { OffboardingDialog } from './OffboardingDialog';
import { usePermissions } from '../../hooks/usePermissions';
import { useLiveRefresh } from '../../hooks/useLiveRefresh';
export const PersonsPage = () => {
    const { t } = useTranslation();
    const { canAdminInventory, canOperate , canExport } = usePermissions();
    const { enqueueSnackbar } = useSnackbar();
    const { abrirEntrega, agregarReciente } = useWorkspace();
    const navigate = useNavigate();
    const location = useLocation();
    const { personaId } = useParams<{ personaId?: string }>();
    const [params, setParams] = useSearchParams();
    const [personas, setPersonas] = useState<PersonaResumen[]>([]);
    const [loading, setLoading] = useState(true);
    const search = params.get('q') ?? '';
    const setSearch = useCallback((v: string) => setParams((prev) => {
        const next = new URLSearchParams(prev);
        if (v) next.set('q', v); else next.delete('q');
        return next;
    }, { replace: true }), [setParams]);
    const [selected, setSelected] = useState<PersonaResumen | null>(null);
    const [detailOpen, setDetailOpen] = useState(false);
    const [offboardOpen, setOffboardOpen] = useState(false);
    const [editOpen, setEditOpen] = useState(false);
    const load = useCallback(async () => {
        setLoading(true);
        try {
            const data = await personService.list();
            setPersonas(data);
        }
        catch {
            enqueueSnackbar(t('common.error_loading'), { variant: 'error' });
        }
        finally {
            setLoading(false);
        }
    }, [enqueueSnackbar, t]);
    useLiveRefresh(load);
    useEffect(() => {
        load();
    }, [load]);
    // Ficha con dirección propia: /empleados/<id>
    useEffect(() => {
        if (!personaId) { setDetailOpen(false); return; }
        const p = personas.find((x) => x.PER_Persona === personaId);
        if (!p) return;
        setSelected(p);
        setDetailOpen(true);
        agregarReciente({ tipo: 'persona', id: p.PER_Persona, titulo: `${p.PER_Primer_Nombre} ${p.PER_Primer_Apellido}`, subtitulo: p.PER_Email_Corporativo, ruta: `/empleados/${p.PER_Persona}` });
    }, [personaId, personas, agregarReciente]);
    const verPersona = (p: PersonaResumen) => navigate({ pathname: `/empleados/${p.PER_Persona}`, search: location.search });
    const cerrarFicha = () => { setDetailOpen(false); navigate({ pathname: '/empleados', search: location.search }); };
    const nuevaPersona = useCallback(() => { setSelected(null); setEditOpen(true); }, []);
    useAccionPagina('nuevo', canAdminInventory ? nuevaPersona : null);
    const handleReactivate = async (p: PersonaResumen) => {
        try {
            await personService.update(p.PER_Persona, { PER_Estado: true });
            enqueueSnackbar(t('persons.messages.reactivated'), { variant: 'success' });
            load();
        }
        catch (err: unknown) {
            enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
        }
    };
    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q)
            return personas;
        return personas.filter((p) => [
            p.PER_Primer_Nombre,
            p.PER_Primer_Apellido,
            p.PER_Email_Corporativo,
        ]
            .filter(Boolean)
            .some((v) => v.toLowerCase().includes(q)));
    }, [personas, search]);
    return (<Box>
      <PageHeader help="persons"
        title={t('persons.title')}
        subtitle={t('persons.subtitle')}
        badge={filtered.length}
        crumb={detailOpen && selected ? `${selected.PER_Primer_Nombre} ${selected.PER_Primer_Apellido}` : undefined}
        actions={canAdminInventory || canExport ? <>
            {canExport && <ExportMenuButton onExport={(lang) => downloadCsv('/export/personas.csv', 'personas.csv', lang)}/>}
            {canAdminInventory && <>
            <Button variant="outlined" startIcon={<AddIcon />} onClick={nuevaPersona}>{t('persons.register_only')}</Button>
            <Button variant="contained" startIcon={<PersonAddIcon />} onClick={() => navigate('/operaciones/alta-empleado')}>{t('menu.onboarding')}</Button>
            </>}
          </> : undefined}
      />

      <CollapsibleToolbar storageKey="persons" search={<SearchInput value={search} onChange={setSearch} placeholder={t('persons.search')}/>}/>

      <DataTableShell
        columns={[
          { key: 'name', label: t('persons.col_name'), sortValue: (p) => `${p.PER_Primer_Nombre} ${p.PER_Primer_Apellido}` },
          { key: 'email', label: t('persons.col_email'), sortValue: (p) => p.PER_Email_Corporativo },
          { key: 'phone', label: t('persons.col_phone'), sortValue: (p) => p.PER_Telefono },
          { key: 'active', label: t('common.active'), align: 'center', sortValue: (p) => (p.PER_Estado ? 0 : 1) },
          { key: 'actions', label: t('common.actions'), align: 'right' },
        ]}
        rows={filtered}
        loading={loading}
        {...emptyStateProps(t, 'persons', { buscando: !!search.trim(), onLimpiar: () => setSearch(''), onCrear: canAdminInventory ? nuevaPersona : undefined })}
        renderRow={(p) => (<TableRow key={p.PER_Persona} hover>
                  <TableCell>
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                      <Typography variant="body2" fontWeight={500}>
                        {p.PER_Primer_Nombre} {p.PER_Primer_Apellido}
                      </Typography>
                      {p.PER_AD_GUID && (<Tooltip title={t('persons.ad_synced_tooltip') as string}>
                          <Chip label={t('persons.ad_chip')} size="small" color="info" variant="outlined" sx={{ height: 20, fontSize: '0.68rem', fontWeight: 700 }}/>
                        </Tooltip>)}
                    </Box>
                  </TableCell>
                  <TableCell>{p.PER_Email_Corporativo}</TableCell>
                  <TableCell>{p.PER_Telefono ?? '—'}</TableCell>
                  <TableCell align="center">
                    <Chip label={p.PER_Estado
                    ? t('common.active')
                    : t('persons.inactive')} size="small" color={p.PER_Estado ? 'success' : 'default'}/>
                  </TableCell>
                  <TableCell align="right">
                    <Stack direction="row" spacing={0.5} justifyContent="flex-end" alignItems="center">
                      <Button size="small" startIcon={<VisibilityIcon fontSize="small"/>} onClick={() => verPersona(p)}>{t('common.view')}</Button>
                      <RowActionsMenu actions={[
                        ...(canOperate && p.PER_Estado ? [{ key: 'handover', label: t('handover.button'), icon: <AssignmentIndIcon fontSize="small"/>, onClick: () => abrirEntrega({ persona: p }) }] : []),
                        ...(canAdminInventory ? [{ key: 'edit', label: t('common.edit'), icon: <EditIcon fontSize="small"/>, onClick: () => { setSelected(p); setEditOpen(true); } }] : []),
                        ...(canAdminInventory && !p.PER_Estado ? [{ key: 'reactivate', label: t('persons.reactivate'), icon: <HowToRegIcon fontSize="small"/>, onClick: () => { void handleReactivate(p); } }] : []),
                        ...(canAdminInventory && p.PER_Estado ? [{ key: 'offboarding', label: t('persons.offboarding.tooltip'), icon: <LogoutIcon fontSize="small"/>, onClick: () => { setSelected(p); setOffboardOpen(true); }, danger: true }] : []),
                      ]}/>
                    </Stack>
                  </TableCell>
                </TableRow>)}
      />

      <PersonDetailDialog open={detailOpen} persona={selected} onClose={cerrarFicha}
        onHandover={canOperate && selected ? () => abrirEntrega({ persona: selected }) : undefined}
        onOffboard={canAdminInventory ? () => { cerrarFicha(); setOffboardOpen(true); } : undefined}/>
      <OffboardingDialog open={offboardOpen} persona={selected} onClose={() => setOffboardOpen(false)} onCompleted={load}/>
      <PersonDialog open={editOpen} persona={selected} onClose={(refresh) => { setEditOpen(false); setSelected(null); if (refresh)
        load(); }}/>
    </Box>);
};
