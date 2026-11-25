import { Box, MenuItem, Stack, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import type { IntegrationOut, SmtpSecurity } from '../types';
import { Grid2, SecretField, SubTitle } from './IntegrationFields';
import { SECURITY_OPTIONS } from './integrationForm';
import type { Errors, FormState, SecretState, Secrets, SetField } from './integrationForm';

interface Props {
  form: FormState;
  errors: Errors;
  set: SetField;
  config: IntegrationOut;
  secrets: Secrets;
  onGraphSecret: (next: SecretState) => void;
}

/** Paso 4: servidor SMTP o aplicación de Microsoft Graph, según el proveedor. */
export const MailServerSection = ({ form, errors, set, config, secrets, onGraphSecret }: Props) => {
  const { t } = useTranslation();

  if (form.proveedor === 'SMTP') {
    return (
      <Stack spacing={2}>
        <SubTitle>{t('directory.integration.smtp_title')}</SubTitle>
        <Box
          sx={{
            display: 'grid',
            gap: 2,
            gridTemplateColumns: { xs: '1fr', sm: '2fr 1fr 1fr' },
          }}
        >
          <TextField
            size="small"
            label={t('directory.integration.fields.smtp_host')}
            placeholder="mail.empresa.local"
            value={form.smtp_host}
            onChange={(e) => set('smtp_host', e.target.value)}
            error={!!errors.smtp_host}
            helperText={errors.smtp_host}
            required
          />
          <TextField
            size="small"
            label={t('directory.integration.fields.smtp_puerto')}
            value={form.smtp_puerto}
            onChange={(e) => set('smtp_puerto', e.target.value.replace(/[^\d]/g, ''))}
            error={!!errors.smtp_puerto}
            helperText={errors.smtp_puerto}
            required
            slotProps={{ htmlInput: { inputMode: 'numeric', maxLength: 5 } }}
          />
          <TextField
            select
            size="small"
            label={t('directory.integration.fields.smtp_seguridad')}
            value={form.smtp_seguridad}
            onChange={(e) => set('smtp_seguridad', e.target.value as SmtpSecurity)}
          >
            {SECURITY_OPTIONS.map((o) => (
              <MenuItem key={o} value={o}>
                {t(`directory.integration.security.${o}`)}
              </MenuItem>
            ))}
          </TextField>
        </Box>
        {form.smtp_seguridad === 'NINGUNA' && (
          <Typography variant="caption" color="warning.main" sx={{ display: 'block' }}>{t('directory.integration.security_none_warning')}</Typography>
        )}
      </Stack>
    );
  }

  return (
    <Stack spacing={2}>
      <SubTitle>{t('directory.integration.graph_title')}</SubTitle>
      <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('directory.integration.graph_info')}</Typography>
      <Grid2>
        <TextField
          size="small"
          label={t('directory.integration.fields.graph_tenant_id')}
          placeholder="00000000-0000-0000-0000-000000000000"
          value={form.graph_tenant_id}
          onChange={(e) => set('graph_tenant_id', e.target.value)}
          error={!!errors.graph_tenant_id}
          helperText={errors.graph_tenant_id}
          required
          autoComplete="off"
        />
        <TextField
          size="small"
          label={t('directory.integration.fields.graph_client_id')}
          placeholder="00000000-0000-0000-0000-000000000000"
          value={form.graph_client_id}
          onChange={(e) => set('graph_client_id', e.target.value)}
          error={!!errors.graph_client_id}
          helperText={errors.graph_client_id}
          required
          autoComplete="off"
        />
        <SecretField
          label={t('directory.integration.fields.graph_client_secret')}
          configured={config.graph_secret_configurado}
          state={secrets.graph_client_secret}
          onChange={onGraphSecret}
          error={errors.graph_client_secret}
        />
      </Grid2>
    </Stack>
  );
};
