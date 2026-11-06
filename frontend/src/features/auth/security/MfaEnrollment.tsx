import { useState } from 'react';
import type { ReactNode } from 'react';
import {
  Box, Button, ButtonBase, Chip, CircularProgress, IconButton, Link, Paper, Stack, TextField, Tooltip, Typography,
} from '@mui/material';
import { alpha, useTheme } from '@mui/material/styles';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import MailOutlineIcon from '@mui/icons-material/MailOutline';
import PhonelinkLockIcon from '@mui/icons-material/PhonelinkLock';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../../api/errors';
import { authService } from '../authService';
import { RecoveryCodesPanel } from './RecoveryCodesPanel';

type Step = 'choose' | 'totp' | 'email' | 'recovery';

interface Props {
  /** Se llama cuando el usuario confirma que guardó los códigos de recuperación. */
  onComplete: () => void;
  /** Si se pasa, muestra "Cancelar" en la elección de método. */
  onCancel?: () => void;
  completeLabel?: string;
}

const CODE_LENGTH = 6;

interface MethodOptionProps {
  icon: ReactNode;
  title: string;
  caption: string;
  badge?: string;
  disabled?: boolean;
  unavailable?: boolean;
  busy?: boolean;
  onClick: () => void;
}

const MethodOption = ({ icon, title, caption, badge, disabled, unavailable, busy, onClick }: MethodOptionProps) => {
  const theme = useTheme();
  return (
    <ButtonBase
      onClick={onClick}
      disabled={disabled}
      sx={{ width: '100%', textAlign: 'left', borderRadius: 2, opacity: unavailable ? 0.7 : 1 }}
    >
      <Paper
        variant="outlined"
        sx={{
          width: '100%', p: 2, display: 'flex', gap: 1.5, alignItems: 'center',
          transition: theme.transitions.create(['border-color', 'background-color']),
          '&:hover': disabled ? undefined : {
            borderColor: 'primary.main', bgcolor: alpha(theme.palette.primary.main, 0.04),
          },
        }}
      >
        <Box sx={{
          width: 40, height: 40, flexShrink: 0, borderRadius: 1.5, display: 'grid', placeItems: 'center',
          bgcolor: alpha(theme.palette.primary.main, 0.12), color: 'primary.main',
        }}>
          {icon}
        </Box>
        <Box sx={{ minWidth: 0, flex: 1 }}>
          <Stack direction="row" spacing={1} alignItems="center">
            <Typography variant="body2" fontWeight={700}>{title}</Typography>
            {badge && <Chip label={badge} size="small" color="primary" variant="outlined" sx={{ height: 20 }} />}
          </Stack>
          <Typography variant="caption" color={unavailable ? 'error.main' : 'text.secondary'} sx={{ display: 'block' }}>
            {caption}
          </Typography>
        </Box>
        {busy && <CircularProgress size={18} />}
      </Paper>
    </ButtonBase>
  );
};

/**
 * Alta de un segundo factor: app de autenticación (TOTP) o código por correo,
 * y a continuación los códigos de recuperación. Se usa en la pantalla de MFA
 * obligatorio y en "Seguridad de la cuenta".
 */
