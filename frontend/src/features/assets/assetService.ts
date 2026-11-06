import { hoyLocal } from '../../utils/dates';
import client from '../../api/axios';
import { apiCache } from '../../api/cache';
import { Activo, ActivoCreate, Marca, Modelo, ModeloFlat, TipoActivo, EstadoOperativo } from '../../types/inventory';
import type { Movimiento } from '../../types/traceability';

export interface ActivoUpdate {
  ACT_Codigo_Interno?: string;
  ACT_Serie_Fabricante?: string;
  ACT_Hostname?: string | null;
  ACT_Fecha_Compra?: string;
  ACT_Fin_Garantia?: string | null;
  ACT_Costo?: number | null;
  ACT_Moneda?: string;
  MOD_Modelo?: number;
  TAC_Tipo_Activo?: number;
  EOP_Estado_Operativo?: number;
  SED_Sede?: number | null;
}

export interface InstalacionDetalle {
  INS_Instalacion: number;
  LIC_Licencia: number;
  INS_Fecha_Instalacion: string;
  INS_Estado: boolean;
  ACT_Activo: string;
  licencia?: {
    LIC_Licencia: number;
    LIC_Fecha_Vencimiento: string | null;
    LIC_Cantidad_Total: number;
    LIC_Cantidad_Usada: number;
    software?: { SOF_Software: number; SOF_Nombre: string; SOF_Version: string | null; SOF_Fabricante: string };
    tipo_licencia?: { TLI_Tipo_Licencia: number; TLI_Nombre: string };
  };
}

export interface Especificacion {
  ESP_Especificacion: number;
  TES_Tipo_Especificacion: number;
  TES_Nombre: string;
  TES_Unidad_Medida: string | null;
  ESP_Valor: string;
}

export interface TipoEspecificacion {
  TES_Tipo_Especificacion: number;
  TES_Nombre: string;
  TES_Unidad_Medida: string | null;
}

export interface ActivoSearchParams {
  sort?: string;
  sort_dir?: 'asc' | 'desc';
  q?: string;
  tipo_activo_id?: number;
  estado_operativo_id?: number;
  page?: number;
  per_page?: number;
}

export interface PaginatedActivos {
  items: Activo[];
  total: number;
  page: number;
  per_page: number;
}

export const assetService = {
  // ESPECIFICACIONES (características: RAM, disco, batería…)
  getEspecificaciones: async (activoId: string): Promise<Especificacion[]> => {
    const r = await client.get<Especificacion[]>(`/core/activos/${activoId}/especificaciones`);
    return r.data;
  },
  getTiposEspecificacion: async (): Promise<TipoEspecificacion[]> => {
    const r = await client.get<TipoEspecificacion[]>('/cat/tipos-especificacion');
    return r.data;
  },
  addEspecificacion: async (activoId: string, tesId: number, valor: string): Promise<Especificacion> => {
    const r = await client.post<Especificacion>(`/core/activos/${activoId}/especificaciones`, {
      TES_Tipo_Especificacion: tesId, ESP_Valor: valor,
    });
    return r.data;
  },
  updateEspecificacion: async (espId: number, valor: string): Promise<void> => {
    await client.patch(`/core/especificaciones/${espId}`, { ESP_Valor: valor });
  },
  deleteEspecificacion: async (espId: number): Promise<void> => {
    await client.delete(`/core/especificaciones/${espId}`);
  },

  // CORE
  getActivos: async (skip = 0, limit = 200): Promise<Activo[]> => {
    const r = await client.get<Activo[]>(`/core/activos?skip=${skip}&limit=${limit}`);
    return r.data;
  },
  searchActivos: async (params: ActivoSearchParams = {}): Promise<PaginatedActivos> => {
    const r = await client.post<PaginatedActivos>('/core/activos/search', {
      q: params.q || undefined,
      tipo_activo_id: params.tipo_activo_id,
      estado_operativo_id: params.estado_operativo_id,
      page: params.page ?? 1,
      per_page: params.per_page ?? 25,
      sort: params.sort,
      sort_dir: params.sort_dir,
    });
    return r.data;
  },
  getActivo: async (id: string): Promise<Activo> => {
    const r = await client.get<Activo>(`/core/activos/${id}`);
    return r.data;
  },
  getActivoByCode: async (codigo: string): Promise<Activo> => {
    const r = await client.get<Activo>(`/core/activos/by-code/${encodeURIComponent(codigo.trim())}`);
    return r.data;
  },
  downloadLabels: async (payload: { activos_ids?: string[]; codigos?: string[] }, lang = 'es'): Promise<Blob> => {
    const r = await client.post('/core/activos/etiquetas.pdf', payload, { responseType: 'blob', params: { lang } });
    return r.data;
  },
  createActivo: async (data: ActivoCreate): Promise<Activo> => {
    const r = await client.post<Activo>('/core/activos', data);
    return r.data;
  },
  updateActivo: async (id: string, data: ActivoUpdate): Promise<Activo> => {
    const r = await client.patch<Activo>(`/core/activos/${id}`, data);
    return r.data;
  },

  // Historial completo de movimientos
  getHistorial: async (activoId: string): Promise<Movimiento[]> => {
    const r = await client.get<Movimiento[]>(`/trazabilidad/activo/${activoId}/historial`);
    return r.data;
  },

  // Licencias instaladas en el activo
  getInstalaciones: async (activoId: string, soloActivas = true): Promise<InstalacionDetalle[]> => {
    const r = await client.get<InstalacionDetalle[]>(
      `/soft/activos/${activoId}/instalaciones?solo_activas=${soloActivas}`,
    );
    return r.data;
  },
  instalarLicencia: async (activoId: string, licenciaId: number, fecha: string) => {
    const r = await client.post('/soft/instalaciones', {
      ACT_Activo: activoId,
      LIC_Licencia: licenciaId,
      INS_Fecha_Instalacion: fecha,
    });
    return r.data;
  },
  desinstalarLicencia: async (activoId: string, licenciaId: number, motivo?: string) => {
    const r = await client.post('/soft/instalaciones/desinstalar', {
      ACT_Activo: activoId,
      LIC_Licencia: licenciaId,
      INS_Fecha_Instalacion: hoyLocal(),
      motivo: motivo || undefined,
    });
    return r.data;
  },

  // CATÁLOGOS para los combos
  getMarcas: (): Promise<Marca[]> => apiCache.get('/cat/marcas', async () => (await client.get<Marca[]>('/cat/marcas')).data),
  getModelos: async (marcaId?: number, tipoId?: number): Promise<Modelo[]> => {
    let url = marcaId ? `/cat/modelos?marca_id=${marcaId}` : '/cat/modelos';
    // Cascada Marca + Tipo: pre-filtra en el backend para no abrumar el select.
    if (marcaId && tipoId) url += `&tipo_id=${tipoId}`;
    return (await client.get<Modelo[]>(url)).data;
  },
  /** Todos los modelos con su marca, en una sola consulta. */
  getModelosFlat: (): Promise<ModeloFlat[]> => apiCache.get('/cat/modelos-flat', async () =>
    (await client.get<ModeloFlat[]>('/cat/modelos-flat', { params: { limit: 1000 } })).data),
  getTipos: (): Promise<TipoActivo[]> => apiCache.get('/cat/tipos-activo', async () => (await client.get<TipoActivo[]>('/cat/tipos-activo')).data),
  getEstados: (): Promise<EstadoOperativo[]> => apiCache.get('/cat/estados-operativos', async () => (await client.get<EstadoOperativo[]>('/cat/estados-operativos')).data),
};
