import type { TFunction } from 'i18next';
import { parseApiDate } from '../../../utils/dates';
import { isValidEmail } from '../directoryUtils';
import type { IntegrationMode, IntegrationOut, IntegrationUpdate, MailProvider, SmtpSecurity } from '../types';

/* Estado del formulario de integración de correo y directorio, y sus reglas (sin React). */

export const MODES: IntegrationMode[] = ['DESACTIVADO', 'CORREO', 'CORREO_AD'];
export const SECURITY_OPTIONS: SmtpSecurity[] = ['STARTTLS', 'SSL', 'NINGUNA'];

export type Preset = 'graph' | 'm365_smtp' | 'exchange' | 'google' | 'custom';
export const PRESETS: Preset[] = ['graph', 'm365_smtp', 'exchange', 'google', 'custom'];

/** Valores de texto tal como se editan (los números se guardan como string para no pelear con el input). */
export interface FormState {
  modo: IntegrationMode;
  proveedor: MailProvider;
  cuenta_email: string;
  cuenta_usuario: string;
  nombre_remitente: string;
  smtp_host: string;
  smtp_puerto: string;
  smtp_seguridad: SmtpSecurity;
  graph_tenant_id: string;
  graph_client_id: string;
  ad_servidor: string;
  ad_base_dn: string;
  ad_grupo_base_dn: string;
  ad_grupo_admin: string;
  ad_start_tls: boolean;
  ad_verificar_cert: boolean;
  ad_intervalo_min: string;
  ad_desactivar: boolean;
  ad_usar_cuenta_servicio: boolean;
  ad_bind_usuario: string;
}

/** Secreto en edición: `value` vacío = conservar el guardado; `clear` = borrarlo al guardar. */
export interface SecretState {
  value: string;
  clear: boolean;
}

export type SecretKey = 'cuenta_password' | 'graph_client_secret' | 'ad_bind_password';
export type Secrets = Record<SecretKey, SecretState>;

export const EMPTY_SECRETS: Secrets = {
  cuenta_password: { value: '', clear: false },
  graph_client_secret: { value: '', clear: false },
  ad_bind_password: { value: '', clear: false },
};

/** Cambia un campo del formulario: lo comparten todas las secciones. */
export type SetField = <K extends keyof FormState>(key: K, value: FormState[K]) => void;

export const toForm = (c: IntegrationOut): FormState => ({
  modo: c.modo,
  proveedor: c.proveedor,
  cuenta_email: c.cuenta_email ?? '',
  cuenta_usuario: c.cuenta_usuario ?? '',
  nombre_remitente: c.nombre_remitente ?? '',
  smtp_host: c.smtp_host ?? '',
  smtp_puerto: String(c.smtp_puerto ?? ''),
  smtp_seguridad: c.smtp_seguridad,
  graph_tenant_id: c.graph_tenant_id ?? '',
  graph_client_id: c.graph_client_id ?? '',
  ad_servidor: c.ad_servidor ?? '',
  ad_base_dn: c.ad_base_dn ?? '',
  ad_grupo_base_dn: c.ad_grupo_base_dn ?? '',
  ad_grupo_admin: c.ad_grupo_admin ?? '',
  ad_start_tls: c.ad_start_tls,
  ad_verificar_cert: c.ad_verificar_cert,
  ad_intervalo_min: String(c.ad_intervalo_min ?? 0),
  ad_desactivar: c.ad_desactivar,
  ad_usar_cuenta_servicio: c.ad_usar_cuenta_servicio,
  ad_bind_usuario: c.ad_bind_usuario ?? '',
});

const nullable = (v: string): string | null => (v.trim() === '' ? null : v.trim());

/** Omitido = conservar; "" = borrar; texto = reemplazar. */
const secretPayload = (s: SecretState): string | undefined => (s.clear ? '' : s.value !== '' ? s.value : undefined);

export const toPayload = (f: FormState, s: Secrets): IntegrationUpdate => {
  const payload: IntegrationUpdate = {
    modo: f.modo,
    proveedor: f.proveedor,
    cuenta_email: nullable(f.cuenta_email),
    cuenta_usuario: nullable(f.cuenta_usuario),
    nombre_remitente: f.nombre_remitente.trim(),
    smtp_host: nullable(f.smtp_host),
    smtp_puerto: Number(f.smtp_puerto),
    smtp_seguridad: f.smtp_seguridad,
    graph_tenant_id: nullable(f.graph_tenant_id),
    graph_client_id: nullable(f.graph_client_id),
    ad_servidor: nullable(f.ad_servidor),
    ad_base_dn: nullable(f.ad_base_dn),
    ad_grupo_base_dn: nullable(f.ad_grupo_base_dn),
    ad_grupo_admin: nullable(f.ad_grupo_admin),
    ad_start_tls: f.ad_start_tls,
    ad_verificar_cert: f.ad_verificar_cert,
    ad_intervalo_min: Number(f.ad_intervalo_min),
    ad_desactivar: f.ad_desactivar,
    ad_usar_cuenta_servicio: f.ad_usar_cuenta_servicio,
    ad_bind_usuario: nullable(f.ad_bind_usuario),
  };
  (Object.keys(s) as SecretKey[]).forEach((k) => {
    const v = secretPayload(s[k]);
    if (v !== undefined) payload[k] = v;
  });
  return payload;
};

