import client from './axios';
import type { Aprobacion, EstadoAprobacion } from '../types/aprobaciones';

export const aprobacionService = {
  listar: async (filtros: { estado?: EstadoAprobacion; activo_id?: string } = {}): Promise<Aprobacion[]> =>
    (await client.get<Aprobacion[]>('/aprobaciones', { params: filtros })).data,
  pendientes: async (): Promise<number> =>
    (await client.get<{ total: number }>('/aprobaciones/pendientes')).data.total,
  aprobar: async (id: number, comentario?: string): Promise<Aprobacion> =>
    (await client.post<Aprobacion>(`/aprobaciones/${id}/aprobar`, { comentario: comentario || undefined })).data,
  rechazar: async (id: number, comentario: string): Promise<Aprobacion> =>
    (await client.post<Aprobacion>(`/aprobaciones/${id}/rechazar`, { comentario })).data,
  anular: async (id: number): Promise<Aprobacion> =>
    (await client.post<Aprobacion>(`/aprobaciones/${id}/anular`)).data,
};
