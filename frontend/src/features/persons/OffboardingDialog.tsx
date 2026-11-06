import { useCallback, useEffect, useState } from 'react';
import { Alert, Box, Button, Checkbox, CircularProgress, DialogActions, DialogContent, DialogTitle, FormControlLabel, List, ListItem, ListItemText, Stack, Typography } from '@mui/material';
import WarningAmberIcon from '@mui/icons-material/WarningAmber';
import LogoutIcon from '@mui/icons-material/Logout';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { getApiErrorDetail, getApiErrorMessage } from '../../api/errors';
import { ActivoVigente, personService } from './personService';
import { PersonaResumen } from '../../types/users';
import { SectionCard } from '../../components/ui';
import { Dialog } from '../../components/ui/Dialog';
interface Props {
    open: boolean;
    persona: PersonaResumen | null;
    onClose: () => void;
    onCompleted: () => void;
}
export const OffboardingDialog = ({ open, persona, onClose, onCompleted }: Props) => {
    const { t } = useTranslation();
    const { enqueueSnackbar } = useSnackbar();
    const [activos, setActivos] = useState<ActivoVigente[]>([]);
    const [loading, setLoading] = useState(true);
    const [submitting, setSubmitting] = useState(false);
    const [desactivarUsuario, setDesactivarUsuario] = useState(true);
    const [confirmed, setConfirmed] = useState(false);
    const loadActivos = useCallback(async () => {
        if (!persona || !open)
            return;
        setConfirmed(false);
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
    useEffect(() => { loadActivos(); }, [loadActivos]);
    if (!persona)
        return null;
    const handleOffboarding = async () => {
        setSubmitting(true);
        try {
            const result = await personService.offboarding(persona.PER_Persona, desactivarUsuario);
            enqueueSnackbar(t('persons.offboarding.success', { count: result.activos_devueltos_a_bodega }), { variant: 'success' });
            onCompleted();
            onClose();
        }
        catch (err: unknown) {
            const detail = getApiErrorDetail(err) || 'OFFBOARDING_FAILED';
            enqueueSnackbar(t(`persons.offboarding.errors.${detail}`, {
                defaultValue: getApiErrorMessage(err, t('persons.offboarding.errors.OFFBOARDING_FAILED')),
            }), {
                variant: 'error',
            });
        }
        finally {
            setSubmitting(false);
        }
    };
    return (<Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
        <WarningAmberIcon color="warning"/>
        {t('persons.offboarding.title')}
      </DialogTitle>
      <DialogContent dividers>
        <Stack spacing={2}>
          <SectionCard title={t('persons.sections.process')} icon={<WarningAmberIcon fontSize="small"/>}>
            <Typography variant="body2" gutterBottom>
              {t('persons.offboarding.subtitle')}
            </Typography>
            <Typography variant="body2" component="ul" sx={{ pl: 3, mb: 2 }}>
              <li>{t('persons.offboarding.b1')}</li>
              <li>{t('persons.offboarding.b2')}</li>
              <li>{t('persons.offboarding.b3')}</li>
              <li>{t('persons.offboarding.b4')}</li>
              <li>{t('persons.offboarding.b5')}</li>
            </Typography>

            <Alert severity="info">
              <strong>
                {persona.PER_Primer_Nombre} {persona.PER_Primer_Apellido}
              </strong>
              <br />
              {persona.PER_Email_Corporativo}
            </Alert>
          </SectionCard>

          <SectionCard title={t('persons.offboarding.assets_title')}>
            {loading ? (<Box display="flex" justifyContent="center" py={2}>
                <CircularProgress size={24}/>
              </Box>) : activos.length === 0 ? (<Alert severity="success">
                {t('persons.offboarding.no_assets')}
              </Alert>) : (<List dense sx={{ bgcolor: 'action.hover', borderRadius: 1 }}>
                {activos.map((m) => (<ListItem key={m.MOV_Movimiento}>
                    <ListItemText primary={m.activo?.ACT_Codigo_Interno ?? '—'} secondary={`${m.area?.ARE_Nombre ?? ''} · ${m.MOV_Observacion ?? ''}`}/>
                  </ListItem>))}
              </List>)}
          </SectionCard>

          <SectionCard title={t('persons.sections.confirmation')}>
            <FormControlLabel control={<Checkbox checked={desactivarUsuario} onChange={(e) => setDesactivarUsuario(e.target.checked)}/>} label={t('persons.offboarding.deactivate_user')}/>

            <FormControlLabel sx={{ display: 'block', mt: 1 }} control={<Checkbox checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} color="warning"/>} label={t('persons.offboarding.confirm')}/>
          </SectionCard>
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={submitting}>
          {t('common.cancel')}
        </Button>
        <Button onClick={handleOffboarding} color="warning" variant="contained" disabled={!confirmed || submitting || loading} startIcon={submitting ? <CircularProgress size={18} color="inherit"/> : <LogoutIcon />}>
          {t('persons.offboarding.button')}
        </Button>
      </DialogActions>
    </Dialog>);
};
