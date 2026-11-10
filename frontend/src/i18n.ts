import i18n, { type BackendModule, type ResourceKey } from 'i18next';
import { initReactI18next } from 'react-i18next';

import { normalizeLanguage, SUPPORTED_LANGUAGES, type SupportedLanguage } from './types/language';

function initialLanguage(): SupportedLanguage {
  try {
    return normalizeLanguage(window.localStorage.getItem('app_language') ?? navigator.language);
  } catch {
    return 'es';
  }
}

/** Only the language in use is downloaded (each one is ~200 KB); the others come when chosen. */
const bundles: Record<SupportedLanguage, () => Promise<{ default: ResourceKey }>> = {
  es: () => import('./locales/es.json'),
  en: () => import('./locales/en.json'),
  it: () => import('./locales/it.json'),
};

const lazyLocales: BackendModule = {
  type: 'backend',
  init: () => undefined,
  read: (language, _namespace, callback) => {
    const load = bundles[normalizeLanguage(language)];
    load().then((bundle) => callback(null, bundle.default), (error: Error) => callback(error, false));
  },
};

/** Resolves once the first language is loaded: the app renders after it, never with bare keys. */
export const i18nReady = i18n
  .use(lazyLocales)
  .use(initReactI18next)
  .init({
    lng: initialLanguage(),
    fallbackLng: 'es',
    supportedLngs: [...SUPPORTED_LANGUAGES],
    nonExplicitSupportedLngs: true,
    load: 'languageOnly',
    returnNull: false,
    interpolation: {
      escapeValue: false,
    },
    react: { useSuspense: false },
  });

i18n.on('languageChanged', (language) => {
  try {
    window.localStorage.setItem('app_language', normalizeLanguage(language));
  } catch {
    /* El idioma funciona aunque el navegador bloquee localStorage. */
  }
});

export default i18n;
