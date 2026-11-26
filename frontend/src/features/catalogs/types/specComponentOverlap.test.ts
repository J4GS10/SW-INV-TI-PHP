import { describe, expect, it } from 'vitest';
import { specsDuplicatedByComponents } from './specComponentOverlap';

describe('specsDuplicatedByComponents', () => {
  const nombres = new Map([[1, 'Procesador'], [2, 'Memoria RAM'], [3, 'Batería']]);

  it('marca la característica que también es pieza interna, sin distinguir tildes ni mayúsculas', () => {
    expect(specsDuplicatedByComponents([1, 2, 3], nombres, ['memoria  ram', 'Bateria'])).toEqual([2, 3]);
  });

  it('no marca nada si no hay piezas con el mismo nombre', () => {
    expect(specsDuplicatedByComponents([1, 2], nombres, ['Disco'])).toEqual([]);
    expect(specsDuplicatedByComponents([9], nombres, ['Procesador'])).toEqual([]);
  });
});
