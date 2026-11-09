import { useEffect, useState } from 'react';
import { Button, DialogActions, DialogContent, DialogTitle, Stack, TextField, Typography } from '@mui/material';
import GppMaybeIcon from '@mui/icons-material/GppMaybe';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { Dialog } from '../../components/ui/Dialog';
import { DateField } from '../../components/ui/DateField';
import { getApiErrorMessage } from '../../api/errors';
import { userService } from './userService';

/** Longest exemption the server accepts (MFA_EXEMPTION_MAX_DAYS). */
const MAX_DAYS = 30;
const isoDay = (date: Date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
const inDays = (days: number) => { const d = new Date(); d.setDate(d.getDate() + days); return isoDay(d); };

interface Props {
  /** Account to exempt (null = closed). */
  user: { id: string; name: string } | null;
  onClose: () => void;
  onSaved: () => void;
}

/** Lets the system administrator skip MFA for one account until a date, with a reason. */
export const MfaExemptionDialog = ({ user, onClose, onSaved }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const [until, setUntil] = useState(inDays(7));
  const [reason, setReason] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (user) { setUntil(inDays(7)); setReason(''); }
  }, [user]);

  const save = async () => {
    if (!user) return;
    setSaving(true);
    try {
      await userService.grantMfaExemption(user.id, until, reason.trim());
      enqueueSnackbar(t('users.mfa_exemption.saved', { name: user.name }), { variant: 'success' });
      onSaved();
      onClose();
    } catch (error) {
      enqueueSnackbar(getApiErrorMessage(error, t('common.error_save')), { variant: 'error' });
    } finally {
      setSaving(false);
    }
  };

  const valid = Boolean(until) && reason.trim().length >= 5;

  return (
    <Dialog open={Boolean(user)} onClose={onClose} maxWidth="xs" fullWidth>
      <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
        <GppMaybeIcon color="warning" />
        {t('users.mfa_exemption.title')}
      </DialogTitle>
      <DialogContent dividers>
        <Stack spacing={2}>
          <Typography variant="body2" color="text.secondary">
            {t('users.mfa_exemption.caption', { name: user?.name ?? '', days: MAX_DAYS })}
          </Typography>
          <DateField fullWidth required label={t('users.mfa_exemption.until')} value={until}
            onChange={(e) => setUntil(e.target.value)} inputProps={{ min: inDays(0), max: inDays(MAX_DAYS) }} />
          <TextField fullWidth required multiline minRows={2} label={t('users.mfa_exemption.reason')} value={reason}
            onChange={(e) => setReason(e.target.value)} inputProps={{ maxLength: 200 }}
            helperText={t('users.mfa_exemption.reason_help')} />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={saving}>{t('common.cancel')}</Button>
        <Button variant="contained" onClick={() => void save()} disabled={!valid || saving}>{t('users.mfa_exemption.save')}</Button>
      </DialogActions>
    </Dialog>
  );
};
