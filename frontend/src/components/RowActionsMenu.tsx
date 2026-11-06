import { useState, type ReactNode } from 'react';
import { Divider, IconButton, ListItemIcon, ListItemText, Menu, MenuItem, Tooltip } from '@mui/material';
import MoreVertIcon from '@mui/icons-material/MoreVert';
import { useTranslation } from 'react-i18next';

export interface RowAction {
  key: string;
  label: string;
  icon?: ReactNode;
  onClick: () => void;
  /** Acción destructiva: se muestra al final, separada y en rojo. */
  danger?: boolean;
}

/** Menú «⋯» de una fila con solo las acciones válidas para ese registro. */
export const RowActionsMenu = ({ actions, label }: { actions: RowAction[]; label?: string }) => {
  const { t } = useTranslation();
  const [anchor, setAnchor] = useState<HTMLElement | null>(null);
  if (actions.length === 0) return null;
  const normales = actions.filter((a) => !a.danger);
  const peligrosas = actions.filter((a) => a.danger);
  const elegir = (a: RowAction) => { setAnchor(null); a.onClick(); };
  return (
    <>
      <Tooltip title={label ?? t('common.more_actions')}>
        <IconButton size="small" aria-label={label ?? t('common.more_actions')} aria-haspopup="menu"
          onClick={(e) => { e.stopPropagation(); setAnchor(e.currentTarget); }}>
          <MoreVertIcon fontSize="small" />
        </IconButton>
      </Tooltip>
      <Menu anchorEl={anchor} open={!!anchor} onClose={() => setAnchor(null)} onClick={(e) => e.stopPropagation()}>
        {normales.map((a) => (
          <MenuItem key={a.key} onClick={() => elegir(a)}>
            {a.icon && <ListItemIcon>{a.icon}</ListItemIcon>}
            <ListItemText>{a.label}</ListItemText>
          </MenuItem>
        ))}
        {peligrosas.length > 0 && normales.length > 0 && <Divider />}
        {peligrosas.map((a) => (
          <MenuItem key={a.key} onClick={() => elegir(a)} sx={{ color: 'error.main' }}>
            {a.icon && <ListItemIcon sx={{ color: 'error.main' }}>{a.icon}</ListItemIcon>}
            <ListItemText>{a.label}</ListItemText>
          </MenuItem>
        ))}
      </Menu>
    </>
  );
};
