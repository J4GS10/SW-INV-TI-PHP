import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { getApiErrorMessage } from '../../../api/errors';
import { useLiveRefresh } from '../../../hooks/useLiveRefresh';

/** Hoy en la zona del navegador, como YYYY-MM-DD (la fecha que la API espera). */
export const today = (): string => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

/**
 * Carga una valoración a la fecha elegida y la recarga cuando otra persona cambia algo.
 * La misma lógica sirve al equipo, a la estación de trabajo y al resumen de los libros.
 */
export function useValuation<T>(load: (asOf: string) => Promise<T>) {
  const { t } = useTranslation();
  const [asOf, setAsOf] = useState(today);
  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const reload = useCallback(async () => {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(asOf)) return;
    setLoading(true);
    try {
      setData(await load(asOf));
      setError(null);
    } catch (err) {
      setError(getApiErrorMessage(err, t('common.error_loading')));
    } finally {
      setLoading(false);
    }
  }, [asOf, load, t]);

  useEffect(() => { void reload(); }, [reload]);
  // Asignar, instalar un componente, cambiar un costo o una categoría mueven los valores: cualquier cambio recarga.
  useLiveRefresh(reload);

  return { asOf, setAsOf, data, loading, error, reload };
}
