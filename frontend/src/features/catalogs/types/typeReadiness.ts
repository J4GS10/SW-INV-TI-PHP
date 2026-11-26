/**
 * ¿Está listo un tipo de activo para registrar equipos? Una lista corta y explicable:
 * lo que impide registrar (error), lo que conviene completar (aviso) y lo que ya está bien (ok).
 */
import type { ClaseActivo } from '../../../utils/clasesActivo';

/** Clases que admiten componentes internos (espejo de App\Catalogo\ClasesActivo). */
export const CON_COMPONENTES: ClaseActivo[] = ['COMPUTO', 'IMPRESION', 'RED', 'ENERGIA'];

export type ReadinessLevel = 'ok' | 'warning' | 'error';

export interface ReadinessItem {
  /** Clave de texto en `asset_type_setup.checks.<clave>`. */
  key: 'name' | 'prefix' | 'class' | 'category' | 'specs' | 'components';
  level: ReadinessLevel;
  /** Datos para el texto (cuántas características, cuántos obligatorios…). */
  values?: Record<string, number>;
}

export interface ReadinessInput {
  name: string;
  prefix: string;
  /** Clase del tipo; vacía = la deduce el servidor del nombre. */
  assetClass: string;
  /** La clase admite componentes internos (Cómputo, Impresión, Red, Energía). */
  classHasComponents: boolean;
  /** Los insumos no se registran como activo (van a consumibles). */
  isSupply: boolean;
  hasCategory: boolean;
  specs: { required: boolean }[];
  components: { required: boolean }[];
}

export function typeReadiness(input: ReadinessInput): ReadinessItem[] {
  const items: ReadinessItem[] = [];
  items.push({ key: 'name', level: input.name.trim().length >= 2 ? 'ok' : 'error' });
  items.push({ key: 'prefix', level: /^[A-Za-z0-9]{2,5}$/.test(input.prefix.trim()) ? 'ok' : 'error' });
  items.push({ key: 'class', level: input.isSupply ? 'error' : input.assetClass ? 'ok' : 'warning' });
  items.push({ key: 'category', level: input.hasCategory ? 'ok' : 'warning' });
  const requiredSpecs = input.specs.filter((s) => s.required).length;
  items.push({ key: 'specs', level: input.specs.length > 0 ? 'ok' : 'warning', values: { count: input.specs.length, required: requiredSpecs } });
  if (input.classHasComponents) {
    const requiredComponents = input.components.filter((c) => c.required).length;
    items.push({ key: 'components', level: input.components.length > 0 ? 'ok' : 'warning',
      values: { count: input.components.length, required: requiredComponents } });
  }
  return items;
}

/** El tipo sirve para registrar activos si nada es un error. */
export const isReady = (items: ReadinessItem[]): boolean => items.every((i) => i.level !== 'error');
