import { Alert, AlertTitle, Box, Button, Chip, CircularProgress, Collapse, Skeleton, Stack, Typography } from '@mui/material';
import MailLockIcon from '@mui/icons-material/MailLock';
import NetworkCheckIcon from '@mui/icons-material/NetworkCheck';
import SaveIcon from '@mui/icons-material/Save';
import SendIcon from '@mui/icons-material/Send';
import { useTranslation } from 'react-i18next';
import { SectionCard, StatusChip } from '../../components/ui';
import { AccountSection, ProviderPresets } from './integration/AccountSection';
import { ActiveDirectorySection } from './integration/ActiveDirectorySection';
import { formatTime, isFuture } from './integration/integrationForm';
import { MailServerSection } from './integration/MailServerSection';
import { MailTestDialog, TestOutcomeAlert } from './integration/MailTestDialog';
import { ModeSelector } from './integration/ModeSelector';
import { useIntegrationForm } from './integration/useIntegrationForm';

interface Props {
  /** Se invoca tras guardar, para que otras secciones (estado AD) se recarguen. */
  onSaved?: () => void;
}

/** Correo saliente y Active Directory: modo, proveedor, cuenta de servicio, pruebas y guardado. */
export const IntegrationSection = ({ onSaved }: Props) => {
  const { t } = useTranslation();
  const s = useIntegrationForm(onSaved);
  const { config, form, secrets, busy, dirty, validation } = s;
  const title = t('directory.integration.title');
  const icon = <MailLockIcon fontSize="small" />;

  if (s.loading) {
    return (
      <SectionCard title={title} icon={icon}>
        <Stack spacing={1}>
          <Skeleton variant="rounded" height={72} />
          <Skeleton variant="rounded" height={160} />
        </Stack>
      </SectionCard>
    );
  }

  if (s.loadError || !form || !config) {
    return (
      <SectionCard title={title} icon={icon}>
        <Alert severity={s.loadError === 'forbidden' ? 'warning' : 'error'}>
          {s.loadError === 'forbidden' ? t('directory.forbidden_section') : t('common.error_loading')}
        </Alert>
      </SectionCard>
    );
  }

  const { errors } = validation;
  const formValid = validation.mailValid && validation.adValid;
  const enabled = form.modo !== 'DESACTIVADO';
  const withAd = form.modo === 'CORREO_AD';
  const needsAccountPassword = form.proveedor === 'SMTP' || (withAd && form.ad_usar_cuenta_servicio);
  const smtpLockedUntil = isFuture(config.bloqueo.smtp_hasta) ? config.bloqueo.smtp_hasta : null;
  const adLockedUntil = isFuture(config.bloqueo.ad_hasta) ? config.bloqueo.ad_hasta : null;
  const isBusy = busy !== null;

  const statusChips = (
    <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
      <StatusChip
        label={
          config.origen === 'interfaz'
            ? t('directory.integration.origin_ui')
            : t('directory.integration.origin_env')
        }
        variant={config.origen === 'interfaz' ? 'activo' : 'por_vencer'}
        tooltip={config.origen === 'entorno' ? t('directory.integration.origin_env_tooltip') : undefined}
      />
      {dirty && <Chip size="small" color="warning" variant="outlined" label={t('directory.integration.unsaved')} />}
    </Stack>
  );

  return (
    <SectionCard
      title={title}
      subtitle={t('directory.integration.subtitle')}
      icon={icon}
      actions={statusChips}
    >
      <Stack spacing={3}>
        {/* ── Avisos de estado ── */}
        {!config.cifrado_ok && (
          <Alert severity="warning">
            <AlertTitle>{t('directory.integration.no_encryption_title')}</AlertTitle>
            {t('directory.integration.no_encryption_description')}
          </Alert>
        )}
        {(smtpLockedUntil || adLockedUntil) && (
          <Alert
            severity="error"
            action={
              <Button
                color="inherit"
                size="small"
                onClick={s.unlock}
                disabled={isBusy}
                startIcon={busy === 'unlock' ? <CircularProgress size={14} color="inherit" /> : undefined}
              >
                {t('directory.integration.unlock')}
              </Button>
            }
          >
            <AlertTitle>{t('directory.integration.locked_title')}</AlertTitle>
            {smtpLockedUntil && (
              <Box>{t('directory.integration.locked_mail', { time: formatTime(smtpLockedUntil) })}</Box>
            )}
            {adLockedUntil && <Box>{t('directory.integration.locked_ad', { time: formatTime(adLockedUntil) })}</Box>}
          </Alert>
        )}

        <ModeSelector value={form.modo} onChange={(m) => s.set('modo', m)} />

        <Collapse in={enabled} unmountOnExit>
          <Stack spacing={3}>
            <ProviderPresets preset={s.preset} onChange={s.applyPreset} />
            <AccountSection
              form={form}
              errors={errors}
              set={s.set}
              config={config}
              secrets={secrets}
              onSecret={(v) => s.setSecret('cuenta_password', v)}
              needsPassword={needsAccountPassword}
              showAltUser={s.showAltUser}
              onToggleAltUser={s.toggleAltUser}
            />
            <MailServerSection
              form={form}
              errors={errors}
              set={s.set}
              config={config}
              secrets={secrets}
              onGraphSecret={(v) => s.setSecret('graph_client_secret', v)}
            />
            {withAd && (
              <ActiveDirectorySection
                form={form}
                errors={errors}
                set={s.set}
                config={config}
                secrets={secrets}
                onBindSecret={(v) => s.setSecret('ad_bind_password', v)}
              />
            )}
          </Stack>
        </Collapse>

        {s.outcome && <TestOutcomeAlert outcome={s.outcome} onClose={() => s.setOutcome(null)} />}

        {/* ── 6. Acciones ── */}
        <Stack
          direction={{ xs: 'column', sm: 'row' }}
          spacing={1}
          justifyContent="space-between"
          alignItems={{ xs: 'stretch', sm: 'center' }}
          sx={{ pt: 2, borderTop: '1px solid', borderColor: 'divider' }}
        >
          <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
            {enabled && (
              <Button
                size="small"
                variant="outlined"
                startIcon={busy === 'mail' ? <CircularProgress size={16} /> : <SendIcon />}
                disabled={isBusy || !validation.mailValid}
                onClick={s.openMailTest}
              >
                {t('directory.integration.send_test')}
              </Button>
            )}
            {withAd && (
              <Button
                size="small"
                variant="outlined"
                startIcon={busy === 'ad' ? <CircularProgress size={16} /> : <NetworkCheckIcon />}
                disabled={isBusy || !validation.adValid}
                onClick={s.testAd}
              >
                {t('directory.integration.test_ad')}
              </Button>
            )}
          </Stack>
          <Stack direction="row" spacing={1} alignItems="center" justifyContent="flex-end">
            {dirty && (
              <Button size="small" onClick={s.discard} disabled={isBusy}>
                {t('directory.integration.discard')}
              </Button>
            )}
            <Button
              size="small"
              variant="contained"
              startIcon={busy === 'save' ? <CircularProgress size={16} color="inherit" /> : <SaveIcon />}
              disabled={isBusy || !dirty || !formValid}
              onClick={s.save}
            >
              {busy === 'save' ? t('common.saving') : t('common.save')}
            </Button>
          </Stack>
        </Stack>
        {dirty && !formValid && (
          <Typography variant="caption" color="error" sx={{ mt: -2, textAlign: 'right' }}>
            {t('directory.integration.errors.fix_before_save')}
          </Typography>
        )}
      </Stack>

      <MailTestDialog
        open={s.testDialogOpen}
        recipient={s.destinatario}
        onRecipient={s.setDestinatario}
        onClose={() => s.setTestDialogOpen(false)}
        onSend={s.sendMailTest}
      />
    </SectionCard>
  );
};
