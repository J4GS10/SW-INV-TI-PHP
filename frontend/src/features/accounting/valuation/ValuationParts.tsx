import { Alert, Box, Chip, Stack, Typography } from '@mui/material';
import type { ChipProps } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { formatMoney } from '../../../utils/currency';
import { DateField } from '../../../components/ui/DateField';
import type { CurrencyTotal, Pending, ValuationStatus } from '../accountingService';

const STATUS_COLOR: Record<ValuationStatus, ChipProps['color']> = {
  DEPRECIATING: 'primary',
  FULLY_DEPRECIATED: 'success',
  NOT_DEPRECIABLE: 'default',
  NO_CATEGORY: 'warning',
  NO_COST: 'warning',
  DISPOSED: 'error',
};

export const ValuationStatusChip = ({ status }: { status: ValuationStatus }) => {
  const { t } = useTranslation();
  return <Chip size="small" variant="outlined" color={STATUS_COLOR[status]} label={t(`accounting.status.${status}`)}/>;
};

/** Costo, depreciación acumulada y valor en libros, una tarjeta por moneda. */
export const CurrencyTotalsSummary = ({ totals, title }: { totals: CurrencyTotal[]; title?: string }) => {
  const { t, i18n } = useTranslation();
  if (totals.length === 0) {
    return <Typography variant="body2" color="text.secondary">{t('accounting.no_values')}</Typography>;
  }
  return (
    <Stack spacing={1}>
      {title && <Typography variant="subtitle2" fontWeight={700}>{title}</Typography>}
      {/* Mosaicos de ancho fijo: una sola moneda no se estira de lado a lado. */}
      <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', sm: 'repeat(auto-fill, minmax(300px, 1fr))' }, maxWidth: { sm: totals.length === 1 ? 420 : 'none' } }}>
        {totals.map((total) => (
          <Box key={total.currency} sx={{ p: 1.5, border: 1, borderColor: 'divider', borderRadius: 2 }}>
            <Stack direction="row" justifyContent="space-between" alignItems="baseline">
              <Typography variant="overline" color="text.secondary">{total.currency}</Typography>
              <Typography variant="caption" color="text.secondary">{t('accounting.items', { count: total.count })}</Typography>
            </Stack>
            <Typography variant="h6" fontWeight={700}>{formatMoney(total.book_value, total.currency, i18n.language)}</Typography>
            <Typography variant="caption" color="text.secondary" component="div">{t('accounting.book_value')}</Typography>
            <Stack direction="row" spacing={2} sx={{ mt: 1 }} flexWrap="wrap" useFlexGap>
              <Typography variant="body2">{t('accounting.cost')}: {formatMoney(total.acquisition_cost, total.currency, i18n.language)}</Typography>
              <Typography variant="body2">{t('accounting.accumulated')}: {formatMoney(total.accumulated_depreciation, total.currency, i18n.language)}</Typography>
            </Stack>
          </Box>
        ))}
      </Box>
    </Stack>
  );
};

/** Lo que falta para que los números estén completos: equipos sin costo o cuyo tipo no tiene categoría. */
export const PendingNotice = ({ pending }: { pending: Pending }) => {
  const { t } = useTranslation();
  if (pending.without_cost === 0 && pending.without_category === 0) return null;
  return (
    <Alert severity="warning" variant="outlined">
      {pending.without_cost > 0 && <Box>{t('accounting.pending_cost', { count: pending.without_cost })}</Box>}
      {pending.without_category > 0 && <Box>{t('accounting.pending_category', { count: pending.without_category })}</Box>}
    </Alert>
  );
};

/** Fecha de la valoración: por defecto hoy; puede mirarse al cierre de cualquier mes. */
export const AsOfField = ({ value, onChange }: { value: string; onChange: (value: string) => void }) => {
  const { t } = useTranslation();
  return (
    <DateField size="small" label={t('accounting.as_of')} value={value} onChange={(e) => onChange(e.target.value)}
      helperText={t('accounting.as_of_help')} slotProps={{ inputLabel: { shrink: true } }} sx={{ width: 240 }}/>
  );
};
