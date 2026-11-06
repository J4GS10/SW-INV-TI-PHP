/*
 * LÓGICA de negocio de punta a punta por el borde de QA (Caddy -> api_php -> Oracle), cada
 * regla verificada en la respuesta de la API Y en la base: ciclo de vida y máquina de
 * estados, kardex (sellado, cadena e inmutabilidad), aprobaciones con separación de
 * funciones, alcance por sede (aplicación y VPD), costos ocultos, idempotencia, doble envío,
 * concurrencia, fechas en UTC, cierre mensual y consistencia API = exporte = base.
 */
import { test, expect } from '@playwright/test';
import { consulta, ejecutar, sesionApi, SEDES, sufijo, usuario, type Sesion } from '../soporte';
import { area, estado, hex, kardexDe, persona, tipoMovimiento, tipoYModelo } from '../datos';

test.describe.configure({ mode: 'serial' });

let admin: Sesion; // ADMIN_TI de la sede Norte (no global, 2FA TOTP)
let jefe: Sesion; // JEFE_TI global (aprueba)
let tecnicoSur: Sesion; // TECNICO de la sede Sur
let tecnicoNorte: Sesion;
let auditor: Sesion;
let contabilidad: Sesion;
let cat: { tipo: number; modelo: number; prefijo: string };
let areaNorte: number;
let ana: string;
let beto: string;

test.beforeAll(async () => {
  [admin, jefe, tecnicoSur, tecnicoNorte, auditor, contabilidad] = await Promise.all([
    sesionApi('qa_admin_ti_norte'), sesionApi('qa_jefe_ti'), sesionApi('qa_tecnico_sur'), sesionApi('qa_tecnico_norte'),
    sesionApi('qa_auditor'), sesionApi('qa_contabilidad'),
  ]);
  cat = await tipoYModelo();
  areaNorte = await area(SEDES.A);
  ana = await persona(SEDES.A, 'Ana');
  beto = await persona(SEDES.A, 'Beto');
});

async function alta(s: Sesion, extra: Record<string, unknown> = {}): Promise<Record<string, any>> {
  const r = await s.ctx.post('/api/v1/core/activos', {
    data: {
      ACT_Serie_Fabricante: `QA-${sufijo()}`, ACT_Fecha_Compra: '2026-10-01', ACT_Costo: 1234.56, MOD_Modelo: cat.modelo,
      TAC_Tipo_Activo: cat.tipo, EOP_Estado_Operativo: await estado('En Bodega'), SED_Sede: SEDES.A, ...extra,
    },
  });
  expect(r.status(), await r.text()).toBe(201);
  return r.json();
}

const detalle = async (r: { status(): number; json(): Promise<any> }): Promise<[number, string]> => [r.status(), (await r.json()).detail];

async function asignar(s: Sesion, activo: string, p: string, areaId = areaNorte, motivo = 'Ingreso QA') {
  return s.ctx.post('/api/v1/trazabilidad/movimientos', {
    data: { ACT_Activo: activo, PER_Persona: p, ARE_Area: areaId, TMO_Tipo_Movimiento: await tipoMovimiento('Asignación'), MOV_Motivo: motivo },
  });
}

async function estadoEnBd(activo: string): Promise<string> {
  return (await consulta<any>('SELECT e."EOP_Nombre" N FROM "INV_ACTIVO" a JOIN "INV_ESTADO_OPERATIVO" e '
    + 'ON e."EOP_Estado_Operativo" = a."EOP_Estado_Operativo" WHERE a."ACT_Activo" = :a', { a: hex(activo) }))[0].N;
}

