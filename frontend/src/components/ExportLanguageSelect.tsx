import { MenuItem, TextField } from '@mui/material';
import { useTranslation } from 'react-i18next';
import type { SupportedLanguage } from '../types/language';
interface Props {
    value: SupportedLanguage;
    onChange: (language: SupportedLanguage) => void;
    size?: 'small' | 'medium';
}
const OPTIONS: SupportedLanguage[] = ['es', 'en', 'it'];
export const ExportLanguageSelect = ({ value, onChange, size = 'small' }: Props) => {
    const { t } = useTranslation();
    return (<TextField select size={size} label={t('common.export_language')} value={value} onChange={(event) => onChange(event.target.value as SupportedLanguage)} sx={{ minWidth: 170 }}>
      {OPTIONS.map((language) => (<MenuItem key={language} value={language}>
          {t(`common.languages.${language}`, language.toUpperCase())}
        </MenuItem>))}
    </TextField>);
};
