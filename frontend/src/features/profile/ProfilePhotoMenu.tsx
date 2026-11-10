import { useRef, useState } from 'react';
import { Box, CircularProgress, Divider, ListItemIcon, ListItemText, MenuItem, Typography } from '@mui/material';
import AddAPhotoIcon from '@mui/icons-material/AddAPhotoOutlined';
import DeleteIcon from '@mui/icons-material/DeleteOutline';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';

import { getApiErrorMessage } from '../../api/errors';
import { UserAvatar } from '../../components/ui/UserAvatar';
import { PHOTO_ACCEPT, PHOTO_MAX_BYTES, profilePhotoService } from './profilePhotoService';

interface Props {
  name: string;
  subtitle: string;
  photoUrl: string | null;
  onPhotoChange: (url: string | null) => void;
  onDone: () => void;
}

/** Top of the account menu: who is signed in, with their photo, and how to change it in one step. */
export const ProfilePhotoMenu = ({ name, subtitle, photoUrl, onPhotoChange, onDone }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const input = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);

  const run = async (action: () => Promise<string | null>, done: string) => {
    setBusy(true);
    try {
      onPhotoChange(await action());
      enqueueSnackbar(t(done), { variant: 'success' });
      onDone();
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
    } finally {
      setBusy(false);
    }
  };

  const picked = (file: File | undefined) => {
    if (!file) return;
    if (file.size > PHOTO_MAX_BYTES) {
      enqueueSnackbar(t('profile.photo_too_large'), { variant: 'warning' });
      return;
    }
    void run(() => profilePhotoService.upload(file), 'profile.photo_saved');
  };

  return (
    <>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5, px: 2, pt: 1, pb: 1.5 }}>
        <UserAvatar name={name} photoUrl={photoUrl} size={48} />
        <Box sx={{ minWidth: 0 }}>
          <Typography variant="body2" fontWeight={700} noWrap>{name}</Typography>
          <Typography variant="caption" color="text.secondary" noWrap sx={{ display: 'block' }}>{subtitle}</Typography>
        </Box>
      </Box>
      <input ref={input} type="file" hidden accept={PHOTO_ACCEPT}
        onChange={(e) => { picked(e.target.files?.[0]); e.target.value = ''; }} />
      <MenuItem dense disabled={busy} onClick={() => input.current?.click()}>
        <ListItemIcon>{busy ? <CircularProgress size={18} /> : <AddAPhotoIcon fontSize="small" />}</ListItemIcon>
        <ListItemText>{photoUrl ? t('profile.change_photo') : t('profile.add_photo')}</ListItemText>
      </MenuItem>
      {photoUrl && (
        <MenuItem dense disabled={busy} onClick={() => void run(async () => { await profilePhotoService.remove(); return null; }, 'profile.photo_removed')}>
          <ListItemIcon><DeleteIcon fontSize="small" /></ListItemIcon>
          <ListItemText>{t('profile.remove_photo')}</ListItemText>
        </MenuItem>
      )}
      <Divider />
    </>
  );
};
