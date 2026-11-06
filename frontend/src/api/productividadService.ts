import client from './axios';

export type ResultadoBusqueda =
  | { tipo: 'activo'; id: string; codigo: string; titulo: string; subtitulo: string; estado: string; custodio: string | null }
  | { tipo: 'persona'; id: string; titulo: string; subtitulo: string; activo: boolean }
  | { tipo: 'orden'; id: string; titulo: string; subtitulo: string };

export interface KitTipo {
  TAC_Tipo_Activo: number;
  nombre: string;
  frecuencia: number;
  disponibles: number;
  ya_tiene: boolean;
  /** Kit estándar: cuántos equipos de este tipo. */
  cantidad?: number;
}

export interface KitSugerido {
  /** «kit»: el kit estándar del cargo o del departamento (Centro de catálogos). */
  origen: 'kit' | 'departamento' | 'general';
  kit?: string;
  departamento: string;
  personas_referencia: number;
  tipos: KitTipo[];
  /** Licencias que incluye el kit estándar. */
  licencias: number[];
}

export interface ItemVerificacion {
  tipo: 'activo' | 'persona' | 'modelo' | 'marca';
  id: string;
  codigo?: string;
  titulo: string;
  detalle?: string | null;
}

export interface Verificacion {
  clave: string;
  severidad: 'alta' | 'media' | 'baja';
  total: number;
  items: ItemVerificacion[];
}

export interface CalidadDatos {
  pendientes: number;
  verificaciones: Verificacion[];
}

export interface ResultadoLote {
  total: number;
  movimientos: string[];
  items: Array<{ ACT_Activo: string; codigo: string; MOV_Movimiento: string; operacion?: string; estado?: string }>;
  /** Licencias asignadas en la misma entrega. */
  licencias?: Array<{ LIC_Licencia: number; INS_Instalacion: number; LCL_Licencia_Clave: number | null }>;
}

export interface EntregaLote {
  PER_Persona: string;
  ARE_Area: number;
  activos: string[];
  MOV_Motivo?: string;
  /** Motivo del catálogo (motivos-movimiento) y su detalle escrito. */
  MMO_Motivo?: number | null;
  MOV_Motivo_Detalle?: string | null;
  MOV_Observacion?: string;
  ticket?: string;
  /** Licencias por persona que se asignan en la misma transacción. */
  licencias?: number[];
}

export interface DevolucionLote {
  activos: string[];
  MOV_Motivo?: string;
  /** Motivo del catálogo (motivos-movimiento) y su detalle escrito. */
  MMO_Motivo?: number | null;
  MOV_Motivo_Detalle?: string | null;
  ARE_Area_Destino?: number | null;
  condicion?: 'BUENO' | 'CON_DANO';
}

export interface FilaImportacion {
  fila: number;
  codigo: string | null;
  categoria: string | null;
  marca: string | null;
  modelo: string | null;
  serie: string | null;
  ubicacion: string | null;
  estado: 'ok' | 'aviso' | 'error';
  errores: string[];
  avisos: string[];
}

export interface PrevisualizacionImportacion {
  resumen: { filas: number; validas: number; con_error: number; marcas_nuevas: number; modelos_nuevos: number };
  filas: FilaImportacion[];
}

const formulario = (archivo: File) => {
  const data = new FormData();
  data.append('archivo', archivo);
  return data;
};

export const productividadService = {
  buscar: async (q: string, signal?: AbortSignal): Promise<ResultadoBusqueda[]> =>
    (await client.get<{ resultados: ResultadoBusqueda[] }>('/productividad/buscar', { params: { q }, signal })).data.resultados,
  kitSugerido: async (personaId: string): Promise<KitSugerido> =>
    (await client.get<KitSugerido>(`/productividad/kit-sugerido/${personaId}`)).data,
  calidadDatos: async (): Promise<CalidadDatos> =>
    (await client.get<CalidadDatos>('/productividad/calidad-datos')).data,
  entregaLote: async (data: EntregaLote): Promise<ResultadoLote> =>
    (await client.post<ResultadoLote>('/trazabilidad/entrega-lote', data)).data,
  devolucionLote: async (data: DevolucionLote): Promise<ResultadoLote> =>
    (await client.post<ResultadoLote>('/trazabilidad/devolucion-lote', data)).data,
  plantillaImportacion: async (): Promise<Blob> =>
    (await client.get('/productividad/importar/activos/plantilla', { responseType: 'blob' })).data,
  previsualizarImportacion: async (archivo: File): Promise<PrevisualizacionImportacion> =>
    (await client.post<PrevisualizacionImportacion>('/productividad/importar/activos/previsualizar', formulario(archivo), { timeout: 120_000 })).data,
  confirmarImportacion: async (archivo: File, omitirErrores: boolean): Promise<{ creados: number; omitidos: number; codigos: string[] }> =>
    (await client.post('/productividad/importar/activos/confirmar', formulario(archivo), {
      params: { omitir_errores: omitirErrores }, timeout: 120_000,
    })).data,
};

/** Descarga un Blob como archivo. */
export function guardarArchivo(blob: Blob, nombre: string): void {
  const url = window.URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = nombre;
  document.body.appendChild(a);
  a.click();
  a.remove();
  window.URL.revokeObjectURL(url);
}
