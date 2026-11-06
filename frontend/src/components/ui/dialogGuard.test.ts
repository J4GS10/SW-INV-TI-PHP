import assert from 'node:assert/strict';
import { test } from 'vitest';
import { shouldConfirmClose } from './dialogGuard';

test('asks only when something was typed and the close was accidental', () => {
  assert.equal(shouldConfirmClose('escapeKeyDown', true), true);
  assert.equal(shouldConfirmClose('backdropClick', true), true);
  assert.equal(shouldConfirmClose('escapeKeyDown', false), false);
  assert.equal(shouldConfirmClose(undefined, true), false);
});
