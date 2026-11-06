import assert from 'node:assert/strict';
import { test } from 'vitest';
import { assetSubtitle, serialLabel } from './assetDisplay.ts';

test('a missing serial never shows null', () => {
  assert.equal(serialLabel(null, 'Sin serie'), 'Sin serie');
  assert.equal(serialLabel('  ', 'Sin serie'), 'Sin serie');
  assert.equal(serialLabel('SN1', 'Sin serie'), 'SN1');
});

test('the subtitle only shows what exists', () => {
  assert.equal(assetSubtitle(null, null, 'Sin serie'), 'Sin serie');
  assert.equal(assetSubtitle('PC-01', null, 'Sin serie'), 'PC-01 / Sin serie');
  assert.equal(assetSubtitle(null, 'SN1', 'Sin serie'), 'SN1');
  assert.equal(assetSubtitle('PC-01', 'SN1', 'Sin serie'), 'PC-01 / SN1');
});
