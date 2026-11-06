/*
 * Logo: el administrador del sistema decide entre subir la imagen o usar un enlace. Un enlace que no
 * muestra una imagen se avisa y no se guarda; cambiar de opción no borra lo ya subido.
 */
import { deflateSync } from 'node:zlib';
import { test, expect } from '@playwright/test';
import { conCerrojo, iniciarSesionUi, sesionApi } from '../soporte';

/** PNG mínimo válido de 1×1 px. */
function png(): Buffer {
  const crcTabla = Array.from({ length: 256 }, (_, n) => {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    return c >>> 0;
  });
  const crc = (b: Buffer): number => {
    let c = 0xffffffff;
    for (const x of b) c = crcTabla[(c ^ x) & 0xff] ^ (c >>> 8);
    return (c ^ 0xffffffff) >>> 0;
  };
  const trozo = (tipo: string, datos: Buffer): Buffer => {
    const largo = Buffer.alloc(4);
    largo.writeUInt32BE(datos.length);
    const cuerpo = Buffer.concat([Buffer.from(tipo), datos]);
    const suma = Buffer.alloc(4);
    suma.writeUInt32BE(crc(cuerpo));
    return Buffer.concat([largo, cuerpo, suma]);
  };
  const ihdr = Buffer.from([0, 0, 0, 1, 0, 0, 0, 1, 8, 2, 0, 0, 0]);
  return Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), trozo('IHDR', ihdr),
    trozo('IDAT', deflateSync(Buffer.from([0, 200, 30, 30]))), trozo('IEND', Buffer.alloc(0))]);
}

test.describe.configure({ mode: 'serial' });

// El logo es uno solo para todo el sistema: los navegadores se turnan para no pisarse.
test('el usuario elige entre subir la imagen o usar un enlace, y un enlace de Drive se convierte', async ({ page }) => {
  test.setTimeout(400_000);
  await conCerrojo('logo', async () => {
  const raiz = await sesionApi('qa_superadmin');
  await raiz.ctx.put('/api/v1/gov/config', { data: { SYS_Logo_URL: null } });
  await iniciarSesionUi(page, 'qa_superadmin');
  await page.goto('/config');

  // Subir: se guarda al elegirla y se ve en la vista previa.
  await expect(page.getByRole('button', { name: 'Subir imagen' }).first()).toHaveAttribute('aria-pressed', 'true');
  await page.locator('input[type="file"]').setInputFiles({ name: 'logo.png', mimeType: 'image/png', buffer: png() });
  await expect(page.getByText('Logo actualizado.')).toBeVisible();
  await expect(page.getByRole('img', { name: 'Logotipo de la empresa' }).first()).toBeVisible();

  // Un archivo que no es imagen se avisa sin enviarlo.
  await page.locator('input[type="file"]').setInputFiles({ name: 'nota.txt', mimeType: 'text/plain', buffer: Buffer.from('hola') });
  await expect(page.getByText('Elija una imagen PNG, JPG, WebP o GIF.').first()).toBeVisible();

  // Enlace: el campo aparece vacío y lo subido sigue vigente mientras no se guarde otro.
  await page.getByRole('button', { name: 'Usar un enlace' }).click();
  const campo = page.getByLabel('URL del logotipo');
  await expect(campo).toHaveValue('');
  await campo.fill('http://no-seguro.example/logo.png');
  await expect(page.getByText('Use una dirección que empiece con https://')).toBeVisible();

  // Enlace que no muestra una imagen: se avisa y no se guarda.
  await campo.fill('https://qa.inventario.local/no-existe.png');
  await expect(page.getByText(/Esa dirección no muestra una imagen/)).toBeVisible();
  await page.getByRole('button', { name: 'Guardar cambios' }).click();
  const guardado = await (await raiz.ctx.get('/api/v1/gov/config')).json();
  expect(guardado.SYS_Logo_URL).toContain('/api/v1/gov/config/logo');

  // Volver a «Subir imagen» recupera lo subido.
  await page.getByRole('button', { name: 'Subir imagen' }).first().click();
  await expect(page.getByRole('img', { name: 'Logotipo de la empresa' }).first()).toBeVisible();

  // Un enlace de Drive se convierte en la dirección de la imagen; quitar el logo se conserva.
  await page.getByRole('button', { name: 'Usar un enlace' }).click();
  await campo.fill('https://drive.google.com/file/d/1AbCdEfGhIjKlMnOp_qrs/view?usp=sharing');
  await campo.blur();
  await expect(campo).toHaveValue('https://drive.google.com/thumbnail?id=1AbCdEfGhIjKlMnOp_qrs&sz=w1000');

  await page.getByRole('button', { name: 'Quitar logotipo' }).click();
  await expect(page.getByText('Logotipo quitado.')).toBeVisible();
  expect((await (await raiz.ctx.get('/api/v1/gov/config')).json()).SYS_Logo_URL).toBeNull();
  });
});
