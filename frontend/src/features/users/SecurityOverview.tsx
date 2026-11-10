import type { ReactNode } from 'react';
import { Box, Chip, Skeleton, Stack, Typography } from '@mui/material';
import GppMaybeIcon from '@mui/icons-material/GppMaybe';
import LockClockIcon from '@mui/icons-material/LockClock';
import LockResetIcon from '@mui/icons-material/LockReset';
import PeopleIcon from '@mui/icons-material/People';
import PolicyIcon from '@mui/icons-material/Policy';
import VerifiedUserIcon from '@mui/icons-material/VerifiedUser';
import DevicesIcon from '@mui/icons-material/Devices';
import TravelExploreIcon from '@mui/icons-material/TravelExplore';
import { useTranslation } from 'react-i18next';
import { KpiCard, SectionCard } from '../../components/ui';
import type { ResumenSeguridad } from '../../types/users';

interface Props {
  summary: ResumenSeguridad | null;
  loading: boolean;
  /** Abre la pestaña de sesiones. */
  onShowSessions?: () => void;
  /** Filtra las cuentas con MFA obligatoria pendiente. */
  onShowMfaPending?: () => void;
}

const PolicyItem = ({ label, children }: { label: string; children: ReactNode }) => (
  <Box sx={{ minWidth: 0 }}>
    <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 0.25 }}>{label}</Typography>
    {children}
  </Box>
);

/** Indicadores de seguridad de las cuentas; los de sesiones y MFA pendiente llevan a su detalle. */
export const SecurityOverview = ({ summary, loading, onShowSessions, onShowMfaPending }: Props) => {
  const { t } = useTranslation();

  if (loading && !summary) {
    return (
      <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr 1fr', sm: 'repeat(3, 1fr)', lg: 'repeat(7, 1fr)' }, gap: 2, mb: 2 }}>
        {Array.from({ length: 7 }).map((_, i) => <Skeleton key={i} variant="rounded" height={104} />)}
      </Box>
    );
  }
  if (!summary) return null;

  const totalActive = summary.usuarios_activos;

  return (
    <>
      <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr 1fr', sm: 'repeat(3, 1fr)', lg: 'repeat(7, 1fr)' }, gap: 2, mb: 2 }}>
        <KpiCard
          title={t('users.kpi.active')}
          value={totalActive}
          icon={PeopleIcon}
          tone="primary"
          subtitle={t('users.kpi.inactive', { count: summary.usuarios_inactivos })}
        />
        <KpiCard
          title={t('users.kpi.mfa_coverage')}
          value={`${summary.cobertura_mfa}%`}
          icon={VerifiedUserIcon}
          tone={summary.cobertura_mfa >= 100 ? 'success' : 'info'}
          subtitle={t('users.kpi.mfa_coverage_detail', { with: summary.con_mfa, total: totalActive })}
        />
        <KpiCard
          title={t('users.kpi.mfa_pending')}
          value={summary.mfa_pendiente_obligatorio}
          icon={GppMaybeIcon}
          tone={summary.mfa_pendiente_obligatorio > 0 ? 'warning' : 'success'}
          subtitle={t('users.kpi.mfa_pending_detail')}
          onClick={summary.mfa_pendiente_obligatorio > 0 ? onShowMfaPending : undefined}
        />
        <KpiCard
          title={t('users.kpi.locked')}
          value={summary.bloqueados}
          icon={LockClockIcon}
          tone={summary.bloqueados > 0 ? 'error' : 'success'}
          subtitle={t('users.kpi.locked_detail')}
        />
        <KpiCard
          title={t('users.kpi.password_pending')}
          value={summary.cambio_password_pendiente}
          icon={LockResetIcon}
          tone="info"
          subtitle={t('users.kpi.password_pending_detail')}
        />
        <KpiCard
          title={t('users.kpi.sessions')}
          value={summary.sesiones_activas ?? 0}
          icon={DevicesIcon}
          tone="info"
          subtitle={t('users.kpi.sessions_detail')}
          onClick={onShowSessions}
        />
        <KpiCard
          title={t('users.kpi.no_scope')}
          value={summary.usuarios_sin_alcance ?? 0}
          icon={TravelExploreIcon}
          tone={(summary.usuarios_sin_alcance ?? 0) > 0 ? 'warning' : 'success'}
          subtitle={t('users.kpi.no_scope_detail', { count: summary.usuarios_alcance_global ?? 0 })}
        />
      </Box>
      {summary.rls_base_datos === false && (
        <Typography variant="body2" color="warning.main" sx={{ mb: 2 }}>
          {t('users.policy.rls_db_inactive')}
        </Typography>
      )}

    </>
  );
};

