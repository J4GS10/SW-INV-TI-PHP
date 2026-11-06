import client from '../../api/axios';
import { apiCache } from '../../api/cache';
import { Movimiento, MovimientoCreate, TransferenciaCreate, TipoMovimiento } from '../../types/traceability';
import { Activo } from '../../types/inventory';
import { PersonaResumen } from '../../types/users';
import { Area } from '../../types/geo';
import type { SupportedLanguage } from '../../types/language';

export interface InstalacionArea {
  ACT_Activo: string;
  ARE_Area: number;
  PER_Responsable?: string | null;
  MMO_Motivo: number | null;
  MOV_Motivo_Detalle?: string | null;
}

export interface EstadoInstalacion {
  ACT_Activo: string;
  ACT_Codigo_Interno: string;
  ACT_Instalado: boolean;
  ARE_Area: number | null;
  area: string | null;
  PER_Responsable: string | null;
  responsable: string | null;
}

export const movementService = {
  /** Equipo compartido (impresora, switch…): instalar o reubicar en un área. */
  instalarArea: async (datos: InstalacionArea): Promise<EstadoInstalacion> =>
    (await client.post<EstadoInstalacion>('/trazabilidad/instalar-area', datos)).data,

  retirarArea: async (datos: { ACT_Activo: string; ARE_Area_Destino?: number | null; MMO_Motivo: number | null;
    MOV_Motivo_Detalle?: string | null; condicion?: 'BUENO' | 'CON_DANO' }): Promise<EstadoInstalacion> =>
    (await client.post<EstadoInstalacion>('/trazabilidad/retirar-area', datos)).data,

  // --- LECTURA ---
  getMovements: async (): Promise<Movimiento[]> => {
    const response = await client.get<Movimiento[]>('/trazabilidad/movimientos');
    return response.data;
  },

  getTiposMovimiento: (): Promise<TipoMovimiento[]> => apiCache.get('/trazabilidad/tipos', async () =>
    (await client.get<TipoMovimiento[]>('/trazabilidad/tipos')).data),

  // --- ESCRITURA ---
  createMovement: async (data: MovimientoCreate): Promise<Movimiento> => {
    const response = await client.post<Movimiento>('/trazabilidad/movimientos', data);
    return response.data;
  },

  /** Cambia la custodia de un equipo ya asignado (cierra la anterior y abre la nueva). */
  transfer: async (data: TransferenciaCreate): Promise<Movimiento> => {
    const response = await client.post<Movimiento>('/trazabilidad/transferencia', data);
    return response.data;
  },

  // --- DOCUMENTOS (Word .docx o PDF; tipo entrega o descargo/devolución) ---
  downloadActa: async (
    movimientoId: string,
    formato: 'docx' | 'pdf' = 'docx',
    tipo: 'entrega' | 'descargo' = 'entrega',
    mensajero?: string,
    lang?: SupportedLanguage,
  ): Promise<Blob> => {
    const params = new URLSearchParams({ formato, tipo });
    if (mensajero && mensajero.trim()) params.set('mensajero', mensajero.trim());
    if (lang) params.set('lang', lang);
    const response = await client.get(
      `/trazabilidad/acta/${movimientoId}?${params.toString()}`,
      { responseType: 'blob' },
    );
    return response.data;
  },

  downloadActaLote: async (
    ids: string[],
    formato: 'docx' | 'pdf' = 'docx',
    tipo: 'entrega' | 'descargo' = 'entrega',
    lang?: SupportedLanguage,
  ): Promise<Blob> => {
    const params = new URLSearchParams({ formato, tipo });
    if (lang) params.set('lang', lang);
    const response = await client.post(`/trazabilidad/acta/lote?${params.toString()}`,
      { movimientos_ids: ids },
      { responseType: 'blob' }
    );
    return response.data;
  },

  getActivosLookup: async (): Promise<Activo[]> => {
    const response = await client.get<Activo[]>('/core/activos');
    return response.data;
  },

  getPersonasLookup: (): Promise<PersonaResumen[]> => apiCache.get('/org/personas', async () =>
    (await client.get<PersonaResumen[]>('/org/personas')).data, 30_000),

  getAreasLookup: (): Promise<Array<Area & { ruta: string }>> => movementService.getAreas(),

  getAreas: (): Promise<Array<Area & { ruta: string }>> => apiCache.get('/geo/areas/all', async () =>
    (await client.get<Array<Area & { ruta: string }>>('/geo/areas/all')).data),

  // Paginación de movimientos
  getMovementsPaged: async (skip = 0, limit = 50): Promise<Movimiento[]> => {
    const response = await client.get<Movimiento[]>(`/trazabilidad/movimientos?skip=${skip}&limit=${limit}`);
    return response.data;
  },
};
