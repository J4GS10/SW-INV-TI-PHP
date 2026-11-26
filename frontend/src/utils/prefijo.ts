/**
 * Prefijo de código sugerido a partir de un nombre: iniciales si son varias palabras
 * («Memoria RAM» → «MR»), las tres primeras letras si es una («Laptop» → «LAP»).
 * Siempre de 2 a 5 letras sin tildes.
 */
export function prefijoDesde(nombre: string): string {
  const palabras = nombre.normalize('NFD').replace(/[^A-Za-z\s]/g, '').toUpperCase().split(/\s+/).filter((p) => p.length > 1 || /^[A-Z]$/.test(p));
  if (palabras.length === 0) return '';
  const base = palabras.length === 1 ? palabras[0].slice(0, 3) : palabras.slice(0, 3).map((p) => p[0]).join('');
  return base.length >= 2 ? base : (palabras[0].slice(0, 3)).padEnd(2, 'X');
}

/** El prefijo sugerido o, si ya lo usa otro tipo, el primero libre con número («BAT» → «BAT2», «BAT3»…). */
export function prefijoLibre(base: string, usados: Iterable<string>): string {
  const ocupados = new Set([...usados].map((p) => p.toUpperCase()));
  if (!ocupados.has(base.toUpperCase())) return base;
  for (let n = 2; n < 100; n++) if (!ocupados.has(`${base}${n}`.toUpperCase())) return `${base}${n}`;
  return base;
}
