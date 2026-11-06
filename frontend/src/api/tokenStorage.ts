import { apiCache } from './cache';
/**
 * Almacenamiento centralizado de tokens.
 *
 * Decision de seguridad:
 * - El access token vive en memoria y se pierde al cerrar o recargar la pestana.
 * - El refresh token lo gestiona el backend en una cookie HttpOnly + SameSite.
 * - No persistimos tokens en localStorage/sessionStorage.
 * - sessionStorage solo guarda una marca no secreta de sesión activa por pestaña;
 *   sin esa marca no se intenta rehidratar la sesión usando la cookie.
 */

let accessTokenInMemory: string | null = null;
const SESSION_MARKER_KEY = 'iti_session_active';

function removeBrowserStorageItem(storageName: 'sessionStorage' | 'localStorage', key: string): void {
  try {
    globalThis[storageName]?.removeItem(key);
  } catch {
    return;
  }
}

export const tokenStorage = {
  getAccessToken(): string | null {
    return accessTokenInMemory;
  },

  setAccessToken(token: string | null): void {
    accessTokenInMemory = token;
  },

  startSession(): void {
    try {
      globalThis.sessionStorage?.setItem(SESSION_MARKER_KEY, '1');
    } catch {
      return;
    }
  },

  hasActiveSession(): boolean {
    try {
      return globalThis.sessionStorage?.getItem(SESSION_MARKER_KEY) === '1';
    } catch {
      return false;
    }
  },

  clear(): void {
    apiCache.clear();
    accessTokenInMemory = null;
    removeBrowserStorageItem('sessionStorage', SESSION_MARKER_KEY);
    removeBrowserStorageItem('sessionStorage', 'rtk');
    removeBrowserStorageItem('localStorage', 'token');
  },
};
