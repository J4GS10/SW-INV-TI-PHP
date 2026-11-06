import React, { useCallback, useEffect, useMemo, useState } from 'react';
import client from '../api/axios';
import { useAuth } from './auth-context';
import { useThemeMode } from './theme-mode-context';
import { PREFERENCIAS_POR_DEFECTO, PreferenciasContext } from './preferencias-context';
import type { Preferencias } from './preferencias-context';
import type { TemaUsuario } from '../theme/theme';

/** Guías marcadas como vistas en versiones anteriores (solo en este navegador). */
const guiasLocales = (usuario: string | undefined): string[] => {
  const prefijo = `ayuda:${usuario ?? 'anon'}:`;
  try {
    return Object.keys(localStorage).filter((k) => k.startsWith(prefijo) && localStorage.getItem(k) === '1')
      .map((k) => k.slice(prefijo.length));
  } catch {
    return [];
  }
};

/**
 * Preferencias de interfaz del usuario (tema y guías), guardadas en el servidor
 * para que lo sigan a cualquier equipo. Los cambios se aplican al instante y
 * se envían en segundo plano; si el envío falla, se conservan en esta sesión.
 */
export const PreferenciasProvider = ({ children }: { children: React.ReactNode }) => {
  const { user, isAuthenticated, restriction } = useAuth();
  const { setTema } = useThemeMode();
  const [preferencias, setPreferencias] = useState<Preferencias>(PREFERENCIAS_POR_DEFECTO);
  // Usuario cuyas preferencias ya llegaron: «cargadas» se deriva (sin setState síncrono en el efecto).
  const [cargadasDe, setCargadasDe] = useState<string | null>(null);
  const activo = isAuthenticated && !restriction;
  const cargadas = activo && cargadasDe === (user?.sub ?? null);

  useEffect(() => {
    if (!activo) return undefined;
    let vivo = true;
    client.get<Preferencias>('/me/preferencias')
      .then(async ({ data }) => {
        // Se trasladan al servidor las guías ya vistas en este navegador.
        const pendientes = guiasLocales(user?.sub).filter((g) => !data.guias_vistas.includes(g));
        let prefs = data;
        if (pendientes.length) {
          prefs = (await client.patch<Preferencias>('/me/preferencias',
            { guias_vistas: [...data.guias_vistas, ...pendientes].slice(0, 50) })).data;
        }
        if (!vivo) return;
        setPreferencias(prefs);
        setTema(prefs.tema);
      })
      .catch(() => undefined)
      .finally(() => { if (vivo) setCargadasDe(user?.sub ?? null); });
    // Al cerrar sesión, cambiar de usuario o entrar en una restricción, se vuelven a cargar.
    return () => { vivo = false; setCargadasDe(null); };
  }, [activo, user?.sub, setTema]);

  const guardar = useCallback((cambios: Partial<Preferencias>) => {
    setPreferencias((actual) => ({ ...actual, ...cambios }));
    client.patch('/me/preferencias', cambios).catch(() => undefined);
  }, []);

  const value = useMemo(() => ({
    preferencias,
    cargadas,
    cambiarTema: (tema: TemaUsuario) => { setTema(tema); if (activo) guardar({ tema }); },
    mostrarGuia: (modulo: string) =>
      cargadas && !preferencias.guias_desactivadas && !preferencias.guias_vistas.includes(modulo),
    marcarGuiaVista: (modulo: string) => {
      if (!preferencias.guias_vistas.includes(modulo)) {
        guardar({ guias_vistas: [...preferencias.guias_vistas, modulo].slice(0, 50) });
      }
    },
    desactivarGuias: (desactivar: boolean) =>
      guardar(desactivar ? { guias_desactivadas: true } : { guias_desactivadas: false, guias_vistas: [] }),
  }), [preferencias, cargadas, activo, guardar, setTema]);

  return <PreferenciasContext.Provider value={value}>{children}</PreferenciasContext.Provider>;
};
