/** Política de contraseñas (misma forma que `politica` del resumen de seguridad). */
export interface PasswordPolicy {
  minLength: number;
  upper: boolean;
  lower: boolean;
  digit: boolean;
  symbol: boolean;
  /** Earlier passwords, besides the current one, that cannot be repeated. */
  history?: number;
}

/** Valores por defecto del backend; se ajustan con la política real cuando se conoce. */
export const DEFAULT_PASSWORD_POLICY: PasswordPolicy = {
  minLength: 10,
  upper: true,
  lower: true,
  digit: true,
  symbol: false,
  history: 3,
};

export type PasswordRule = 'min_length' | 'require_upper' | 'require_lower' | 'require_digit' | 'require_symbol';

export interface PasswordRuleCheck {
  rule: PasswordRule;
  ok: boolean;
}

export function checkPassword(password: string, policy: PasswordPolicy): PasswordRuleCheck[] {
  const checks: PasswordRuleCheck[] = [{ rule: 'min_length', ok: password.length >= policy.minLength }];
  if (policy.upper) checks.push({ rule: 'require_upper', ok: /\p{Lu}/u.test(password) });
  if (policy.lower) checks.push({ rule: 'require_lower', ok: /\p{Ll}/u.test(password) });
  if (policy.digit) checks.push({ rule: 'require_digit', ok: /\d/.test(password) });
  if (policy.symbol) checks.push({ rule: 'require_symbol', ok: /[^\p{L}\p{N}]/u.test(password) });
  return checks;
}

export const meetsPolicy = (password: string, policy: PasswordPolicy) =>
  checkPassword(password, policy).every((check) => check.ok);

/**
 * Ajusta la política con el detalle de un error `PASSWORD_POLICY_VIOLATION:min_length:12,require_symbol`
 * (el backend informa las reglas incumplidas). Devuelve la política sin cambios si no aplica.
 */
export function policyFromViolation(detail: string | undefined, current: PasswordPolicy): PasswordPolicy {
  const reused = detail?.match(/^PASSWORD_REUSED:(\d+)$/);
  if (reused) return { ...current, history: Number(reused[1]) };
  if (!detail?.startsWith('PASSWORD_POLICY_VIOLATION:')) return current;
  const next = { ...current };
  for (const part of detail.slice('PASSWORD_POLICY_VIOLATION:'.length).split(',')) {
    const [rule, value] = part.split(':');
    if (rule === 'min_length' && Number(value) > 0) next.minLength = Number(value);
    if (rule === 'require_upper') next.upper = true;
    if (rule === 'require_lower') next.lower = true;
    if (rule === 'require_digit') next.digit = true;
    if (rule === 'require_symbol') next.symbol = true;
  }
  return next;
}

// Sin caracteres ambiguos (0/O, 1/l/I) para poder dictarla sin errores.
const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
const LOWER = 'abcdefghijkmnopqrstuvwxyz';
const DIGITS = '23456789';
const SYMBOLS = '#$%&*+-=?@';

/** Entero uniforme en [0, max) con CSPRNG (muestreo por rechazo, sin sesgo de módulo). */
function randomInt(max: number): number {
  const limit = Math.floor(0x1_0000_0000 / max) * max;
  const buffer = new Uint32Array(1);
  let value: number;
  do {
    crypto.getRandomValues(buffer);
    value = buffer[0];
  } while (value >= limit);
  return value % max;
}

const pick = (alphabet: string) => alphabet[randomInt(alphabet.length)];

/** Contraseña aleatoria fuerte: incluye mayúscula, minúscula, dígito y símbolo. */
export function generateStrongPassword(length = 16, policy: PasswordPolicy = DEFAULT_PASSWORD_POLICY): string {
  const size = Math.max(length, policy.minLength, 12);
  const all = UPPER + LOWER + DIGITS + SYMBOLS;
  const chars = [pick(UPPER), pick(LOWER), pick(DIGITS), pick(SYMBOLS)];
  while (chars.length < size) chars.push(pick(all));
  // Fisher-Yates con el mismo generador.
  for (let i = chars.length - 1; i > 0; i -= 1) {
    const j = randomInt(i + 1);
    [chars[i], chars[j]] = [chars[j], chars[i]];
  }
  return chars.join('');
}

/** Política desde el resumen de seguridad del backend (`politica`). */
export const policyFromSummary = (politica: {
  password_historial?: number;
  password_longitud_minima: number;
  password_mayuscula: boolean;
  password_minuscula: boolean;
  password_digito: boolean;
  password_simbolo: boolean;
}): PasswordPolicy => ({
  history: politica.password_historial,
  minLength: politica.password_longitud_minima,
  upper: politica.password_mayuscula,
  lower: politica.password_minuscula,
  digit: politica.password_digito,
  symbol: politica.password_simbolo,
});
