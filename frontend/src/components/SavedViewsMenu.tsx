import { useState } from 'react';
import { Button, DialogActions, DialogContent, DialogTitle, Divider, IconButton, ListItemIcon, ListItemText, ListSubheader, Menu, MenuItem, TextField } from '@mui/material';
import BookmarksIcon from '@mui/icons-material/Bookmarks';
import BookmarkAddIcon from '@mui/icons-material/BookmarkAdd';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutline';
import CheckIcon from '@mui/icons-material/Check';
import { useTranslation } from 'react-i18next';
import { Dialog } from './ui/Dialog';

export interface Vista {
  nombre: string;
  /** Parámetros de la URL que la definen (sin la página). */
  query: string;
}

interface Props {
  /** Clave de almacenamiento por página (cada página tiene sus vistas). */
  storageKey: string;
  query: string;
  presets?: Vista[];
  onApply: (query: string) => void;
}

const leer = (clave: string): Vista[] => {
  try { return JSON.parse(window.localStorage.getItem(clave) ?? '[]') as Vista[]; } catch { return []; }
};

/** Vistas guardadas: combinaciones de filtros que se usan a diario, a un clic. */
export const SavedViewsMenu = ({ storageKey, query, presets = [], onApply }: Props) => {
  const { t } = useTranslation();
  const clave = `vistas-${storageKey}`;
  const [anchor, setAnchor] = useState<HTMLElement | null>(null);
  const [vistas, setVistas] = useState<Vista[]>(() => leer(clave));
  const [nombrando, setNombrando] = useState(false);
  const [nombre, setNombre] = useState('');

  const persistir = (next: Vista[]) => {
    setVistas(next);
    try { window.localStorage.setItem(clave, JSON.stringify(next)); } catch { /* sin storage */ }
  };
  const aplicar = (q: string) => { setAnchor(null); onApply(q); };
  const guardar = () => {
    const n = nombre.trim();
    if (!n) return;
    persistir([...vistas.filter((v) => v.nombre !== n), { nombre: n, query }]);
    setNombrando(false);
    setNombre('');
  };

  return (
    <>
      <Button size="small" variant="outlined" color="inherit" startIcon={<BookmarksIcon />} onClick={(e) => setAnchor(e.currentTarget)}
        aria-haspopup="menu" sx={{ borderColor: 'divider' }}>
        {t('views.button')}
      </Button>
      <Menu anchorEl={anchor} open={!!anchor} onClose={() => setAnchor(null)} slotProps={{ paper: { sx: { minWidth: 260 } } }}>
        {presets.length > 0 && <ListSubheader sx={{ lineHeight: 2.4 }}>{t('views.presets')}</ListSubheader>}
        {presets.map((v) => (
          <MenuItem key={`p-${v.nombre}`} dense onClick={() => aplicar(v.query)} selected={v.query === query}>
            <ListItemText>{v.nombre}</ListItemText>
            {v.query === query && <CheckIcon fontSize="small" color="primary" />}
          </MenuItem>
        ))}
        {vistas.length > 0 && <ListSubheader sx={{ lineHeight: 2.4 }}>{t('views.saved')}</ListSubheader>}
        {vistas.map((v) => (
          <MenuItem key={`s-${v.nombre}`} dense onClick={() => aplicar(v.query)} selected={v.query === query}>
            <ListItemText>{v.nombre}</ListItemText>
            <IconButton size="small" edge="end" aria-label={t('views.delete', { name: v.nombre })}
              onClick={(e) => { e.stopPropagation(); persistir(vistas.filter((x) => x.nombre !== v.nombre)); }}>
              <DeleteOutlineIcon fontSize="small" />
            </IconButton>
          </MenuItem>
        ))}
        {(presets.length > 0 || vistas.length > 0) && <Divider />}
        <MenuItem dense onClick={() => aplicar('')} disabled={!query}>
          <ListItemText>{t('views.clear')}</ListItemText>
        </MenuItem>
        <MenuItem dense disabled={!query} onClick={() => { setAnchor(null); setNombrando(true); }}>
          <ListItemIcon><BookmarkAddIcon fontSize="small" /></ListItemIcon>
          <ListItemText>{t('views.save_current')}</ListItemText>
        </MenuItem>
      </Menu>
      <Dialog open={nombrando} onClose={() => setNombrando(false)} maxWidth="xs" fullWidth>
        <DialogTitle>{t('views.save_title')}</DialogTitle>
        <DialogContent>
          <TextField autoFocus fullWidth margin="dense" label={t('views.name')} value={nombre}
            onChange={(e) => setNombre(e.target.value)} inputProps={{ maxLength: 40 }}
            onKeyDown={(e) => { if (e.key === 'Enter') guardar(); }} />
        </DialogContent>
        <DialogActions>
          <Button color="inherit" onClick={() => setNombrando(false)}>{t('common.cancel')}</Button>
          <Button variant="contained" onClick={guardar} disabled={!nombre.trim()}>{t('common.save')}</Button>
        </DialogActions>
      </Dialog>
    </>
  );
};
