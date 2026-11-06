import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { catalogoService, type CatalogoClave, type CatalogoItem, type MovimientoAplica } from '../api/catalogoService';
import { catalogCodeLabel, catalogItemLabel } from '../utils/catalogLabels';

/** Registros activos de un catálogo (con caché) y una función para recargarlos. */
export function useCatalogo(clave: CatalogoClave) {
  const [items, setItems] = useState<CatalogoItem[]>([]);
  const [loading, setLoading] = useState(true);
  const reload = useCallback(async () => {
    setLoading(true);
    try {
      setItems(await catalogoService.listar(clave));
    } catch {
      setItems([]);
    } finally {
      setLoading(false);
    }
  }, [clave]);
  useEffect(() => { void reload(); }, [reload]);
  return { items, loading, reload };
}

/** ¿El motivo aplica a este tipo de movimiento? (sin lista = a todos). */
export const motivoAplica = (item: CatalogoItem, aplicaA?: MovimientoAplica): boolean => {
  const lista = item.extras.MMO_Aplica_A as MovimientoAplica[] | null | undefined;
  return !aplicaA || !lista || lista.length === 0 || lista.includes(aplicaA);
};

/** ¿El registro elegido exige un detalle escrito («Otro»)? */
export const pideDetalle = (item: CatalogoItem | null | undefined): boolean =>
  !!item && Object.entries(item.extras).some(([k, v]) => k.endsWith('_Pide_Detalle') && v === true);

/**
 * Etiqueta visible de un código guardado (p. ej. el motivo de una baja):
 * traduce los de sistema y muestra el nombre de los que creó el usuario.
 */
export function useCatalogLabel(clave: CatalogoClave) {
  const { t } = useTranslation();
  const { items } = useCatalogo(clave);
  return useCallback((codigo?: string | null) => {
    const item = codigo ? items.find((i) => i.codigo === codigo) : undefined;
    return item ? catalogItemLabel(t, clave, item) : catalogCodeLabel(t, clave, codigo);
  }, [items, t, clave]);
}
