import { TextField, InputAdornment } from '@mui/material';
import SearchIcon from '@mui/icons-material/Search';
import { useTranslation } from 'react-i18next';

interface SearchInputProps {
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
}

/** Búsqueda estándar de los listados (va en la barra de filtros). */
export const SearchInput = ({ value, onChange, placeholder }: SearchInputProps) => {
  const { t } = useTranslation();
  return (
    <TextField
      placeholder={placeholder ?? t('common.search')}
      size="small"
      fullWidth
      value={value}
      onChange={(e) => onChange(e.target.value)}
      inputProps={{ 'aria-label': placeholder ?? t('common.search') }}
      InputProps={{
        startAdornment: (
          <InputAdornment position="start">
            <SearchIcon fontSize="small" sx={{ color: 'text.secondary' }} />
          </InputAdornment>
        ),
      }}
    />
  );
};
