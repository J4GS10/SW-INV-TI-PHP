/*
 * Componente nuevo desde la ficha del equipo: con un modelo que aún no existe, se registra e instala en un
 * solo paso. El navegador hace una única escritura (el servidor crea el modelo, el activo y sus valores de
 * fábrica en la misma transacción) y el componente queda instalado en el equipo.
 */
import { test, expect } from '@playwright/test';
import { consulta, ejecutar, iniciarSesionUi, irA, sesionApi, sufijo } from '../soporte';
import { altaApi, tipoYModelo } from '../datos';

test('componente con modelo nuevo, registrado e instalado en un paso', async ({ page }) => {
  // Dos inicios de sesión con TOTP de la misma cuenta: el segundo puede esperar al paso siguiente.
  test.setTimeout(180_000);
  const jefe = await sesionApi('qa_componentes_ti');
  const laptop = await tipoYModelo('COMPUTO');
  const bateria = await tipoYModelo('COMPONENTE');
  // El equipo admite solo este tipo de componente: el formulario lo trae elegido.
  expect((await jefe.ctx.put(`/api/v1/cat/tipos-activo/${laptop.tipo}/componentes`, { data: { componentes: [bateria.tipo] } })).status()).toBe(200);
  const equipo = await altaApi(jefe, laptop.modelo, laptop.tipo);
  const [{ MARCA: marca }] = await consulta<{ MARCA: string }>(
    'SELECT ma."MAR_Nombre" MARCA FROM "INV_MODELO" m JOIN "INV_MARCA" ma ON ma."MAR_Marca" = m."MAR_Marca" WHERE m."MOD_Modelo" = :m', { m: bateria.modelo });
  const modeloNuevo = `Pila E2E ${sufijo()}`;
  const serie = `BAT-E2E-${sufijo()}`;

  await iniciarSesionUi(page, 'qa_componentes_ti');
  await irA(page, `/activos/${encodeURIComponent(equipo.ACT_Codigo_Interno)}`);
  await page.getByRole('tab', { name: 'Componentes' }).click();
  await page.getByRole('button', { name: 'Registrar componente nuevo' }).click();
  const form = page.getByRole('region', { name: 'Componente nuevo' });
  await expect(form).toBeVisible();

  // Un modelo que no existe se crea ahí mismo con su marca.
  await form.getByRole('combobox', { name: 'Modelo' }).fill(modeloNuevo);
  await page.getByRole('option', { name: `Crear el modelo «${modeloNuevo}»` }).click();
  await form.getByLabel(`Marca del modelo «${modeloNuevo}»`).click();
  await page.getByRole('option', { name: marca, exact: true }).click();
  await form.getByLabel('Número de serie').fill(serie);

  const escrituras: string[] = [];
  page.on('request', (r) => {
    // La búsqueda usa POST pero solo lee: no cuenta como escritura.
    if (r.method() !== 'GET' && r.url().includes('/api/v1/') && !r.url().includes('/search')) escrituras.push(`${r.method()} ${new URL(r.url()).pathname}`);
  });
  await form.getByRole('button', { name: 'Registrar y seguir con otro igual' }).click();
  await expect(page.getByText(`Componente registrado e instalado en ${equipo.ACT_Codigo_Interno}`).first()).toBeVisible();
  // Una sola operación del servidor: nada de crear el modelo aparte ni guardar sus valores después.
  expect(escrituras).toEqual(['POST /api/v1/core/activos']);

  // La siguiente pieza igual: el formulario sigue con el tipo y el modelo (ya creado); solo se escribe la serie y Enter.
  await expect(page.getByText('Listo para la siguiente pieza', { exact: false })).toBeVisible();
  await expect(form.getByRole('combobox', { name: 'Modelo' })).toHaveValue(new RegExp(modeloNuevo));
  const serie2 = `BAT-E2E-${sufijo()}`;
  await form.getByLabel('Número de serie').fill(serie2);
  await form.getByLabel('Número de serie').press('Enter');
  await expect(form).toHaveCount(0);
  expect(escrituras).toEqual(['POST /api/v1/core/activos', 'POST /api/v1/core/activos']);

  // Los dos quedan instalados, agrupados bajo su tipo, con un solo modelo creado.
  await expect(page.getByText('2 componentes instalados')).toBeVisible();
  await expect(page.getByText(serie, { exact: false }).first()).toBeVisible();
  const instalados = await (await jefe.ctx.get(`/api/v1/core/activos/${equipo.ACT_Activo}/componentes`)).json() as { actuales: unknown[] };
  expect(JSON.stringify(instalados.actuales)).toContain(serie);
  expect(JSON.stringify(instalados.actuales)).toContain(serie2);
  const modelos = await consulta<{ N: number }>('SELECT COUNT(*) N FROM "INV_MODELO" WHERE "MOD_Nombre" = :n AND "TAC_Tipo_Activo" = :t',
    { n: modeloNuevo, t: bateria.tipo });
  expect(Number(modelos[0].N)).toBe(1);
});

