/*
 * QR de punta a punta, en cada navegador y en móvil: etiquetas PDF generadas por la API ->
 * imagen -> lector del frontend por cámara en vivo (getUserMedia sustituido por
 * canvas.captureStream() que dibuja la etiqueta real, girando y moviéndose), por «Leer desde
 * foto» y por teclado (lector USB/Bluetooth: código + Enter); modo lote con impresión de
 * etiquetas (el PDF descargado se vuelve a decodificar); toma física desde la interfaz con
 * cámara y teclado, verificada en Oracle; y el escáner en inglés e italiano.
 */
import { test, expect, type Page } from '@playwright/test';
import { consulta, iniciarSesionUi, sesionApi, SEDES, type Sesion } from '../soporte';
import { altaApi, area, persona, tipoMovimiento, tipoYModelo } from '../datos';

import { leerQr, pdfAPng } from '../qr';

let jefe: Sesion;
let cat: { tipo: number; modelo: number; prefijo: string };

test.beforeAll(async () => {
  jefe = await sesionApi('qa_jefe_ti');
  cat = await tipoYModelo();
});

/** Imagen (PNG en base64) de la etiqueta de un activo, tal como sale del PDF a 200 ppp. */
async function etiqueta(activoId: string): Promise<string> {
  const r = await jefe.ctx.post('/api/v1/core/activos/etiquetas.pdf', { data: { activos_ids: [activoId] } });
  expect(r.status()).toBe(200);
  const [pagina] = await pdfAPng(await r.body(), 200);
  return pagina.toString('base64');
}

/**
 * Cámara simulada: getUserMedia devuelve el video de un canvas que dibuja la primera
 * etiqueta de la hoja (recorte 30,90 560×220 px a 200 ppp), girándola y desplazándola.
 * enumerateDevices anuncia dos cámaras (trasera y frontal) para el botón de cambio.
 */
async function camaraFalsa(page: Page, pngBase64: string): Promise<void> {
  await page.addInitScript((png: string) => {
    const w = window as unknown as { __qaPng: string; __qaPedidas: number };
    w.__qaPng = png;
    w.__qaPedidas = 0;
    const md = navigator.mediaDevices;
    md.getUserMedia = async () => {
      w.__qaPedidas++;
      const img = new Image();
      img.src = `data:image/png;base64,${w.__qaPng}`;
      await img.decode();
      const c = document.createElement('canvas');
      c.width = 640;
      c.height = 480;
      const g = c.getContext('2d')!;
      let t = 0;
      setInterval(() => {
        g.fillStyle = '#ffffff';
        g.fillRect(0, 0, 640, 480);
        g.save();
        g.translate(320 + Math.sin(t / 7) * 20, 240 + Math.cos(t / 9) * 15);
        g.rotate(Math.sin(t / 10) * 0.12);
        g.drawImage(img, 30, 90, 560, 220, -280, -110, 560, 220);
        g.restore();
        t++;
      }, 66);
      return c.captureStream(15);
    };
    md.enumerateDevices = async () => [
      { deviceId: 'qa-trasera', kind: 'videoinput', label: 'Cámara trasera QA', groupId: 'qa', toJSON() { return this; } },
      { deviceId: 'qa-frontal', kind: 'videoinput', label: 'Cámara frontal QA', groupId: 'qa', toJSON() { return this; } },
    ] as MediaDeviceInfo[];
  }, pngBase64);
}

function sinErrores(page: Page): string[] {
  const errores: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') errores.push(m.text()); });
  page.on('pageerror', (e) => errores.push(e.message));
  // El lector se sirve desde el propio sitio: ninguna petición sale a otro origen (CDN).
  page.on('request', (r) => { if (!r.url().startsWith('https://qa.inventario.local') && !r.url().startsWith('data:') && !r.url().startsWith('blob:')) errores.push(`externa: ${r.url()}`); });
  return errores;
}

test('escáner: cámara en vivo con la etiqueta real -> ficha del activo (una sola lectura aunque el QR siga enfrente)', async ({ page, browserName }) => {
  test.skip(browserName === 'webkit', 'WebKit sin interfaz (Linux) no reproduce en <video> un MediaStream de canvas.captureStream (readyState 0): la cámara en vivo de Safari se verifica en un iPhone real; el mismo decodificador ZXing sí se prueba en WebKit con «Leer desde foto».');
  const a = await altaApi(jefe, cat.modelo, cat.tipo);
  await camaraFalsa(page, await etiqueta(a.ACT_Activo));
  const errores = sinErrores(page);
  await iniciarSesionUi(page, 'qa_tecnico_norte');
  const lecturas: string[] = [];
  page.on('response', (r) => { if (r.url().includes('/core/activos/by-code/')) lecturas.push(decodeURIComponent(r.url().split('/by-code/')[1])); });
  await page.goto('/scanner');
  await page.getByRole('button', { name: 'Activar cámara' }).click();
  await page.waitForTimeout(3000);
  // Diagnóstico: ¿el <video> recibe cuadros del flujo simulado? (readyState >= 2 y tiempo que avanza)
  const video = await page.evaluate(async () => {
    const v = document.querySelector('video');
    if (!v) return null;
    const t0 = v.currentTime;
    await new Promise((r) => setTimeout(r, 1000));
    return { readyState: v.readyState, ancho: v.videoWidth, alto: v.videoHeight, pausado: v.paused, avanza: v.currentTime > t0 };
  });
  test.info().annotations.push({ type: 'video', description: JSON.stringify(video) });
  await expect(page.getByText('Activo encontrado')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByText(a.ACT_Codigo_Interno).first()).toBeVisible();
  await page.waitForTimeout(2000);
  expect(lecturas.filter((c) => c === a.ACT_Codigo_Interno)).toHaveLength(1);
  expect(errores.filter((e) => !/favicon/i.test(e))).toEqual([]);
});

