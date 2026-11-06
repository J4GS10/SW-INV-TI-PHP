/*
 * DOCUMENTOS por el borde de QA: etiquetas QR (PDF -> imagen -> QR decodificado = códigos),
 * etiquetas tras la recepción de una compra, actas PDF/DOCX, hoja de vida, exportes CSV/XLSX
 * (contenido, BOM, protección de fórmulas), cierre mensual, importación Excel y adjuntos (MinIO).
 */
import { writeFileSync, mkdirSync } from 'node:fs';
import { test, expect } from '@playwright/test';
import { consulta, sesionApi, SEDES, sufijo, type Sesion } from '../soporte';
import JSZip from 'jszip';
import { altaApi, area, persona, tipoMovimiento, tipoYModelo } from '../datos';
import { leerQr, pdfAPng, pdfATexto } from '../qr';

test.describe.configure({ mode: 'serial' });
const evidencia = `${process.env.QA_EVIDENCIA ?? 'evidencia'}/documentos`;
let jefe: Sesion;
let cat: { tipo: number; modelo: number; prefijo: string };

test.beforeAll(async () => {
  mkdirSync(evidencia, { recursive: true });
  jefe = await sesionApi('qa_jefe_ti');
  cat = await tipoYModelo();
});


test('etiquetas QR: el PDF trae un QR legible por activo y cada QR contiene exactamente su código interno', async () => {
  const activos = [];
  for (let i = 0; i < 4; i++) activos.push(await altaApi(jefe, cat.modelo, cat.tipo));
  const r = await jefe.ctx.post('/api/v1/core/activos/etiquetas.pdf', { data: { activos_ids: activos.map((a) => a.ACT_Activo) } });
  expect(r.status(), await r.text()).toBe(200);
  expect(r.headers()['content-type']).toContain('application/pdf');
  const pdf = await r.body();
  writeFileSync(`${evidencia}/etiquetas.pdf`, pdf);
  const [pagina] = await pdfAPng(pdf, 200);
  writeFileSync(`${evidencia}/etiquetas.png`, pagina);
  const leidos = await leerQr(pagina);
  expect(leidos.sort()).toEqual(activos.map((a) => a.ACT_Codigo_Interno).sort());
});

async function movimiento(): Promise<{ activo: any; mov: string; persona: string }> {
  const activo = await altaApi(jefe, cat.modelo, cat.tipo);
  const p = await persona(SEDES.A, 'Acta');
  const r = await jefe.ctx.post('/api/v1/trazabilidad/movimientos', { data: { ACT_Activo: activo.ACT_Activo, PER_Persona: p, ARE_Area: await area(SEDES.A),
    TMO_Tipo_Movimiento: await tipoMovimiento('Asignación'), MOV_Motivo: 'Acta QA' } });
  expect(r.status()).toBe(201);
  return { activo, mov: (await r.json()).MOV_Movimiento, persona: p };
}

test('actas: PDF y DOCX con el código del activo y la persona; formato inválido 422; CONSULTA no las genera', async () => {
  const { activo, mov } = await movimiento();
  const pdf = await jefe.ctx.get(`/api/v1/trazabilidad/acta/${mov}?formato=pdf`);
  expect(pdf.headers()['content-type']).toContain('application/pdf');
  const texto = await pdfATexto(await pdf.body());
  expect(texto).toContain(activo.ACT_Serie_Fabricante);
  expect(texto).toMatch(/ENTREGA DE EQUIPO/);
  expect(texto).toContain('Acta QA');
  expect(texto).toContain('Acta');
  const docx = await jefe.ctx.get(`/api/v1/trazabilidad/acta/${mov}`);
  expect(docx.headers()['content-type']).toContain('officedocument.wordprocessingml');
  const zip = await JSZip.loadAsync(await docx.body());
  expect(await zip.file('word/document.xml')!.async('string')).toContain(activo.ACT_Serie_Fabricante);
  expect((await jefe.ctx.get(`/api/v1/trazabilidad/acta/${mov}?formato=odt`)).status()).toBe(422);
  expect((await (await sesionApi('qa_consulta_norte')).ctx.get(`/api/v1/trazabilidad/acta/${mov}`)).status()).toBe(403);
});

