import client from './axios';
import type { ActivoContexto, DepartamentoContexto, ModeloContexto, PersonaContexto } from '../types/suggestions';

/**
 * Contextos relacionales para autocompletar formularios. Todas las llamadas
 * aceptan un `AbortSignal` para descartar respuestas de una selección anterior.
 */
export const suggestionService = {
  persona: async (id: string, signal?: AbortSignal): Promise<PersonaContexto> =>
    (await client.get<PersonaContexto>(`/org/personas/${id}/contexto`, { signal })).data,

  departamento: async (id: number, signal?: AbortSignal): Promise<DepartamentoContexto> =>
    (await client.get<DepartamentoContexto>(`/org/departamentos/${id}/contexto`, { signal })).data,

  activo: async (id: string, signal?: AbortSignal): Promise<ActivoContexto> =>
    (await client.get<ActivoContexto>(`/core/activos/${id}/contexto`, { signal })).data,

  modelo: async (id: number, signal?: AbortSignal): Promise<ModeloContexto> =>
    (await client.get<ModeloContexto>(`/cat/modelos/${id}/contexto`, { signal })).data,
};
