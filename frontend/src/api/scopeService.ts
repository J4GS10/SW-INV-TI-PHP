import client from './axios';
import type { SesionActiva } from '../types/users';

/** Sede visible para el usuario (todas si su alcance es global). */
export interface SedeAlcance {
  SED_Sede: number;
  SED_Nombre: string;
  /** "Municipio, País". */
  ubicacion?: string;
}

/** Alcance de datos por sede y sesiones del propio usuario. */
export const scopeService = {
  getSedes: async (): Promise<SedeAlcance[]> => (await client.get<SedeAlcance[]>('/org/sedes')).data,

  mySessions: async (): Promise<SesionActiva[]> => (await client.get<SesionActiva[]>('/me/sessions')).data,

  closeMySession: async (id: string): Promise<void> => {
    await client.delete(`/me/sessions/${id}`);
  },
};
