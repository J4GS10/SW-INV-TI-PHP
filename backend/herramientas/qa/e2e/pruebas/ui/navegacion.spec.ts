/*
 * Recorrido por rol (click-path): cada usuario sembrado entra por la interfaz y recorre,
 * con clics en el menú lateral, cada pantalla que su rol puede ver. Por pantalla: llega a
 * la ruta, sin errores de consola, sin 5xx de la API, sin 403 (incoherencia entre menú y
 * permisos) y sin la pantalla de error. El menú no ofrece lo que el rol no puede ver, y
 * entrar a esa ruta escribiéndola no muestra datos. Guarda una instantánea accesible.
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import { test, expect, type Page } from '@playwright/test';
import { iniciarSesionUi } from '../soporte';

// Espejo de src/router/navigation.ts y src/utils/roles.ts del frontend (títulos en español).
const INVENTARIO = ['ADMIN_TI', 'TECNICO', 'AUDITOR', 'CONSULTA'];
const EXISTENCIAS = INVENTARIO;
const ADMIN_INV = ['ADMIN_TI'];
const LECTOR_ADMIN = [...ADMIN_INV, 'AUDITOR'];
const COMPRAS_LECTURA = [...ADMIN_INV, 'COMPRAS', 'AUDITOR'];
const FINANZAS = [...LECTOR_ADMIN, 'COMPRAS'];
const PANTALLAS: [string, string, string[]][] = [
  ['/inicio', 'Mis tareas', [...EXISTENCIAS, 'COMPRAS']], ['/ingresos/por-completar', 'Por completar', ['ADMIN_TI', 'TECNICO']],
  ['/panel', 'Panel de control', EXISTENCIAS], ['/activos', 'Activos', EXISTENCIAS], ['/scanner', 'Escáner QR', INVENTARIO],
  ['/operaciones/movimientos', 'Movimientos', INVENTARIO], ['/operaciones/alta-empleado', 'Alta de empleado', ADMIN_INV],
  ['/mantenimiento', 'Mantenimiento', INVENTARIO], ['/consumibles', 'Consumibles', EXISTENCIAS],
  ['/trazabilidad', 'Trazabilidad', INVENTARIO], ['/inventario/tomas', 'Toma de inventario', INVENTARIO],
  ['/aprobaciones', 'Aprobaciones', LECTOR_ADMIN], ['/contabilidad/cierre', 'Cierre mensual', FINANZAS],
  ['/auditoria', 'Auditoría', ['SUPER_ADMIN', 'ADMIN_SEGURIDAD', 'AUDITOR']],
  ['/ingresos', 'Ingresos a bodega', [...INVENTARIO, 'COMPRAS']], ['/compras/stock', 'Stock y asignaciones', FINANZAS],
  ['/compras/proveedores', 'Proveedores', COMPRAS_LECTURA], ['/compras/garantias', 'Garantías', LECTOR_ADMIN],
  ['/software', 'Software', LECTOR_ADMIN],
  ['/empleados', 'Empleados', LECTOR_ADMIN], ['/departamentos', 'Departamentos', LECTOR_ADMIN],
  ['/usuarios', 'Usuarios y accesos', ['SUPER_ADMIN', 'ADMIN_SEGURIDAD']], ['/config/importar', 'Importar activos', ADMIN_INV],
  ['/config/catalogos', 'Centro de catálogos', ADMIN_INV], ['/config/directorio', 'Directorio y notificaciones', ['SUPER_ADMIN']],
  ['/config', 'Configuración', ['SUPER_ADMIN']],
];
const USUARIOS: [string, string][] = [
  ['qa_superadmin', 'SUPER_ADMIN'], ['qa_admin_seg', 'ADMIN_SEGURIDAD'],
  ['qa_admin_ti_norte', 'ADMIN_TI'], ['qa_tecnico_norte', 'TECNICO'], ['qa_auditor', 'AUDITOR'],
  ['qa_consulta_norte', 'CONSULTA'], ['qa_compras_norte', 'COMPRAS'],
];

function vigilar(page: Page): { errores: string[]; prohibidas: string[] } {
  const estado = { errores: [] as string[], prohibidas: [] as string[] };
  page.on('console', (m) => {
    if (m.type() === 'error') estado.errores.push(`consola: ${m.text()}`);
  });
  page.on('pageerror', (e) => estado.errores.push(`excepción: ${e.message}`));
  page.on('response', (r) => {
    if (!r.url().includes('/api/')) return;
    const ruta = `${r.request().method()} ${new URL(r.url()).pathname}`;
    if (r.status() >= 500) estado.errores.push(`${r.status()} ${ruta}`);
    if (r.status() === 403) estado.prohibidas.push(ruta);
  });
  return estado;
}

for (const [nombre, rol] of USUARIOS) {
  test(`navegación de ${rol} (${nombre})`, async ({ page }, info) => {
    test.skip(info.project.name.startsWith('movil'), 'En móvil el menú es un cajón: lo cubre movil.spec.ts');
    const estado = vigilar(page);
    await iniciarSesionUi(page, nombre);
    const menu = page.getByRole('navigation');
    const dir = `${process.env.QA_EVIDENCIA ?? 'evidencia'}/pantallas/${info.project.name}/${rol}`;
    mkdirSync(dir, { recursive: true });

    // Los grupos plegables desmontan sus opciones al cerrarse: se abren todos (el estado se recuerda)
    // para que «no aparece» signifique que el rol no la tiene, no que el grupo está cerrado.
    // Abrir un grupo puede cerrar otro (acordeón): se reabren antes de cada búsqueda.
    const plegados = menu.locator('[aria-expanded="false"]');
    const expandir = async (): Promise<void> => {
      for (let i = 0; i < 6 && (await plegados.count()); i++) await plegados.first().click({ timeout: 3000 }).catch(() => undefined);
    };

    for (const [ruta, titulo, roles] of PANTALLAS) {
      await expandir();
      const opcion = menu.getByRole('button', { name: titulo, exact: true });
      if (!roles.includes(rol)) {
        await expect(opcion, `el menú de ${rol} no debe ofrecer «${titulo}»`).toHaveCount(0);
        continue;
      }
      await opcion.click();
      await expect(page, `«${titulo}» lleva a ${ruta}`).toHaveURL(new RegExp(`${ruta.replace(/\//g, '\\/')}(\\?|$)`));
      await page.waitForLoadState('networkidle');
      await expect(page.getByText(/algo salió mal|something went wrong/i)).toHaveCount(0);
      if (info.project.name === 'chromium') {
        writeFileSync(`${dir}/${ruta.replace(/\//g, '_')}.yml`, await page.locator('main').ariaSnapshot());
      }
    }
    expect(estado.errores, 'errores de consola o 5xx').toEqual([]);
    expect(estado.prohibidas, 'pantallas permitidas que reciben 403 de la API').toEqual([]);

    // Rutas prohibidas escritas a mano: no muestran la pantalla (el menú no las tiene y la API las rechaza).
    const prohibida = PANTALLAS.find(([, , roles]) => !roles.includes(rol));
    if (prohibida) {
      estado.prohibidas.length = 0;
      await page.goto(prohibida[0]);
      await page.waitForLoadState('networkidle');
      await expect(page.getByRole('heading', { name: prohibida[1], exact: true }), `${rol} no ve «${prohibida[1]}»`).toHaveCount(0);
    }
    await page.screenshot({ path: `${dir}/ultima.png`, fullPage: true });
  });
}
