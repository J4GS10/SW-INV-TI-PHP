import { useEffect, useState } from 'react';

type EntityId = string | number;

/**
 * Carga el contexto relacional de la entidad elegida en un formulario.
 *
 * - Espera `delay` ms antes de pedirlo (cambiar de selección rápido no lanza
 *   una petición por cada opción) y aborta la petición anterior.
 * - Solo expone datos cuyo id coincide con la selección actual: una respuesta
 *   tardía de otra selección nunca se aplica.
 * - Los errores son silenciosos (`data` queda en `null`): el formulario sigue
 *   funcionando a mano.
 *
 * `fetcher` debe ser estable (p. ej. un método de un servicio de módulo).
 */
export function useEntityContext<I extends EntityId, T>(
  id: I | null | undefined | '',
  fetcher: (id: I, signal: AbortSignal) => Promise<T>,
  delay = 250,
): { data: T | null; loading: boolean } {
  const [result, setResult] = useState<{ id: I; data: T | null } | null>(null);
  const hasId = id !== null && id !== undefined && id !== '';

  useEffect(() => {
    if (id === null || id === undefined || id === '') return undefined;
    const controller = new AbortController();
    const timer = window.setTimeout(() => {
      fetcher(id, controller.signal)
        .then((data) => {
          if (!controller.signal.aborted) setResult({ id, data });
        })
        .catch(() => {
          if (!controller.signal.aborted) setResult({ id, data: null });
        });
    }, delay);
    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [id, fetcher, delay]);

  const settled = hasId && result !== null && result.id === id;
  return { data: settled ? result.data : null, loading: hasId && !settled };
}
