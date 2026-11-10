import React, { useCallback, useEffect, useState } from 'react';
import { jwtDecode } from 'jwt-decode';

import { authSync } from '../api/authSync';
import { liveUpdates } from '../api/liveUpdates';
import { refreshAccessToken } from '../api/refresh';
import { sessionEvents } from '../api/sessionEvents';
import { tokenStorage } from '../api/tokenStorage';
import { authService } from '../features/auth/authService';
import { AuthContext } from './auth-context';
import { SessionRestriction, User } from '../types/auth';

interface AuthProviderProps {
  children: React.ReactNode;
}

const DEFAULT_IDLE_TIMEOUT_MINUTES = 30;
/** Cada cuánto se confirma con el servidor que la sesión sigue abierta. */
const SESSION_CHECK_MS = 30_000;

function getIdleTimeoutMs(): number {
  const configured = Number(import.meta.env.VITE_SESSION_IDLE_TIMEOUT_MINUTES ?? DEFAULT_IDLE_TIMEOUT_MINUTES);
  const minutes = Number.isFinite(configured) && configured > 0 ? configured : DEFAULT_IDLE_TIMEOUT_MINUTES;
  return minutes * 60_000;
}

interface TokenClaims extends User {
  pwd_change?: boolean;
  mfa_enroll?: boolean;
  mfa_due?: number;
  mfa_skipped?: boolean;
  mfa_can_skip?: boolean;
}

interface DecodedSession {
  user: User;
  /** Restricción según los claims del token (la contraseña va antes que el MFA). */
  restriction: SessionRestriction | null;
  mfaDue: number | null;
  mfaSkipped: boolean;
  mfaCanSkip: boolean;
}

function decodeAndValidate(token: string | null): DecodedSession | null {
  if (!token) return null;
  try {
    const decoded = jwtDecode<TokenClaims>(token);
    if (decoded.exp && decoded.exp * 1000 < Date.now()) return null;
    if (!decoded.sub || !decoded.role) return null;
    const restriction: SessionRestriction | null = decoded.pwd_change
      ? 'PASSWORD_CHANGE_REQUIRED'
      : decoded.mfa_enroll ? 'MFA_ENROLLMENT_REQUIRED' : null;
    return { user: { sub: decoded.sub, role: decoded.role, exp: decoded.exp ?? 0 }, restriction,
      mfaDue: typeof decoded.mfa_due === 'number' ? decoded.mfa_due : null,
      mfaSkipped: decoded.mfa_skipped === true, mfaCanSkip: decoded.mfa_can_skip === true };
  } catch {
    return null;
  }
}

