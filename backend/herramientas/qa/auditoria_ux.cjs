// Auditoria UX/UI de solo lectura. Bloquea POST/PATCH/PUT/DELETE tras iniciar sesion, salvo busquedas y el cambio de clave
// inicial de la cuenta temporal `barridoti`, que activa al empezar y desactiva al terminar (su clave nueva no se guarda).
// Uso: mkdir ux; MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W):/s" -v "<esta carpeta>:/h:ro" -e CLAVE=<clave de las cuentas de prueba> \n//        --entrypoint node inventario-qa-e2e:latest /h/auditoria_ux.cjs
// Deja audit.json y capturas en ./ux. La clave no se versiona.
const { chromium, request } = require('/e2e/node_modules/playwright');
const fs = require('fs');
let axe = null;
for (const c of ['/e2e/node_modules/axe-core/axe.min.js']) if (fs.existsSync(c)) axe = fs.readFileSync(c, 'utf8');
const BASE = process.env.BASE_URL || 'https://localhost';
const ROLES = ['barridoti', 'tecnico', 'compras', 'auditor', 'consulta', 'seguridad', 'sysadmin'];
const PATHS = ['/inicio', '/panel', '/activos', '/ingresos/por-completar', '/scanner', '/operaciones/movimientos', '/operaciones/alta-empleado', '/mantenimiento', '/consumibles', '/trazabilidad', '/inventario/tomas', '/aprobaciones', '/contabilidad/cierre', '/auditoria', '/ingresos', '/ingresos/nuevo', '/compras/stock', '/compras/proveedores', '/compras/garantias', '/software', '/empleados', '/departamentos', '/usuarios', '/config/importar', '/config/catalogos', '/config/directorio', '/config'];
const RUTAS = {"tecnico":["/inicio","/panel","/activos","/ingresos/por-completar","/scanner","/operaciones/movimientos","/mantenimiento","/consumibles","/trazabilidad","/inventario/tomas","/ingresos"],"compras":["/inicio","/contabilidad/cierre","/ingresos","/ingresos/nuevo","/compras/stock","/compras/proveedores"],"auditor":["/inicio","/panel","/activos","/scanner","/operaciones/movimientos","/mantenimiento","/consumibles","/trazabilidad","/inventario/tomas","/aprobaciones","/contabilidad/cierre","/auditoria","/ingresos","/compras/stock","/compras/proveedores","/compras/garantias","/software","/empleados","/departamentos"],"consulta":["/inicio","/panel","/activos","/scanner","/operaciones/movimientos","/mantenimiento","/consumibles","/trazabilidad","/inventario/tomas","/ingresos"],"seguridad":["/auditoria","/usuarios"],"sysadmin":["/auditoria","/usuarios","/config/directorio","/config"]};
const VIEWS = { d: { width: 1400, height: 900 }, m: { width: 390, height: 844 } };
const nav = (p, x) => p.evaluate((u) => { history.pushState({}, '', u); dispatchEvent(new PopStateEvent('popstate')); }, x);
const metrics = () => {
  const main = document.querySelector('main') || document.body;
  const vis = (e) => { const r = e.getBoundingClientRect(); const s = getComputedStyle(e); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none'; };
  const interact = [...document.querySelectorAll('button,a[href],input:not([type=hidden]),[role=button],[role=tab],[role=checkbox],[role=switch]')].filter(vis);
  const small = interact.filter((e) => { const r = e.getBoundingClientRect(); return (r.width < 24 || r.height < 24) && e.tagName !== 'INPUT'; });
  const unnamed = interact.filter((e) => !(e.innerText || '').trim() && !e.getAttribute('aria-label') && !e.getAttribute('title') && !e.getAttribute('aria-labelledby') && !(e.labels && e.labels.length) && !e.getAttribute('placeholder'));
  const trunc = [...main.querySelectorAll('*')].filter((e) => e.children.length === 0 && vis(e) && e.scrollWidth > e.clientWidth + 1 && getComputedStyle(e).overflow === 'hidden' && (e.innerText || '').trim()).map((e) => e.innerText.trim().slice(0, 50));
  const tables = [...main.querySelectorAll('table')].filter(vis).map((t) => ({ tabla: Math.round(t.scrollWidth), visible: Math.round(t.parentElement.clientWidth) }));
  const mainBtns = [...main.querySelectorAll('button')].filter(vis);
  return {
    h1: [...main.querySelectorAll('h1')].filter(vis).map((e) => e.innerText.trim()),
    desborde: document.documentElement.scrollWidth - innerWidth,
    alto: document.documentElement.scrollHeight,
    filas: main.querySelectorAll('tbody tr').length,
    tablas: tables,
    alertas: [...main.querySelectorAll('[role=alert]')].filter(vis).map((e) => e.innerText.trim().slice(0, 140)),
    chicos: small.length,
    sinNombre: unnamed.length,
    sinNombreEj: unnamed.slice(0, 4).map((e) => e.tagName + '.' + (e.className || '').toString().split(' ').filter((c) => !c.startsWith('css-')).slice(0, 2).join('.')),
    truncados: [...new Set(trunc)].slice(0, 8),
    botones: mainBtns.filter((e) => (e.innerText || '').trim()).map((e) => (e.disabled ? '[off] ' : '') + e.innerText.trim().slice(0, 36)).slice(0, 18),
    apagados: mainBtns.filter((e) => e.disabled).length,
    campos: main.querySelectorAll('input:not([type=hidden]),textarea,[role=combobox]').length,
    pestanas: [...main.querySelectorAll('[role=tab]')].filter(vis).map((e) => e.innerText.trim().slice(0, 28)),
    vacio: [...main.querySelectorAll('p,td,h6')].filter((e) => vis(e) && /^(sin |no hay|aún no|ningún|todavía)/i.test((e.innerText || '').trim())).map((e) => e.innerText.trim().slice(0, 110)).slice(0, 4),
    ingles: (main.innerText.match(/\b(Rows per page|No options|Loading|Search|Save|Cancel|Delete|Close|of \d+)\b/g) || []).slice(0, 5),
    claves: (main.innerText.match(/\b[a-z_]+\.[a-z_]+(\.[a-z_]+)*\b/g) || []).filter((x) => x.includes('_')).slice(0, 5),
  };
};
(async () => {
  const api = await request.newContext({ ignoreHTTPSErrors: true });
  const API = BASE + '/api/v1';
  const sa = (await (await api.post(API + '/login/access-token', { form: { username: 'sysadmin', password: process.env.CLAVE } })).json()).access_token;
  const h = { Authorization: 'Bearer ' + sa };
  const cuenta = (await (await api.get(API + '/org/usuarios', { headers: h })).json()).find((x) => x.USU_Username === 'barridoti');
  const cid = cuenta.USU_Usuario;
  console.log('activar', (await api.patch(`${API}/org/usuarios/${cid}`, { headers: h, data: { USU_Estado: 1 } })).status());
  const temp = (await (await api.post(`${API}/org/usuarios/${cid}/password/reset`, { headers: h })).json()).temporary_password;
  const nueva = 'Vr-' + require('crypto').randomBytes(12).toString('base64url') + '9a!';
  let claveTi = temp;
  const b = await chromium.launch();
  try {
  const out = { axe: !!axe, bloqueadas: [], roles: {} };
  for (const u of ROLES) {
    const r = (out.roles[u] = { paginas: {}, errores: [], js: [] });
    for (const [vk, vp] of Object.entries(VIEWS)) {
      const c = await b.newContext({ ignoreHTTPSErrors: true, viewport: vp, locale: 'es', isMobile: vk === 'm', hasTouch: vk === 'm' });
      const p = await c.newPage();
      let actual = 'login';
      await c.route('**/api/**', (route) => {
        const q = route.request();
        if (q.method() === 'GET' || q.url().includes('/login/') || q.url().endsWith('/search') || (u === 'barridoti' && q.url().includes('/password'))) return route.continue();
        out.bloqueadas.push(`${u} ${q.method()} ${q.url().replace(BASE, '').split('?')[0]} @${actual}`);
        return route.abort();
      });
      p.on('response', (x) => { if (x.url().includes('/api/') && x.status() >= 400) r.errores.push(`${x.status()} ${x.url().replace(BASE, '').split('?')[0]} @${actual}`); });
      p.on('pageerror', (e) => r.js.push(String(e).slice(0, 140) + ' @' + actual));
      p.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource|ERR_FAILED/.test(m.text())) r.js.push('console: ' + m.text().slice(0, 140) + ' @' + actual); });
      await p.goto(BASE + '/login');
      if (u === 'barridoti') await p.screenshot({ path: `/s/ux/login_${vk}.png` });
      await p.getByLabel('Usuario', { exact: true }).fill(u);
      await p.getByLabel('Contraseña', { exact: true }).fill(u === 'barridoti' ? claveTi : process.env.CLAVE);
      await p.getByRole('button', { name: 'Iniciar sesión' }).click();
      try { await p.waitForFunction(() => !location.pathname.includes('/login'), null, { timeout: 12000 }); }
      catch { r.sinAcceso = (await p.locator('[role=alert]').allInnerTexts()).join(' | ') || 'sin mensaje'; await c.close(); break; }
      await p.waitForLoadState('networkidle').catch(() => {});
      await p.waitForTimeout(500);
      if (u === 'barridoti' && claveTi === temp) {
        const pw = p.locator('input[type=password]'); const n = await pw.count();
        if (n >= 3) { await pw.nth(0).fill(temp); await pw.nth(1).fill(nueva); await pw.nth(2).fill(nueva); } else if (n === 2) { await pw.nth(0).fill(nueva); await pw.nth(1).fill(nueva); }
        if (n >= 2) { await p.screenshot({ path: '/s/ux/cambio_clave.png' }); await p.getByRole('button', { name: /guardar|cambiar|continuar/i }).first().click(); await p.waitForTimeout(2500); claveTi = nueva; }
      }
      r.inicio = new URL(p.url()).pathname;
      if (/password|mfa/i.test(r.inicio)) { r.sinAcceso = 'pide ' + r.inicio; await c.close(); break; }
      if (vk === 'd') r.menu = (await p.locator('nav a, aside a').evaluateAll((a) => a.map((x) => x.getAttribute('href')))).filter(Boolean);
      const lista = u === 'barridoti' ? PATHS : RUTAS[u];
      for (const path of lista) {
        actual = path;
        const t0 = Date.now();
        await nav(p, path);
        await p.waitForTimeout(150);
        await p.waitForFunction(() => !document.querySelector('main [role=progressbar], main .MuiSkeleton-root'), null, { timeout: 15000 }).catch(() => {});
        const ms = Date.now() - t0;
        await p.waitForTimeout(900);
        if (new URL(p.url()).pathname !== path) continue;
        const guia = p.getByRole('button', { name: 'No mostrar más guías' });
        const guiaVisible = await guia.count();
        if (guiaVisible) {
          if (u === 'barridoti' && vk === 'd') await p.screenshot({ path: '/s/ux/guia.png' });
          await guia.click().catch(() => {});
          await p.waitForTimeout(250);
        }
        const m = await p.evaluate(metrics);
        m.ms = ms; m.guia = guiaVisible > 0;
        if (axe && vk === 'd' && true) {
          await p.evaluate(axe);
          m.axe = await p.evaluate(async () => {
            const a = await window.axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] });
            return a.violations.map((v) => `${v.id}[${v.impact}]x${v.nodes.length}: ${v.nodes[0].target.join(' ').slice(0, 70)}`);
          });
        }
        r.paginas[path + ':' + vk] = m;
        const n = path.replace(/\W+/g, '_');
        if (u === 'barridoti' || (u === 'compras' && ['/inicio', '/ingresos/nuevo', '/compras/stock'].includes(path)) || (u === 'tecnico' && path === '/inicio') || (u === 'sysadmin' && ['/config', '/usuarios'].includes(path)) || (u === 'auditor' && path === '/auditoria')) await p.screenshot({ path: `/s/ux/${u}${n}_${vk}.png` });
      }
      if (u === 'barridoti') {
        actual = '/activos/ficha';
        await nav(p, '/activos');
        await p.waitForLoadState('networkidle').catch(() => {});
        await p.waitForTimeout(500);
        const f = p.locator('main tbody tr').first();
        if (await f.count()) {
          await f.click({ force: true }).catch(() => {});
          await p.waitForTimeout(1200);
          await p.screenshot({ path: `/s/ux/ficha_${vk}.png` });
          r.ficha = r.ficha || {};
          r.ficha[vk] = await p.evaluate(metrics);
          await p.keyboard.press('Escape');
        }
        if (vk === 'd') {
          for (const path of ['/activos', '/empleados', '/compras/proveedores', '/mantenimiento', '/consumibles', '/inventario/tomas', '/software', '/operaciones/movimientos']) {
            actual = path + ' dialogo';
            await nav(p, path);
            await p.waitForLoadState('networkidle').catch(() => {});
            await p.waitForTimeout(400);
            const btn = p.locator('main button:not([disabled])').filter({ hasText: /^(nuevo|nueva|agregar|crear|registrar)/i }).first();
            if (!(await btn.count())) continue;
            const txt = await btn.innerText();
            await btn.click().catch(() => {});
            await p.waitForTimeout(700);
            const dlg = p.getByRole('dialog');
            r.dialogos = r.dialogos || {};
            r.dialogos[path] = { boton: txt, abre: await dlg.count(), ruta: new URL(p.url()).pathname, obligatorios: await dlg.locator('label:has(.MuiFormLabel-asterisk)').count(), campos: await dlg.locator('input:not([type=hidden]),textarea,[role=combobox]').count() };
            await p.screenshot({ path: `/s/ux/dlg${path.replace(/\W+/g, '_')}.png` });
            await p.keyboard.press('Escape');
            await p.waitForTimeout(250);
          }
        } else {
          const m = p.getByRole('button', { name: /menú|menu|abrir/i }).first();
          if (await m.count()) { await m.click().catch(() => {}); await p.waitForTimeout(500); await p.screenshot({ path: '/s/ux/menu_m.png' }); await p.keyboard.press('Escape'); }
        }
      }
      await c.close();
    }
    fs.writeFileSync('/s/ux/audit.json', JSON.stringify(out, null, 1));
    console.log(u, Object.keys(r.paginas).length, r.sinAcceso || '');
  }
  } finally {
    await b.close();
    console.log('desactivar', (await api.patch(`${API}/org/usuarios/${cid}`, { headers: h, data: { USU_Estado: 0 } })).status());
  }
  console.log('listo');
})().catch((e) => { console.error(String(e).slice(0, 600)); process.exit(1); });
