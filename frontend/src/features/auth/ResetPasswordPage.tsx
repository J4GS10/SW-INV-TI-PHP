import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Box, Button, Card, CardContent, TextField, Typography, Alert, Container, CircularProgress, alpha, useTheme, } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { getApiErrorDetail, getApiErrorMessage } from '../../api/errors';
import { useConfig } from '../../context/config-context';
import { authService } from './authService';
export const ResetPasswordPage = () => {
    const { t } = useTranslation();
    const theme = useTheme();
    const { config } = useConfig();
    const navigate = useNavigate();
    const [params] = useSearchParams();
    // El enlace trae el token en el fragmento (#token=…): no viaja al servidor ni en el Referer
    // ni queda en los registros del proxy. Se acepta también ?token= (enlaces enviados antes del cambio).
    const [token] = useState(() => new URLSearchParams(window.location.hash.slice(1)).get('token') ?? params.get('token') ?? '');
    useEffect(() => {
        // Fuera de la barra de direcciones y del historial una vez leído.
        if (token)
            window.history.replaceState(null, '', window.location.pathname);
    }, [token]);
    const [pwd, setPwd] = useState('');
    const [confirm, setConfirm] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [done, setDone] = useState(false);
    const submit = async () => {
        setError('');
        if (pwd !== confirm) {
            setError(t('auth.reset_mismatch'));
            return;
        }
        if (!token) {
            setError(t('auth.reset_no_token'));
            return;
        }
        setLoading(true);
        try {
            await authService.confirmPasswordReset(token, pwd);
            setDone(true);
            setTimeout(() => navigate('/login'), 2500);
        }
        catch (err: unknown) {
            const code = getApiErrorDetail(err);
            setError(code === 'INVALID_OR_EXPIRED_RESET_TOKEN'
                ? t('auth.reset_invalid')
                : getApiErrorMessage(err, t('auth.reset_error')));
        }
        finally {
            setLoading(false);
        }
    };
    return (<Box sx={{
            minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center',
            background: `radial-gradient(circle at 50% 0%, ${alpha(theme.palette.primary.main, 0.2)} 0%, ${theme.palette.background.default} 100%)`,
        }}>
      <Container maxWidth="xs">
        <Card sx={{ border: `1px solid ${alpha(theme.palette.primary.main, 0.2)}` }}>
          <CardContent sx={{ p: 5 }}>
            <Typography variant="h5" align="center" sx={{ fontWeight: 'bold', color: theme.palette.primary.main, mb: 1 }}>
              {config.SYS_Nombre_Empresa}
            </Typography>
            <Typography variant="body2" color="text.secondary" align="center" sx={{ mb: 3 }}>
              {t('auth.reset_title')}
            </Typography>

            {done ? (<Alert severity="success">
                {t('auth.reset_done')}
              </Alert>) : (<>
                {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
                <TextField fullWidth type="password" margin="normal" label={t('auth.new_password')} helperText={t('users.dialog.password_help')} value={pwd} onChange={(e) => setPwd(e.target.value)}/>
                <TextField fullWidth type="password" margin="normal" label={t('auth.confirm_password')} value={confirm} onChange={(e) => setConfirm(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter')
            submit(); }}/>
                <Button fullWidth variant="contained" size="large" disabled={loading || !pwd || !confirm} onClick={submit} sx={{ mt: 3, py: 1.3 }}>
                  {loading ? <CircularProgress size={24}/> : t('auth.reset_submit')}
                </Button>
                <Button fullWidth color="inherit" sx={{ mt: 1 }} onClick={() => navigate('/login')}>
                  {t('auth.back_to_login')}
                </Button>
              </>)}
          </CardContent>
        </Card>
      </Container>
    </Box>);
};
