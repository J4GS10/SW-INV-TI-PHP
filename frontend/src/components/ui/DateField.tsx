import { useState } from 'react';
import type { TextFieldProps } from '@mui/material';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';
import dayjs, { type Dayjs } from 'dayjs';

const FORMAT = 'YYYY-MM-DD';
const toText = (date: Dayjs | null): string => (date?.isValid() ? date.format(FORMAT) : '');
const toDate = (text: string | null | undefined): Dayjs | null => {
  if (!text) return null;
  const date = dayjs(text);
  return date.isValid() ? date : null;
};

type DateFieldProps = Pick<TextFieldProps, 'label' | 'required' | 'error' | 'helperText' | 'fullWidth' | 'size' | 'sx' | 'disabled' | 'name' | 'onBlur'> & {
  /** YYYY-MM-DD, the format the API uses. */
  value: string | null | undefined;
  onChange?: (event: { target: { value: string } }) => void;
  inputProps?: { min?: string; max?: string };
  slotProps?: { htmlInput?: { min?: string; max?: string }; inputLabel?: unknown };
  InputLabelProps?: unknown;
};

/**
 * Date field with the application's language and format instead of the browser's.
 * Keeps TextField's `value` and `onChange(e)` so forms do not change.
 */
export const DateField = ({ value, onChange, label, required, error, helperText, fullWidth, size, sx, disabled, name, onBlur, inputProps, slotProps }: DateFieldProps) => {
  const [inner, setInner] = useState<Dayjs | null>(toDate(value));
  const [seen, setSeen] = useState(value ?? '');
  const limits = inputProps ?? slotProps?.htmlInput;

  // While typing, the date is incomplete and the parent holds an empty value: that must not erase what was typed.
  if ((value ?? "") !== seen) {
    setSeen(value ?? "");
    if ((value ?? "") !== toText(inner) && (value || !inner)) setInner(toDate(value));
  }

  return (
    <DatePicker
      label={label}
      value={inner}
      disabled={disabled}
      minDate={toDate(limits?.min) ?? undefined}
      maxDate={toDate(limits?.max) ?? undefined}
      onChange={(date) => {
        setInner(date);
        onChange?.({ target: { value: toText(date) } });
      }}
      slotProps={{
        field: { clearable: !required },
        textField: { name, onBlur: onBlur as never, required, error, helperText, fullWidth, size, sx: [size === 'small' && !fullWidth ? { width: { xs: '100%', sm: 180 } } : {}, ...(Array.isArray(sx) ? sx : [sx])] },
      }}
    />
  );
};