test('ciclo de vida: alta, asignación, transferencia, devolución; kardex sellado y encadenado en Oracle', async () => {
  const a = await alta(admin);
  expect(a.ACT_Codigo_Interno).toMatch(new RegExp(`^${cat.prefijo}\\d{5}$`));
  const fila = (await consulta<any>('SELECT "ACT_Codigo_Interno" C, "ACT_Costo" COSTO, "SED_Sede" SEDE FROM "INV_ACTIVO" WHERE "ACT_Activo" = :a',
    { a: hex(a.ACT_Activo) }))[0];
  expect([fila.C, Number(fila.COSTO), fila.SEDE]).toEqual([a.ACT_Codigo_Interno, 1234.56, SEDES.A]);

  expect((await asignar(admin, a.ACT_Activo, ana)).status()).toBe(201);
  // Regla: un activo asignado no se reasigna; se transfiere.
  expect(await detalle(await asignar(admin, a.ACT_Activo, beto))).toEqual([409, 'ASSET_ALREADY_ASSIGNED_USE_TRANSFER']);
  expect((await admin.ctx.post('/api/v1/trazabilidad/transferencia', {
    data: { ACT_Activo: a.ACT_Activo, PER_Persona_Destino: beto, ARE_Area_Destino: areaNorte, MOV_Motivo: 'Cambio de puesto' },
  })).status()).toBe(201);
  const dev = await admin.ctx.post('/api/v1/trazabilidad/devolucion', { data: { ACT_Activo: a.ACT_Activo, MOV_Motivo: 'Renuncia', condicion: 'BUENO' } });
  expect(dev.status(), await dev.text()).toBe(200);

  // Estado en la API = estado en la base.
  const api = await (await admin.ctx.get(`/api/v1/core/activos/${a.ACT_Activo}`)).json();
  expect(api.estado_operativo.EOP_Nombre).toBe(await estadoEnBd(a.ACT_Activo));
  const abiertos = await consulta<any>('SELECT COUNT(*) N FROM "INV_MOVIMIENTO" WHERE "ACT_Activo" = :a AND "MOV_Fecha_Devolucion" IS NULL', { a: hex(a.ACT_Activo) });
  expect(Number(abiertos[0].N)).toBe(0);

  const k = await kardexDe(a.ACT_Activo);
  expect(k.map((e) => e.KDX_TIPO)).toEqual(['ALTA', 'ASIGNACION', 'TRANSFERENCIA', 'DEVOLUCION']);
  expect(k.every((e) => /^[0-9a-f]{64}$/i.test(e.KDX_HASH))).toBe(true);
  expect(new Set(k.map((e) => e.KDX_HASH)).size).toBe(4);
  const v = await (await jefe.ctx.get('/api/v1/inventario/kardex/verificar')).json();
  expect(v.integro, JSON.stringify(v.problemas)).toBe(true);
});

test('kardex inmutable: Oracle rechaza UPDATE/DELETE del dueño y del usuario de ejecución; la cadena sigue íntegra', async () => {
  const a = await alta(admin);
  for (const como of ['dueno', 'app'] as const) {
    await expect(ejecutar('UPDATE "INV_KARDEX" SET "KDX_Motivo" = \'alterado\' WHERE "ACT_Activo" = :a', { a: hex(a.ACT_Activo) }, como)).rejects.toThrow(/ORA-/);
    await expect(ejecutar('DELETE FROM "INV_KARDEX" WHERE "ACT_Activo" = :a', { a: hex(a.ACT_Activo) }, como)).rejects.toThrow(/ORA-/);
  }
  await expect(ejecutar('INSERT INTO "INV_KARDEX" ("ACT_Activo", "KDX_Tipo") VALUES (:a, \'ALTA\')', { a: hex(a.ACT_Activo) }, 'app')).rejects.toThrow(/ORA-/);
  const v = await (await auditor.ctx.get('/api/v1/inventario/kardex/verificar')).json();
  expect(v.integro).toBe(true);
});

test('VPD con el usuario de ejecución: con un usuario no global solo ve su sede; no puede tocar políticas, disparadores ni el contexto', async () => {
  // Sin usuario fijado el VPD está en «modo sistema» por diseño (tareas en segundo plano, login): lo
  // documenta INV_VPD.predicado. Con el usuario fijado por el paquete de confianza, filtra en la base.
  const sur = await alta(jefe, { SED_Sede: SEDES.B });
  const norte = await alta(admin);
  const visibles = await consulta<any>(
    'SELECT "ACT_Activo" A, "SED_Sede" S FROM "INV_ACTIVO" WHERE "ACT_Activo" IN (:s, :n)',
    { s: hex(sur.ACT_Activo), n: hex(norte.ACT_Activo) }, 'app', usuario('qa_tecnico_sur').id);
  expect(visibles.map((f) => f.S)).toEqual([SEDES.B]);
  // La escritura también se filtra: el técnico Sur no actualiza el de la Norte aunque escriba SQL directo.
  expect(await ejecutar('UPDATE "INV_ACTIVO" SET "ACT_Hostname" = \'VPD\' WHERE "ACT_Activo" = :n', { n: hex(norte.ACT_Activo) }, 'app',
    usuario('qa_tecnico_sur').id)).toBe(0);
  await expect(ejecutar("BEGIN DBMS_RLS.DROP_POLICY('INVENTARIO', 'INV_ACTIVO', 'INV_RLS_LECTURA'); END;", [], 'app')).rejects.toThrow(/ORA-/);
  const disparador = (await consulta<any>('SELECT MIN("TRIGGER_NAME") T FROM USER_TRIGGERS WHERE "TABLE_NAME" = \'INV_KARDEX\''))[0].T;
  await expect(ejecutar(`ALTER TRIGGER "INVENTARIO"."${disparador}" DISABLE`, [], 'app')).rejects.toThrow(/ORA-/);
  await expect(ejecutar("BEGIN DBMS_SESSION.SET_CONTEXT('INV_CTX', 'GLOBAL', '1'); END;", [], 'app')).rejects.toThrow(/ORA-/);
});

