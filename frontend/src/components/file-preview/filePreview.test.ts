import { describe, expect, it } from 'vitest';
import { formatBytes, parseCsv, previewKind } from './filePreview';

describe('previewKind', () => {
  it('elige el visor por el tipo del servidor', () => {
    expect(previewKind('factura', 'application/pdf')).toBe('pdf');
    expect(previewKind('foto', 'image/jpeg')).toBe('image');
    expect(previewKind('datos', 'text/csv; charset=utf-8')).toBe('csv');
    expect(previewKind('notas.txt', 'text/plain')).toBe('text');
  });

  it('usa la extensión si el tipo falta o es genérico', () => {
    expect(previewKind('Acta.PDF')).toBe('pdf');
    expect(previewKind('foto.webp', 'application/octet-stream')).toBe('image');
    expect(previewKind('lista.csv', 'text/plain')).toBe('csv');
  });

  it('no muestra Word, Excel ni SVG', () => {
    expect(previewKind('acta.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')).toBe('none');
    expect(previewKind('hoja.xlsx')).toBe('none');
    expect(previewKind('logo.svg', 'image/svg+xml')).toBe('none');
    expect(previewKind('falso.pdf', 'image/svg+xml')).toBe('none');
  });
});

describe('parseCsv', () => {
  it('respeta comillas y detecta el separador', () => {
    expect(parseCsv('a;b\n"x;1";"di ""hola"""\n').rows).toEqual([['a', 'b'], ['x;1', 'di "hola"']]);
    expect(parseCsv(String.fromCharCode(0xfeff) + 'a,b\r\n1,2').rows).toEqual([['a', 'b'], ['1', '2']]);
  });

  it('corta en el máximo de filas', () => {
    const r = parseCsv('h\n1\n2\n3\n', 2);
    expect(r).toEqual({ rows: [['h'], ['1']], truncated: true });
  });
});

it('formatBytes', () => {
  expect([formatBytes(512), formatBytes(2048), formatBytes(3 * 1024 * 1024)]).toEqual(['512 B', '2.0 KB', '3.0 MB']);
});
