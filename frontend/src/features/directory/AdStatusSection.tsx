import { useCallback, useEffect, useState } from 'react';
import { formatDateTimeValue } from '../../utils/dates';
import { Link as RouterLink } from 'react-router-dom';
import { Alert, AlertTitle, Box, Button, CircularProgress, DialogActions, DialogContent, DialogContentText, DialogTitle, List, ListItem, ListItemText, Skeleton, Stack, TableCell, TableRow, Typography } from '@mui/material';
import { alpha } from '@mui/material/styles';
import DnsIcon from '@mui/icons-material/Dns';
import NetworkCheckIcon from '@mui/icons-material/NetworkCheck';
import PreviewIcon from '@mui/icons-material/Preview';
import SyncIcon from '@mui/icons-material/Sync';
import WarningAmberIcon from '@mui/icons-material/WarningAmber';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../api/errors';
import { DataTableShell, SectionCard, StatusChip } from '../../components/ui';
import { directoryService } from './directoryService';
import { isForbidden } from './directoryUtils';
import type { DirectoryStatus, SyncResult } from './types';
import { Dialog } from '../../components/ui/Dialog';
import { useLiveRefresh } from '../../hooks/useLiveRefresh';


const COUNT_KEYS = [
  'total_ad',
  'creados',
  'actualizados',
  'vinculados',
  'desactivados',
  'reactivados',
  'jefes_asignados',
] as const;

type RunningAction = 'test' | 'dry' | 'sync' | null;

const StatTile = ({ label, value, warning = false }: { label: string; value: number; warning?: boolean }) => (
  <Box
    sx={(theme) => ({
      px: 1.5,
      py: 1,
      minWidth: 110,
      flex: '1 1 110px',
      borderRadius: 2,
      border: '1px solid',
      borderColor: warning ? alpha(theme.palette.warning.main, 0.5) : 'divider',
      bgcolor: warning ? alpha(theme.palette.warning.main, 0.08) : 'action.hover',
    })}
  >
    <Typography variant="h6" fontWeight={700} lineHeight={1.2}>
      {value}
    </Typography>
    <Typography variant="caption" color="text.secondary">
      {label}
    </Typography>
  </Box>
);

const InfoRow = ({ label, value }: { label: string; value: string | null | undefined }) => (
  <Box sx={{ minWidth: 0 }}>
    <Typography variant="caption" color="text.secondary" display="block">
      {label}
    </Typography>
    <Typography variant="body2" fontWeight={500} sx={{ wordBreak: 'break-all' }}>
      {value || '—'}
    </Typography>
  </Box>
);

const SyncCounts = ({ result }: { result: SyncResult }) => {
  const { t } = useTranslation();
  return (
    <Stack direction="row" flexWrap="wrap" gap={1}>
      {COUNT_KEYS.map((k) => (
        <StatTile key={k} label={t(`directory.sync.counts.${k}`)} value={result[k]} />
      ))}
      <StatTile
        label={t('directory.sync.counts.pendientes')}
        value={result.pendientes_con_activos.length}
        warning={result.pendientes_con_activos.length > 0}
      />
      <StatTile label={t('directory.sync.counts.errores')} value={result.errores.length} warning={result.errores.length > 0} />
    </Stack>
  );
};

