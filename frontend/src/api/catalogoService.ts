import client from './axios';
import { apiCache } from './cache';

/** Catálogos administrables del Centro de catálogos (backend: /catalogos). */
export type CatalogoClave =
  | 'marcas' | 'tipos-conexion' | 'tipos-especificacion' | 'unidades-medida'
  | 'motivos-movimiento' | 'motivos-baja' | 'destinos-baja'
  | 'categorias-consumible'
  | 'tipos-mantenimiento' | 'tipos-falla' | 'acciones-mantenimiento'
  | 'tipos-area'
  | 'tipos-documento' | 'tipos-evidencia'
  | 'modelos-comerciales' | 'metodos-activacion' | 'formas-conteo';

export type CatalogoGrupo =
  'ACTIVOS' | 'MOVIMIENTOS' | 'LICENCIAS' | 'CONSUMIBLES' | 'MANTENIMIENTO' | 'UBICACIONES' | 'DOCUMENTOS';

export type MovimientoAplica = 'ASIGNACION' | 'DEVOLUCION' | 'TRANSFERENCIA' | 'PRESTAMO' | 'INSTALACION' | 'RETIRO';

export interface CatalogoExtraDef {
  columna: string;
  tipo: 'bool' | 'texto' | 'aplica_a' | 'catalogo' | 'tipo_dato' | 'numero';
  largo: number;
  catalogo: CatalogoClave | null;
}

export interface CatalogoDef {
  clave: CatalogoClave;
  grupo: CatalogoGrupo;
  tiene_codigo: boolean;
  tiene_descripcion: boolean;
  largo_nombre: number;
  extras: CatalogoExtraDef[];
}

export interface CatalogoItem {
  id: number;
  nombre: string;
  codigo: string | null;
  descripcion: string | null;
  orden: number;
  activo: boolean;
  fusionado_en: number | null;
  /** Registro de sistema: su código no cambia, no se desactiva y la UI traduce su nombre. */
  sistema: boolean;
  /** Registros que lo usan. */
  uso: number;
  extras: Record<string, unknown>;
}

export interface CatalogoItemInput {
  nombre?: string;
  descripcion?: string | null;
  orden?: number;
  activo?: boolean;
  extras?: Record<string, unknown>;
}

export interface FusionPrevia {
  conteos: Record<string, number>;
  total: number;
  modelos_unidos?: number;
}

const base = '/catalogos';

export const catalogoService = {
  definiciones: (): Promise<CatalogoDef[]> =>
    apiCache.get('catalogos:def', async () => (await client.get<CatalogoDef[]>(base)).data),

  /** Registros activos (con caché); `inactivos` trae todos, sin caché (administración). */
  listar: async (clave: CatalogoClave, inactivos = false): Promise<CatalogoItem[]> => {
    if (inactivos) return (await client.get<CatalogoItem[]>(`${base}/${clave}`, { params: { inactivos: true } })).data;
    return apiCache.get(`catalogos:${clave}`, async () => (await client.get<CatalogoItem[]>(`${base}/${clave}`)).data);
  },

  crear: async (clave: CatalogoClave, data: CatalogoItemInput): Promise<CatalogoItem> =>
    (await client.post<CatalogoItem>(`${base}/${clave}`, data)).data,

  editar: async (clave: CatalogoClave, id: number, data: CatalogoItemInput): Promise<CatalogoItem> =>
    (await client.patch<CatalogoItem>(`${base}/${clave}/${id}`, data)).data,

  reordenar: async (clave: CatalogoClave, ids: number[]): Promise<void> => {
    await client.post(`${base}/${clave}/reordenar`, { ids });
  },

  previaFusion: async (clave: CatalogoClave, origen: number, destino: number): Promise<FusionPrevia> =>
    (await client.get<FusionPrevia>(`${base}/${clave}/${origen}/fusionar/${destino}`)).data,

  fusionar: async (clave: CatalogoClave, origen: number, destino: number): Promise<FusionPrevia> =>
    (await client.post<FusionPrevia>(`${base}/${clave}/${origen}/fusionar/${destino}`)).data,
};

// ── Especificaciones por tipo de activo y kit estándar ───────────────────────
export interface EspecificacionTipo {
  TES_Tipo_Especificacion: number;
  nombre: string | null;
  unidad: string | null;
  obligatoria: boolean;
  valores: string[];
  orden: number;
}

export interface KitItem {
  KIT_Item?: number;
  TAC_Tipo_Activo: number | null;
  KIT_Cantidad: number;
  LIC_Licencia: number | null;
}

export interface KitEstandar {
  KIT_Kit: number;
  KIT_Nombre: string;
  CAR_Cargo: number | null;
  DEP_Departamento: number | null;
  KIT_Activo: boolean;
  items: KitItem[];
}

export type KitEstandarInput = Omit<KitEstandar, 'KIT_Kit'>;

export const catalogoConfigService = {
  especificacionesPorTipo: (): Promise<Record<string, EspecificacionTipo[]>> =>
    apiCache.get('catalogos:especificaciones-tipo',
      async () => (await client.get<Record<string, EspecificacionTipo[]>>(`${base}/especificaciones-tipo`)).data),

  fijarEspecificaciones: async (
    tipoId: number, items: { TES_Tipo_Especificacion: number; obligatoria: boolean; valores: string[] }[],
  ): Promise<EspecificacionTipo[]> =>
    (await client.put<EspecificacionTipo[]>(`${base}/especificaciones-tipo/${tipoId}`, { items })).data,

  kits: async (): Promise<KitEstandar[]> => (await client.get<KitEstandar[]>(`${base}/kits`)).data,
  crearKit: async (data: KitEstandarInput): Promise<KitEstandar> =>
    (await client.post<KitEstandar>(`${base}/kits`, data)).data,
  editarKit: async (id: number, data: KitEstandarInput): Promise<KitEstandar> =>
    (await client.put<KitEstandar>(`${base}/kits/${id}`, data)).data,
  borrarKit: async (id: number): Promise<void> => {
    await client.delete(`${base}/kits/${id}`);
  },
};