export const MfaEnrollment = ({ onComplete, onCancel, completeLabel }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const [step, setStep] = useState<Step>('choose');
  const [busy, setBusy] = useState<'totp' | 'email' | 'activate' | null>(null);
  const [qr, setQr] = useState('');
  const [secret, setSecret] = useState('');
  const [code, setCode] = useState('');
  const [codeError, setCodeError] = useState('');
  const [emailUnavailable, setEmailUnavailable] = useState('');
  const [recovery, setRecovery] = useState<string[]>([]);

  const startTotp = async () => {
    setBusy('totp');
    try {
      const setup = await authService.totpSetup();
      setSecret(setup.secret);
      setQr(setup.qr_data_uri);
      setCode('');
      setCodeError('');
      setStep('totp');
    } catch (error) {
      enqueueSnackbar(getApiErrorMessage(error, t('common.error_generic')), { variant: 'error' });
    } finally {
      setBusy(null);
    }
  };

  const startEmail = async (resend = false) => {
    setBusy('email');
    try {
      await authService.emailSetup();
      setCode('');
      setCodeError('');
      setStep('email');
      if (resend) enqueueSnackbar(t('security.mfa.code_resent'), { variant: 'info' });
    } catch (error) {
      // Sin correo configurado (o sin servicio de correo): se ofrece solo la app.
      setEmailUnavailable(getApiErrorMessage(error, t('security.mfa.email_unavailable')));
      setStep('choose');
    } finally {
      setBusy(null);
    }
  };

  const activate = async () => {
    const method = step;
    setBusy('activate');
    setCodeError('');
    try {
      const result = method === 'totp'
        ? await authService.totpActivate(code)
        : await authService.emailActivate(code);
      setRecovery(result.recovery_codes);
      setStep('recovery');
      enqueueSnackbar(t('security.mfa.activated'), { variant: 'success' });
    } catch (error) {
      setCodeError(getApiErrorMessage(error, t('common.error_generic')));
    } finally {
      setBusy(null);
    }
  };

  const copySecret = () => {
    navigator.clipboard?.writeText(secret).then(
      () => enqueueSnackbar(t('security.mfa.key_copied'), { variant: 'success' }),
      () => undefined,
    );
  };

  const codeField = (label: string) => (
    <TextField
      fullWidth
      autoFocus
      label={label}
      value={code}
      error={Boolean(codeError)}
      helperText={codeError || ' '}
      autoComplete="one-time-code"
      inputProps={{ inputMode: 'numeric', maxLength: CODE_LENGTH, style: { letterSpacing: 6, fontFamily: 'monospace' } }}
      onChange={(e) => { setCode(e.target.value.replace(/\D/g, '').slice(0, CODE_LENGTH)); setCodeError(''); }}
      onKeyDown={(e) => { if (e.key === 'Enter' && code.length === CODE_LENGTH) void activate(); }}
    />
  );

  const activateActions = (
    <Stack direction="row" spacing={1} justifyContent="flex-end">
      <Button color="inherit" onClick={() => setStep('choose')} disabled={busy === 'activate'}>
        {t('common.back')}
      </Button>
      <Button variant="contained" onClick={activate} disabled={busy === 'activate' || code.length !== CODE_LENGTH}>
        {busy === 'activate' ? <CircularProgress size={20} /> : t('security.mfa.activate')}
      </Button>
    </Stack>
  );

  if (step === 'recovery') {
    return <RecoveryCodesPanel codes={recovery} onContinue={onComplete} continueLabel={completeLabel} />;
  }

  if (step === 'totp') {
    return (
      <Stack spacing={2}>
        <Typography variant="body2" color="text.secondary">{t('security.mfa.scan')}</Typography>
        {qr && (
          <Box sx={{ display: 'flex', justifyContent: 'center' }}>
            {/* Fondo blanco del tema: los lectores de QR necesitan contraste también en modo oscuro. */}
            <Box
              component="img"
              src={qr}
              alt={t('security.mfa.qr_alt')}
              sx={{ width: 184, height: 184, p: 1, borderRadius: 2, border: '1px solid', borderColor: 'divider', bgcolor: 'common.white' }}
            />
          </Box>
        )}
        <Box>
          <Typography variant="caption" color="text.secondary">{t('security.mfa.manual_key')}</Typography>
          <Paper variant="outlined" sx={{ display: 'flex', alignItems: 'center', gap: 1, pl: 1.5, pr: 0.5, py: 0.5, bgcolor: 'action.hover' }}>
            <Typography sx={{ fontFamily: 'monospace', fontSize: '0.85rem', wordBreak: 'break-all', flex: 1 }}>
              {secret}
            </Typography>
            <Tooltip title={t('security.mfa.copy_key')}>
              <IconButton size="small" onClick={copySecret} aria-label={t('security.mfa.copy_key')}>
                <ContentCopyIcon fontSize="small" />
              </IconButton>
            </Tooltip>
          </Paper>
        </Box>
        {codeField(t('security.mfa.app_code'))}
        {activateActions}
      </Stack>
    );
  }

  if (step === 'email') {
    return (
      <Stack spacing={2}>
        <Typography variant="body2" color="text.secondary">{t('security.mfa.email_sent')}</Typography>
        {codeField(t('security.mfa.email_code'))}
        <Box>
          <Link
            component="button"
            type="button"
            variant="body2"
            underline="hover"
            onClick={() => void startEmail(true)}
            disabled={busy !== null}
          >
            {t('security.mfa.resend')}
          </Link>
        </Box>
        {activateActions}
      </Stack>
    );
  }

  return (
    <Stack spacing={1.5}>
      <Typography variant="body2" color="text.secondary">{t('security.mfa.choose')}</Typography>
      <MethodOption
        icon={<PhonelinkLockIcon fontSize="small" />}
        title={t('security.mfa.app_title')}
        caption={t('security.mfa.app_caption')}
        badge={t('security.mfa.recommended')}
        disabled={busy !== null}
        busy={busy === 'totp'}
        onClick={startTotp}
      />
      <MethodOption
        icon={<MailOutlineIcon fontSize="small" />}
        title={t('security.mfa.email_title')}
        caption={emailUnavailable || t('security.mfa.email_caption')}
        disabled={busy !== null || Boolean(emailUnavailable)}
        unavailable={Boolean(emailUnavailable)}
        busy={busy === 'email'}
        onClick={() => void startEmail()}
      />
      {onCancel && (
        <Stack direction="row" justifyContent="flex-end">
          <Button color="inherit" onClick={onCancel}>{t('common.cancel')}</Button>
        </Stack>
      )}
    </Stack>
  );
};
