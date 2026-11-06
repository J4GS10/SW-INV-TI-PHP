import { Alert, Button, DialogActions, DialogContent, DialogTitle, IconButton, InputAdornment, Stack, TextField, Tooltip, Typography } from '@mui/material';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import KeyIcon from '@mui/icons-material/Key';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { Dialog } from '../../components/ui/Dialog';

interface Props {
  /** Contraseña temporal devuelta por el backend (null = cerrado). */
  password: string | null;
  userName: string;
  onClose: () => void;
}

/**
 * Muestra UNA sola vez la contraseña temporal generada por el backend. Solo se
 * cierra con el botón (no con clic fuera), para no perderla por accidente.
 */
export const TemporaryPasswordDialog = ({ password, userName, onClose }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();

  const copy = () => {
    if (!password) return;
    navigator.clipboard?.writeText(password).then(
      () => enqueueSnackbar(t('users.temp_password.copied'), { variant: 'success' }),
      () => undefined,
    );
  };

  return (
    <Dialog open={Boolean(password)} maxWidth="xs" fullWidth>
      <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
        <KeyIcon color="primary" />
        {t('users.temp_password.title')}
      </DialogTitle>
      <DialogContent dividers>
        <Stack spacing={2}>
          <Typography variant="body2" color="text.secondary">
            {t('users.temp_password.for_user', { name: userName })}
          </Typography>
          <TextField
            fullWidth
            value={password ?? ''}
            label={t('users.temp_password.label')}
            InputProps={{
              readOnly: true,
              sx: { fontFamily: 'monospace', fontSize: '1.05rem', letterSpacing: 1 },
              endAdornment: (
                <InputAdornment position="end">
                  <Tooltip title={t('users.temp_password.copy')}>
                    <IconButton onClick={copy} edge="end" aria-label={t('users.temp_password.copy')}>
                      <ContentCopyIcon fontSize="small" />
                    </IconButton>
                  </Tooltip>
                </InputAdornment>
              ),
            }}
            onFocus={(e) => e.target.select()}
          />
          <Alert severity="warning">{t('users.temp_password.shown_once')}</Alert>
          <Typography variant="caption" color="text.secondary">
            {t('users.temp_password.must_change')}
          </Typography>
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button variant="contained" onClick={onClose}>{t('users.temp_password.done')}</Button>
      </DialogActions>
    </Dialog>
  );
};
