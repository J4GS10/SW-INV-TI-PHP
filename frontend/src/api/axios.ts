import axios, { AxiosError, AxiosRequestConfig, InternalAxiosRequestConfig } from 'axios';

import { isSessionRestriction } from '../types/auth';

import { apiCache, GET_TTL_MS } from './cache';
import { CLIENT_ID } from './liveUpdates';
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
// LECTURAS EN CACHÉ: volver a una pantalla muestra sus datos al instante.
// Toda escritura y todo aviso en vivo vacían la caché (ver cache.ts). Quedan
// fuera la sesión (/me, /login) y las descargas (archivos, imágenes).
// =========================================================================
const sendRequest = axios.getAdapter(client.defaults.adapter);
const cacheable = (config: InternalAxiosRequestConfig): boolean => {
  if ((config.method ?? 'get').toLowerCase() !== 'get') return false;
  if (config.responseType && config.responseType !== 'json') return false;
  // Una lectura que se puede cancelar (búsqueda mientras se escribe) no se comparte.
  if (config.signal) return false;
  const url = config.url ?? '';
  return !/^\/?(me|login)(\/|\?|$)/.test(url);
};
// =========================================================================
// ESCRITURAS UNA SOLA VEZ: dos clics iguales mientras la primera sigue en curso
// comparten la misma petición (el servidor recibe una). El servidor, además,
// no repite nada con la misma Idempotency-Key (ver el interceptor de abajo).
// =========================================================================
const inFlightWrites = new Map<string, ReturnType<typeof sendRequest>>();
const sendWrite = (config: InternalAxiosRequestConfig) => {
  // Un archivo (FormData) no se compara: cada subida es la que eligió la persona.
  if (config.data !== undefined && typeof config.data !== 'string') return sendRequest(config);
  const key = `${(config.method ?? '').toUpperCase()} ${client.getUri(config)} ${String(config.headers.Authorization ?? '')} ${config.data ?? ''}`;
  const running = inFlightWrites.get(key);
  if (running) return running;
  const request = sendRequest(config).finally(() => inFlightWrites.delete(key));
  inFlightWrites.set(key, request);
  return request;
};

client.defaults.adapter = (config) => {
  if ((config.method ?? 'get').toLowerCase() !== 'get') return sendWrite(config);
  if (!cacheable(config)) return sendRequest(config);
  // La clave lleva el token: otra cuenta en la misma pestaña nunca ve lo que leyó la anterior.
  const key = `GET ${client.getUri(config)} ${String(config.headers.Authorization ?? '')}`;
  return apiCache.get(key, () => sendRequest(config), GET_TTL_MS)
    // Cada pantalla recibe su propia copia: si la modifica, no altera la guardada.
    .then((response) => ({ ...response, config, data: structuredClone(response.data) }));
};

// =========================================================================
// REQUEST: inyectar Authorization
// =========================================================================
client.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = tokenStorage.getAccessToken();
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  // La pestaña que escribe ya se actualizó: el aviso en vivo de su propio cambio se ignora.
  config.headers['X-Client-Id'] = CLIENT_ID;
  // Toda escritura lleva su clave de idempotencia; un reintento conserva la misma (va en config).
  if ((config.method ?? 'get').toLowerCase() !== 'get' && !config.headers[IDEMPOTENCY_HEADER]) {
    config.headers[IDEMPOTENCY_HEADER] = newIdempotencyKey();
  }
  return config;
});

const IDEMPOTENCY_HEADER = 'Idempotency-Key';
/** 32 caracteres hexadecimales al azar (el servidor acepta [A-Za-z0-9_-]{16,128}). */
const newIdempotencyKey = (): string => {
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
};
/** Reintentos de una escritura cortada o aún en proceso en el servidor (con la misma clave, nunca se aplica dos veces). */
const WRITE_RETRIES = 3;

/** Marca para que la pantalla de acceso avise que la sesión terminó (cerrada por un administrador o vencida). */
export const SESSION_ENDED_FLAG = 'inv_sesion_terminada';

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
    const original = error.config as (AxiosRequestConfig & { _retried?: boolean; _netRetried?: boolean; _writeRetries?: number }) | undefined;

    // Escritura cortada (Wi-Fi, reinicio de la API, tiempo agotado) o todavía en proceso en el servidor:
    // se repite con la MISMA Idempotency-Key. El servidor devuelve el resultado guardado, avisa que
    // sigue en curso o la ejecuta si nunca llegó; jamás la aplica dos veces.
    const errorDetail = (error.response?.data as { detail?: unknown } | undefined)?.detail;
    const cut = !error.response && error.code !== 'ERR_CANCELED';
    const stillRunning = error.response?.status === 409 && errorDetail === 'IDEMPOTENCY_REQUEST_IN_PROGRESS' && (original?._writeRetries ?? 0) > 0;
    if (original && (original.method ?? 'get').toLowerCase() !== 'get' && original.headers?.[IDEMPOTENCY_HEADER]
      && !original.url?.includes('/login/') && (cut || stillRunning) && (original._writeRetries ?? 0) < WRITE_RETRIES) {
      original._writeRetries = (original._writeRetries ?? 0) + 1;
      await new Promise((resolve) => setTimeout(resolve, 700 * original._writeRetries!));
      return client.request(original);
    }

    // Conexión cortada sin respuesta (Wi-Fi, reinicio de la API): una lectura se repite una
    // vez sola. Las escrituras no: el servidor pudo haberlas aplicado.
    if (original && !error.response && error.code !== 'ECONNABORTED' && error.code !== 'ERR_CANCELED'
      && (original.method ?? 'get').toLowerCase() === 'get' && !original._netRetried) {
      original._netRetried = true;
      await new Promise((resolve) => setTimeout(resolve, 500));
      return client.request(original);
    }

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

    // No se pudo refrescar → cerrar sesión (la pantalla de acceso explica por qué).
    tokenStorage.clear();
    if (typeof window !== 'undefined' && !window.location.pathname.endsWith('/login')) {
      try { window.sessionStorage.setItem(SESSION_ENDED_FLAG, '1'); } catch { /* sin almacenamiento: solo no se muestra el aviso */ }
      window.location.href = '/login';
    }
    return Promise.reject(error);
  },
);

export default client;
