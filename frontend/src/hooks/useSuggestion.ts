import { useEffect, useEffectEvent, useRef } from 'react';

export type SuggestionValue = string | number | null;

export interface Suggestion<V extends SuggestionValue> {
  value: V;
  /** Motivo ya traducido ("último destino de Ana Pérez"). */
  reason: string;
  /** Texto legible del valor sugerido, para mostrarlo si el usuario lo cambió. */
  label?: string;
}

export interface SuggestionHintState {
  reason: string;
  label?: string;
  /** El valor actual difiere de la sugerencia (el usuario lo cambió). */
  modified: boolean;
  /** Vuelve a aplicar la sugerencia. */
  reset: () => void;
}

interface Options<V extends SuggestionValue> {
  /** Valor actual del campo. */
  current: V;
  /** Escribe un valor en el campo. */
  apply: (value: V) => void;
  /**
   * `undefined`: el contexto aún carga (no se toca nada).
   * `null`: no hay sugerencia para la selección actual.
   */
  suggestion: Suggestion<V> | null | undefined;
  /** Valor "vacío" del campo (o su valor por defecto sin elegir). */
  empty: V;
  enabled?: boolean;
}

const NONE = Symbol('none');

/**
 * Prellenado relacional con una regla simple: el campo se rellena solo si
 * está vacío o conserva una sugerencia anterior que el usuario no tocó.
 * Nunca se sobrescribe un valor escrito o elegido por el usuario.
 * Si la selección de origen cambia y ya no hay sugerencia, una sugerencia
 * previa sin tocar se limpia (pertenecía a otra selección).
 */
export function useSuggestion<V extends SuggestionValue>({
  current,
  apply,
  suggestion,
  empty,
  enabled = true,
}: Options<V>): SuggestionHintState | null {
  const applied = useRef<V | typeof NONE>(NONE);
  const active = enabled ? suggestion : null;
  const key = !enabled ? 'off' : active === undefined ? 'pending' : active === null ? 'none' : `v:${String(active.value)}`;

  const sync = useEffectEvent(() => {
    // Desactivada o cargando: no se toca el campo.
    if (!enabled || active === undefined) return;
    const isEmpty = current === empty || current === null || current === '';
    const untouched = isEmpty || (applied.current !== NONE && current === applied.current);
    if (active) {
      if (!untouched) return;
      applied.current = active.value;
      if (current !== active.value) apply(active.value);
    } else if (untouched) {
      if (!isEmpty) apply(empty);
      applied.current = NONE;
    }
  });

  useEffect(() => {
    sync();
  }, [key]);

  if (!active) return null;
  const { value } = active;
  return {
    reason: active.reason,
    label: active.label,
    modified: current !== value,
    reset: () => {
      applied.current = value;
      apply(value);
    },
  };
}
