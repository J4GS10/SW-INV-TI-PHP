import { useState } from 'react';
import { Box, Stack } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { PageHeader } from '../../components/ui';
import { AdStatusSection } from './AdStatusSection';
import { IntegrationSection } from './IntegrationSection';
import { NotificationRulesSection } from './NotificationRulesSection';

/**
 * Directorio y notificaciones: estado/sincronización con Active Directory y
 * reglas de destinatarios de correo por evento. Solo para el administrador del sistema.
 */
export const DirectoryPage = () => {
  const { t } = useTranslation();
  // Se incrementa al guardar la integración para que el estado de AD se recargue.
  const [adRefreshKey, setAdRefreshKey] = useState(0);

  return (
    <Box>
      <PageHeader title={t('directory.title')} subtitle={t('directory.subtitle')} />
      <Stack spacing={3}>
        <IntegrationSection onSaved={() => setAdRefreshKey((k) => k + 1)} />
        <AdStatusSection canTestConnection refreshKey={adRefreshKey} />
        <NotificationRulesSection />
      </Stack>
    </Box>
  );
};
