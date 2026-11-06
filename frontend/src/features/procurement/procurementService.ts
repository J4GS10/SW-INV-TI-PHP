import client from '../../api/axios';
import {
  Proveedor, ProveedorCreate, OrdenCompra, OrdenCreate, Garantia, LicenciaClaveCreate,
  PendienteLinea, DiferenciaRegistrada, Recepcion,
} from '../../types/procurement';

export interface RecibirOrdenConsumiblePayload {
  OCL_Linea: number;
  CON_Consumible: number;
  cantidad: number;
  /** Precio unitario real facturado (si se omite, el de la orden). */
  precio_unitario?: string | null;
}

export interface RecibirOrdenActivoPayload {
  OCL_Linea: number;
  ACT_Serie_Fabricante: string;
  MOD_Modelo: number;
  TAC_Tipo_Activo: number;
  ACT_Hostname: string | null;
  ACT_Fecha_Compra: string;
  ACT_Fin_Garantia: string | null;
  ACT_Costo: number | null;
}

export interface RecibirOrdenLicenciaPayload {
  OCL_Linea: number;
  precio_unitario?: string | null;
  SOF_Software: number;
  TLI_Tipo_Licencia: number;
  cantidad?: number;
  LIC_Fecha_Vencimiento?: string | null;
  LIC_Clave_Activacion?: string | null;
  claves: LicenciaClaveCreate[];
}

export interface RecibirOrdenPayload {
  consumibles: RecibirOrdenConsumiblePayload[];
  activos: RecibirOrdenActivoPayload[];
  licencias: RecibirOrdenLicenciaPayload[];
  /** Conformidad de fletes o servicios (líneas OTRO): no generan inventario. */
  otros?: { OCL_Linea: number; cantidad: number; precio_unitario?: string | null }[];
  /** Guía de remisión o factura con que llegó la entrega. */
  documento?: string;
  observacion?: string;
  /** Justificación de sobrantes o precios fuera de tolerancia. */
  diferencias?: { OCL_Linea: number; motivo: string; aceptar_sobrante?: boolean }[];
}

export interface RecibirOrdenResponse {
  OCO_Orden: number;
  OCO_Estado: OrdenCompra['OCO_Estado'];
  REC_Recepcion?: number | null;
  consumibles_reabastecidos: number;
  activos_creados: number;
  licencias_creadas: number;
  licencias_claves_creadas: number;
  activos_codigos: string[];
  pendientes: PendienteLinea[];
  diferencias: DiferenciaRegistrada[];
  /** Traía un sobrante: quedó pendiente de aprobación dotro administrador de TI (no se registró nada). */
  aprobacion_pendiente?: number | null;
}

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

  // ---- Órdenes ----
  listOrdenes: async (estado?: string): Promise<OrdenCompra[]> => {
    const q = estado ? `?estado=${estado}` : '';
    const r = await client.get<OrdenCompra[]>(`/compras/ordenes${q}`);
    return r.data;
  },
  getOrden: async (id: number): Promise<OrdenCompra> => {
    const r = await client.get<OrdenCompra>(`/compras/ordenes/${id}`);
    return r.data;
  },
  createOrden: async (data: OrdenCreate): Promise<OrdenCompra> => {
    const r = await client.post<OrdenCompra>('/compras/ordenes', data);
    return r.data;
  },
  /** Cancelar se solicita (202): la orden cambia cuando otro administrador de TI lo aprueba. */
  cambiarEstado: async (id: number, estado: string, motivo?: string): Promise<{ APR_Aprobacion: number }> => {
    const r = await client.patch<{ APR_Aprobacion: number }>(`/compras/ordenes/${id}/estado`, { OCO_Estado: estado, motivo });
    return r.data;
  },
  /** Cierra una orden PARCIAL aceptando que lo pendiente no llegará. */
  /** Cerrar con faltante se solicita (202): se cierra cuando otro administrador de TI lo aprueba. */
  cerrarConFaltante: async (id: number, motivo: string): Promise<{ APR_Aprobacion: number }> => {
    const r = await client.post<{ APR_Aprobacion: number }>(`/compras/ordenes/${id}/cerrar`, { motivo });
    return r.data;
  },
  recepciones: async (id: number): Promise<Recepcion[]> => {
    const r = await client.get<Recepcion[]>(`/compras/ordenes/${id}/recepciones`);
    return r.data;
  },

  recibir: async (
    id: number,
    payload: RecibirOrdenPayload,
  ): Promise<RecibirOrdenResponse> => {
    const r = await client.post<RecibirOrdenResponse>(`/compras/ordenes/${id}/recibir`, payload);
    return r.data;
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
