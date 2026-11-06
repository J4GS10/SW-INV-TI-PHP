import { useEffect, useState } from 'react';

import { scopeService, type SedeAlcance } from '../api/scopeService';

/**
 * Sedes del alcance del usuario (el backend ya filtra: global = todas).
 * `unica` es la sede implícita cuando el usuario tiene exactamente una: los
 * formularios la prellenan como sugerencia.
 */
export function useSedes(enabled = true) {
  // null = aún no se cargó; al reabrir un formulario se refresca en segundo plano.
  const [sedes, setSedes] = useState<SedeAlcance[] | null>(null);

  useEffect(() => {
    if (!enabled) return;
    let alive = true;
    scopeService.getSedes()
      .then((data) => { if (alive) setSedes(data); })
      .catch(() => { if (alive) setSedes([]); });
    return () => { alive = false; };
  }, [enabled]);

  const list = sedes ?? [];
  return {
    sedes: list,
    loading: enabled && sedes === null,
    unica: list.length === 1 ? list[0] : null,
  };
}
