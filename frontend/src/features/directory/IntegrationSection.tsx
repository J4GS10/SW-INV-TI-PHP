import { useCallback, useEffect, useMemo, useState } from 'react';
import { parseApiDate } from '../../utils/dates';
import type { ReactNode } from 'react';
import { Alert, AlertTitle, Box, Button, Chip, CircularProgress, Collapse, DialogActions, DialogContent, DialogContentText, DialogTitle, FormControlLabel, IconButton, InputAdornment, MenuItem, Radio, Skeleton, Stack, Switch, TextField, ToggleButton, ToggleButtonGroup, Typography } from '@mui/material';
import { alpha } from '@mui/material/styles';
import MailLockIcon from '@mui/icons-material/MailLock';
import NetworkCheckIcon from '@mui/icons-material/NetworkCheck';
import SaveIcon from '@mui/icons-material/Save';
import SendIcon from '@mui/icons-material/Send';
import Visibility from '@mui/icons-material/Visibility';
import VisibilityOff from '@mui/icons-material/VisibilityOff';
import { useTranslation } from 'react-i18next';
import type { TFunction } from 'i18next';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../api/errors';
import { SectionCard, StatusChip } from '../../components/ui';
import { useAuth } from '../../context/auth-context';
import { directoryService } from './directoryService';
import { isForbidden, isValidEmail } from './directoryUtils';
import type {
  AdTestResult,
  IntegrationMode,
  IntegrationOut,
  IntegrationUpdate,
  MailProvider,
  MailTestResult,
  SmtpSecurity,
} from './types';
import { Dialog } from '../../components/ui/Dialog';
import { useLiveRefresh } from '../../hooks/useLiveRefresh';

/* ─────────────────────────── Estado del formulario ─────────────────────────── */

const MODES: IntegrationMode[] = ['DESACTIVADO', 'CORREO', 'CORREO_AD'];
const SECURITY_OPTIONS: SmtpSecurity[] = ['STARTTLS', 'SSL', 'NINGUNA'];

type Preset = 'graph' | 'm365_smtp' | 'exchange' | 'google' | 'custom';
const PRESETS: Preset[] = ['graph', 'm365_smtp', 'exchange', 'google', 'custom'];

/** Valores de texto tal como se editan (los números se guardan como string para no pelear con el input). */
interface FormState {
  modo: IntegrationMode;
  proveedor: MailProvider;
  cuenta_email: string;
  cuenta_usuario: string;
  nombre_remitente: string;
  smtp_host: string;
  smtp_puerto: string;
  smtp_seguridad: SmtpSecurity;
  graph_tenant_id: string;
  graph_client_id: string;
  ad_servidor: string;
  ad_base_dn: string;
  ad_grupo_base_dn: string;
  ad_grupo_admin: string;
  ad_start_tls: boolean;
  ad_verificar_cert: boolean;
  ad_intervalo_min: string;
  ad_desactivar: boolean;
  ad_usar_cuenta_servicio: boolean;
  ad_bind_usuario: string;
}

/** Secreto en edición: `value` vacío = conservar el guardado; `clear` = borrarlo al guardar. */
interface SecretState {
  value: string;
  clear: boolean;
}

type SecretKey = 'cuenta_password' | 'graph_client_secret' | 'ad_bind_password';
type Secrets = Record<SecretKey, SecretState>;

const EMPTY_SECRETS: Secrets = {
  cuenta_password: { value: '', clear: false },
  graph_client_secret: { value: '', clear: false },
  ad_bind_password: { value: '', clear: false },
};

const toForm = (c: IntegrationOut): FormState => ({
  modo: c.modo,
  proveedor: c.proveedor,
  cuenta_email: c.cuenta_email ?? '',
  cuenta_usuario: c.cuenta_usuario ?? '',
  nombre_remitente: c.nombre_remitente ?? '',
  smtp_host: c.smtp_host ?? '',
  smtp_puerto: String(c.smtp_puerto ?? ''),
  smtp_seguridad: c.smtp_seguridad,
  graph_tenant_id: c.graph_tenant_id ?? '',
  graph_client_id: c.graph_client_id ?? '',
  ad_servidor: c.ad_servidor ?? '',
  ad_base_dn: c.ad_base_dn ?? '',
  ad_grupo_base_dn: c.ad_grupo_base_dn ?? '',
  ad_grupo_admin: c.ad_grupo_admin ?? '',
  ad_start_tls: c.ad_start_tls,
  ad_verificar_cert: c.ad_verificar_cert,
  ad_intervalo_min: String(c.ad_intervalo_min ?? 0),
  ad_desactivar: c.ad_desactivar,
  ad_usar_cuenta_servicio: c.ad_usar_cuenta_servicio,
  ad_bind_usuario: c.ad_bind_usuario ?? '',
});

const nullable = (v: string): string | null => (v.trim() === '' ? null : v.trim());

/** Omitido = conservar; "" = borrar; texto = reemplazar. */
const secretPayload = (s: SecretState): string | undefined => (s.clear ? '' : s.value !== '' ? s.value : undefined);

