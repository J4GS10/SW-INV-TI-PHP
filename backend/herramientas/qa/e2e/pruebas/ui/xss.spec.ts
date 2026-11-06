/*
 * XSS almacenado y reflejado en el frontend: datos con HTML/JS guardados por la API (hostname de un
 * activo) y en la URL se muestran como texto en el listado, la búsqueda, el escáner y la ficha.
 * Ningún script se ejecuta (ni alert, ni marca en window).
 */
import { test, expect } from '@playwright/test';
import { iniciarSesionUi, sesionApi, sufijo } from '../soporte';
import { altaApi, tipoYModelo } from '../datos';

test('XSS almacenado y reflejado: los datos con HTML se muestran como texto en todas las pantallas', async ({ page }) => {
  const jefe = await sesionApi('qa_jefe_ti');
  const cat = await tipoYModelo();
  const carga = `"><img src=x onerror=window.__xss=1>${sufijo()}`;
  const a = await altaApi(jefe, cat.modelo, cat.tipo, { ACT_Hostname: carga });
  let dialogos = 0;
  page.on('dialog', async (d) => { dialogos++; await d.dismiss(); });
  await iniciarSesionUi(page, 'qa_tecnico_norte');
  await page.goto(`/activos?q=${encodeURIComponent(a.ACT_Codigo_Interno)}`);
  await expect(page.getByText(carga).first()).toBeVisible();
  await page.goto('/scanner');
  await page.getByLabel('Código del activo').fill(a.ACT_Codigo_Interno);
  await page.getByLabel('Código del activo').press('Enter');
  await expect(page.getByText(carga).first()).toBeVisible();
  // Reflejado: parámetros de la URL con script.
  await page.goto(`/activos?q=${encodeURIComponent('<script>window.__xss=2</script>')}&tipo=%3Csvg%20onload%3Dalert(1)%3E`);
  await page.waitForLoadState('networkidle');
  expect(await page.evaluate(() => (window as unknown as { __xss?: number }).__xss)).toBeUndefined();
  expect(dialogos).toBe(0);
});
