import assert from 'node:assert/strict';
import { test } from 'vitest';
import { statusColor } from './statusColors.ts';

test('closed does not look like active', () => {
  assert.notEqual(statusColor('cerrado'), statusColor('activo'));
});

test('an unknown status is neutral', () => {
  assert.equal(statusColor('closed'), 'default');
});
