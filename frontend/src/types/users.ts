import { UserRole } from './auth';

// Interfaz completa de Persona (Tal como viene de /org/personas/disponibles)
export interface PersonaResumen {
  PER_Persona: string;
  PER_Primer_Nombre: string;
  PER_Segundo_Nombre: string | null;
  PER_Primer_Apellido: string;
  PER_Segundo_Apellido: string | null;
  PER_Email_Corporativo: string;
  PER_Telefono: string | null;
  PER_Estado: boolean;
  DEP_Departamento: number;
  CAR_Cargo: number;
  created_at: string; // Fecha ISO
  /** UUID de la persona jefe directo (sincronizado desde AD `manager`). */
  PER_Jefe?: string | null;
  /** Sede donde trabaja (alcance de datos). */
  SED_Sede?: number | null;
  /** objectGUID de AD; no nulo ⇒ la persona se sincroniza desde Active Directory. */
  PER_AD_GUID?: string | null;
  /** Fecha ISO de la última sincronización desde AD. */
  PER_AD_Sincronizado_En?: string | null;
}

export interface PersonaCombo {
  PER_Persona: string;
  PER_Primer_Nombre: string;
  PER_Primer_Apellido: string;
  PER_Email_Corporativo: string;
  /** Inactiva = ya salió de la organización (no recibe equipos ni reporta tickets). */
  PER_Estado?: boolean;
}

export type PersonaBackend = PersonaResumen;

// Usuario del sistema con su estado de seguridad (tal como viene de /org/usuarios)
export interface UsuarioSistema {
  USU_Usuario: string;
  USU_Username: string;
  USU_Rol: UserRole;
  USU_Estado: boolean;
  USU_SSO_Habilitado: boolean;
  USU_SSO_Provider?: 'microsoft' | 'google' | null;
  USU_2FA_Habilitado?: boolean;
  USU_2FA_Metodo?: 'TOTP' | 'EMAIL' | null;
  USU_Ultimo_Login: string | null;
  /** Contraseña temporal asignada por un administrador: debe cambiarla al iniciar sesión. */
  USU_Debe_Cambiar_Password?: boolean;
  /** Fecha ISO hasta la que la cuenta está bloqueada por intentos fallidos. */
  USU_Bloqueado_Hasta?: string | null;
  /** Bloqueo puesto por el administrador del sistema (vigente): motivo y fin (null = hasta que lo quite). */
  bloqueo_admin_motivo?: string | null;
  bloqueo_admin_hasta?: string | null;
  USU_Intentos_Fallidos?: number;
  USU_Password_Cambiada_En?: string | null;
  /** El rol exige MFA y la cuenta no usa SSO. */
  mfa_requerido?: boolean;
  /** Excepción temporal del MFA vigente: hasta cuándo y por qué. */
  mfa_exento_hasta?: string | null;
  mfa_exento_motivo?: string | null;
  /** Alcance global otorgado (solo lo concede un SUPER_ADMIN). */
  USU_Alcance_Global?: boolean;
  /** Dirección de la foto de perfil en la API; null si la cuenta no tiene. */
  foto_url?: string | null;
  /** Ve todas las sedes: por rol de gobierno o por alcance global. */
  alcance_global_efectivo?: boolean;
  sedes?: { SED_Sede: number; SED_Nombre: string }[];
  persona: PersonaResumen; // Objeto anidado obligatorio
}

export interface PoliticaSeguridad {
  mfa_roles_obligatorios: string[];
  password_longitud_minima: number;
  password_mayuscula: boolean;
  password_minuscula: boolean;
  password_digito: boolean;
  password_simbolo: boolean;
  bloqueo_intentos: number;
  bloqueo_minutos: number;
  bloqueo_progresivo_tope_minutos?: number;
  sesion_inactividad_minutos: number;
  sesion_maxima_horas: number;
  password_historial?: number;
  password_bloquea_comunes?: boolean;
  aviso_dispositivo_nuevo?: boolean;
  cuentas_inactivas_dias?: number;
  auditoria_retencion_dias?: number;
}

export interface ResumenSeguridad {
  usuarios_activos: number;
  usuarios_inactivos: number;
  con_mfa: number;
  /** Porcentaje 0-100 de usuarios activos con MFA. */
  cobertura_mfa: number;
  mfa_pendiente_obligatorio: number;
  bloqueados: number;
  cambio_password_pendiente: number;
  sesiones_activas?: number;
  usuarios_alcance_global?: number;
  /** Cuentas de inventario sin sedes ni alcance global: no ven datos. */
  usuarios_sin_alcance?: number;
  /** Las políticas VPD de Oracle están activas (segunda capa del alcance). */
  rls_base_datos?: boolean;
  politica: PoliticaSeguridad;
}

/** Sesión de inicio (dispositivo) de una cuenta. */
export interface SesionActiva {
  id: string;
  usuario_id: string;
  creada_en: string | null;
  ultima_actividad: string | null;
  expira: string | null;
  ip: string | null;
  dispositivo: string | null;
  metodo: 'password' | '2fa' | 'sso';
  actual: boolean;
  username?: string;
  rol?: UserRole;
  nombre?: string;
  gestionable?: boolean;
}

export interface UsuarioCreatePayload {
  PER_Persona: string;
  USU_Username: string;
  USU_Password?: string;
  USU_Rol: UserRole;
  USU_SSO_Habilitado?: boolean;
  USU_SSO_Provider?: 'microsoft' | 'google' | null;
  USU_Alcance_Global?: boolean;
  sedes?: number[];
}

export interface UsuarioUpdatePayload {
  USU_Rol?: UserRole;
  USU_Estado?: boolean;
  USU_SSO_Habilitado?: boolean;
  USU_SSO_Provider?: 'microsoft' | 'google' | null;
  USU_Alcance_Global?: boolean;
  sedes?: number[];
}