test('tipo de activo configurado en una pantalla: el componente obligatorio queda pendiente en el equipo', async ({ page }) => {
  test.setTimeout(240_000); // Dos ingresos con TOTP de la misma cuenta que usa la otra prueba de este archivo.
  const s = sufijo().toUpperCase();
  const nombreTipo = `QA Equipo ${s}`;
  const nombreComponente = `Batería QA ${s}`;
  await iniciarSesionUi(page, 'qa_componentes_ti');

  // 1. ¿Qué es? 3. ¿Qué componentes lleva? — en el mismo diálogo, sin ir a otras listas.
  await irA(page, '/config/catalogos?g=ACTIVOS&c=tipos-activo');
  await page.getByRole('button', { name: 'Nuevo tipo' }).click();
  const dialogo = page.getByRole('dialog');
  await dialogo.getByRole('textbox', { name: /^Nombre/ }).fill(nombreTipo);
  await dialogo.getByLabel('Clase').click();
  await page.getByRole('option', { name: 'Cómputo', exact: true }).click();
  await expect(dialogo.getByText('Listo para registrar activos')).toBeVisible();
  await dialogo.getByRole('combobox', { name: 'Agregar un componente' }).fill(nombreComponente);
  // La primera opción queda resaltada: Enter la elige (en WebKit la lista puede quedar fuera de la vista).
  await expect(page.getByRole('option', { name: `Crear «${nombreComponente}» como tipo de componente` })).toBeVisible();
  await dialogo.getByRole('combobox', { name: 'Agregar un componente' }).press('Enter');
  await expect(dialogo.getByText(nombreComponente)).toBeVisible();
  // Un componente agregado nace obligatorio (es lo habitual: la batería de una laptop).
  await expect(dialogo.getByLabel('Obligatorio al registrar')).toBeChecked();

  // Al crearlo, el diálogo sigue abierto con las características habituales de su clase para revisarlas.
  await dialogo.getByRole('button', { name: 'Crear tipo' }).click();
  await expect(dialogo.getByText('El tipo ya existe', { exact: false })).toBeVisible();
  await expect(dialogo.getByText(nombreComponente)).toBeVisible();
  await dialogo.getByRole('button', { name: 'Guardar' }).click();
  await expect(dialogo).toHaveCount(0);
  // Con muchos tipos, el nuevo puede quedar en otra página: el buscador lo encuentra.
  await page.getByRole('textbox', { name: 'Buscar por nombre o prefijo…' }).fill(nombreTipo);
  await expect(page.getByRole('row', { name: new RegExp(nombreTipo) })).toBeVisible();

  // Un equipo de ese tipo, sin su batería: la ficha avisa qué falta y ofrece registrarlo.
  const [{ T: tipo }] = await consulta<{ T: number }>('SELECT "TAC_Tipo_Activo" T FROM "INV_TIPO_ACTIVO" WHERE "TAC_Nombre" = :n', { n: nombreTipo });
  await ejecutar('INSERT INTO "INV_MARCA" ("MAR_Nombre") VALUES (:n)', { n: `QA Marca ${s}` });
  await ejecutar('INSERT INTO "INV_MODELO" ("MOD_Nombre", "MAR_Marca", "TAC_Tipo_Activo") '
    + 'SELECT :m, "MAR_Marca", :t FROM "INV_MARCA" WHERE "MAR_Nombre" = :n', { m: `QA Modelo ${s}`, t: tipo, n: `QA Marca ${s}` });
  const [{ M: modelo }] = await consulta<{ M: number }>('SELECT "MOD_Modelo" M FROM "INV_MODELO" WHERE "MOD_Nombre" = :m', { m: `QA Modelo ${s}` });
  const jefe = await sesionApi('qa_componentes_ti');
  const equipo = await altaApi(jefe, Number(modelo), Number(tipo));
  await irA(page, `/activos/${encodeURIComponent(equipo.ACT_Codigo_Interno)}`);
  await page.getByRole('tab', { name: 'Componentes' }).click();
  await expect(page.getByText('Faltan componentes obligatorios')).toBeVisible();
  await page.getByRole('button', { name: `${nombreComponente}: falta 1` }).click();
  await expect(page.getByRole('region', { name: 'Componente nuevo' })).toBeVisible();
});