export const AuthProvider = ({ children }: AuthProviderProps) => {
  const [user, setUser] = useState<User | null>(null);
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [restriction, setRestriction] = useState<SessionRestriction | null>(null);
  const [mfaDue, setMfaDue] = useState<number | null>(null);
  const [mfaSkipped, setMfaSkipped] = useState(false);
  const [mfaCanSkip, setMfaCanSkip] = useState(false);

  const applyToken = useCallback((accessToken: string | null, explicit?: SessionRestriction | null): boolean => {
    const decoded = decodeAndValidate(accessToken);

    if (decoded) {
      if (accessToken) tokenStorage.setAccessToken(accessToken);
      tokenStorage.startSession();
      setUser(decoded.user);
      setRestriction(explicit !== undefined ? explicit : decoded.restriction);
      setMfaDue(decoded.mfaDue);
      setMfaSkipped(decoded.mfaSkipped);
      setMfaCanSkip(decoded.mfaCanSkip);
      setIsAuthenticated(true);
      return true;
    }

    tokenStorage.clear();
    setUser(null);
    setRestriction(null);
    setMfaDue(null);
    setMfaSkipped(false);
    setMfaCanSkip(false);
    setIsAuthenticated(false);
    return false;
  }, []);

  const refreshSession = useCallback(async (): Promise<boolean> => {
    let token = tokenStorage.getAccessToken();

    if (!token) {
      if (!tokenStorage.hasActiveSession()) {
        // Pestaña nueva: adopta la sesión solo si otra pestaña la tiene activa.
        if (!(await authSync.askPeersForSession())) {
          return applyToken(null);
        }
        tokenStorage.startSession();
      }
      token = await refreshAccessToken();
    }

    return applyToken(token);
  }, [applyToken]);

  const renewSession = useCallback(async (): Promise<boolean> => {
    const token = await refreshAccessToken();
    return applyToken(token);
  }, [applyToken]);

  useEffect(() => {
    let mounted = true;

    Promise.resolve().then(refreshSession).finally(() => {
      if (mounted) setIsLoading(false);
    });

    return () => { mounted = false; };
  }, [refreshSession]);

  const login = useCallback((accessToken: string, nextRestriction?: SessionRestriction | null) => {
    applyToken(accessToken, nextRestriction);
  }, [applyToken]);

  const clearLocalSession = useCallback(() => {
    tokenStorage.clear();
    setUser(null);
    setRestriction(null);
    setIsAuthenticated(false);
  }, []);

  // 403 con código de restricción desde cualquier petición → pantalla de seguridad.
  useEffect(() => sessionEvents.onRestriction((next) => {
    if (tokenStorage.getAccessToken()) setRestriction(next);
  }), []);

  const logout = useCallback(() => {
    // Se captura el token ANTES de limpiar: la revocación en servidor viaja
    // con él (y con la cookie) aunque la memoria ya esté vacía.
    void authService.logout(tokenStorage.getAccessToken());
    authSync.announceLogout();
    clearLocalSession();
  }, [clearLocalSession]);

  const logoutAll = useCallback(async () => {
    await authService.logoutAll();
    authSync.announceLogout();
    clearLocalSession();
  }, [clearLocalSession]);

  // Sincronización entre pestañas: logout remoto y préstamo de sesión.
  useEffect(() => authSync.subscribe({
    onLogout: clearLocalSession,
    isAuthenticated: () => isAuthenticated,
  }), [clearLocalSession, isAuthenticated]);

  // Si un administrador cierra la sesión (o vence), la pantalla se entera sin esperar a
  // que la persona haga algo: chequeo liviano cada 30 s y al volver a la pestaña. Un 401
  // lo resuelve el interceptor (intenta renovar y, si no puede, lleva al inicio de sesión).
  useEffect(() => {
    if (!isAuthenticated) return undefined;
    const check = () => {
      if (document.visibilityState === 'visible') void authService.me().catch(() => undefined);
    };
    const intervalId = window.setInterval(check, SESSION_CHECK_MS);
    document.addEventListener('visibilitychange', check);
    window.addEventListener('focus', check);
    return () => {
      window.clearInterval(intervalId);
      document.removeEventListener('visibilitychange', check);
      window.removeEventListener('focus', check);
    };
  }, [isAuthenticated]);

  // Avisos en vivo: con la sesión abierta, escucha los cambios del sistema. Si el aviso es
  // para esta persona (sesión cerrada, rol o MFA cambiados), lo comprueba en el acto y
  // renueva el token para tomar los permisos nuevos; si la sesión ya no vale, sale.
  useEffect(() => {
    if (!isAuthenticated) return undefined;
    liveUpdates.start();
    const unsubscribe = liveUpdates.subscribe(({ resource }) => {
      if (resource !== 'me') return;
      void authService.me().then(() => renewSession()).catch(() => undefined);
    });
    return () => {
      unsubscribe();
      liveUpdates.stop();
    };
  }, [isAuthenticated, renewSession]);

  useEffect(() => {
    if (!isAuthenticated) return undefined;

    let timeoutId: number | undefined;
    const events = ['click', 'keydown', 'mousemove', 'scroll', 'touchstart', 'visibilitychange'];
    const resetIdleTimer = () => {
      if (timeoutId) window.clearTimeout(timeoutId);
      timeoutId = window.setTimeout(logout, getIdleTimeoutMs());
    };

    resetIdleTimer();
    events.forEach((eventName) => {
      window.addEventListener(eventName, resetIdleTimer, { passive: true });
    });

    return () => {
      if (timeoutId) window.clearTimeout(timeoutId);
      events.forEach((eventName) => {
        window.removeEventListener(eventName, resetIdleTimer);
      });
    };
  }, [isAuthenticated, logout]);

  return (
    <AuthContext.Provider value={{
      user, isAuthenticated, restriction, mfaDue, mfaSkipped, mfaCanSkip, login, refreshSession, renewSession, logout, logoutAll, isLoading,
    }}>
      {children}
    </AuthContext.Provider>
  );
};
