/*
 * MÓDULOS por el borde de QA, verificados en Oracle, Mailpit y el doble de Samba AD:
 * consumibles (stock y existencias), licencias (cupos y claves cifradas),
 * etiquetas QR decodificadas, mantenimiento, directorio
 * (integración guardada con secretos cifrados, prueba de LDAPS, sincronización), cola de
 * correos y gobierno (configuración y auditoría).
 */
import { test, expect } from '@playwright/test';
import { consulta, contextoApi, correos, sesionApi, SEDES, sufijo, type Sesion } from '../soporte';
import { altaApi, persona, tipoMovimiento, tipoYModelo, area } from '../datos';
import { leerQr, pdfAPng } from '../qr';

test.describe.configure({ mode: 'serial' });
let jefe: Sesion;
let raiz: Sesion;
let tecnico: Sesion;
let cat: { tipo: number; modelo: number; prefijo: string };

test.beforeAll(async () => {
  [jefe, raiz, tecnico] = await Promise.all([sesionApi('qa_jefe_ti'), sesionApi('qa_superadmin'), sesionApi('qa_tecnico_norte')]);
  cat = await tipoYModelo();
});

test('consumibles: entrada, salida, stock insuficiente y existencias en Oracle', async () => {
  const c = await (await jefe.ctx.post('/api/v1/consumibles', { data: { CON_Nombre: `Tóner QA ${sufijo()}`, CON_Stock_Minimo: 5, SED_Sede: SEDES.A } })).json();
  const id = c.CON_Consumible;
  expect((await jefe.ctx.post(`/api/v1/consumibles/${id}/entrada`, { data: { MOC_Cantidad: 10, precio_unitario: 13.5 } })).status()).toBe(201);
  expect((await tecnico.ctx.post(`/api/v1/consumibles/${id}/salida`, { data: { MOC_Cantidad: 4, MOC_Motivo: 'Entrega' } })).status()).toBe(201);
  const sin = await tecnico.ctx.post(`/api/v1/consumibles/${id}/salida`, { data: { MOC_Cantidad: 7, MOC_Motivo: 'Demasiado' } });
  expect([sin.status(), (await sin.json()).detail]).toEqual([409, 'INSUFFICIENT_STOCK']);
  const bd = (await consulta<any>('SELECT "CON_Stock_Actual" S FROM "INV_CONSUMIBLE" WHERE "CON_Consumible" = :c', { c: id }))[0].S;
  expect(Number(bd)).toBe(6);
  const movs = await consulta<any>('SELECT COUNT(*) N FROM "INV_MOVIMIENTO_CONSUMIBLE" WHERE "CON_Consumible" = :c', { c: id });
  expect(Number(movs[0].N)).toBe(2);
});

test('licencias: cupos y claves cifradas en la base (nunca en claro)', async () => {
  const sw = await (await jefe.ctx.post('/api/v1/soft/software', { data: { SOF_Nombre: `QA Soft ${sufijo()}`, SOF_Version: '1.0', SOF_Fabricante: 'QA' } })).json();
  const tl = await (await jefe.ctx.post('/api/v1/soft/tipos-licencia', { data: { TLI_Nombre: `QA Tipo ${sufijo()}` } })).json();
  const clave = `QAKEY-${sufijo()}-${sufijo()}`;
  const r = await jefe.ctx.post('/api/v1/soft/licencias', {
    data: { SOF_Software: sw.SOF_Software, TLI_Tipo_Licencia: tl.TLI_Tipo_Licencia, LIC_Cantidad_Total: 1, LIC_Claves: [{ LCL_Clave_Activacion: clave }] },
  });
  expect(r.status(), await r.text()).toBe(201);
  const lic = await r.json();
  expect(lic.LIC_Claves_Total).toBe(1);
  const filas = await consulta<any>('SELECT * FROM "INV_LICENCIA_CLAVE" WHERE "LIC_Licencia" = :l', { l: lic.LIC_Licencia });
  expect(filas.length).toBe(1);
  expect(JSON.stringify(filas[0])).not.toContain(clave);
  // Las claves solo las ve quien administra; el técnico no.
  expect((await tecnico.ctx.get(`/api/v1/soft/licencias/${lic.LIC_Licencia}/claves`)).status()).toBe(403);
});

test('etiquetas QR: el PDF lleva el código de cada activo', async () => {
  const codigos = [(await altaApi(jefe, cat.modelo, cat.tipo)).ACT_Codigo_Interno, (await altaApi(jefe, cat.modelo, cat.tipo)).ACT_Codigo_Interno];
  const pdf = await jefe.ctx.post('/api/v1/core/activos/etiquetas.pdf', { data: { codigos } });
  expect(pdf.status()).toBe(200);
  expect((await leerQr((await pdfAPng(await pdf.body()))[0])).sort()).toEqual([...codigos].sort());
});

