import { createTheme, alpha } from '@mui/material/styles';
import {
  contrastRatio, ensureReadable, mix, muteText, pickText, relativeLuminance,
} from './contrast';

const FONT_FAMILY = '"Manrope Variable", "Manrope", "Segoe UI", system-ui, sans-serif';

// ── Paleta pastel neutra (defaults cuando el sistema no tiene config) ─────────
// Tonos cálidos y apagados: profesionales, no saturados, modificables vía config.
const DEFAULT_BG = '#f5f3ef';              // crema cálido — superficie de trabajo
const DEFAULT_PRIMARY = '#4a7c9e';         // azul grisáceo — acento de marca
const DEFAULT_SECONDARY = '#5a8e7a';       // verde salvia — botones de acción

// Semántica de los colores configurables:
//  - Fondo:      superficie de trabajo. Claro u oscuro: el modo (light/dark),
//                los textos, tarjetas y bordes se derivan de él.
//  - Primario:   color de marca → navegación activa, iconos, enlaces y foco.
//  - Secundario: color de acción → botones principales.
// Presets de paleta disponibles para el panel de configuración.
export const PALETTE_PRESETS = [
  { name: 'Neutro Cálido',  bg: '#f5f3ef', primary: '#4a7c9e', secondary: '#5a8e7a' },
  { name: 'Pizarra',        bg: '#f1f3f6', primary: '#556fb5', secondary: '#7a9e7e' },
  { name: 'Lavanda',        bg: '#f3f1f8', primary: '#7c6fba', secondary: '#9e7a8a' },
  { name: 'Terracota',      bg: '#f7f3f0', primary: '#a06050', secondary: '#8a7a5a' },
  { name: 'Medianoche',     bg: '#1a1f2e', primary: '#7eb8d4', secondary: '#7ebfa0' },
] as const;

// Colores base de estado (se ajustan al fondo para garantizar contraste).
const STATUS_BASE = {
  success: '#22c55e',
  warning: '#f59e0b',
  error: '#ef4444',
  info: '#38bdf8',
};

export type TemaUsuario = 'claro' | 'oscuro' | 'sistema';

/**
 * Fondo efectivo según la preferencia del usuario. Si la paleta de la empresa
 * ya está en el modo pedido se respeta tal cual; si no, se deriva un fondo del
 * modo contrario teñido levemente con el color de marca (la identidad se
 * conserva y createAppTheme recalcula textos, superficies y contraste).
 */
export const fondoSegunTema = (fondo: string, primario: string, oscuro: boolean): string => {
  const bg = /^#([0-9a-fA-F]{3}){1,2}$/.test(fondo || '') ? fondo : DEFAULT_BG;
  const p = /^#([0-9a-fA-F]{3}){1,2}$/.test(primario || '') ? primario : DEFAULT_PRIMARY;
  const esOscuro = relativeLuminance(bg) <= 0.35;
  if (oscuro === esOscuro) return bg;
  return oscuro ? mix('#14171f', p, 0.08) : mix(DEFAULT_BG, p, 0.04);
};

