import { createContext, useContext } from 'react';

export interface SystemConfig {
  SYS_Configuracion: number;
  SYS_Nombre_Empresa: string;
  SYS_Logo_URL: string | null;
  SYS_Color_Primario: string;
  SYS_Color_Secundario: string;
  SYS_Color_Fondo: string;
  SYS_Idioma_Defecto: string;
}

// Paleta "Neutro Cálido" (misma que el primer preset de theme.ts y el default de la BD).
export const DEFAULT_CONFIG: SystemConfig = {
  SYS_Configuracion: 1,
  SYS_Nombre_Empresa: 'Inventario TI',
  SYS_Logo_URL: null,
  SYS_Color_Primario: '#4a7c9e',
  SYS_Color_Secundario: '#5a8e7a',
  SYS_Color_Fondo: '#f5f3ef',
  SYS_Idioma_Defecto: 'es',
};

export interface ConfigContextType {
  config: SystemConfig;
  isLoaded: boolean;
  reload: () => Promise<void>;
  /** Aplica una configuración ya guardada sin volver a pedirla (tema en vivo). */
  apply: (next: Partial<SystemConfig>) => void;
}

export const ConfigContext = createContext<ConfigContextType | null>(null);

export const useConfig = () => {
  const ctx = useContext(ConfigContext);
  if (!ctx) throw new Error('useConfig debe usarse dentro de un ConfigProvider');
  return ctx;
};
