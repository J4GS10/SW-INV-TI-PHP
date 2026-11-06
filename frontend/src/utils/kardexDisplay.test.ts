import assert from 'node:assert/strict';
import { test } from 'vitest';
import { kardexReasonText } from './kardexDisplay.ts';

const names: Record<string, string> = { MOVIMIENTO: 'Movimiento', TICKET: 'Ticket', INGRESO: 'Ingreso', TOMA_INVENTARIO: 'Toma de inventario', OFFBOARDING: 'Salida de personal' };
const label = (type: string) => names[type] ?? type;
const reason = (motivo: string | null, tipo: string | null, ref: string | null) => kardexReasonText({ motivo, documentType: tipo, documentRef: ref }, label);

test('a movement id is internal and adds nothing', () => {
  assert.equal(reason(null, 'MOVIMIENTO', 'a77bbc47-1a3a-4f5e-9b1c-0123456789ab'), '');
  assert.equal(reason('Reposición', 'MOVIMIENTO', 'a77bbc471a3a4f5e9b1c0123456789ab'), 'Reposición');
});

test('other documents keep their name without the internal id', () => {
  assert.equal(reason('Falla de pantalla', 'TICKET', '03936faf-1a3a-4f5e-9b1c-0123456789ab'), 'Falla de pantalla — Ticket');
  assert.equal(reason('Salida', 'OFFBOARDING', '03936faf1a3a4f5e9b1c0123456789ab'), 'Salida — Salida de personal');
});

test('a goods receipt is not repeated', () => {
  assert.equal(reason('Ingreso ING-00001', 'INGRESO', 'ING-00001 · FAC-9'), 'Ingreso ING-00001 · FAC-9');
  assert.equal(reason('Ingreso ING-00001', 'INGRESO', 'ING-00001'), 'Ingreso ING-00001');
});

test('readable references stay', () => {
  assert.equal(reason(null, 'TOMA_INVENTARIO', '15'), 'Toma de inventario 15');
  assert.equal(reason('Compra', 'RECEPCION', 'OC 7 · F-1'), 'Compra — RECEPCION OC 7 · F-1');
  assert.equal(reason(null, null, null), '');
});
