import { Box, Stack, Typography } from '@mui/material';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import RadioButtonUncheckedIcon from '@mui/icons-material/RadioButtonUnchecked';
import { useTranslation } from 'react-i18next';
import { checkPassword, DEFAULT_PASSWORD_POLICY, PasswordPolicy } from '../../../utils/password';

interface Props {
  password: string;
  policy: PasswordPolicy;
  /** Comprobaciones adicionales (coincidencia, distinta de la actual…). */
  extra?: { label: string; ok: boolean }[];
  /** The person is replacing their own password: it cannot repeat the current or earlier ones. */
  showHistory?: boolean;
}

/** Lista en vivo de los requisitos de la política de contraseñas. */
export const PasswordChecklist = ({ password, policy, extra = [], showHistory = false }: Props) => {
  const { t } = useTranslation();
  const items = [
    ...checkPassword(password, policy).map((check) => ({
      label: t(`security.password_rules.${check.rule}`, { min: policy.minLength }),
      ok: check.ok,
    })),
    ...extra,
  ];
  return (
    <Box component="ul" aria-label={t('security.password_rules.title')} sx={{ listStyle: 'none', m: 0, p: 0 }}>
      <Stack spacing={0.5}>
        {items.map((item) => (
          <Stack key={item.label} component="li" direction="row" spacing={1} alignItems="center">
            {item.ok
              ? <CheckCircleIcon sx={{ fontSize: 16, color: 'success.main' }} />
              : <RadioButtonUncheckedIcon sx={{ fontSize: 16, color: 'text.disabled' }} />}
            <Typography variant="caption" color={item.ok ? 'text.primary' : 'text.secondary'}>
              {item.label}
            </Typography>
          </Stack>
        ))}
        <Typography component="li" variant="caption" color="text.secondary" sx={{ pl: 3 }}>
          {t('security.password_rules.blocklist')}
        </Typography>
        {showHistory && (
          <Typography component="li" variant="caption" color="text.secondary" sx={{ pl: 3 }}>
            {t('security.password_rules.history', { total: policy.history ?? DEFAULT_PASSWORD_POLICY.history })}
          </Typography>
        )}
      </Stack>
    </Box>
  );
};
