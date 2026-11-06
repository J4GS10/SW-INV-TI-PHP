import type { SessionRestriction } from '../types/auth';

/**
 * Canal mínimo entre el interceptor de axios (fuera de React) y el AuthContext:
 * cuando el backend responde 403 con un código de restricción, la sesión pasa a
 * la pantalla de seguridad correspondiente en vez de cerrarse.
 */
type Listener = (restriction: SessionRestriction) => void;

const listeners = new Set<Listener>();

export const sessionEvents = {
  emitRestriction(restriction: SessionRestriction): void {
    listeners.forEach((listener) => listener(restriction));
  },
  onRestriction(listener: Listener): () => void {
    listeners.add(listener);
    return () => { listeners.delete(listener); };
  },
};