// Función generadora de tema dinámico.
// El contraste de texto/paper/bordes/acentos se DERIVA matemáticamente (WCAG)
// del color de fondo, de modo que el fondo puede ser cualquier color (oscuro o
// claro) sin romper la legibilidad del texto ni de los iconos.
export const createAppTheme = (
  primaryColor: string = DEFAULT_PRIMARY,
  secondaryColor: string = DEFAULT_SECONDARY,
  backgroundColor: string = DEFAULT_BG,
) => {
  // Se respeta el fondo configurado tal cual (antes se mezclaba 92 % hacia
  // crema y forzaba modo claro: la opción de color de fondo no tenía efecto).
  const bg = /^#([0-9a-fA-F]{3}){1,2}$/.test(backgroundColor || '') ? backgroundColor : DEFAULT_BG;
  const isLight = relativeLuminance(bg) > 0.35;
  const mode: 'light' | 'dark' = isLight ? 'light' : 'dark';

  // Texto: el de mayor contraste (AAA si se puede), y secundario atenuado pero ≥AA.
  const textPrimary = pickText(bg);
  const divider = alpha(textPrimary, isLight ? 0.18 : 0.2);

  // Superficies sólidas: evitan que textos y diálogos pierdan legibilidad
  // cuando el fondo de marca es oscuro o muy saturado.
  // En claro las superficies se acercan al blanco; en oscuro se aclaran un
  // poco sobre el fondo (tarjetas visibles sin perder el tono de la paleta).
  const surfaceTarget = isLight ? '#fffdfa' : '#ffffff';
  const paper = isLight ? mix(bg, surfaceTarget, 0.86) : mix(bg, surfaceTarget, 0.05);
  const paperElevated = isLight ? mix(bg, surfaceTarget, 0.98) : mix(bg, surfaceTarget, 0.09);
  const inputBg = isLight ? mix(bg, surfaceTarget, 0.7) : mix(bg, surfaceTarget, 0.03);
  const tableHeadBg = isLight ? mix(bg, surfaceTarget, 0.74) : mix(bg, surfaceTarget, 0.07);
  // Texto secundario: lo más sutil posible pero ≥ 4.5:1 (WCAG AA) sobre TODAS
  // las superficies donde aparece (fondo, tarjetas, cabeceras de tabla,
  // inputs), no solo sobre el fondo de página.
  const surfaces = [bg, paper, paperElevated, tableHeadBg, inputBg];
  const textSecondary = surfaces
    .map((surface) => muteText(textPrimary, surface, 4.6))
    .reduce((best, c) => (contrastRatio(c, bg) > contrastRatio(best, bg) ? c : best));

  // Acentos legibles sobre el fondo (para iconos/bordes de foco). El primario es
  // "brillo de marca": se usa su versión legible como acento, y el color crudo
  // solo para el glow del gradiente. El secundario se conserva para botones
  // (su texto lo resuelve getContrastText).
  // El color de marca también se usa como TEXTO (menú activo, enlaces): ≥ 4.5:1
  // con margen para el fondo tintado del ítem seleccionado.
  const accent = ensureReadable(primaryColor, bg, 5);
  const focus = accent;
  const buttonColor = ensureReadable(secondaryColor, bg, 3);
  const tableHoverBg = alpha(accent, isLight ? 0.08 : 0.12);

  // Paleta de estado ajustada al fondo (chips/alertas/KPIs legibles).
  const status = Object.fromEntries(
    Object.entries(STATUS_BASE).map(([k, v]) => [k, { main: ensureReadable(v, bg, 4.5) }]),
  ) as Record<'success' | 'warning' | 'error' | 'info', { main: string }>;

  const theme = createTheme({
    palette: {
      mode,
      // Texto de chips/botones rellenos: blanco o negro según el que cumpla AA.
      contrastThreshold: 4.5,
      primary: { main: accent, contrastText: pickText(accent) },
      secondary: { main: buttonColor, contrastText: pickText(buttonColor) },
      background: { default: bg, paper },
      text: { primary: textPrimary, secondary: textSecondary },
      divider,
      success: status.success,
      warning: status.warning,
      error: status.error,
      info: status.info,
    },
    // Una sola familia (Manrope, servida por la propia app: sin Google Fonts,
    // compatible con la CSP y con intranets sin salida a internet).
    typography: {
      fontFamily: FONT_FAMILY,
      h1: { fontWeight: 700, letterSpacing: '-0.02em' },
      h2: { fontWeight: 700, letterSpacing: '-0.02em' },
      h3: { fontWeight: 700, letterSpacing: '-0.015em' },
      h4: { fontWeight: 700, letterSpacing: '-0.01em', fontSize: '1.75rem' },
      h5: { fontWeight: 700, letterSpacing: '-0.01em', fontSize: '1.375rem' },
      h6: { fontWeight: 700, letterSpacing: 0, fontSize: '1.0625rem' },
      subtitle1: { fontWeight: 600, letterSpacing: 0 },
      subtitle2: { fontWeight: 700, letterSpacing: 0 },
      button: { fontWeight: 600, textTransform: 'none', letterSpacing: 0 },
      overline: { fontWeight: 700, letterSpacing: '0.06em' },
    },
    shape: { borderRadius: 10 },
    components: {
      MuiCssBaseline: {
        styleOverrides: {
          // Fondo más oscuro que el verde de notistack: su texto blanco no llegaba al contraste AA.
          '.notistack-MuiContent-success': { backgroundColor: '#1b7a3d !important' },
          body: {
            backgroundColor: bg,
            backgroundImage: 'none',
            backgroundAttachment: 'fixed',
            scrollbarColor: `${alpha(accent, 0.4)} transparent`,
          },
        },
      },
      MuiPaper: {
        defaultProps: {
          elevation: 0,
        },
        styleOverrides: {
          root: {
            backgroundColor: paper,
            backgroundImage: 'none',
            color: textPrimary,
          },
        },
      },
      MuiCard: {
        styleOverrides: {
          root: {
            border: `1px solid ${divider}`,
            boxShadow: 'none',
            backgroundImage: 'none',
          },
        },
      },
      MuiDialog: {
        styleOverrides: {
          paper: {
            backgroundColor: paperElevated,
            backgroundImage: 'none',
            border: `1px solid ${divider}`,
            boxShadow: 'none',
            color: textPrimary,
          },
        },
      },
      MuiPopover: {
        styleOverrides: {
          paper: {
            backgroundColor: paperElevated,
            backgroundImage: 'none',
            border: `1px solid ${divider}`,
            color: textPrimary,
          },
        },
      },
      MuiMenu: {
        styleOverrides: {
          paper: {
            backgroundColor: paperElevated,
            backgroundImage: 'none',
          },
        },
      },
      MuiTableCell: {
        styleOverrides: {
          root: {
            borderColor: divider,
            color: textPrimary,
            paddingTop: 10,
            paddingBottom: 10,
          },
          // Columna de acciones: iconos siempre en una fila (no apilados).
          alignRight: { whiteSpace: 'nowrap' },
          head: {
            backgroundColor: tableHeadBg,
            color: textSecondary,
            fontWeight: 700,
            fontSize: '0.75rem',
            letterSpacing: 0,
            textTransform: 'uppercase',
          },
        },
      },
      MuiTableRow: {
        styleOverrides: {
          root: {
            '&.MuiTableRow-hover:hover': {
              backgroundColor: tableHoverBg,
            },
          },
        },
      },
      MuiTableContainer: {
        styleOverrides: {
          root: {
            border: `1px solid ${divider}`,
            borderRadius: 8,
            backgroundImage: 'none',
          },
        },
      },
      MuiButton: {
        styleOverrides: {
          root: () => ({ borderRadius: 8, padding: '8px 16px', whiteSpace: 'nowrap', flexShrink: 0 }),
          sizeSmall: { padding: '5px 12px' },
          containedPrimary: ({ theme }) => ({
            backgroundColor: buttonColor,
            color: theme.palette.getContrastText(buttonColor),
            boxShadow: 'none',
            '&:hover': {
              backgroundColor: mix(buttonColor, theme.palette.getContrastText(buttonColor), 0.08),
              boxShadow: 'none',
            },
          }),
        },
      },
      MuiIconButton: {
        styleOverrides: {
          root: {
            borderRadius: 8,
            '&.Mui-focusVisible': {
              outline: `2px solid ${focus}`,
              outlineOffset: 2,
            },
          },
        },
      },
      MuiChip: {
        styleOverrides: {
          root: {
            borderRadius: 8,
            fontWeight: 600,
          },
          outlined: {
            borderColor: divider,
          },
        },
      },
      MuiOutlinedInput: {
        styleOverrides: {
          root: {
            backgroundColor: inputBg,
            color: textPrimary,
            '& fieldset': { borderColor: divider },
            '&:hover fieldset': { borderColor: alpha(textPrimary, 0.36) },
            '&.Mui-focused fieldset': { borderColor: focus },
          },
          input: {
            color: textPrimary,
            '&::placeholder': { color: textSecondary, opacity: 1 },
          },
        },
      },
      MuiTextField: {
        styleOverrides: {
          root: {
            '& .MuiOutlinedInput-root': {
              backgroundColor: inputBg,
              '& fieldset': { borderColor: divider },
              '&:hover fieldset': { borderColor: alpha(textPrimary, 0.3) },
              '&.Mui-focused fieldset': { borderColor: focus },
            },
            '& .MuiInputLabel-root': { color: textSecondary },
            '& .MuiInputLabel-root.Mui-focused': { color: focus },
          },
        },
      },
      MuiFormHelperText: {
        styleOverrides: {
          root: {
            color: textSecondary,
          },
        },
      },
      MuiAutocomplete: {
        styleOverrides: {
          paper: {
            backgroundColor: paperElevated,
            backgroundImage: 'none',
            color: textPrimary,
            border: `1px solid ${divider}`,
          },
          listbox: {
            maxHeight: 320,
            padding: 4,
            '& .MuiAutocomplete-option': {
              alignItems: 'flex-start',
              borderRadius: 8,
              margin: '2px 4px',
              minHeight: 44,
            },
          },
          option: {
            '&[aria-selected="true"]': {
              backgroundColor: alpha(accent, 0.18),
            },
            '&.Mui-focused': {
              backgroundColor: alpha(accent, 0.12),
            },
          },
        },
      },
      MuiMenuItem: {
        styleOverrides: {
          root: {
            color: textPrimary,
            borderRadius: 8,
            '&.Mui-selected': {
              backgroundColor: alpha(accent, 0.18),
            },
          },
        },
      },
      MuiAlert: {
        styleOverrides: {
          root: {
            backgroundImage: 'none',
            border: `1px solid ${divider}`,
            borderRadius: 8,
          },
        },
      },
      MuiTooltip: {
        styleOverrides: {
          tooltip: {
            backgroundColor: paperElevated,
            border: `1px solid ${divider}`,
            color: textPrimary,
            boxShadow: 'none',
            fontWeight: 600,
          },
          arrow: {
            color: paperElevated,
          },
        },
      },
      MuiStepLabel: {
        styleOverrides: {
          label: {
            color: textSecondary,
            '&.Mui-active, &.Mui-completed': {
              color: textPrimary,
            },
          },
        },
      },
      MuiListItemIcon: {
        styleOverrides: { root: { minWidth: 40 } },
      },
    },
  });
  return theme;
};

// Reexport para tests y uso puntual en componentes.
export { contrastRatio, ensureReadable, pickText };
