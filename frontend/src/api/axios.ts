import axios, { AxiosError, AxiosRequestConfig, InternalAxiosRequestConfig } from 'axios';

import { isSessionRestriction } from '../types/auth';

import { apiCache } from './cache';
import { refreshAccessToken } from './refresh';
import { sessionEvents } from './sessionEvents';
import { tokenStorage } from './tokenStorage';

const client = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? '/api/v1',
  // NO fijamos Content-Type global: axios pone application/json para cuerpos
  // objeto y multipart/form-data (con boundary) para FormData. Un default JSON
  // rompía las subidas de adjuntos (FormData → 422 por falta de boundary).
  // Timeout para evitar requests colgados.
  timeout: 30_000,
  // Envía la cookie HttpOnly del refresh token (rtk) en login/refresh/logout.
  withCredentials: true,
});

// =========================================================================
// REQUEST: inyectar Authorization
// =========================================================================
client.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = tokenStorage.getAccessToken();
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// =========================================================================
// RESPONSE: refresh transparente + manejo de 401
// =========================================================================
client.interceptors.response.use(
  (response) => {
    // Toda escritura invalida las lecturas en caché (catálogos, áreas…).
    if ((response.config.method ?? 'get').toLowerCase() !== 'get') apiCache.clear();
    return response;
  },
  async (error: AxiosError) => {
    const original = error.config as (AxiosRequestConfig & { _retried?: boolean }) | undefined;

    // 403 por paso de seguridad pendiente (contraseña temporal / MFA obligatorio):
    // la sesión sigue viva; el AuthContext muestra la pantalla correspondiente.
    const detail = (error.response?.data as { detail?: unknown } | undefined)?.detail;
    if (error.response?.status === 403 && isSessionRestriction(detail)) {
      sessionEvents.emitRestriction(detail);
      return Promise.reject(error);
    }

    if (!original || error.response?.status !== 401) {
      return Promise.reject(error);
    }

    // No reintentes endpoints de login para evitar loops.
    if (original.url?.includes('/login/')) {
      return Promise.reject(error);
    }

    // Refresh coordinado (una promesa por pestaña + Web Lock entre pestañas).
    if (!original._retried && tokenStorage.hasActiveSession()) {
      original._retried = true;
      const newToken = await refreshAccessToken();
      if (newToken) {
        original.headers = { ...(original.headers ?? {}), Authorization: `Bearer ${newToken}` };
        return client.request(original);
      }
    }

    // No se pudo refrescar → cerrar sesión.
    tokenStorage.clear();
    if (typeof window !== 'undefined' && !window.location.pathname.endsWith('/login')) {
      window.location.href = '/login';
    }
    return Promise.reject(error);
  },
);

export default client;
