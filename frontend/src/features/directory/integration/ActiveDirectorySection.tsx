import { Box, FormControlLabel, Stack, Switch, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import type { IntegrationOut } from '../types';
import { Grid2, SecretField, SubTitle } from './IntegrationFields';
import type { Errors, FormState, SecretState, Secrets, SetField } from './integrationForm';

interface Props {
  form: FormState;
  errors: Errors;
  set: SetField;
  config: IntegrationOut;
  secrets: Secrets;
  onBindSecret: (next: SecretState) => void;
}

/** Paso 5: conexión con Active Directory y cuenta con la que se consulta. */
export const ActiveDirectorySection = ({ form, errors, set, config, secrets, onBindSecret }: Props) => {
  const { t } = useTranslation();

  return (
    <Stack spacing={2}>
      <SubTitle>{t('directory.integration.ad_title')}</SubTitle>
      <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('directory.integration.ad_info')}</Typography>
      <Grid2>
        <TextField
          size="small"
          label={t('directory.integration.fields.ad_servidor')}
          placeholder="ldaps://dc01.empresa.local"
          value={form.ad_servidor}
          onChange={(e) => set('ad_servidor', e.target.value)}
          error={!!errors.ad_servidor}
          helperText={errors.ad_servidor}
          required
          autoComplete="off"
        />
        <TextField
          size="small"
          label={t('directory.integration.fields.ad_base_dn')}
          placeholder="OU=Usuarios,DC=empresa,DC=local"
          value={form.ad_base_dn}
          onChange={(e) => set('ad_base_dn', e.target.value)}
          error={!!errors.ad_base_dn}
          helperText={errors.ad_base_dn}
          required
        />
        <TextField
          size="small"
          label={t('directory.integration.fields.ad_grupo_base_dn')}
          placeholder="OU=Grupos,DC=empresa,DC=local"
          value={form.ad_grupo_base_dn}
          onChange={(e) => set('ad_grupo_base_dn', e.target.value)}
          helperText={t('directory.integration.help.ad_grupo_base_dn')}
        />
        <TextField
          size="small"
          label={t('directory.integration.fields.ad_grupo_admin')}
          placeholder="GG-Inventario-Admins"
          value={form.ad_grupo_admin}
          onChange={(e) => set('ad_grupo_admin', e.target.value)}
          helperText={t('directory.integration.help.ad_grupo_admin')}
        />
        <TextField
          size="small"
          label={t('directory.integration.fields.ad_intervalo_min')}
          value={form.ad_intervalo_min}
          onChange={(e) => set('ad_intervalo_min', e.target.value.replace(/[^\d]/g, ''))}
          error={!!errors.ad_intervalo_min}
          helperText={errors.ad_intervalo_min ?? t('directory.integration.help.ad_intervalo_min')}
          slotProps={{ htmlInput: { inputMode: 'numeric', maxLength: 5 } }}
        />
      </Grid2>
      <Stack direction={{ xs: 'column', md: 'row' }} spacing={{ xs: 0, md: 3 }} flexWrap="wrap" useFlexGap>
        <FormControlLabel
          control={
            <Switch checked={form.ad_start_tls} onChange={(e) => set('ad_start_tls', e.target.checked)} />
          }
          label={t('directory.integration.fields.ad_start_tls')}
        />
        <FormControlLabel
          control={
            <Switch
              checked={form.ad_verificar_cert}
              onChange={(e) => set('ad_verificar_cert', e.target.checked)}
            />
          }
          label={t('directory.integration.fields.ad_verificar_cert')}
        />
        <FormControlLabel
          control={
            <Switch checked={form.ad_desactivar} onChange={(e) => set('ad_desactivar', e.target.checked)} />
          }
          label={t('directory.integration.fields.ad_desactivar')}
        />
      </Stack>
      {!form.ad_verificar_cert && (
        <Typography variant="caption" color="warning.main" sx={{ display: 'block' }}>{t('directory.integration.verify_cert_off_warning')}</Typography>
      )}

      <Box>
        <FormControlLabel
          control={
            <Switch
              checked={form.ad_usar_cuenta_servicio}
              onChange={(e) => set('ad_usar_cuenta_servicio', e.target.checked)}
            />
          }
          label={t('directory.integration.fields.ad_usar_cuenta_servicio')}
        />
        {form.ad_usar_cuenta_servicio && (
          <Typography variant="caption" color="text.secondary" display="block">
            {t('directory.integration.help.ad_usar_cuenta_servicio', {
              account: form.cuenta_usuario.trim() || form.cuenta_email.trim() || '—',
            })}
          </Typography>
        )}
      </Box>
      {!form.ad_usar_cuenta_servicio && (
        <Grid2>
          <TextField
            size="small"
            label={t('directory.integration.fields.ad_bind_usuario')}
            placeholder="svc_inventario@empresa.local"
            value={form.ad_bind_usuario}
            onChange={(e) => set('ad_bind_usuario', e.target.value)}
            error={!!errors.ad_bind_usuario}
            helperText={errors.ad_bind_usuario ?? t('directory.integration.help.ad_bind_usuario')}
            required
            autoComplete="off"
          />
          <SecretField
            label={t('directory.integration.fields.ad_bind_password')}
            configured={config.ad_bind_password_configurada}
            state={secrets.ad_bind_password}
            onChange={onBindSecret}
            error={errors.ad_bind_password}
          />
        </Grid2>
      )}
    </Stack>
  );
};