test('escáner: «Leer desde foto» con la imagen de la etiqueta y con una foto sin QR', async ({ page }) => {
  const a = await altaApi(jefe, cat.modelo, cat.tipo);
  const png = Buffer.from(await etiqueta(a.ACT_Activo), 'base64');
  await iniciarSesionUi(page, 'qa_tecnico_norte');
  await page.goto('/scanner');
  const archivo = page.locator('input[type="file"][accept="image/*"]').first();
  await archivo.setInputFiles({ name: 'etiqueta.png', mimeType: 'image/png', buffer: png });
  await expect(page.getByText('Activo encontrado')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByText(a.ACT_Codigo_Interno).first()).toBeVisible();
  // Foto sin QR: aviso, sin errores.
  const blanco = await page.screenshot(); // foto real sin QR
  await page.getByRole('dialog').getByRole('button', { name: 'Cerrar', exact: true }).last().click();
  await archivo.setInputFiles({ name: 'vacia.png', mimeType: 'image/png', buffer: blanco });
  await expect(page.getByText('No se encontró un código QR en la foto', { exact: false })).toBeVisible();
});

test('escáner: lector USB (código + Enter), código ajeno a la sede y modo lote con etiquetas re-decodificadas', async ({ page }) => {
  const a1 = await altaApi(jefe, cat.modelo, cat.tipo);
  const a2 = await altaApi(jefe, cat.modelo, cat.tipo);
  const ajeno = await altaApi(jefe, cat.modelo, cat.tipo, { SED_Sede: SEDES.B });
  await iniciarSesionUi(page, 'qa_tecnico_norte');
  await page.goto('/scanner');
  const campo = page.getByLabel('Código del activo');
  // El lector USB "teclea" muy rápido y termina con Enter; admite asset:CÓDIGO y URL.
  await campo.pressSequentially(`asset:${a1.ACT_Codigo_Interno}`, { delay: 5 });
  await campo.press('Enter');
  await expect(page.getByText('Activo encontrado')).toBeVisible();
  await page.getByRole('dialog').getByRole('button', { name: 'Cerrar', exact: true }).last().click();
  await campo.fill(ajeno.ACT_Codigo_Interno);
  await campo.press('Enter');
  await expect(page.getByText('No se encontró el activo.')).toBeVisible();

  await page.getByText('Modo lote').click();
  for (const a of [a1, a2, a1]) {
    await campo.fill(`https://qa.inventario.local/activos/${a.ACT_Codigo_Interno}`);
    await campo.press('Enter');
  }
  await expect(page.getByText('2 equipos en la lista')).toBeVisible();
  const descarga = page.waitForEvent('download');
  await page.getByRole('button', { name: 'Etiquetas' }).click();
  const pdf = await (await descarga).createReadStream();
  const trozos: Buffer[] = [];
  for await (const t of pdf) trozos.push(t as Buffer);
  const leidos = await leerQr((await pdfAPng(Buffer.concat(trozos), 200))[0]);
  expect(leidos.sort()).toEqual([a1.ACT_Codigo_Interno, a2.ACT_Codigo_Interno].sort());
});