test('alcance por sede: el técnico de la sede Sur no ve ni opera activos de la Norte', async () => {
  const a = await alta(admin);
  expect(await detalle(await tecnicoSur.ctx.get(`/api/v1/core/activos/${a.ACT_Activo}`))).toEqual([404, 'ASSET_NOT_FOUND']);
  expect((await tecnicoSur.ctx.get(`/api/v1/core/activos/by-code/${a.ACT_Codigo_Interno}`)).status()).toBe(404);
  const busqueda = await (await tecnicoSur.ctx.post('/api/v1/core/activos/search', { data: { q: a.ACT_Codigo_Interno } })).json();
  expect(busqueda.total).toBe(0);
  const hist = await tecnicoSur.ctx.get(`/api/v1/trazabilidad/activo/${a.ACT_Activo}/historial`);
  test.info().annotations.push({ type: 'historial ajeno', description: `${hist.status()} ${(await hist.text()).slice(0, 120)}` });
  expect(hist.status() === 404 || (hist.status() === 200 && (await hist.json()).length === 0)).toBe(true);
  expect((await asignar(tecnicoSur, a.ACT_Activo, ana)).status()).toBeGreaterThanOrEqual(400);
  expect((await tecnicoNorte.ctx.get(`/api/v1/core/activos/${a.ACT_Activo}`)).status()).toBe(200);
  const lista = await (await tecnicoSur.ctx.get('/api/v1/core/activos?limit=200')).json();
  const sedes = new Set((Array.isArray(lista) ? lista : lista.items).map((x: any) => x.SED_Sede));
  expect([...sedes].every((s) => s === SEDES.B), `sedes visibles: ${[...sedes]}`).toBe(true);
});

test('costos ocultos a TECNICO y CONSULTA en detalle y búsqueda; visibles a TI', async () => {
  const a = await alta(admin);
  const consultaNorte = await sesionApi('qa_consulta_norte');
  for (const s of [tecnicoNorte, consultaNorte]) {
    const det = await (await s.ctx.get(`/api/v1/core/activos/${a.ACT_Activo}`)).json();
    expect(det.ACT_Costo ?? null, `${s.usuario.rol} no recibe el costo`).toBeNull();
    const bus = await (await s.ctx.post('/api/v1/core/activos/search', { data: { q: a.ACT_Codigo_Interno } })).json();
    expect(JSON.stringify(bus)).not.toContain('1234.56');
  }
  expect((await (await admin.ctx.get(`/api/v1/core/activos/${a.ACT_Activo}`)).json()).ACT_Costo).toBe('1234.56');
});

test('Idempotency-Key: reintento = misma respuesta y un solo movimiento; otro cuerpo u otro usuario con la misma clave = 409', async () => {
  const a = await alta(admin);
  const clave = `qa-${sufijo()}-${sufijo()}-${sufijo()}`;
  const cuerpo = { ACT_Activo: a.ACT_Activo, PER_Persona: ana, ARE_Area: areaNorte, TMO_Tipo_Movimiento: await tipoMovimiento('Asignación'), MOV_Motivo: 'Idempotente' };
  const r1 = await admin.ctx.post('/api/v1/trazabilidad/movimientos', { data: cuerpo, headers: { 'Idempotency-Key': clave } });
  const r2 = await admin.ctx.post('/api/v1/trazabilidad/movimientos', { data: cuerpo, headers: { 'Idempotency-Key': clave } });
  expect([r1.status(), r2.status()]).toEqual([201, 201]);
  expect(await r2.json()).toEqual(await r1.json());
  const n = await consulta<any>('SELECT COUNT(*) N FROM "INV_MOVIMIENTO" WHERE "ACT_Activo" = :a', { a: hex(a.ACT_Activo) });
  expect(Number(n[0].N)).toBe(1);
  expect((await admin.ctx.post('/api/v1/trazabilidad/movimientos', { data: { ...cuerpo, MOV_Motivo: 'Otro' }, headers: { 'Idempotency-Key': clave } })).status()).toBe(409);
  expect(await detalle(await jefe.ctx.post('/api/v1/trazabilidad/movimientos', { data: cuerpo, headers: { 'Idempotency-Key': clave } })))
    .toEqual([409, 'IDEMPOTENCY_KEY_CONFLICT_DIFFERENT_OWNER']);
  expect((await admin.ctx.post('/api/v1/trazabilidad/movimientos', { data: cuerpo, headers: { 'Idempotency-Key': 'corta' } })).status()).toBe(400);
});

