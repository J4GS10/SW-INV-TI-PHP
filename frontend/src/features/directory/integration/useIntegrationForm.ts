import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../../api/errors';
import { useAuth } from '../../../context/auth-context';
import { useLiveRefresh } from '../../../hooks/useLiveRefresh';
import { directoryService } from '../directoryService';
import { isForbidden, isValidEmail } from '../directoryUtils';
import type { IntegrationOut } from '../types';
import { EMPTY_SECRETS, detectPreset, toForm, toPayload, validate, withPreset } from './integrationForm';
import type { FormState, Preset, SecretKey, SecretState, Secrets, Validation } from './integrationForm';
import type { TestOutcome } from './MailTestDialog';

export type Busy = 'save' | 'mail' | 'ad' | 'unlock' | null;

/**
 * Estado y acciones de la integración de correo y directorio: carga, cambios sin guardar,
 * validación, guardado, pruebas de correo y de AD, y desbloqueo tras credenciales rechazadas.
 */
export const useIntegrationForm = (onSaved?: () => void) => {
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
  const [busy, setBusy] = useState<Busy>(null);
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

  const validation: Validation = useMemo(
    () => (form && config ? validate(form, secrets, config, t) : { errors: {}, mailValid: false, adValid: false }),
    [form, secrets, config, t],
  );

  const set = <K extends keyof FormState>(key: K, value: FormState[K]) =>
    setForm((prev) => (prev ? { ...prev, [key]: value } : prev));

  const setSecret = (key: SecretKey, next: SecretState) => setSecrets((prev) => ({ ...prev, [key]: next }));

  const applyPreset = (p: Preset) => {
    setPreset(p);
    setForm((prev) => (prev ? withPreset(prev, p) : prev));
  };

  const reportError = (err: unknown, fallbackKey = 'common.error_generic') =>
    enqueueSnackbar(isForbidden(err) ? t('directory.forbidden_action') : getApiErrorMessage(err, t(fallbackKey)), {
      variant: 'error',
    });

  /** Recarga solo el estado de bloqueo, sin perder cambios sin guardar del formulario. */
  const refreshLock = async () => {
    try {
      const fresh = await directoryService.getIntegration();
      setConfig((prev) => (prev ? { ...prev, bloqueo: fresh.bloqueo } : prev));
    } catch {
      /* no crítico */
    }
  };

  const save = async () => {
    if (!form) return;
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

  const sendMailTest = async () => {
    if (!form) return;
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

  const testAd = async () => {
    if (!form) return;
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

  const unlock = async () => {
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

  const discard = () => {
    if (config) applyConfig(config);
  };

  return {
    config, form, secrets, preset, showAltUser, loading, loadError, busy, dirty, validation,
    testDialogOpen, destinatario, outcome,
    set, setSecret, applyPreset, setDestinatario, setOutcome, setTestDialogOpen,
    toggleAltUser: () => setShowAltUser((v) => !v),
    save, discard, openMailTest, sendMailTest, testAd, unlock,
  };
};
