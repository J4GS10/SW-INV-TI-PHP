export type StatusVariant =
  | 'asignado'
  | 'bodega'
  | 'reparacion'
  | 'baja'
  | 'activo'
  | 'inactivo'
  | 'abierto'
  | 'cerrado'
  | 'borrador'
  | 'recibido'
  | 'cancelado'
  | 'vigente'
  | 'por_vencer'
  | 'vencido'
  | 'default';

export type StatusColor = 'default' | 'primary' | 'secondary' | 'error' | 'info' | 'success' | 'warning';

/** One color per meaning: green is in force or fine, grey is finished or neutral. */
const VARIANT_COLOR: Record<StatusVariant, StatusColor> = {
  asignado: 'info',
  bodega: 'success',
  reparacion: 'secondary',
  baja: 'error',
  activo: 'success',
  inactivo: 'default',
  abierto: 'warning',
  cerrado: 'default',
  borrador: 'default',
  recibido: 'success',
  cancelado: 'error',
  vigente: 'success',
  por_vencer: 'warning',
  vencido: 'error',
  default: 'default',
};

export const statusColor = (variant: string): StatusColor => VARIANT_COLOR[variant as StatusVariant] ?? 'default';
