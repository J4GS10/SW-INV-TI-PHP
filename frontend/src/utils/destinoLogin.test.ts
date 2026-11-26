import { describe, expect, it } from 'vitest';
import { destinoTrasLogin } from './destinoLogin';

describe('destinoTrasLogin', () => {
  it('vuelve a la ficha que abrió el QR', () => {
    expect(destinoTrasLogin({ from: '/activos/LAP00013' })).toBe('/activos/LAP00013');
    expect(destinoTrasLogin({ from: '/activos?tipo=1' })).toBe('/activos?tipo=1');
  });

  it('sin destino o con uno ajeno, al inicio', () => {
    for (const malo of [null, undefined, {}, { from: 5 }, { from: 'https://otro.sitio/x' }, { from: '//otro.sitio' }, { from: '/\\otro' },
      { from: '/login' }, { from: '/sso/callback' }, { from: 'activos' }]) {
      expect(destinoTrasLogin(malo)).toBe('/');
    }
  });
});
