import React, { useState } from 'react';
import {
  Avatar, Box, ButtonBase, Collapse, Divider, Drawer, IconButton, List, ListItem, ListItemButton,
  ListItemIcon, ListItemText, ListSubheader, Menu, MenuItem, Tooltip, Typography, alpha, useTheme,
} from '@mui/material';
import SearchIcon from '@mui/icons-material/Search';
import StarIcon from '@mui/icons-material/Star';
import StarBorderIcon from '@mui/icons-material/StarBorder';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import KeyboardIcon from '@mui/icons-material/Keyboard';
import { useLocation, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import CheckIcon from '@mui/icons-material/Check';
import ChevronLeftIcon from '@mui/icons-material/ChevronLeft';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import DevicesOtherIcon from '@mui/icons-material/DevicesOther';
import LogoutIcon from '@mui/icons-material/Logout';
import MoreVertIcon from '@mui/icons-material/MoreVert';
import SecurityIcon from '@mui/icons-material/Security';
import LightModeIcon from '@mui/icons-material/LightModeOutlined';
import DarkModeIcon from '@mui/icons-material/DarkModeOutlined';
import ContrastIcon from '@mui/icons-material/Contrast';
import HelpOutlineIcon from '@mui/icons-material/HelpOutline';
import { useAuth } from '../context/auth-context';
import { usePreferencias } from '../context/preferencias-context';
import type { TemaUsuario } from '../theme/theme';
import { NotificationsBell } from './NotificationsBell';
import { APP_ROLES } from '../utils/roles';
import { useConfig } from '../context/config-context';
import { NAVIGATION_ITEMS, NavItem, navItemFor } from '../router/navigation';
import { useWorkspace } from '../context/workspace-context';
import { normalizeLanguage } from '../types/language';
import { ChangePasswordDialog } from '../features/auth/ChangePasswordDialog';
import { AccountSecurityDialog } from '../features/auth/AccountSecurityDialog';

export const DRAWER_WIDTH = 256;

const TEMAS: { valor: TemaUsuario; Icono: typeof LightModeIcon }[] = [
  { valor: 'claro', Icono: LightModeIcon },
  { valor: 'oscuro', Icono: DarkModeIcon },
  { valor: 'sistema', Icono: ContrastIcon },
];
export const DRAWER_COLLAPSED_WIDTH = 72;

type GroupKey = NavItem['group'];
const GROUP_ORDER: GroupKey[] = ['DAILY', 'CONTROL', 'PROCUREMENT', 'ORGANIZATION', 'SETTINGS'];
/** Grupos que se pliegan (se abren solos si la página actual está dentro). */
const PLEGABLES: GroupKey[] = ['CONTROL', 'PROCUREMENT', 'ORGANIZATION', 'SETTINGS'];
const CLAVE_PLEGADOS = 'sidebar-grupos-abiertos';
const LANGUAGES = [
  { code: 'es', label: 'Español' },
  { code: 'en', label: 'English' },
  { code: 'it', label: 'Italiano' },
] as const;

interface SidebarProps {
  /** Escritorio: barra fija expandida o en modo rail (solo iconos). */
  variant: 'permanent' | 'temporary';
  collapsed?: boolean;
  onToggleCollapse?: () => void;
  /** Móvil: drawer temporal. */
  open?: boolean;
  onClose?: () => void;
}

export const Sidebar = ({ variant, collapsed = false, onToggleCollapse, open = false, onClose }: SidebarProps) => {
  const theme = useTheme();
  const { user, logout, logoutAll } = useAuth();
  const { preferencias, cambiarTema, desactivarGuias } = usePreferencias();
  const { config } = useConfig();
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const location = useLocation();
  const [pwdOpen, setPwdOpen] = useState(false);
  const [securityOpen, setSecurityOpen] = useState(false);
  const [menuAnchor, setMenuAnchor] = useState<HTMLElement | null>(null);
  const { abrirPaleta, abrirAtajos, favoritos, alternarFavorito } = useWorkspace();
  const [abiertos, setAbiertos] = useState<string[]>(() => {
    try { return JSON.parse(window.localStorage.getItem(CLAVE_PLEGADOS) ?? '[]') as string[]; } catch { return []; }
  });
  const alternarGrupo = (g: string) => setAbiertos((prev) => {
    const next = prev.includes(g) ? prev.filter((x) => x !== g) : [...prev, g];
    try { window.localStorage.setItem(CLAVE_PLEGADOS, JSON.stringify(next)); } catch { /* sin storage */ }
    return next;
  });
  const actual = navItemFor(location.pathname);

  const rail = variant === 'permanent' && collapsed;
  // La campana muestra los pendientes que el servidor asigna al rol (Consulta no
  // atiende pendientes y el administrador de seguridad no ve el inventario).
  const esInventario = !!user?.role && APP_ROLES.includes(user.role) && user.role !== 'CONSULTA';
  const width = rail ? DRAWER_COLLAPSED_WIDTH : DRAWER_WIDTH;
  const menuItems: NavItem[] = user
    ? NAVIGATION_ITEMS.filter((item) => !item.hiddenInMenu && item.allowedRoles.includes(user.role))
    : [];
  const currentLanguage = normalizeLanguage(i18n.resolvedLanguage);
  const initial = user?.sub ? user.sub.charAt(0).toUpperCase() : '?';

  const go = (path: string) => {
    navigate(path);
    onClose?.();
  };
  const closeMenu = () => setMenuAnchor(null);

  const renderItem = (item: NavItem, keyPrefix = '') => {
    const isActive = actual?.path === item.path;
    const label = t(item.title, item.title);
    const esFavorito = favoritos.includes(item.path);
    const button = (
      <ListItemButton
        onClick={() => go(item.path)}
        selected={isActive}
        aria-label={rail ? label : undefined}
        sx={{
          minHeight: 40,
          borderRadius: 1.5,
          px: rail ? 0 : 1.5,
          justifyContent: rail ? 'center' : 'flex-start',
          color: 'text.secondary',
          '& .MuiListItemIcon-root': { color: 'inherit' },
          '&:hover': { color: 'text.primary' },
          '&.Mui-selected': {
            color: 'primary.main',
            bgcolor: alpha(theme.palette.primary.main, 0.12),
            '&:hover': { bgcolor: alpha(theme.palette.primary.main, 0.18) },
          },
        }}
      >
        <ListItemIcon sx={{ minWidth: rail ? 0 : 36, justifyContent: 'center' }}>
          <item.icon fontSize="small" />
        </ListItemIcon>
        {!rail && (
          <ListItemText
            primary={label}
            primaryTypographyProps={{ variant: 'body2', fontWeight: isActive ? 700 : 500, sx: { lineHeight: 1.25, overflowWrap: 'anywhere' } }}
          />
        )}
      </ListItemButton>
    );
    return (
      <ListItem
        key={keyPrefix + item.path}
        disablePadding
        sx={{ mb: 0.25, '&:hover .fav-toggle, & .fav-toggle.Mui-focusVisible': { opacity: 1 } }}
        secondaryAction={rail ? undefined : (
          <IconButton
            className="fav-toggle"
            tabIndex={-1}
            size="small"
            edge="end"
            onClick={() => alternarFavorito(item.path)}
            aria-label={t(esFavorito ? 'workspace.unpin' : 'workspace.pin', { page: label })}
            sx={{ opacity: esFavorito ? 0.9 : 0, mr: 0.25, color: esFavorito ? 'warning.main' : 'text.secondary' }}
          >
            {esFavorito ? <StarIcon sx={{ fontSize: 16 }} /> : <StarBorderIcon sx={{ fontSize: 16 }} />}
          </IconButton>
        )}
      >
        {rail ? <Tooltip title={label} placement="right">{button}</Tooltip> : button}
      </ListItem>
    );
  };

  const content = (
    <Box sx={{ display: 'flex', flexDirection: 'column', height: '100%', overflow: 'hidden' }}>
      {/* Marca */}
      <Box sx={{
        display: 'flex', alignItems: 'center', gap: 1, minHeight: 64,
        px: rail ? 0 : 2, justifyContent: rail ? 'center' : 'space-between',
      }}>
        {!rail && (
          <Box sx={{ minWidth: 0 }}>
            {config.SYS_Logo_URL ? (
              <Box component="img" src={config.SYS_Logo_URL} alt={config.SYS_Nombre_Empresa}
                sx={{ display: 'block', height: 48, maxWidth: 190, objectFit: 'contain' }}
                onError={(e: React.SyntheticEvent<HTMLImageElement>) => { e.currentTarget.style.display = 'none'; }} />
            ) : (
              <Typography variant="subtitle1" sx={{ fontWeight: 800, color: 'primary.main', lineHeight: 1.2 }} noWrap>
                {config.SYS_Nombre_Empresa}
              </Typography>
            )}
            <Typography variant="caption" color="text.secondary" noWrap sx={{ display: 'block' }}>
              {t('app.title')}
            </Typography>
          </Box>
        )}
        {variant === 'permanent' && onToggleCollapse && (
          <Tooltip title={t(rail ? 'common.show_navigation' : 'common.hide_navigation')} placement="right">
            <IconButton size="small" onClick={onToggleCollapse}
              aria-label={t(rail ? 'common.show_navigation' : 'common.hide_navigation')}>
              {rail ? <ChevronRightIcon fontSize="small" /> : <ChevronLeftIcon fontSize="small" />}
            </IconButton>
          </Tooltip>
        )}
      </Box>
      <Divider />

      {/* Buscar o ejecutar (Ctrl+K) y avisos */}
      <Box sx={{ px: rail ? 1 : 1.5, pt: 1.25, display: 'flex', flexDirection: rail ? 'column' : 'row', alignItems: 'center', gap: 0.5 }}>
        {rail ? (
          <Tooltip title={`${t('workspace.search')} (Ctrl+K)`} placement="right">
            <IconButton onClick={abrirPaleta} aria-label={t('workspace.search')} sx={{ width: '100%', borderRadius: 1.5 }}>
              <SearchIcon fontSize="small" />
            </IconButton>
          </Tooltip>
        ) : (
          // Sin aria-label: el nombre accesible es el texto visible («Buscar o ejecutar… Ctrl K», WCAG 2.5.3).
          <ButtonBase onClick={abrirPaleta} sx={{
            flex: 1, minWidth: 0, justifyContent: 'flex-start', gap: 1, px: 1.25, py: 0.9, borderRadius: 1.5,
            border: 1, borderColor: 'divider', color: 'text.secondary', bgcolor: 'action.hover',
            '&:hover': { borderColor: 'primary.main', color: 'text.primary' },
          }}>
            <SearchIcon fontSize="small" />
            <Typography variant="body2" sx={{ flex: 1, textAlign: 'left', color: 'text.primary' }} noWrap>{t('workspace.search')}</Typography>
            <Box component="kbd" sx={{ fontSize: '0.68rem', fontFamily: 'inherit', fontWeight: 700, px: 0.6, borderRadius: 0.75, border: 1, borderColor: 'divider' }}>Ctrl K</Box>
          </ButtonBase>
        )}
        {/* El buscador cede ancho (minWidth: 0) para que el contador de la campana no se corte. */}
        {esInventario && <Box sx={{ flexShrink: 0, pr: 0.5 }}><NotificationsBell /></Box>}
      </Box>

      {/* Navegación */}
      {/* <nav> envuelve una lista real (<ul>): los <li> del menú necesitan su lista (WCAG 1.3.1). */}
      <Box component="nav" sx={{ flex: 1, overflowY: 'auto', overflowX: 'hidden', px: rail ? 1 : 1.5, py: 1 }}>
      <List dense disablePadding>
        {!rail && menuItems.some((m) => favoritos.includes(m.path)) && (
          <>
            <ListSubheader disableSticky sx={{
              bgcolor: 'transparent', lineHeight: 1, px: 1.5, pt: 0.5, pb: 1,
              typography: 'overline', fontSize: '0.68rem', color: 'text.secondary',
            }}>
              {t('workspace.favorites')}
            </ListSubheader>
            {menuItems.filter((m) => favoritos.includes(m.path)).map((m) => renderItem(m, 'fav-'))}
          </>
        )}
        {GROUP_ORDER.map((g, index) => {
          const items = menuItems.filter((m) => m.group === g);
          if (items.length === 0) return null;
          const plegable = PLEGABLES.includes(g) && !rail && items.length > 1;
          const abierto = !plegable || abiertos.includes(g) || items.some((m) => m.path === actual?.path);
          const subheaderSx = {
            bgcolor: 'transparent', lineHeight: 1, px: 1.5, pt: index === 0 ? 0.5 : 2, pb: 1,
            typography: 'overline', fontSize: '0.68rem', color: 'text.secondary',
          } as const;
          return (
            <React.Fragment key={g}>
              {rail
                ? index > 0 && <Divider sx={{ my: 1 }} />
                : items.length === 1 ? null : plegable ? (
                  <ListSubheader disableSticky sx={{ ...subheaderSx, px: 0, pt: 1.5, pb: 0.5 }}>
                    <ButtonBase onClick={() => alternarGrupo(g)} aria-expanded={abierto}
                      sx={{ width: '100%', justifyContent: 'space-between', px: 1.5, py: 0.5, borderRadius: 1, typography: 'overline', fontSize: '0.68rem' }}>
                      {t(`menu_groups.${g}`)}
                      <ExpandMoreIcon sx={{ fontSize: 16, transition: 'transform 150ms', transform: abierto ? 'rotate(180deg)' : 'none' }} />
                    </ButtonBase>
                  </ListSubheader>
                ) : (
                  <ListSubheader disableSticky sx={subheaderSx}>
                    {t(`menu_groups.${g}`)}
                  </ListSubheader>
                )}
              {/* Grupo = <li> con su propia lista: sin <div> entre <ul> y <li> (WCAG 1.3.1). */}
              <Collapse in={abierto || rail} timeout="auto" unmountOnExit component="li">
                <List dense disablePadding>{items.map((m) => renderItem(m))}</List>
              </Collapse>
            </React.Fragment>
          );
        })}
      </List>
      </Box>

      {/* Cuenta */}
      <Divider />
      <Box sx={{ p: 1 }}>
        <Tooltip title={rail ? (user?.sub ?? '') : ''} placement="right">
          <ButtonBase
            onClick={(e) => setMenuAnchor(e.currentTarget)}
            aria-haspopup="menu"
            // Con el menú plegado no hay texto visible; abierto, el nombre es el usuario y su rol (WCAG 2.5.3).
            aria-label={rail ? t('common.account_menu') : undefined}
            sx={{
              width: '100%', borderRadius: 1.5, p: 1, gap: 1.25,
              justifyContent: rail ? 'center' : 'flex-start',
              '&:hover': { bgcolor: 'action.hover' },
            }}
          >
            <Avatar sx={{
              width: 32, height: 32, fontSize: '0.875rem', fontWeight: 700,
              bgcolor: alpha(theme.palette.primary.main, 0.15), color: 'primary.main',
            }}>
              {initial}
            </Avatar>
            {!rail && (
              <>
                <Box sx={{ minWidth: 0, flex: 1, textAlign: 'left' }}>
                  <Typography variant="body2" fontWeight={700} noWrap>{user?.sub}</Typography>
                  <Typography variant="caption" color="text.secondary" noWrap sx={{ display: 'block' }}>
                    {user?.role ? t(`users.roles.${user.role}`, user.role) : ''}
                  </Typography>
                </Box>
                <MoreVertIcon fontSize="small" sx={{ color: 'text.secondary' }} />
              </>
            )}
          </ButtonBase>
        </Tooltip>
      </Box>

      <Menu
        anchorEl={menuAnchor}
        open={Boolean(menuAnchor)}
        onClose={closeMenu}
        anchorOrigin={{ vertical: 'top', horizontal: rail ? 'right' : 'left' }}
        transformOrigin={{ vertical: 'bottom', horizontal: 'left' }}
        slotProps={{ paper: { sx: { minWidth: 240 } } }}
      >
        <ListSubheader sx={{ bgcolor: 'transparent', lineHeight: 2.5, typography: 'overline', fontSize: '0.68rem' }}>
          {t('common.language')}
        </ListSubheader>
        {LANGUAGES.map((lang) => (
          <MenuItem key={lang.code} dense selected={currentLanguage === lang.code}
            onClick={() => { void i18n.changeLanguage(lang.code); closeMenu(); }}>
            <ListItemText>{lang.label}</ListItemText>
            {currentLanguage === lang.code && <CheckIcon fontSize="small" color="primary" />}
          </MenuItem>
        ))}
        <Divider />
        <ListSubheader sx={{ bgcolor: 'transparent', lineHeight: 2.5, typography: 'overline', fontSize: '0.68rem' }}>
          {t('appearance.title')}
        </ListSubheader>
        {TEMAS.map(({ valor, Icono }) => (
          <MenuItem key={valor} dense selected={preferencias.tema === valor}
            onClick={() => { cambiarTema(valor); closeMenu(); }}>
            <ListItemIcon><Icono fontSize="small" /></ListItemIcon>
            <ListItemText>{t(`appearance.${valor}`)}</ListItemText>
            {preferencias.tema === valor && <CheckIcon fontSize="small" color="primary" />}
          </MenuItem>
        ))}
        <MenuItem dense onClick={() => { desactivarGuias(!preferencias.guias_desactivadas); closeMenu(); }}>
          <ListItemIcon><HelpOutlineIcon fontSize="small" /></ListItemIcon>
          <ListItemText>
            {preferencias.guias_desactivadas ? t('appearance.guides_on') : t('appearance.guides_off')}
          </ListItemText>
        </MenuItem>
        <Divider />
        <MenuItem dense onClick={() => { abrirAtajos(); closeMenu(); }}>
          <ListItemIcon><KeyboardIcon fontSize="small" /></ListItemIcon>
          <ListItemText>{t('shortcuts.title')}</ListItemText>
        </MenuItem>
        <MenuItem dense onClick={() => { setSecurityOpen(true); closeMenu(); }}>
          <ListItemIcon><SecurityIcon fontSize="small" /></ListItemIcon>
          <ListItemText>{t('security.account.title')}</ListItemText>
        </MenuItem>
        <Divider />
        <MenuItem dense onClick={() => { closeMenu(); logout(); navigate('/login'); }}>
          <ListItemIcon><LogoutIcon fontSize="small" /></ListItemIcon>
          <ListItemText>{t('auth.logout')}</ListItemText>
        </MenuItem>
        <MenuItem dense onClick={() => {
          closeMenu();
          logoutAll().catch(() => undefined).finally(() => navigate('/login'));
        }}>
          <ListItemIcon><DevicesOtherIcon fontSize="small" /></ListItemIcon>
          <ListItemText>{t('auth.logout_all')}</ListItemText>
        </MenuItem>
      </Menu>

      <ChangePasswordDialog open={pwdOpen} onClose={() => setPwdOpen(false)} />
      <AccountSecurityDialog
        open={securityOpen}
        onClose={() => setSecurityOpen(false)}
        onChangePassword={() => setPwdOpen(true)}
      />
    </Box>
  );

  return (
    <Drawer
      variant={variant}
      open={variant === 'temporary' ? open : true}
      onClose={onClose}
      ModalProps={{ keepMounted: true }}
      sx={{
        width: variant === 'permanent' ? width : undefined,
        flexShrink: 0,
        '& .MuiDrawer-paper': {
          width,
          boxSizing: 'border-box',
          bgcolor: 'background.paper',
          borderRight: '1px solid',
          borderColor: 'divider',
          overflowX: 'hidden',
          transition: theme.transitions.create('width', { duration: theme.transitions.duration.shorter }),
        },
      }}
    >
      {content}
    </Drawer>
  );
};
