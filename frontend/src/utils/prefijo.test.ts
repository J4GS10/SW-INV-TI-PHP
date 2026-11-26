import { describe, expect, it } from 'vitest';
import { prefijoDesde, prefijoLibre } from './prefijo';

describe('prefijo', () => {
  it('sugiere iniciales o las tres primeras letras', () => {
    expect(prefijoDesde('Memoria RAM')).toBe('MR');
    expect(prefijoDesde('Laptop')).toBe('LAP');
  });
  it('si el prefijo ya está ocupado, agrega el primer número libre', () => {
    expect(prefijoLibre('BQA', ['LAP'])).toBe('BQA');
    expect(prefijoLibre('BQA', ['bqa', 'BQA2'])).toBe('BQA3');
  });
});
