import type { ClaseActivo, ReglasClase } from '../utils/clasesActivo';
// src/types/catalogs.ts

// --- 1. MARCAS ---
export interface Marca {
  MAR_Marca: number;
  MAR_Nombre: string;
}

export interface MarcaCreate {
  MAR_Nombre: string;
}

// --- 2. TIPOS DE ACTIVO ---
export interface TipoActivo {
  TAC_Tipo_Activo: number;
  TAC_Nombre: string;
  TAC_Prefijo: string | null;
  TAC_Aplica_Depreciacion: boolean;
  TAC_Clase?: ClaseActivo;
  reglas?: ReglasClase;
}

export interface TipoActivoCreate {
  TAC_Nombre: string;
  TAC_Prefijo?: string;
  TAC_Aplica_Depreciacion?: boolean;
  TAC_Clase?: ClaseActivo;
}

// --- 3. TIPOS DE CONEXIÓN ---
export interface TipoConexion {
  TCN_Tipo_Conexion: number;
  TCN_Nombre: string;
  TCN_Descripcion?: string | null;
}

export interface TipoConexionCreate {
  TCN_Nombre: string;
  TCN_Descripcion?: string;
}

// --- 4. MODELOS ---
export interface Modelo {
  MOD_Modelo: number;
  MOD_Nombre: string;
  MOD_Anio_Lanzamiento?: number | null;
  MAR_Marca: number; // FK
  TCN_Tipo_Conexion?: number | null; // FK
  TAC_Tipo_Activo?: number | null; // FK (cascada por tipo)
}

export interface ModeloCreate {
  MOD_Nombre: string;
  MOD_Anio_Lanzamiento?: number;
  MAR_Marca: number;
  TCN_Tipo_Conexion?: number;
  TAC_Tipo_Activo?: number | null;
}

// --- 5. ESTADOS OPERATIVOS ---
export interface EstadoOperativo {
  EOP_Estado_Operativo: number;
  EOP_Nombre: string;
  EOP_Descripcion?: string | null;
}

export interface EstadoOperativoCreate {
  EOP_Nombre: string;
  EOP_Descripcion?: string;
}

// --- 6. TIPOS DE ESPECIFICACIÓN (EAV) ---
export interface TipoEspecificacion {
  TES_Tipo_Especificacion: number;
  TES_Nombre: string;
  TES_Unidad_Medida?: string | null;
}

export interface TipoEspecificacionCreate {
  TES_Nombre: string;
  TES_Unidad_Medida?: string;
}