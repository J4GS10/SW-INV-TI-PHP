import { useState } from 'react';
import type { FormEvent } from 'react';
import { Alert, Box, Button, CircularProgress, Stack, TextField, Typography } from '@mui/material';
import LockResetIcon from '@mui/icons-material/LockReset';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { getApiErrorDetail, getApiErrorMessage } from '../../../api/errors';
import { useAuth } from '../../../context/auth-context';
import { DEFAULT_PASSWORD_POLICY, meetsPolicy, policyFromViolation } from '../../../utils/password';
import { authService } from '../authService';
import { AuthGateLayout } from './AuthGateLayout';
import { PasswordChecklist } from './PasswordChecklist';
import { PasswordField } from './PasswordField';

type Phase = 'form' | 'signing_in' | 'second_factor';

/**
 * Cambio obligatorio de la contraseña temporal asignada por un administrador.
 * Tras cambiarla (el backend cierra todas las sesiones) inicia sesión de nuevo
 * con la contraseña nueva y continúa: al sistema o al alta de MFA si su rol la exige.
 */
export const ForcePasswordChangePage = () => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const navigate = useNavigate();
  const { user, login, logout } = useAuth();
  const [policy, setPolicy] = useState(DEFAULT_PASSWORD_POLICY);
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [confirm, setConfirm] = useState('');
  const [phase, setPhase] = useState<Phase>('form');
  const [error, setError] = useState('');
  const [challenge, setChallenge] = useState<{ token: string; method: 'TOTP' | 'EMAIL' } | null>(null);
  const [otp, setOtp] = useState('');
  const [verifying, setVerifying] = useState(false);

  const matches = next.length > 0 && next === confirm;
  const differs = next.length > 0 && next !== current;
  const sameAsCurrent = next.length > 0 && next === current;
  const canSubmit = current.length > 0 && meetsPolicy(next, policy) && matches && differs;

  // La contraseña ya cambió: si el re-login automático falla, se vuelve al login.
  const fallbackToLogin = () => {
    enqueueSnackbar(t('security.password_gate.relogin_failed'), { variant: 'info' });
    logout();
    navigate('/login', { replace: true });
  };

  const signInAgain = async (password: string) => {
    setPhase('signing_in');
    try {
      const result = await authService.login(user?.sub ?? '', password);
      if ('requires_2fa' in result) {
        setChallenge({ token: result.challenge_token, method: result.method });
        setPhase('second_factor');
        return;
      }
      enqueueSnackbar(t('security.password_gate.changed'), { variant: 'success' });
      login(result.access_token, result.restriction);
    } catch {
      fallbackToLogin();
    }
  };

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault();
    if (!canSubmit) return;
    setError('');
    setPhase('signing_in');
    try {
      await authService.changeMyPassword(current, next);
    } catch (err) {
      setPolicy((prev) => policyFromViolation(getApiErrorDetail(err), prev));
      setError(getApiErrorMessage(err, t('auth.password_change_failed')));
      setPhase('form');
      return;
    }
    await signInAgain(next);
  };

  const onVerify = async () => {
    if (!challenge || !otp.trim()) return;
    setVerifying(true);
    setError('');
    try {
      const result = await authService.verify2fa(challenge.token, otp.trim());
      enqueueSnackbar(t('security.password_gate.changed'), { variant: 'success' });
      login(result.access_token, result.restriction);
    } catch (err) {
      if (getApiErrorDetail(err) === 'INVALID_OR_EXPIRED_2FA_CHALLENGE') {
        fallbackToLogin();
        return;
      }
      setError(t('auth.error_2fa'));
    } finally {
      setVerifying(false);
    }
  };

  if (phase === 'second_factor' && challenge) {
    return (
      <AuthGateLayout
        icon={<LockResetIcon />}
        title={t('security.password_gate.second_factor_title')}
        description={challenge.method === 'EMAIL' ? t('auth.twofa_email_hint') : t('auth.twofa_totp_hint')}
      >
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        <TextField
          fullWidth
          autoFocus
          label={t('auth.twofa_code')}
          value={otp}
          autoComplete="one-time-code"
          inputProps={{ inputMode: 'numeric' }}
          onChange={(e) => setOtp(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') void onVerify(); }}
          helperText={t('auth.twofa_recovery_hint')}
        />
        <Button fullWidth variant="contained" size="large" sx={{ mt: 2 }} disabled={verifying || !otp.trim()} onClick={onVerify}>
          {verifying ? <CircularProgress size={22} /> : t('auth.twofa_verify')}
        </Button>
      </AuthGateLayout>
    );
  }

  const busy = phase === 'signing_in';

  return (
    <AuthGateLayout
      icon={<LockResetIcon />}
      title={t('security.password_gate.title')}
      description={t('security.password_gate.description')}
    >
      {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
      <Box component="form" onSubmit={onSubmit} noValidate>
        <Stack spacing={2}>
          <PasswordField
            fullWidth
            autoFocus
            label={t('security.password_gate.current')}
            value={current}
            onChange={(e) => setCurrent(e.target.value)}
            autoComplete="current-password"
            disabled={busy}
          />
          <PasswordField
            fullWidth
            label={t('auth.new_password')}
            value={next}
            onChange={(e) => setNext(e.target.value)}
            error={sameAsCurrent}
            helperText={sameAsCurrent ? t('security.password_rules.same_as_current') : undefined}
            autoComplete="new-password"
            disabled={busy}
          />
          <PasswordField
            fullWidth
            label={t('auth.confirm_password')}
            value={confirm}
            onChange={(e) => setConfirm(e.target.value)}
            autoComplete="new-password"
            disabled={busy}
            error={confirm.length > 0 && !matches}
            helperText={confirm.length > 0 && !matches ? t('auth.password_mismatch') : undefined}
          />
          <PasswordChecklist
            password={next}
            showHistory
            policy={policy}
            extra={[
              { label: t('security.password_rules.different'), ok: differs },
              { label: t('security.password_rules.match'), ok: matches },
            ]}
          />
          <Button type="submit" fullWidth variant="contained" size="large" disabled={busy || !canSubmit}>
            {busy ? <CircularProgress size={22} /> : t('security.password_gate.submit')}
          </Button>
          {busy && (
            <Typography variant="caption" color="text.secondary" align="center">
              {t('security.password_gate.signing_in')}
            </Typography>
          )}
        </Stack>
      </Box>
    </AuthGateLayout>
  );
};
