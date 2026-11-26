import { describe, expect, it } from 'vitest';
import { isReady, typeReadiness, type ReadinessInput } from './typeReadiness';

const laptop: ReadinessInput = {
  name: 'Laptop', prefix: 'LAP', assetClass: 'COMPUTO', classHasComponents: true, isSupply: false, hasCategory: true,
  specs: [{ required: true }, { required: false }], components: [{ required: true }],
};
const level = (input: ReadinessInput, key: string) => typeReadiness(input).find((i) => i.key === key)?.level;

describe('tipo de activo apto para registrar', () => {
  it('un tipo completo está listo y cuenta lo obligatorio', () => {
    const items = typeReadiness(laptop);
    expect(isReady(items)).toBe(true);
    expect(items.every((i) => i.level === 'ok')).toBe(true);
    expect(items.find((i) => i.key === 'specs')?.values).toEqual({ count: 2, required: 1 });
    expect(items.find((i) => i.key === 'components')?.values).toEqual({ count: 1, required: 1 });
  });

  it('sin nombre o prefijo válido no se puede registrar', () => {
    expect(isReady(typeReadiness({ ...laptop, name: 'L' }))).toBe(false);
    expect(isReady(typeReadiness({ ...laptop, prefix: 'L-1' }))).toBe(false);
  });

  it('un insumo no se registra como activo', () => {
    expect(level({ ...laptop, isSupply: true }, 'class')).toBe('error');
  });

  it('lo que conviene completar es aviso, no bloqueo', () => {
    const items = typeReadiness({ ...laptop, assetClass: '', hasCategory: false, specs: [], components: [] });
    expect(isReady(items)).toBe(true);
    expect(items.filter((i) => i.level === 'warning').map((i) => i.key)).toEqual(['class', 'category', 'specs', 'components']);
  });

  it('una clase sin componentes no pregunta por ellos', () => {
    expect(level({ ...laptop, classHasComponents: false }, 'components')).toBeUndefined();
  });
});
