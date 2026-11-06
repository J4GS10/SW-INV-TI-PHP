import client from './axios';
import { downloadCsv, downloadFile } from './downloadCsv';
import type { SupportedLanguage } from '../types/language';
import { hoyLocal } from '../utils/dates';
import type { Aprobacion } from '../types/aprobaciones';

export interface VerificacionKardex {
  total: number;
  integro: boolean;
  problemas: { evento: number; secuencia: number | null; problema: 'SIN_SELLO' | 'EVENTO_ALTERADO' | 'CADENA_ROTA' | 'EVENTO_FALTANTE' }[];
  ultima_secuencia: number;
  ultimo_hash: string | null;
  ultima_fecha: string | null;
}
import type {
  BajaPayload, ComponentesActivo, CustodiaItem, DevolucionPayload, DevolucionResultado, ItemAtencion,
  KardexFiltros, KardexPagina, LecturaResultado, ResumenEntradasSalidas, Toma, TomaDetalle,
} from '../types/kardex';

/** Convierte los filtros a query string (los arreglos se repiten: tipos=A&tipos=B). */
function params(filtros: KardexFiltros & Record<string, unknown>): URLSearchParams {
  const p = new URLSearchParams();
  Object.entries(filtros).forEach(([k, v]) => {
    if (v === undefined || v === null || v === '') return;
    if (Array.isArray(v)) v.forEach((x) => p.append(k, String(x)));
    else p.set(k, String(v));
  });
  return p;
}

export interface Faltante {
  FAL_Faltante: number;
  TOM_Toma: number | null;
  ACT_Activo: string;
  codigo: string;
  serie: string;
  tipo: string;
  modelo: string;
  area: string | null;
  FAL_Estado: 'PENDIENTE' | 'EN_INVESTIGACION' | 'LOCALIZADO' | 'BAJA_SOLICITADA' | 'DADO_DE_BAJA';
  FAL_Fecha: string;
  FAL_Seguimiento: { fecha: string; usuario: string | null; accion: string; nota: string }[];
  FAL_Resolucion: string | null;
  FAL_Fecha_Resolucion: string | null;
  APR_Aprobacion: number | null;
}

