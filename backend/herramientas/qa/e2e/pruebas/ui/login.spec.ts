/*
 * Autenticación de punta a punta por la interfaz, en cada navegador y en móvil: contraseña,
 * renovación al recargar y cierre de sesión, bloqueo y desbloqueo, contraseña temporal con
 * cambio obligatorio, recuperación por correo (Mailpit), enrolamiento TOTP obligatorio
 * leyendo el QR, códigos de recuperación, 2FA por correo y SSO (desactivado en producción).
 * Cada prueba que cambia una cuenta crea la suya por la API de identidades (IAM).
 */
import { test, expect, type Page } from '@playwright/test';
import { contextoApi, esperarCorreo, sesionApi, SEDES, sufijo, totp, iniciarSesionUi, usuario, type Sesion } from '../soporte';
import { persona } from '../datos';
import { leerQr } from '../qr';

let iam: Sesion; // ADMIN_SEGURIDAD
let raiz: Sesion; // SUPER_ADMIN (crea administradores)

test.beforeAll(async () => {
  [iam, raiz] = await Promise.all([sesionApi('qa_superadmin'), sesionApi('qa_superadmin')]);
});

interface Cuenta { id: string; username: string; password: string; correo: string }

/** Cuenta nueva por la API de identidades (el correo es el de su persona). */
async function cuentaNueva(rol = 'CONSULTA'): Promise<Cuenta> {
  const per = await persona(SEDES.A, 'Cuenta');
  const username = `e2e_${sufijo()}`;
  const password = `Qa.${sufijo()}${sufijo()}#9`;
  const creador = ['ADMIN_TI', 'JEFE_TI', 'ADMIN_SEGURIDAD', 'SUPER_ADMIN'].includes(rol) ? raiz : iam;
  const r = await creador.ctx.post('/api/v1/org/usuarios', { data: { USU_Username: username, USU_Password: password, USU_Rol: rol, PER_Persona: per, sedes: [SEDES.A] } });
  expect(r.status(), await r.text()).toBe(201);
  const id = (await r.json()).USU_Usuario as string;
  return { id, username, password, correo: `p${per.replace(/-/g, '')}@qa.inventario.local` };
}

/**
 * La contraseña que asigna IAM es temporal (debe cambiarse al entrar). Para las pruebas que parten
 * de una cuenta ya en uso, el titular la cambia por la API de autoservicio (/me/password).
 */
async function cuentaActiva(rol = 'CONSULTA'): Promise<Cuenta> {
  const c = await cuentaNueva(rol);
  const ctx = await contextoApi();
  const t = (await (await ctx.post('/api/v1/login/access-token', { form: { username: c.username, password: c.password } })).json()).access_token;
  const nueva = `Ac.${sufijo()}${sufijo()}#4`;
  const r = await ctx.post('/api/v1/me/password', { data: { current_password: c.password, new_password: nueva }, headers: { Authorization: `Bearer ${t}` } });
  expect(r.status(), await r.text()).toBe(204);
  return { ...c, password: nueva };
}

async function entrar(page: Page, username: string, password: string): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Usuario', { exact: true }).fill(username);
  await page.getByLabel('Contraseña', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'Iniciar sesión' }).click();
}

/** Cierre de sesión desde el menú de la cuenta (nombre de usuario al pie del menú lateral). */
async function salir(page: Page, username: string): Promise<void> {
  // En móvil hay dos copias del menú (la fija, oculta, y el cajón): se usa siempre la visible.
  const cuenta = page.getByText(username, { exact: true }).filter({ visible: true });
  const expandir = page.getByRole('button', { name: 'Expandir menú' }).filter({ visible: true });
  await expect(cuenta.or(expandir).first()).toBeVisible();
  if (!(await cuenta.count())) await expandir.first().click();
  await cuenta.first().click();
  await page.getByRole('menuitem', { name: 'Cerrar sesión', exact: true }).click();
  await expect(page).toHaveURL(/\/login/);
}

