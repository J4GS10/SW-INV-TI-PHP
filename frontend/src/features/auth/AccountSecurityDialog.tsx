import { useCallback, useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { Box, Button, Chip, CircularProgress, DialogActions, DialogContent, DialogTitle, Divider, List, ListItem, ListItemIcon, ListItemText, Stack, Typography } from '@mui/material';
import DevicesOtherIcon from '@mui/icons-material/DevicesOther';
import GppGoodIcon from '@mui/icons-material/GppGood';
import GppMaybeIcon from '@mui/icons-material/GppMaybe';
import KeyIcon from '@mui/icons-material/Key';
import LockResetIcon from '@mui/icons-material/LockReset';
import NoEncryptionIcon from '@mui/icons-material/NoEncryption';
import ShieldIcon from '@mui/icons-material/Shield';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../api/errors';
import { useConfirm } from '../../components/confirm-context';
import { useAuth } from '../../context/auth-context';
import { authService } from './authService';
import type { TwoFactorStatus } from './authService';
import { MfaEnrollment } from './security/MfaEnrollment';
import { PasswordField } from './security/PasswordField';
import { RecoveryCodesPanel } from './security/RecoveryCodesPanel';
import { SessionsList } from './security/SessionsList';
import { scopeService } from '../../api/scopeService';
import type { SesionActiva } from '../../types/users';
import { Dialog } from '../../components/ui/Dialog';

interface Props {
  open: boolean;
  onClose: () => void;
  /** Abre el diálogo de cambio de contraseña (este se cierra). */
  onChangePassword: () => void;
}

type View = 'overview' | 'enroll' | 'recovery' | 'disable' | 'regenerate';

interface RowProps {
  icon: ReactNode;
  primary: ReactNode;
  secondary: string;
  action?: ReactNode;
}

const Row = ({ icon, primary, secondary, action }: RowProps) => (
  <ListItem disableGutters sx={{ gap: 2, py: 1.25, alignItems: 'center' }}>
    <ListItemIcon sx={{ minWidth: 0, color: 'text.secondary' }}>{icon}</ListItemIcon>
    <ListItemText
      sx={{ my: 0, minWidth: 0 }}
      primary={primary}
      secondary={secondary}
      primaryTypographyProps={{ variant: 'body2', fontWeight: 700, component: 'div' }}
      secondaryTypographyProps={{ variant: 'caption' }}
    />
    {action && <Box sx={{ flexShrink: 0 }}>{action}</Box>}
  </ListItem>
);

/** Panel personal "Seguridad de la cuenta": MFA, códigos de recuperación, contraseña y sesiones. */
export const AccountSecurityDialog = ({ open, onClose, onChangePassword }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const confirm = useConfirm();
  const navigate = useNavigate();
  const { logoutAll } = useAuth();
  const [status, setStatus] = useState<TwoFactorStatus | null>(null);
  const [loading, setLoading] = useState(false);
  const [view, setView] = useState<View>('overview');
  const [recovery, setRecovery] = useState<string[]>([]);
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [sessions, setSessions] = useState<SesionActiva[]>([]);
  const [closingId, setClosingId] = useState<string | null>(null);

  const loadSessions = useCallback(async () => {
    try {
      setSessions(await scopeService.mySessions());
    } catch {
      setSessions([]);
    }
  }, []);

  const closeSession = async (session: SesionActiva) => {
    if (!(await confirm({
      message: t('sessions.close_confirm', { device: session.dispositivo?.replace(/Otro|Desconocido/g, (w) => (w === 'Otro' ? t('sessions.other') : t('sessions.unknown'))) ?? t('sessions.unknown_device') }),
      confirmText: t('sessions.close'),
      destructive: true,
    }))) return;
    setClosingId(session.id);
    try {
      await scopeService.closeMySession(session.id);
      enqueueSnackbar(t('sessions.closed'), { variant: 'success' });
      await loadSessions();
    } catch (error) {
      enqueueSnackbar(getApiErrorMessage(error, t('common.error_generic')), { variant: 'error' });
    } finally {
      setClosingId(null);
    }
  };

  const loadStatus = useCallback(async () => {
    setLoading(true);
    try {
      setStatus(await authService.get2faStatus());
    } catch {
      setStatus(null);
      enqueueSnackbar(t('common.error_loading'), { variant: 'error' });
    } finally {
      setLoading(false);
    }
  }, [enqueueSnackbar, t]);

  useEffect(() => {
    if (!open) return;
    setView('overview');
    setRecovery([]);
    setPassword('');
    void loadStatus();
    void loadSessions();
  }, [open, loadStatus, loadSessions]);

  const backToOverview = () => {
    setView('overview');
    setRecovery([]);
    setPassword('');
    void loadStatus();
  };

  const askRegenerate = async () => {
    if (!(await confirm({
      message: t('security.account.regenerate_confirm'),
      confirmText: t('security.account.regenerate'),
    }))) return;
    setPassword('');
    setView('regenerate');
  };

  const regenerate = async () => {
    setBusy(true);
    try {
      const result = await authService.regenerateRecoveryCodes(password);
      setPassword('');
      setRecovery(result.recovery_codes);
      setView('recovery');
    } catch (error) {
      enqueueSnackbar(getApiErrorMessage(error, t('common.error_generic')), { variant: 'error' });
    } finally {
      setBusy(false);
    }
  };

  const disable = async () => {
    setBusy(true);
    try {
      await authService.disable2fa(password);
      enqueueSnackbar(t('security.account.disabled_done'), { variant: 'success' });
      backToOverview();
    } catch (error) {
      enqueueSnackbar(getApiErrorMessage(error, t('common.error_generic')), { variant: 'error' });
    } finally {
      setBusy(false);
    }
  };

  const closeAllSessions = async () => {
    if (!(await confirm({
      message: t('security.account.close_sessions_confirm'),
      confirmText: t('security.account.close_sessions'),
      destructive: true,
    }))) return;
    onClose();
    await logoutAll().catch(() => undefined);
    navigate('/login', { replace: true });
  };

  const mfaStatusLabel = !status?.habilitado
    ? t('security.account.mfa_off')
    : status.metodo === 'EMAIL' ? t('security.account.mfa_on_email') : t('security.account.mfa_on_app');

  const overview = (
    <List disablePadding>
      <Row
        icon={status?.habilitado ? <GppGoodIcon color="success" /> : <GppMaybeIcon color={status?.requerido ? 'warning' : 'inherit'} />}
        primary={(
          <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
            <span>{t('security.account.mfa')}</span>
            {status?.requerido && (
              <Chip size="small" variant="outlined" label={t('security.account.required_by_role')} sx={{ height: 20 }} />
            )}
          </Stack>
        )}
        secondary={mfaStatusLabel}
        action={!status?.habilitado && (
          <Button size="small" variant="contained" onClick={() => setView('enroll')}>
            {t('security.account.setup')}
          </Button>
        )}
      />
      {status?.habilitado && (
        <Row
          icon={<KeyIcon />}
          primary={t('security.account.recovery_codes')}
          secondary={t('security.account.recovery_caption')}
          action={(
            <Button size="small" variant="outlined" onClick={() => void askRegenerate()} disabled={busy}>
              {t('security.account.regenerate')}
            </Button>
          )}
        />
      )}
      {status?.habilitado && !status.requerido && (
        <Row
          icon={<NoEncryptionIcon />}
          primary={t('security.account.disable_title')}
          secondary={t('security.account.disable_caption')}
          action={(
            <Button size="small" color="error" variant="outlined" onClick={() => setView('disable')}>
              {t('security.account.disable')}
            </Button>
          )}
        />
      )}
      <Divider component="li" sx={{ my: 0.5 }} />
      <Row
        icon={<LockResetIcon />}
        primary={t('security.account.password')}
        secondary={t('security.account.password_caption')}
        action={(
          <Button size="small" variant="outlined" onClick={() => { onClose(); onChangePassword(); }}>
            {t('security.account.change')}
          </Button>
        )}
      />
      <Row
        icon={<DevicesOtherIcon />}
        primary={t('security.account.sessions')}
        secondary={t('security.account.sessions_caption')}
        action={(
          <Button size="small" variant="outlined" color="error" onClick={closeAllSessions}>
            {t('security.account.close_sessions')}
          </Button>
        )}
      />
      <Box component="li" sx={{ listStyle: 'none', pl: { xs: 0, sm: 7 }, pb: 1 }}>
        <SessionsList sessions={sessions} onCloseSession={closeSession} busyId={closingId} />
      </Box>
    </List>
  );

  const disableView = (
    <Stack spacing={2}>
      <Typography variant="body2" color="text.secondary">{t('security.account.disable_caption')}</Typography>
      <PasswordField
        fullWidth
        autoFocus
        size="small"
        label={t('security.account.disable_password')}
        value={password}
        autoComplete="current-password"
        onChange={(e) => setPassword(e.target.value)}
        onKeyDown={(e) => { if (e.key === 'Enter' && password) void disable(); }}
      />
      <Stack direction="row" spacing={1} justifyContent="flex-end">
        <Button color="inherit" onClick={() => setView('overview')} disabled={busy}>{t('common.cancel')}</Button>
        <Button color="error" variant="contained" onClick={disable} disabled={busy || !password}>
          {busy ? <CircularProgress size={20} /> : t('security.account.disable')}
        </Button>
      </Stack>
    </Stack>
  );

  // Regenerar los códigos exige volver a autenticarse: un token robado no basta.
  const regenerateView = (
    <Stack spacing={2}>
      <Typography variant="body2" color="text.secondary">{t('security.account.regenerate_confirm')}</Typography>
      <PasswordField
        fullWidth
        autoFocus
        size="small"
        label={t('security.account.regenerate_password')}
        value={password}
        autoComplete="current-password"
        onChange={(e) => setPassword(e.target.value)}
        onKeyDown={(e) => { if (e.key === 'Enter' && password) void regenerate(); }}
      />
      <Stack direction="row" spacing={1} justifyContent="flex-end">
        <Button color="inherit" onClick={() => setView('overview')} disabled={busy}>{t('common.cancel')}</Button>
        <Button variant="contained" onClick={() => void regenerate()} disabled={busy || !password}>
          {busy ? <CircularProgress size={20} /> : t('security.account.regenerate')}
        </Button>
      </Stack>
    </Stack>
  );

  let body: ReactNode;
  if (loading && view === 'overview') {
    body = <Box sx={{ display: 'flex', justifyContent: 'center', py: 4 }}><CircularProgress /></Box>;
  } else if (view === 'enroll') {
    body = <MfaEnrollment onComplete={backToOverview} onCancel={() => setView('overview')} completeLabel={t('common.close')} />;
  } else if (view === 'recovery') {
    body = <RecoveryCodesPanel codes={recovery} onContinue={backToOverview} continueLabel={t('common.close')} />;
  } else if (view === 'disable') {
    body = disableView;
  } else if (view === 'regenerate') {
    body = regenerateView;
  } else {
    body = overview;
  }

  // Durante el alta o con códigos en pantalla no se cierra por accidente (se perderían).
  const dismissible = view === 'overview' || view === 'disable' || view === 'regenerate';

  return (
    <Dialog open={open} onClose={dismissible ? onClose : undefined} maxWidth="sm" fullWidth>
      <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
        <ShieldIcon color="primary" />
        {view === 'enroll' ? t('security.account.mfa') : t('security.account.title')}
      </DialogTitle>
      <DialogContent dividers>{body}</DialogContent>
      {dismissible && (
        <DialogActions>
          <Button onClick={onClose}>{t('common.close')}</Button>
        </DialogActions>
      )}
    </Dialog>
  );
};
