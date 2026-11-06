export interface Proveedor {
  PRV_Proveedor: number;
  PRV_Nombre: string;
  PRV_Identificacion_Fiscal?: string | null;
  PRV_Contacto?: string | null;
  PRV_Email?: string | null;
  PRV_Telefono?: string | null;
  PRV_Direccion?: string | null;
  PRV_Activo: boolean;
}

export interface ProveedorCreate {
  PRV_Nombre: string;
  PRV_Identificacion_Fiscal?: string;
  PRV_Contacto?: string;
  PRV_Email?: string;
  PRV_Telefono?: string;
  PRV_Direccion?: string;
}

export type TipoLinea = 'ACTIVO' | 'CONSUMIBLE' | 'LICENCIA' | 'OTRO';
export const TIPOS_LINEA: TipoLinea[] = ['ACTIVO', 'CONSUMIBLE', 'LICENCIA', 'OTRO'];
export type EstadoOrden = 'BORRADOR' | 'PARCIAL' | 'RECIBIDA' | 'CERRADA_CON_FALTANTE' | 'CANCELADA';
export const ESTADOS_ORDEN: EstadoOrden[] = ['BORRADOR', 'PARCIAL', 'RECIBIDA', 'CERRADA_CON_FALTANTE', 'CANCELADA'];

export interface LineaOrden {
  OCL_Linea?: number;
  OCL_Descripcion: string;
  OCL_Cantidad: number;
  OCL_Precio_Unitario: string | number;
  OCL_Subtotal?: string | number;
  /** Qué se compra: la recepción de la línea solo acepta eso. */
  OCL_Tipo?: TipoLinea | null;
  OCL_Cantidad_Recibida?: number;
  OCL_Pendiente?: number;
  ACT_Activo?: string | null;
  CON_Consumible?: number | null;
  LIC_Licencia?: number | null;
}

export interface OrdenCompra {
  OCO_Orden: number;
  OCO_Numero: string;
  OCO_Fecha: string;
  OCO_Estado: EstadoOrden;
  OCO_Motivo_Cierre?: string | null;
  OCO_Moneda: string;
  OCO_Total: string | number;
  OCO_Notas?: string | null;
  PRV_Proveedor: number;
  SED_Sede?: number | null;
  proveedor?: { PRV_Proveedor: number; PRV_Nombre: string } | null;
  created_at: string;
  lineas?: LineaOrden[];
}

export interface OrdenCreate {
  OCO_Numero: string;
  OCO_Fecha: string;
  OCO_Moneda: string;
  OCO_Notas?: string;
  PRV_Proveedor: number;
  /** Sede que recibe la compra (alcance de datos). */
  SED_Sede?: number | null;
  lineas: LineaOrden[];
}

export interface LicenciaClaveCreate {
  LCL_Clave_Activacion: string;
  LCL_Referencia?: string | null;
}

export interface Garantia {
  ACT_Activo: string;
  ACT_Codigo_Interno: string;
  ACT_Serie_Fabricante: string;
  ACT_Fecha_Compra: string;
  ACT_Fin_Garantia?: string | null;
  dias_restantes?: number | null;
  estado_garantia: 'vigente' | 'por_vencer' | 'vencida' | 'sin_garantia';
  proveedor?: string | null;
}

export interface PendienteLinea {
  OCL_Linea: number;
  descripcion: string;
  tipo?: TipoLinea | null;
  pedido: number;
  recibido: number;
  pendiente: number;
}

export interface DiferenciaRegistrada {
  OCL_Linea: number;
  tipo: 'SOBRANTE' | 'PRECIO' | string;
  detalle: string;
  motivo?: string | null;
}

export interface RecepcionLinea {
  OCL_Linea: number;
  RCL_Cantidad: number;
  RCL_Precio_Real?: string | number | null;
  RCL_Diferencia?: string | null;
  RCL_Motivo?: string | null;
}

export interface Recepcion {
  REC_Recepcion: number;
  OCO_Orden: number;
  REC_Fecha: string;
  REC_Documento?: string | null;
  REC_Observacion?: string | null;
  REC_Resultado: string;
  lineas: RecepcionLinea[];
}
