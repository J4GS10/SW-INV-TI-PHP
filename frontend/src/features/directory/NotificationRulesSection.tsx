import { useCallback, useEffect, useState } from 'react';
import {
  Alert,
  Chip,
  IconButton,
  Stack,
  Switch,
  TableCell,
  TableRow,
  Tooltip,
  Typography,
} from '@mui/material';
import EditIcon from '@mui/icons-material/Edit';
import NotificationsActiveIcon from '@mui/icons-material/NotificationsActive';
import GroupsIcon from '@mui/icons-material/Groups';
import AlternateEmailIcon from '@mui/icons-material/AlternateEmail';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../api/errors';
import { DataTableShell, SectionCard, StatusChip } from '../../components/ui';
import { directoryService } from './directoryService';
import { isForbidden } from './directoryUtils';
import { RuleEditDialog } from './RuleEditDialog';
import { NOTIFICATION_EVENTS } from './types';
import type { NotificationRule, NotificationRuleInput } from './types';

type ToggleField = 'activa' | 'notificar_afectado' | 'notificar_jefe' | 'copiar_admins';

const toInput = (r: NotificationRule): NotificationRuleInput => ({
  activa: r.activa,
  notificar_afectado: r.notificar_afectado,
  notificar_jefe: r.notificar_jefe,
  copiar_admins: r.copiar_admins,
  grupos_ad: r.grupos_ad,
  correos_extra: r.correos_extra,
});

/** Orden estable: el definido en NOTIFICATION_EVENTS; eventos desconocidos al final. */
const sortRules = (rules: NotificationRule[]) => {
  const idx = (e: string) => {
    const i = (NOTIFICATION_EVENTS as readonly string[]).indexOf(e);
    return i === -1 ? Number.MAX_SAFE_INTEGER : i;
  };
  return [...rules].sort((a, b) => idx(a.evento) - idx(b.evento));
};

export const NotificationRulesSection = () => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const [rules, setRules] = useState<NotificationRule[]>([]);
  const [loading, setLoading] = useState(true);
  const [forbidden, setForbidden] = useState(false);
  const [savingEvent, setSavingEvent] = useState<string | null>(null);
  const [editing, setEditing] = useState<NotificationRule | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      setRules(sortRules(await directoryService.listRules()));
      setForbidden(false);
    } catch (err) {
      if (isForbidden(err)) setForbidden(true);
      else enqueueSnackbar(t('common.error_loading'), { variant: 'error' });
    } finally {
      setLoading(false);
    }
  }, [enqueueSnackbar, t]);

  useEffect(() => {
    load();
  }, [load]);

  const replaceRule = (updated: NotificationRule) =>
    setRules((prev) => prev.map((r) => (r.evento === updated.evento ? updated : r)));

  const handleToggle = async (rule: NotificationRule, field: ToggleField, value: boolean) => {
    setSavingEvent(rule.evento);
    try {
      const updated = await directoryService.updateRule(rule.evento, { ...toInput(rule), [field]: value });
      replaceRule(updated);
    } catch (err) {
      enqueueSnackbar(
        isForbidden(err) ? t('directory.forbidden_action') : getApiErrorMessage(err, t('common.error_save')),
        { variant: 'error' },
      );
    } finally {
      setSavingEvent(null);
    }
  };

  const toggleCell = (rule: NotificationRule, field: ToggleField, label: string) => (
    <TableCell align="center">
      <Switch
        size="small"
        checked={rule[field]}
        disabled={savingEvent === rule.evento || (field !== 'activa' && !rule.activa)}
        onChange={(e) => handleToggle(rule, field, e.target.checked)}
        slotProps={{ input: { 'aria-label': `${label} · ${t(`directory.events.${rule.evento}`, rule.evento)}` } }}
      />
    </TableCell>
  );

  return (
    <SectionCard
      title={t('directory.rules.title')}
      subtitle={t('directory.rules.subtitle')}
      icon={<NotificationsActiveIcon fontSize="small" />}
    >
      {forbidden ? (
        <Alert severity="warning">{t('directory.rules.forbidden')}</Alert>
      ) : (
        <DataTableShell
          columns={[
            { key: 'evento', label: t('directory.rules.col_event') },
            { key: 'activa', label: t('directory.rules.col_active'), align: 'center' },
            { key: 'afectado', label: t('directory.rules.col_affected'), align: 'center' },
            { key: 'jefe', label: t('directory.rules.col_manager'), align: 'center' },
            { key: 'admins', label: t('directory.rules.col_admins'), align: 'center' },
            { key: 'extra', label: t('directory.rules.col_extra') },
            { key: 'actions', label: t('common.actions'), align: 'right' },
          ]}
          rows={rules}
          loading={loading}
          emptyTitle={t('directory.rules.empty')}
          renderRow={(r) => (
            <TableRow key={r.evento} hover sx={{ opacity: r.activa ? 1 : 0.6 }}>
              <TableCell>
                <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
                  <Typography variant="body2" fontWeight={600}>
                    {t(`directory.events.${r.evento}`, r.evento)}
                  </Typography>
                  {r.personalizada ? (
                    <StatusChip
                      label={t('directory.rules.customized')}
                      variant="asignado"
                      tooltip={t('directory.rules.customized_hint')}
                    />
                  ) : (
                    <Typography variant="caption" color="text.secondary">
                      {t('directory.rules.default')}
                    </Typography>
                  )}
                </Stack>
              </TableCell>
              {toggleCell(r, 'activa', t('directory.rules.col_active'))}
              {toggleCell(r, 'notificar_afectado', t('directory.rules.col_affected'))}
              {toggleCell(r, 'notificar_jefe', t('directory.rules.col_manager'))}
              {toggleCell(r, 'copiar_admins', t('directory.rules.col_admins'))}
              <TableCell sx={{ maxWidth: 360 }}>
                {r.grupos_ad.length === 0 && r.correos_extra.length === 0 ? (
                  <Typography variant="caption" color="text.secondary">—</Typography>
                ) : (
                  <Stack direction="row" spacing={0.5} flexWrap="wrap" useFlexGap>
                    {r.grupos_ad.map((g) => (
                      <Chip key={`g-${g}`} size="small" icon={<GroupsIcon />} label={g} color="info" variant="outlined" />
                    ))}
                    {r.correos_extra.map((c) => (
                      <Chip key={`c-${c}`} size="small" icon={<AlternateEmailIcon />} label={c} variant="outlined" />
                    ))}
                  </Stack>
                )}
              </TableCell>
              <TableCell align="right">
                <Tooltip title={t('common.edit') as string}>
                  <IconButton size="small" color="primary" onClick={() => setEditing(r)}>
                    <EditIcon fontSize="small" />
                  </IconButton>
                </Tooltip>
              </TableCell>
            </TableRow>
          )}
        />
      )}

      <RuleEditDialog
        rule={editing}
        onClose={() => setEditing(null)}
        onSaved={(updated) => {
          replaceRule(updated);
          setEditing(updated);
        }}
        onReset={() => {
          setEditing(null);
          load();
        }}
      />
    </SectionCard>
  );
};
