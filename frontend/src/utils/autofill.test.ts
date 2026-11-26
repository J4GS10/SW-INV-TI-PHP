import { describe, expect, it } from 'vitest';
import { companyDomain, destinationForReason, median, mostCommon, normalizeTaxId, suggestEmail } from './autofill';

describe('autorrelleno', () => {
  it('elige el valor más repetido e ignora los vacíos', () => {
    expect(mostCommon([1, 2, 2, null, 3])).toBe(2);
    expect(mostCommon(['', null, undefined])).toBeNull();
    expect(mostCommon(['a', 'b'])).toBe('a');
  });

  it('calcula la mediana', () => {
    expect(median([5, 1, 3])).toBe(3);
    expect(median([1, 2, 3, 4])).toBe(2);
    expect(median([])).toBeNull();
  });

  it('toma el dominio más usado', () => {
    expect(companyDomain(['a@Empresa.com', 'b@empresa.com', 'c@gmail.com', null, 'roto@'])).toBe('empresa.com');
    expect(companyDomain([])).toBeNull();
  });

  it('arma el correo sin tildes y sin repetir', () => {
    const name = { firstName: 'José Ángel', firstLastName: 'Peña', secondLastName: 'López' };
    expect(suggestEmail(name, 'empresa.com', [])).toBe('jose.pena@empresa.com');
    expect(suggestEmail(name, 'empresa.com', ['JOSE.PENA@empresa.com'])).toBe('jose.penal@empresa.com');
    expect(suggestEmail(name, 'empresa.com', ['jose.pena@empresa.com', 'jose.penal@empresa.com'])).toBe('jose.pena2@empresa.com');
    expect(suggestEmail(name, null, [])).toBeNull();
    expect(suggestEmail({ firstName: 'A', firstLastName: 'Peña' }, 'empresa.com', [])).toBeNull();
  });

  it('normaliza el NIT', () => {
    expect(normalizeTaxId(' 1234567-k ')).toBe('1234567K');
  });

  it('sugiere el destino solo cuando el motivo lo define', () => {
    expect(destinationForReason('ROBO')).toBe('NO_RECUPERADO');
    expect(destinationForReason('GARANTIA_REEMPLAZO')).toBe('DEVOLUCION_PROVEEDOR');
    expect(destinationForReason('OBSOLESCENCIA')).toBeNull();
    expect(destinationForReason(null)).toBeNull();
  });
});
