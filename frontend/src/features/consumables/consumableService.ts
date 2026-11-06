import client from '../../api/axios';
import {
  Consumible, ConsumibleCreate, MovimientoConsumible, StockAjusteCreate, StockMovimientoCreate,
} from '../../types/consumables';

export const consumableService = {
  list: async (bajoStock = false): Promise<Consumible[]> => {
    const q = bajoStock ? '?bajo_stock=true' : '';
    const r = await client.get<Consumible[]>(`/consumibles${q}`);
    return r.data;
  },

  create: async (data: ConsumibleCreate): Promise<Consumible> => {
    const r = await client.post<Consumible>('/consumibles', data);
    return r.data;
  },

  update: async (id: number, data: Partial<ConsumibleCreate> & { CON_Activo?: boolean }): Promise<Consumible> => {
    const r = await client.patch<Consumible>(`/consumibles/${id}`, data);
    return r.data;
  },

  remove: async (id: number): Promise<void> => {
    await client.delete(`/consumibles/${id}`);
  },

  entrada: async (id: number, data: StockMovimientoCreate): Promise<MovimientoConsumible> => {
    const r = await client.post<MovimientoConsumible>(`/consumibles/${id}/entrada`, data);
    return r.data;
  },

  salida: async (id: number, data: StockMovimientoCreate): Promise<MovimientoConsumible> => {
    const r = await client.post<MovimientoConsumible>(`/consumibles/${id}/salida`, data);
    return r.data;
  },

  /** Ajuste por conteo físico: fija la existencia real (con motivo). */
  ajuste: async (id: number, data: StockAjusteCreate): Promise<MovimientoConsumible | { APR_Aprobacion: number }> => {
    // Suba o baje la existencia, el servidor responde 202 con la solicitud de aprobación.
    const r = await client.post<MovimientoConsumible | { APR_Aprobacion: number }>(`/consumibles/${id}/ajuste`, data);
    return r.data;
  },

  /** Modelos de equipo con los que es compatible (tóner → impresoras). */
  compatibles: async (id: number): Promise<{ MOD_Modelo: number; modelo: string; marca: string }[]> =>
    (await client.get(`/consumibles/${id}/compatibles`)).data,

  setCompatibles: async (id: number, modelos: number[]) =>
    (await client.put(`/consumibles/${id}/compatibles`, { modelos })).data,

  movimientos: async (id: number): Promise<MovimientoConsumible[]> => {
    const r = await client.get<MovimientoConsumible[]>(`/consumibles/${id}/movimientos`);
    return r.data;
  },
};
