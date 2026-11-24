import { describe, expect, it } from 'vitest';

import { moveRowVersionToHeader, withRowVersion } from './rowVersion';

describe('versión de fila en las ediciones', () => {
  it('la edición lleva la versión del registro que se abrió', () => {
    expect(withRowVersion({ PRV_Contacto: 'Ana' }, { PRV_Proveedor: 3, row_version: 7 })).toEqual({ PRV_Contacto: 'Ana', row_version: 7 });
    // Un registro nuevo, o sin versión, se envía tal cual.
    expect(withRowVersion({ PRV_Contacto: 'Ana' }, null)).toEqual({ PRV_Contacto: 'Ana' });
    expect(withRowVersion({ PRV_Contacto: 'Ana' }, { PRV_Proveedor: 3 })).toEqual({ PRV_Contacto: 'Ana' });
  });

  it('la versión viaja en If-Match y no en el cuerpo', () => {
    expect(moveRowVersionToHeader('patch', { a: 1, row_version: 7 })).toEqual({ data: { a: 1 }, ifMatch: '"7"' });
    expect(moveRowVersionToHeader('PUT', { row_version: 2 })).toEqual({ data: {}, ifMatch: '"2"' });
  });

  it('las demás peticiones no cambian', () => {
    const body = { a: 1, row_version: 7 };
    expect(moveRowVersionToHeader('post', body)).toEqual({ data: body });
    expect(moveRowVersionToHeader('patch', { a: 1 })).toEqual({ data: { a: 1 } });
    expect(moveRowVersionToHeader('patch', [1, 2])).toEqual({ data: [1, 2] });
    const form = new FormData();
    expect(moveRowVersionToHeader('put', form).data).toBe(form);
  });
});
