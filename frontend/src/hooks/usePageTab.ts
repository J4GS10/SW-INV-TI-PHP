import { useSearchParams } from 'react-router-dom';

/**
 * Vista activa de una página con pestañas, guardada en `?vista=` para que el enlace, el botón
 * atrás y la recarga vuelvan a la misma pestaña. La primera vista no ocupa la URL.
 */
export const usePageTab = <V extends string>(views: readonly V[]): [V, (view: V) => void] => {
  const [params, setParams] = useSearchParams();
  const requested = params.get('vista') as V | null;
  const current = requested && views.includes(requested) ? requested : views[0];
  const change = (view: V) => setParams((prev) => {
    const next = new URLSearchParams(prev);
    if (view === views[0]) next.delete('vista');
    else next.set('vista', view);
    return next;
  }, { replace: true });
  return [current, change];
};