const SyncResultView = ({ result, onForce }: { result: SyncResult; onForce?: () => void }) => {
  const { t, i18n } = useTranslation();
  const protegidos = result.protegidos ?? [];
  const retenidas = result.advertencia === 'AD_SYNC_MASS_DEACTIVATION_BLOCKED' ? result.bajas_retenidas ?? 0 : 0;
  return (
    <Stack spacing={2}>
      <Stack direction="row" alignItems="center" spacing={1} flexWrap="wrap">
        <Typography variant="subtitle2" fontWeight={700}>
          {result.dry_run ? t('directory.sync.result_dry_title') : t('directory.sync.result_title')}
        </Typography>
        <Typography variant="caption" color="text.secondary">
          {formatDateTimeValue(result.fecha, i18n.language)}
        </Typography>
      </Stack>
      {result.dry_run && <Typography variant="body2" color="text.secondary">{t('directory.sync.dry_run_note')}</Typography>}

      <SyncCounts result={result} />

      {retenidas > 0 && (
        <Alert
          severity="warning"
          icon={<WarningAmberIcon />}
          action={
            onForce && (
              <Button color="inherit" size="small" onClick={onForce}>
                {t('directory.sync.force_action')}
              </Button>
            )
          }
        >
          <AlertTitle>{t('directory.sync.mass_blocked_title', { count: retenidas })}</AlertTitle>
          {t('directory.sync.mass_blocked_description')}
        </Alert>
      )}

      {protegidos.length > 0 && (
        <Alert severity="info">
          <AlertTitle>{t('directory.sync.protected_title')}</AlertTitle>
          {t('directory.sync.protected_description')}
          <List dense disablePadding>
            {protegidos.map((p) => (
              <ListItem key={p.persona_id} disableGutters sx={{ py: 0 }}>
                <ListItemText primary={p.nombre} secondary={p.email} slotProps={{ primary: { variant: 'body2' } }} />
              </ListItem>
            ))}
          </List>
        </Alert>
      )}

      {result.pendientes_con_activos.length > 0 && (
        <Stack spacing={1}>
          <Alert
            severity="warning"
            icon={<WarningAmberIcon />}
            action={
              <Button component={RouterLink} to="/empleados" color="inherit" size="small">
                {t('directory.sync.go_to_persons')}
              </Button>
            }
          >
            <AlertTitle>{t('directory.sync.pending_title')}</AlertTitle>
            {t('directory.sync.pending_description')}
          </Alert>
          <DataTableShell
            columns={[
              { key: 'nombre', label: t('directory.sync.col_name') },
              { key: 'email', label: t('directory.sync.col_email') },
              { key: 'activos', label: t('directory.sync.col_assets'), align: 'right' },
            ]}
            rows={result.pendientes_con_activos}
            renderRow={(p) => (
              <TableRow key={p.persona_id} hover sx={(theme) => ({ bgcolor: alpha(theme.palette.warning.main, 0.06) })}>
                <TableCell>
                  <Typography variant="body2" fontWeight={500}>
                    {p.nombre}
                  </Typography>
                </TableCell>
                <TableCell>{p.email}</TableCell>
                <TableCell align="right">
                  <StatusChip label={String(p.activos)} variant="por_vencer" />
                </TableCell>
              </TableRow>
            )}
          />
        </Stack>
      )}

      {result.errores.length > 0 && (
        <Alert severity="error">
          <AlertTitle>{t('directory.sync.errors_title', { count: result.errores.length })}</AlertTitle>
          <List dense disablePadding>
            {result.errores.map((e, i) => (
              <ListItem key={`${e.email ?? 'none'}-${i}`} disableGutters sx={{ py: 0 }}>
                <ListItemText
                  primary={e.detalle}
                  secondary={e.email ?? t('directory.sync.error_no_email')}
                  slotProps={{ primary: { variant: 'body2' } }}
                />
              </ListItem>
            ))}
          </List>
        </Alert>
      )}
    </Stack>
  );
};

interface Props {
  /** Solo SUPER_ADMIN puede probar la conexión. */
  canTestConnection: boolean;
  /** Cambia cuando otra sección modifica la configuración (fuerza recarga del estado). */
  refreshKey?: number;
}

