import axios from 'axios';

import { tokenStorage } from './tokenStorage';

const baseURL = import.meta.env.VITE_API_URL ?? '/api/v1';

let inFlight: Promise<string | null> | null = null;

async function doRefresh(): Promise<string | null> {
  try {
    const resp = await axios.post(
      `${baseURL}/login/refresh`,
      {},
      { headers: { 'Content-Type': 'application/json' }, timeout: 15_000, withCredentials: true },
    );
    const token = resp.data?.access_token as string | undefined;
    if (token) {
      tokenStorage.setAccessToken(token);
      return token;
    }
    return null;
  } catch {
    return null;
  }
}

/**
 * Refresca el access token usando la cookie HttpOnly del refresh token.
 *
 * El backend ROTA el refresh token en cada uso y trata el reuso como robo, así
 * que dos refresh simultáneos con la misma cookie no deben ocurrir:
 * - dentro de la pestaña, una sola promesa en vuelo;
 * - entre pestañas, un Web Lock (`navigator.locks`) los serializa: la segunda
 *   pestaña espera y refresca después con la cookie ya rotada.
 */
export function refreshAccessToken(): Promise<string | null> {
  inFlight ??= (async () => {
    const locks = typeof navigator !== 'undefined' ? navigator.locks : undefined;
    if (locks?.request) {
      return locks.request('iti-auth-refresh', doRefresh);
    }
    return doRefresh();
  })().finally(() => {
    inFlight = null;
  });
  return inFlight;
}
