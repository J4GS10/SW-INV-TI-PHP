import { useState } from 'react';
import { Box } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { PageHeader, PageTabs } from '../../components/ui';
import { usePageTab } from '../../hooks/usePageTab';
import { AdStatusSection } from './AdStatusSection';
import { IntegrationSection } from './IntegrationSection';
import { NotificationRulesSection } from './NotificationRulesSection';

const VIEWS = ['integracion', 'directorio', 'notificaciones'] as const;

/**
 * Directorio y notificaciones: conexión con AD y correo, estado y sincronización con Active
 * Directory, y reglas de destinatarios por evento, cada uno en su pestaña. Solo para el administrador del sistema.
 */
export const DirectoryPage = () => {
  const { t } = useTranslation();
  const [view, setView] = usePageTab(VIEWS);
  // Se incrementa al guardar la integración para que el estado de AD se recargue.
  const [adRefreshKey, setAdRefreshKey] = useState(0);

  return (
    <Box>
      <PageHeader title={t('directory.title')} subtitle={t('directory.subtitle')} />
      <PageTabs
        value={view}
        onChange={setView}
        tabs={[
          { value: 'integracion', label: t('directory.integration.title') },
          { value: 'directorio', label: t('directory.ad.title') },
          { value: 'notificaciones', label: t('directory.rules.title') },
        ]}
      />
      {view === 'integracion' && <IntegrationSection onSaved={() => setAdRefreshKey((k) => k + 1)} />}
      {view === 'directorio' && <AdStatusSection canTestConnection refreshKey={adRefreshKey} />}
      {view === 'notificaciones' && <NotificationRulesSection />}
    </Box>
  );
};
