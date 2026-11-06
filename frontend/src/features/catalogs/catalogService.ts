import client from '../../api/axios';
import {
  Marca, MarcaCreate,
  TipoActivo, TipoActivoCreate,
  TipoConexion, TipoConexionCreate,
  Modelo, ModeloCreate,
  EstadoOperativo, EstadoOperativoCreate,
  TipoEspecificacion, TipoEspecificacionCreate
} from '../../types/catalogs';

export const catalogService = {
  // ==========================================
  // 1. MARCAS
  // ==========================================
  getMarcas: async (): Promise<Marca[]> => {
    const response = await client.get<Marca[]>('/cat/marcas');
    return response.data;
  },

  createMarca: async (data: MarcaCreate): Promise<Marca> => {
    const response = await client.post<Marca>('/cat/marcas', data);
    return response.data;
  },

  updateMarca: async (id: number, data: Partial<MarcaCreate>): Promise<Marca> => {
    const response = await client.patch<Marca>(`/cat/marcas/${id}`, data);
    return response.data;
  },

  deleteMarca: async (id: number): Promise<void> => {
    await client.delete(`/cat/marcas/${id}`);
  },

  // ==========================================
  // 2. TIPOS DE ACTIVO (Con Prefijos)
  // ==========================================
  getTiposActivo: async (): Promise<TipoActivo[]> => {
    const response = await client.get<TipoActivo[]>('/cat/tipos-activo');
    return response.data;
  },

  createTipoActivo: async (data: TipoActivoCreate): Promise<TipoActivo> => {
    const response = await client.post<TipoActivo>('/cat/tipos-activo', data);
    return response.data;
  },

  updateTipoActivo: async (id: number, data: Partial<TipoActivoCreate>): Promise<TipoActivo> => {
    const response = await client.patch<TipoActivo>(`/cat/tipos-activo/${id}`, data);
    return response.data;
  },

  deleteTipoActivo: async (id: number): Promise<void> => {
    await client.delete(`/cat/tipos-activo/${id}`);
  },

  /** Componentes internos que admite el tipo (Laptop → RAM, disco…). */
  getComponentesTipo: async (id: number): Promise<number[]> =>
    (await client.get<number[]>(`/cat/tipos-activo/${id}/componentes`)).data,

  setComponentesTipo: async (id: number, componentes: number[]): Promise<number[]> =>
    (await client.put<number[]>(`/cat/tipos-activo/${id}/componentes`, { componentes })).data,

  /** Insumos registrados como activos: vista previa y solicitud de paso a consumible. */
  vistaReclasificacion: async (id: number, sede?: number) =>
    (await client.get(`/core/tipos-activo/${id}/reclasificar`, { params: sede ? { sede } : {} })).data as {
      tipo: string; activos: number; bloqueados: { codigo: string; motivo: string }[];
      por_sede: { SED_Sede: number | null; sede: string | null; activos: number }[];
    },

  solicitarReclasificacion: async (id: number, datos: { SED_Sede: number; nombre: string; CON_Unidad: string; motivo: string }) =>
    (await client.post(`/core/tipos-activo/${id}/reclasificar`, datos)).data,

  // ==========================================
  // 3. TIPOS DE CONEXIÓN
  // ==========================================
  getTiposConexion: async (): Promise<TipoConexion[]> => {
    const response = await client.get<TipoConexion[]>('/cat/tipos-conexion');
    return response.data;
  },

  createTipoConexion: async (data: TipoConexionCreate): Promise<TipoConexion> => {
    const response = await client.post<TipoConexion>('/cat/tipos-conexion', data);
    return response.data;
  },

  updateTipoConexion: async (id: number, data: Partial<TipoConexionCreate>): Promise<TipoConexion> => {
    const response = await client.patch<TipoConexion>(`/cat/tipos-conexion/${id}`, data);
    return response.data;
  },

  deleteTipoConexion: async (id: number): Promise<void> => {
    await client.delete(`/cat/tipos-conexion/${id}`);
  },

  // ==========================================
  // 4. MODELOS
  // ==========================================
  /**
   * Obtiene modelos filtrados por marca.
   * @param marcaId ID de la marca para filtrar.
   */
  getModelos: async (marcaId: number): Promise<Modelo[]> => {
    const response = await client.get<Modelo[]>(`/cat/modelos?marca_id=${marcaId}`);
    return response.data;
  },

  createModelo: async (data: ModeloCreate): Promise<Modelo> => {
    const response = await client.post<Modelo>('/cat/modelos', data);
    return response.data;
  },

  updateModelo: async (id: number, data: Partial<ModeloCreate>): Promise<Modelo> => {
    const response = await client.patch<Modelo>(`/cat/modelos/${id}`, data);
    return response.data;
  },

  deleteModelo: async (id: number): Promise<void> => {
    await client.delete(`/cat/modelos/${id}`);
  },

  // ==========================================
  // 5. ESTADOS OPERATIVOS
  // ==========================================
  getEstadosOperativos: async (): Promise<EstadoOperativo[]> => {
    const response = await client.get<EstadoOperativo[]>('/cat/estados-operativos');
    return response.data;
  },

  createEstadoOperativo: async (data: EstadoOperativoCreate): Promise<EstadoOperativo> => {
    const response = await client.post<EstadoOperativo>('/cat/estados-operativos', data);
    return response.data;
  },

  updateEstadoOperativo: async (id: number, data: Partial<EstadoOperativoCreate>): Promise<EstadoOperativo> => {
    const response = await client.patch<EstadoOperativo>(`/cat/estados-operativos/${id}`, data);
    return response.data;
  },

  deleteEstadoOperativo: async (id: number): Promise<void> => {
    await client.delete(`/cat/estados-operativos/${id}`);
  },

  // ==========================================
  // 6. TIPOS DE ESPECIFICACIÓN (RAM, Disco, etc.)
  // ==========================================
  getTiposEspecificacion: async (): Promise<TipoEspecificacion[]> => {
    const response = await client.get<TipoEspecificacion[]>('/cat/tipos-especificacion');
    return response.data;
  },

  createTipoEspecificacion: async (data: TipoEspecificacionCreate): Promise<TipoEspecificacion> => {
    const response = await client.post<TipoEspecificacion>('/cat/tipos-especificacion', data);
    return response.data;
  },

  updateTipoEspecificacion: async (id: number, data: Partial<TipoEspecificacionCreate>): Promise<TipoEspecificacion> => {
    const response = await client.patch<TipoEspecificacion>(`/cat/tipos-especificacion/${id}`, data);
    return response.data;
  },

  deleteTipoEspecificacion: async (id: number): Promise<void> => {
    await client.delete(`/cat/tipos-especificacion/${id}`);
  },

  // ==========================================
  // 7. MODELOS FLAT (todos, sin filtro de marca)
  // ==========================================
  getModelosFlat: async (): Promise<Modelo[]> => {
    const response = await client.get<Modelo[]>('/cat/modelos-flat');
    return response.data;
  },
};