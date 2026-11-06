import { createContext, useContext, useEffect, useRef } from 'react';
import type { Activo } from '../types/inventory';
import type { PersonaResumen } from '../types/users';

/** Registro abierto hace poco (se muestra en la paleta de comandos). */
export interface Reciente {
  tipo: 'activo' | 'persona' | 'orden';
  id: string;
  titulo: string;
  subtitulo?: string;
  ruta: string;
}

export interface EntregaOpciones {
  activos?: Activo[];
  persona?: PersonaResumen | null;
  onDone?: () => void;
}

export interface DevolucionOpciones {
  activos: Activo[];
  onDone?: () => void;
}

/** Acciones que una página ofrece al teclado (p. ej. «n» = nuevo). */
export type AccionPagina = 'nuevo';

export interface WorkspaceValue {
  abrirPaleta: () => void;
  abrirAtajos: () => void;
  abrirEntrega: (opciones?: EntregaOpciones) => void;
  abrirDevolucion: (opciones: DevolucionOpciones) => void;
  recientes: Reciente[];
  agregarReciente: (r: Reciente) => void;
  favoritos: string[];
  alternarFavorito: (ruta: string) => void;
  registrarAccion: (accion: AccionPagina, fn: () => void) => () => void;
}

export const WorkspaceContext = createContext<WorkspaceValue | null>(null);

export function useWorkspace(): WorkspaceValue {
  const ctx = useContext(WorkspaceContext);
  if (!ctx) throw new Error('useWorkspace debe usarse dentro de WorkspaceProvider');
  return ctx;
}

/** Registra la acción de teclado de la página mientras está montada. */
export function useAccionPagina(accion: AccionPagina, fn: (() => void) | null): void {
  const { registrarAccion } = useWorkspace();
  const ref = useRef(fn);
  useEffect(() => { ref.current = fn; });
  const activa = fn !== null;
  useEffect(() => {
    if (!activa) return undefined;
    return registrarAccion(accion, () => ref.current?.());
  }, [accion, activa, registrarAccion]);
}
