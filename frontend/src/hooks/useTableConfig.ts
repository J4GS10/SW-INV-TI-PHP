import { useCallback, useState } from 'react';

export type Densidad = 'compact' | 'comfortable';
interface Config { ocultas: string[]; densidad: Densidad }

const leer = (clave: string): Config => {
  try {
    const raw = localStorage.getItem(clave);
    if (raw) {
      const c = JSON.parse(raw) as Partial<Config>;
      return { ocultas: Array.isArray(c.ocultas) ? c.ocultas : [], densidad: c.densidad === 'comfortable' ? 'comfortable' : 'compact' };
    }
  } catch { /* sin almacenamiento: valores por defecto */ }
  return { ocultas: [], densidad: 'compact' };
};

/**
 * Preferencias de una tabla (columnas visibles y densidad) por usuario y
 * página, guardadas en este navegador.
 */
export function useTableConfig(pagina: string, usuario?: string) {
  const clave = `tabla:${usuario ?? 'anon'}:${pagina}`;
  const [config, setConfig] = useState<Config>(() => leer(clave));
  const guardar = useCallback((c: Config) => {
    setConfig(c);
    try { localStorage.setItem(clave, JSON.stringify(c)); } catch { /* no se recuerda */ }
  }, [clave]);
  const alternarColumna = (col: string) => guardar({
    ...config, ocultas: config.ocultas.includes(col) ? config.ocultas.filter((c) => c !== col) : [...config.ocultas, col],
  });
  return {
    ocultas: new Set(config.ocultas),
    densidad: config.densidad,
    alternarColumna,
    cambiarDensidad: (densidad: Densidad) => guardar({ ...config, densidad }),
    restablecer: () => guardar({ ocultas: [], densidad: 'compact' }),
  };
}
