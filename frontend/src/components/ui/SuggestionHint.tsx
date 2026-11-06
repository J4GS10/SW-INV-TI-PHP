import { Box, Button, Typography } from '@mui/material';
import type { SvgIconComponent } from '@mui/icons-material';
import AutoAwesomeIcon from '@mui/icons-material/AutoAwesome';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';
import { useTranslation } from 'react-i18next';
import type { ReactNode } from 'react';
import type { SuggestionHintState } from '../../hooks/useSuggestion';

const rowSx = { display: 'flex', alignItems: 'center', gap: 0.75, mt: 0.5, mx: 1.75, minHeight: 20 } as const;

/**
 * Leyenda bajo un campo prellenado: explica por qué se sugiere el valor y,
 * si el usuario lo cambió, ofrece restablecer la sugerencia.
 */
export const SuggestionHint = ({ hint }: { hint: SuggestionHintState | null }) => {
  const { t } = useTranslation();
  if (!hint) return null;
  const text = hint.modified && hint.label
    ? t('suggestions.hint_value', { value: hint.label, reason: hint.reason })
    : t('suggestions.hint', { reason: hint.reason });
  return (
    <Box sx={rowSx}>
      <AutoAwesomeIcon sx={{ fontSize: 14, color: hint.modified ? 'text.disabled' : 'primary.main', flexShrink: 0 }} />
      <Typography variant="caption" color="text.secondary" sx={{ overflowWrap: 'anywhere' }}>
        {text}
      </Typography>
      {hint.modified && (
        <Button
          size="small"
          onClick={hint.reset}
          sx={{ minWidth: 0, py: 0, px: 0.75, ml: 'auto', flexShrink: 0, fontSize: 12, lineHeight: 1.6 }}
        >
          {t('suggestions.reset')}
        </Button>
      )}
    </Box>
  );
};

interface ContextCaptionProps {
  children: ReactNode;
  /** `error` para avisos que impiden guardar. */
  tone?: 'info' | 'error';
  icon?: SvgIconComponent;
}

/** Línea informativa de solo lectura con datos relacionados a la selección. */
export const ContextCaption = ({ children, tone = 'info', icon: Icon = InfoOutlinedIcon }: ContextCaptionProps) => (
  <Box sx={rowSx}>
    <Icon sx={{ fontSize: 14, color: tone === 'error' ? 'error.main' : 'text.secondary', flexShrink: 0 }} />
    <Typography variant="caption" color={tone === 'error' ? 'error' : 'text.secondary'} sx={{ overflowWrap: 'anywhere' }}>
      {children}
    </Typography>
  </Box>
);
