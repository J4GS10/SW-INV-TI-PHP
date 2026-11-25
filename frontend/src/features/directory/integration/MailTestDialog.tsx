import { Alert, AlertTitle, Button, DialogActions, DialogContent, DialogContentText, DialogTitle, TextField, Typography } from '@mui/material';
import SendIcon from '@mui/icons-material/Send';
import { useTranslation } from 'react-i18next';
import { Dialog } from '../../../components/ui/Dialog';
import { isValidEmail } from '../directoryUtils';
import type { AdTestResult, MailTestResult } from '../types';

export type TestKind = 'mail' | 'ad';
export interface TestOutcome {
  kind: TestKind;
  result: MailTestResult | AdTestResult;
  destinatario?: string;
}

interface DialogProps {
  open: boolean;
  recipient: string;
  onRecipient: (value: string) => void;
  onClose: () => void;
  onSend: () => void;
}

/** Pide a quién se envía el correo de prueba. */
export const MailTestDialog = ({ open, recipient, onRecipient, onClose, onSend }: DialogProps) => {
  const { t } = useTranslation();
  const valid = isValidEmail(recipient);

  return (
    <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth>
      <DialogTitle>{t('directory.integration.test.dialog_title')}</DialogTitle>
      <DialogContent>
        <DialogContentText sx={{ mb: 2 }}>{t('directory.integration.test.dialog_description')}</DialogContentText>
        <TextField
          autoFocus
          fullWidth
          size="small"
          type="email"
          label={t('directory.integration.test.recipient')}
          value={recipient}
          onChange={(e) => onRecipient(e.target.value)}
          error={recipient !== '' && !valid}
          helperText={recipient !== '' && !valid ? t('directory.integration.errors.email') : undefined}
          onKeyDown={(e) => {
            if (e.key === 'Enter' && valid) onSend();
          }}
        />
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>{t('common.cancel')}</Button>
        <Button variant="contained" onClick={onSend} disabled={!valid} startIcon={<SendIcon />}>
          {t('directory.integration.test.send')}
        </Button>
      </DialogActions>
    </Dialog>
  );
};

/** Resultado de la última prueba de correo o de directorio. */
export const TestOutcomeAlert = ({ outcome, onClose }: { outcome: TestOutcome; onClose: () => void }) => {
  const { t } = useTranslation();
  const message = outcome.result.ok
    ? outcome.kind === 'mail'
      ? t('directory.integration.test.mail_ok', { to: outcome.destinatario })
      : t('directory.integration.test.ad_ok', { count: (outcome.result as AdTestResult).usuarios_encontrados })
    : t(`directory.integration.test.codes.${outcome.result.codigo}`, t('directory.integration.test.codes.ERROR'));

  return (
    <Alert severity={outcome.result.ok ? 'success' : 'error'} onClose={onClose}>
      <AlertTitle>
        {outcome.kind === 'mail'
          ? t('directory.integration.test.mail_title')
          : t('directory.integration.test.ad_title')}
      </AlertTitle>
      {message}
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
  );
};
