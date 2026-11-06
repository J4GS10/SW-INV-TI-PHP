import { useState } from 'react';
import {
  Button, Checkbox, Divider, ListItemIcon, ListItemText, ListSubheader, Menu, MenuItem, ToggleButton, ToggleButtonGroup,
} from '@mui/material';
import ViewColumnIcon from '@mui/icons-material/ViewColumn';
import { useTranslation } from 'react-i18next';
import type { Densidad } from '../hooks/useTableConfig';

interface Props {
  columnas: { key: string; label: string }[];
  ocultas: ReadonlySet<string>;
  densidad: Densidad;
  onAlternar: (col: string) => void;
  onDensidad: (d: Densidad) => void;
  onRestablecer: () => void;
}

/** Botón «Columnas»: columnas visibles y densidad de la tabla (se recuerdan por usuario). */
export const TableSettingsMenu = ({ columnas, ocultas, densidad, onAlternar, onDensidad, onRestablecer }: Props) => {
  const { t } = useTranslation();
  const [anchor, setAnchor] = useState<HTMLElement | null>(null);
  return (
    <>
      <Button variant="outlined" startIcon={<ViewColumnIcon />} onClick={(e) => setAnchor(e.currentTarget)}>
        {t('table_settings.button')}
      </Button>
      <Menu anchorEl={anchor} open={!!anchor} onClose={() => setAnchor(null)}>
        <ListSubheader>{t('table_settings.columns')}</ListSubheader>
        {columnas.map((c) => (
          <MenuItem key={c.key} dense onClick={() => onAlternar(c.key)}
            disabled={!ocultas.has(c.key) && columnas.length - ocultas.size <= 1}>
            <ListItemIcon><Checkbox edge="start" size="small" checked={!ocultas.has(c.key)} tabIndex={-1} disableRipple /></ListItemIcon>
            <ListItemText>{c.label}</ListItemText>
          </MenuItem>
        ))}
        <Divider />
        <ListSubheader>{t('table_settings.density')}</ListSubheader>
        <MenuItem disableRipple sx={{ '&:hover': { bgcolor: 'transparent' } }}>
          <ToggleButtonGroup size="small" exclusive value={densidad} onChange={(_e, v) => v && onDensidad(v)}>
            <ToggleButton value="compact">{t('table_settings.compact')}</ToggleButton>
            <ToggleButton value="comfortable">{t('table_settings.comfortable')}</ToggleButton>
          </ToggleButtonGroup>
        </MenuItem>
        <Divider />
        <MenuItem onClick={() => { onRestablecer(); setAnchor(null); }}>{t('table_settings.reset')}</MenuItem>
      </Menu>
    </>
  );
};
