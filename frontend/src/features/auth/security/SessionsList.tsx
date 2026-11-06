import { Box, Button, Chip, Stack, Typography } from '@mui/material';
import ComputerIcon from '@mui/icons-material/Computer';
import PhoneIphoneIcon from '@mui/icons-material/PhoneIphone';
import { useTranslation } from 'react-i18next';

import type { SesionActiva } from '../../../types/users';

interface Props {
  sessions: SesionActiva[];
  /** Cierra una sesión; si falta, la lista es de solo lectura. */
  onCloseSession?: (session: SesionActiva) => void;
  busyId?: string | null;
  /** Muestra el titular de cada sesión (panel de administración). */
  showOwner?: boolean;
}

const isMobile = (device: string | null) => !!device && /Android|iOS/.test(device);

/** El backend describe el dispositivo como "Navegador · Sistema"; se traducen las partes genéricas. */
const deviceLabel = (device: string | null, t: (key: string) => string) => {
  if (!device) return t('sessions.unknown_device');
  return device.split(' · ')
    .map((part) => (part === 'Otro' ? t('sessions.other') : part === 'Desconocido' ? t('sessions.unknown') : part))
    .join(' · ');
};

const formatDateTime = (value: string | null, locale: string) => {
  if (!value) return '—';
  // Las fechas del backend son UTC sin zona.
  const date = new Date(value.endsWith('Z') ? value : `${value}Z`);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString(locale);
};

/** Sesiones activas (dispositivo, IP, método y última actividad), con cierre individual. */
export const SessionsList = ({ sessions, onCloseSession, busyId, showOwner }: Props) => {
  const { t, i18n } = useTranslation();
  if (!sessions.length) {
    return <Typography variant="body2" color="text.secondary">{t('sessions.empty')}</Typography>;
  }
  return (
    <Stack spacing={1}>
      {sessions.map((s) => {
        const Icon = isMobile(s.dispositivo) ? PhoneIphoneIcon : ComputerIcon;
        return (
          <Box
            key={s.id}
            sx={{
              display: 'flex', alignItems: 'center', gap: 1.5, p: 1.25, borderRadius: 1.5,
              border: 1, borderColor: s.actual ? 'primary.main' : 'divider', flexWrap: 'wrap',
            }}
          >
            <Icon color={s.actual ? 'primary' : 'action'} />
            <Box sx={{ flex: 1, minWidth: 180 }}>
              <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
                <Typography variant="body2" sx={{ fontWeight: 700 }}>
                  {showOwner && s.nombre ? `${s.nombre} · ` : ''}{deviceLabel(s.dispositivo, t)}
                </Typography>
                {s.actual && <Chip size="small" color="primary" label={t('sessions.current')} sx={{ height: 20 }} />}
                <Chip size="small" variant="outlined" label={t(`sessions.method.${s.metodo}`)} sx={{ height: 20 }} />
              </Stack>
              <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
                {showOwner && s.username ? `${s.username} · ` : ''}
                {t('sessions.last_activity', { date: formatDateTime(s.ultima_actividad, i18n.language) })}
                {s.ip ? ` · ${t('sessions.ip', { ip: s.ip })}` : ''}
              </Typography>
            </Box>
            {onCloseSession && !s.actual && s.gestionable !== false && (
              <Button
                size="small"
                color="error"
                variant="outlined"
                onClick={() => onCloseSession(s)}
                disabled={busyId === s.id}
              >
                {t('sessions.close')}
              </Button>
            )}
          </Box>
        );
      })}
    </Stack>
  );
};
