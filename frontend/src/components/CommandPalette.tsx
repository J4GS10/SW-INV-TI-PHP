import { useEffect, useMemo, useRef, useState } from 'react';
import {
  Box, Chip, CircularProgress, Dialog, InputBase, List, ListItemButton, ListItemIcon, ListItemText,
  ListSubheader, Typography, alpha, useTheme,
} from '@mui/material';
import SearchIcon from '@mui/icons-material/Search';
import ComputerIcon from '@mui/icons-material/Computer';
import BadgeIcon from '@mui/icons-material/Badge';
import ReceiptLongIcon from '@mui/icons-material/ReceiptLong';
import HistoryIcon from '@mui/icons-material/History';
import AssignmentIndIcon from '@mui/icons-material/AssignmentInd';
import AddBoxIcon from '@mui/icons-material/AddBox';
import PersonAddIcon from '@mui/icons-material/PersonAdd';
import QrCodeScannerIcon from '@mui/icons-material/QrCodeScanner';
import UploadFileIcon from '@mui/icons-material/UploadFile';
import SwapHorizIcon from '@mui/icons-material/SwapHoriz';
import BuildIcon from '@mui/icons-material/Build';
import Inventory2Icon from '@mui/icons-material/Inventory2';
import KeyboardIcon from '@mui/icons-material/Keyboard';
import type { SvgIconComponent } from '@mui/icons-material';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { productividadService, type ResultadoBusqueda } from '../api/productividadService';
import { useAuth } from '../context/auth-context';
import type { Reciente } from '../context/workspace-context';
import { NAVIGATION_ITEMS } from '../router/navigation';
import { usePermissions } from '../hooks/usePermissions';
import { stateLabel } from '../utils/catalogLabels';

interface Item {
  clave: string;
  grupo: string;
  titulo: string;
  subtitulo?: string;
  icono: SvgIconComponent;
  etiqueta?: string;
  ejecutar: () => void;
}

interface Props {
  open: boolean;
  onClose: () => void;
  recientes: Reciente[];
  favoritos: string[];
  onEntrega: () => void;
  onAtajos: () => void;
  onAbrirRegistro: (r: Reciente) => void;
}

const ICONO_TIPO: Record<Reciente['tipo'], SvgIconComponent> = {
  activo: ComputerIcon, persona: BadgeIcon, orden: ReceiptLongIcon,
};

const normal = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/** Ctrl+K: buscar cualquier cosa, ejecutar acciones y navegar sin usar el ratón. */
export const CommandPalette = (props: Props) => {
  const { t } = useTranslation();
  return (
    <Dialog open={props.open} onClose={props.onClose} fullWidth maxWidth="sm"
      PaperProps={{ sx: { position: 'fixed', top: { xs: 16, sm: '12vh' }, m: { xs: 1, sm: 2 }, borderRadius: 3, overflow: 'hidden' } }}
      aria-label={t('palette.title')}>
      {props.open && <PaletteBody {...props} />}
    </Dialog>
  );
};

