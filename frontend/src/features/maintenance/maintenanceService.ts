import client from '../../api/axios';
import {
  DetalleCreate, DetalleMantenimiento, Mantenimiento, MantenimientoCreate,
  TipoMantenimiento, TipoMantenimientoCreate,
} from '../../types/maintenance';

export interface MantenimientoCierre {
  MAN_Costo_Total: number;
  MAN_Moneda?: string;
  MAN_Fecha_Cierre?: string | null;
}

export const maintenanceService = {
  // TIPOS
  getTipos: async (): Promise<TipoMantenimiento[]> =>
    (await client.get<TipoMantenimiento[]>('/mantenimiento/tipos')).data,
  createTipo: async (data: TipoMantenimientoCreate): Promise<TipoMantenimiento> =>
    (await client.post<TipoMantenimiento>('/mantenimiento/tipos', data)).data,
  updateTipo: async (id: number, data: Partial<TipoMantenimiento>): Promise<TipoMantenimiento> =>
    (await client.patch<TipoMantenimiento>(`/mantenimiento/tipos/${id}`, data)).data,
  deleteTipo: async (id: number): Promise<void> => {
    await client.delete(`/mantenimiento/tipos/${id}`);
  },

  // TICKETS
  getAll: async (): Promise<Mantenimiento[]> =>
    (await client.get<Mantenimiento[]>('/mantenimiento/')).data,
  getById: async (id: string): Promise<Mantenimiento> =>
    (await client.get<Mantenimiento>(`/mantenimiento/${id}`)).data,
  create: async (data: MantenimientoCreate): Promise<Mantenimiento> =>
    (await client.post<Mantenimiento>('/mantenimiento/', data)).data,
  close: async (id: string, payload: MantenimientoCierre): Promise<Mantenimiento> =>
    (await client.patch<Mantenimiento>(`/mantenimiento/${id}/cerrar`, payload)).data,
  remove: async (id: string): Promise<void> => {
    await client.delete(`/mantenimiento/${id}`);
  },

  // DETALLES
  listDetalles: async (mantenimientoId: string): Promise<DetalleMantenimiento[]> =>
    (await client.get<DetalleMantenimiento[]>(`/mantenimiento/${mantenimientoId}/detalles`)).data,
  addDetalle: async (mantenimientoId: string, data: DetalleCreate): Promise<DetalleMantenimiento> =>
    (await client.post<DetalleMantenimiento>(`/mantenimiento/${mantenimientoId}/detalles`, data)).data,
  updateDetalle: async (detalleId: number, data: DetalleCreate): Promise<DetalleMantenimiento> =>
    (await client.patch<DetalleMantenimiento>(`/mantenimiento/detalles/${detalleId}`, data)).data,
  deleteDetalle: async (detalleId: number): Promise<void> => {
    await client.delete(`/mantenimiento/detalles/${detalleId}`);
  },
};
