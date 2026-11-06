export type SupportedLanguage = 'es' | 'en' | 'it';

export const SUPPORTED_LANGUAGES: SupportedLanguage[] = ['es', 'en', 'it'];

export function normalizeLanguage(value: string | null | undefined): SupportedLanguage {
  const code = (value ?? '').split('-')[0].toLowerCase();
  return SUPPORTED_LANGUAGES.includes(code as SupportedLanguage) ? code as SupportedLanguage : 'es';
}
