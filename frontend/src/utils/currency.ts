import { normalizeLanguage } from '../types/language';

/**
 * Monedas admitidas (espejo de app/core/currency.py). Cada monto conserva la
 * moneda en que se pagó: no se convierte ni se suman monedas distintas.
 */
export const CURRENCIES = ['GTQ', 'USD', 'CHF'] as const;
export type Currency = typeof CURRENCIES[number];
export const DEFAULT_CURRENCY: Currency = 'GTQ';

/** Clave i18n del nombre largo de cada moneda ("Quetzales (GTQ)"). */
export const CURRENCY_LABEL_KEY: Record<Currency, string> = {
  GTQ: 'procurement.currency_gtq',
  USD: 'procurement.currency_usd',
  CHF: 'procurement.currency_chf',
};

const LOCALE = { es: 'es-GT', en: 'en-US', it: 'it-IT' } as const;

export function isCurrency(value: unknown): value is Currency {
  return typeof value === 'string' && (CURRENCIES as readonly string[]).includes(value);
}

/** Símbolo corto para adornos de campos: "Q", "US$", "CHF". */
export function currencySymbol(currency: string, language: string): string {
  const code = isCurrency(currency) ? currency : DEFAULT_CURRENCY;
  const part = new Intl.NumberFormat(LOCALE[normalizeLanguage(language)], {
    style: 'currency', currency: code,
  }).formatToParts(0).find((p) => p.type === 'currency');
  return part?.value ?? code;
}

/** "Q 1,500.00" / "US$ 820.50" en el idioma activo; "-" si no hay monto. */
export function formatMoney(
  value: number | string | null | undefined,
  currency: string | null | undefined,
  language: string,
  fractionDigits = 2,
): string {
  if (value === null || value === undefined || value === '') return '-';
  const amount = Number(value);
  if (Number.isNaN(amount)) return '-';
  const code = isCurrency(currency) ? currency : DEFAULT_CURRENCY;
  return new Intl.NumberFormat(LOCALE[normalizeLanguage(language)], {
    style: 'currency',
    currency: code,
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  }).format(amount);
}

/** Totales por moneda ({GTQ: 1500, USD: 80}) → "Q 1,500 · US$ 80". */
export function formatMoneyTotals(
  totals: Record<string, number> | null | undefined,
  language: string,
): string {
  const entries = CURRENCIES
    .map((code) => [code, totals?.[code] ?? 0] as const)
    .filter(([, amount]) => amount);
  if (entries.length === 0) return formatMoney(0, DEFAULT_CURRENCY, language, 0);
  return entries.map(([code, amount]) => formatMoney(amount, code, language, 0)).join(' · ');
}
