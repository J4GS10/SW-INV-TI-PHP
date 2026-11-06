import { useCallback, useEffect, useState } from 'react';
import { Button, Stack } from '@mui/material';
import DevicesIcon from '@mui/icons-material/Devices';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';

import { getApiErrorMessage } from '../../api/errors';
import { useConfirm } from '../../components/confirm-context';
import { SectionCard } from '../../components/ui';
import type { SesionActiva } from '../../types/users';
import { SessionsList } from '../auth/security/SessionsList';
import { userService } from './userService';

const PREVIEW = 8;

/** Sesiones activas de todas las cuentas; cierre individual según la jerarquía de roles. */
export const ActiveSessionsPanel = ({ refreshKey }: { refreshKey?: number }) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const confirm = useConfirm();
  const [sessions, setSessions] = useState<SesionActiva[]>([]);
  const [showAll, setShowAll] = useState(false);
  const [busyId, setBusyId] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setSessions(await userService.getActiveSessions());
    } catch {
      setSessions([]);
    }
  }, []);

  useEffect(() => { void load(); }, [load, refreshKey]);

  const close = async (session: SesionActiva) => {
    if (!(await confirm({
      message: t('sessions.close_other_confirm', {
        device: session.dispositivo?.replace(/Otro|Desconocido/g, (w) => (w === 'Otro' ? t('sessions.other') : t('sessions.unknown'))) ?? t('sessions.unknown_device'),
        user: session.nombre ?? session.username ?? '',
      }),
      confirmText: t('sessions.close'),
      destructive: true,
    }))) return;
    setBusyId(session.id);
    try {
      await userService.closeSession(session.usuario_id, session.id);
      enqueueSnackbar(t('sessions.closed'), { variant: 'success' });
      await load();
    } catch (error) {
      enqueueSnackbar(getApiErrorMessage(error, t('common.error_generic')), { variant: 'error' });
    } finally {
      setBusyId(null);
    }
  };

  const visible = showAll ? sessions : sessions.slice(0, PREVIEW);
  return (
    <SectionCard
      title={t('sessions.panel_title')}
      subtitle={t('sessions.panel_subtitle', { count: sessions.length })}
      icon={<DevicesIcon fontSize="small" />}
    >
      <Stack spacing={1.5}>
        <SessionsList sessions={visible} onCloseSession={close} busyId={busyId} showOwner />
        {sessions.length > PREVIEW && (
          <Button size="small" onClick={() => setShowAll((v) => !v)} sx={{ alignSelf: 'flex-start' }}>
            {showAll ? t('sessions.show_less') : t('sessions.show_all', { count: sessions.length })}
          </Button>
        )}
      </Stack>
    </SectionCard>
  );
};
