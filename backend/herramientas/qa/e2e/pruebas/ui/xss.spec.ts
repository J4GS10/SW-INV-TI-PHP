/*
 * XSS almacenado y reflejado en el frontend. Primera barrera: los identificadores (hostname de un activo)
 * no admiten HTML. En texto libre (nombre de un consumible) el HTML se guarda tal cual y se muestra como
 * texto; tampoco se ejecuta lo que llega en la URL. Ningún script corre (ni alert, ni marca en window).
 */
import { test, expect } from '@playwright/test';
import { iniciarSesionUi, sesionApi, SEDES, sufijo } from '../soporte';
import { altaApi, tipoYModelo } from '../datos';

test('XSS almacenado y reflejado: los datos con HTML se muestran como texto en todas las pantallas', async ({ page }) => {
  const jefe = await sesionApi('qa_jefe_ti');
  const cat = await tipoYModelo();
  const marca = sufijo();
  const carga = `"><img src=x onerror=window.__xss=1>${marca}`;
  const base = await altaApi(jefe, cat.modelo, cat.tipo);
  const rechazo = await jefe.ctx.post('/api/v1/core/activos', { data: { ACT_Serie_Fabricante: `XSS-${marca}`, ACT_Fecha_Compra: '2026-10-01',
    MOD_Modelo: cat.modelo, TAC_Tipo_Activo: cat.tipo, EOP_Estado_Operativo: base.EOP_Estado_Operativo, SED_Sede: SEDES.A, ACT_Hostname: carga } });
  expect(rechazo.status(), 'Un hostname con HTML no se acepta').toBe(422);
  const c = await jefe.ctx.post('/api/v1/consumibles', { data: { CON_Nombre: carga, CON_Stock_Minimo: 1, SED_Sede: SEDES.A } });
  expect(c.status(), await c.text()).toBe(201);
  let dialogos = 0;
  page.on('dialog', async (d) => { dialogos++; await d.dismiss(); });
  await iniciarSesionUi(page, 'qa_tecnico_norte');
  await page.goto('/consumibles');
  await page.getByRole('textbox', { name: /Buscar/ }).first().fill(marca);
  await expect(page.getByText(carga).first()).toBeVisible();
  // Reflejado: parámetros de la URL con script.
  await page.goto(`/activos?q=${encodeURIComponent('<script>window.__xss=2</script>')}&tipo=%3Csvg%20onload%3Dalert(1)%3E`);
  await page.waitForLoadState('networkidle');
  expect(await page.evaluate(() => (window as unknown as { __xss?: number }).__xss)).toBeUndefined();
  expect(dialogos).toBe(0);
});
