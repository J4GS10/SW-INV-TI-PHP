import { useState } from 'react';
import type { ReactNode } from 'react';
import { Box, Button, IconButton, InputAdornment, TextField, Typography } from '@mui/material';
import Visibility from '@mui/icons-material/Visibility';
import VisibilityOff from '@mui/icons-material/VisibilityOff';
import { useTranslation } from 'react-i18next';
import type { SecretState } from './integrationForm';

interface SecretFieldProps {
  label: string;
  configured: boolean;
  state: SecretState;
  onChange: (next: SecretState) => void;
  error?: string;
  helperText?: string;
  autoComplete?: string;
}

/** Campo de clave: nunca muestra la guardada; vacío = conservarla; "borrar clave" = enviar "". */
export const SecretField = ({ label, configured, state, onChange, error, helperText, autoComplete }: SecretFieldProps) => {
  const { t } = useTranslation();
  const [show, setShow] = useState(false);
  const cleared = state.clear;

  return (
    <Box>
      <TextField
        fullWidth
        size="small"
        label={label}
        type={show ? 'text' : 'password'}
        value={state.value}
        disabled={cleared}
        autoComplete={autoComplete ?? 'new-password'}
        placeholder={configured && !cleared ? t('directory.integration.secret_saved_placeholder') : undefined}
        onChange={(e) => onChange({ value: e.target.value, clear: false })}
        error={!!error}
        helperText={
          error ??
          (cleared
            ? t('directory.integration.secret_will_clear')
            : configured
              ? t('directory.integration.secret_keep_hint')
              : helperText)
        }
        slotProps={{
          inputLabel: configured && !cleared ? { shrink: true } : undefined,
          input: {
            endAdornment: (
              <InputAdornment position="end">
                <IconButton
                  size="small"
                  edge="end"
                  aria-label={show ? t('directory.integration.hide_secret') : t('directory.integration.show_secret')}
                  onClick={() => setShow((v) => !v)}
                  disabled={cleared}
                >
                  {show ? <VisibilityOff fontSize="small" /> : <Visibility fontSize="small" />}
                </IconButton>
              </InputAdornment>
            ),
          },
        }}
      />
      {configured && (
        <Button
          size="small"
          color={cleared ? 'primary' : 'error'}
          sx={{ mt: 0.25, px: 0.5, minWidth: 0, textTransform: 'none' }}
          onClick={() => onChange({ value: '', clear: !cleared })}
        >
          {cleared ? t('directory.integration.undo_clear_secret') : t('directory.integration.clear_secret')}
        </Button>
      )}
    </Box>
  );
};

export const Grid2 = ({ children }: { children: ReactNode }) => (
  <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: 'repeat(2, minmax(0, 1fr))' } }}>
    {children}
  </Box>
);

export const SubTitle = ({ children }: { children: ReactNode }) => (
  <Typography variant="subtitle2" fontWeight={700}>
    {children}
  </Typography>
);
