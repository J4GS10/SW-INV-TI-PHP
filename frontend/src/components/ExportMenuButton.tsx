import { useState } from 'react';
import { Button, ButtonGroup, CircularProgress, ListItemIcon, ListItemText, ListSubheader, Menu, MenuItem } from '@mui/material';
import FileDownloadIcon from '@mui/icons-material/FileDownload';
import ArrowDropDownIcon from '@mui/icons-material/ArrowDropDown';
import CheckIcon from '@mui/icons-material/Check';
import { useTranslation } from 'react-i18next';
import { normalizeLanguage, type SupportedLanguage } from '../types/language';

const LANGUAGES: { code: SupportedLanguage; label: string }[] = [
  { code: 'es', label: 'Español' },
  { code: 'en', label: 'English' },
  { code: 'it', label: 'Italiano' },
];

interface ExportMenuButtonProps {
  /** Descarga el archivo en el idioma elegido. */
  onExport: (language: SupportedLanguage) => void | Promise<void>;
  label?: string;
  disabled?: boolean;
}

/**
 * Botón estándar de exportación: un clic exporta en el idioma de la interfaz;
 * la flecha ofrece los otros idiomas.
 */
export const ExportMenuButton = ({ onExport, label, disabled }: ExportMenuButtonProps) => {
  const { t, i18n } = useTranslation();
  const [anchor, setAnchor] = useState<HTMLElement | null>(null);
  const [busy, setBusy] = useState(false);
  const current = normalizeLanguage(i18n.resolvedLanguage);

  const run = async (language: SupportedLanguage) => {
    setAnchor(null);
    setBusy(true);
    try {
      await onExport(language);
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <ButtonGroup variant="outlined" disabled={disabled || busy}>
        <Button startIcon={busy ? <CircularProgress size={16} /> : <FileDownloadIcon />} onClick={() => void run(current)}>
          {label ?? t('common.export')}
        </Button>
        <Button size="small" sx={{ px: 0.5 }} aria-haspopup="menu" aria-label={t('common.export_language')} onClick={(e) => setAnchor(e.currentTarget)}>
          <ArrowDropDownIcon />
        </Button>
      </ButtonGroup>
      <Menu anchorEl={anchor} open={Boolean(anchor)} onClose={() => setAnchor(null)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
        transformOrigin={{ vertical: 'top', horizontal: 'right' }}>
        <ListSubheader sx={{ bgcolor: 'transparent', lineHeight: 2.5, typography: 'overline', fontSize: '0.68rem' }}>
          {t('common.export_language')}
        </ListSubheader>
        {LANGUAGES.map((lang) => (
          <MenuItem key={lang.code} dense onClick={() => void run(lang.code)}>
            <ListItemText>{lang.label}</ListItemText>
            {lang.code === current && (
              <ListItemIcon sx={{ minWidth: 0, ml: 2, color: 'primary.main' }}><CheckIcon fontSize="small" /></ListItemIcon>
            )}
          </MenuItem>
        ))}
      </Menu>
    </>
  );
};
