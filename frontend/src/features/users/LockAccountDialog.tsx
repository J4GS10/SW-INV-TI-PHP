import { useEffect, useState } from 'react';
import { Button, DialogActions, DialogContent, DialogTitle, Stack, TextField, Typography } from '@mui/material';
import LockIcon from '@mui/icons-material/Lock';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { Dialog } from '../../components/ui/Dialog';
import { DateField } from '../../components/ui/DateField';
import { getApiErrorMessage } from '../../api/errors';
import { userService } from './userService';

const isoDay = (date: Date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
const tomorrow = () => { const d = new Date(); d.setDate(d.getDate() + 1); return isoDay(d); };

interface Props {
  /** Account to lock (null = closed). */
  user: { id: string; name: string } | null;
  onClose: () => void;
  onSaved: () => void;
}

/**
 * The system administrator suspends an account with a reason, until a date or with no end.
 * Its sessions close at once; only the administrator (or the date) lifts it.
 */
export const LockAccountDialog = ({ user, onClose, onSaved }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const [until, setUntil] = useState('');
  const [reason, setReason] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (user) { setUntil(''); setReason(''); }
  }, [user]);

  const save = async () => {
    if (!user) return;
    setSaving(true);
    try {
      await userService.lock(user.id, reason.trim(), until || null);
      enqueueSnackbar(t('users.lock.saved', { name: user.name }), { variant: 'success' });
      onSaved();
      onClose();
    } catch (error) {
      enqueueSnackbar(getApiErrorMessage(error, t('common.error_save')), { variant: 'error' });
    } finally {
      setSaving(false);
    }
  };

  const valid = reason.trim().length >= 5;

  return (
    <Dialog open={Boolean(user)} onClose={onClose} maxWidth="xs" fullWidth>
      <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
        <LockIcon color="error" />
        {t('users.lock.title')}
      </DialogTitle>
      <DialogContent dividers>
        <Stack spacing={2}>
          <Typography variant="body2" color="text.secondary">
            {t('users.lock.caption', { name: user?.name ?? '' })}
          </Typography>
          <TextField fullWidth required multiline minRows={2} autoFocus label={t('users.lock.reason')} value={reason}
            onChange={(e) => setReason(e.target.value)} inputProps={{ maxLength: 500 }}
            helperText={t('users.lock.reason_help')} />
          <DateField fullWidth label={t('users.lock.until')} value={until}
            onChange={(e) => setUntil(e.target.value)} inputProps={{ min: tomorrow() }}
            helperText={t('users.lock.until_help')} />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={saving}>{t('common.cancel')}</Button>
        <Button variant="contained" color="error" onClick={() => void save()} disabled={!valid || saving}>{t('users.lock.save')}</Button>
      </DialogActions>
    </Dialog>
  );
};
