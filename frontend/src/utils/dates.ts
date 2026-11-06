const UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
  ['minute', 60],
  ['hour', 60 * 60],
  ['day', 60 * 60 * 24],
];

/** Parsea fechas ISO del backend (naive UTC, sin zona) como UTC. */
export function parseApiDate(value: string | null | undefined): Date | null {
  if (!value) return null;
  const hasZone = /[zZ]|[+-]\d{2}:?\d{2}$/.test(value);
  const date = new Date(hasZone ? value : `${value}Z`);
  return Number.isNaN(date.getTime()) ? null : date;
}

/** "hace 5 minutos", "ayer"… hasta 7 días; después, fecha corta en el idioma activo. */
export function formatRelativeDate(date: Date, locale: string, now = new Date()): string {
  const seconds = Math.round((date.getTime() - now.getTime()) / 1000);
  const abs = Math.abs(seconds);
  if (abs < 7 * 24 * 60 * 60) {
    const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
    if (abs < 60) return rtf.format(0, 'minute');
    let unit: Intl.RelativeTimeFormatUnit = 'minute';
    let size = 60;
    for (const [candidate, candidateSize] of UNITS) {
      if (abs >= candidateSize) {
        unit = candidate;
        size = candidateSize;
      }
    }
    return rtf.format(Math.round(seconds / size), unit);
  }
  return date.toLocaleDateString(locale, { dateStyle: 'medium' });
}

export const formatDateTime = (date: Date, locale: string) =>
  date.toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' });

/** Hora (HH:mm) si es hoy; si no, fecha y hora cortas. */
export function formatTimeOrDateTime(date: Date, locale: string, now = new Date()): string {
  const sameDay = date.toDateString() === now.toDateString();
  return sameDay
    ? date.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' })
    : date.toLocaleString(locale, { dateStyle: 'short', timeStyle: 'short' });
}

const LOCALE_POR_IDIOMA: Record<string, string> = { es: 'es-GT', en: 'en-US', it: 'it-IT' };

function localeDe(language: string | undefined): string {
  const code = (language ?? 'es').split('-')[0].toLowerCase();
  return LOCALE_POR_IDIOMA[code] ?? 'es-GT';
}

/** Fecha de hoy en la hora local (YYYY-MM-DD). `toISOString()` da la de UTC: después de las 18:00 en Guatemala sería mañana. */
export function hoyLocal(desplazamientoDias = 0): string {
  const d = new Date();
  d.setDate(d.getDate() + desplazamientoDias);
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  return `${d.getFullYear()}-${mm}-${dd}`;
}

/**
 * Fecha para mostrar en el idioma de la aplicación. Acepta "YYYY-MM-DD"
 * (fechas sin hora: no se desplazan por zona horaria) o fecha-hora del backend.
 */
export function formatDate(value: string | null | undefined, language?: string): string {
  if (!value) return '—';
  const soloFecha = /^\d{4}-\d{2}-\d{2}$/.exec(value);
  const date = soloFecha ? new Date(`${value}T12:00:00`) : parseApiDate(value);
  if (!date || Number.isNaN(date.getTime())) return value;
  return date.toLocaleDateString(localeDe(language), { day: '2-digit', month: 'short', year: 'numeric' });
}

/** Fecha y hora (del backend, en UTC) mostrada en la hora local y el idioma de la aplicación. */
export function formatDateTimeValue(value: string | null | undefined, language?: string): string {
  const date = parseApiDate(value);
  if (!date) return '—';
  return date.toLocaleString(localeDe(language), { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
