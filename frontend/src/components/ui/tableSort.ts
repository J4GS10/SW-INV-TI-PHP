export type SortDirection = 'asc' | 'desc';
export type SortValue = string | number | boolean | null | undefined;
export interface SortState { key: string; direction: SortDirection }

const isEmpty = (value: SortValue) => value === null || value === undefined || value === '';

/** Text in natural order («A2» before «A10»); empty values always last, whatever the direction. */
export const compareValues = (a: SortValue, b: SortValue, direction: SortDirection = 'asc'): number => {
  if (isEmpty(a) || isEmpty(b)) return Number(isEmpty(a)) - Number(isEmpty(b));
  const sign = direction === 'asc' ? 1 : -1;
  if (typeof a === 'string' || typeof b === 'string') {
    return sign * String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
  }
  return sign * (Number(a) - Number(b));
};

export const sortRows = <T,>(rows: readonly T[], value: (row: T) => SortValue, direction: SortDirection): T[] =>
  [...rows].sort((a, b) => compareValues(value(a), value(b), direction));

/** A header cycles through ascending, descending and the list's own order. */
export const nextSort = (current: SortState | null, key: string): SortState | null => {
  if (current?.key !== key) return { key, direction: 'asc' };
  return current.direction === 'asc' ? { key, direction: 'desc' } : null;
};
