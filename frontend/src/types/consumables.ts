export interface Consumible {
  CON_Consumible: number;
  CON_Nombre: string;
  CON_Descripcion?: string | null;
  CON_Categoria?: string | null;
  CON_Unidad: string;
  /** Catálogos de categoría y unidad (los textos quedan con su nombre). */
  CCO_Categoria?: number | null;
  UME_Unidad?: number | null;
  CON_Stock_Actual: number;
  CON_Stock_Minimo: number;
  CON_Activo: boolean;
  bajo_stock: boolean;
  /** Último costo unitario de compra y su moneda (valoriza el stock). */
  CON_Costo_Unitario?: string | number | null;
  CON_Moneda?: string | null;
  /** Bodega (sede) donde se almacena. */
  SED_Sede?: number | null;
}

export interface ConsumibleCreate {
  CON_Nombre: string;
  CON_Descripcion?: string;
  CON_Categoria?: string;
  CON_Unidad: string;
  CCO_Categoria?: number | null;
  UME_Unidad?: number | null;
  CON_Stock_Minimo: number;
  CON_Stock_Actual: number;
  SED_Sede?: number | null;
  /** Costo unitario del saldo inicial y su moneda. */
  CON_Costo_Unitario?: string | null;
  CON_Moneda?: string | null;
}

export interface MovimientoConsumible {
  MOC_Movimiento: number;
  MOC_Tipo: 'ENTRADA' | 'SALIDA' | 'AJUSTE';
  MOC_Cantidad: number;
  MOC_Stock_Resultante: number;
  /** La salida dejó el stock en el mínimo o por debajo. */
  bajo_minimo?: boolean;
  CON_Stock_Minimo?: number | null;
  MOC_Motivo?: string | null;
  MOC_Fecha: string;
  CON_Consumible: number;
  PER_Persona?: string | null;
  USU_Usuario?: string | null;
}

export interface StockMovimientoCreate {
  MOC_Cantidad: number;
  MOC_Motivo?: string;
  PER_Persona?: string;
  /** Solo en salidas: equipo en que se instala (tóner en una impresora). */
  ACT_Activo?: string;
  /** Solo en entradas: costo unitario y su moneda. */
  precio_unitario?: string;
  moneda?: string;
}

export interface StockAjusteCreate {
  CON_Stock_Real: number;
  MOC_Motivo: string;
}
