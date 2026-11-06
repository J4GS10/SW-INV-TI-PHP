import { InputAdornment, MenuItem, TextField } from '@mui/material';
import type { TextFieldProps } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { CURRENCIES, currencySymbol, DEFAULT_CURRENCY } from '../../utils/currency';

type Props = Omit<TextFieldProps, 'value' | 'onChange' | 'type'> & {
  value: string | number;
  onChange: (value: string) => void;
  currency: string;
  /** Sin él, la moneda se muestra pero no se puede cambiar (p. ej. la de una orden). */
  onCurrencyChange?: (currency: string) => void;
};

/**
 * Monto con su moneda en un solo campo: "Q | 1500.00 | GTQ ▾".
 * La moneda es parte del dato: un costo en dólares se guarda en USD.
 */
export const MoneyField = ({ value, onChange, currency, onCurrencyChange, InputProps, ...rest }: Props) => {
  const { t, i18n } = useTranslation();
  const code = currency || DEFAULT_CURRENCY;
  return (
    <TextField
      {...rest}
      type="number"
      value={value}
      onChange={(e) => onChange(e.target.value)}
      inputProps={{ min: 0, step: '0.01', ...rest.inputProps }}
      InputProps={{
        ...InputProps,
        startAdornment: <InputAdornment position="start">{currencySymbol(code, i18n.language)}</InputAdornment>,
        endAdornment: (
          <InputAdornment position="end">
            {onCurrencyChange ? (
              <TextField
                select
                variant="standard"
                value={code}
                onChange={(e) => onCurrencyChange(e.target.value)}
                disabled={rest.disabled}
                inputProps={{ 'aria-label': t('procurement.currency') }}
                InputProps={{ disableUnderline: true }}
                sx={{ minWidth: 64, '& .MuiSelect-select': { py: 0, fontSize: '0.875rem' } }}
              >
                {CURRENCIES.map((c) => <MenuItem key={c} value={c}>{c}</MenuItem>)}
              </TextField>
            ) : code}
          </InputAdornment>
        ),
      }}
    />
  );
};
