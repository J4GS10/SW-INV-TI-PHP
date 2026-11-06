import { MenuItem, TextField, type TextFieldProps } from '@mui/material';
import { useTranslation } from 'react-i18next';

import type { SedeAlcance } from '../../api/scopeService';

interface Props extends Omit<TextFieldProps, 'value' | 'onChange' | 'select' | 'children'> {
  value: number | '';
  onChange: (value: number | '') => void;
  sedes: SedeAlcance[];
  loading?: boolean;
}

/**
 * Selector de sede (alcance de datos). Solo lista las sedes del alcance del
 * usuario; con una sola sede el formulario la prellena como sugerencia.
 */
export const SedeField = ({ value, onChange, sedes, loading, helperText, ...rest }: Props) => {
  const { t } = useTranslation();
  const known = value === '' || sedes.some((s) => s.SED_Sede === value);
  return (
    <TextField
      {...rest}
      select
      fullWidth
      value={known ? value : ''}
      onChange={(event) => onChange(event.target.value === '' ? '' : Number(event.target.value))}
      label={rest.label ?? t('scope.sede')}
      helperText={helperText ?? (loading ? t('common.loading') : undefined)}
    >
      {sedes.map((sede) => (
        <MenuItem key={sede.SED_Sede} value={sede.SED_Sede}>
          {sede.SED_Nombre}{sede.ubicacion ? ` · ${sede.ubicacion}` : ''}
        </MenuItem>
      ))}
    </TextField>
  );
};
