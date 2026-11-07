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

