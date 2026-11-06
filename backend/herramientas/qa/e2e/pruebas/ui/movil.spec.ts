/*
 * Vista móvil y accesibilidad (WCAG 2.2 AA con axe-core) de las pantallas de trabajo:
 * login, panel, activos, escáner, toma de inventario y entrega de puesto. axe cubre solo una
 * parte de WCAG: además se comprueba que no haya desplazamiento horizontal, que los
 * controles midan al menos 24×24 px (2.5.8) y que se pueda operar con teclado.
 * Incluye la entrega de puesto de punta a punta con el lector (código + Enter).
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { consulta, iniciarSesionUi, sesionApi, SEDES } from '../soporte';
import { altaApi, area, hex, persona, tipoYModelo } from '../datos';

const evidencia = `${process.env.QA_EVIDENCIA ?? 'evidencia'}/accesibilidad`;

async function auditar(page: Page, nombre: string, proyecto: string): Promise<{ graves: string[]; todas: number }> {
  await page.waitForLoadState('networkidle');
  // Contraste a mitad de una transición (opacidad) da falsos positivos: se espera a que terminen.
  await page.evaluate(() => Promise.all(document.getAnimations().map((a) => a.finished.catch(() => undefined))));
  await expect(page.getByRole('progressbar')).toHaveCount(0, { timeout: 10_000 }).catch(() => undefined);
  const r = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
  mkdirSync(evidencia, { recursive: true });
  writeFileSync(`${evidencia}/${proyecto}-${nombre}.json`, JSON.stringify(r.violations.map((v) => ({ id: v.id, impacto: v.impact, nodos: v.nodes.length,
    ejemplos: v.nodes.slice(0, 4).map((n) => n.html.slice(0, 220)) })), null, 2));
  return { graves: r.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious').map((v) => `${v.id} (${v.nodes.length})`),
    todas: r.violations.length };
}

async function sinDesbordeHorizontal(page: Page): Promise<number> {
  return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}

test('accesibilidad y vista: login, panel, activos, escáner y tomas', async ({ page }, info) => {
  const resultados: Record<string, { graves: string[]; todas: number; desborde: number }> = {};
  await page.goto('/login');
  resultados.login = { ...(await auditar(page, 'login', info.project.name)), desborde: await sinDesbordeHorizontal(page) };
  await iniciarSesionUi(page, 'qa_tecnico_norte');
  for (const [nombre, ruta] of [['tareas', '/inicio'], ['panel', '/panel'], ['activos', '/activos'], ['escaner', '/scanner'], ['tomas', '/inventario/tomas']]) {
    // WebKit informa «interrupted by another navigation» si la SPA aún redirige: se espera y se reintenta.
    for (let intento = 0; intento < 3; intento++) {
      try { await page.goto(ruta); break; } catch { await page.waitForLoadState('networkidle').catch(() => undefined); }
    }
    resultados[nombre] = { ...(await auditar(page, nombre, info.project.name)), desborde: await sinDesbordeHorizontal(page) };
  }
  test.info().annotations.push({ type: 'axe', description: JSON.stringify(resultados) });
  for (const [nombre, r] of Object.entries(resultados)) {
    expect.soft(r.desborde, `${nombre}: sin desplazamiento horizontal`).toBeLessThanOrEqual(1);
    expect.soft(r.graves, `${nombre}: violaciones críticas o serias de WCAG`).toEqual([]);
  }
  // Escáner en móvil: los botones principales miden al menos 24×24 px y se alcanzan con teclado.
  await page.goto('/scanner');
  for (const nombre of ['Activar cámara', 'Leer desde foto']) {
    const caja = await page.getByRole('button', { name: nombre }).first().boundingBox();
    expect(caja && caja.width >= 24 && caja.height >= 24, `${nombre} >= 24 px`).toBe(true);
  }
  const campo = page.getByLabel('Código del activo');
  await campo.focus();
  await expect(campo).toBeFocused();
});

test('entrega de puesto: persona y área, equipos agregados con el lector (código + Enter) y movimientos en Oracle', async ({ page }, info) => {
  test.skip(info.project.name !== 'chromium' && !info.project.name.startsWith('movil'), 'Flujo de escritura: escritorio Chromium y móviles');
  const jefe = await sesionApi('qa_jefe_ti');
  const cat = await tipoYModelo();
  const [a1, a2] = [await altaApi(jefe, cat.modelo, cat.tipo), await altaApi(jefe, cat.modelo, cat.tipo)];
  const p = await persona(SEDES.A, 'Entrega');
  const areaId = await area(SEDES.A);
  const nombreArea = (await consulta<any>('SELECT "ARE_Nombre" N FROM "INV_AREA" WHERE "ARE_Area" = :a', { a: areaId }))[0].N;
  const apellido = (await consulta<any>('SELECT "PER_Primer_Apellido" A FROM "INV_PERSONA" WHERE "PER_Persona" = :p', { p: hex(p) }))[0].A;
  await iniciarSesionUi(page, 'qa_jefe_ti');
  await page.goto('/operaciones/movimientos');
  await page.getByRole('button', { name: 'Entrega de puesto' }).first().click();
  await page.getByLabel('Persona que recibe').fill(apellido);
  await page.getByRole('option', { name: new RegExp(apellido) }).first().click();
  await page.getByLabel('Área de destino').fill(nombreArea);
  await page.getByRole('option', { name: new RegExp(nombreArea) }).first().click();
  const equipo = page.getByLabel('Agregar equipo');
  for (const a of [a1, a2]) {
    await equipo.fill(a.ACT_Codigo_Interno);
    await equipo.press('Enter');
    await expect(page.getByText(a.ACT_Codigo_Interno).first()).toBeVisible();
  }
  const motivo = page.getByLabel(/Motivo/).first();
  await motivo.click();
  await page.getByRole('option').first().click();
  const lote = page.waitForResponse((r) => r.url().includes('/trazabilidad/entrega-lote'));
  await page.getByRole('button', { name: 'Entregar 2 equipos' }).click();
  expect((await lote).status()).toBe(201);
  const abiertos = await consulta<any>('SELECT COUNT(*) N FROM "INV_MOVIMIENTO" WHERE "PER_Persona" = :p AND "MOV_Fecha_Devolucion" IS NULL', { p: hex(p) });
  expect(Number(abiertos[0].N)).toBe(2);
});
