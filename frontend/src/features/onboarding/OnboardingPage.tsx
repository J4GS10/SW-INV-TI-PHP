import { useEffect, useState } from 'react';
import {
  Alert, Autocomplete, Box, Button, Paper, Stack, Step, StepContent, StepLabel, Stepper, TextField, Typography,
} from '@mui/material';
import PersonAddIcon from '@mui/icons-material/PersonAdd';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { ContextCaption, PageHeader } from '../../components/ui';
import { useAuth } from '../../context/auth-context';
import { useEntityContext } from '../../hooks/useEntityContext';
import { suggestionService } from '../../api/suggestionService';
import type { ResultadoLote } from '../../api/productividadService';
import { createSearchFilter } from '../../utils/autocomplete';
import { personContextLine } from '../../utils/suggestionReasons';
import { IAM_ROLES } from '../../utils/roles';
import type { PersonaResumen } from '../../types/users';
import { movementService } from '../operations/movementService';
import { EntregaPuestoForm } from '../operations/EntregaPuestoForm';
import { nombrePersona } from '../../utils/personas';
import { EntregaResultado } from '../operations/EntregaResultado';
import { PersonDialog } from '../persons/PersonDialog';
import { UserDialog } from '../users/UserDialog';

const filtro = createSearchFilter<PersonaResumen>((p) => `${nombrePersona(p)} ${p.PER_Email_Corporativo}`);

/**
 * Ingreso de un empleado de principio a fin, sin saltar entre pantallas:
 * 1) la persona (existente o nueva), 2) su puesto (equipos en una sola entrega
 * con su acta), 3) su acceso al sistema si corresponde, 4) resumen.
 */
