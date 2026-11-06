import type { UserRole } from './auth';

/**
 * Contextos relacionales que el backend devuelve para autocompletar formularios.
 * Cada sugerencia trae su `origen` para explicar POR QUÉ se propone.
 */

export interface PersonaRef {
  PER_Persona: string;
  nombre: string | null;
  email: string | null;
}

export type JefeOrigen = 'registrado' | 'departamento';
export type AreaOrigen = 'ultima_asignacion' | 'departamento' | 'movimiento_vigente';
export type RolOrigen = 'cargo' | 'departamento' | 'minimo_privilegio';

export interface AreaSugerida {
  ARE_Area: number;
  nombre: string;
  /** Sede del área (solo se sugieren áreas de las sedes del alcance). */
  SED_Sede?: number | null;
  origen: AreaOrigen;
}

export type SedeOrigen = 'persona' | 'area' | 'alcance' | 'departamento' | 'activo';

export interface SedeSugerida {
  SED_Sede: number;
  nombre: string;
  origen: SedeOrigen;
}

export interface PersonaContexto {
  persona: PersonaRef;
  departamento: { DEP_Departamento: number; nombre: string } | null;
  cargo: { CAR_Cargo: number; nombre: string } | null;
  jefe: (PersonaRef & { origen: JefeOrigen }) | null;
  area_sugerida: AreaSugerida | null;
  /** Códigos internos de los activos que tiene en custodia. */
  activos_vigentes: string[];
  usuario: { USU_Usuario: string; username: string; rol: UserRole } | null;
  /** Nulo si la persona ya tiene usuario. */
  username_sugerido: string | null;
  /** Nunca SUPER_ADMIN ni ADMIN_SEGURIDAD. */
  rol_sugerido: { rol: UserRole; origen: RolOrigen } | null;
  sede_sugerida?: SedeSugerida | null;
  /** Alcance sugerido para un usuario nuevo: la sede de la persona (nunca global). */
  alcance_sugerido?: { sedes: number[]; origen: SedeOrigen } | null;
}

export interface DepartamentoContexto {
  cargo_sugerido: { CAR_Cargo: number; nombre: string; origen: 'departamento' } | null;
  jefe_sugerido: (PersonaRef & { origen: JefeOrigen }) | null;
  area_sugerida: AreaSugerida | null;
  sede_sugerida?: SedeSugerida | null;
}

export interface ActivoContexto {
  custodio: (PersonaRef & { departamento: string | null }) | null;
  area_actual: AreaSugerida | null;
  tipo_movimiento_sugerido: 'asignacion' | 'transferencia';
  hostname: string | null;
  sede?: SedeSugerida | null;
}

export interface ModeloContexto {
  marca: { MAR_Marca: number; nombre: string } | null;
  tipo_sugerido: {
    TAC_Tipo_Activo: number;
    nombre: string;
    prefijo: string | null;
    origen: 'activos_del_modelo';
  } | null;
}
