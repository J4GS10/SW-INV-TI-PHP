import { useEffect, useRef, useState, type SyntheticEvent } from 'react';
import {
  Button, DialogActions, DialogContent, DialogContentText, DialogTitle, Dialog as MuiDialog, useMediaQuery, useTheme,
  type DialogProps,
} from '@mui/material';
import { useTranslation } from 'react-i18next';
import { isChoiceTarget, shouldConfirmClose } from './dialogGuard';

/**
 * The application's dialog: same API as MUI's, plus two rules for every form.
 * Esc or a click outside ask before discarding what was typed, and on a phone it takes the whole screen.
 */
export const Dialog = ({ open, onClose, fullScreen, children, onInputCapture, onChangeCapture, onClickCapture, ...rest }: DialogProps) => {
  const { t } = useTranslation();
  const theme = useTheme();
  const isPhone = useMediaQuery(theme.breakpoints.down('sm'));
  const dirty = useRef(false);
  const [confirming, setConfirming] = useState(false);

  useEffect(() => {
    if (!open) {
      dirty.current = false;
    }
  }, [open]);

  const handleClose: DialogProps['onClose'] = (event, reason) => {
    if (shouldConfirmClose(reason, dirty.current)) {
      setConfirming(true);
      return;
    }
    onClose?.(event, reason);
  };

  const markDirty = () => { dirty.current = true; };

  return (
    <MuiDialog
      {...rest}
      open={open}
      fullScreen={fullScreen ?? isPhone}
      onClose={handleClose}
      onInputCapture={(e: SyntheticEvent<HTMLDivElement>) => { markDirty(); onInputCapture?.(e); }}
      onChangeCapture={(e: SyntheticEvent<HTMLDivElement>) => { markDirty(); onChangeCapture?.(e); }}
      onClickCapture={(e) => { if (isChoiceTarget(e.target)) markDirty(); onClickCapture?.(e); }}
    >
      {children}
      <MuiDialog open={confirming && !!open} onClose={() => setConfirming(false)} maxWidth="xs" fullWidth>
        <DialogTitle>{t('common.unsaved_title')}</DialogTitle>
        <DialogContent>
          <DialogContentText>{t('common.unsaved_message')}</DialogContentText>
        </DialogContent>
        <DialogActions>
          <Button autoFocus onClick={() => setConfirming(false)}>{t('common.unsaved_keep')}</Button>
          <Button color="error" onClick={() => { setConfirming(false); onClose?.({}, 'backdropClick'); }}>{t('common.unsaved_discard')}</Button>
        </DialogActions>
      </MuiDialog>
    </MuiDialog>
  );
};