const toPayload = (f: FormState, s: Secrets): IntegrationUpdate => {
  const payload: IntegrationUpdate = {
    modo: f.modo,
    proveedor: f.proveedor,
    cuenta_email: nullable(f.cuenta_email),
    cuenta_usuario: nullable(f.cuenta_usuario),
    nombre_remitente: f.nombre_remitente.trim(),
    smtp_host: nullable(f.smtp_host),
    smtp_puerto: Number(f.smtp_puerto),
    smtp_seguridad: f.smtp_seguridad,
    graph_tenant_id: nullable(f.graph_tenant_id),
    graph_client_id: nullable(f.graph_client_id),
    ad_servidor: nullable(f.ad_servidor),
    ad_base_dn: nullable(f.ad_base_dn),
    ad_grupo_base_dn: nullable(f.ad_grupo_base_dn),
    ad_grupo_admin: nullable(f.ad_grupo_admin),
    ad_start_tls: f.ad_start_tls,
    ad_verificar_cert: f.ad_verificar_cert,
    ad_intervalo_min: Number(f.ad_intervalo_min),
    ad_desactivar: f.ad_desactivar,
    ad_usar_cuenta_servicio: f.ad_usar_cuenta_servicio,
    ad_bind_usuario: nullable(f.ad_bind_usuario),
  };
  (Object.keys(s) as SecretKey[]).forEach((k) => {
    const v = secretPayload(s[k]);
    if (v !== undefined) payload[k] = v;
  });
  return payload;
};

const detectPreset = (c: IntegrationOut): Preset => {
  if (c.proveedor === 'GRAPH') return 'graph';
  const host = (c.smtp_host ?? '').toLowerCase();
  if (host === 'smtp.office365.com') return 'm365_smtp';
  if (host === 'smtp.gmail.com') return 'google';
  return 'custom';
};

// El servidor envía UTC sin zona: parseApiDate lo interpreta como UTC.
const isFuture = (iso: string | null): iso is string => !!iso && (parseApiDate(iso)?.getTime() ?? 0) > Date.now();

const formatTime = (iso: string) =>
  (parseApiDate(iso) ?? new Date(iso)).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

/* ─────────────────────────── Validación ─────────────────────────── */

type Errors = Partial<Record<keyof FormState | SecretKey, string>>;

interface Validation {
  errors: Errors;
  mailValid: boolean;
  adValid: boolean;
}

const validate = (
  f: FormState,
  s: Secrets,
  cfg: IntegrationOut,
  t: TFunction,
): Validation => {
  const mail: Errors = {};
  const ad: Errors = {};
  if (f.modo === 'DESACTIVADO') return { errors: {}, mailValid: true, adValid: true };

  const hasSecret = (k: SecretKey, configured: boolean) => !s[k].clear && (s[k].value !== '' || configured);
  const accountPasswordOk = hasSecret('cuenta_password', cfg.password_configurada);
  const req = t('common.required');

  if (!f.cuenta_email.trim()) mail.cuenta_email = req;
  else if (!isValidEmail(f.cuenta_email)) mail.cuenta_email = t('directory.integration.errors.email');

  if (f.proveedor === 'SMTP') {
    if (!f.smtp_host.trim()) mail.smtp_host = req;
    const port = Number(f.smtp_puerto);
    if (!/^\d+$/.test(f.smtp_puerto.trim()) || port < 1 || port > 65535) {
      mail.smtp_puerto = t('directory.integration.errors.port');
    }
    // Sin clave es válido: relay SMTP autorizado por IP (Exchange local).
  } else {
    if (!f.graph_tenant_id.trim()) mail.graph_tenant_id = req;
    if (!f.graph_client_id.trim()) mail.graph_client_id = req;
    if (!hasSecret('graph_client_secret', cfg.graph_secret_configurado)) {
      mail.graph_client_secret = t('directory.integration.errors.password_required');
    }
  }

  if (f.modo === 'CORREO_AD') {
    if (!f.ad_servidor.trim()) ad.ad_servidor = req;
    else if (!/^ldaps?:\/\/\S+$/i.test(f.ad_servidor.trim())) ad.ad_servidor = t('directory.integration.errors.ldap_url');
    if (!f.ad_base_dn.trim()) ad.ad_base_dn = req;
    if (!/^\d+$/.test(f.ad_intervalo_min.trim())) ad.ad_intervalo_min = t('directory.integration.errors.interval');
    if (f.ad_usar_cuenta_servicio) {
      if (!accountPasswordOk) ad.cuenta_password = t('directory.integration.errors.password_required');
    } else {
      if (!f.ad_bind_usuario.trim()) ad.ad_bind_usuario = req;
      if (!hasSecret('ad_bind_password', cfg.ad_bind_password_configurada)) {
        ad.ad_bind_password = t('directory.integration.errors.password_required');
      }
    }
  }

  return {
    errors: { ...ad, ...mail },
    mailValid: Object.keys(mail).length === 0,
    adValid: f.modo !== 'CORREO_AD' || Object.keys(ad).length === 0,
  };
};

