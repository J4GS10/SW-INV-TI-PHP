// Revisión visual de solo lectura: inicio de sesión, tiempos de carga, tema oscuro, inglés, italiano,
// diálogos en móvil, tarjetas en móvil y aviso de cambios sin guardar. Bloquea toda escritura salvo el inicio de sesión.
// Uso: docker run --rm -e CLAVE -e BASE_URL -v "<salida>:/s" -v "<esta carpeta>:/h:ro" inventario-qa-e2e:latest node /h/revision_visual.cjs
// La clave no se versiona. Deja capturas y revision.json en la salida.
const { chromium } = require('/e2e/node_modules/playwright');
const fs = require('fs');
const axeSrc = fs.readFileSync('/e2e/node_modules/axe-core/axe.min.js', 'utf8');

const BASE = process.env.BASE_URL || 'https://localhost';
const CLAVE = process.env.CLAVE;
const out = { login: {}, tiempos: {}, temas: {}, idiomas: {}, movil: {}, dialogos: {}, guardia: {}, errores: [] };

const nuevo = async (b, { viewport = { width: 1400, height: 900 }, movil = false, locale = 'es', oscuro = false } = {}) => {
  const c = await b.newContext({ ignoreHTTPSErrors: true, viewport, isMobile: movil, hasTouch: movil, locale, colorScheme: oscuro ? 'dark' : 'light' });
  await c.route('**/api/**', (route) => {
    const q = route.request();
    if (q.method() === 'GET' || q.url().includes('/login/') || q.url().endsWith('/search')) return route.continue();
    return route.abort();
  });
  const p = await c.newPage();
  p.on('pageerror', (e) => out.errores.push(`js ${String(e).slice(0, 160)}`));
  p.on('response', (r) => { if (r.url().includes('/api/') && r.status() >= 500) out.errores.push(`${r.status()} ${r.url().replace(BASE, '')}`); });
  return { c, p };
};
const entrar = async (p, usuario) => {
  await p.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
  await p.locator('input[name=username], input[autocomplete=username], input[type=text]').first().fill(usuario);
  await p.locator('input[type=password]').first().fill(CLAVE);
  await p.keyboard.press('Enter');
  await p.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 20000 });
  await p.getByRole('navigation').first().waitFor({ timeout: 15000 }).catch(() => {});
  await p.waitForTimeout(1500);
  await p.waitForLoadState('networkidle').catch(() => {});
};
const ir = async (p, ruta) => {
  await p.evaluate((u) => { history.pushState({}, '', u); dispatchEvent(new PopStateEvent('popstate')); }, ruta);
  await p.waitForLoadState('networkidle').catch(() => {});
  await p.waitForTimeout(500);
};
const foto = (p, nombre) => p.screenshot({ path: `/s/${nombre}.png` });
const axe = async (p) => {
  await p.evaluate(axeSrc);
  return p.evaluate(async () => (await window.axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'] })).violations.map((v) => `${v.id}:${v.nodes.length}:${v.nodes[0].html.slice(0, 120)} :: ${(v.nodes[0].any[0]?.message || '').slice(0, 110)}`));
};

(async () => {
  const b = await chromium.launch();
  try {
    {
      const { c, p } = await nuevo(b);
      await p.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
      await foto(p, 'login_d');
      out.login.axe = await axe(p);
      await p.locator('input[type=text], input[name=username]').first().fill('usuario-que-no-existe');
      await p.locator('input[type=password]').first().fill('x');
      await p.keyboard.press('Enter');
      await p.waitForTimeout(1500);
      out.login.errorTexto = (await p.locator('[role=alert]').allInnerTexts()).join(' | ').slice(0, 200);
      await foto(p, 'login_error');
      await c.close();
    }
    {
      const { c, p } = await nuevo(b, { viewport: { width: 390, height: 844 }, movil: true });
      await p.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
      await foto(p, 'login_m');
      await c.close();
    }
    {
      const { c, p } = await nuevo(b);
      const t0 = Date.now();
      await entrar(p, 'tecnico');
      out.tiempos.entrar_ms = Date.now() - t0;
      const lentas = [];
      p.on('requestfinished', (q) => { if (q.url().includes('/api/')) { const f = q.timing(); lentas.push([Math.round(f.responseEnd), q.url().replace(BASE, '').split('?')[0]]); } });
      for (const ruta of ['/activos', '/operaciones/movimientos', '/trazabilidad', '/ingresos', '/ingresos/por-completar']) {
        const t = Date.now();
        await p.goto(`${BASE}${ruta}`, { waitUntil: 'networkidle' });
        out.tiempos[ruta] = Date.now() - t;
      }
      out.tiempos.api_mas_lentas = lentas.sort((a, x) => x[0] - a[0]).slice(0, 6);
      out.tiempos.transferido_kb = await p.evaluate(() => Math.round(performance.getEntriesByType('resource').reduce((a, e) => a + (e.transferSize || 0), 0) / 1024));
      await ir(p, '/ingresos/por-completar');
      await foto(p, 'pendientes_d');
      await c.close();
    }
    {
      const { c, p } = await nuevo(b, { oscuro: true });
      await entrar(p, 'auditor');
      for (const [ruta, n] of [['/inicio', 'inicio'], ['/activos', 'activos'], ['/contabilidad/cierre', 'cierre'], ['/trazabilidad', 'trazabilidad']]) {
        await ir(p, ruta);
        await foto(p, `oscuro_${n}`);
        out.temas[ruta] = await axe(p);
      }
      await c.close();
    }
    for (const idioma of ['en', 'it']) {
      const { c, p } = await nuevo(b, { locale: idioma });
      await entrar(p, 'auditor');
      await p.evaluate((l) => { localStorage.setItem('i18nextLng', l); }, idioma);
      await p.reload({ waitUntil: 'networkidle' });
      out.idiomas[idioma] = {};
      for (const ruta of ['/inicio', '/activos', '/operaciones/movimientos', '/mantenimiento', '/trazabilidad', '/contabilidad/cierre', '/ingresos']) {
        await ir(p, ruta);
        out.idiomas[idioma][ruta] = await p.evaluate(() => {
          const main = document.querySelector('main') || document.body;
          const txt = main.innerText;
          return {
            desborde: document.documentElement.scrollWidth - innerWidth,
            claves: (txt.match(/\b[a-z_]+\.[a-z_]+(\.[a-z_]+)*\b/g) || []).filter((x) => x.includes('_')).slice(0, 5),
            espanol: (txt.match(/\b(Buscar|Guardar|Cancelar|Exportar|Nuevo|Nueva|Sin serie|Activos|Fecha|Estado|Custodio|Movimiento|Ingreso)\b/g) || []).slice(0, 6),
          };
        });
        await foto(p, `${idioma}${ruta.replace(/\W+/g, '_')}`);
      }
      await c.close();
    }
    {
      const { c, p } = await nuevo(b, { viewport: { width: 390, height: 844 }, movil: true });
      await entrar(p, 'auditor');
      for (const ruta of ['/activos', '/operaciones/movimientos', '/aprobaciones', '/trazabilidad', '/ingresos', '/compras/proveedores', '/mantenimiento', '/contabilidad/cierre', '/auditoria']) {
        await ir(p, ruta);
        out.movil[ruta] = await p.evaluate(() => {
          const main = document.querySelector('main') || document.body;
          const anchas = [...main.querySelectorAll('table')].filter((t) => t.scrollWidth > (t.parentElement?.clientWidth ?? 0) + 4).length;
          const input = main.querySelector('input');
          return { desborde: document.documentElement.scrollWidth - innerWidth, tablasAnchas: anchas, alturaBuscador: input ? Math.round(input.getBoundingClientRect().top) : null };
        });
        await foto(p, `movil${ruta.replace(/\W+/g, '_')}`);
      }
      await c.close();
    }
    {
      const { c, p } = await nuevo(b, { viewport: { width: 390, height: 844 }, movil: true });
      await entrar(p, 'compras');
      await ir(p, '/compras/proveedores');
      const abrir = p.locator('main button').filter({ hasText: /^(nuevo|nueva|agregar|crear|registrar)/i }).first();
      if (await abrir.count()) {
        await abrir.click();
        await p.waitForTimeout(600);
        const d = p.getByRole('dialog').first();
        const caja = await d.boundingBox();
        out.dialogos.proveedor = { abre: !!caja, anchoPantalla: caja ? Math.round(caja.width) === 390 : false };
        await foto(p, 'movil_dialogo');
        await d.locator('input').first().fill('Texto de prueba');
        await p.keyboard.press('Escape');
        await p.waitForTimeout(400);
        out.guardia.avisoAlPulsarEsc = (await p.getByText(/descartar lo escrito/i).count()) > 0;
        await foto(p, 'guardia_aviso');
        await p.getByRole('button', { name: /seguir editando/i }).click();
        await p.waitForTimeout(300);
        out.guardia.conservaTexto = (await d.locator('input').first().inputValue()) === 'Texto de prueba';
      }
      await c.close();
    }
    {
      const { c, p } = await nuevo(b);
      await entrar(p, 'auditor');
      await ir(p, '/trazabilidad');
      await foto(p, 'fecha_traza');
      await ir(p, '/operaciones/movimientos');
      const encabezado = p.locator('th').getByRole('button', { name: /activo/i }).first();
      if (await encabezado.count()) {
        await encabezado.click();
        await p.waitForTimeout(300);
        out.orden = { ariaSort: await p.locator('th[aria-sort]').first().getAttribute('aria-sort').catch(() => null) };
      }
      out.movimientosAxe = await axe(p);
      await c.close();
    }
    {
      const { c, p } = await nuevo(b);
      await entrar(p, 'sysadmin');
      await ir(p, '/config');
      out.configAxe = await axe(p);
      await foto(p, 'config_d');
      await foto(p, 'movimientos_d');
      await c.close();
    }
  } finally {
    await b.close();
    fs.writeFileSync('/s/revision.json', JSON.stringify(out, null, 1));
  }
  console.log('listo');
})().catch((e) => { console.error(String(e).slice(0, 500)); process.exit(1); });