test('contraseña: entra, la sesión sobrevive a recargar (cookie de refresh segura) y el cierre la invalida', async ({ page, context }) => {
  await iniciarSesionUi(page, 'qa_tecnico_norte');
  await page.reload();
  await expect(page).not.toHaveURL(/\/login/);
  const refresh = (await context.cookies()).find((c) => c.name === 'rtk');
  expect(refresh, 'cookie de refresh').toBeTruthy();
  expect([refresh!.httpOnly, refresh!.secure, refresh!.sameSite]).toEqual([true, true, expect.stringMatching(/Strict|Lax/)]);
  // Ningún token en el almacenamiento del navegador (solo en memoria).
  const almacenado = await page.evaluate(() => JSON.stringify({ ...localStorage }) + JSON.stringify({ ...sessionStorage }));
  expect(almacenado).not.toMatch(/eyJ[A-Za-z0-9_-]{10,}\./);
  await salir(page, 'qa_tecnico_norte');
  const r = await page.request.post('/api/v1/login/refresh');
  expect(r.status(), 'el refresh tras cerrar sesión no sirve').toBe(401);
});

test('credenciales incorrectas: mensaje genérico; bloqueo tras 5 intentos; desbloqueo por IAM', async ({ page }) => {
  const c = await cuentaActiva();
  for (let i = 0; i < 5; i++) {
    await entrar(page, c.username, 'Incorrecta.123');
    await expect(page.getByText('Credenciales incorrectas. Por favor, inténtelo de nuevo.')).toBeVisible();
  }
  // Un usuario inexistente recibe el mismo mensaje (sin enumeración).
  await entrar(page, `no_existe_${sufijo()}`, 'Incorrecta.123');
  await expect(page.getByText('Credenciales incorrectas. Por favor, inténtelo de nuevo.')).toBeVisible();
  await entrar(page, c.username, c.password);
  await expect(page.getByText(/bloquead|locked/i)).toBeVisible();
  await expect(page).toHaveURL(/\/login/);
  expect((await iam.ctx.post(`/api/v1/org/usuarios/${c.id}/unlock`)).status()).toBe(200);
  await entrar(page, c.username, c.password);
  await expect(page).not.toHaveURL(/\/login/);
});

test('contraseña temporal asignada por IAM: cambio obligatorio antes de usar el sistema', async ({ page }) => {
  const c = await cuentaNueva();
  const r = await iam.ctx.post(`/api/v1/org/usuarios/${c.id}/password/reset`);
  expect(r.status()).toBe(200);
  expect(r.headers()['cache-control']).toContain('no-store');
  const temporal = (await r.json()).temporary_password as string;
  await entrar(page, c.username, temporal);
  await expect(page.getByText('Cambie su contraseña temporal')).toBeVisible();
  const nueva = `Nv.${sufijo()}${sufijo()}#5`;
  await page.getByLabel('Contraseña temporal').fill(temporal);
  await page.getByLabel('Nueva contraseña', { exact: true }).fill(nueva);
  await page.getByLabel('Confirmar nueva contraseña').fill(nueva);
  await page.getByRole('button', { name: 'Cambiar y continuar' }).click();
  await expect(page.getByText('Cambie su contraseña temporal')).toHaveCount(0, { timeout: 20_000 });
  await expect(page.getByRole('button', { name: /Expandir menú|Contraer menú/ })).toBeVisible();
  // La temporal ya no sirve.
  const ctx = page.request;
  expect((await ctx.post('/api/v1/login/access-token', { form: { username: c.username, password: temporal } })).status()).toBe(400);
});

