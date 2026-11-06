/**
 * Clase de un tipo de activo (espejo de app/core/clases_activo.py): decide si
 * el equipo se asigna a una persona o se instala en un área, si lleva software
 * y componentes internos, si tiene identidad de red y si usa consumibles.
 */
export type ClaseActivo = 'COMPUTO' | 'MOVIL' | 'PERIFERICO' | 'IMPRESION' | 'RED' | 'ENERGIA' | 'AUDIOVISUAL'
  | 'COMPONENTE' | 'HERRAMIENTA' | 'INSUMO' | 'OTRO';

export type UsoActivo = 'PERSONAL' | 'COMPARTIDO' | 'COMPONENTE' | 'INSUMO';

export interface ReglasClase {
  clase: ClaseActivo;
  uso: UsoActivo;
  software: boolean;
  componentes: boolean;
  red: boolean;
  consumibles: boolean;
}

export const CLASES_ACTIVO: ClaseActivo[] = ['COMPUTO', 'MOVIL', 'PERIFERICO', 'IMPRESION', 'RED', 'ENERGIA',
  'AUDIOVISUAL', 'COMPONENTE', 'HERRAMIENTA', 'INSUMO', 'OTRO'];

const POR_DEFECTO: ReglasClase = {
  clase: 'OTRO', uso: 'PERSONAL', software: false, componentes: false, red: false, consumibles: false,
};

/** Reglas del tipo (el servidor las envía en `reglas`; sin tipo, las de «Otro»). */
export function reglasDe(tipo?: { reglas?: ReglasClase } | null): ReglasClase {
  return tipo?.reglas ?? POR_DEFECTO;
}

export const esCompartido = (tipo?: { reglas?: ReglasClase } | null) => reglasDe(tipo).uso === 'COMPARTIDO';
