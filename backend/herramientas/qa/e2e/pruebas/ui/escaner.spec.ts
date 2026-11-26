/*
 * Rol «Escáner» y código de operador: la cuenta de un teléfono de bodega entra directo al escáner; el QR de la
 * etiqueta (/activos/<código>) abre el equipo ahí; al devolverlo pide el código de un técnico, y el cambio queda
 * a nombre del técnico y con la cuenta del escáner.
 */
import { test, expect } from '@playwright/test';
import { consulta, iniciarSesionUi, irA, SEDES, sesionApi } from '../soporte';
import { altaApi, area, persona, tipoMovimiento, tipoYModelo } from '../datos';

const CODIGO = '482916';

test('escáner: abre el equipo por su QR y lo devuelve con el código de un técnico, a su nombre', async ({ page }, info) => {
  test.setTimeout(240_000);
  const jefe = await sesionApi('qa_jefe_ti');
  const tecnico = await sesionApi('qa_tecnico_codigo');
  const definir = await tecnico.ctx.put('/api/v1/me/operator-code', { data: { code: CODIGO, current_password: tecnico.usuario.password } });
  expect(definir.status(), await definir.text()).toBe(204);

  // Un equipo asignado a una persona de la sede del escáner.
  const laptop = await tipoYModelo('COMPUTO');
  const equipo = await altaApi(jefe, laptop.modelo, laptop.tipo);
  const ana = await persona(SEDES.A, 'Escaneada');
  const lugar = await area(SEDES.A);
  const asignado = await jefe.ctx.post('/api/v1/trazabilidad/movimientos', { data: { ACT_Activo: equipo.ACT_Activo, PER_Persona: ana, ARE_Area: lugar,
    TMO_Tipo_Movimiento: await tipoMovimiento('Asignación'), MOV_Motivo: 'QA escáner' } });
  expect(asignado.status(), await asignado.text()).toBe(201);

  // Entra directo a su pantalla; las demás no existen para esta cuenta.
  await iniciarSesionUi(page, 'qa_escaner');
  await expect(page).toHaveURL(/\/scanner/);
  await irA(page, '/activos');
  await expect(page).toHaveURL(/\/scanner/);

  // El QR de la etiqueta abierto con la cámara del teléfono: el equipo se busca solo.
  await irA(page, `/activos/${encodeURIComponent(equipo.ACT_Codigo_Interno)}`);
  await expect(page).toHaveURL(/\/scanner/);
  const ficha = page.getByRole('dialog').filter({ hasText: equipo.ACT_Codigo_Interno }).first();
  await expect(ficha).toBeVisible();
  await expect(ficha.getByRole('tab', { name: 'Historial' })).toBeVisible();
  await page.screenshot({ path: info.outputPath('escaner-ficha.png') });

  // Devolver: el motivo, el software revisado y, al registrar, el código de quien lo hace.
  await ficha.getByRole('button', { name: 'Devolver', exact: true }).click();
  const devolucion = page.getByRole('dialog').filter({ hasText: `Devolver ${equipo.ACT_Codigo_Interno}` });
  await devolucion.getByRole('combobox', { name: 'Motivo' }).click();
  await page.getByRole('option', { name: 'Cambio de equipo' }).click();
  await expect(devolucion.getByText('Software revisado', { exact: false })).toBeVisible();
  await devolucion.getByRole('button', { name: 'Registrar devolución' }).click();

  const autorizar = page.getByRole('dialog').filter({ hasText: 'Autorice el cambio' });
  await expect(autorizar.getByText('Devolver el equipo a bodega')).toBeVisible();
  await autorizar.getByLabel('Su usuario').fill(tecnico.usuario.username);
  await autorizar.getByLabel('Código de operador (6 dígitos)').fill(CODIGO);
  await page.screenshot({ path: info.outputPath('escaner-codigo.png') });
  await autorizar.getByRole('button', { name: 'Autorizar' }).click();
  await expect(page.getByText(`${equipo.ACT_Codigo_Interno} fue devuelto`, { exact: false })).toBeVisible();

  // Queda a nombre del técnico, con la cuenta del escáner.
  const [evento] = await consulta<{ U: string; D: string }>('SELECT "USU_Usuario" U, "KDX_Detalle" D FROM "INV_KARDEX" '
    + 'WHERE "ACT_Activo" = :a AND "KDX_Tipo" = \'DEVOLUCION\'', { a: String(equipo.ACT_Activo).replace(/-/g, '').toLowerCase() });
  expect(evento, 'evento DEVOLUCION en el kardex').toBeTruthy();
  expect(evento.U.toLowerCase()).toBe(tecnico.usuario.id.replace(/-/g, '').toLowerCase());
  expect(JSON.parse(evento.D).cuenta).toBe('qa_escaner');
});
