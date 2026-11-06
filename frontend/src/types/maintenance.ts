import { Activo } from './inventory';
import { PersonaResumen } from './users';

// --- CATÁLOGOS ---
export interface TipoMantenimiento {
  TMA_Tipo_Mantenimiento: number;
  TMA_Nombre: string;
}

export interface TipoMantenimientoCreate {
  TMA_Nombre: string;
}

// --- DETALLES ---
export interface DetalleMantenimiento {
  DMA_Detalle_Mant: number;
  DMA_Accion_Realizada: string;
  DMA_Costo_Item: number;
  /** Acción del catálogo (acciones-mantenimiento). */
  AMA_Accion?: number | null;
}

export interface DetalleCreate {
  /** Detalle escrito; con acción del catálogo es opcional. */
  DMA_Accion_Realizada: string;
  DMA_Costo_Item: number;
  AMA_Accion?: number | null;
}

export interface Mantenimiento {
  MAN_Mantenimiento: string; 
  MAN_Fecha_Ingreso: string;
  MAN_Fecha_Cierre: string | null;
  MAN_Descripcion_Falla: string;
  /** Tipo de falla del catálogo (tipos-falla). */
  TFA_Tipo_Falla?: number | null;
  MAN_Costo_Total: number;
  /** Moneda del ticket: aplica al total y a cada detalle. */
  MAN_Moneda: string;

  ACT_Activo: string;
  PER_Persona_Solicita: string;
  TMA_Tipo_Mantenimiento: number;

  activo?: Activo;
  persona_solicita?: PersonaResumen; 
  tipo_mantenimiento?: TipoMantenimiento;
  detalles?: DetalleMantenimiento[];
}

export interface MantenimientoCreate {
  ACT_Activo: string;
  PER_Persona_Solicita: string;
  TMA_Tipo_Mantenimiento: number;
  MAN_Descripcion_Falla: string;
  TFA_Tipo_Falla?: number | null;
  MAN_Costo_Total?: number;
  MAN_Moneda?: string;
  detalles?: DetalleCreate[];
}