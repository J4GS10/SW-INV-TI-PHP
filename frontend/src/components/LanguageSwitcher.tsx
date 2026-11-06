import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  Button,
  ListItemIcon,
  ListItemText,
  Menu,
  MenuItem,
} from '@mui/material';
import CheckIcon from '@mui/icons-material/Check';
import TranslateIcon from '@mui/icons-material/Translate';

import { normalizeLanguage } from '../types/language';

const LANGUAGES = [
  { code: 'es', label: 'Español' },
  { code: 'en', label: 'English' },
  { code: 'it', label: 'Italiano' },
] as const;

export const LanguageSwitcher = () => {
  const { i18n } = useTranslation();
  const [anchorEl, setAnchorEl] = useState<null | HTMLElement>(null);
  const selectedLanguage = normalizeLanguage(i18n.resolvedLanguage);
  const currentLanguage = LANGUAGES.find((language) => language.code === selectedLanguage) ?? LANGUAGES[0];

  const handleClose = () => setAnchorEl(null);

  const handleChange = async (language: typeof LANGUAGES[number]['code']) => {
    await i18n.changeLanguage(language);
    handleClose();
  };

  return (
    <>
      <Button
        startIcon={<TranslateIcon />}
        onClick={(event) => setAnchorEl(event.currentTarget)}
        sx={{
          color: 'text.secondary',
          textTransform: 'none',
          '&:hover': { color: 'primary.main', bgcolor: 'action.hover' },
        }}
      >
        {currentLanguage.label}
      </Button>

      <Menu
        anchorEl={anchorEl}
        open={Boolean(anchorEl)}
        onClose={handleClose}
        MenuListProps={{ dense: true }}
      >
        {LANGUAGES.map((language) => (
          <MenuItem
            key={language.code}
            selected={currentLanguage.code === language.code}
            onClick={() => void handleChange(language.code)}
          >
            <ListItemText primary={language.label} />
            {currentLanguage.code === language.code && (
              <ListItemIcon sx={{ minWidth: 32, color: 'primary.main' }}>
                <CheckIcon fontSize="small" />
              </ListItemIcon>
            )}
          </MenuItem>
        ))}
      </Menu>
    </>
  );
};
