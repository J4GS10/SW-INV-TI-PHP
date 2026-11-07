import client from '../../api/axios';
import {
  Proveedor, ProveedorCreate, Garantia,
} from '../../types/procurement';

export const procurementService = {
  // ---- Proveedores ----
  listProveedores: async (soloActivos = false): Promise<Proveedor[]> => {
    const q = soloActivos ? '?solo_activos=true' : '';
    const r = await client.get<Proveedor[]>(`/compras/proveedores${q}`);
    return r.data;
  },
  createProveedor: async (data: ProveedorCreate): Promise<Proveedor> => {
    const r = await client.post<Proveedor>('/compras/proveedores', data);
    return r.data;
  },
  updateProveedor: async (id: number, data: Partial<ProveedorCreate> & { PRV_Activo?: boolean }): Promise<Proveedor> => {
    const r = await client.patch<Proveedor>(`/compras/proveedores/${id}`, data);
    return r.data;
  },
  deleteProveedor: async (id: number): Promise<void> => {
    await client.delete(`/compras/proveedores/${id}`);
  },

  // ---- Garantías ----
  garantias: async (dias = 90, soloAlertas = false): Promise<Garantia[]> => {
    const r = await client.get<Garantia[]>(`/compras/garantias?dias=${dias}&solo_alertas=${soloAlertas}`);
    return r.data;
  },

  notificarGarantias: async (dias = 90): Promise<{ total: number; enviado: boolean }> => {
    const r = await client.post(`/compras/garantias/notificar?dias=${dias}`);
    return r.data;
  },
};