export const AdStatusSection = ({ canTestConnection, refreshKey = 0 }: Props) => {
  const { t, i18n } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const [status, setStatus] = useState<DirectoryStatus | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<'forbidden' | 'generic' | null>(null);
  const [running, setRunning] = useState<RunningAction>(null);
  const [result, setResult] = useState<SyncResult | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [forceOpen, setForceOpen] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      setStatus(await directoryService.getStatus());
      setLoadError(null);
    } catch (err) {
      setLoadError(isForbidden(err) ? 'forbidden' : 'generic');
    } finally {
      setLoading(false);
    }
  }, []);
  useLiveRefresh(load);

  useEffect(() => {
    load();
  }, [load, refreshKey]);

  const handleTest = async () => {
    setRunning('test');
    try {
      const r = await directoryService.testConnection();
      enqueueSnackbar(
        r.ok
          ? t('directory.ad.test_ok', { count: r.usuarios_encontrados, message: r.message })
          : t('directory.ad.test_failed', { message: r.message }),
        { variant: r.ok ? 'success' : 'error' },
      );
    } catch (err) {
      enqueueSnackbar(
        isForbidden(err) ? t('directory.forbidden_action') : getApiErrorMessage(err, t('common.error_generic')),
        { variant: 'error' },
      );
    } finally {
      setRunning(null);
    }
  };

  const handleSync = async (dryRun: boolean, forzarBajas = false) => {
    setConfirmOpen(false);
    setForceOpen(false);
    setRunning(dryRun ? 'dry' : 'sync');
    try {
      const r = await directoryService.sync(dryRun, forzarBajas);
      setResult(r);
      enqueueSnackbar(dryRun ? t('directory.sync.dry_done') : t('directory.sync.done'), { variant: 'success' });
      if (!dryRun) load();
    } catch (err) {
      enqueueSnackbar(
        isForbidden(err) ? t('directory.forbidden_action') : getApiErrorMessage(err, t('common.error_generic')),
        { variant: 'error' },
      );
    } finally {
      setRunning(null);
    }
  };

  const adReady = !!status?.enabled && !!status?.configured;
  const busy = running !== null;

  const actions = (
    <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
      {canTestConnection && (
        <Button
          size="small"
          variant="outlined"
          startIcon={running === 'test' ? <CircularProgress size={16} /> : <NetworkCheckIcon />}
          disabled={!adReady || busy}
          onClick={handleTest}
        >
          {t('directory.ad.test_connection')}
        </Button>
      )}
      <Button
        size="small"
        variant="outlined"
        startIcon={running === 'dry' ? <CircularProgress size={16} /> : <PreviewIcon />}
        disabled={!adReady || busy}
        onClick={() => handleSync(true)}
      >
        {t('directory.ad.simulate_sync')}
      </Button>
      <Button
        size="small"
        variant="contained"
        startIcon={running === 'sync' ? <CircularProgress size={16} color="inherit" /> : <SyncIcon />}
        disabled={!adReady || busy}
        onClick={() => setConfirmOpen(true)}
      >
        {t('directory.ad.sync_now')}
      </Button>
    </Stack>
  );

  return (
    <SectionCard
      title={t('directory.ad.title')}
      subtitle={t('directory.ad.subtitle')}
      icon={<DnsIcon fontSize="small" />}
    >
      {loading ? (
        <Stack spacing={1}>
          <Skeleton variant="rounded" height={32} />
          <Skeleton variant="rounded" height={64} />
        </Stack>
      ) : loadError ? (
        <Alert severity={loadError === 'forbidden' ? 'warning' : 'error'}>
          {loadError === 'forbidden' ? t('directory.forbidden_section') : t('common.error_loading')}
        </Alert>
      ) : status ? (
        <Stack spacing={2.5}>
          <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap alignItems="center">
            <StatusChip
              label={status.enabled ? t('directory.ad.enabled') : t('directory.ad.disabled')}
              variant={status.enabled ? 'activo' : 'inactivo'}
            />
            <StatusChip
              label={status.configured ? t('directory.ad.configured') : t('directory.ad.not_configured')}
              variant={status.configured ? 'activo' : 'por_vencer'}
            />
          </Stack>

          {!adReady && (
            <Alert severity="info">
              <AlertTitle>{t('directory.ad.not_enabled_title')}</AlertTitle>
              {t('directory.ad.not_enabled_description')}
            </Alert>
          )}

          <Box
            sx={{
              display: 'grid',
              gap: 2,
              gridTemplateColumns: { xs: '1fr', sm: 'repeat(2, minmax(0, 1fr))', md: 'repeat(4, minmax(0, 1fr))' },
            }}
          >
            <InfoRow label={t('directory.ad.server')} value={status.server} />
            <InfoRow label={t('directory.ad.base_dn')} value={status.base_dn} />
            <InfoRow label={t('directory.ad.admin_group')} value={status.admin_group} />
            <InfoRow
              label={t('directory.ad.sync_interval')}
              value={t('directory.ad.minutes', { count: status.sync_interval_minutes })}
            />
          </Box>

          {actions}

          <Box>
            <Typography variant="subtitle2" fontWeight={700} gutterBottom>
              {t('directory.ad.last_sync')}
              {status.last_sync && (
                <Typography component="span" variant="caption" color="text.secondary" sx={{ ml: 1 }}>
                  {formatDateTimeValue(status.last_sync.fecha, i18n.language)}
                  {status.last_sync.dry_run && ` · ${t('directory.sync.simulation')}`}
                </Typography>
              )}
            </Typography>
            {status.last_sync ? (
              <SyncCounts result={status.last_sync} />
            ) : (
              <Typography variant="body2" color="text.secondary">
                {t('directory.ad.never_synced')}
              </Typography>
            )}
          </Box>

          {result && (
            <Box sx={{ pt: 2, borderTop: '1px solid', borderColor: 'divider' }}>
              <SyncResultView result={result} onForce={busy ? undefined : () => setForceOpen(true)} />
            </Box>
          )}
        </Stack>
      ) : null}

      <Dialog open={confirmOpen} onClose={() => setConfirmOpen(false)} maxWidth="xs" fullWidth>
        <DialogTitle>{t('directory.sync.confirm_title')}</DialogTitle>
        <DialogContent>
          <DialogContentText>{t('directory.sync.confirm_message')}</DialogContentText>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setConfirmOpen(false)}>{t('common.cancel')}</Button>
          <Button variant="contained" onClick={() => handleSync(false)} autoFocus>
            {t('directory.ad.sync_now')}
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog open={forceOpen} onClose={() => setForceOpen(false)} maxWidth="xs" fullWidth>
        <DialogTitle>{t('directory.sync.force_title')}</DialogTitle>
        <DialogContent>
          <DialogContentText>
            {t('directory.sync.force_message', { count: result?.bajas_retenidas ?? 0 })}
          </DialogContentText>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setForceOpen(false)} autoFocus>
            {t('common.cancel')}
          </Button>
          <Button variant="contained" color="warning" onClick={() => handleSync(false, true)}>
            {t('directory.sync.force_action')}
          </Button>
        </DialogActions>
      </Dialog>
    </SectionCard>
  );
};
