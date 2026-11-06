import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';

import en from './locales/en.json';
import es from './locales/es.json';
import it from './locales/it.json';
import { normalizeLanguage, SUPPORTED_LANGUAGES, type SupportedLanguage } from './types/language';

function initialLanguage(): SupportedLanguage {
  try {
    return normalizeLanguage(window.localStorage.getItem('app_language') ?? navigator.language);
  } catch {
    return 'es';
  }
}

i18n
  .use(initReactI18next)
  .init({
    resources: {
      es: { translation: es },
      en: { translation: en },
      it: { translation: it },
    },
    lng: initialLanguage(),
    fallbackLng: 'es',
    supportedLngs: [...SUPPORTED_LANGUAGES],
    nonExplicitSupportedLngs: true,
    returnNull: false,
    interpolation: {
      escapeValue: false,
    },
  });

i18n.on('languageChanged', (language) => {
  try {
    window.localStorage.setItem('app_language', normalizeLanguage(language));
  } catch {
    /* El idioma funciona aunque el navegador bloquee localStorage. */
  }
});

export default i18n;
