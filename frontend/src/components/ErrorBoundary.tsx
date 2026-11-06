import React from 'react';
import { Alert, Box, Button, Typography } from '@mui/material';
import i18n from '../i18n';

interface State {
  hasError: boolean;
  error: Error | null;
}

interface Props {
  children: React.ReactNode;
}

export class ErrorBoundary extends React.Component<Props, State> {
  state: State = { hasError: false, error: null };

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error };
  }

  componentDidCatch(error: Error, info: React.ErrorInfo) {
    // En producción este reporte iría a Sentry/Datadog.
     
    console.error('UI crash:', error, info);
  }

  handleReload = () => window.location.reload();

  render() {
    if (this.state.hasError) {
      return (
        <Box sx={{
          minHeight: '100vh', display: 'flex', alignItems: 'center',
          justifyContent: 'center', p: 4, bgcolor: 'secondary.contrastText',
        }}>
          <Box sx={{ maxWidth: 600, width: '100%' }}>
            <Alert severity="error" sx={{ mb: 2 }}>
              <Typography variant="h6">{i18n.t('common.error_boundary_title')}</Typography>
              <Typography variant="body2" sx={{ mt: 1, opacity: 0.85 }}>
                {i18n.t('common.error_boundary_message')}
              </Typography>
            </Alert>
            <Box sx={{ display: 'flex', gap: 1 }}>
              <Button variant="contained" onClick={this.handleReload}>
                {i18n.t('common.reload')}
              </Button>
              <Button onClick={() => (window.location.href = '/')}>{i18n.t('common.go_home')}</Button>
            </Box>
            {import.meta.env.DEV && this.state.error && (
              <Box component="pre" sx={{
                mt: 3, p: 2, bgcolor: 'action.hover', color: 'error.main',
                fontSize: '0.75rem', overflow: 'auto', borderRadius: 1,
                maxHeight: 240,
              }}>
                {this.state.error.stack || this.state.error.message}
              </Box>
            )}
          </Box>
        </Box>
      );
    }
    return this.props.children;
  }
}
