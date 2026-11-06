import { useEffect, useState } from 'react';
import { Alert, Stack } from '@mui/material';
import { useTranslation } from 'react-i18next';
import client from '../api/axios';
import { useAuth } from '../context/auth-context';

interface SystemAlert {
  code: string;
  message: string;
}

const REFRESH_MS = 5 * 60 * 1000;
const WHO_KEEPS_THE_SYSTEM_RUNNING = ['SUPER_ADMIN', 'ADMIN_TI'];

/** Open system problems (backups, disk, mail), visible only to whoever can fix them. */
export const SystemAlertsBanner = () => {
  const { t } = useTranslation();
  const { user } = useAuth();
  const [alerts, setAlerts] = useState<SystemAlert[]>([]);
  const visible = !!user && WHO_KEEPS_THE_SYSTEM_RUNNING.includes(user.role);

  useEffect(() => {
    if (!visible) return undefined;
    const load = () => { client.get<SystemAlert[]>('/gov/alerts').then((r) => setAlerts(r.data)).catch(() => undefined); };
    load();
    const timer = window.setInterval(load, REFRESH_MS);
    return () => window.clearInterval(timer);
  }, [visible]);

  if (!visible || alerts.length === 0) return null;
  return (<Stack spacing={1} sx={{ mb: 2 }} role="status">
      {alerts.map((a) => <Alert key={a.code} severity="warning">{t(`system_alerts.${a.code}`, { defaultValue: a.message })}</Alert>)}
    </Stack>);
};
