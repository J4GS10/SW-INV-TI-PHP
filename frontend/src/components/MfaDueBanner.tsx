import { useState } from 'react';
import { Alert, Button } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useAuth } from '../context/auth-context';
import { AccountSecurityDialog } from '../features/auth/AccountSecurityDialog';
import { ChangePasswordDialog } from '../features/auth/ChangePasswordDialog';

/**
 * While MFA was skipped in this first sign-in (or the account has a temporary
 * exemption), reminds the person to set it up and opens the security dialog in one click.
 */
export const MfaDueBanner = () => {
  const { t, i18n } = useTranslation();
  const { mfaDue, mfaSkipped, renewSession } = useAuth();
  const [securityOpen, setSecurityOpen] = useState(false);
  const [passwordOpen, setPasswordOpen] = useState(false);

  if (mfaDue === null && !mfaSkipped) return null;
  const message = mfaDue !== null
    ? t('security.mfa_due.message', { date: new Date(mfaDue * 1000).toLocaleDateString(i18n.language, { day: 'numeric', month: 'long', year: 'numeric' }) })
    : t('security.mfa_due.skipped');

  return (
    <>
      <Alert severity="warning" sx={{ mb: 2 }} action={(
        <Button color="inherit" size="small" onClick={() => setSecurityOpen(true)}>{t('security.mfa_due.setup')}</Button>
      )}>
        {message}
      </Alert>
      <AccountSecurityDialog
        open={securityOpen}
        // On close, a fresh session drops the reminder if MFA is now set up.
        onClose={() => { setSecurityOpen(false); void renewSession(); }}
        onChangePassword={() => setPasswordOpen(true)}
      />
      <ChangePasswordDialog open={passwordOpen} onClose={() => setPasswordOpen(false)} />
    </>
  );
};