test('doble envío y concurrencia: varios usuarios asignan el mismo activo a la vez -> uno gana, un solo movimiento abierto', async () => {
  for (let ronda = 0; ronda < 3; ronda++) {
    const a = await alta(admin);
    const res = await Promise.all([asignar(admin, a.ACT_Activo, ana), asignar(jefe, a.ACT_Activo, beto), asignar(admin, a.ACT_Activo, ana),
      asignar(tecnicoNorte, a.ACT_Activo, beto)]);
    const codigos = res.map((r) => r.status()).sort();
    expect(codigos.filter((c) => c === 201), `ronda ${ronda}: ${codigos}`).toHaveLength(1);
    expect(codigos.every((c) => c === 201 || c === 409), `ronda ${ronda}: ${codigos}`).toBe(true);
    const abiertos = await consulta<any>('SELECT COUNT(*) N FROM "INV_MOVIMIENTO" WHERE "ACT_Activo" = :a AND "MOV_Fecha_Devolucion" IS NULL', { a: hex(a.ACT_Activo) });
    expect(Number(abiertos[0].N)).toBe(1);
    expect((await kardexDe(a.ACT_Activo)).map((e) => e.KDX_TIPO)).toEqual(['ALTA', 'ASIGNACION']);
  }
});

test('lotes opuestos sobre los mismos activos: ninguno termina en bloqueo mutuo', async () => {
  for (let ronda = 0; ronda < 5; ronda++) {
    const activos = (await Promise.all(Array.from({ length: 6 }, () => alta(admin)))).map((a) => a.ACT_Activo);
    const opuesto = [...activos].reverse();
    const res = await Promise.all([
      admin.ctx.patch('/api/v1/core/activos/lote', { data: { activos, cambios: { ACT_Factura: `A-${sufijo()}` } } }),
      jefe.ctx.patch('/api/v1/core/activos/lote', { data: { activos: opuesto, cambios: { ACT_Factura: `B-${sufijo()}` } } }),
    ]);
    expect(res.map((r) => r.status()), `ronda ${ronda}`).toEqual([200, 200]);
  }
});

test('edición concurrente del mismo activo: sin 5xx y la base queda con uno de los valores enviados', async () => {
  const a = await alta(admin);
  const valores = Array.from({ length: 8 }, (_, i) => `HOST-${i}-${sufijo()}`);
  const res = await Promise.all(valores.map((v, i) => (i % 2 ? jefe : admin).ctx.patch(`/api/v1/core/activos/${a.ACT_Activo}`, { data: { ACT_Hostname: v } })));
  expect(res.map((r) => r.status()).every((c) => c < 500)).toBe(true);
  const final = (await consulta<any>('SELECT "ACT_Hostname" H FROM "INV_ACTIVO" WHERE "ACT_Activo" = :a', { a: hex(a.ACT_Activo) }))[0].H;
  expect(valores).toContain(final);
});

test('baja con aprobación y separación de funciones: quien solicita no aprueba; aprobada queda Baja y el kardex la registra', async () => {
  const a = await alta(admin);
  const s = await admin.ctx.post(`/api/v1/core/activos/${a.ACT_Activo}/baja`, { data: { motivo: 'OBSOLESCENCIA', destino: 'RECICLAJE' } });
  expect(s.status(), await s.text()).toBe(202);
  const apr = (await s.json()).APR_Aprobacion;
  expect((await admin.ctx.post(`/api/v1/aprobaciones/${apr}/aprobar`, { data: {} })).status()).toBe(403);
  const ok = await jefe.ctx.post(`/api/v1/aprobaciones/${apr}/aprobar`, { data: {} });
  expect(ok.status(), await ok.text()).toBe(200);
  expect(await detalle(await jefe.ctx.post(`/api/v1/aprobaciones/${apr}/rechazar`, { data: { comentario: 'Tarde ya' } }))).toEqual([409, 'APPROVAL_ALREADY_RESOLVED:APROBADA']);
  expect(await estadoEnBd(a.ACT_Activo)).toBe('Baja');
  expect((await kardexDe(a.ACT_Activo)).map((e) => e.KDX_TIPO)).toContain('BAJA');
  expect((await asignar(admin, a.ACT_Activo, ana)).status()).toBeGreaterThanOrEqual(400);
});