/* ─────────────────────────── Sub-componentes ─────────────────────────── */

interface SecretFieldProps {
  label: string;
  configured: boolean;
  state: SecretState;
  onChange: (next: SecretState) => void;
  error?: string;
  helperText?: string;
  autoComplete?: string;
}

/** Campo de clave: nunca muestra la guardada; vacío = conservarla; "borrar clave" = enviar "". */
const SecretField = ({ label, configured, state, onChange, error, helperText, autoComplete }: SecretFieldProps) => {
  const { t } = useTranslation();
  const [show, setShow] = useState(false);
  const cleared = state.clear;

  return (
    <Box>
      <TextField
        fullWidth
        size="small"
        label={label}
        type={show ? 'text' : 'password'}
        value={state.value}
        disabled={cleared}
        autoComplete={autoComplete ?? 'new-password'}
        placeholder={configured && !cleared ? t('directory.integration.secret_saved_placeholder') : undefined}
        onChange={(e) => onChange({ value: e.target.value, clear: false })}
        error={!!error}
        helperText={
          error ??
          (cleared
            ? t('directory.integration.secret_will_clear')
            : configured
              ? t('directory.integration.secret_keep_hint')
              : helperText)
        }
        slotProps={{
          inputLabel: configured && !cleared ? { shrink: true } : undefined,
          input: {
            endAdornment: (
              <InputAdornment position="end">
                <IconButton
                  size="small"
                  edge="end"
                  aria-label={show ? t('directory.integration.hide_secret') : t('directory.integration.show_secret')}
                  onClick={() => setShow((v) => !v)}
                  disabled={cleared}
                >
                  {show ? <VisibilityOff fontSize="small" /> : <Visibility fontSize="small" />}
                </IconButton>
              </InputAdornment>
            ),
          },
        }}
      />
      {configured && (
        <Button
          size="small"
          color={cleared ? 'primary' : 'error'}
          sx={{ mt: 0.25, px: 0.5, minWidth: 0, textTransform: 'none' }}
          onClick={() => onChange({ value: '', clear: !cleared })}
        >
          {cleared ? t('directory.integration.undo_clear_secret') : t('directory.integration.clear_secret')}
        </Button>
      )}
    </Box>
  );
};

const Grid2 = ({ children }: { children: ReactNode }) => (
  <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: 'repeat(2, minmax(0, 1fr))' } }}>
    {children}
  </Box>
);

const SubTitle = ({ children }: { children: ReactNode }) => (
  <Typography variant="subtitle2" fontWeight={700}>
    {children}
  </Typography>
);

/* ─────────────────────────── Componente principal ─────────────────────────── */

type TestKind = 'mail' | 'ad';
interface TestOutcome {
  kind: TestKind;
  result: MailTestResult | AdTestResult;
  destinatario?: string;
}

interface Props {
  /** Se invoca tras guardar, para que otras secciones (estado AD) se recarguen. */
  onSaved?: () => void;
}

