import { useEffect, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { Autocomplete, Box, Button, CircularProgress, DialogActions, DialogContent, DialogTitle, FormControlLabel, IconButton, InputAdornment, MenuItem, Radio, RadioGroup, Stack, Switch, TextField, Tooltip, Typography } from '@mui/material';
import AutorenewIcon from '@mui/icons-material/Autorenew';
import ErrorOutlineIcon from '@mui/icons-material/ErrorOutline';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import { Visibility, VisibilityOff } from '@mui/icons-material';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { userService } from './userService';
import { PersonaResumen, PoliticaSeguridad, UsuarioSistema, UsuarioUpdatePayload } from '../../types/users';
import { useAuth } from '../../context/auth-context';
import { UserRole } from '../../types/auth';
import { getApiErrorMessage } from '../../api/errors';
import { createSearchFilter } from '../../utils/autocomplete';
import { DEFAULT_PASSWORD_POLICY, generateStrongPassword, meetsPolicy, policyFromSummary } from '../../utils/password';
import { ALWAYS_GLOBAL_ROLES, grantableRoles } from '../../utils/roles';
import { useSedes } from '../../hooks/useSedes';
import { ContextCaption, SectionCard, SuggestionHint } from '../../components/ui';
import { useEntityContext } from '../../hooks/useEntityContext';
import { useSuggestion } from '../../hooks/useSuggestion';
import { suggestionService } from '../../api/suggestionService';
import { personContextLine, roleReason } from '../../utils/suggestionReasons';
import { PasswordChecklist } from '../auth/security/PasswordChecklist';
import { Dialog } from '../../components/ui/Dialog';

interface Props {
  open: boolean;
  onClose: (refresh?: boolean) => void;
  userToEdit?: UsuarioSistema | null;
  /** Política vigente (resumen de seguridad), para validar la contraseña y avisar del MFA obligatorio. */
  policy?: PoliticaSeguridad | null;
  /** Alta: persona ya elegida (asistente de ingreso de empleado). */
  personaInicial?: string;
}

type AuthMethod = 'password' | 'sso';
type SsoProvider = '' | 'microsoft' | 'google';

interface FormValues {
  PER_Persona: string;
  USU_Username: string;
  USU_Password: string;
  /** Vacío hasta elegir persona: se prellena con el rol sugerido. */
  USU_Rol: UserRole | '';
  authMethod: AuthMethod;
  USU_SSO_Habilitado: boolean;
  USU_SSO_Provider: SsoProvider;
  /** Alcance de datos: todas las sedes (solo lo otorga un SUPER_ADMIN)… */
  USU_Alcance_Global: boolean;
  /** …o las sedes indicadas. */
  sedes: number[];
}

const empty: FormValues = {
  PER_Persona: '',
  USU_Username: '',
  USU_Password: '',
  USU_Rol: '',
  authMethod: 'password',
  USU_SSO_Habilitado: false,
  USU_SSO_Provider: '',
  USU_Alcance_Global: false,
  sedes: [],
};

const personFilter = createSearchFilter<PersonaResumen>((person) => [
  person.PER_Primer_Nombre,
  person.PER_Segundo_Nombre ?? '',
  person.PER_Primer_Apellido,
  person.PER_Segundo_Apellido ?? '',
  person.PER_Email_Corporativo,
].join(' '));

const personLabel = (person: PersonaResumen) => [
  person.PER_Primer_Nombre,
  person.PER_Segundo_Nombre,
  person.PER_Primer_Apellido,
  person.PER_Segundo_Apellido,
].filter(Boolean).join(' ');

export const UserDialog = ({ open, onClose, userToEdit, policy, personaInicial }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const { user: currentUser } = useAuth();
  const isEdit = !!userToEdit;
  const isSuperAdmin = currentUser?.role === 'SUPER_ADMIN';
  const passwordPolicy = policy ? policyFromSummary(policy) : DEFAULT_PASSWORD_POLICY;
  const roleOptions = grantableRoles(currentUser?.role);

  const { control, handleSubmit, reset, watch, setValue, formState: { errors } } = useForm<FormValues>({ mode: 'onChange',
    defaultValues: empty,
  });
  const authMethod = watch('authMethod');
  const ssoEnabled = watch('USU_SSO_Habilitado');
  const password = watch('USU_Password');
  const role = watch('USU_Rol');
  const personId = watch('PER_Persona');
  const username = watch('USU_Username');
  // Contexto de la persona elegida (solo al crear): usuario, rol y organización sugeridos.
  const personCtx = useEntityContext(isEdit ? null : personId, suggestionService.persona);
  const ctx = personCtx.data;
  const existingUser = ctx?.usuario ?? null;
  const pending = personCtx.loading ? undefined : null;
  const usernameHint = useSuggestion<string>({
    current: username,
    empty: '',
    enabled: !isEdit,
    apply: (value) => setValue('USU_Username', value, { shouldDirty: true, shouldValidate: value !== '' }),
    suggestion: ctx?.username_sugerido
      ? { value: ctx.username_sugerido, label: ctx.username_sugerido, reason: t('suggestions.reasons.username_email') }
      : pending,
  });
  const suggestedRole = ctx?.rol_sugerido && roleOptions.includes(ctx.rol_sugerido.rol) ? ctx.rol_sugerido : null;
  const roleHint = useSuggestion<UserRole | ''>({
    current: role,
    empty: '',
    enabled: !isEdit,
    apply: (value) => setValue('USU_Rol', value, { shouldDirty: true, shouldValidate: value !== '' }),
    suggestion: suggestedRole
      ? { value: suggestedRole.rol, label: t(`users.roles.${suggestedRole.rol}`), reason: roleReason(t, suggestedRole.origen) }
      : pending,
  });
  const personLine = ctx ? personContextLine(t, ctx, false) : '';
  // Alcance de datos. Los roles de gobierno ven todas las sedes por definición.
  const scopeGlobal = watch('USU_Alcance_Global');
  const scopeSedes = watch('sedes');
  const roleIsAlwaysGlobal = role !== '' && ALWAYS_GLOBAL_ROLES.includes(role);
  const { sedes: sedeOptions, loading: loadingSedes } = useSedes(open);
  const alcanceSug = ctx?.alcance_sugerido;
  // Sugerencia de mínimo privilegio: la sede de la persona (nunca el alcance global).
  const scopeHint = useSuggestion<string>({
    current: scopeSedes.join(','),
    empty: '',
    enabled: !isEdit && !scopeGlobal && !roleIsAlwaysGlobal,
    apply: (value) => setValue('sedes', value ? value.split(',').map(Number) : [], { shouldDirty: true, shouldValidate: true }),
    suggestion: alcanceSug && alcanceSug.sedes.every((id) => sedeOptions.some((s) => s.SED_Sede === id))
      ? {
          value: alcanceSug.sedes.join(','),
          label: sedeOptions.filter((s) => alcanceSug.sedes.includes(s.SED_Sede)).map((s) => s.SED_Nombre).join(', '),
          reason: t('scope.reasons.person_sede'),
        }
      : personCtx.loading || loadingSedes ? undefined : null,
  });
  const [personas, setPersonas] = useState<PersonaResumen[]>([]);
  const [loading, setLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false);

  useEffect(() => {
    if (!open) return;
    setShowPassword(false);
    if (isEdit && userToEdit) {
      // En edición la persona es fija (única opción, no editable).
      if (userToEdit.persona) setPersonas([userToEdit.persona]);
      reset({
        PER_Persona: userToEdit.persona?.PER_Persona ?? '',
        USU_Username: userToEdit.USU_Username,
        USU_Password: '',
        USU_Rol: userToEdit.USU_Rol,
        authMethod: userToEdit.USU_SSO_Habilitado ? 'sso' : 'password',
        USU_SSO_Habilitado: userToEdit.USU_SSO_Habilitado,
        USU_SSO_Provider: userToEdit.USU_SSO_Provider ?? '',
        USU_Alcance_Global: !!userToEdit.USU_Alcance_Global,
        sedes: (userToEdit.sedes ?? []).map((s) => s.SED_Sede),
      });
    } else {
      userService.getPersonasSinUsuario()
        .then(setPersonas)
        .catch(() => enqueueSnackbar(t('common.error_loading'), { variant: 'error' }));
      // La clave temporal ya viene generada: se ve y se copia sin pedirla.
      reset({ ...empty, PER_Persona: personaInicial ?? '', USU_Password: generateStrongPassword(16, passwordPolicy) });
      setShowPassword(true);
    }
  }, [open, userToEdit, isEdit, reset, enqueueSnackbar, t, personaInicial]);

  const generate = () => {
    setValue('USU_Password', generateStrongPassword(16, passwordPolicy), { shouldValidate: true });
    setShowPassword(true);
  };

  const copyPassword = () => {
    if (!password) return;
    navigator.clipboard?.writeText(password).then(
      () => enqueueSnackbar(t('users.temp_password.copied'), { variant: 'success' }),
      () => undefined,
    );
  };

  const scopePayload = (data: FormValues) => {
    if (ALWAYS_GLOBAL_ROLES.includes(data.USU_Rol as UserRole)) return {};
    return data.USU_Alcance_Global
      ? { USU_Alcance_Global: true, sedes: [] }
      : { USU_Alcance_Global: false, sedes: data.sedes };
  };

  const onSubmit = async (data: FormValues) => {
    setLoading(true);
    try {
      if (isEdit && userToEdit) {
        const payload: UsuarioUpdatePayload = {
          USU_Rol: data.USU_Rol as UserRole,
          USU_SSO_Habilitado: data.USU_SSO_Habilitado,
          USU_SSO_Provider: data.USU_SSO_Habilitado ? data.USU_SSO_Provider || null : null,
          ...scopePayload(data),
        };
        await userService.updateUser(userToEdit.USU_Usuario, payload);
        enqueueSnackbar(t('users.messages.updated'), { variant: 'success' });
      } else {
        const sso = data.authMethod === 'sso';
        await userService.createUser({
          PER_Persona: data.PER_Persona,
          USU_Username: data.USU_Username.trim(),
          USU_Rol: data.USU_Rol as UserRole,
          USU_Password: sso ? undefined : data.USU_Password,
          USU_SSO_Habilitado: sso,
          USU_SSO_Provider: sso ? data.USU_SSO_Provider || null : null,
          ...scopePayload(data),
        });
        enqueueSnackbar(t('users.messages.created_success'), { variant: 'success' });
      }
      onClose(true);
    } catch (error) {
      enqueueSnackbar(getApiErrorMessage(error, t('common.error_generic')), { variant: 'error' });
    } finally {
      setLoading(false);
    }
  };

  const providerSelect = (
    <Controller
      name="USU_SSO_Provider"
      control={control}
      render={({ field }) => (
        <TextField
          {...field}
          select
          fullWidth
          margin="dense"
          label={t('users.dialog.sso_provider')}
          helperText={t('users.dialog.sso_provider_hint')}
        >
          <MenuItem value="">{t('users.dialog.sso_any')}</MenuItem>
          <MenuItem value="microsoft">Microsoft 365</MenuItem>
          <MenuItem value="google">Google Workspace</MenuItem>
        </TextField>
      )}
    />
  );

  const roleRequiresMfa = Boolean(policy?.mfa_roles_obligatorios.includes(role));
  const usesSso = isEdit ? ssoEnabled : authMethod === 'sso';

  return (
    <Dialog open={open} onClose={() => onClose()} maxWidth="sm" fullWidth>
      <DialogTitle>{isEdit ? t('users.dialog.edit_title') : t('users.dialog.title')}</DialogTitle>

      <form onSubmit={handleSubmit(onSubmit)} noValidate>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <SectionCard title={t('users.sections.account')}>
              <Controller
                name="PER_Persona"
                control={control}
                rules={{ required: !isEdit }}
                render={({ field }) => (
                  <Autocomplete
                    options={personas}
                    value={personas.find((person) => person.PER_Persona === field.value) ?? null}
                    onChange={(_, person) => field.onChange(person?.PER_Persona ?? '')}
                    getOptionLabel={(person) => `${personLabel(person)} (${person.PER_Email_Corporativo})`}
                    filterOptions={personFilter}
                    autoHighlight
                    openOnFocus={false}
                    disabled={isEdit}
                    isOptionEqualToValue={(option, selected) => option.PER_Persona === selected.PER_Persona}
                    noOptionsText={t('common.type_to_search')}
                    renderOption={(props, person) => (
                      <Box component="li" {...props} key={person.PER_Persona}>
                        <Stack spacing={0.25} sx={{ minWidth: 0 }}>
                          <Typography variant="body2" sx={{ fontWeight: 700 }}>{personLabel(person)}</Typography>
                          <Typography variant="caption" color="text.secondary" sx={{ overflowWrap: 'anywhere' }}>
                            {person.PER_Email_Corporativo}
                          </Typography>
                        </Stack>
                      </Box>
                    )}
                    renderInput={(params) => (
                      <TextField
                        {...params}
                        fullWidth
                        margin="dense"
                        label={t('users.dialog.select_person')}
                        error={!!errors.PER_Persona}
                        required={!isEdit}
                      />
                    )}
                  />
                )}
              />
              {!isEdit && personLine && <ContextCaption>{personLine}</ContextCaption>}
              {!isEdit && existingUser && (
                <ContextCaption tone="error" icon={ErrorOutlineIcon}>
                  {t('users.dialog.person_has_user', { username: existingUser.username })}
                </ContextCaption>
              )}

              <Controller
                name="USU_Username"
                control={control}
                rules={{ required: !isEdit, minLength: 4 }}
                render={({ field }) => (
                  <TextField
                    {...field}
                    fullWidth
                    margin="dense"
                    label={t('auth.username')}
                    disabled={isEdit}
                    autoComplete="off"
                    InputLabelProps={{ shrink: true }}
                    error={!!errors.USU_Username}
                    helperText={errors.USU_Username ? t('users.dialog.username_hint') : undefined}
                    required={!isEdit}
                  />
                )}
              />
              <SuggestionHint hint={usernameHint} />
            </SectionCard>

            <SectionCard title={t('users.sections.permissions')}>
              <Controller
                name="USU_Rol"
                control={control}
                rules={{ required: true }}
                render={({ field }) => (
                  <TextField
                    {...field}
                    select
                    fullWidth
                    margin="dense"
                    required
                    label={t('users.dialog.select_role')}
                    error={!!errors.USU_Rol}
                  >
                    {roleOptions.map((option) => (
                      <MenuItem key={option} value={option}>{t(`users.roles.${option}`)}</MenuItem>
                    ))}
                  </TextField>
                )}
              />
              <SuggestionHint hint={roleHint} />
              {roleRequiresMfa && !usesSso && (
                <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.5 }}>
                  {t('users.dialog.role_requires_mfa')}
                </Typography>
              )}
            </SectionCard>

            {role !== '' && (
              <SectionCard title={t('scope.title')}>
                {roleIsAlwaysGlobal ? (
                  <Typography variant="body2" color="text.secondary">{t('scope.role_always_global')}</Typography>
                ) : (
                  <Stack spacing={1}>
                    {(isSuperAdmin || scopeGlobal) && <Controller
                      name="USU_Alcance_Global"
                      control={control}
                      render={({ field }) => (
                        <FormControlLabel
                          control={(
                            <Switch
                              checked={field.value}
                              // Solo un SUPER_ADMIN lo otorga; quitarlo sí se permite.
                              disabled={!isSuperAdmin && !field.value}
                              onChange={(_, checked) => field.onChange(checked)}
                            />
                          )}
                          label={t('scope.global')}
                        />
                      )}
                    />}
                    {isSuperAdmin && (
                      <Typography variant="caption" color="text.secondary">{t('scope.global_help')}</Typography>
                    )}
                    {!scopeGlobal && (
                      <>
                        <Controller
                          name="sedes"
                          control={control}
                          rules={{ validate: (value) => scopeGlobal || roleIsAlwaysGlobal || value.length > 0 }}
                          render={({ field }) => (
                            <Autocomplete
                              multiple
                              options={sedeOptions}
                              loading={loadingSedes}
                              value={sedeOptions.filter((s) => field.value.includes(s.SED_Sede))}
                              onChange={(_, selected) => field.onChange(selected.map((s) => s.SED_Sede))}
                              getOptionLabel={(s) => (s.ubicacion ? `${s.SED_Nombre} · ${s.ubicacion}` : s.SED_Nombre)}
                              isOptionEqualToValue={(option, selected) => option.SED_Sede === selected.SED_Sede}
                              filterSelectedOptions
                              renderInput={(params) => (
                                <TextField
                                  {...params}
                                  margin="dense"
                                  required
                                  label={t('scope.sedes')}
                                  error={!!errors.sedes}
                                  helperText={errors.sedes ? t('scope.sedes_required') : t('scope.sedes_help')}
                                />
                              )}
                            />
                          )}
                        />
                        <SuggestionHint hint={scopeHint} />
                      </>
                    )}
                  </Stack>
                )}
              </SectionCard>
            )}

            <SectionCard title={t('users.sections.authentication')}>
              {isEdit ? (
                <>
                  <Controller
                    name="USU_SSO_Habilitado"
                    control={control}
                    render={({ field }) => (
                      <FormControlLabel
                        control={<Switch checked={field.value} onChange={(_, checked) => field.onChange(checked)} />}
                        label={t('users.dialog.sso_enabled')}
                      />
                    )}
                  />
                  {ssoEnabled && providerSelect}
                  <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
                    {t('users.dialog.password_via_reset')}
                  </Typography>
                </>
              ) : (
                <>
                  <Controller
                    name="authMethod"
                    control={control}
                    render={({ field }) => (
                      <RadioGroup {...field} row sx={{ columnGap: 2 }}>
                        <FormControlLabel value="password" control={<Radio />} label={t('users.dialog.method_password')} />
                        <FormControlLabel value="sso" control={<Radio />} label={t('users.dialog.method_sso')} />
                      </RadioGroup>
                    )}
                  />
                  {authMethod === 'password' ? (
                    <Stack spacing={1.25} sx={{ mt: 1 }}>
                      <Stack direction="row" spacing={1} alignItems="flex-start">
                        <Controller
                          name="USU_Password"
                          control={control}
                          rules={{ validate: (value) => authMethod !== 'password' || meetsPolicy(value, passwordPolicy) }}
                          render={({ field }) => (
                            <TextField
                              {...field}
                              fullWidth
                              required
                              type={showPassword ? 'text' : 'password'}
                              label={t('users.dialog.temporary_password')}
                              autoComplete="new-password"
                              error={!!errors.USU_Password}
                              InputProps={{
                                sx: showPassword ? { fontFamily: 'monospace' } : undefined,
                                endAdornment: (
                                  <InputAdornment position="end">
                                    {password && (
                                      <Tooltip title={t('users.temp_password.copy')}>
                                        <IconButton size="small" onClick={copyPassword} aria-label={t('users.temp_password.copy')}>
                                          <ContentCopyIcon fontSize="small" />
                                        </IconButton>
                                      </Tooltip>
                                    )}
                                    <IconButton
                                      size="small"
                                      edge="end"
                                      onClick={() => setShowPassword((v) => !v)}
                                      aria-label={t('auth.toggle_password_visibility')}
                                    >
                                      {showPassword ? <VisibilityOff fontSize="small" /> : <Visibility fontSize="small" />}
                                    </IconButton>
                                  </InputAdornment>
                                ),
                              }}
                            />
                          )}
                        />
                        <Button variant="outlined" startIcon={<AutorenewIcon />} onClick={generate} sx={{ flexShrink: 0, height: 56 }}>
                          {t('users.dialog.generate')}
                        </Button>
                      </Stack>
                      <Typography variant="caption" color="text.secondary">
                        {t('users.dialog.temporary_password_hint')}
                      </Typography>
                      <PasswordChecklist password={password} policy={passwordPolicy} />
                    </Stack>
                  ) : providerSelect}
                </>
              )}
            </SectionCard>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => onClose()} color="inherit">
            {t('common.cancel')}
          </Button>
          <Button type="submit" variant="contained" disabled={loading || (!isEdit && !!existingUser)}>
            {loading ? <CircularProgress size={24} /> : t('common.save')}
          </Button>
        </DialogActions>
      </form>
    </Dialog>
  );
};