const PaletteBody = ({ onClose, recientes, favoritos, onEntrega, onAtajos, onAbrirRegistro }: Props) => {
  const { t } = useTranslation();
  const theme = useTheme();
  const navigate = useNavigate();
  const { user } = useAuth();
  const { canOperate, canAdminInventory } = usePermissions();
  const [q, setQ] = useState('');
  const [remotos, setRemotos] = useState<ResultadoBusqueda[]>([]);
  const [buscando, setBuscando] = useState(false);
  const [activo, setActivo] = useState(0);
  const listaRef = useRef<HTMLUListElement | null>(null);

  const texto = q.trim();
  // Administran el sistema: la búsqueda es de datos de negocio.
  const puedeBuscarDatos = !!user && !['SUPER_ADMIN', 'ADMIN_SEGURIDAD'].includes(user.role);
  const remotosVisibles = useMemo(() => (texto.length >= 2 && puedeBuscarDatos ? remotos : []), [texto, remotos, puedeBuscarDatos]);
  useEffect(() => {
    if (texto.length < 2 || !puedeBuscarDatos) return undefined;
    const ctrl = new AbortController();
    const timer = window.setTimeout(() => {
      setBuscando(true);
      productividadService.buscar(texto, ctrl.signal)
        .then((r) => { if (!ctrl.signal.aborted) { setRemotos(r); setActivo(0); } })
        .catch(() => { if (!ctrl.signal.aborted) setRemotos([]); })
        .finally(() => { if (!ctrl.signal.aborted) setBuscando(false); });
    }, 180);
    return () => { ctrl.abort(); window.clearTimeout(timer); };
  }, [texto, puedeBuscarDatos]);

  const items = useMemo<Item[]>(() => {
    const ir = (ruta: string) => () => { onClose(); navigate(ruta); };
    const texto = normal(q.trim());
    const coincide = (s: string) => !texto || normal(s).includes(texto);

    const rol = user?.role;
    const acciones: Item[] = [
      rol === 'COMPRAS' && { clave: 'a-ingreso', grupo: 'actions', titulo: t('tasks.items.new_receipt.title'), icono: ReceiptLongIcon, ejecutar: ir('/ingresos/nuevo') },
      (rol === 'TECNICO' || rol === 'ADMIN_TI') && { clave: 'a-completar', grupo: 'actions', titulo: t('tasks.items.complete.title'), icono: QrCodeScannerIcon, ejecutar: ir('/ingresos/por-completar') },
      rol === 'ADMIN_TI' && { clave: 'a-aprobar', grupo: 'actions', titulo: t('tasks.items.approve.title'), icono: AssignmentIndIcon, ejecutar: ir('/aprobaciones') },
      (rol === 'COMPRAS' || rol === 'ADMIN_TI' || rol === 'AUDITOR') && { clave: 'a-stock', grupo: 'actions', titulo: t('tasks.items.stock.title'), icono: Inventory2Icon, ejecutar: ir('/compras/stock') },
      canOperate && { clave: 'a-entrega', grupo: 'actions', titulo: t('palette.actions.handover'), subtitulo: t('palette.actions.handover_help'), icono: AssignmentIndIcon, etiqueta: 'E', ejecutar: () => { onClose(); onEntrega(); } },
      canOperate && { clave: 'a-activo', grupo: 'actions', titulo: t('palette.actions.new_asset'), icono: AddBoxIcon, ejecutar: ir('/activos?nuevo=1') },
      canAdminInventory && { clave: 'a-alta', grupo: 'actions', titulo: t('palette.actions.onboarding'), subtitulo: t('palette.actions.onboarding_help'), icono: PersonAddIcon, ejecutar: ir('/operaciones/alta-empleado') },
      puedeBuscarDatos && rol !== 'COMPRAS' && { clave: 'a-scanner', grupo: 'actions', titulo: t('palette.actions.scan'), icono: QrCodeScannerIcon, ejecutar: ir('/scanner') },
      canAdminInventory && { clave: 'a-import', grupo: 'actions', titulo: t('palette.actions.import'), icono: UploadFileIcon, ejecutar: ir('/config/importar') },
      canOperate && { clave: 'a-movimientos', grupo: 'actions', titulo: t('palette.actions.movements'), subtitulo: t('palette.actions.movements_help'), icono: SwapHorizIcon, ejecutar: ir('/operaciones/movimientos') },
      canOperate && { clave: 'a-maintenance', grupo: 'actions', titulo: t('palette.actions.maintenance'), icono: BuildIcon, ejecutar: ir('/mantenimiento') },
      canOperate && { clave: 'a-consumables', grupo: 'actions', titulo: t('palette.actions.consumables'), icono: Inventory2Icon, ejecutar: ir('/consumibles') },
      { clave: 'a-atajos', grupo: 'actions', titulo: t('palette.actions.shortcuts'), icono: KeyboardIcon, etiqueta: '?', ejecutar: () => { onClose(); onAtajos(); } },
    ].filter((x): x is Item => !!x && coincide(x.titulo));

    const resultados: Item[] = remotosVisibles.map((r) => {
      if (r.tipo === 'activo') {
        return {
          clave: `r-a-${r.id}`, grupo: 'assets', titulo: r.titulo, icono: ComputerIcon,
          subtitulo: [r.subtitulo, r.custodio ? t('palette.custody', { name: r.custodio }) : null].filter(Boolean).join(' · '),
          etiqueta: stateLabel(t, r.estado),
          ejecutar: () => { onClose(); onAbrirRegistro({ tipo: 'activo', id: r.id, titulo: r.titulo, subtitulo: r.subtitulo, ruta: `/activos/${encodeURIComponent(r.codigo)}` }); },
        };
      }
      if (r.tipo === 'persona') {
        return {
          clave: `r-p-${r.id}`, grupo: 'people', titulo: r.titulo, subtitulo: r.subtitulo, icono: BadgeIcon,
          etiqueta: r.activo ? undefined : t('persons.inactive'),
          ejecutar: () => { onClose(); onAbrirRegistro({ tipo: 'persona', id: r.id, titulo: r.titulo, subtitulo: r.subtitulo, ruta: `/empleados/${r.id}` }); },
        };
      }
      return {
        clave: `r-o-${r.id}`, grupo: 'orders', titulo: r.titulo, subtitulo: r.subtitulo, icono: ReceiptLongIcon,
        ejecutar: () => { onClose(); onAbrirRegistro({ tipo: 'orden', id: r.id, titulo: r.titulo, subtitulo: r.subtitulo, ruta: `/compras/ordenes?orden=${r.id}` }); },
      };
    });

    const recientesItems: Item[] = texto ? [] : recientes.map((r) => ({
      clave: `h-${r.tipo}-${r.id}`, grupo: 'recent', titulo: r.titulo, subtitulo: r.subtitulo, icono: ICONO_TIPO[r.tipo] ?? HistoryIcon,
      ejecutar: () => { onClose(); onAbrirRegistro(r); },
    }));

    const nav = (user ? NAVIGATION_ITEMS.filter((n) => n.allowedRoles.includes(user.role)) : [])
      .map((n) => ({
        clave: `n-${n.path}`, grupo: 'navigation', titulo: t(n.title), icono: n.icon,
        subtitulo: n.group ? t(`menu_groups.${n.group}`) : undefined,
        etiqueta: favoritos.includes(n.path) ? '★' : undefined, ejecutar: ir(n.path),
      }))
      .filter((n) => coincide(n.titulo))
      .sort((a, b) => Number(!!b.etiqueta) - Number(!!a.etiqueta));

    return [...acciones, ...resultados, ...recientesItems, ...nav];
  }, [q, remotosVisibles, recientes, favoritos, user, puedeBuscarDatos, canOperate, canAdminInventory, t, navigate, onClose, onEntrega, onAtajos, onAbrirRegistro]);

  useEffect(() => {
    listaRef.current?.querySelector('[data-activo="true"]')?.scrollIntoView({ block: 'nearest' });
  }, [activo]);

  const alTeclear = (e: React.KeyboardEvent) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setActivo((i) => Math.min(i + 1, items.length - 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActivo((i) => Math.max(i - 1, 0)); }
    else if (e.key === 'Enter') { e.preventDefault(); items[activo]?.ejecutar(); }
  };

  let grupoAnterior = '';
  const cargando = buscando && texto.length >= 2;
  return (
    <>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, px: 2, py: 1.25, borderBottom: 1, borderColor: 'divider' }}>
        <SearchIcon color="action" />
        <InputBase autoFocus fullWidth value={q} onChange={(e) => { setQ(e.target.value); setActivo(0); }} onKeyDown={alTeclear}
          placeholder={t('palette.placeholder')} inputProps={{ 'aria-label': t('palette.placeholder') }} sx={{ fontSize: '1rem' }} />
        {cargando && <CircularProgress size={18} />}
        <Chip size="small" label="Esc" variant="outlined" sx={{ height: 20, fontSize: '0.68rem' }} />
      </Box>
      <List ref={listaRef} dense sx={{ maxHeight: '60vh', overflowY: 'auto', py: 0.5 }}>
        {items.length === 0 && (
          <Typography variant="body2" color="text.secondary" sx={{ px: 2, py: 3, textAlign: 'center' }}>
            {texto.length >= 2 && !cargando ? t('palette.no_results', { q: texto }) : t('palette.hint')}
          </Typography>
        )}
        {items.map((it, i) => {
          const cabecera = it.grupo !== grupoAnterior;
          grupoAnterior = it.grupo;
          const Icono = it.icono;
          return (
            <Box key={it.clave}>
              {cabecera && (
                <ListSubheader disableSticky sx={{ lineHeight: 2.4, typography: 'overline', fontSize: '0.66rem', bgcolor: 'transparent' }}>
                  {t(`palette.groups.${it.grupo}`)}
                </ListSubheader>
              )}
              <ListItemButton data-activo={i === activo} selected={i === activo} onMouseMove={() => setActivo(i)} onClick={it.ejecutar}
                sx={{ mx: 1, borderRadius: 1.5, '&.Mui-selected': { bgcolor: alpha(theme.palette.primary.main, 0.12) } }}>
                <ListItemIcon sx={{ minWidth: 34 }}><Icono fontSize="small" /></ListItemIcon>
                <ListItemText primary={it.titulo} secondary={it.subtitulo}
                  primaryTypographyProps={{ variant: 'body2', fontWeight: 600, noWrap: true }}
                  secondaryTypographyProps={{ variant: 'caption', noWrap: true }} />
                {it.etiqueta && <Chip size="small" label={it.etiqueta} variant="outlined" sx={{ ml: 1, height: 20, fontSize: '0.68rem' }} />}
              </ListItemButton>
            </Box>
          );
        })}
      </List>
      <Box sx={{ display: 'flex', gap: 2, px: 2, py: 1, borderTop: 1, borderColor: 'divider', color: 'text.secondary' }}>
        <Typography variant="caption">↑ ↓ {t('palette.footer.move')}</Typography>
        <Typography variant="caption">Enter {t('palette.footer.open')}</Typography>
        <Typography variant="caption" sx={{ ml: 'auto' }}>Ctrl + K</Typography>
      </Box>
    </>
  );
};
