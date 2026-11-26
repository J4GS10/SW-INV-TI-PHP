/*
 * Visor de archivos: los adjuntos de un activo se ven dentro del sistema, sin descargarlos.
 * PDF en el visor del navegador (o, si el navegador no lo muestra en la página, la opción de abrirlo aparte),
 * imágenes con zoom, CSV como tabla. Descargar sigue disponible, a mano.
 */
import { test, expect } from '@playwright/test';
import { iniciarSesionUi, irA, sesionApi, sufijo } from '../soporte';
import { altaApi, tipoYModelo } from '../datos';

const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');

/** Un PDF mínimo y válido, de una página con un texto. */
const pdf = (texto: string): Buffer => {
  const flujo = `BT /F1 24 Tf 40 70 Td (${texto}) Tj ET`;
  const objetos = [
    '<</Type/Catalog/Pages 2 0 R>>',
    '<</Type/Pages/Kids[3 0 R]/Count 1>>',
    '<</Type/Page/Parent 2 0 R/MediaBox[0 0 400 144]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>',
    `<</Length ${flujo.length}>>stream\n${flujo}\nendstream`,
    '<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>',
  ];
  let cuerpo = '%PDF-1.4\n';
  const posiciones: number[] = [];
  objetos.forEach((o, i) => { posiciones.push(cuerpo.length); cuerpo += `${i + 1} 0 obj\n${o}\nendobj\n`; });
  const xref = cuerpo.length;
  cuerpo += `xref\n0 ${objetos.length + 1}\n0000000000 65535 f \n${posiciones.map((p) => `${String(p).padStart(10, '0')} 00000 n \n`).join('')}`;
  cuerpo += `trailer\n<</Size ${objetos.length + 1}/Root 1 0 R>>\nstartxref\n${xref}\n%%EOF\n`;
  return Buffer.from(cuerpo, 'latin1');
};

test('los adjuntos se ven en el visor sin descargarlos', async ({ page }, info) => {
  test.setTimeout(180_000);
  const jefe = await sesionApi('qa_visor_ti');
  const laptop = await tipoYModelo('COMPUTO');
  const equipo = await altaApi(jefe, laptop.modelo, laptop.tipo);
  const s = sufijo();
  const nombres = { pdf: `factura-${s}.pdf`, png: `foto-${s}.png`, csv: `lista-${s}.csv` };
  const subidos: string[] = [];
  for (const [nombre, mimeType, buffer] of [
    [nombres.pdf, 'application/pdf', pdf('Factura E2E')],
    [nombres.png, 'image/png', PNG],
    [nombres.csv, 'text/csv', Buffer.from('Serie;Modelo\nSN-1;"Latitude; 5440"\nSN-2;ThinkPad\n', 'utf8')],
  ] as const) {
    const r = await jefe.ctx.post(`/api/v1/adjuntos/activos/${equipo.ACT_Activo}`, {
      multipart: { file: { name: nombre, mimeType, buffer }, categoria: 'otro' },
    });
    expect([200, 201], `subir ${nombre}: ${await r.text()}`).toContain(r.status());
    subidos.push(((await r.json()) as { ADJ_Adjunto: string }).ADJ_Adjunto);
  }

  // Ninguna regla de seguridad del sitio debe bloquear el visor.
  const bloqueos: string[] = [];
  page.on('console', (m) => { if (/Content.Security.Policy|Refused to (frame|load)/i.test(m.text())) bloqueos.push(m.text()); });
  const descargas: string[] = [];
  page.on('download', (d) => descargas.push(d.suggestedFilename()));

  try {
    await iniciarSesionUi(page, 'qa_visor_ti');
    await irA(page, `/activos/${encodeURIComponent(equipo.ACT_Codigo_Interno)}`);
    await page.getByRole('tab', { name: 'Adjuntos' }).click();

    // PDF: se ve en la página (o, en un navegador que no los muestra ahí, se ofrece abrirlo aparte); nada se descarga.
    await page.getByRole('button', { name: nombres.pdf, exact: true }).click();
    const visor = page.getByRole('dialog', { name: nombres.pdf });
    await expect(visor).toBeVisible();
    await expect(visor.getByText(/\b[123] de 3\b/)).toBeVisible();
    const marco = visor.locator(`iframe[title="${nombres.pdf}"]`);
    const sinVisorPdf = visor.getByText('Este navegador no muestra PDF aquí');
    await expect(marco.or(sinVisorPdf)).toBeVisible();
    if (await marco.isVisible()) await expect(marco).toHaveAttribute('src', /^blob:.*#view=FitH$/);
    else await expect(visor.getByRole('button', { name: 'Abrir en una pestaña nueva' }).first()).toBeVisible();
    await page.screenshot({ path: info.outputPath('visor-pdf.png') });

    // Las flechas recorren los tres archivos sin cerrar el visor y vuelven al PDF.
    for (let i = 0; i < 3; i++) {
      await page.keyboard.press('ArrowRight');
      await expect(page.getByRole('dialog')).toHaveCount(1);
    }
    await expect(page.getByRole('dialog', { name: nombres.pdf })).toBeVisible();
    await visor.getByRole('button', { name: 'Cerrar' }).click();
    await expect(visor).toHaveCount(0);

    // Imagen, con zoom.
    await page.getByRole('button', { name: nombres.png, exact: true }).click();
    const foto = page.getByRole('dialog', { name: nombres.png });
    const imagen = foto.getByRole('img', { name: nombres.png });
    await expect(imagen).toBeVisible();
    expect(await imagen.evaluate((i: HTMLImageElement) => i.naturalWidth)).toBe(1);
    await foto.getByRole('button', { name: 'Acercar' }).click();
    await expect(foto.getByText('125 %')).toBeVisible();
    await foto.getByRole('button', { name: 'Cerrar' }).click();
    await expect(foto).toHaveCount(0);

    // CSV: como tabla, respetando las comillas.
    await page.getByRole('button', { name: nombres.csv, exact: true }).click();
    const tabla = page.getByRole('dialog', { name: nombres.csv });
    await expect(tabla.getByRole('columnheader', { name: 'Modelo' })).toBeVisible();
    await expect(tabla.getByRole('cell', { name: 'Latitude; 5440' })).toBeVisible();
    await page.screenshot({ path: info.outputPath('visor-csv.png') });

    // Nada se descargó solo; Descargar sigue a mano, desde el visor.
    expect(descargas).toEqual([]);
    const descarga = page.waitForEvent('download');
    await tabla.getByRole('button', { name: 'Descargar' }).click();
    expect((await descarga).suggestedFilename()).toBe(nombres.csv);
    expect(bloqueos).toEqual([]);
  } finally {
    for (const id of subidos) await jefe.ctx.delete(`/api/v1/adjuntos/${id}`);
  }
});
