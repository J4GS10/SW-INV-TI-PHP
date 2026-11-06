import { useState } from 'react';
import { Box, CircularProgress } from '@mui/material';
import GppGoodIcon from '@mui/icons-material/GppGood';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { useAuth } from '../../../context/auth-context';
import { AuthGateLayout } from './AuthGateLayout';
import { MfaEnrollment } from './MfaEnrollment';

/**
 * Alta obligatoria de MFA (el rol la exige y la cuenta no la tiene). Al terminar
 * se renueva la sesión: el refresh devuelve un token sin restricciones.
 */
export const MfaEnrollmentPage = () => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const navigate = useNavigate();
  const { renewSession, logout } = useAuth();
  const [finishing, setFinishing] = useState(false);

  const finish = async () => {
    setFinishing(true);
    if (!(await renewSession())) {
      enqueueSnackbar(t('security.mfa_gate.session_failed'), { variant: 'warning' });
      logout();
      navigate('/login', { replace: true });
      return;
    }
    setFinishing(false);
  };

  return (
    <AuthGateLayout
      icon={<GppGoodIcon />}
      title={t('security.mfa_gate.title')}
      description={t('security.mfa_gate.description')}
      maxWidth="sm"
    >
      {finishing ? (
        <Box sx={{ display: 'flex', justifyContent: 'center', py: 4 }}><CircularProgress /></Box>
      ) : (
        <MfaEnrollment onComplete={finish} completeLabel={t('security.mfa_gate.enter')} />
      )}
    </AuthGateLayout>
  );
};