export const inventarioService = {
  // --- Faltantes de la toma de inventario ---
  faltantes: async (filtros: { estado?: string; abiertos?: boolean } = {}) =>
    (await client.get<Faltante[]>(`/inventario/faltantes?${params(filtros)}`)).data,
  notaFaltante: async (id: number, nota: string) =>
    (await client.post<Faltante>(`/inventario/faltantes/${id}/seguimiento`, { nota })).data,
  faltanteLocalizado: async (id: number, nota: string, ARE_Area?: number | null) =>
    (await client.post<Faltante>(`/inventario/faltantes/${id}/localizado`, { nota, ARE_Area: ARE_Area ?? null })).data,
  bajaFaltante: async (id: number, datos: { motivo: string; destino: string; observacion?: string; documento?: string }) =>
    (await client.post<Faltante>(`/inventario/faltantes/${id}/baja`, datos)).data,

  // --- Cierre mensual (Contabilidad) ---
  cerrarMes: async (anio: number, mes: number, sede_id?: number) =>
    (await client.post('/inventario/cierre-mensual/cerrar', null, { params: { anio, mes, sede_id } })).data,
  cierreMensual: async (anio: number, mes: number, sede_id?: number) =>
    (await client.get(`/inventario/cierre-mensual?${params({ anio, mes, sede_id })}`)).data,
  cierreMensualXlsx: (anio: number, mes: number, sede_id: number | undefined, lang: SupportedLanguage) =>
    downloadFile(`/inventario/cierre-mensual.xlsx?${params({ anio, mes, sede_id, lang })}`,
      `cierre_${anio}${String(mes).padStart(2, '0')}.xlsx`),

  kardex: async (filtros: KardexFiltros, limite = 50, desplazamiento = 0): Promise<KardexPagina> =>
    (await client.get<KardexPagina>(`/inventario/kardex?${params({ ...filtros, limite, desplazamiento })}`)).data,
  kardexCsv: (filtros: KardexFiltros, lang: SupportedLanguage) =>
    downloadCsv(`/inventario/kardex.csv?${params({ ...filtros })}`, `kardex_${hoyLocal()}.csv`, lang),

  custodia: async (filtros: { fecha?: string; persona_id?: string; area_id?: number; sede_id?: number }) =>
    (await client.get<CustodiaItem[]>(`/inventario/custodia?${params(filtros)}`)).data,
  custodiaCsv: (filtros: { fecha?: string; sede_id?: number }, lang: SupportedLanguage) =>
    downloadCsv(`/inventario/custodia.csv?${params(filtros)}`, 'custodia.csv', lang),

  entradasSalidas: async (filtros: { desde?: string; hasta?: string; sede_id?: number }) =>
    (await client.get<ResumenEntradasSalidas>(`/inventario/entradas-salidas?${params(filtros)}`)).data,

  resumenMotivos: async (filtros: { desde?: string; hasta?: string; sede_id?: number }) =>
    (await client.get(`/inventario/resumen-motivos?${params(filtros)}`)).data,

  atencion: async () => (await client.get<{ items: ItemAtencion[] }>('/inventario/atencion')).data.items,

  hojaDeVida: (activoId: string, lang: SupportedLanguage) =>
    downloadFile(`/inventario/activos/${activoId}/hoja-de-vida.pdf`, 'hoja_de_vida.pdf', { lang }),
  licenciasCsv: (lang: SupportedLanguage) => downloadCsv('/inventario/licencias.csv', 'licencias.csv', lang),

  // --- Toma de inventario ---
  tomas: async () => (await client.get<Toma[]>('/inventario/tomas')).data,
  crearToma: async (data: { SED_Sede: number; ARE_Area?: number | null; TOM_Observacion?: string }) =>
    (await client.post<Toma>('/inventario/tomas', data)).data,
  toma: async (id: number) => (await client.get<TomaDetalle>(`/inventario/tomas/${id}`)).data,
  leer: async (id: number, codigo: string, areaId?: number | null) =>
    (await client.post<LecturaResultado>(`/inventario/tomas/${id}/lecturas`, { codigo, ARE_Area: areaId ?? null })).data,
  cerrarToma: async (id: number, actualizarUbicacion: boolean) =>
    (await client.post<TomaDetalle>(`/inventario/tomas/${id}/cerrar`, null,
      { params: { actualizar_ubicacion: actualizarUbicacion } })).data,
  anularToma: async (id: number) => (await client.post<Toma>(`/inventario/tomas/${id}/anular`)).data,
  reporteToma: (id: number, lang: SupportedLanguage) =>
    downloadCsv(`/inventario/tomas/${id}/reporte.csv`, `toma_${id}.csv`, lang),

  // --- Activo: componentes, baja y devolución ---
  componentes: async (activoId: string) =>
    (await client.get<ComponentesActivo>(`/core/activos/${activoId}/componentes`)).data,
  instalarComponente: async (activoId: string, componenteId: string, motivo?: string) =>
    (await client.post<ComponentesActivo>(`/core/activos/${activoId}/componentes`,
      { ACT_Componente: componenteId, motivo: motivo || undefined })).data,
  retirarComponente: async (activoId: string, componenteId: string, motivo: string | undefined) =>
    (await client.post<ComponentesActivo>(`/core/activos/${activoId}/componentes/${componenteId}/retirar`,
      { motivo: motivo || undefined })).data,
  /** Recalcula el sello encadenado del kardex (integridad verificable). */
  verificarKardex: async () => (await client.get<VerificacionKardex>('/inventario/kardex/verificar')).data,
  /** Solicita la baja: queda PENDIENTE hasta que otro administrador de TI la aprueba. */
  darDeBaja: async (activoId: string, data: BajaPayload) =>
    (await client.post<Aprobacion>(`/core/activos/${activoId}/baja`, data)).data,
  devolver: async (data: DevolucionPayload) =>
    (await client.post<DevolucionResultado>('/trazabilidad/devolucion', data)).data,
};
