import type { TFunction } from 'i18next';
import type { StatusVariant } from '../components/ui/StatusChip';

/**
 * Los estados operativos y tipos de movimiento vienen del catálogo de la BD
 * en español ("En Bodega", "Asignación"). Los valores canónicos del sistema se
 * traducen por una clave estable; los que agregue el usuario se muestran tal cual.
 */
export const catalogSlug = (value: string): string =>
  (value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim().replace(/\s+/g, '_');

export const stateLabel = (t: TFunction, name?: string | null): string =>
  name ? t(`catalog.states.${catalogSlug(name)}`, { defaultValue: name }) : '—';

export const movementTypeLabel = (t: TFunction, name?: string | null): string =>
  name ? t(`catalog.movement_types.${catalogSlug(name)}`, { defaultValue: name }) : '—';

const STATE_VARIANT: Record<string, StatusVariant> = {
  asignado: 'asignado',
  // Equipo compartido en uso en un área: mismo tono que «en uso».
  instalado: 'asignado',
  en_bodega: 'bodega',
  en_reparacion: 'reparacion',
  en_transito: 'por_vencer',
  baja: 'baja',
};

/** Color del chip según el estado canónico (independiente del idioma). */
export const stateVariant = (name?: string | null): StatusVariant =>
  STATE_VARIANT[catalogSlug(name ?? '')] ?? 'default';

/** Tipos de activo del catálogo base (Laptop, Monitor…); los propios se muestran tal cual. */
export const typeLabel = (t: TFunction, name?: string | null): string =>
  name ? t(`catalog.asset_types.${catalogSlug(name)}`, { defaultValue: name }) : '—';

/** Tipos de mantenimiento (Preventivo, Correctivo, Predictivo) en el idioma de la aplicación. */
export const maintenanceTypeLabel = (t: TFunction, name?: string | null): string =>
  name ? t(`catalog.maintenance_types.${catalogSlug(name)}`, { defaultValue: name }) : '—';

/**
 * Nombre visible de un registro de catálogo estándar: los de sistema se
 * traducen por su código (`catalog_items.<catálogo>.<CÓDIGO>`); los que crea
 * el usuario se muestran tal cual.
 */
export const catalogItemLabel = (
  t: TFunction, clave: string, item?: { nombre: string; codigo: string | null; sistema: boolean } | null,
): string => {
  if (!item) return '—';
  if (item.sistema && item.codigo) return t(`catalog_items.${clave}.${item.codigo}`, { defaultValue: item.nombre });
  return item.nombre;
};

/** Igual que catalogItemLabel, cuando solo se conoce el código guardado (p. ej. en el kardex). */
export const catalogCodeLabel = (t: TFunction, clave: string, codigo?: string | null, fallback?: string | null): string =>
  codigo ? t(`catalog_items.${clave}.${codigo}`, { defaultValue: fallback || codigo }) : '—';

const CATALOGOS_EN_RESUMEN = ['motivos-baja', 'destinos-baja', 'motivos-movimiento'] as const;

/**
 * El resumen de una aprobación se guarda en el servidor con los códigos del
 * catálogo («Baja de L001 (SN-1): OBSOLESCENCIA → RECICLAJE»). Aquí se muestra
 * en el idioma de la aplicación y con las etiquetas del catálogo; los textos
 * que no siguen el formato conocido se muestran tal cual.
 */
export const approvalSummaryLabel = (t: TFunction, resumen?: string | null): string => {
  if (!resumen) return '—';
  const codigo = (c: string) => {
    for (const catalogo of CATALOGOS_EN_RESUMEN) {
      const clave = `catalog_items.${catalogo}.${c}`;
      const valor = t(clave, { defaultValue: '' });
      if (valor) return valor;
    }
    return c.charAt(0) + c.slice(1).toLowerCase().replace(/_/g, ' ');
  };
  const baja = /^Baja de (.+?): ([A-Z][A-Z_]+) → ([A-Z][A-Z_]+)$/.exec(resumen);
  if (baja) {
    return t('approvals.summary_decommission', { asset: baja[1], reason: codigo(baja[2]), destination: codigo(baja[3]) });
  }
  return resumen.replace(/\b[A-Z][A-Z_]{3,}\b/g, (c) => codigo(c));
};
