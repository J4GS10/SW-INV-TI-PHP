import { createContext, useContext } from 'react';
import type { TemaUsuario } from '../theme/theme';

export interface ThemeModeContextType {
  /** Preferencia elegida (claro, oscuro o la del sistema operativo). */
  tema: TemaUsuario;
  /** Modo que se está mostrando, ya resuelto «sistema». */
  oscuro: boolean;
  setTema: (tema: TemaUsuario) => void;
}

export const ThemeModeContext = createContext<ThemeModeContextType | null>(null);

export const useThemeMode = () => {
  const context = useContext(ThemeModeContext);
  if (!context) throw new Error('useThemeMode debe usarse dentro de ThemedApp');
  return context;
};

// Último tema usado en este navegador: se aplica antes del login (sin parpadeo).
// La preferencia real vive en el servidor (/me/preferencias).
const CLAVE = 'iti_tema';

export const leerTemaLocal = (): TemaUsuario => {
  try {
    const v = localStorage.getItem(CLAVE);
    return v === 'claro' || v === 'oscuro' || v === 'sistema' ? v : 'sistema';
  } catch {
    return 'sistema';
  }
};

export const guardarTemaLocal = (tema: TemaUsuario) => {
  try { localStorage.setItem(CLAVE, tema); } catch { /* sin storage */ }
};
