import React, { useCallback, useEffect, useState } from 'react';
import client from '../api/axios';
import { ConfigContext, DEFAULT_CONFIG } from './config-context';
import type { SystemConfig } from './config-context';

interface Props {
  children: React.ReactNode;
}

// Última configuración válida. Si /gov/config falla (red, 429, reinicio del
// backend) la app conserva su marca y paleta en vez de saltar a los defaults.
const CACHE_KEY = 'iti_system_config';

const readCache = (): SystemConfig | null => {
  try {
    const raw = localStorage.getItem(CACHE_KEY);
    return raw ? { ...DEFAULT_CONFIG, ...JSON.parse(raw) } : null;
  } catch {
    return null;
  }
};

const writeCache = (config: SystemConfig) => {
  try {
    localStorage.setItem(CACHE_KEY, JSON.stringify(config));
  } catch {
    /* sin storage: solo se pierde la caché */
  }
};

export const ConfigProvider = ({ children }: Props) => {
  const cached = readCache();
  const [config, setConfig] = useState<SystemConfig>(cached ?? DEFAULT_CONFIG);
  // Con caché se pinta de inmediato (sin spinner ni parpadeo de colores).
  const [isLoaded, setIsLoaded] = useState(cached !== null);

  const load = useCallback(async () => {
    try {
      const { data } = await client.get<SystemConfig>('/gov/config');
      const next = { ...DEFAULT_CONFIG, ...data };
      setConfig(next);
      writeCache(next);
    } catch {
      setConfig((current) => current);
    } finally {
      setIsLoaded(true);
    }
  }, []);

  const apply = useCallback((next: Partial<SystemConfig>) => {
    setConfig((current) => {
      const merged = { ...current, ...next };
      writeCache(merged);
      return merged;
    });
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  return (
    <ConfigContext.Provider value={{ config, isLoaded, reload: load, apply }}>
      {children}
    </ConfigContext.Provider>
  );
};
