import type { ItemAtencion } from '../../types/kardex';
import type { UserRole } from '../../types/auth';

/** A dónde lleva cada tipo de pendiente (y si sus elementos son activos). */
export const DESTINO: Record<string, { ruta: string; activos?: boolean }> = {
  ASIGNADO_SIN_CUSTODIO: { ruta: '/trazabilidad?vista=custodia', activos: true },
  ALMACEN_CON_CUSTODIO: { ruta: '/trazabilidad?vista=custodia', activos: true },
  BAJA_CON_CUSTODIA: { ruta: '/trazabilidad?vista=custodia', activos: true },
  APROBACION_PENDIENTE: { ruta: '/aprobaciones' },
  PERSONA_INACTIVA_CON_EQUIPOS: { ruta: '/trazabilidad?vista=custodia' },
  PRESTAMO_VENCIDO: { ruta: '/operaciones/movimientos' },
  STOCK_BAJO: { ruta: '/consumibles' },
  INGRESO_POR_CONFIRMAR: { ruta: '/ingresos?estado=REGISTERED' },
  ACTIVO_POR_COMPLETAR: { ruta: '/ingresos/por-completar' },
  TICKET_ANTIGUO: { ruta: '/mantenimiento' },
  GARANTIA_POR_VENCER: { ruta: '/compras/garantias', activos: true },
  LICENCIA_POR_VENCER: { ruta: '/software' },
  CUENTA_POR_CAMBIAR: { ruta: '/software' },
  CUENTA_SIN_ROTAR: { ruta: '/software' },
  CUPOS_AGOTADOS: { ruta: '/software' },
  DATOS_INCOMPLETOS: { ruta: '/activos' },
  FALTANTE_PENDIENTE: { ruta: '/inventario/tomas?vista=faltantes' },
  RESPONSABLE_INACTIVO: { ruta: '/activos', activos: true },
  INSUMOS_POR_RECLASIFICAR: { ruta: '/config/tipos' },
};

/**
 * «Mis pendientes». El servidor ya envía solo los del rol (ReportesService.atencion);
 * aquí solo se ordena: el administrador de TI ve primero las aprobaciones.
 */
export function pendientesDeRol(items: ItemAtencion[], rol?: UserRole | null): ItemAtencion[] {
  if (rol === 'ADMIN_TI') {
    return [...items].sort((a, b) => Number(b.clave === 'APROBACION_PENDIENTE') - Number(a.clave === 'APROBACION_PENDIENTE'));
  }
  return items;
}
