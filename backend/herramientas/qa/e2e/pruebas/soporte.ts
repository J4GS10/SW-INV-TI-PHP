/*
 * Soporte de las pruebas de la fase 7: usuarios sembrados, TOTP, Mailpit, sesión por
 * API y por la interfaz, y verificaciones directas en la Oracle de QA.
 */
import { createHmac, randomBytes } from 'node:crypto';
import { mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { expect, type APIRequestContext, type Page, request as pwRequest } from '@playwright/test';
import oracledb from 'oracledb';

export interface UsuarioQa {
  id: string;
  rol: string;
  global: boolean;
  sedes: number[];
  password: string;
  correo: string;
  metodo_2fa: 'TOTP' | 'EMAIL' | null;
  secreto_totp: string | null;
  debe_cambiar: boolean;
}

const siembra = JSON.parse(readFileSync(process.env.QA_USUARIOS ?? '/qa/usuarios.json', 'utf8')) as {
  sedes: { A: number; B: number };
  usuarios: Record<string, UsuarioQa>;
};
export const SEDES = siembra.sedes;
export const URL_QA = process.env.QA_URL ?? 'https://qa.inventario.local';
export const API = `${URL_QA}/api/v1`;
const MAILPIT = process.env.QA_MAILPIT ?? 'http://mailpit:8025';

export function usuario(nombre: string): UsuarioQa & { username: string } {
  const u = siembra.usuarios[nombre];
  if (!u) throw new Error(`Usuario de QA no sembrado: ${nombre}`);
  return { ...u, username: nombre };
}

/** Sufijo único para datos creados por una prueba. */
export const sufijo = (): string => randomBytes(4).toString('hex');

// --- TOTP (RFC 6238, SHA-1, 30 s, 6 dígitos), igual que App\Seguridad\Totp ---
function base32(texto: string): Buffer {
  const alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const c of texto.replace(/=+$/, '').toUpperCase()) {
    const v = alfabeto.indexOf(c);
    if (v < 0) throw new Error('Secreto TOTP inválido');
    bits += v.toString(2).padStart(5, '0');
  }
  const bytes: number[] = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
  return Buffer.from(bytes);
}

export function totp(secreto: string, momento = Date.now(), desfase = 0): string {
  const paso = Math.floor(momento / 1000 / 30) + desfase;
  const contador = Buffer.alloc(8);
  contador.writeBigUInt64BE(BigInt(paso));
  const h = createHmac('sha1', base32(secreto)).update(contador).digest();
  const o = h[h.length - 1] & 0x0f;
  const n = (h.readUInt32BE(o) & 0x7fffffff) % 1_000_000;
  return n.toString().padStart(6, '0');
}

/**
 * El servidor rechaza reutilizar un paso TOTP ya usado (USU_2FA_Ultimo_Paso). Los workers de
 * Playwright son procesos distintos: cada paso se reserva creando un archivo de forma
 * exclusiva (`wx`); si otro ya lo tomó, se espera al paso siguiente.
 */
export async function totpNuevo(secreto: string, usuarioId?: string): Promise<string> {
  const huella = createHmac('sha1', 'qa').update(secreto).digest('hex').slice(0, 12);
  // Paso ya usado en corridas anteriores (otro contenedor): lo guarda el servidor.
  const usado = usuarioId
    ? Number((await consulta<any>('SELECT NVL("USU_2FA_Ultimo_Paso", -1) P FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', { u: usuarioId }))[0]?.P ?? -1)
    : -1;
  for (;;) {
    const ahora = Date.now();
    const paso = Math.floor(ahora / 30000);
    // Con menos de 3 s de vida, el código podría caducar en tránsito: se espera al siguiente.
    if (ahora % 30000 < 27000 && paso > usado) {
      try {
        writeFileSync(`/tmp/totp-${huella}-${paso}`, '', { flag: 'wx' });
        return totp(secreto, ahora);
      } catch { /* paso ya usado por otro worker */ }
    }
    await new Promise((r) => setTimeout(r, 1000));
  }
}

/** Cerrojo entre workers (mkdir es atómico): un solo inicio de sesión con 2FA por usuario a la vez. */
export async function conCerrojo<T>(nombre: string, fn: () => Promise<T>): Promise<T> {
  const dir = `/tmp/cerrojo-${nombre}`;
  for (let i = 0; ; i++) {
    try { mkdirSync(dir); break; } catch {
      if (i > 600) throw new Error(`Cerrojo ${nombre} ocupado`);
      await new Promise((r) => setTimeout(r, 500));
    }
  }
  try { return await fn(); } finally { rmSync(dir, { recursive: true, force: true }); }
}

// --- Mailpit ---
interface MensajeMailpit { ID: string; Subject: string; Created: string; To: { Address: string }[] }

