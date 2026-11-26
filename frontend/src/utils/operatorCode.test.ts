import { describe, expect, it } from 'vitest';
import { operatorCodeProblem } from './operatorCode';

describe('operatorCodeProblem', () => {
  it('acepta 6 dígitos no obvios', () => {
    expect(operatorCodeProblem('482916')).toBeNull();
    expect(operatorCodeProblem('730415')).toBeNull();
  });

  it('pide exactamente 6 dígitos', () => {
    for (const c of ['', '12345', '1234567', '12a456', '482 916']) expect(operatorCodeProblem(c)).toBe('OPERATOR_CODE_FORMAT');
  });

  it('rechaza repetidos, secuencias y obvios, como el servidor', () => {
    for (const c of ['777777', '345678', '987654', '012345', '123123']) expect(operatorCodeProblem(c)).toBe('OPERATOR_CODE_TOO_WEAK');
  });
});
