import { createContext, useContext } from 'react';

import { SessionRestriction, User } from '../types/auth';

export interface AuthContextType {
  user: User | null;
  isAuthenticated: boolean;
  /**
   * Paso de seguridad pendiente (contraseña temporal o MFA obligatorio). Mientras
   * exista, la app muestra la pantalla correspondiente en lugar del sistema.
   */
  restriction: SessionRestriction | null;
  /** Aplica un access token recién emitido (login, 2FA). */
  login: (accessToken: string, restriction?: SessionRestriction | null) => void;
  refreshSession: () => Promise<boolean>;
  /** Fuerza un refresh (p. ej. tras activar MFA, para obtener una sesión sin restricciones). */
  renewSession: () => Promise<boolean>;
  logout: () => void;
  /** Cierra la sesión en todos los dispositivos (revocación global). */
  logoutAll: () => Promise<void>;
  isLoading: boolean;
}

export const AuthContext = createContext<AuthContextType | null>(null);

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) throw new Error('useAuth debe usarse dentro de un AuthProvider');
  return context;
};
