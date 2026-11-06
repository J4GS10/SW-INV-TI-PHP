import type { PendingUnit, UnitSpecification } from './goodsReceiptService';

export interface PendingGroup {
  key: string;
  units: PendingUnit[];
}

/**
 * Units of the same product in the same receipt, with the same requested data and factory values:
 * the common data is captured once. A different pre-filled value means they are not the same thing.
 */
export const groupPendingUnits = (units: PendingUnit[]): PendingGroup[] => {
  const groups = new Map<string, PendingUnit[]>();
  for (const unit of units) {
    const specs = unit.specifications.map((s) => `${s.type_id}=${s.value ?? ''}`).join('|');
    const key = [unit.receipt_id, unit.product, unit.item_text, unit.site, specs].join('~');
    groups.set(key, [...(groups.get(key) ?? []), unit]);
  }
  return [...groups].map(([key, members]) => ({ key, units: members }));
};

export const specProblem = (spec: UnitSpecification, value: string): 'spec_number' | 'spec_range' | null => {
  if (!value.trim() || spec.data_type !== 'NUMBER') return null;
  if (!/^\d+(\.\d+)?$/.test(value.trim())) return 'spec_number';
  const n = Number(value);
  return (spec.min !== null && n < spec.min) || (spec.max !== null && n > spec.max) ? 'spec_range' : null;
};

/** Names of what is still required, to say why a unit cannot be saved yet. */
export const missingFields = (unit: PendingUnit, serial: string, specs: Record<number, string>, serialLabel: string): string[] => [
  ...(serial.trim() ? [] : [serialLabel]),
  ...unit.specifications.filter((s) => s.required && !(specs[s.type_id] ?? '').trim()).map((s) => s.name),
];

/** What Purchasing wrote is only worth showing when it differs from the catalog product. */
export const purchasingTextDiffers = (unit: Pick<PendingUnit, 'product' | 'item_text'>): boolean =>
  !!unit.item_text.trim() && unit.item_text.trim().toLowerCase() !== unit.product.trim().toLowerCase();