test('centro de catálogos: ninguna pantalla muestra claves sin traducir; los métodos de activación dicen qué piden', async ({ page }, info) => {
  test.setTimeout(240_000);
  // Una clave de traducción a la vista («catalog_center.extras.MAC_Etiqueta_Usuario») es un texto que nadie entiende.
  const claveCruda = /\b(?:[a-z]+_[a-z_]+|catalogs|common|accounting|components)\.[A-Za-z_]+(?:\.[A-Za-z_]+)*\b/;
  await iniciarSesionUi(page, 'qa_catalogos_ti');
  await irA(page, '/config/catalogos');
  const areas = page.getByRole('tablist', { name: 'Área' }).getByRole('tab');
  const totalAreas = await areas.count();
  for (let a = 0; a < totalAreas; a++) {
    await areas.nth(a).click();
    const catalogos = page.getByRole('navigation', { name: 'Catálogo' }).getByRole('button');
    const total = await catalogos.count();
    for (let c = 0; c < total; c++) {
      const nombre = (await catalogos.nth(c).innerText()).trim();
      await catalogos.nth(c).click();
      await expect(page.getByRole('heading', { level: 6, name: nombre })).toBeVisible();
      await expect(page.locator('main')).not.toContainText(claveCruda);
    }
  }

  // Métodos de activación: cada fila dice en palabras qué pide; el detalle se configura en un diálogo con vista previa.
  await page.getByRole('tab', { name: 'Licencias' }).click();
  await page.getByRole('navigation', { name: 'Catálogo' }).getByRole('button', { name: 'Métodos de activación' }).click();
  const cuenta = page.getByRole('row', { name: /Cuenta \(correo \+ clave\)/ });
  await expect(cuenta.getByText('Correo', { exact: true })).toBeVisible();
  await expect(cuenta.getByText('Contraseña', { exact: true })).toBeVisible();
  await page.screenshot({ path: info.outputPath('metodos-activacion.png'), fullPage: true });
  await cuenta.getByRole('button', { name: 'Configurar' }).click();
  const dialogo = page.getByRole('dialog', { name: /Qué pide «Cuenta \(correo \+ clave\)»/ });
  await expect(dialogo.getByText('Así se verá el formulario')).toBeVisible();
  await expect(dialogo).not.toContainText(claveCruda);
  await page.screenshot({ path: info.outputPath('metodos-activacion-dialogo.png'), fullPage: true });
  await dialogo.getByRole('button', { name: 'Cancelar' }).click();
});
