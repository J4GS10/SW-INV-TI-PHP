import { expect, test } from 'vitest';
import { compareValues, nextSort, sortRows } from './tableSort';

test('natural order and empty values last in both directions', () => {
  const rows = ['A10', null, 'A2'];
  expect(sortRows(rows, (r) => r, 'asc')).toEqual(['A2', 'A10', null]);
  expect(sortRows(rows, (r) => r, 'desc')).toEqual(['A10', 'A2', null]);
});

test('numbers compare as numbers', () => {
  expect(compareValues(9, 10)).toBeLessThan(0);
});

test('does not change the original list', () => {
  const rows = [2, 1];
  sortRows(rows, (r) => r, 'asc');
  expect(rows).toEqual([2, 1]);
});

test('a header cycles ascending, descending, none', () => {
  const first = nextSort(null, 'code');
  expect(first).toEqual({ key: 'code', direction: 'asc' });
  const second = nextSort(first, 'code');
  expect(second).toEqual({ key: 'code', direction: 'desc' });
  expect(nextSort(second, 'code')).toBeNull();
  expect(nextSort(second, 'host')).toEqual({ key: 'host', direction: 'asc' });
});
