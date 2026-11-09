import { useState } from 'react';
import { Box, Button, CircularProgress, Typography } from '@mui/material';
import GppGoodIcon from '@mui/icons-material/GppGood';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { useAuth } from '../../../context/auth-context';
import { getApiErrorMessage } from '../../../api/errors';
import { authService } from '../authService';
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
  const { renewSession, logout, login, mfaCanSkip } = useAuth();
  const [finishing, setFinishing] = useState(false);

  // Solo en el primer ingreso: el siguiente lo exige (salvo excepción del administrador del sistema).
  const skip = async () => {
    setFinishing(true);
    try {
      const r = await authService.skipMfa();
      login(r.access_token, r.restriction);
    } catch (error) {
      enqueueSnackbar(getApiErrorMessage(error, t('common.error_generic')), { variant: 'error' });
      setFinishing(false);
    }
  };

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
        <>
          <MfaEnrollment onComplete={finish} completeLabel={t('security.mfa_gate.enter')} />
          {mfaCanSkip ? (
            <Box sx={{ mt: 2, textAlign: 'center' }}>
              <Button color="inherit" onClick={() => void skip()}>{t('security.mfa_gate.skip')}</Button>
              <Typography variant="caption" color="text.secondary" component="p">{t('security.mfa_gate.skip_hint')}</Typography>
            </Box>
          ) : null}
        </>
      )}
    </AuthGateLayout>
  );
};
