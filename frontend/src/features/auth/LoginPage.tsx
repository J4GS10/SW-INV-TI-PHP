import { useEffect, useState } from 'react';
import { SubmitHandler, useForm } from 'react-hook-form';
import { useLocation, useNavigate } from 'react-router-dom';
import { Box, Button, Card, CardContent, Checkbox, FormControlLabel, TextField, Typography, Alert, Container, Divider, InputAdornment, IconButton, alpha, useTheme } from '@mui/material';
import { Login as LoginIcon, Visibility, VisibilityOff } from '@mui/icons-material';
import { useTranslation } from 'react-i18next';
import { getApiErrorCode, getApiErrorMessage } from '../../api/errors';
import { Link as MuiLink } from '@mui/material';
import { LanguageSwitcher } from '../../components/LanguageSwitcher';
import { tokenStorage } from '../../api/tokenStorage';
import { SESSION_ENDED_FLAG } from '../../api/axios';
import { useAuth } from '../../context/auth-context';
import { useConfig } from '../../context/config-context';
import { LOGO_BASE_HEIGHT, logoScale } from '../config/logoSource';
import { authService, SsoProvider } from './authService';
import { ForgotPasswordDialog } from './ForgotPasswordDialog';
interface LoginFormValues {
    username: string;
    password: string;
}
export const LoginPage = () => {
    const { t } = useTranslation();
    const theme = useTheme();
    const { config } = useConfig();
    const { register, handleSubmit } = useForm<LoginFormValues>();
    const { login } = useAuth();
    const navigate = useNavigate();
    const location = useLocation();
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    const [showPassword, setShowPassword] = useState(false);
    const [forgotOpen, setForgotOpen] = useState(false);
    const [ssoProviders, setSsoProviders] = useState<SsoProvider[]>([]);
    const [ssoLoading, setSsoLoading] = useState<string | null>(null);
    // Reto 2FA: si el backend lo exige tras la contraseña o tras el SSO
    // (SsoCallbackPage lo pasa en el estado de la navegación).
    const [challenge, setChallenge] = useState<{
        token: string;
        method: 'TOTP' | 'EMAIL';
        /** Días que se puede recordar este equipo (0 = no se ofrece). */
        rememberDays?: number;
    } | null>(() => (location.state as { ssoChallenge?: { token: string; method: 'TOTP' | 'EMAIL' } } | null)?.ssoChallenge ?? null);
    const [otp, setOtp] = useState('');
    const [rememberDevice, setRememberDevice] = useState(false);
    // Llegó aquí porque su sesión terminó (cerrada por un administrador o vencida).
    const [sessionEnded] = useState(() => {
        try {
            const ended = window.sessionStorage.getItem(SESSION_ENDED_FLAG) === '1';
            window.sessionStorage.removeItem(SESSION_ENDED_FLAG);
            return ended;
        }
        catch {
            return false;
        }
    });
    const handleClickShowPassword = () => setShowPassword((show) => !show);
    useEffect(() => {
        authService.getSsoProviders()
            .then(setSsoProviders)
            .catch(() => setSsoProviders([]));
    }, []);
    const handleSso = async (provider: string) => {
        setError('');
        setSsoLoading(provider);
        try {
            const returnTo = `${window.location.origin}/sso/callback`;
            const url = await authService.getSsoAuthorizationUrl(provider, returnTo);
            tokenStorage.startSession();
            window.location.assign(url);
        }
        catch {
            tokenStorage.clear();
            setError(t('auth.sso_error'));
            setSsoLoading(null);
        }
    };
    const onSubmit: SubmitHandler<LoginFormValues> = async (data) => {
        setLoading(true);
        setError('');
        try {
            const response = await authService.login(data.username.trim(), data.password);
            if ('requires_2fa' in response) {
                setChallenge({ token: response.challenge_token, method: response.method, rememberDays: response.remember_days ?? 0 });
            }
            else {
                login(response.access_token, response.restriction);
                navigate('/');
            }
        }
        catch (err) {
            // No logueamos detalles del error: evita filtrar info en la consola del navegador.
            const code = getApiErrorCode(err);
            setError(code === 'ACCOUNT_LOCKED' || code === 'INACTIVE_USER' || code === 'RATE_LIMIT_EXCEEDED'
                || code === 'NETWORK_ERROR' || code === 'REQUEST_TIMEOUT'
                ? getApiErrorMessage(err, t('auth.error_credentials'))
                : t('auth.error_credentials'));
        }
        finally {
            setLoading(false);
        }
    };
    const onVerify2fa = async () => {
        if (!challenge || !otp.trim())
            return;
        setLoading(true);
        setError('');
        try {
            const r = await authService.verify2fa(challenge.token, otp.trim(), rememberDevice);
            login(r.access_token, r.restriction);
            navigate('/');
        }
        catch (err) {
            const code = getApiErrorCode(err);
            if (code === 'INVALID_OR_EXPIRED_2FA_CHALLENGE' || code === 'ACCOUNT_LOCKED') {
                // El desafío ya no sirve: se vuelve a pedir usuario y contraseña.
                setChallenge(null);
                setOtp('');
            }
            setError(code && code !== 'INVALID_2FA_CODE'
                ? getApiErrorMessage(err, t('auth.error_2fa'))
                : t('auth.error_2fa'));
        }
        finally {
            setLoading(false);
        }
    };
    return (<Box sx={{
            minHeight: '100vh',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            position: 'relative',
            // Fondo dinámico basado en el color primario del cliente
            background: `radial-gradient(circle at 50% 0%, ${alpha(theme.palette.primary.main, 0.2)} 0%, ${theme.palette.background.default} 100%)`
        }}>
      <Box sx={{ position: 'absolute', top: 20, right: 20 }}>
        <LanguageSwitcher />
      </Box>

      <Container maxWidth="xs">
        <Card sx={{
            // El borde brilla sutilmente con el color del cliente
            border: `1px solid ${alpha(theme.palette.primary.main, 0.2)}`,
            boxShadow: `0 20px 40px ${alpha(theme.palette.primary.main, 0.1)}`
        }}>
          <CardContent sx={{ p: 5 }}>
            {config.SYS_Logo_URL ? (<Box sx={{ display: 'flex', justifyContent: 'center', mb: 3 }}>
                <img src={config.SYS_Logo_URL} alt={config.SYS_Nombre_Empresa} style={{ maxHeight: LOGO_BASE_HEIGHT.login * logoScale(config.SYS_Logo_Tamano), maxWidth: '100%', objectFit: 'contain' }} onError={(e) => { (e.currentTarget as HTMLImageElement).style.display = 'none'; }}/>
              </Box>) : null}
            <Typography variant="h4" component="h1" gutterBottom align="center" sx={{
            fontWeight: 'bold',
            color: theme.palette.primary.main,
        }}>
              {config.SYS_Nombre_Empresa}
            </Typography>
            <Typography variant="body2" color="text.secondary" align="center" sx={{ mb: 4 }}>
              {t('app.title')}
            </Typography>

            {sessionEnded && !error && <Alert severity="info" sx={{ mb: 3 }}>{t('auth.session_ended')}</Alert>}
            {error && <Alert severity="error" variant="filled" sx={{ mb: 3 }}>{error}</Alert>}

            {challenge ? (<Box>
                <Alert severity="info" sx={{ mb: 2 }}>
                  {challenge.method === 'EMAIL'
                ? t('auth.twofa_email_hint')
                : t('auth.twofa_totp_hint')}
                </Alert>
                <TextField fullWidth autoFocus label={t('auth.twofa_code')} margin="normal" variant="outlined" value={otp} autoComplete="one-time-code" inputMode="numeric" onChange={(e) => setOtp(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter')
            onVerify2fa(); }} helperText={t('auth.twofa_recovery_hint')}/>
                {(challenge.rememberDays ?? 0) > 0 ? (<FormControlLabel sx={{ mt: 1 }} control={<Checkbox checked={rememberDevice} onChange={(e) => setRememberDevice(e.target.checked)}/>}
                    label={<Typography variant="body2">{t('auth.remember_device', { count: challenge.rememberDays })}</Typography>}/>) : null}
                <Button fullWidth variant="contained" size="large" disabled={loading || !otp.trim()} onClick={onVerify2fa} sx={{ mt: 2, mb: 1, py: 1.5 }}>
                  {loading ? t('auth.loading_button') : t('auth.twofa_verify')}
                </Button>
                <Button fullWidth color="inherit" onClick={() => { setChallenge(null); setOtp(''); setError(''); }}>
                  {t('common.cancel')}
                </Button>
              </Box>) : (<>
                <form onSubmit={handleSubmit(onSubmit)}>
                  <TextField fullWidth label={t('auth.username')} margin="normal" variant="outlined" autoComplete="username" {...register('username', { required: true })}/>

                  <TextField fullWidth label={t('auth.password')} type={showPassword ? 'text' : 'password'} margin="normal" variant="outlined" autoComplete="current-password" {...register('password', { required: true })} InputProps={{
                endAdornment: (<InputAdornment position="end">
                          <IconButton aria-label={t('auth.toggle_password_visibility')} onClick={handleClickShowPassword} edge="end">
                            {showPassword ? <VisibilityOff /> : <Visibility />}
                          </IconButton>
                        </InputAdornment>),
            }}/>

                  <Button fullWidth type="submit" variant="contained" size="large" disabled={loading} sx={{ mt: 3, mb: 2, py: 1.5 }}>
                    {loading ? t('auth.loading_button') : t('auth.login_button')}
                  </Button>
                </form>

                {ssoProviders.length > 0 && (<>
                    <Divider sx={{ my: 2 }}>{t('auth.or')}</Divider>
                    {ssoProviders.map((provider) => (<Button key={provider.provider} fullWidth variant="outlined" startIcon={<LoginIcon />} disabled={Boolean(ssoLoading)} onClick={() => handleSso(provider.provider)} sx={{ mb: 1 }}>
                        {ssoLoading === provider.provider
                        ? t('auth.loading_button')
                        : provider.label}
                      </Button>))}
                  </>)}

                <Box sx={{ textAlign: 'center', mt: 1 }}>
                  <MuiLink component="button" type="button" variant="body2" underline="hover" onClick={() => setForgotOpen(true)} sx={{ color: 'text.secondary', minHeight: 24, px: 0.5 }}>
                    {t('auth.forgot_link')}
                  </MuiLink>
                </Box>
              </>)}
          </CardContent>
        </Card>
      </Container>

      <ForgotPasswordDialog open={forgotOpen} onClose={() => setForgotOpen(false)}/>
    </Box>);
};
