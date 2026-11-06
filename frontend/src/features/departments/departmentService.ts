import client from '../../api/axios';

export interface DepartamentoResumen {
  DEP_Departamento: number;
  DEP_Nombre: string;
  DEP_Codigo_Costos: string | null;
  DEP_Activo: boolean;
  personas_activas: number;
  activos_asignados: number;
  por_tipo: Record<string, number>;
}

export interface ActivoAsignadoSummary {
  MOV_Movimiento: string;
  MOV_Fecha_Asignacion: string | null;
  activo: {
    ACT_Activo: string;
    ACT_Codigo_Interno: string;
    ACT_Hostname: string | null;
    ACT_Serie_Fabricante: string;
    tipo: string | null;
    modelo: string | null;
    marca: string | null;
  } | null;
  area: string | null;
}

export interface PersonaConActivos {
  PER_Persona: string;
  nombre_completo: string;
  PER_Email_Corporativo: string;
  PER_Telefono: string | null;
  PER_Estado: boolean;
  activos_asignados: ActivoAsignadoSummary[];
}

export interface DepartamentoDetalle {
  DEP_Departamento: number;
  DEP_Nombre: string;
  DEP_Codigo_Costos: string | null;
  DEP_Descripcion: string | null;
  DEP_Activo: boolean;
  personas: PersonaConActivos[];
  totales: { personas: number; activos: number };
}

export const departmentService = {
  list: async (): Promise<DepartamentoResumen[]> =>
    (await client.get<DepartamentoResumen[]>('/org/departamentos/resumen')).data,
  detail: async (id: number): Promise<DepartamentoDetalle> =>
    (await client.get<DepartamentoDetalle>(`/org/departamentos/${id}/detalle`)).data,
};
