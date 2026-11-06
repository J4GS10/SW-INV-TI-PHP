import { useAuth } from '../context/auth-context';
import { permissionsFor } from '../utils/roles';

/**
 * Qué puede hacer el usuario según su rol. La UI oculta las acciones que el
 * backend rechazaría (el backend siempre las valida igualmente).
 */
export function usePermissions() {
  const { user } = useAuth();
  return permissionsFor(user?.role);
}
