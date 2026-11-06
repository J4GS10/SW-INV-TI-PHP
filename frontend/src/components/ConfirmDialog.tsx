import React, { useCallback, useState } from 'react';
import { ConfirmContext, type ConfirmOptions } from './confirm-context';
import { Button, DialogActions, DialogContent, DialogContentText, DialogTitle } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { Dialog } from './ui/Dialog';
interface InternalState extends ConfirmOptions {
    open: boolean;
    resolver?: (v: boolean) => void;
}
export const ConfirmProvider = ({ children }: {
    children: React.ReactNode;
}) => {
    const { t } = useTranslation();
    const [state, setState] = useState<InternalState>({ open: false, message: '' });
    const confirm = useCallback((opts: ConfirmOptions) => new Promise<boolean>((resolve) => {
        setState({ ...opts, open: true, resolver: resolve });
    }), []);
    const handle = (result: boolean) => {
        state.resolver?.(result);
        setState((s) => ({ ...s, open: false }));
    };
    return (<ConfirmContext.Provider value={{ confirm }}>
      {children}
      <Dialog open={state.open} onClose={() => handle(false)} maxWidth="xs" fullWidth>
        <DialogTitle>{state.title ?? t('common.confirm_title')}</DialogTitle>
        <DialogContent>
          <DialogContentText>{state.message}</DialogContentText>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => handle(false)}>
            {state.cancelText ?? t('common.cancel')}
          </Button>
          <Button onClick={() => handle(true)} color={state.destructive ? 'error' : 'primary'} variant="contained" autoFocus>
            {state.confirmText ?? t('common.confirm')}
          </Button>
        </DialogActions>
      </Dialog>
    </ConfirmContext.Provider>);
};
