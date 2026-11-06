import assert from 'node:assert/strict';
import esJson from './es.json';
import enJson from './en.json';
import itJson from './it.json';
import { test } from 'vitest';

type Tree = { [key: string]: string | Tree };
const keys = (tree: Tree, prefix = ''): string[] => Object.entries(tree).flatMap(([k, v]) => (typeof v === 'string' ? [prefix + k] : keys(v, `${prefix}${k}.`)));
const [es, en, it] = [esJson, enJson, itJson] as unknown as Tree[];
const at = (tree: Tree, path: string) => path.split('.').reduce<string | Tree>((node, k) => (node as Tree)[k], tree);

test('the three languages have the same keys', () => {
  const base = keys(es).sort();
  assert.deepEqual(keys(en).sort(), base);
  assert.deepEqual(keys(it).sort(), base);
});

test('priority words do not collide with a decommission («baja»)', () => {
  assert.equal(at(es, 'attention.severity.alta'), 'Urgente');
  assert.equal(at(es, 'attention.severity.media'), 'Normal');
  assert.notEqual(at(es, 'attention.severity.baja'), 'Baja');
});

test('new labels exist in all languages', () => {
  for (const tree of [es, en, it]) {
    for (const path of ['assets.table.no_serial', 'assets.actions.complete', 'kardex.documentos.INGRESO']) {
      assert.equal(typeof at(tree, path), 'string', path);
    }
  }
});
