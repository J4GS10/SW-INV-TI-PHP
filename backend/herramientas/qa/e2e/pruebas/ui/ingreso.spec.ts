/*
 * Ingreso a bodega de punta a punta en el navegador: Compras registra lo que llegó, un técnico
 * clasifica y confirma, completa la serie y queda el acta. Valida también los avisos mientras se escribe.
 */
import { test, expect } from '@playwright/test';
import { iniciarSesionUi, sesionApi, sufijo } from '../soporte';
import { tipoYModelo } from '../datos';

/** PNG válido de 1×1 píxel: el servidor revisa el contenido del archivo, no solo su extensión. */
const PNG_1X1 = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');

test('Compras registra, TI confirma y el técnico completa', async ({ browser }) => {
  const jefe = await sesionApi('qa_jefe_ti');
  const nombreProveedor = `Proveedor E2E ${sufijo()}`;
  expect((await jefe.ctx.post('/api/v1/compras/proveedores', { data: { PRV_Nombre: nombreProveedor } })).status()).toBe(201);
  const cat = await tipoYModelo();
  const factura = `F-E2E-${sufijo()}`;
  const producto = `Laptop E2E ${sufijo()}`;

  // --- Compras ---
  const compras = await (await browser.newContext({ ignoreHTTPSErrors: true })).newPage();
  await iniciarSesionUi(compras, 'qa_compras_norte');
  await expect(compras).toHaveURL(/\/inicio$/);
  await compras.getByRole('link', { name: /Nuevo ingreso/ }).click();
  const guardar = compras.getByRole('button', { name: 'Registrar ingreso' });
  await expect(guardar).toBeDisabled();

  await compras.getByRole('combobox', { name: 'Proveedor' }).fill(nombreProveedor);
  await compras.getByRole('option', { name: nombreProveedor }).click();
  await compras.getByLabel('Factura o envío').fill(factura);
  await compras.getByRole('textbox', { name: 'Producto' }).fill(producto);

  // Avisa mientras se escribe, no al guardar.
  await compras.getByRole('textbox', { name: 'Costo unitario' }).fill('abc');
  await expect(compras.getByText('Número con hasta 2 decimales')).toBeVisible();
  await compras.getByRole('textbox', { name: 'Costo unitario' }).fill('1250.00');
  await compras.getByRole('textbox', { name: 'Cantidad' }).fill('1');
  // La foto de la factura: el técnico verá que existe, pero no podrá abrirla (muestra precios).
  const archivo = `factura-${sufijo()}.png`;
  await compras.locator('input[type=file]').setInputFiles({ name: archivo, mimeType: 'image/png', buffer: PNG_1X1 });
  await expect(compras.getByText(archivo)).toBeVisible();
  await expect(guardar).toBeEnabled();
  await guardar.click();
  await expect(compras).toHaveURL(/\/ingresos\/\d+$/);
  await expect(compras.getByText('TI debe confirmar lo recibido')).toBeVisible();
  await expect(compras.getByRole('button', { name: 'Acta de recibido' })).toHaveCount(0);
  const url = new URL(compras.url()).pathname;

  // Una factura repetida se avisa al salir del campo.
  // El último proveedor viene precargado.
  await compras.goto('/ingresos/nuevo');
  await expect(compras.getByRole('combobox', { name: 'Proveedor' })).toHaveValue(nombreProveedor);
  await compras.getByLabel('Factura o envío').fill(factura);
  await compras.getByLabel('Factura o envío').blur();
  await expect(compras.getByText('Esta factura ya está registrada')).toBeVisible();

  // --- Técnico ---
  const tecnico = await (await browser.newContext({ ignoreHTTPSErrors: true })).newPage();
  await iniciarSesionUi(tecnico, 'qa_tecnico_norte');
  await tecnico.goto(url);
  await expect(tecnico.getByText(producto)).toBeVisible();
  await expect(tecnico.getByText('1250.00')).toHaveCount(0);
  // Lo que registró Compras se lee entero; el archivo se lista sin poder abrirlo.
  await expect(tecnico.getByText(nombreProveedor).first()).toBeVisible();
  await expect(tecnico.getByText(archivo)).toBeVisible();
  await expect(tecnico.getByRole('button', { name: `Descargar ${archivo}` })).toHaveCount(0);
  await expect(tecnico.getByText('Solo Compras, Administración de TI y Auditoría abren los archivos', { exact: false })).toBeVisible();
  await tecnico.getByLabel('Clasificar como').click();
  await tecnico.getByRole('option', { name: 'Activo' }).click();
  await tecnico.getByLabel('Tipo de activo').fill(cat.nombre);
  await tecnico.getByRole('option', { name: cat.nombre }).click();
  await tecnico.getByLabel('Modelo', { exact: true }).click();
  await tecnico.getByRole('option').first().click();
  await tecnico.getByRole('button', { name: 'Confirmar lo recibido' }).click();
  await expect(tecnico.getByText('Ya entró al inventario')).toBeVisible();

  await tecnico.getByRole('link', { name: 'Por completar' }).first().click();
  // La bandeja tiene lo pendiente de todos los ingresos: se escribe en la tarjeta del producto recién recibido.
  const unidad = tecnico.locator('.MuiCard-root').filter({ hasText: producto });
  await expect(unidad).toHaveCount(1);
  await unidad.getByLabel('Serie').fill(`SN-E2E-${sufijo()}`);
  await unidad.getByLabel('Serie').press('Enter');
  await expect(tecnico.getByText('completado', { exact: false }).first()).toBeVisible();
  await expect(tecnico.getByRole('button', { name: /Imprimir 1 etiqueta/ })).toBeVisible();

  // --- Compras recibe el acta ---
  await compras.goto(url);
  await expect(compras.getByRole('button', { name: 'Acta de recibido' })).toBeVisible();
  await expect(compras.getByRole('button', { name: `Descargar ${archivo}` })).toBeVisible();
  const descarga = compras.waitForEvent('download');
  await compras.getByRole('button', { name: 'Acta de recibido' }).click();
  await expect(compras.getByText('En bodega, por completar').or(compras.getByText('Completo')).first()).toBeVisible();
  expect((await descarga).suggestedFilename()).toContain('acta-recibido');
});