test('fechas: la API devuelve instantes iguales a los guardados en UTC y la fecha de compra sin desplazamiento', async () => {
  const antes = Date.now();
  const a = await alta(admin, { ACT_Fecha_Compra: '2026-01-31' });
  const m = await (await asignar(admin, a.ACT_Activo, ana)).json();
  const api = new Date(m.MOV_Fecha_Asignacion).getTime();
  test.info().annotations.push({ type: 'fecha', description: String(m.MOV_Fecha_Asignacion) });
  expect(Math.abs(api - antes)).toBeLessThan(60_000);
  const bd = (await consulta<any>('SELECT TO_CHAR("MOV_Fecha_Asignacion", \'YYYY-MM-DD"T"HH24:MI:SS\') F FROM "INV_MOVIMIENTO" WHERE "ACT_Activo" = :a',
    { a: hex(a.ACT_Activo) }))[0].F;
  expect(Math.abs(new Date(`${bd}Z`).getTime() - api)).toBeLessThan(1000);
  expect((await (await admin.ctx.get(`/api/v1/core/activos/${a.ACT_Activo}`)).json()).ACT_Fecha_Compra).toBe('2026-01-31');
});

test('cierre mensual: cantidades y valores del JSON = suma en Oracle = Excel; 2 decimales', async () => {
  for (const costo of [0.1, 0.2, 1000.01]) await alta(admin, { ACT_Costo: costo });
  const ahora = new Date();
  const [anio, mes] = [ahora.getUTCFullYear(), ahora.getUTCMonth() + 1];
  const r = await contabilidad.ctx.get(`/api/v1/inventario/cierre-mensual?anio=${anio}&mes=${mes}&sede_id=${SEDES.A}`);
  expect(r.status(), await r.text()).toBe(200);
  const c = await r.json();
  const gtq = c.activos.find((f: any) => f.moneda === 'GTQ');
  // Altas del mes = fecha de compra dentro del mes (CierreMensual), recalculadas en Oracle.
  const bd = (await consulta<any>(
    'SELECT COUNT(*) N, TO_CHAR(NVL(SUM("ACT_Costo"), 0), \'FM9999999990.00\') V FROM "INV_ACTIVO" WHERE "SED_Sede" = :s AND "ACT_Moneda" = \'GTQ\' '
    + 'AND "ACT_Fecha_Compra" BETWEEN TO_DATE(:d, \'YYYY-MM-DD\') AND LAST_DAY(TO_DATE(:d, \'YYYY-MM-DD\'))',
    { s: SEDES.A, d: `${anio}-${String(mes).padStart(2, '0')}-01` }))[0];
  test.info().annotations.push({ type: 'cierre', description: `API altas ${gtq.altas_cantidad}/${gtq.altas_valor}; Oracle ${bd.N}/${bd.V}` });
  expect([gtq.altas_cantidad, gtq.altas_valor]).toEqual([Number(bd.N), bd.V]);
  expect(gtq.altas_valor).toMatch(/^\d+\.\d{2}$/);
  // Continuidad: inicial + altas - bajas = final (cantidad y valor, en centavos sin coma flotante).
  const c100 = (v: string): number => Math.round(Number(v) * 100);
  for (const f of c.activos) {
    expect(f.inicial_cantidad + f.altas_cantidad - f.bajas_cantidad, `${f.moneda}`).toBe(f.final_cantidad);
    expect(c100(f.inicial_valor) + c100(f.altas_valor) - c100(f.bajas_valor), `${f.moneda}`).toBe(c100(f.final_valor));
  }
  const x = await contabilidad.ctx.get(`/api/v1/inventario/cierre-mensual.xlsx?anio=${anio}&mes=${mes}&sede_id=${SEDES.A}`);
  expect(x.status()).toBe(200);
  const ExcelJS = (await import('exceljs')).default;
  const libro = new ExcelJS.Workbook();
  await libro.xlsx.load(await x.body());
  const celdas: string[] = [];
  libro.worksheets.forEach((h) => h.eachRow((f) => f.eachCell((cel) => { celdas.push(String((cel.value as any)?.result ?? cel.value)); })));
  expect(celdas.some((v) => !Number.isNaN(Number(v)) && Number(v).toFixed(2) === gtq.altas_valor), 'el Excel trae el total de altas').toBe(true);
});