export const OnboardingPage = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { user } = useAuth();
  const esIam = !!user && IAM_ROLES.includes(user.role);
  const [paso, setPaso] = useState(0);
  const [personas, setPersonas] = useState<PersonaResumen[]>([]);
  const [persona, setPersona] = useState<PersonaResumen | null>(null);
  const [nuevaPersona, setNuevaPersona] = useState(false);
  const [entrega, setEntrega] = useState<ResultadoLote | null>(null);
  const [usuarioCreado, setUsuarioCreado] = useState(false);
  const [crearUsuario, setCrearUsuario] = useState(false);

  const cargarPersonas = () => movementService.getPersonasLookup()
    .then((p) => setPersonas(p.filter((x) => x.PER_Estado !== false)))
    .catch(() => setPersonas([]));
  useEffect(() => { void cargarPersonas(); }, []);

  const ctx = useEntityContext(persona?.PER_Persona ?? null, suggestionService.persona);
  const yaTieneUsuario = !!ctx.data?.usuario;

  const reiniciar = () => {
    setPaso(0); setPersona(null); setEntrega(null); setUsuarioCreado(false);
  };

  const pasos = [t('onboarding.steps.person'), t('onboarding.steps.equipment'), t('onboarding.steps.access'), t('onboarding.steps.done')];

  return (
    <Box>
      <PageHeader title={t('onboarding.title')} subtitle={t('onboarding.subtitle')} />
      <Paper variant="outlined" sx={{ p: { xs: 2, md: 3 }, borderRadius: 2 }}>
        <Stepper activeStep={paso} orientation="vertical">
          {/* 1. Persona */}
          <Step>
            <StepLabel optional={paso > 0 && persona ? <Typography variant="caption">{nombrePersona(persona)}</Typography> : undefined}>
              {pasos[0]}
            </StepLabel>
            <StepContent>
              <Stack spacing={2} sx={{ maxWidth: 560 }}>
                <Typography variant="body2" color="text.secondary">{t('onboarding.person_help')}</Typography>
                <Autocomplete
                  options={personas}
                  value={persona}
                  filterOptions={filtro}
                  autoHighlight
                  openOnFocus={false}
                  getOptionLabel={(p) => `${nombrePersona(p)} (${p.PER_Email_Corporativo})`}
                  isOptionEqualToValue={(a, b) => a.PER_Persona === b.PER_Persona}
                  noOptionsText={t('common.type_to_search')}
                  onChange={(_, p) => setPersona(p)}
                  renderInput={(params) => <TextField {...params} autoFocus label={t('onboarding.existing_person')} />}
                />
                {ctx.data && <ContextCaption>{personContextLine(t, ctx.data)}</ContextCaption>}
                <Box>
                  <Button startIcon={<PersonAddIcon />} onClick={() => setNuevaPersona(true)}>{t('onboarding.register_person')}</Button>
                </Box>
                <Box>
                  <Button variant="contained" disabled={!persona} onClick={() => setPaso(1)}>{t('onboarding.continue')}</Button>
                </Box>
              </Stack>
            </StepContent>
          </Step>

          {/* 2. Equipos del puesto */}
          <Step>
            <StepLabel optional={entrega ? <Typography variant="caption">{t('onboarding.equipment_summary', { count: entrega.total })}</Typography> : undefined}>
              {pasos[1]}
            </StepLabel>
            <StepContent>
              {persona && (
                <Box sx={{ maxWidth: 820 }}>
                  <EntregaPuestoForm
                    personaFija={persona}
                    onCompleted={(res) => { setEntrega(res); setPaso(2); }}
                    onCancel={() => setPaso(0)}
                  />
                  <Button sx={{ mt: 1 }} color="inherit" onClick={() => setPaso(2)}>{t('onboarding.skip_equipment')}</Button>
                </Box>
              )}
            </StepContent>
          </Step>

          {/* 3. Acceso al sistema */}
          <Step>
            <StepLabel>{pasos[2]}</StepLabel>
            <StepContent>
              <Stack spacing={2} sx={{ maxWidth: 560 }}>
                {yaTieneUsuario || usuarioCreado ? (
                  <Alert severity="success">{t('onboarding.access_exists', { user: ctx.data?.usuario?.username ?? '' })}</Alert>
                ) : esIam ? (
                  <>
                    <Typography variant="body2" color="text.secondary">{t('onboarding.access_help')}</Typography>
                    <Box><Button variant="outlined" onClick={() => setCrearUsuario(true)}>{t('onboarding.create_user')}</Button></Box>
                  </>
                ) : (
                  <Alert severity="info">{t('onboarding.access_by_security')}</Alert>
                )}
                <Stack direction="row" spacing={1}>
                  <Button color="inherit" onClick={() => setPaso(1)} disabled={!!entrega}>{t('onboarding.back')}</Button>
                  <Button variant="contained" onClick={() => setPaso(3)}>{t('onboarding.finish')}</Button>
                </Stack>
              </Stack>
            </StepContent>
          </Step>

          {/* 4. Resumen */}
          <Step>
            <StepLabel>{pasos[3]}</StepLabel>
            <StepContent>
              <Stack spacing={2} sx={{ maxWidth: 680 }}>
                <Typography variant="subtitle1" sx={{ display: 'flex', alignItems: 'center', gap: 1, fontWeight: 700 }}>
                  <CheckCircleIcon color="success" /> {t('onboarding.done_title', { name: persona ? nombrePersona(persona) : '' })}
                </Typography>
                {entrega ? (
                  <EntregaResultado resultado={entrega} tipoActa="entrega"
                    mensaje={t('handover.done', { count: entrega.total, person: persona ? nombrePersona(persona) : '' })} />
                ) : (
                  <Alert severity="info">{t('onboarding.no_equipment')}</Alert>
                )}
                <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1}>
                  {persona && <Button onClick={() => navigate(`/empleados/${persona.PER_Persona}`)}>{t('handover.view_person')}</Button>}
                  <Button variant="contained" onClick={reiniciar}>{t('onboarding.another')}</Button>
                </Stack>
              </Stack>
            </StepContent>
          </Step>
        </Stepper>
      </Paper>

      <PersonDialog
        open={nuevaPersona}
        persona={null}
        onCreated={(p) => { setPersona(p); void cargarPersonas(); }}
        onClose={() => setNuevaPersona(false)}
      />
      {esIam && persona && (
        <UserDialog
          open={crearUsuario}
          personaInicial={persona.PER_Persona}
          onClose={(refresh) => { setCrearUsuario(false); if (refresh) setUsuarioCreado(true); }}
        />
      )}
    </Box>
  );
};
