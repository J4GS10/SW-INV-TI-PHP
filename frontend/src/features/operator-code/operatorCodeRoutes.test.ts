import { describe, expect, it } from 'vitest';
import { operatorActionFor } from './operatorCodeRoutes';

describe('operatorActionFor', () => {
  it('reconoce las operaciones que pide código', () => {
    expect(operatorActionFor('post', '/trazabilidad/movimientos')).toBe('assign');
    expect(operatorActionFor('POST', '/trazabilidad/movimientos/revertir')).toBe('undo');
    expect(operatorActionFor('post', '/trazabilidad/devolucion')).toBe('return');
    expect(operatorActionFor('post', '/trazabilidad/transferencia')).toBe('transfer');
    expect(operatorActionFor('post', '/trazabilidad/entrega-lote')).toBe('handover_batch');
    expect(operatorActionFor('post', '/trazabilidad/devolucion-lote?lang=es')).toBe('return_batch');
    expect(operatorActionFor('post', '/mantenimiento/')).toBe('maintenance');
    expect(operatorActionFor('post', 'https://lombardi.inventario.group/api/v1/trazabilidad/devolucion')).toBe('return');
  });

  it('no pide código para lecturas ni para otras escrituras', () => {
    expect(operatorActionFor('get', '/trazabilidad/movimientos')).toBeNull();
    expect(operatorActionFor('post', '/trazabilidad/acta/lote')).toBeNull();
    expect(operatorActionFor('post', '/mantenimiento/tipos')).toBeNull();
    expect(operatorActionFor('post', '/mantenimiento/12/detalles')).toBeNull();
    expect(operatorActionFor('post', '/core/activos')).toBeNull();
    expect(operatorActionFor(undefined, undefined)).toBeNull();
  });
});
