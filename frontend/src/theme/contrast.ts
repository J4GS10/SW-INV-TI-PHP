/**
 * Utilidades de contraste WCAG 2.1 (cálculo matemático puro, sin dependencias).
 *
 * Permiten derivar colores de texto/acentos legibles sobre CUALQUIER color de
 * fondo elegido por el usuario, garantizando los ratios de contraste mínimos:
 *   - Texto normal: AA ≥ 4.5:1, AAA ≥ 7:1
 *   - Texto grande / componentes UA (iconos, bordes de foco): AA ≥ 3:1
 */

type RGB = [number, number, number];

function toRgb(hex: string): RGB {
  let h = (hex || '').replace('#', '').trim();
  if (h.length === 3) h = h.split('').map((c) => c + c).join('');
  if (h.length !== 6 || /[^0-9a-fA-F]/.test(h)) return [15, 23, 42]; // fallback slate
  const n = parseInt(h, 16);
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
}

function toHex([r, g, b]: RGB): string {
  const c = (v: number) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0');
  return `#${c(r)}${c(g)}${c(b)}`;
}

/** Luminancia relativa WCAG (linealización sRGB). */
export function relativeLuminance(hex: string): number {
  const [r, g, b] = toRgb(hex).map((v) => {
    const s = v / 255;
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** Ratio de contraste WCAG entre dos colores: (L1+0.05)/(L2+0.05), 1..21. */
export function contrastRatio(a: string, b: string): number {
  const la = relativeLuminance(a);
  const lb = relativeLuminance(b);
  const [hi, lo] = la >= lb ? [la, lb] : [lb, la];
  return (hi + 0.05) / (lo + 0.05);
}

/** Mezcla lineal c1→c2 (w: 0=c1, 1=c2) en espacio sRGB. */
export function mix(c1: string, c2: string, w: number): string {
  const a = toRgb(c1);
  const b = toRgb(c2);
  return toHex([0, 1, 2].map((i) => a[i] + (b[i] - a[i]) * w) as unknown as RGB);
}

/** Devuelve el color de texto (claro u oscuro) con MÁS contraste sobre `bg`. */
export function pickText(bg: string, light = '#f8fafc', dark = '#0b1220'): string {
  return contrastRatio(light, bg) >= contrastRatio(dark, bg) ? light : dark;
}

/**
 * Atenúa `fg` hacia `bg` lo máximo posible manteniendo el ratio ≥ target.
 * Útil para el texto secundario: lo más sutil posible pero aún legible.
 */
export function muteText(fg: string, bg: string, target = 4.5): string {
  let best = fg;
  for (let w = 0.05; w <= 0.85; w += 0.05) {
    const c = mix(fg, bg, w);
    if (contrastRatio(c, bg) >= target) best = c;
    else break;
  }
  return best;
}

/**
 * Garantiza que `color` sea legible como primer plano sobre `bg` (≥ target);
 * si no lo es, lo empuja hacia blanco/negro (según el fondo) hasta lograrlo.
 * Preserva el tono mientras puede. Para acentos, iconos y bordes de foco.
 */
export function ensureReadable(color: string, bg: string, target = 3): string {
  if (contrastRatio(color, bg) >= target) return color;
  const towards = relativeLuminance(bg) > 0.5 ? '#000000' : '#ffffff';
  let best = color;
  for (let w = 0.05; w <= 1; w += 0.05) {
    best = mix(color, towards, w);
    if (contrastRatio(best, bg) >= target) return best;
  }
  return best;
}