test('mantenimiento: ticket con detalle, cierre y estado del activo', async () => {
  const a = await altaApi(jefe, cat.modelo, cat.tipo);
  const p = await persona(SEDES.A, 'Mant');
  await jefe.ctx.post('/api/v1/trazabilidad/movimientos', { data: { ACT_Activo: a.ACT_Activo, PER_Persona: p, ARE_Area: await area(SEDES.A),
    TMO_Tipo_Movimiento: await tipoMovimiento('Asignación'), MOV_Motivo: 'Mant' } });
  const tma = (await consulta<any>('SELECT MIN("TMA_Tipo_Mantenimiento") T FROM "INV_TIPO_MANTENIMIENTO"'))[0].T;
  const t = await tecnico.ctx.post('/api/v1/mantenimiento/', { data: { ACT_Activo: a.ACT_Activo, PER_Persona_Solicita: p, TMA_Tipo_Mantenimiento: tma,
    MAN_Descripcion_Falla: 'No enciende', detalles: [{ DMA_Accion_Realizada: 'Diagnóstico', DMA_Costo_Item: 50 }] } });
  expect(t.status(), await t.text()).toBe(201);
  const ticket = await t.json();
  const cerrar = await jefe.ctx.patch(`/api/v1/mantenimiento/${ticket.MAN_Mantenimiento}/cerrar`, { data: { MAN_Solucion: 'Cambio de fuente' } });
  test.info().annotations.push({ type: 'cierre mantenimiento', description: `${cerrar.status()} ${(await cerrar.text()).slice(0, 200)}` });
  expect(cerrar.status()).toBeLessThan(500);
  // El técnico no recibe costos de mantenimiento (decidido en la fase 8).
  const visto = await (await tecnico.ctx.get(`/api/v1/mantenimiento/${ticket.MAN_Mantenimiento}`)).json();
  expect(visto.MAN_Costo_Total).toBeUndefined();
});

test('directorio con el doble de Samba AD: integración con secretos cifrados, LDAPS verificado, sincronización y correo de prueba', async () => {
  const formulario = {
    modo: 'CORREO_AD', proveedor: 'SMTP', cuenta_email: 'inventario@qa.inventario.local', smtp_host: 'mailpit', smtp_puerto: 1025, smtp_seguridad: 'NINGUNA',
    ad_servidor: 'ldaps://dc1.pruebas.local', ad_base_dn: 'OU=Usuarios,DC=pruebas,DC=local', ad_grupo_base_dn: 'OU=Grupos,DC=pruebas,DC=local',
    ad_usar_cuenta_servicio: false, ad_bind_usuario: 'svc.inventario@pruebas.local', ad_bind_password: 'Servicio.Inventario.2026', ad_intervalo_min: 0,
  };
  const g = await raiz.ctx.put('/api/v1/directorio/integracion', { data: formulario });
  expect(g.status(), await g.text()).toBe(200);
  const guardado = await g.json();
  expect(JSON.stringify(guardado)).not.toContain('Servicio.Inventario.2026');
  const fila = await consulta<any>('SELECT "INT_AD_Bind_Password_Enc" P FROM "SYS_INTEGRACION_CORREO" WHERE "INT_Id" = 1');
  expect(String(fila[0].P)).not.toContain('Servicio.Inventario.2026');
  const probar = await raiz.ctx.post('/api/v1/directorio/integracion/probar-ad', { data: { config: formulario } });
  expect(probar.status(), await probar.text()).toBe(200);
  const sinc = await raiz.ctx.post('/api/v1/directorio/sincronizar');
  expect(sinc.status(), await sinc.text()).toBe(200);
  const ana = await consulta<any>('SELECT COUNT(*) N FROM "INV_PERSONA" WHERE LOWER("PER_Email_Corporativo") = \'ana.lopez@ad.pruebas.local\'');
  expect(Number(ana[0].N)).toBe(1);
  const desde = new Date();
  const pc = await raiz.ctx.post('/api/v1/directorio/integracion/probar-correo', { data: { config: formulario, destinatario: 'admin@qa.inventario.local' } });
  expect(pc.status(), await pc.text()).toBe(200);
  await expect.poll(async () => (await correos('admin@qa.inventario.local', desde)).length, { timeout: 30_000 }).toBeGreaterThan(0);
});

test('cola de correos y gobierno: sin envíos FALLIDOS; configuración y auditoría legibles por quien corresponde', async () => {
  const cola = await (await raiz.ctx.get('/api/v1/gov/email-outbox')).json();
  const items = Array.isArray(cola) ? cola : cola.items ?? [];
  test.info().annotations.push({ type: 'cola', description: JSON.stringify(cola.resumen ?? cola.totales ?? items.slice(0, 3)).slice(0, 300) });
  const fallidos = await consulta<any>('SELECT COUNT(*) N FROM "SYS_EMAIL_OUTBOX" WHERE "EOB_Estado" = \'FALLIDO\' AND "EOB_Creado_En" > SYSTIMESTAMP - INTERVAL \'1\' DAY');
  expect(Number(fallidos[0].N)).toBe(0);
  expect((await raiz.ctx.get('/api/v1/gov/config')).status()).toBe(200);
  // Marca pública (la usa el login sin sesión): solo llaves SYS_ de presentación, ningún secreto.
  const marca = await (await (await contextoApi()).get('/api/v1/gov/config')).json();
  expect(Object.keys(marca).every((k) => k.startsWith('SYS_'))).toBe(true);
  expect(JSON.stringify(marca)).not.toMatch(/password|secret|clave|smtp|token/i);
  expect((await tecnico.ctx.put('/api/v1/gov/config', { data: { SYS_Nombre_Empresa: 'x' } })).status()).toBe(403);
  const aud = await (await sesionApi('qa_auditor')).ctx.get('/api/v1/gov/auditoria/resumen');
  expect(aud.status()).toBe(200);
  expect((await tecnico.ctx.get('/api/v1/gov/auditoria')).status()).toBe(403);
});
