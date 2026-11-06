import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Alert, Box, Button, CircularProgress, Stack } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useAuth } from '../../context/auth-context';
/** Reto 2FA local tras el proveedor SSO (`#sso=2fa&method=…&challenge=…`). */
const leerRetoSso = (): { token: string; method: 'TOTP' | 'EMAIL' } | null => {
    const params = new URLSearchParams(window.location.hash.replace(/^#/, ''));
    const token = params.get('challenge');
    if (params.get('sso') !== '2fa' || !token)
        return null;
    return { token, method: params.get('method') === 'EMAIL' ? 'EMAIL' : 'TOTP' };
};
export const SsoCallbackPage = () => {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const { isAuthenticated, isLoading } = useAuth();
    const [reto] = useState(leerRetoSso);
    const hasError = !reto && !isLoading && !isAuthenticated;
    useEffect(() => {
        if (reto) {
            // El challenge no se queda en la barra de direcciones ni en el historial.
            window.history.replaceState(null, document.title, '/sso/callback');
            navigate('/login', { replace: true, state: { ssoChallenge: reto } });
            return;
        }
        if (isLoading)
            return;
        if (isAuthenticated) {
            window.history.replaceState(null, document.title, '/');
            navigate('/', { replace: true });
            return;
        }
    }, [reto, isAuthenticated, isLoading, navigate]);
    if (hasError) {
        return (<Box sx={{ minHeight: '100vh', display: 'grid', placeItems: 'center', p: 3 }}>
        <Stack spacing={2} sx={{ width: '100%', maxWidth: 420 }}>
          <Alert severity="error">{t('auth.sso_error')}</Alert>
          <Button variant="contained" onClick={() => navigate('/login', { replace: true })}>
            {t('auth.back_to_login')}
          </Button>
        </Stack>
      </Box>);
    }
    return (<Box sx={{ minHeight: '100vh', display: 'grid', placeItems: 'center' }}>
      <CircularProgress />
    </Box>);
};