test('consistencia: exporte CSV de activos = base para un usuario global', async () => {
  const csv = await (await contabilidad.ctx.get('/api/v1/export/activos.csv')).text();
  const filas = csv.replace(/^﻿/, '').trim().split(/\r?\n/).length - 1;
  const bd = await consulta<any>('SELECT COUNT(*) N FROM "INV_ACTIVO"');
  expect(filas).toBe(Number(bd[0].N));
});

test('toma física por API: ENCONTRADO, REPETIDO, OTRA_UBICACION, OTRA_SEDE, DE_BAJA, NO_REGISTRADO y FALTANTE al cerrar', async () => {
  const areaToma = await area(SEDES.A);
  const otraArea = await area(SEDES.A);
  const enArea = await alta(admin);
  expect((await asignar(admin, enArea.ACT_Activo, ana, areaToma, 'Toma')).status()).toBe(201);
  const enOtraArea = await alta(admin);
  expect((await asignar(admin, enOtraArea.ACT_Activo, beto, otraArea, 'Toma')).status()).toBe(201);
  const faltara = await alta(admin);
  const deOtraSede = await alta(jefe, { SED_Sede: SEDES.B });
  const deBaja = await alta(admin);
  const b = await (await admin.ctx.post(`/api/v1/core/activos/${deBaja.ACT_Activo}/baja`, { data: { motivo: 'OBSOLESCENCIA', destino: 'RECICLAJE' } })).json();
  await jefe.ctx.post(`/api/v1/aprobaciones/${b.APR_Aprobacion}/aprobar`, { data: {} });

  const previas = await (await jefe.ctx.get('/api/v1/inventario/tomas')).json();
  for (const t of (Array.isArray(previas) ? previas : previas.items ?? []).filter((t: any) => t.TOM_Estado === 'ABIERTA' && t.SED_Sede === SEDES.A)) {
    await jefe.ctx.post(`/api/v1/inventario/tomas/${t.TOM_Toma}/anular`, { data: { motivo: 'QA' } });
  }
  const tr = await jefe.ctx.post('/api/v1/inventario/tomas', { data: { SED_Sede: SEDES.A } });
  expect(tr.status(), await tr.text()).toBe(201);
  const toma = await tr.json();
  const leer = async (codigo: string) => {
    const r = await jefe.ctx.post(`/api/v1/inventario/tomas/${toma.TOM_Toma}/lecturas`, { data: { codigo, ARE_Area: areaToma } });
    expect(r.status(), await r.text()).toBe(201);
    return (await r.json()).TLE_Resultado;
  };
  const resultados = {
    encontrado: await leer(enArea.ACT_Codigo_Interno),
    repetido: await leer(enArea.ACT_Codigo_Interno),
    otraUbicacion: await leer(enOtraArea.ACT_Codigo_Interno),
    otraSede: await leer(deOtraSede.ACT_Codigo_Interno),
    deBaja: await leer(deBaja.ACT_Codigo_Interno),
    noRegistrado: await leer(`NOEXISTE-${sufijo()}`),
  };
  test.info().annotations.push({ type: 'toma', description: JSON.stringify(resultados) });
  expect(resultados).toEqual({ encontrado: 'ENCONTRADO', repetido: 'REPETIDO', otraUbicacion: 'OTRA_UBICACION', otraSede: 'OTRA_SEDE',
    deBaja: 'DE_BAJA', noRegistrado: 'NO_REGISTRADO' });
  const cerrada = await (await jefe.ctx.post(`/api/v1/inventario/tomas/${toma.TOM_Toma}/cerrar`, { data: {} })).json();
  expect(cerrada.TOM_Estado).toBe('CERRADA');
  const faltantes = cerrada.resumen.faltantes.map((f: any) => f.codigo);
  expect(faltantes).toContain(faltara.ACT_Codigo_Interno);
  expect(faltantes).not.toContain(enArea.ACT_Codigo_Interno);
  const bd = await consulta<any>('SELECT "TLE_Resultado" R FROM "INV_TOMA_LECTURA" WHERE "TOM_Toma" = :t', { t: toma.TOM_Toma });
  expect(bd.map((f) => f.R).sort()).toEqual(Object.values(resultados).sort());
});