export async function correos(destino: string, desde?: Date): Promise<MensajeMailpit[]> {
  const r = await fetch(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(`to:"${destino}"`)}&limit=50`);
  const datos = (await r.json()) as { messages: MensajeMailpit[] };
  return datos.messages.filter((m) => !desde || new Date(m.Created) >= desde);
}

export async function textoCorreo(id: string): Promise<{ texto: string; html: string; asunto: string; cabeceras: Record<string, string[]> }> {
  const m = (await (await fetch(`${MAILPIT}/api/v1/message/${id}`)).json()) as { Text: string; HTML: string; Subject: string };
  const cab = (await (await fetch(`${MAILPIT}/api/v1/message/${id}/headers`)).json()) as Record<string, string[]>;
  return { texto: m.Text, html: m.HTML, asunto: m.Subject, cabeceras: cab };
}

/** Espera un correo nuevo para `destino` y devuelve el primer código de 6 dígitos o el texto. */
export async function esperarCorreo(destino: string, desde: Date, patron: RegExp = /\b(\d{6})\b/): Promise<string> {
  for (let i = 0; i < 60; i++) {
    const lista = await correos(destino, desde);
    for (const m of lista) {
      const { texto, html } = await textoCorreo(m.ID);
      const encontrado = `${texto}
${html}`.match(patron);
      if (encontrado) return encontrado[1] ?? encontrado[0];
    }
    await new Promise((r) => setTimeout(r, 1000));
  }
  throw new Error(`No llegó a Mailpit un correo para ${destino}`);
}

// --- Sesión por API ---
export interface Sesion { token: string; ctx: APIRequestContext; usuario: UsuarioQa & { username: string } }

export async function contextoApi(): Promise<APIRequestContext> {
  return pwRequest.newContext({ baseURL: URL_QA, ignoreHTTPSErrors: true });
}

export async function sesionApi(nombre: string): Promise<Sesion> {
  const u = usuario(nombre);
  const ctx = await contextoApi();
  const token = await conCerrojo(nombre, async () => {
    const desde = new Date();
    const r = await ctx.post('/api/v1/login/access-token', { form: { username: u.username, password: u.password } });
    expect(r.status(), `login de ${nombre}`).toBe(200);
    let cuerpo = (await r.json()) as { access_token?: string; requires_2fa?: boolean; challenge_token?: string; method?: string };
    if (cuerpo.requires_2fa) {
      const code = cuerpo.method === 'TOTP' ? await totpNuevo(u.secreto_totp!, u.id) : await esperarCorreo(u.correo, desde);
      const v = await ctx.post('/api/v1/login/2fa/verify', { data: { challenge_token: cuerpo.challenge_token, code } });
      expect(v.status(), `2FA de ${nombre}`).toBe(200);
      cuerpo = await v.json();
    }
    return cuerpo.access_token!;
  });
  const conToken = await pwRequest.newContext({
    baseURL: URL_QA, ignoreHTTPSErrors: true, extraHTTPHeaders: { Authorization: `Bearer ${token}` },
  });
  return { token, ctx: conToken, usuario: u };
}

// --- Sesión por la interfaz ---
export async function iniciarSesionUi(page: Page, nombre: string): Promise<void> {
  const u = usuario(nombre);
  await page.goto('/login');
  await conCerrojo(nombre, async () => {
    const desde = new Date();
    await page.getByLabel('Usuario', { exact: true }).fill(u.username);
    await page.getByLabel('Contraseña', { exact: true }).fill(u.password);
    await page.getByRole('button', { name: 'Iniciar sesión' }).click();
    if (u.metodo_2fa) {
      const campo = page.getByLabel('Código de verificación');
      await expect(campo).toBeVisible();
      const code = u.metodo_2fa === 'TOTP' ? await totpNuevo(u.secreto_totp!, u.id) : await esperarCorreo(u.correo, desde);
      await campo.fill(code);
      await campo.press('Enter');
    }
    await expect(page).not.toHaveURL(/\/login/);
    // Termina la navegación posterior al login (WebKit interrumpe un goto inmediato).
    await page.waitForLoadState('networkidle');
  });
}

// --- Oracle de QA (verificaciones) ---
oracledb.fetchAsString = [oracledb.CLOB];
/** Conexión directa; con `usuarioCtx` fija el contexto VPD como lo hace la API (INV_CTX.fijar_usuario). */
async function conexion(como: 'dueno' | 'app', usuarioCtx?: string): Promise<oracledb.Connection> {
  const cx = await oracledb.getConnection({
    user: como === 'dueno' ? 'INVENTARIO' : 'INVENTARIO_APP',
    password: como === 'dueno' ? process.env.QA_ORACLE_DUENO_CLAVE : process.env.QA_ORACLE_APP_CLAVE,
    connectString: process.env.QA_ORACLE ?? 'oracle:1521/XEPDB1',
  });
  if (como === 'app') await cx.execute('ALTER SESSION SET CURRENT_SCHEMA = "INVENTARIO"');
  if (usuarioCtx) await cx.execute('BEGIN INV_CTX.fijar_usuario(:u); END;', { u: usuarioCtx });
  return cx;
}

export async function consulta<T = Record<string, unknown>>(sql: string, binds: Record<string, unknown> | unknown[] = [],
  como: 'dueno' | 'app' = 'dueno', usuarioCtx?: string): Promise<T[]> {
  const cx = await conexion(como, usuarioCtx);
  try {
    const r = await cx.execute<T>(sql, binds as oracledb.BindParameters, { outFormat: oracledb.OUT_FORMAT_OBJECT });
    return (r.rows ?? []) as T[];
  } finally {
    await cx.close();
  }
}

export async function ejecutar(sql: string, binds: Record<string, unknown> | unknown[] = [], como: 'dueno' | 'app' = 'dueno',
  usuarioCtx?: string): Promise<number> {
  const cx = await conexion(como, usuarioCtx);
  try {
    const r = await cx.execute(sql, binds as oracledb.BindParameters, { autoCommit: true });
    return r.rowsAffected ?? 0;
  } finally {
    await cx.close();
  }
}
