export type UserRole = 'SUPER_ADMIN' | 'ADMIN_SEGURIDAD' | 'ADMIN_TI' | 'TECNICO' | 'AUDITOR' | 'CONSULTA'
  | 'COMPRAS';

/**
 * Paso de seguridad pendiente. Mientras exista, el backend responde 403 con este
 * código en todo salvo el autoservicio (/me, /me/password, /me/2fa/*, logout).
 */
export type SessionRestriction = 'PASSWORD_CHANGE_REQUIRED' | 'MFA_ENROLLMENT_REQUIRED';

export const SESSION_RESTRICTIONS: readonly SessionRestriction[] = ['PASSWORD_CHANGE_REQUIRED', 'MFA_ENROLLMENT_REQUIRED'];

export const isSessionRestriction = (value: unknown): value is SessionRestriction =>
  typeof value === 'string' && (SESSION_RESTRICTIONS as readonly string[]).includes(value);

export interface User {
  sub: string;
  role: UserRole;
  exp: number;
}

export interface AuthResponse {
  access_token: string;
  token_type: string;
  expires_in?: number;
  restriction?: SessionRestriction | null;
}
