import client from '../../api/axios';
import { PersonaResumen } from '../../types/users';

export interface PersonaCreate {
  PER_Primer_Nombre: string;
  PER_Segundo_Nombre?: string;
  PER_Primer_Apellido: string;
  PER_Segundo_Apellido?: string;
  PER_Email_Corporativo: string;
  PER_Telefono?: string;
  DEP_Departamento: number;
  CAR_Cargo: number;
  /** Jefe inmediato (opcional). */
  PER_Jefe?: string | null;
  /** Sede donde trabaja (alcance de datos). */
  SED_Sede?: number | null;
}

export interface PersonaUpdate {
  PER_Primer_Nombre?: string;
  PER_Segundo_Nombre?: string;
  PER_Primer_Apellido?: string;
  PER_Segundo_Apellido?: string;
  PER_Email_Corporativo?: string;
  PER_Telefono?: string;
  DEP_Departamento?: number;
  CAR_Cargo?: number;
  PER_Jefe?: string | null;
  SED_Sede?: number | null;
  PER_Estado?: boolean;
}

export interface ActivoVigente {
  MOV_Movimiento: string;
  MOV_Fecha_Asignacion: string;
  MOV_Observacion: string | null;
  activo?: { ACT_Codigo_Interno: string; ACT_Hostname: string | null };
  area?: { ARE_Nombre: string };
  tipo_movimiento?: { TMO_Nombre: string };
}

export interface OffboardingResult {
  status: string;
  persona_id: string;
  movimientos_cerrados: number;
  activos_devueltos_a_bodega: number;
  usuario_desactivado: boolean;
  persona_inactivada: boolean;
}

export const personService = {
  list: async (): Promise<PersonaResumen[]> => {
    const r = await client.get<PersonaResumen[]>('/org/personas');
    return r.data;
  },
  get: async (id: string): Promise<PersonaResumen> => {
    const r = await client.get<PersonaResumen>(`/org/personas/${id}`);
    return r.data;
  },
  create: async (data: PersonaCreate): Promise<PersonaResumen> => {
    const r = await client.post<PersonaResumen>('/org/personas', data);
    return r.data;
  },
  update: async (id: string, data: PersonaUpdate): Promise<PersonaResumen> => {
    const r = await client.patch<PersonaResumen>(`/org/personas/${id}`, data);
    return r.data;
  },
  delete: async (id: string): Promise<void> => {
    await client.delete(`/org/personas/${id}`);
  },
  asignacionesVigentes: async (id: string): Promise<ActivoVigente[]> => {
    const r = await client.get<ActivoVigente[]>(`/trazabilidad/persona/${id}/asignaciones`);
    return r.data;
  },
  offboarding: async (id: string, desactivarUsuario = true): Promise<OffboardingResult> => {
    const r = await client.post<OffboardingResult>(
      `/trazabilidad/persona/${id}/offboarding?desactivar_usuario=${desactivarUsuario}`,
    );
    return r.data;
  },
};