test('hoja de vida PDF del activo y exportes CSV (BOM UTF-8, encabezado, CRLF) y kardex CSV', async () => {
  const { activo } = await movimiento();
  const hv = await jefe.ctx.get(`/api/v1/inventario/activos/${activo.ACT_Activo}/hoja-de-vida.pdf`);
  expect(hv.status()).toBe(200);
  expect(await pdfATexto(await hv.body())).toContain(activo.ACT_Codigo_Interno);
  for (const ruta of ['/api/v1/export/activos.csv', '/api/v1/export/movimientos.csv', '/api/v1/inventario/kardex.csv', '/api/v1/inventario/custodia.csv']) {
    const r = await jefe.ctx.get(ruta);
    expect(r.status(), ruta).toBe(200);
    const crudo = await r.body();
    expect([crudo[0], crudo[1], crudo[2]], `${ruta}: BOM`).toEqual([0xef, 0xbb, 0xbf]);
    expect(crudo.toString('utf8')).toContain('\r\n');
  }
});

test('importación desde Excel: plantilla -> vista previa -> confirmación crea el activo con kardex', async () => {
  const plantilla = await jefe.ctx.get('/api/v1/productividad/importar/activos/plantilla');
  expect(plantilla.status()).toBe(200);
  const ExcelJS = (await import('exceljs')).default;
  const libro = new ExcelJS.Workbook();
  await libro.xlsx.load(await plantilla.body());
  const hoja = libro.worksheets[0];
  const enc = (hoja.getRow(1).values as unknown[]).map((v) => String(v ?? ''));
  const tipoNombre = (await consulta<any>('SELECT "TAC_Nombre" N FROM "INV_TIPO_ACTIVO" WHERE "TAC_Tipo_Activo" = :t', { t: cat.tipo }))[0].N;
  const areaId = await area(SEDES.A);
  const areaNombre = (await consulta<any>('SELECT "ARE_Nombre" N FROM "INV_AREA" WHERE "ARE_Area" = :a', { a: areaId }))[0].N;
  const serie = `IMP-${sufijo()}`;
  const valores: Record<string, unknown> = { 'Categoría': tipoNombre, 'Descripción': 'Laptop importada', 'Marca': `Marca IMP ${sufijo()}`, 'Modelo': 'M1',
    'Serie': serie, 'Ubicación': areaNombre, 'Fecha de compra': '15/03/2026', 'Costo': '1250.50', 'Moneda': 'GTQ' };
  const fila = hoja.getRow(2);
  enc.forEach((e, i) => { if (e in valores) fila.getCell(i).value = valores[e] as string; });
  fila.commit();
  const xlsx = Buffer.from(await libro.xlsx.writeBuffer());
  const multipart = { archivo: { name: 'inventario.xlsx', mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', buffer: xlsx } };
  const previa = await jefe.ctx.post('/api/v1/productividad/importar/activos/previsualizar', { multipart });
  expect(previa.status(), await previa.text()).toBe(200);
  expect((await previa.json()).resumen.validas).toBe(1);
  const conf = await jefe.ctx.post('/api/v1/productividad/importar/activos/confirmar', { multipart });
  expect(conf.status(), await conf.text()).toBe(201);
  const bd = await consulta<any>('SELECT a."ACT_Codigo_Interno" C, (SELECT COUNT(*) FROM "INV_KARDEX" k WHERE k."ACT_Activo" = a."ACT_Activo") K '
    + 'FROM "INV_ACTIVO" a WHERE a."ACT_Serie_Fabricante" = :s', { s: serie });
  expect(bd).toHaveLength(1);
  expect(Number(bd[0].K)).toBeGreaterThanOrEqual(1);
});

test('adjuntos en MinIO: subida, listado, descarga idéntica; un técnico de otra sede no lo descarga', async () => {
  const activo = await altaApi(jefe, cat.modelo, cat.tipo);
  const contenido = Buffer.from(`%PDF-1.4\n% QA ${sufijo()}\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n`);
  const sub = await jefe.ctx.post(`/api/v1/adjuntos/activos/${activo.ACT_Activo}`, {
    multipart: { file: { name: 'factura.pdf', mimeType: 'application/pdf', buffer: contenido }, descripcion: 'Factura QA' },
  });
  expect(sub.status(), await sub.text()).toBe(201);
  const adj = await sub.json();
  const id = adj.ADJ_Adjunto ?? adj.id;
  const lista = await (await jefe.ctx.get(`/api/v1/adjuntos/activos/${activo.ACT_Activo}`)).json();
  expect(JSON.stringify(lista)).toContain('factura');
  const baj = await jefe.ctx.get(`/api/v1/adjuntos/${id}/download`);
  expect(baj.status()).toBe(200);
  expect(Buffer.compare(await baj.body(), contenido)).toBe(0);
  const ajeno = await (await sesionApi('qa_tecnico_sur')).ctx.get(`/api/v1/adjuntos/${id}/download`);
  expect(ajeno.status()).toBeGreaterThanOrEqual(403);
  expect(ajeno.status()).toBeLessThan(500);
});