test('recuperación por correo: enlace de Mailpit, nueva contraseña y entrada; el enlace no se reutiliza', async ({ page }) => {
  const c = await cuentaActiva();
  const desde = new Date();
  await page.goto('/login');
  await page.getByText('¿Olvidó su contraseña?').click();
  await page.getByLabel('Usuario o correo').fill(c.username);
  await page.getByRole('button', { name: 'Enviar enlace' }).click();
  await expect(page.getByText(/Si la cuenta existe/)).toBeVisible();
  const enlace = await esperarCorreo(c.correo, desde, /(https?:\/\/[^\s"<>]+reset-password#token=[A-Za-z0-9_-]+)/);
  const ruta = new URL(enlace).pathname + new URL(enlace).hash;
  await page.goto(ruta);
  const nueva = `Rc.${sufijo()}${sufijo()}#3`;
  await page.getByLabel('Nueva contraseña', { exact: true }).fill(nueva);
  await page.getByLabel('Confirmar nueva contraseña').fill(nueva);
  await page.getByRole('button', { name: 'Restablecer' }).click();
  await expect(page.getByText(/Contraseña actualizada/)).toBeVisible();
  await entrar(page, c.username, nueva);
  await expect(page).not.toHaveURL(/\/login/);
  const token = new URLSearchParams(new URL(enlace).hash.slice(1)).get('token');
  const otra = await page.request.post('/api/v1/login/password-reset/confirm', { data: { token, new_password: `Ot.${sufijo()}${sufijo()}#1` } });
  expect(otra.status()).toBeGreaterThanOrEqual(400);
});

test('enrolamiento TOTP obligatorio: se decodifica el QR, se activa, códigos de recuperación de un solo uso', async ({ page }) => {
  const c = await cuentaActiva('ADMIN_TI');
  await entrar(page, c.username, c.password);
  await expect(page.getByText('Configure la verificación en dos pasos')).toBeVisible();
  await page.getByText('App de autenticación').click();
  const qr = page.getByRole('img', { name: 'Código QR para configurar la verificación en dos pasos' });
  await expect(qr).toBeVisible();
  const textos = await leerQr(await qr.screenshot({ scale: 'device' }));
  expect(textos).toHaveLength(1);
  const uri = new URL(textos[0]);
  expect(uri.protocol).toBe('otpauth:');
  const secreto = uri.searchParams.get('secret')!;
  expect(secreto).toMatch(/^[A-Z2-7]{16,}$/);
  const activacion = page.waitForResponse((r) => r.url().includes('/me/2fa/totp/activate'));
  await page.getByLabel('Código de 6 dígitos').fill(totp(secreto));
  await page.getByRole('button', { name: 'Activar' }).click();
  const recuperacion = (await (await activacion).json()).recovery_codes as string[];
  expect(recuperacion.length).toBeGreaterThanOrEqual(8);
  await expect(page.getByText('Códigos de recuperación')).toBeVisible();
  for (const cod of recuperacion.slice(0, 2)) await expect(page.getByText(cod, { exact: true })).toBeVisible();

  // Nueva entrada con un código de recuperación: sirve una vez.
  const api = page.request;
  const reto = async () => (await (await api.post('/api/v1/login/access-token', { form: { username: c.username, password: c.password } })).json()).challenge_token;
  expect((await api.post('/api/v1/login/2fa/verify', { data: { challenge_token: await reto(), code: recuperacion[0] } })).status()).toBe(200);
  expect((await api.post('/api/v1/login/2fa/verify', { data: { challenge_token: await reto(), code: recuperacion[0] } })).status()).toBe(400);
  // Y con la aplicación (paso siguiente al usado en la activación).
  await page.waitForTimeout(31_000 - (Date.now() % 30_000));
  expect((await api.post('/api/v1/login/2fa/verify', { data: { challenge_token: await reto(), code: totp(secreto) } })).status()).toBe(200);
});

test('2FA por correo: código de Mailpit; un código incorrecto no entra', async ({ page }) => {
  const u = usuario('qa_auditor');
  await page.goto('/login');
  const desde = new Date();
  await page.getByLabel('Usuario', { exact: true }).fill(u.username);
  await page.getByLabel('Contraseña', { exact: true }).fill(u.password);
  await page.getByRole('button', { name: 'Iniciar sesión' }).click();
  const campo = page.getByLabel('Código de verificación');
  await expect(campo).toBeVisible();
  await campo.fill('000000');
  await campo.press('Enter');
  await expect(page.getByText('Código incorrecto o expirado.')).toBeVisible();
  const code = await esperarCorreo(u.correo, desde);
  await campo.fill(code);
  await campo.press('Enter');
  await expect(page).not.toHaveURL(/\/login/);
});

test('SSO: desactivado como en producción, sin botones de proveedores', async ({ page }) => {
  const r = await page.request.get('/api/v1/login/sso/providers');
  expect((await r.json()).enabled).toBe(false);
  await page.goto('/login');
  await expect(page.getByRole('button', { name: /Microsoft|Google/ })).toHaveCount(0);
});

test('MFA obligatorio: el administrador del sistema lo apaga y lo enciende desde Configuración', async ({ page }) => {
  await iniciarSesionUi(page, 'qa_superadmin');
  await page.goto('/config');
  const interruptor = page.getByRole('switch', { name: /Exigir segundo factor/ });
  await expect(interruptor).toBeChecked();

  await interruptor.click();
  await expect(page.getByText('Segundo factor obligatorio desactivado.')).toBeVisible();
  await expect(interruptor).not.toBeChecked();

  await interruptor.click();
  await expect(page.getByText('Segundo factor obligatorio activado.')).toBeVisible();
  await expect(interruptor).toBeChecked();
});