export const IntegrationSection = ({ onSaved }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const { user } = useAuth();

  const [config, setConfig] = useState<IntegrationOut | null>(null);
  const [initial, setInitial] = useState<FormState | null>(null);
  const [form, setForm] = useState<FormState | null>(null);
  const [secrets, setSecrets] = useState<Secrets>(EMPTY_SECRETS);
  const [preset, setPreset] = useState<Preset>('custom');
  const [showAltUser, setShowAltUser] = useState(false);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<'forbidden' | 'generic' | null>(null);
  const [busy, setBusy] = useState<'save' | 'mail' | 'ad' | 'unlock' | null>(null);
  const [testDialogOpen, setTestDialogOpen] = useState(false);
  const [destinatario, setDestinatario] = useState('');
  const [outcome, setOutcome] = useState<TestOutcome | null>(null);
  const [, setTick] = useState(0);

  const applyConfig = useCallback((c: IntegrationOut) => {
    const f = toForm(c);
    setConfig(c);
    setInitial(f);
    setForm(f);
    setSecrets(EMPTY_SECRETS);
    setPreset(detectPreset(c));
    setShowAltUser(!!c.cuenta_usuario);
  }, []);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      applyConfig(await directoryService.getIntegration());
      setLoadError(null);
    } catch (err) {
      setLoadError(isForbidden(err) ? 'forbidden' : 'generic');
    } finally {
      setLoading(false);
    }
  }, [applyConfig]);

  useEffect(() => {
    load();
  }, [load]);

  // Re-renderiza cada 30 s para que el aviso de bloqueo desaparezca al vencer.
  useEffect(() => {
    const id = window.setInterval(() => setTick((n) => n + 1), 30_000);
    return () => window.clearInterval(id);
  }, []);

  const dirty = useMemo(() => {
    if (!form || !initial) return false;
    const formChanged = (Object.keys(form) as (keyof FormState)[]).some((k) => form[k] !== initial[k]);
    const secretsChanged = Object.values(secrets).some((s) => s.clear || s.value !== '');
    return formChanged || secretsChanged;
  }, [form, initial, secrets]);
  // Cambios de otra persona: se recargan, salvo si aquí hay cambios sin guardar.
  useLiveRefresh(load, { resources: ['directorio'], enabled: !dirty });

  const validation = useMemo(
    () => (form && config ? validate(form, secrets, config, t) : { errors: {}, mailValid: false, adValid: false }),
    [form, secrets, config, t],
  );
  const { errors } = validation;
  const formValid = validation.mailValid && validation.adValid;

  if (loading) {
    return (
      <SectionCard title={t('directory.integration.title')} icon={<MailLockIcon fontSize="small" />}>
        <Stack spacing={1}>
          <Skeleton variant="rounded" height={72} />
          <Skeleton variant="rounded" height={160} />
        </Stack>
      </SectionCard>
    );
  }

  if (loadError || !form || !config) {
    return (
      <SectionCard title={t('directory.integration.title')} icon={<MailLockIcon fontSize="small" />}>
        <Alert severity={loadError === 'forbidden' ? 'warning' : 'error'}>
          {loadError === 'forbidden' ? t('directory.forbidden_section') : t('common.error_loading')}
        </Alert>
      </SectionCard>
    );
  }

  const set = <K extends keyof FormState>(key: K, value: FormState[K]) =>
    setForm((prev) => (prev ? { ...prev, [key]: value } : prev));

  const setSecret = (key: SecretKey, next: SecretState) => setSecrets((prev) => ({ ...prev, [key]: next }));

  const applyPreset = (p: Preset) => {
    setPreset(p);
    setForm((prev) => {
      if (!prev) return prev;
      switch (p) {
        case 'graph':
          return { ...prev, proveedor: 'GRAPH' };
        case 'm365_smtp':
          return { ...prev, proveedor: 'SMTP', smtp_host: 'smtp.office365.com', smtp_puerto: '587', smtp_seguridad: 'STARTTLS' };
        case 'exchange':
          return { ...prev, proveedor: 'SMTP', smtp_host: '', smtp_puerto: '587', smtp_seguridad: 'STARTTLS' };
        case 'google':
          return { ...prev, proveedor: 'SMTP', smtp_host: 'smtp.gmail.com', smtp_puerto: '587', smtp_seguridad: 'STARTTLS' };
        default:
          return { ...prev, proveedor: prev.proveedor };
      }
    });
  };

  const reportError = (err: unknown, fallbackKey = 'common.error_generic') =>
    enqueueSnackbar(isForbidden(err) ? t('directory.forbidden_action') : getApiErrorMessage(err, t(fallbackKey)), {
      variant: 'error',
    });

  const handleSave = async () => {
    setBusy('save');
    try {
      applyConfig(await directoryService.updateIntegration(toPayload(form, secrets)));
      enqueueSnackbar(t('directory.integration.saved'), { variant: 'success' });
      onSaved?.();
    } catch (err) {
      reportError(err, 'common.error_save');
    } finally {
      setBusy(null);
    }
  };

  const openMailTest = () => {
    if (!destinatario) {
      const own = user?.sub ?? '';
      setDestinatario(isValidEmail(own) ? own : '');
    }
    setTestDialogOpen(true);
  };

  const handleMailTest = async () => {
    const to = destinatario.trim();
    setTestDialogOpen(false);
    setBusy('mail');
    setOutcome(null);
    try {
      const result = await directoryService.testIntegrationMail(toPayload(form, secrets), to);
      setOutcome({ kind: 'mail', result, destinatario: to });
      if (!result.ok && result.codigo === 'AUTH_FAILED') refreshLock();
    } catch (err) {
      reportError(err);
    } finally {
      setBusy(null);
    }
  };

  const handleAdTest = async () => {
    setBusy('ad');
    setOutcome(null);
    try {
      const result = await directoryService.testIntegrationAd(toPayload(form, secrets));
      setOutcome({ kind: 'ad', result });
      if (!result.ok && result.codigo === 'AUTH_FAILED') refreshLock();
    } catch (err) {
      reportError(err);
    } finally {
      setBusy(null);
    }
  };

  /** Recarga solo el estado de bloqueo, sin perder cambios sin guardar del formulario. */
  const refreshLock = async () => {
    try {
      const fresh = await directoryService.getIntegration();
      setConfig((prev) => (prev ? { ...prev, bloqueo: fresh.bloqueo } : prev));
    } catch {
      /* no crítico */
    }
  };

  const handleUnlock = async () => {
    setBusy('unlock');
    try {
      await directoryService.unlockIntegration();
      enqueueSnackbar(t('directory.integration.unlocked'), { variant: 'success' });
      await refreshLock();
    } catch (err) {
      reportError(err);
    } finally {
      setBusy(null);
    }
  };

  const outcomeMessage = (o: TestOutcome): string => {
    if (o.result.ok) {
      return o.kind === 'mail'
        ? t('directory.integration.test.mail_ok', { to: o.destinatario })
        : t('directory.integration.test.ad_ok', { count: (o.result as AdTestResult).usuarios_encontrados });
    }
    return t(`directory.integration.test.codes.${o.result.codigo}`, t('directory.integration.test.codes.ERROR'));
  };

  const enabled = form.modo !== 'DESACTIVADO';
  const withAd = form.modo === 'CORREO_AD';
  const isSmtp = form.proveedor === 'SMTP';
  const needsAccountPassword = isSmtp || (withAd && form.ad_usar_cuenta_servicio);
  const smtpLockedUntil = isFuture(config.bloqueo.smtp_hasta) ? config.bloqueo.smtp_hasta : null;
  const adLockedUntil = isFuture(config.bloqueo.ad_hasta) ? config.bloqueo.ad_hasta : null;
  const isBusy = busy !== null;
  const destinatarioValid = isValidEmail(destinatario);

  const statusChips = (
    <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
      <StatusChip
        label={
          config.origen === 'interfaz'
            ? t('directory.integration.origin_ui')
            : t('directory.integration.origin_env')
        }
        variant={config.origen === 'interfaz' ? 'activo' : 'por_vencer'}
        tooltip={config.origen === 'entorno' ? t('directory.integration.origin_env_tooltip') : undefined}
      />
      {dirty && <Chip size="small" color="warning" variant="outlined" label={t('directory.integration.unsaved')} />}
    </Stack>
  );

  return (
    <SectionCard
      title={t('directory.integration.title')}
      subtitle={t('directory.integration.subtitle')}
      icon={<MailLockIcon fontSize="small" />}
      actions={statusChips}
    >
      <Stack spacing={3}>
        {/* ── Avisos de estado ── */}
        {!config.cifrado_ok && (
          <Alert severity="warning">
            <AlertTitle>{t('directory.integration.no_encryption_title')}</AlertTitle>
            {t('directory.integration.no_encryption_description')}
          </Alert>
        )}
        {(smtpLockedUntil || adLockedUntil) && (
          <Alert
            severity="error"
            action={
              <Button
                color="inherit"
                size="small"
                onClick={handleUnlock}
                disabled={isBusy}
                startIcon={busy === 'unlock' ? <CircularProgress size={14} color="inherit" /> : undefined}
              >
                {t('directory.integration.unlock')}
              </Button>
            }
          >
            <AlertTitle>{t('directory.integration.locked_title')}</AlertTitle>
            {smtpLockedUntil && (
              <Box>{t('directory.integration.locked_mail', { time: formatTime(smtpLockedUntil) })}</Box>
            )}
            {adLockedUntil && <Box>{t('directory.integration.locked_ad', { time: formatTime(adLockedUntil) })}</Box>}
          </Alert>
        )}

        {/* ── 1. Modo ── */}
        <Stack spacing={1}>
          <SubTitle>{t('directory.integration.mode_label')}</SubTitle>
          <Box
            role="radiogroup"
            aria-label={t('directory.integration.mode_label')}
            sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', md: 'repeat(3, minmax(0, 1fr))' } }}
          >
            {MODES.map((m) => {
              const selected = form.modo === m;
              return (
                <Box
                  key={m}
                  component="label"
                  sx={(theme) => ({
                    display: 'flex',
                    gap: 1,
                    alignItems: 'flex-start',
                    p: 1.5,
                    pl: 0.5,
                    borderRadius: 2,
                    cursor: 'pointer',
                    border: '1px solid',
                    borderColor: selected ? 'primary.main' : 'divider',
                    bgcolor: selected ? alpha(theme.palette.primary.main, 0.06) : 'transparent',
                    transition: 'border-color .15s, background-color .15s',
                    '&:hover': { borderColor: selected ? 'primary.main' : 'text.secondary' },
                  })}
                >
                  <Radio size="small" checked={selected} onChange={() => set('modo', m)} value={m} sx={{ mt: -0.5 }} />
                  <Box>
                    <Typography variant="body2" fontWeight={600}>
                      {t(`directory.integration.modes.${m}.label`)}
                    </Typography>
                    <Typography variant="caption" color="text.secondary">
                      {t(`directory.integration.modes.${m}.description`)}
                    </Typography>
                  </Box>
                </Box>
              );
            })}
          </Box>
        </Stack>

        <Collapse in={enabled} unmountOnExit>
          <Stack spacing={3}>
            {/* ── 2. Proveedor ── */}
            <Stack spacing={1.5}>
              <SubTitle>{t('directory.integration.provider_label')}</SubTitle>
              <ToggleButtonGroup
                exclusive
                size="small"
                color="primary"
                value={preset}
                onChange={(_, v: Preset | null) => v && applyPreset(v)}
                sx={{ flexWrap: 'wrap', gap: 1, '& .MuiToggleButton-root': { borderRadius: '8px !important', border: '1px solid', borderColor: 'divider', textTransform: 'none' } }}
              >
                {PRESETS.map((p) => (
                  <ToggleButton key={p} value={p}>
                    {t(`directory.integration.presets.${p}`)}
                  </ToggleButton>
                ))}
              </ToggleButtonGroup>
              {preset === 'm365_smtp' && (
                <Typography variant="caption" color="warning.main" sx={{ display: 'block' }}>{t('directory.integration.preset_notes.m365_smtp')}</Typography>
              )}
              {preset === 'google' && <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('directory.integration.preset_notes.google')}</Typography>}
              {preset === 'exchange' && (
                <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('directory.integration.preset_notes.exchange')}</Typography>
              )}
            </Stack>

            {/* ── 3. Cuenta de servicio ── */}
            <Stack spacing={2}>
              <SubTitle>{t('directory.integration.account_title')}</SubTitle>
              <Grid2>
                <TextField
                  size="small"
                  label={t('directory.integration.fields.cuenta_email')}
                  placeholder="inventario@empresa.com"
                  value={form.cuenta_email}
                  onChange={(e) => set('cuenta_email', e.target.value)}
                  error={!!errors.cuenta_email}
                  helperText={errors.cuenta_email ?? t('directory.integration.help.cuenta_email')}
                  required
                  type="email"
                  autoComplete="off"
                />
                <TextField
                  size="small"
                  label={t('directory.integration.fields.nombre_remitente')}
                  placeholder={t('directory.integration.placeholders.nombre_remitente')}
                  value={form.nombre_remitente}
                  onChange={(e) => set('nombre_remitente', e.target.value)}
                  helperText={t('directory.integration.help.nombre_remitente')}
                />
                {needsAccountPassword && (
                  <SecretField
                    label={t('directory.integration.fields.cuenta_password')}
                    configured={config.password_configurada}
                    state={secrets.cuenta_password}
                    onChange={(v) => setSecret('cuenta_password', v)}
                    error={errors.cuenta_password}
                  />
                )}
              </Grid2>
              <Box>
                <Button
                  size="small"
                  sx={{ textTransform: 'none', px: 0.5 }}
                  onClick={() => setShowAltUser((v) => !v)}
                >
                  {showAltUser ? t('directory.integration.alt_user_hide') : t('directory.integration.alt_user_show')}
                </Button>
                <Collapse in={showAltUser}>
                  <Box sx={{ pt: 1.5, maxWidth: { md: 'calc(50% - 8px)' } }}>
                    <TextField
                      fullWidth
                      size="small"
                      label={t('directory.integration.fields.cuenta_usuario')}
                      placeholder="EMPRESA\svc_inventario"
                      value={form.cuenta_usuario}
                      onChange={(e) => set('cuenta_usuario', e.target.value)}
                      helperText={t('directory.integration.help.cuenta_usuario')}
                      autoComplete="off"
                    />
                  </Box>
                </Collapse>
              </Box>
            </Stack>

            {/* ── 4. Proveedor: SMTP / Graph ── */}
            {isSmtp ? (
              <Stack spacing={2}>
                <SubTitle>{t('directory.integration.smtp_title')}</SubTitle>
                <Box
                  sx={{
                    display: 'grid',
                    gap: 2,
                    gridTemplateColumns: { xs: '1fr', sm: '2fr 1fr 1fr' },
                  }}
                >
                  <TextField
                    size="small"
                    label={t('directory.integration.fields.smtp_host')}
                    placeholder="mail.empresa.local"
                    value={form.smtp_host}
                    onChange={(e) => set('smtp_host', e.target.value)}
                    error={!!errors.smtp_host}
                    helperText={errors.smtp_host}
                    required
                  />
                  <TextField
                    size="small"
                    label={t('directory.integration.fields.smtp_puerto')}
                    value={form.smtp_puerto}
                    onChange={(e) => set('smtp_puerto', e.target.value.replace(/[^\d]/g, ''))}
                    error={!!errors.smtp_puerto}
                    helperText={errors.smtp_puerto}
                    required
                    slotProps={{ htmlInput: { inputMode: 'numeric', maxLength: 5 } }}
                  />
                  <TextField
                    select
                    size="small"
                    label={t('directory.integration.fields.smtp_seguridad')}
                    value={form.smtp_seguridad}
                    onChange={(e) => set('smtp_seguridad', e.target.value as SmtpSecurity)}
                  >
                    {SECURITY_OPTIONS.map((o) => (
                      <MenuItem key={o} value={o}>
                        {t(`directory.integration.security.${o}`)}
                      </MenuItem>
                    ))}
                  </TextField>
                </Box>
                {form.smtp_seguridad === 'NINGUNA' && (
                  <Typography variant="caption" color="warning.main" sx={{ display: 'block' }}>{t('directory.integration.security_none_warning')}</Typography>
                )}
              </Stack>
            ) : (
              <Stack spacing={2}>
                <SubTitle>{t('directory.integration.graph_title')}</SubTitle>
                <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('directory.integration.graph_info')}</Typography>
                <Grid2>
                  <TextField
                    size="small"
                    label={t('directory.integration.fields.graph_tenant_id')}
                    placeholder="00000000-0000-0000-0000-000000000000"
                    value={form.graph_tenant_id}
                    onChange={(e) => set('graph_tenant_id', e.target.value)}
                    error={!!errors.graph_tenant_id}
                    helperText={errors.graph_tenant_id}
                    required
                    autoComplete="off"
                  />
                  <TextField
                    size="small"
                    label={t('directory.integration.fields.graph_client_id')}
                    placeholder="00000000-0000-0000-0000-000000000000"
                    value={form.graph_client_id}
                    onChange={(e) => set('graph_client_id', e.target.value)}
                    error={!!errors.graph_client_id}
                    helperText={errors.graph_client_id}
                    required
                    autoComplete="off"
                  />
                  <SecretField
                    label={t('directory.integration.fields.graph_client_secret')}
                    configured={config.graph_secret_configurado}
                    state={secrets.graph_client_secret}
                    onChange={(v) => setSecret('graph_client_secret', v)}
                    error={errors.graph_client_secret}
                  />
                </Grid2>
              </Stack>
            )}

            {/* ── 5. Active Directory ── */}
            {withAd && (
              <Stack spacing={2}>
                <SubTitle>{t('directory.integration.ad_title')}</SubTitle>
                <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('directory.integration.ad_info')}</Typography>
                <Grid2>
                  <TextField
                    size="small"
                    label={t('directory.integration.fields.ad_servidor')}
                    placeholder="ldaps://dc01.empresa.local"
                    value={form.ad_servidor}
                    onChange={(e) => set('ad_servidor', e.target.value)}
                    error={!!errors.ad_servidor}
                    helperText={errors.ad_servidor}
                    required
                    autoComplete="off"
                  />
                  <TextField
                    size="small"
                    label={t('directory.integration.fields.ad_base_dn')}
                    placeholder="OU=Usuarios,DC=empresa,DC=local"
                    value={form.ad_base_dn}
                    onChange={(e) => set('ad_base_dn', e.target.value)}
                    error={!!errors.ad_base_dn}
                    helperText={errors.ad_base_dn}
                    required
                  />
                  <TextField
                    size="small"
                    label={t('directory.integration.fields.ad_grupo_base_dn')}
                    placeholder="OU=Grupos,DC=empresa,DC=local"
                    value={form.ad_grupo_base_dn}
                    onChange={(e) => set('ad_grupo_base_dn', e.target.value)}
                    helperText={t('directory.integration.help.ad_grupo_base_dn')}
                  />
                  <TextField
                    size="small"
                    label={t('directory.integration.fields.ad_grupo_admin')}
                    placeholder="GG-Inventario-Admins"
                    value={form.ad_grupo_admin}
                    onChange={(e) => set('ad_grupo_admin', e.target.value)}
                    helperText={t('directory.integration.help.ad_grupo_admin')}
                  />
                  <TextField
                    size="small"
                    label={t('directory.integration.fields.ad_intervalo_min')}
                    value={form.ad_intervalo_min}
                    onChange={(e) => set('ad_intervalo_min', e.target.value.replace(/[^\d]/g, ''))}
                    error={!!errors.ad_intervalo_min}
                    helperText={errors.ad_intervalo_min ?? t('directory.integration.help.ad_intervalo_min')}
                    slotProps={{ htmlInput: { inputMode: 'numeric', maxLength: 5 } }}
                  />
                </Grid2>
                <Stack direction={{ xs: 'column', md: 'row' }} spacing={{ xs: 0, md: 3 }} flexWrap="wrap" useFlexGap>
                  <FormControlLabel
                    control={
                      <Switch checked={form.ad_start_tls} onChange={(e) => set('ad_start_tls', e.target.checked)} />
                    }
                    label={t('directory.integration.fields.ad_start_tls')}
                  />
                  <FormControlLabel
                    control={
                      <Switch
                        checked={form.ad_verificar_cert}
                        onChange={(e) => set('ad_verificar_cert', e.target.checked)}
                      />
                    }
                    label={t('directory.integration.fields.ad_verificar_cert')}
                  />
                  <FormControlLabel
                    control={
                      <Switch checked={form.ad_desactivar} onChange={(e) => set('ad_desactivar', e.target.checked)} />
                    }
                    label={t('directory.integration.fields.ad_desactivar')}
                  />
                </Stack>
                {!form.ad_verificar_cert && (
                  <Typography variant="caption" color="warning.main" sx={{ display: 'block' }}>{t('directory.integration.verify_cert_off_warning')}</Typography>
                )}

                <Box>
                  <FormControlLabel
                    control={
                      <Switch
                        checked={form.ad_usar_cuenta_servicio}
                        onChange={(e) => set('ad_usar_cuenta_servicio', e.target.checked)}
                      />
                    }
                    label={t('directory.integration.fields.ad_usar_cuenta_servicio')}
                  />
                  {form.ad_usar_cuenta_servicio && (
                    <Typography variant="caption" color="text.secondary" display="block">
                      {t('directory.integration.help.ad_usar_cuenta_servicio', {
                        account: form.cuenta_usuario.trim() || form.cuenta_email.trim() || '—',
                      })}
                    </Typography>
                  )}
                </Box>
                {!form.ad_usar_cuenta_servicio && (
                  <Grid2>
                    <TextField
                      size="small"
                      label={t('directory.integration.fields.ad_bind_usuario')}
                      placeholder="svc_inventario@empresa.local"
                      value={form.ad_bind_usuario}
                      onChange={(e) => set('ad_bind_usuario', e.target.value)}
                      error={!!errors.ad_bind_usuario}
                      helperText={errors.ad_bind_usuario ?? t('directory.integration.help.ad_bind_usuario')}
                      required
                      autoComplete="off"
                    />
                    <SecretField
                      label={t('directory.integration.fields.ad_bind_password')}
                      configured={config.ad_bind_password_configurada}
                      state={secrets.ad_bind_password}
                      onChange={(v) => setSecret('ad_bind_password', v)}
                      error={errors.ad_bind_password}
                    />
                  </Grid2>
                )}
              </Stack>
            )}
          </Stack>
        </Collapse>


        {/* ── Resultado de la última prueba ── */}
        {outcome && (
          <Alert severity={outcome.result.ok ? 'success' : 'error'} onClose={() => setOutcome(null)}>
            <AlertTitle>
              {outcome.kind === 'mail'
                ? t('directory.integration.test.mail_title')
                : t('directory.integration.test.ad_title')}
            </AlertTitle>
            {outcomeMessage(outcome)}
            {outcome.result.mensaje && (
              <Typography
                variant="caption"
                component="div"
                sx={{ mt: 0.5, fontFamily: 'monospace', wordBreak: 'break-word', opacity: 0.85 }}
              >
                {outcome.result.mensaje}
              </Typography>
            )}
          </Alert>
        )}

        {/* ── 6. Acciones ── */}
        <Stack
          direction={{ xs: 'column', sm: 'row' }}
          spacing={1}
          justifyContent="space-between"
          alignItems={{ xs: 'stretch', sm: 'center' }}
          sx={{ pt: 2, borderTop: '1px solid', borderColor: 'divider' }}
        >
          <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
            {enabled && (
              <Button
                size="small"
                variant="outlined"
                startIcon={busy === 'mail' ? <CircularProgress size={16} /> : <SendIcon />}
                disabled={isBusy || !validation.mailValid}
                onClick={openMailTest}
              >
                {t('directory.integration.send_test')}
              </Button>
            )}
            {withAd && (
              <Button
                size="small"
                variant="outlined"
                startIcon={busy === 'ad' ? <CircularProgress size={16} /> : <NetworkCheckIcon />}
                disabled={isBusy || !validation.adValid}
                onClick={handleAdTest}
              >
                {t('directory.integration.test_ad')}
              </Button>
            )}
          </Stack>
          <Stack direction="row" spacing={1} alignItems="center" justifyContent="flex-end">
            {dirty && (
              <Button size="small" onClick={() => applyConfig(config)} disabled={isBusy}>
                {t('directory.integration.discard')}
              </Button>
            )}
            <Button
              size="small"
              variant="contained"
              startIcon={busy === 'save' ? <CircularProgress size={16} color="inherit" /> : <SaveIcon />}
              disabled={isBusy || !dirty || !formValid}
              onClick={handleSave}
            >
              {busy === 'save' ? t('common.saving') : t('common.save')}
            </Button>
          </Stack>
        </Stack>
        {dirty && !formValid && (
          <Typography variant="caption" color="error" sx={{ mt: -2, textAlign: 'right' }}>
            {t('directory.integration.errors.fix_before_save')}
          </Typography>
        )}
      </Stack>

      {/* ── Diálogo de destinatario ── */}
      <Dialog open={testDialogOpen} onClose={() => setTestDialogOpen(false)} maxWidth="xs" fullWidth>
        <DialogTitle>{t('directory.integration.test.dialog_title')}</DialogTitle>
        <DialogContent>
          <DialogContentText sx={{ mb: 2 }}>{t('directory.integration.test.dialog_description')}</DialogContentText>
          <TextField
            autoFocus
            fullWidth
            size="small"
            type="email"
            label={t('directory.integration.test.recipient')}
            value={destinatario}
            onChange={(e) => setDestinatario(e.target.value)}
            error={destinatario !== '' && !destinatarioValid}
            helperText={destinatario !== '' && !destinatarioValid ? t('directory.integration.errors.email') : undefined}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && destinatarioValid) handleMailTest();
            }}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setTestDialogOpen(false)}>{t('common.cancel')}</Button>
          <Button variant="contained" onClick={handleMailTest} disabled={!destinatarioValid} startIcon={<SendIcon />}>
            {t('directory.integration.test.send')}
          </Button>
        </DialogActions>
      </Dialog>
    </SectionCard>
  );
};
