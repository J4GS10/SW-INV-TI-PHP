import type { TFunction } from 'i18next';

export type ModuloVacio =
  | 'software' | 'consumables' | 'maintenance' | 'persons' | 'providers' | 'orders' | 'brands' | 'asset_types';

interface Opciones {
  /** Hay una búsqueda o filtro activo: el vacío es «sin coincidencias». */
  buscando: boolean;
  onLimpiar?: () => void;
  /** Acción para registrar el primero; se omite si el usuario no puede crear. */
  onCrear?: () => void;
}

/**
 * Props de estado vacío para DataTableShell: distingue «todavía no hay nada»
 * (explica el módulo y ofrece registrar el primero) de «la búsqueda no
 * encontró nada» (ofrece limpiarla).
 */
export const emptyStateProps = (t: TFunction, modulo: ModuloVacio, { buscando, onLimpiar, onCrear }: Opciones) =>
  buscando
    ? {
        emptyTitle: t('empty.no_matches'),
        emptyDescription: t('empty.no_matches_hint'),
        emptyAction: onLimpiar ? { label: t('empty.clear_search'), onClick: onLimpiar } : undefined,
      }
    : {
        emptyTitle: t(`empty.${modulo}.title`),
        emptyDescription: t(`empty.${modulo}.description`),
        emptyAction: onCrear ? { label: t(`empty.${modulo}.action`), onClick: onCrear } : undefined,
      };
