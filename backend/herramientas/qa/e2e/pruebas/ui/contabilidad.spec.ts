/*
 * Valor contable en el navegador: categorías contables, el cuadro de sumas por categoría, usuario y sede,
 * el valor de un equipo con su RAM instalada, la estación de trabajo de una persona y los archivos de un
 * componente. En Chromium deja capturas de cada pantalla para revisar su presentación.
 */
import { test, expect, type Page, type TestInfo } from '@playwright/test';
import { iniciarSesionUi, sesionApi, SEDES, ejecutar } from '../soporte';
import { altaApi, hex, persona, tipoYModelo } from '../datos';

const captura = async (page: Page, info: TestInfo, nombre: string) => {
  if (info.project.name === 'chromium') {
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: info.outputPath(`${nombre}.png`), fullPage: true });
  }
};

test('el valor de cada equipo, de la estación de una persona y de los libros', async ({ page, browser }, info) => {
  // Recorre cinco pantallas y dos inicios de sesión (uno con TOTP, que puede esperar al paso siguiente).
  test.setTimeout(240_000);
  // --- Datos: una laptop con su RAM y un monitor, a cargo de una persona ---
  const jefe = await sesionApi('qa_contable_ti');
  const categorias = await (await jefe.ctx.get('/api/v1/accounting/categories')).json() as { id: number; code: string; country: string }[];
  const computo = categorias.find((c) => c.country === 'GT' && c.code === 'GT_COMPUTO');
  expect(computo, 'La categoría de cómputo de Guatemala viene sembrada').toBeTruthy();
  const laptop = await tipoYModelo('COMPUTO');
  const ram = await tipoYModelo('COMPONENTE');
  const monitor = await tipoYModelo('PERIFERICO');
  for (const t of [laptop, ram, monitor]) {
    expect((await jefe.ctx.patch(`/api/v1/cat/tipos-activo/${t.tipo}`, { data: { FAC_ASSET_CATEGORY: computo!.id } })).status()).toBe(200);
  }
  const equipo = await altaApi(jefe, laptop.modelo, laptop.tipo, { ACT_Costo: 12000, ACT_Fecha_Compra: '2025-03-10', ACT_Factura: 'F-1001' });
  const memoria = await altaApi(jefe, ram.modelo, ram.tipo, { ACT_Costo: 1500, ACT_Fecha_Compra: '2025-03-10', ACT_Hostname: null });
  const pantalla = await altaApi(jefe, monitor.modelo, monitor.tipo, { ACT_Costo: 2400, ACT_Fecha_Compra: '2025-05-20', ACT_Hostname: null });
  expect((await jefe.ctx.post(`/api/v1/core/activos/${equipo.ACT_Activo}/componentes`, { data: { ACT_Componente: memoria.ACT_Activo, motivo: 'Ampliación' } })).status()).toBe(200);
  const ana = await persona(SEDES.A, 'Ana');
  await ejecutar('UPDATE "INV_ACTIVO" SET "ACT_Responsable" = :p WHERE "ACT_Activo" IN (:a, :b)', { p: hex(ana), a: hex(equipo.ACT_Activo), b: hex(pantalla.ACT_Activo) });

  await iniciarSesionUi(page, 'qa_contable_ti');

  // --- Categorías contables ---
  await page.goto('/contabilidad/categorias');
  await expect(page.getByRole('heading', { name: 'Categorías contables' })).toBeVisible();
  await expect(page.getByText('Equipo de computación', { exact: true })).toBeVisible();
  await expect(page.getByRole('cell', { name: '36 meses', exact: true }).first()).toBeVisible();
  await captura(page, info, '1-categorias');
  await page.getByRole('button', { name: 'Nueva categoría' }).click();
  const dialogo = page.getByRole('dialog');
  await expect(dialogo).toBeVisible();
  await dialogo.getByLabel('Tasa anual %').fill('20');
  // Dentro del diálogo: la tabla de detrás también muestra vidas útiles de 60 meses.
  await expect(dialogo.getByText('Vida útil: 60 meses')).toBeVisible();
  await captura(page, info, '2-categoria-nueva');
  await page.getByRole('button', { name: 'Cancelar' }).click();

  // --- Cuadro de sumas: por categoría, por usuario y por sede ---
  await page.goto('/contabilidad/activos-fijos');
  await expect(page.getByRole('heading', { name: 'Activos fijos' })).toBeVisible();
  await expect(page.getByText('Total en libros')).toBeVisible();
  await expect(page.getByRole('cell', { name: /Equipo de computación/ })).toBeVisible();
  await captura(page, info, '3-libros-categoria');
  await page.getByRole('button', { name: 'Usuario', exact: true }).click();
  await expect(page.locator(`a[href$="/empleados/${ana}"]`)).toBeVisible();
  await captura(page, info, '4-libros-usuario');
  await page.getByRole('button', { name: 'Sede', exact: true }).click();
  await captura(page, info, '5-libros-sede');

  // --- La estación de trabajo de la persona: laptop con su RAM, y el monitor ---
  await page.getByRole('button', { name: 'Usuario', exact: true }).click();
  await page.locator(`a[href$="/empleados/${ana}"]`).click();
  await expect(page).toHaveURL(new RegExp(`/empleados/${ana}$`, 'i'));
  await page.getByRole('tab', { name: 'Valor de la estación' }).click();
  await expect(page.getByText('Valor de la estación de trabajo')).toBeVisible();
  await expect(page.getByText(memoria.ACT_Codigo_Interno)).toBeVisible();
  await expect(page.getByText(`Total de ${equipo.ACT_Codigo_Interno} con lo instalado`)).toBeVisible();
  await captura(page, info, '6-estacion');

  // --- El equipo: su valor con la RAM, la tabla anual y los archivos de un componente ---
  await page.goto(`/activos/${encodeURIComponent(equipo.ACT_Codigo_Interno)}`);
  await page.getByRole('tab', { name: 'Valor contable' }).click();
  await expect(page.getByText('Valor del equipo con sus componentes')).toBeVisible();
  await expect(page.getByText('Depreciación año por año')).toBeVisible();
  await captura(page, info, '7-equipo');
  await page.getByRole('button', { name: `Archivos de ${memoria.ACT_Codigo_Interno}` }).click();
  await expect(page.getByRole('heading', { name: `Archivos de ${memoria.ACT_Codigo_Interno}` })).toBeVisible();
  await captura(page, info, '8-archivos-componente');
  // El diálogo de archivos va encima del detalle del equipo, que tiene su propio «Cerrar».
  await page.getByRole('dialog').last().getByRole('button', { name: 'Cerrar', exact: true }).click();
  await page.getByRole('tab', { name: 'Componentes' }).click();
  await expect(page.getByRole('button', { name: `Archivos de ${memoria.ACT_Codigo_Interno}` })).toBeVisible();

  // --- Compras ve los libros por categoría, tipo y sede, pero no a las personas ---
  const compras = await (await browser.newContext({ ignoreHTTPSErrors: true })).newPage();
  await iniciarSesionUi(compras, 'qa_compras_norte');
  await compras.goto('/contabilidad/activos-fijos');
  await expect(compras.getByRole('heading', { name: 'Activos fijos' })).toBeVisible();
  await expect(compras.getByRole('button', { name: 'Sede', exact: true })).toBeVisible();
  await expect(compras.getByRole('button', { name: 'Usuario', exact: true })).toHaveCount(0);
});
