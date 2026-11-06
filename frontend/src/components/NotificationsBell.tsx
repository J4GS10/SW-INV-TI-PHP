import { useEffect, useState } from 'react';
import { Badge, Box, IconButton, ListItemText, Menu, MenuItem, Tooltip, Typography } from '@mui/material';
import NotificationsIcon from '@mui/icons-material/NotificationsNone';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { inventarioService } from '../api/inventarioService';
import { useAuth } from '../context/auth-context';
import { DESTINO, pendientesDeRol } from '../features/dashboard/atencion';
import type { ItemAtencion } from '../types/kardex';

const CINCO_MINUTOS = 5 * 60_000;

/**
 * Campana de avisos dentro del sistema (mientras el correo siga pausado):
 * vencimientos, préstamos vencidos, aprobaciones, cuentas por cambiar… según
 * el rol. Se actualiza cada 5 minutos.
 */
export const NotificationsBell = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { user } = useAuth();
  const [items, setItems] = useState<ItemAtencion[]>([]);
  const [anchor, setAnchor] = useState<HTMLElement | null>(null);

  useEffect(() => {
    let vivo = true;
    const cargar = () => inventarioService.atencion()
      .then((r) => { if (vivo) setItems(pendientesDeRol(r, user?.role)); })
      .catch(() => undefined);
    void cargar();
    const id = window.setInterval(cargar, CINCO_MINUTOS);
    return () => { vivo = false; window.clearInterval(id); };
  }, [user?.role]);

  const urgentes = items.filter((i) => i.severidad !== 'baja').reduce((a, i) => a + i.total, 0);
  const etiqueta = t('notifications.title', { count: urgentes });

  return (
    <>
      <Tooltip title={etiqueta}>
        {/* La insignia muestra «99+» pasado el máximo: el nombre accesible empieza con ese mismo texto (WCAG 2.5.3). */}
        <IconButton size="small" aria-label={urgentes > 99 ? `99+ · ${etiqueta}` : etiqueta} onClick={(e) => setAnchor(e.currentTarget)}>
          <Badge badgeContent={urgentes} color="warning" max={99}>
            <NotificationsIcon fontSize="small" />
          </Badge>
        </IconButton>
      </Tooltip>
      <Menu anchorEl={anchor} open={!!anchor} onClose={() => setAnchor(null)} slotProps={{ paper: { sx: { maxWidth: 360 } } }}>
        <Box sx={{ px: 2, py: 1 }}>
          <Typography variant="subtitle2">{t('attention.my_title')}</Typography>
        </Box>
        {items.length === 0 && (
          <MenuItem disabled><ListItemText primary={t('attention.all_clear')} /></MenuItem>
        )}
        {items.map((i) => (
          <MenuItem key={i.clave} onClick={() => { setAnchor(null); navigate(DESTINO[i.clave]?.ruta ?? '/'); }}>
            <ListItemText primary={t(`attention.items.${i.clave}.title`, { count: i.total })}
              secondary={t(`attention.severity.${i.severidad}`)} primaryTypographyProps={{ sx: { whiteSpace: 'normal' } }} />
          </MenuItem>
        ))}
      </Menu>
    </>
  );
};
