/** Tipos del módulo Directorio (Active Directory) y reglas de notificación. */

export interface SyncPendiente {
  persona_id: string;
  nombre: string;
  email: string;
  activos: number;
}

/** Baja que la sincronización no aplicó para no dejar el sistema sin administración. */
export interface SyncProtegido {
  persona_id: string;
  nombre: string;
  email: string;
  motivo: 'LAST_SUPER_ADMIN';
}

export interface SyncError {
  email: string | null;
  detalle: string;
}

export interface SyncResult {
  fecha: string;
  dry_run: boolean;
  total_ad: number;
  creados: number;
  actualizados: number;
  vinculados: number;
  desactivados: number;
  reactivados: number;
  jefes_asignados: number;
  pendientes_con_activos: SyncPendiente[];
  errores: SyncError[];
  /** Bajas retenidas por superar AD_SYNC_MAX_BAJAS_PCT o por un directorio vacío. */
  bajas_retenidas?: number;
  advertencia?: 'AD_SYNC_MASS_DEACTIVATION_BLOCKED' | null;
  protegidos?: SyncProtegido[];
}

export interface DirectoryStatus {
  enabled: boolean;
  configured: boolean;
  server: string | null;
  base_dn: string | null;
  admin_group: string | null;
  sync_interval_minutes: number;
  last_sync: SyncResult | null;
}

export interface ConnectionTestResult {
  ok: boolean;
  message: string;
  usuarios_encontrados: number;
}

export interface AdGroup {
  nombre: string;
  dn: string;
}

export const NOTIFICATION_EVENTS = [
  'asignacion',
  'devolucion',
  'transferencia',
  'baja',
  'offboarding',
  'mantenimiento_abierto',
  'mantenimiento_cerrado',
  'stock_bajo',
  'garantia_por_vencer',
  'recepcion_diferencias',
] as const;

export type NotificationEvent = (typeof NOTIFICATION_EVENTS)[number];

export interface NotificationRuleInput {
  activa: boolean;
  notificar_afectado: boolean;
  notificar_jefe: boolean;
  copiar_admins: boolean;
  grupos_ad: string[];
  correos_extra: string[];
}

export interface NotificationRule extends NotificationRuleInput {
  evento: string;
  personalizada: boolean;
}

export interface RecipientDetail {
  email: string;
  /** 'afectado' | 'jefe' | 'admins' | 'grupo:<nombre>' | 'extra' */
  origen: string;
}

export interface RecipientPreview {
  destinatarios: string[];
  detalle: RecipientDetail[];
}

/* ── Cuenta de servicio / integración de correo y AD (SUPER_ADMIN) ── */

export type IntegrationMode = 'DESACTIVADO' | 'CORREO' | 'CORREO_AD';
export type MailProvider = 'SMTP' | 'GRAPH';
export type SmtpSecurity = 'STARTTLS' | 'SSL' | 'NINGUNA';

/** Campos editables comunes a la lectura y a la actualización. */
export interface IntegrationEditable {
  modo: IntegrationMode;
  proveedor: MailProvider;
  cuenta_email: string | null;
  cuenta_usuario: string | null;
  nombre_remitente: string;
  smtp_host: string | null;
  smtp_puerto: number;
  smtp_seguridad: SmtpSecurity;
  graph_tenant_id: string | null;
  graph_client_id: string | null;
  ad_servidor: string | null;
  ad_base_dn: string | null;
  ad_grupo_base_dn: string | null;
  ad_grupo_admin: string | null;
  ad_start_tls: boolean;
  ad_verificar_cert: boolean;
  ad_intervalo_min: number;
  ad_desactivar: boolean;
  ad_usar_cuenta_servicio: boolean;
  ad_bind_usuario: string | null;
}

export interface IntegrationOut extends IntegrationEditable {
  /** 'entorno' = aún no se guardó nada desde la interfaz; los valores vienen de variables del servidor. */
  origen: 'interfaz' | 'entorno';
  password_configurada: boolean;
  graph_secret_configurado: boolean;
  ad_bind_password_configurada: boolean;
  /** Circuit breaker tras un fallo de autenticación (fechas ISO). */
  bloqueo: { smtp_hasta: string | null; ad_hasta: string | null };
  /** false = el servidor no tiene FIELD_ENCRYPTION_KEY (los secretos se guardarían sin cifrar). */
  cifrado_ok: boolean;
}

/**
 * Secretos: omitido o null = conservar el guardado; "" = borrarlo.
 */
export interface IntegrationUpdate extends IntegrationEditable {
  cuenta_password?: string | null;
  graph_client_secret?: string | null;
  ad_bind_password?: string | null;
}

export type MailTestCode =
  | 'OK'
  | 'AUTH_FAILED'
  | 'CONNECTION_FAILED'
  | 'TLS_FAILED'
  | 'SENDER_REJECTED'
  | 'NOT_CONFIGURED'
  | 'ERROR';

export type AdTestCode = 'OK' | 'AUTH_FAILED' | 'CONNECTION_FAILED' | 'NOT_CONFIGURED' | 'ERROR';

export interface MailTestResult {
  ok: boolean;
  codigo: MailTestCode;
  mensaje: string;
}

export interface AdTestResult {
  ok: boolean;
  codigo: AdTestCode;
  mensaje: string;
  usuarios_encontrados: number;
}
