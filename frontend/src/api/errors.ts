import { isAxiosError } from 'axios';

import i18n from '../i18n';

interface ApiErrorBody {
  detail?: unknown;
}

/** Los códigos de error del backend se traducen con las claves `api_errors.<CÓDIGO>` de los locales. */
const API_ERROR_CODE = /^[A-Z0-9_]+$/;

export function getApiErrorDetail(error: unknown): string | undefined {
  if (!isAxiosError<ApiErrorBody>(error)) return undefined;
  const detail = error.response?.data?.detail;
  return typeof detail === 'string' ? detail : undefined;
}

/** Código del error sin sus parámetros (`RECEIPT_EXCEEDS_PENDING:12:1` → `RECEIPT_EXCEEDS_PENDING`). */
export function getApiErrorCode(error: unknown): string | undefined {
  return getApiErrorDetail(error)?.split(':')[0];
}

/** Parámetros del código (`RECEIPT_EXCEEDS_PENDING:12:1` → `['12', '1']`). */
export function getApiErrorParams(error: unknown): string[] {
  const detail = getApiErrorDetail(error);
  return detail ? detail.split(':').slice(1) : [];
}

/**
 * Errores sin código del backend: el proxy (413 de nginx), el límite de
 * peticiones o la red. Se traducen igual que los códigos del backend.
 */
function codeFromStatus(error: unknown): string | undefined {
  if (!isAxiosError(error)) return undefined;
  if (!error.response) return error.code === 'ECONNABORTED' ? 'REQUEST_TIMEOUT' : 'NETWORK_ERROR';
  if (error.response.status === 413) return 'FILE_TOO_LARGE';
  if (error.response.status === 429) return 'RATE_LIMIT_EXCEEDED';
  if (error.response.status === 403) return 'FORBIDDEN';
  return undefined;
}

/** Errores de validación del backend, por campo: `{ SYS_Logo_URL: 'Input should be…' }`. */
export function getApiFieldErrors(error: unknown): Record<string, string> {
  if (!isAxiosError<ApiErrorBody>(error)) return {};
  const detail = error.response?.data?.detail;
  if (!Array.isArray(detail)) return {};
  const fields: Record<string, string> = {};
  for (const item of detail as { loc?: unknown[]; msg?: string }[]) {
    const name = item.loc?.[item.loc.length - 1];
    if (typeof name === 'string') fields[name] = item.msg ?? '';
  }
  return fields;
}

export function getApiErrorMessage(error: unknown, fallback: string): string {
  const detail = getApiErrorDetail(error);
  if (!detail) {
    // Un 422 sin texto no ayuda: se dice qué dato hay que revisar.
    const invalid = Object.keys(getApiFieldErrors(error));
    if (invalid.length > 0) {
      const names = invalid.map((name) => (i18n.exists(`field_names.${name}`) ? i18n.t(`field_names.${name}`) : name));
      return i18n.t('api_errors.CHECK_FIELDS', { fields: names.join(', ') });
    }
    const code = codeFromStatus(error);
    return code && i18n.exists(`api_errors.${code}`) ? i18n.t(`api_errors.${code}`) : fallback;
  }
  // Algunos códigos traen parámetros: `PASSWORD_POLICY_VIOLATION:min_length:12,require_symbol`.
  const [code, ...params] = detail.split(':');
  const key = `api_errors.${code}`;
  if (code === 'PASSWORD_POLICY_VIOLATION' && i18n.exists(key)) {
    // Motivos concretos (palabra común, datos personales, secuencias…).
    const reasons = detail.slice(code.length + 1).split(',')
      .map((part) => part.split(':')[0])
      .filter((rule) => i18n.exists(`password_violations.${rule}`))
      .map((rule) => i18n.t(`password_violations.${rule}`));
    return [i18n.t(key), ...reasons].join(' ');
  }
  // Operación en lote: `BATCH_ITEM_FAILED:<código del activo>:<motivo>` (nada se aplicó).
  if (code === 'BATCH_ITEM_FAILED' || code === 'IMPORT_ROW_FAILED') {
    const [item, ...rest] = params;
    const reasonCode = rest.join(':').split(':')[0];
    const reason = i18n.exists(`api_errors.${reasonCode}`) ? i18n.t(`api_errors.${reasonCode}`) : fallback;
    return i18n.t(`api_errors.${code}`, { item, reason });
  }
  // The text only exists in plural forms, so the check needs the count too.
  if (code === 'PASSWORD_REUSED') {
    const count = Number(params[0]) || 3;
    if (i18n.exists(key, { count })) return i18n.t(key, { count });
  }
  if (API_ERROR_CODE.test(code) && i18n.exists(key)) {
    // Parámetros posicionales disponibles como {{p1}}, {{p2}}… y {{count}}.
    const values: Record<string, string | number> = {};
    params.forEach((value, index) => { values[`p${index + 1}`] = value; });
    if (params.length && !Number.isNaN(Number(params[params.length - 1]))) {
      values.count = Number(params[params.length - 1]);
    }
    return i18n.t(key, values);
  }
  // Un código técnico sin traducción no debe mostrarse al usuario.
  return API_ERROR_CODE.test(code) ? fallback : detail;
}
