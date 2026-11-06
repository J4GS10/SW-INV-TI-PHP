import assert from 'node:assert/strict';
import { test } from 'vitest';
import { accionesDisponibles } from './acciones.ts';

const operator = { canOperate: true, canAdminInventory: true };

test('an asset to complete offers completing, not assigning', () => {
  const actions = accionesDisponibles('En Bodega', operator, 'PERSONAL', true);
  assert.ok(actions.includes('completar'));
  for (const blocked of ['asignar', 'instalar', 'editar', 'mantenimiento', 'etiqueta']) {
    assert.ok(!actions.includes(blocked as never), blocked);
  }
});

test('a read-only role is not offered completing', () => {
  assert.ok(!accionesDisponibles('En Bodega', { canOperate: false, canAdminInventory: false }, 'PERSONAL', true).includes('completar'));
});

test('an assigned asset can be reported lost, only by an inventory administrator', () => {
  const assigned = accionesDisponibles('Asignado', operator);
  assert.ok(assigned.includes('reportar_perdida'));
  assert.ok(!assigned.includes('baja'));
  assert.ok(!accionesDisponibles('Asignado', { canOperate: true, canAdminInventory: false }).includes('reportar_perdida'));
  assert.ok(!accionesDisponibles('En Bodega', operator).includes('reportar_perdida'));
});

test('a complete asset keeps its usual actions', () => {
  const actions = accionesDisponibles('En Bodega', operator);
  assert.ok(actions.includes('asignar'));
  assert.ok(!actions.includes('completar'));
});
