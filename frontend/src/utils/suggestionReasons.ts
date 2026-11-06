import type { TFunction } from 'i18next';
import type { AreaOrigen, JefeOrigen, PersonaContexto, RolOrigen } from '../types/suggestions';

/** Motivos traducidos de las sugerencias relacionales (según su `origen`). */

export const areaReason = (t: TFunction, origen: AreaOrigen, name?: string | null): string =>
  origen === 'ultima_asignacion'
    ? t('suggestions.reasons.area_last', { name: name ?? '' })
    : t('suggestions.reasons.area_department');

const ROLE_REASON: Record<RolOrigen, string> = {
  cargo: 'suggestions.reasons.role_position',
  departamento: 'suggestions.reasons.role_department',
  minimo_privilegio: 'suggestions.reasons.role_least_privilege',
};

export const roleReason = (t: TFunction, origen: RolOrigen): string => t(ROLE_REASON[origen]);

export const managerReason = (t: TFunction, origen: JefeOrigen): string =>
  origen === 'registrado' ? t('suggestions.reasons.manager_registered') : t('suggestions.reasons.manager_department');

/** "Departamento · Cargo · Jefe: X" a partir del contexto de una persona. */
export const personContextLine = (t: TFunction, ctx: PersonaContexto, withManager = true): string =>
  [
    ctx.departamento?.nombre,
    ctx.cargo?.nombre,
    withManager && ctx.jefe?.nombre ? t('suggestions.info.manager', { name: ctx.jefe.nombre }) : null,
  ].filter(Boolean).join(' · ');

/** Lista corta de códigos: "LAP-001, LAP-002 +3". */
export const shortList = (items: string[], max = 5): string =>
  items.length > max ? `${items.slice(0, max).join(', ')} +${items.length - max}` : items.join(', ');
