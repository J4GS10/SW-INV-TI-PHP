import AssignmentIndIcon from '@mui/icons-material/AssignmentInd';
import { useCallback, useEffect, useState } from 'react';
import { Box, Button, Chip, CircularProgress, DialogActions, DialogContent, DialogTitle, IconButton, List, ListItem, ListItemText, Stack, Tooltip, Typography } from '@mui/material';
import CloseIcon from '@mui/icons-material/Close';
import LaptopIcon from '@mui/icons-material/Laptop';
import LogoutIcon from '@mui/icons-material/Logout';
import SupervisorAccountIcon from '@mui/icons-material/SupervisorAccount';
import { useTranslation } from 'react-i18next';
import { movementTypeLabel } from '../../utils/catalogLabels';
import { ActivoVigente, personService } from './personService';
import type { PersonaResumen } from '../../types/users';
import { SectionCard } from '../../components/ui';
import { Dialog } from '../../components/ui/Dialog';
import { useLiveRefresh } from '../../hooks/useLiveRefresh';
interface Props {
    open: boolean;
    persona: PersonaResumen | null;
    onClose: () => void;
    onOffboard?: () => void;
    /** Abre la entrega de puesto para esta persona. */
    onHandover?: () => void;
}
export const PersonDetailDialog = ({ open, persona, onClose, onOffboard, onHandover }: Props) => {
    const { t } = useTranslation();
    const [activos, setActivos] = useState<ActivoVigente[]>([]);
    const [loading, setLoading] = useState(true);
    const load = useCallback(async () => {
        if (!persona || !open)
            return;
        setLoading(true);
        try {
            setActivos(await personService.asignacionesVigentes(persona.PER_Persona));
        }
        catch {
            setActivos([]);
        }
        finally {
            setLoading(false);
        }
    }, [open, persona]);
    useEffect(() => { load(); }, [load]);
    useLiveRefresh(load);
    // Jefe directo (PER_Jefe) resuelto por id; `undefined` = cargando, `null` = sin jefe / no encontrado.
    const jefeId = open ? persona?.PER_Jefe ?? null : null;
    const [jefe, setJefe] = useState<{ id: string; data: PersonaResumen | null } | null>(null);
    useEffect(() => {
        if (!jefeId)
            return;
        let cancelled = false;
        personService.get(jefeId)
            .then((data) => { if (!cancelled) setJefe({ id: jefeId, data }); })
            .catch(() => { if (!cancelled) setJefe({ id: jefeId, data: null }); });
        return () => { cancelled = true; };
    }, [jefeId]);
    const jefeLoading = !!jefeId && jefe?.id !== jefeId;
    const jefeData = jefeId && jefe?.id === jefeId ? jefe.data : null;
    if (!persona)
        return null;
    return (<Dialog open={open} onClose={onClose} maxWidth="md" fullWidth>
      <DialogTitle sx={{ display: 'flex', justifyContent: 'space-between' }}>
        <Box>
          <Typography variant="h6">
            {persona.PER_Primer_Nombre} {persona.PER_Primer_Apellido}
          </Typography>
          <Typography variant="caption" color="text.secondary">
            {persona.PER_Email_Corporativo}
          </Typography>
        </Box>
        <IconButton onClick={onClose}>
          <CloseIcon />
        </IconButton>
      </DialogTitle>
      <DialogContent dividers>
        <Stack spacing={2}>
          <SectionCard title={t('persons.sections.general')}>
            <Stack direction="row" spacing={2} flexWrap="wrap">
              <Chip label={persona.PER_Estado
                ? t('common.active')
                : t('persons.inactive')} color={persona.PER_Estado ? 'success' : 'default'} size="small"/>
              {persona.PER_Telefono && (<Chip label={persona.PER_Telefono} size="small" variant="outlined"/>)}
              {persona.PER_AD_GUID && (<Tooltip title={persona.PER_AD_Sincronizado_En
                    ? t('persons.ad_synced_at', { date: new Date(persona.PER_AD_Sincronizado_En).toLocaleString() })
                    : t('persons.ad_synced_tooltip')}>
                  <Chip label={t('persons.ad_chip')} size="small" color="info" variant="outlined"/>
                </Tooltip>)}
            </Stack>
            <Stack direction="row" spacing={1} alignItems="center" mt={2}>
              <SupervisorAccountIcon fontSize="small" sx={{ color: 'text.secondary' }}/>
              <Typography variant="body2" color="text.secondary">{t('persons.ad_manager')}:</Typography>
              {jefeLoading ? (<CircularProgress size={14}/>) : jefeData ? (<Typography variant="body2" fontWeight={500}>
                  {jefeData.PER_Primer_Nombre} {jefeData.PER_Primer_Apellido}
                  <Typography component="span" variant="caption" color="text.secondary" sx={{ ml: 1 }}>
                    {jefeData.PER_Email_Corporativo}
                  </Typography>
                </Typography>) : (<Typography variant="body2" color="text.secondary">
                  {jefeId ? t('persons.ad_manager_unknown') : t('persons.ad_no_manager')}
                </Typography>)}
            </Stack>
          </SectionCard>

          <SectionCard title={t('persons.assigned_assets')} icon={<LaptopIcon fontSize="small"/>}>
            {loading ? (<Box display="flex" justifyContent="center" py={3}>
                <CircularProgress />
              </Box>) : activos.length === 0 ? (<Typography variant="body2" color="text.secondary" textAlign="center" py={4}>
                {t('persons.no_assets')}
              </Typography>) : (<List dense>
                {activos.map((m) => (<ListItem key={m.MOV_Movimiento} sx={{ bgcolor: 'action.hover', borderRadius: 1, mb: 1 }}>
                    <ListItemText primary={<Stack direction="row" alignItems="center" spacing={1}>
                          <Typography variant="body1" fontWeight={600}>
                            {m.activo?.ACT_Codigo_Interno ?? '—'}
                          </Typography>
                          {m.activo?.ACT_Hostname && (<Chip size="small" label={m.activo.ACT_Hostname} variant="outlined"/>)}
                          {m.tipo_movimiento?.TMO_Nombre && (<Chip size="small" label={movementTypeLabel(t, m.tipo_movimiento.TMO_Nombre)} color="primary"/>)}
                        </Stack>} secondary={<>
                          {m.area?.ARE_Nombre && `${m.area.ARE_Nombre} · `}
                          {new Date(m.MOV_Fecha_Asignacion).toLocaleDateString()}
                          {m.MOV_Observacion && ` · ${m.MOV_Observacion}`}
                        </>}/>
                  </ListItem>))}
              </List>)}
          </SectionCard>
        </Stack>
      </DialogContent>
      {persona.PER_Estado && (onOffboard || onHandover) && (<DialogActions sx={{ px: 3, py: 2, flexWrap: 'wrap', gap: 1 }}>
          {onOffboard && (<Typography variant="caption" color="text.secondary" sx={{ mr: 'auto' }}>
            {t('persons.offboarding.hint')}
          </Typography>)}
          {onHandover && (<Button variant="contained" startIcon={<AssignmentIndIcon />} onClick={onHandover}>
            {t('handover.button')}
          </Button>)}
          {onOffboard && (<Button variant="outlined" color="warning" startIcon={<LogoutIcon />} onClick={onOffboard}>
            {t('persons.offboarding.from_detail')}
          </Button>)}
        </DialogActions>)}
    </Dialog>);
};
