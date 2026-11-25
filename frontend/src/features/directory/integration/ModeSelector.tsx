import { Box, Radio, Stack, Typography } from '@mui/material';
import { alpha } from '@mui/material/styles';
import { useTranslation } from 'react-i18next';
import type { IntegrationMode } from '../types';
import { SubTitle } from './IntegrationFields';
import { MODES } from './integrationForm';

interface Props {
  value: IntegrationMode;
  onChange: (mode: IntegrationMode) => void;
}

/** Paso 1: desactivado, solo correo o correo con Active Directory. */
export const ModeSelector = ({ value, onChange }: Props) => {
  const { t } = useTranslation();

  return (
    <Stack spacing={1}>
      <SubTitle>{t('directory.integration.mode_label')}</SubTitle>
      <Box
        role="radiogroup"
        aria-label={t('directory.integration.mode_label')}
        sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', md: 'repeat(3, minmax(0, 1fr))' } }}
      >
        {MODES.map((m) => {
          const selected = value === m;
          return (
            <Box
              key={m}
              component="label"
              sx={(theme) => ({
                display: 'flex',
                gap: 1,
                alignItems: 'flex-start',
                p: 1.5,
                pl: 0.5,
                borderRadius: 2,
                cursor: 'pointer',
                border: '1px solid',
                borderColor: selected ? 'primary.main' : 'divider',
                bgcolor: selected ? alpha(theme.palette.primary.main, 0.06) : 'transparent',
                transition: 'border-color .15s, background-color .15s',
                '&:hover': { borderColor: selected ? 'primary.main' : 'text.secondary' },
              })}
            >
              <Radio size="small" checked={selected} onChange={() => onChange(m)} value={m} sx={{ mt: -0.5 }} />
              <Box>
                <Typography variant="body2" fontWeight={600}>
                  {t(`directory.integration.modes.${m}.label`)}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  {t(`directory.integration.modes.${m}.description`)}
                </Typography>
              </Box>
            </Box>
          );
        })}
      </Box>
    </Stack>
  );
};
