/**
 * Reglas puras de autorrelleno: deducen un valor de los datos que ya existen,
 * para que el usuario escriba lo mínimo. Ninguna inventa datos: si no hay
 * de dónde deducir, devuelven null.
 */

/** Minúsculas ASCII sin tildes ni signos («Peña» → «pena»). */
const asciiWord = (value: string): string =>
  value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]/g, '');

/** Valor más repetido (en empate, el que apareció primero); null si no hay ninguno. */
export function mostCommon<T extends string | number>(values: ReadonlyArray<T | null | undefined>): T | null {
  const counts = new Map<T, number>();
  let best: T | null = null;
  let bestCount = 0;
  for (const value of values) {
    if (value === null || value === undefined || value === '') continue;
    const count = (counts.get(value) ?? 0) + 1;
    counts.set(value, count);
    if (count > bestCount) {
      best = value;
      bestCount = count;
    }
  }
  return best;
}

/** Mediana redondeada hacia abajo; null si no hay números. */
export function median(values: ReadonlyArray<number>): number | null {
  const sorted = values.filter((v) => Number.isFinite(v)).sort((a, b) => a - b);
  if (sorted.length === 0) return null;
  const mid = Math.floor(sorted.length / 2);
  return Math.floor(sorted.length % 2 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2);
}

/** Dominio más usado entre los correos registrados («empresa.com»). */
export function companyDomain(emails: ReadonlyArray<string | null | undefined>): string | null {
  return mostCommon(emails.map((email) => {
    const clean = (email ?? '').trim().toLowerCase();
    const at = clean.lastIndexOf('@');
    return at > 0 && at < clean.length - 1 ? clean.slice(at + 1) : null;
  }));
}

export interface PersonName {
  firstName: string;
  firstLastName: string;
  secondLastName?: string;
}

/**
 * Correo corporativo «nombre.apellido@dominio». Si ya existe, prueba con la
 * inicial del segundo apellido y después con un número, para no repetirlo.
 */
export function suggestEmail(name: PersonName, domain: string | null, taken: ReadonlyArray<string | null | undefined>): string | null {
  const first = asciiWord(name.firstName.trim().split(/\s+/)[0] ?? '');
  const last = asciiWord(name.firstLastName.trim().split(/\s+/)[0] ?? '');
  if (!domain || first.length < 2 || last.length < 2) return null;
  const used = new Set(taken.map((email) => (email ?? '').trim().toLowerCase()));
  const second = asciiWord(name.secondLastName ?? '').slice(0, 1);
  const candidates = [`${first}.${last}`, ...(second ? [`${first}.${last}${second}`] : [])];
  for (const local of candidates) {
    if (!used.has(`${local}@${domain}`)) return `${local}@${domain}`;
  }
  for (let n = 2; n < 100; n++) {
    const email = `${first}.${last}${n}@${domain}`;
    if (!used.has(email)) return email;
  }
  return null;
}

/** NIT sin espacios, puntos ni guiones y en mayúsculas («1234567-k» → «1234567K»). */
export const normalizeTaxId = (value: string): string => value.replace(/[\s.-]/g, '').toUpperCase();

/**
 * Destino de una baja cuando el motivo no deja dudas. En los demás casos el
 * destino depende de una decisión (reciclar, donar, vender) y no se sugiere.
 */
const DESTINATION_BY_REASON: Readonly<Record<string, string>> = {
  PERDIDA: 'NO_RECUPERADO',
  ROBO: 'NO_RECUPERADO',
  GARANTIA_REEMPLAZO: 'DEVOLUCION_PROVEEDOR',
  FIN_ARRENDAMIENTO: 'DEVOLUCION_PROVEEDOR',
};

export const destinationForReason = (reason: string | null | undefined): string | null =>
  (reason && DESTINATION_BY_REASON[reason]) || null;
