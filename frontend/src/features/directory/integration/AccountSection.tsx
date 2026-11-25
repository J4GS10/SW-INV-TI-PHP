import { Box, Button, Collapse, Stack, TextField, ToggleButton, ToggleButtonGroup, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import type { IntegrationOut } from '../types';
import { Grid2, SecretField, SubTitle } from './IntegrationFields';
import { PRESETS } from './integrationForm';
import type { Errors, FormState, Preset, SecretState, Secrets, SetField } from './integrationForm';

interface ProviderProps {
  preset: Preset;
  onChange: (preset: Preset) => void;
}

/** Paso 2: proveedor predefinido (rellena servidor, puerto y seguridad). */
export const ProviderPresets = ({ preset, onChange }: ProviderProps) => {
  const { t } = useTranslation();

  return (
    <Stack spacing={1.5}>
      <SubTitle>{t('directory.integration.provider_label')}</SubTitle>
      <ToggleButtonGroup
        exclusive
        size="small"
        color="primary"
        value={preset}
        onChange={(_, v: Preset | null) => v && onChange(v)}
        sx={{ flexWrap: 'wrap', gap: 1, '& .MuiToggleButton-root': { borderRadius: '8px !important', border: '1px solid', borderColor: 'divider', textTransform: 'none' } }}
      >
        {PRESETS.map((p) => (
          <ToggleButton key={p} value={p}>
            {t(`directory.integration.presets.${p}`)}
          </ToggleButton>
        ))}
      </ToggleButtonGroup>
      {preset === 'm365_smtp' && (
        <Typography variant="caption" color="warning.main" sx={{ display: 'block' }}>{t('directory.integration.preset_notes.m365_smtp')}</Typography>
      )}
      {preset === 'google' && <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('directory.integration.preset_notes.google')}</Typography>}
      {preset === 'exchange' && (
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('directory.integration.preset_notes.exchange')}</Typography>
      )}
    </Stack>
  );
};

interface AccountProps {
  form: FormState;
  errors: Errors;
  set: SetField;
  config: IntegrationOut;
  secrets: Secrets;
  onSecret: (next: SecretState) => void;
  needsPassword: boolean;
  /** Se abre sola si la cuenta ya tiene un usuario distinto del correo. */
  showAltUser: boolean;
  onToggleAltUser: () => void;
}

/** Paso 3: la cuenta de servicio que envía el correo (y, si se elige, consulta el directorio). */
export const AccountSection = ({ form, errors, set, config, secrets, onSecret, needsPassword, showAltUser, onToggleAltUser }: AccountProps) => {
  const { t } = useTranslation();

  return (
    <Stack spacing={2}>
      <SubTitle>{t('directory.integration.account_title')}</SubTitle>
      <Grid2>
        <TextField
          size="small"
          label={t('directory.integration.fields.cuenta_email')}
          placeholder="inventario@empresa.com"
          value={form.cuenta_email}
          onChange={(e) => set('cuenta_email', e.target.value)}
          error={!!errors.cuenta_email}
          helperText={errors.cuenta_email ?? t('directory.integration.help.cuenta_email')}
          required
          type="email"
          autoComplete="off"
        />
        <TextField
          size="small"
          label={t('directory.integration.fields.nombre_remitente')}
          placeholder={t('directory.integration.placeholders.nombre_remitente')}
          value={form.nombre_remitente}
          onChange={(e) => set('nombre_remitente', e.target.value)}
          helperText={t('directory.integration.help.nombre_remitente')}
        />
        {needsPassword && (
          <SecretField
            label={t('directory.integration.fields.cuenta_password')}
            configured={config.password_configurada}
            state={secrets.cuenta_password}
            onChange={onSecret}
            error={errors.cuenta_password}
          />
        )}
      </Grid2>
      <Box>
        <Button size="small" sx={{ textTransform: 'none', px: 0.5 }} onClick={onToggleAltUser}>
          {showAltUser ? t('directory.integration.alt_user_hide') : t('directory.integration.alt_user_show')}
        </Button>
        <Collapse in={showAltUser}>
          <Box sx={{ pt: 1.5, maxWidth: { md: 'calc(50% - 8px)' } }}>
            <TextField
              fullWidth
              size="small"
              label={t('directory.integration.fields.cuenta_usuario')}
              placeholder="EMPRESA\svc_inventario"
              value={form.cuenta_usuario}
              onChange={(e) => set('cuenta_usuario', e.target.value)}
              helperText={t('directory.integration.help.cuenta_usuario')}
              autoComplete="off"
            />
          </Box>
        </Collapse>
      </Box>
    </Stack>
  );
};
