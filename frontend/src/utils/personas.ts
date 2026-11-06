import type { PersonaResumen } from '../types/users';

type Nombres = Pick<PersonaResumen, 'PER_Primer_Nombre' | 'PER_Segundo_Nombre' | 'PER_Primer_Apellido' | 'PER_Segundo_Apellido'>;

/** Nombre completo de una persona (omite los campos vacíos). */
export const nombrePersona = (p: Nombres): string =>
  [p.PER_Primer_Nombre, p.PER_Segundo_Nombre, p.PER_Primer_Apellido, p.PER_Segundo_Apellido].filter(Boolean).join(' ');