test('toma física desde la interfaz: lecturas por teclado y por cámara; resultados en Oracle y FALTANTE al cerrar', async ({ page, browserName }) => {
  const areaToma = await area(SEDES.A);
  const otraArea = await area(SEDES.A);
  const p = await persona(SEDES.A, 'Toma');
  const asignar = async (id: string, ar: number) => expect((await jefe.ctx.post('/api/v1/trazabilidad/movimientos', {
    data: { ACT_Activo: id, PER_Persona: p, ARE_Area: ar, TMO_Tipo_Movimiento: await tipoMovimiento('Asignación'), MOV_Motivo: 'Toma UI' } })).status()).toBe(201);
  const enArea = await altaApi(jefe, cat.modelo, cat.tipo);
  await asignar(enArea.ACT_Activo, areaToma);
  const porCamara = await altaApi(jefe, cat.modelo, cat.tipo);
  await asignar(porCamara.ACT_Activo, otraArea);
  const faltara = await altaApi(jefe, cat.modelo, cat.tipo);
  // Se anulan tomas abiertas de la sede (corridas anteriores u otros navegadores).
  const previas = await (await jefe.ctx.get('/api/v1/inventario/tomas')).json();
  for (const t of (Array.isArray(previas) ? previas : previas.items ?? []).filter((x: any) => x.TOM_Estado === 'ABIERTA' && x.SED_Sede === SEDES.A)) {
    await jefe.ctx.post(`/api/v1/inventario/tomas/${t.TOM_Toma}/anular`, { data: { motivo: 'QA' } });
  }
  await camaraFalsa(page, await etiqueta(porCamara.ACT_Activo));
  await iniciarSesionUi(page, 'qa_tecnico_norte');
  // WebKit puede informar «Frame load interrupted» si la SPA aún redirige: se reintenta una vez.
  await page.goto('/inventario/tomas').catch(() => page.goto('/inventario/tomas'));
  await page.getByRole('button', { name: 'Nueva toma' }).first().click();
  await page.getByLabel(/Sede/).click();
  await page.getByRole('option', { name: 'QA Sede Norte' }).click();
  const creada = page.waitForResponse((r) => r.url().endsWith('/inventario/tomas') && r.request().method() === 'POST');
  await page.getByRole('button', { name: 'Iniciar toma' }).click();
  const toma = await (await creada).json();
  await expect(page.getByText(`Toma de inventario #${toma.TOM_Toma}`)).toBeVisible();
  // Área donde se escanea: sin ella no se compara la ubicación (no habría OTRA_UBICACION).
  const nombreArea = (await consulta<any>('SELECT "ARE_Nombre" N FROM "INV_AREA" WHERE "ARE_Area" = :a', { a: areaToma }))[0].N;
  await page.getByLabel('Área donde está').click();
  await page.getByRole('option', { name: new RegExp(nombreArea) }).click();
  const campo = page.getByLabel('Código o número de serie');
  for (const codigo of [enArea.ACT_Codigo_Interno, enArea.ACT_Codigo_Interno, 'NO-EXISTE-QA']) {
    const leida = page.waitForResponse((r) => r.url().includes(`/tomas/${toma.TOM_Toma}/lecturas`));
    await campo.fill(codigo);
    await campo.press('Enter');
    expect((await leida).status()).toBe(201);
  }
  const porCam = page.waitForResponse((r) => r.url().includes(`/tomas/${toma.TOM_Toma}/lecturas`));
  if (browserName === 'webkit') {
    await campo.fill(porCamara.ACT_Codigo_Interno); // WebKit sin interfaz: ver la prueba de la cámara
    await campo.press('Enter');
  } else {
    await page.getByRole('button', { name: 'Usar la cámara' }).click();
  }
  expect((await porCam).status()).toBe(201);
  const resultados = await consulta<any>('SELECT "TLE_Resultado" R FROM "INV_TOMA_LECTURA" WHERE "TOM_Toma" = :t ORDER BY "TLE_Lectura"', { t: toma.TOM_Toma })
    .catch(async () => consulta<any>('SELECT "TLE_Resultado" R FROM "INV_TOMA_LECTURA" WHERE "TOM_Toma" = :t', { t: toma.TOM_Toma }));
  expect(resultados.map((f) => f.R).sort()).toEqual(['ENCONTRADO', 'NO_REGISTRADO', 'OTRA_UBICACION', 'REPETIDO'].sort());
  await page.getByRole('button', { name: 'Cerrar toma' }).first().click();
  const cierre = page.waitForResponse((r) => r.url().includes(`/tomas/${toma.TOM_Toma}/cerrar`));
  await page.getByRole('dialog').last().getByRole('button', { name: 'Cerrar toma' }).click();
  const resumen = await (await cierre).json();
  expect(resumen.resumen.faltantes.map((f: any) => f.codigo)).toContain(faltara.ACT_Codigo_Interno);
});

for (const [idioma, textos] of [['en', { camara: 'Start camera', codigo: 'Asset code', encontrado: 'Asset found', foto: 'Read from photo' }],
  ['it', { camara: 'Avvia fotocamera', codigo: 'Codice del bene', encontrado: 'Bene trovato', foto: 'Leggi da foto' }]] as const) {
  test(`escáner en ${idioma}: textos traducidos y lectura por teclado`, async ({ page }) => {
    const a = await altaApi(jefe, cat.modelo, cat.tipo);
    // Se entra en español y luego se cambia el idioma guardado (el que usa el selector de idioma).
    await iniciarSesionUi(page, 'qa_tecnico_norte');
    await page.evaluate((l) => window.localStorage.setItem('app_language', l), idioma);
    await page.goto('/scanner');
    await expect(page.getByRole('button', { name: textos.camara })).toBeVisible();
    await expect(page.getByText(textos.foto)).toBeVisible();
    await page.getByLabel(textos.codigo).fill(a.ACT_Codigo_Interno);
    await page.getByLabel(textos.codigo).press('Enter');
    await expect(page.getByText(textos.encontrado)).toBeVisible();
  });
}
