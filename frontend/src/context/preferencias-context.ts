import { createContext, useContext } from 'react';
import type { TemaUsuario } from '../theme/theme';

export interface Preferencias {
  tema: TemaUsuario;
  guias_desactivadas: boolean;
  guias_vistas: string[];
}

export interface PreferenciasContextType {
  preferencias: Preferencias;
  /** true cuando ya llegaron del servidor (antes no se abren guías solas). */
  cargadas: boolean;
  cambiarTema: (tema: TemaUsuario) => void;
  /** ¿Debe abrirse sola la guía de este módulo? */
  mostrarGuia: (modulo: string) => boolean;
  marcarGuiaVista: (modulo: string) => void;
  desactivarGuias: (desactivar: boolean) => void;
}

export const PREFERENCIAS_POR_DEFECTO: Preferencias = { tema: 'sistema', guias_desactivadas: false, guias_vistas: [] };

export const PreferenciasContext = createContext<PreferenciasContextType | null>(null);

export const usePreferencias = () => {
  const context = useContext(PreferenciasContext);
  if (!context) throw new Error('usePreferencias debe usarse dentro de PreferenciasProvider');
  return context;
};
