import type { TFunction } from 'i18next';
import type { ChipColor } from '../../types/ui';
import type { EstadoOrden } from '../../types/procurement';

/** Etiqueta traducida del estado de una orden de compra. */
export const estadoOrdenLabel = (t: TFunction, estado: string): string =>
  t(`procurement.states.${estado}`, { defaultValue: estado });

export const estadoOrdenColor = (estado: EstadoOrden | string): ChipColor => {
  switch (estado) {
    case 'RECIBIDA': return 'success';
    case 'PARCIAL': return 'warning';
    case 'CERRADA_CON_FALTANTE': return 'info';
    case 'CANCELADA': return 'error';
    default: return 'default';
  }
};

/** Una orden admite nuevas entregas mientras está en borrador o parcial. */
export const ordenRecibible = (estado: string): boolean => estado === 'BORRADOR' || estado === 'PARCIAL';
