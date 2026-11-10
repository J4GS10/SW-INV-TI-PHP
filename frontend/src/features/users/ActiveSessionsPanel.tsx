import { useCallback, useEffect, useMemo, useState } from 'react';
import { Box, Chip, IconButton, Stack, TableCell, TableRow, Tooltip, Typography } from '@mui/material';
import ComputerIcon from '@mui/icons-material/Computer';
import LogoutIcon from '@mui/icons-material/Logout';
import PhoneIphoneIcon from '@mui/icons-material/PhoneIphone';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';

import { getApiErrorMessage } from '../../api/errors';
import { useConfirm } from '../../components/confirm-context';
import { SearchInput } from '../../components/SearchInput';
import { DataTableShell } from '../../components/ui';
import type { SesionActiva } from '../../types/users';
import { formatDateTime, formatRelativeDate, parseApiDate } from '../../utils/dates';
import { deviceLabel, isMobile } from '../auth/security/sessionDevice';
import { userService } from './userService';
import { useLiveRefresh } from '../../hooks/useLiveRefresh';

/** Sesiones activas de todas las cuentas, en una tabla paginada; cierre individual según la jerarquía de roles. */
export const ActiveSessionsPanel = ({ refreshKey }: { refreshKey?: number }) => {
  const { t, i18n } = useTranslation();
  const locale = i18n.resolvedLanguage ?? i18n.language;
  const { enqueueSnackbar } = useSnackbar();
  const confirm = useConfirm();
  const [sessions, setSessions] = useState<SesionActiva[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const [search, setSearch] = useState('');
  const [busyId, setBusyId] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setSessions(await userService.getActiveSessions());
      setError(false);
    } catch {
      setError(true);
    } finally {
      setLoading(false);
    }
  }, []);
  useLiveRefresh(load);

  useEffect(() => { void load(); }, [load, refreshKey]);

  const filtered = useMemo(() => {
    const term = search.trim().toLowerCase();
    if (!term) return sessions;
    return sessions.filter((s) => [s.nombre, s.username, deviceLabel(s.dispositivo, t), s.ip]
      .filter(Boolean).join(' ').toLowerCase().includes(term));
  }, [sessions, search, t]);

  const close = async (session: SesionActiva) => {
    if (!(await confirm({
      message: t('sessions.close_other_confirm', {
        device: deviceLabel(session.dispositivo, t),
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
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
    } finally {
      setBusyId(null);
    }
  };

  const now = new Date();
  const lastActivity = (s: SesionActiva) => parseApiDate(s.ultima_actividad)?.getTime() ?? 0;

  return (
    <DataTableShell
      toolbar={<SearchInput value={search} onChange={setSearch} placeholder={t('sessions.search_placeholder')} />}
      columns={[
        { key: 'user', label: t('users.table.user'), sortValue: (s) => s.nombre ?? s.username ?? '' },
        { key: 'device', label: t('sessions.table.device'), sortValue: (s) => deviceLabel(s.dispositivo, t) },
        { key: 'method', label: t('sessions.table.method') },
        { key: 'activity', label: t('sessions.table.last_activity'), sortValue: lastActivity },
        { key: 'ip', label: t('sessions.table.ip') },
        { key: 'actions', label: '', align: 'right' },
      ]}
      rows={filtered}
      loading={loading}
      error={error}
      onRetry={() => void load()}
      emptyTitle={sessions.length > 0 ? t('users.empty_filtered') : t('sessions.empty')}
      renderRow={(s) => {
        const Icon = isMobile(s.dispositivo) ? PhoneIphoneIcon : ComputerIcon;
        const date = parseApiDate(s.ultima_actividad);
        return (
          <TableRow key={s.id} hover selected={s.actual}>
            <TableCell>
              <Typography variant="body2" fontWeight={600}>{s.nombre ?? s.username}</Typography>
              {s.nombre && s.username && (
                <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{s.username}</Typography>
              )}
            </TableCell>
            <TableCell>
              <Stack direction="row" spacing={1} alignItems="center">
                <Box sx={{ display: 'flex', color: 'text.secondary' }}><Icon fontSize="small" /></Box>
                <Typography variant="body2">{deviceLabel(s.dispositivo, t)}</Typography>
                {s.actual && <Chip size="small" color="primary" label={t('sessions.current')} sx={{ height: 20 }} />}
              </Stack>
            </TableCell>
            <TableCell>
              <Chip size="small" variant="outlined" label={t(`sessions.method.${s.metodo}`)} sx={{ height: 22 }} />
            </TableCell>
            <TableCell>
              {date ? (
                <Tooltip title={formatDateTime(date, locale)}>
                  <Typography variant="body2" color="text.secondary" component="span">{formatRelativeDate(date, locale, now)}</Typography>
                </Tooltip>
              ) : '—'}
            </TableCell>
            <TableCell>
              <Typography variant="body2" color="text.secondary" sx={{ fontVariantNumeric: 'tabular-nums' }}>{s.ip ?? '—'}</Typography>
            </TableCell>
            <TableCell align="right">
              {!s.actual && s.gestionable !== false && (
                <Tooltip title={t('sessions.close')}>
                  <span>
                    <IconButton
                      size="small"
                      color="error"
                      onClick={() => void close(s)}
                      disabled={busyId === s.id}
                      aria-label={t('sessions.close_for', { user: s.nombre ?? s.username ?? '', device: deviceLabel(s.dispositivo, t) })}
                    >
                      <LogoutIcon fontSize="small" />
                    </IconButton>
                  </span>
                </Tooltip>
              )}
            </TableCell>
          </TableRow>
        );
      }}
    />
  );
};
