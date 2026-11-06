import { useState } from 'react';
import type { FormEvent } from 'react';
import { Button, CircularProgress, DialogActions, DialogContent, DialogTitle, Stack, Typography } from '@mui/material';
import LockResetIcon from '@mui/icons-material/LockReset';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { useNavigate } from 'react-router-dom';
import { authService } from './authService';
import { useAuth } from '../../context/auth-context';
import { getApiErrorDetail, getApiErrorMessage } from '../../api/errors';
import { DEFAULT_PASSWORD_POLICY, meetsPolicy, policyFromViolation } from '../../utils/password';
import { PasswordChecklist } from './security/PasswordChecklist';
import { PasswordField } from './security/PasswordField';
import { Dialog } from '../../components/ui/Dialog';

interface Props {
  open: boolean;
  onClose: () => void;
}

export const ChangePasswordDialog = ({ open, onClose }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const { logout } = useAuth();
  const navigate = useNavigate();
  const [policy, setPolicy] = useState(DEFAULT_PASSWORD_POLICY);
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [confirm, setConfirm] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const matches = next.length > 0 && next === confirm;
  const differs = next.length > 0 && next !== current;
  const sameAsCurrent = next.length > 0 && next === current;
  const canSubmit = current.length > 0 && meetsPolicy(next, policy) && matches && differs;

  const handleClose = () => {
    setCurrent('');
    setNext('');
    setConfirm('');
    onClose();
  };

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault();
    if (!canSubmit) return;
    setSubmitting(true);
    try {
      await authService.changeMyPassword(current, next);
      enqueueSnackbar(t('auth.password_changed'), { variant: 'success' });
      handleClose();
      // El backend revoca todas las sesiones: hay que iniciar sesión de nuevo.
      setTimeout(() => {
        logout();
        navigate('/login');
      }, 600);
    } catch (error) {
      setPolicy((prev) => policyFromViolation(getApiErrorDetail(error), prev));
      enqueueSnackbar(getApiErrorMessage(error, t('auth.password_change_failed')), { variant: 'error' });
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onClose={handleClose} maxWidth="xs" fullWidth>
      <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
        <LockResetIcon />
        {t('auth.change_password')}
      </DialogTitle>
      <form onSubmit={onSubmit} noValidate>
        <DialogContent>
          <Stack spacing={2}>
            <PasswordField
              fullWidth
              autoFocus
              label={t('auth.current_password')}
              value={current}
              onChange={(e) => setCurrent(e.target.value)}
              autoComplete="current-password"
            />
            <PasswordField
              fullWidth
              label={t('auth.new_password')}
              value={next}
              onChange={(e) => setNext(e.target.value)}
              error={sameAsCurrent}
              helperText={sameAsCurrent ? t('security.password_rules.same_as_current') : undefined}
              autoComplete="new-password"
            />
            <PasswordField
              fullWidth
              label={t('auth.confirm_password')}
              value={confirm}
              onChange={(e) => setConfirm(e.target.value)}
              autoComplete="new-password"
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
            <Typography variant="caption" color="text.secondary">
              {t('auth.password_change_notice')}
            </Typography>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={handleClose} disabled={submitting}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" variant="contained" disabled={submitting || !canSubmit}>
            {submitting ? <CircularProgress size={20} /> : t('common.save')}
          </Button>
        </DialogActions>
      </form>
    </Dialog>
  );
};
