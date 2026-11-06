import client from './axios';
import i18n from '../i18n';
import { normalizeLanguage, type SupportedLanguage } from '../types/language';

/**
 * Nombre de archivo que envía el servidor (Content-Disposition), ya traducido
 * al idioma pedido; si no viene, el nombre de reserva del llamador.
 */
function filenameFrom(disposition: string | undefined, fallback: string): string {
  if (!disposition) return fallback;
  const utf8 = /filename\*=UTF-8''([^;]+)/i.exec(disposition);
  if (utf8) {
    try {
      return decodeURIComponent(utf8[1]);
    } catch {
      // cae al nombre ASCII
    }
  }
  const ascii = /filename="?([^";]+)"?/i.exec(disposition);
  return ascii ? ascii[1] : fallback;
}

function saveBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  // Firefox y Safari cortan la descarga si la URL se revoca de inmediato.
  window.setTimeout(() => URL.revokeObjectURL(url), 10_000);
}

/** Descarga autenticada de cualquier archivo del backend (PDF, Word, CSV…). */
export const downloadFile = async (
  path: string, fallbackName: string, params?: Record<string, string | number | boolean | undefined>,
): Promise<void> => {
  const r = await client.get(path, { responseType: 'blob', params });
  saveBlob(r.data as Blob, filenameFrom(r.headers['content-disposition'] as string | undefined, fallbackName));
};

/**
 * Descarga autenticada de un CSV del backend en el idioma elegido. El nombre
 * del archivo lo decide el servidor (p. ej. `assets_20260928.csv` en inglés).
 */
export const downloadCsv = async (path: string, filename: string, language?: SupportedLanguage): Promise<void> => {
  const lang = normalizeLanguage(language ?? i18n.resolvedLanguage ?? i18n.language);
  const separator = path.includes('?') ? '&' : '?';
  const r = await client.get(`${path}${separator}lang=${lang}`, { responseType: 'blob' });
  saveBlob(r.data as Blob, filenameFrom(r.headers['content-disposition'] as string | undefined, filename));
};