/** Política de seguridad vigente (solo lectura, definida en el servidor). */
export const SecurityPolicy = ({ summary }: { summary: ResumenSeguridad }) => {
  const { t } = useTranslation();
  const p = summary.politica;
  const requirements = [
    p.password_mayuscula && t('users.policy.upper'),
    p.password_minuscula && t('users.policy.lower'),
    p.password_digito && t('users.policy.digit'),
    p.password_simbolo && t('users.policy.symbol'),
  ].filter(Boolean).join(' · ');

  return (
        <SectionCard title={t('users.policy.title')} subtitle={t('users.policy.subtitle')} icon={<PolicyIcon fontSize="small" />}>
          <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr', lg: 'repeat(4, 1fr)' }, gap: 2 }}>
            <PolicyItem label={t('users.policy.passwords')}>
              <Typography variant="body2">{t('users.policy.min_length', { min: p.password_longitud_minima })}</Typography>
              {requirements && <Typography variant="caption" color="text.secondary">{requirements}</Typography>}
            </PolicyItem>
            <PolicyItem label={t('users.policy.lockout')}>
              <Typography variant="body2">
                {t('users.policy.lockout_value', { attempts: p.bloqueo_intentos, minutes: p.bloqueo_minutos })}
              </Typography>
              {!!p.bloqueo_progresivo_tope_minutos && p.bloqueo_progresivo_tope_minutos > p.bloqueo_minutos && (
                <Typography variant="caption" color="text.secondary">
                  {t('users.policy.lockout_progressive', { hours: Math.round(p.bloqueo_progresivo_tope_minutos / 60) })}
                </Typography>
              )}
            </PolicyItem>
            <PolicyItem label={t('users.policy.sessions')}>
              <Typography variant="body2">
                {t('users.policy.sessions_value', { idle: p.sesion_inactividad_minutos, max: p.sesion_maxima_horas })}
              </Typography>
            </PolicyItem>
            <PolicyItem label={t('users.policy.password_rules')}>
              <Typography variant="body2">
                {t('users.policy.history_value', { count: p.password_historial ?? 0 })}
              </Typography>
              <Typography variant="caption" color="text.secondary">{t('users.policy.blocklist_value')}</Typography>
            </PolicyItem>
            <PolicyItem label={t('users.policy.accounts')}>
              <Typography variant="body2">
                {p.cuentas_inactivas_dias
                  ? t('users.policy.inactive_value', { days: p.cuentas_inactivas_dias })
                  : t('users.policy.inactive_off')}
              </Typography>
              <Typography variant="caption" color="text.secondary">
                {p.aviso_dispositivo_nuevo ? t('users.policy.new_device_on') : t('users.policy.new_device_off')}
              </Typography>
            </PolicyItem>
            <PolicyItem label={t('users.policy.audit')}>
              <Typography variant="body2">
                {p.auditoria_retencion_dias
                  ? t('users.policy.retention_value', { days: Math.max(p.auditoria_retencion_dias, 365) })
                  : t('users.policy.retention_forever')}
              </Typography>
              <Typography variant="caption" color="text.secondary">
                {summary.rls_base_datos ? t('users.policy.rls_db_active') : t('users.policy.rls_app_only')}
              </Typography>
            </PolicyItem>
            <PolicyItem label={t('users.policy.mfa_roles')}>
              {p.mfa_roles_obligatorios.length > 0 ? (
                <Stack direction="row" spacing={0.5} useFlexGap flexWrap="wrap">
                  {p.mfa_roles_obligatorios.map((role) => (
                    <Chip key={role} size="small" variant="outlined" label={t(`users.roles.${role}`, role)} />
                  ))}
                </Stack>
              ) : (
                <Typography variant="body2">{t('users.policy.mfa_roles_none')}</Typography>
              )}
            </PolicyItem>
          </Box>
        </SectionCard>
  );
};
