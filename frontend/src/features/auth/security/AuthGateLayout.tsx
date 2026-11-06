import type { ReactNode } from 'react';
import { Box, Button, Card, CardContent, Container, Divider, Stack, Typography, alpha, useTheme } from '@mui/material';
import type { Breakpoint } from '@mui/material';
import LogoutIcon from '@mui/icons-material/Logout';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { LanguageSwitcher } from '../../../components/LanguageSwitcher';
import { useAuth } from '../../../context/auth-context';
import { useConfig } from '../../../context/config-context';

interface Props {
  icon: ReactNode;
  title: string;
  description: string;
  children: ReactNode;
  maxWidth?: Breakpoint;
}

/**
 * Pantalla completa de seguridad (fuera del layout del sistema), con el mismo
 * lenguaje visual que el inicio de sesión. Siempre ofrece cerrar sesión.
 */
export const AuthGateLayout = ({ icon, title, description, children, maxWidth = 'xs' }: Props) => {
  const { t } = useTranslation();
  const theme = useTheme();
  const { config } = useConfig();
  const { user, logout } = useAuth();
  const navigate = useNavigate();

  return (
    <Box sx={{
      minHeight: '100vh',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      position: 'relative',
      py: { xs: 9, sm: 6 },
      background: `radial-gradient(circle at 50% 0%, ${alpha(theme.palette.primary.main, 0.2)} 0%, ${theme.palette.background.default} 100%)`,
    }}>
      <Box sx={{ position: 'absolute', top: 20, right: 20 }}>
        <LanguageSwitcher />
      </Box>

      <Container maxWidth={maxWidth}>
        <Card sx={{
          border: `1px solid ${alpha(theme.palette.primary.main, 0.2)}`,
          boxShadow: `0 20px 40px ${alpha(theme.palette.primary.main, 0.1)}`,
        }}>
          <CardContent sx={{ p: { xs: 3, sm: 4 } }}>
            <Typography variant="subtitle2" color="primary" align="center" sx={{ fontWeight: 800, mb: 3 }}>
              {config.SYS_Nombre_Empresa}
            </Typography>

            <Stack spacing={1.5} alignItems="center" sx={{ textAlign: 'center', mb: 3 }}>
              <Box sx={{
                width: 48, height: 48, borderRadius: '50%', display: 'grid', placeItems: 'center',
                bgcolor: alpha(theme.palette.primary.main, 0.12), color: 'primary.main',
              }}>
                {icon}
              </Box>
              <Typography variant="h6" component="h1" fontWeight={700}>{title}</Typography>
              <Typography variant="body2" color="text.secondary">{description}</Typography>
            </Stack>

            {children}

            <Divider sx={{ mt: 3, mb: 1.5 }} />
            <Stack direction="row" alignItems="center" justifyContent="space-between" spacing={1}>
              <Typography variant="caption" color="text.secondary" noWrap sx={{ minWidth: 0 }}>
                {t('security.gate.signed_in_as', { user: user?.sub ?? '' })}
              </Typography>
              <Button
                size="small"
                color="inherit"
                startIcon={<LogoutIcon fontSize="small" />}
                onClick={() => { logout(); navigate('/login', { replace: true }); }}
                sx={{ flexShrink: 0 }}
              >
                {t('auth.logout')}
              </Button>
            </Stack>
          </CardContent>
        </Card>
      </Container>
    </Box>
  );
};
