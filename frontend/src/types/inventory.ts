import type { ClaseActivo, ReglasClase } from '../utils/clasesActivo';
// --- CATÁLOGOS ---
export interface Marca {
  MAR_Marca: number;
  MAR_Nombre: string;
}

export interface Modelo {
  MOD_Modelo: number;
  MOD_Nombre: string;
  MAR_Marca: number;
  TAC_Tipo_Activo?: number | null;
}

/** Modelo con su marca embebida (`/cat/modelos-flat`), para selects. */
export interface ModeloFlat extends Modelo {
  MAR_Nombre: string;
}

export interface TipoActivo {
  TAC_Tipo_Activo: number;
  TAC_Nombre: string;
  TAC_Prefijo: string | null;
  /** Clase y reglas de operación (ver utils/clasesActivo.ts). */
  TAC_Clase?: ClaseActivo;
  reglas?: ReglasClase;
}

export interface EstadoOperativo {
  EOP_Estado_Operativo: number;
  EOP_Nombre: string;
}

// --- CORE ---
export interface Especificacion {
  ESP_Especificacion: number;
  TES_Tipo_Especificacion: number;
  ESP_Valor: string;
}

export interface Activo {
  /** Quién lo tiene; solo llega a los roles que pueden ver personas. */
  custodio?: string | null;
  ACT_Activo: string; // UUID
  ACT_Codigo_Interno: string;
  /** Null while the asset is still to be completed. */
  ACT_Serie_Fabricante: string | null;
  pending_completion?: boolean;
  ACT_Hostname: string | null;
  ACT_Fecha_Compra: string;
  ACT_Fin_Garantia: string | null;
  ACT_Costo: number | null;
  ACT_Moneda: string;

  // FKs
  MOD_Modelo: number;
  TAC_Tipo_Activo: number;
  EOP_Estado_Operativo: number;
  ACT_Activo_Padre: string | null;
  ARE_Area_Ubicacion?: number | null;
  ACT_Baja_Motivo?: string | null;
  ACT_Baja_Destino?: string | null;
  ACT_Baja_Fecha?: string | null;
  ACT_Baja_Documento?: string | null;
  /** Sede a la que pertenece (alcance de datos). */
  SED_Sede?: number | null;
  /** Equipo compartido instalado en un área y su responsable (no es custodia). */
  ACT_Instalado?: boolean;
  ACT_Responsable?: string | null;

  especificaciones?: Especificacion[];
}

// DTO para Creación
export interface ActivoCreate {
  ACT_Codigo_Interno?: string; // Opcional (auto-generado por backend)
  ACT_Serie_Fabricante: string;
  ACT_Hostname?: string;
  ACT_Fecha_Compra: string; // YYYY-MM-DD
  ACT_Fin_Garantia?: string;
  ACT_Costo?: number;
  ACT_Moneda?: string;

  MOD_Modelo: number;
  TAC_Tipo_Activo: number;
  EOP_Estado_Operativo: number;
  SED_Sede?: number | null;

  especificaciones?: { TES_Tipo_Especificacion: number; ESP_Valor: string }[];
}