export const detectPreset = (c: IntegrationOut): Preset => {
  if (c.proveedor === 'GRAPH') return 'graph';
  const host = (c.smtp_host ?? '').toLowerCase();
  if (host === 'smtp.office365.com') return 'm365_smtp';
  if (host === 'smtp.gmail.com') return 'google';
  return 'custom';
};

/** Lo que cada proveedor predefinido rellena en el formulario. */
export const withPreset = (prev: FormState, p: Preset): FormState => {
  switch (p) {
    case 'graph':
      return { ...prev, proveedor: 'GRAPH' };
    case 'm365_smtp':
      return { ...prev, proveedor: 'SMTP', smtp_host: 'smtp.office365.com', smtp_puerto: '587', smtp_seguridad: 'STARTTLS' };
    case 'exchange':
      return { ...prev, proveedor: 'SMTP', smtp_host: '', smtp_puerto: '587', smtp_seguridad: 'STARTTLS' };
    case 'google':
      return { ...prev, proveedor: 'SMTP', smtp_host: 'smtp.gmail.com', smtp_puerto: '587', smtp_seguridad: 'STARTTLS' };
    default:
      return { ...prev, proveedor: prev.proveedor };
  }
};

// El servidor envía UTC sin zona: parseApiDate lo interpreta como UTC.
export const isFuture = (iso: string | null): iso is string => !!iso && (parseApiDate(iso)?.getTime() ?? 0) > Date.now();

export const formatTime = (iso: string) =>
  (parseApiDate(iso) ?? new Date(iso)).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

/* ─────────────────────────── Validación ─────────────────────────── */

export type Errors = Partial<Record<keyof FormState | SecretKey, string>>;

export interface Validation {
  errors: Errors;
  mailValid: boolean;
  adValid: boolean;
}

export const validate = (
  f: FormState,
  s: Secrets,
  cfg: IntegrationOut,
  t: TFunction,
): Validation => {
  const mail: Errors = {};
  const ad: Errors = {};
  if (f.modo === 'DESACTIVADO') return { errors: {}, mailValid: true, adValid: true };

  const hasSecret = (k: SecretKey, configured: boolean) => !s[k].clear && (s[k].value !== '' || configured);
  const accountPasswordOk = hasSecret('cuenta_password', cfg.password_configurada);
  const req = t('common.required');

  if (!f.cuenta_email.trim()) mail.cuenta_email = req;
  else if (!isValidEmail(f.cuenta_email)) mail.cuenta_email = t('directory.integration.errors.email');

  if (f.proveedor === 'SMTP') {
    if (!f.smtp_host.trim()) mail.smtp_host = req;
    const port = Number(f.smtp_puerto);
    if (!/^\d+$/.test(f.smtp_puerto.trim()) || port < 1 || port > 65535) {
      mail.smtp_puerto = t('directory.integration.errors.port');
    }
    // Sin clave es válido: relay SMTP autorizado por IP (Exchange local).
  } else {
    if (!f.graph_tenant_id.trim()) mail.graph_tenant_id = req;
    if (!f.graph_client_id.trim()) mail.graph_client_id = req;
    if (!hasSecret('graph_client_secret', cfg.graph_secret_configurado)) {
      mail.graph_client_secret = t('directory.integration.errors.password_required');
    }
  }

  if (f.modo === 'CORREO_AD') {
    if (!f.ad_servidor.trim()) ad.ad_servidor = req;
    else if (!/^ldaps?:\/\/\S+$/i.test(f.ad_servidor.trim())) ad.ad_servidor = t('directory.integration.errors.ldap_url');
    if (!f.ad_base_dn.trim()) ad.ad_base_dn = req;
    if (!/^\d+$/.test(f.ad_intervalo_min.trim())) ad.ad_intervalo_min = t('directory.integration.errors.interval');
    if (f.ad_usar_cuenta_servicio) {
      if (!accountPasswordOk) ad.cuenta_password = t('directory.integration.errors.password_required');
    } else {
      if (!f.ad_bind_usuario.trim()) ad.ad_bind_usuario = req;
      if (!hasSecret('ad_bind_password', cfg.ad_bind_password_configurada)) {
        ad.ad_bind_password = t('directory.integration.errors.password_required');
      }
    }
  }

  return {
    errors: { ...ad, ...mail },
    mailValid: Object.keys(mail).length === 0,
    adValid: f.modo !== 'CORREO_AD' || Object.keys(ad).length === 0,
  };
};
