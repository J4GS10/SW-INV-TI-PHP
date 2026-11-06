import { useState } from 'react';
import { DialogTitle, DialogContent, DialogActions, Button, TextField, CircularProgress, Alert, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { authService } from './authService';
import { Dialog } from '../../components/ui/Dialog';
interface Props {
    open: boolean;
    onClose: () => void;
}
export const ForgotPasswordDialog = ({ open, onClose }: Props) => {
    const { t } = useTranslation();
    const [identifier, setIdentifier] = useState('');
    const [loading, setLoading] = useState(false);
    const [sent, setSent] = useState(false);
    const close = () => { setIdentifier(''); setSent(false); onClose(); };
    const submit = async () => {
        if (!identifier.trim())
            return;
        setLoading(true);
        try {
            await authService.requestPasswordReset(identifier.trim());
            // Anti-enumeración: el backend siempre responde igual; mostramos un
            // mensaje neutro independientemente de si la cuenta existe.
            setSent(true);
        }
        catch {
            setSent(true); // mismo mensaje neutro ante cualquier error
        }
        finally {
            setLoading(false);
        }
    };
    return (<Dialog open={open} onClose={close} maxWidth="xs" fullWidth>
      <DialogTitle>{t('auth.forgot_title')}</DialogTitle>
      <DialogContent>
        {sent ? (<Alert severity="success">
            {t('auth.forgot_sent')}
          </Alert>) : (<>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
              {t('auth.forgot_help')}
            </Typography>
            <TextField autoFocus fullWidth label={t('auth.user_or_email')} value={identifier} onChange={(e) => setIdentifier(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter')
            submit(); }}/>
          </>)}
      </DialogContent>
      <DialogActions>
        <Button onClick={close} color="inherit">{sent ? t('common.close') : t('common.cancel')}</Button>
        {!sent && (<Button variant="contained" onClick={submit} disabled={loading || !identifier.trim()}>
            {loading ? <CircularProgress size={22}/> : t('auth.forgot_send')}
          </Button>)}
      </DialogActions>
    </Dialog>);
};
