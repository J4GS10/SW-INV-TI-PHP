import { expect, test } from 'vitest';
import type { PendingUnit, UnitSpecification } from './goodsReceiptService';
import { groupPendingUnits, missingFields, purchasingTextDiffers, specProblem } from './pendingUnits';

const spec = (over: Partial<UnitSpecification> = {}): UnitSpecification => ({
  type_id: 1, name: 'RAM', unit: 'GB', data_type: 'NUMBER', required: true, min: 1, max: 64, options: null, value: null, ...over,
} as UnitSpecification);
const unit = (over: Partial<PendingUnit> = {}): PendingUnit => ({
  asset_id: 'a', code: 'NB1', item_text: 'Laptop', receipt_id: 1, receipt_code: 'ING-1', document_number: 'F1', site: 'S', type: 'Laptop',
  product: 'HP 450', specifications: [spec()], ...over,
});

test('same product and receipt go together', () => {
  const groups = groupPendingUnits([unit(), unit({ asset_id: 'b', code: 'NB2' })]);
  expect(groups).toHaveLength(1);
  expect(groups[0].units).toHaveLength(2);
});

test('another product, receipt or factory value is another group', () => {
  expect(groupPendingUnits([unit(), unit({ asset_id: 'b', product: 'Dell' })])).toHaveLength(2);
  expect(groupPendingUnits([unit(), unit({ asset_id: 'b', receipt_id: 2 })])).toHaveLength(2);
  expect(groupPendingUnits([unit(), unit({ asset_id: 'b', specifications: [spec({ value: '16' })] })])).toHaveLength(2);
});

test('says exactly what is missing', () => {
  expect(missingFields(unit(), '', {}, 'Serie')).toEqual(['Serie', 'RAM']);
  expect(missingFields(unit(), 'SN', { 1: '8' }, 'Serie')).toEqual([]);
  expect(missingFields(unit({ specifications: [spec({ required: false })] }), 'SN', {}, 'Serie')).toEqual([]);
});

test('numbers are validated', () => {
  expect(specProblem(spec(), 'abc')).toBe('spec_number');
  expect(specProblem(spec(), '128')).toBe('spec_range');
  expect(specProblem(spec(), '16')).toBeNull();
  expect(specProblem(spec(), '')).toBeNull();
});

test('purchasing text only shows when it says something else', () => {
  expect(purchasingTextDiffers(unit({ item_text: 'Laptop Dell' }))).toBe(true);
  expect(purchasingTextDiffers(unit({ item_text: 'hp 450' }))).toBe(false);
});
