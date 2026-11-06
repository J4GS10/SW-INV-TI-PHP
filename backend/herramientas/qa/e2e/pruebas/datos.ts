/*
 * Datos de partida de las pruebas (como dueño del esquema, igual que api/tests/Soporte):
 * catálogos y ubicaciones con nombres únicos. Lo que se prueba (activos, movimientos,
 * tomas, compras…) se crea siempre por la API.
 */
import { consulta, ejecutar, sufijo } from './soporte';

export const hex = (uuid: string): string => uuid.replace(/-/g, '').toLowerCase();
export const uuid = (h: string): string => {
  const x = hex(h);
  return `${x.slice(0, 8)}-${x.slice(8, 12)}-${x.slice(12, 16)}-${x.slice(16, 20)}-${x.slice(20)}`;
};

async function uno(sql: string, binds: Record<string, unknown> = {}): Promise<number> {
  const filas = await consulta<{ V: number }>(sql, binds);
  return Number(filas[0]?.V);
}

export async function estado(nombre: string): Promise<number> {
  return uno('SELECT MIN("EOP_Estado_Operativo") V FROM "INV_ESTADO_OPERATIVO" WHERE "EOP_Nombre" = :n', { n: nombre });
}

export async function tipoMovimiento(nombre: string): Promise<number> {
  return uno('SELECT MIN("TMO_Tipo_Movimiento") V FROM "INV_TIPO_MOVIMIENTO" WHERE "TMO_Nombre" = :n', { n: nombre });
}

export async function tipoYModelo(clase = 'COMPUTO'): Promise<{ tipo: number; modelo: number; prefijo: string; nombre: string }> {
  const s = sufijo().toUpperCase();
  const prefijo = `Q${s.slice(0, 4)}`;
  await ejecutar('INSERT INTO "INV_TIPO_ACTIVO" ("TAC_Nombre", "TAC_Prefijo", "TAC_Clase", "TAC_Aplica_Depreciacion") VALUES (:n, :p, :c, 1)',
    { n: `QA Tipo ${s}`, p: prefijo, c: clase });
  const tipo = await uno('SELECT "TAC_Tipo_Activo" V FROM "INV_TIPO_ACTIVO" WHERE "TAC_Nombre" = :n', { n: `QA Tipo ${s}` });
  await ejecutar('INSERT INTO "INV_MARCA" ("MAR_Nombre") VALUES (:n)', { n: `QA Marca ${s}` });
  const marca = await uno('SELECT "MAR_Marca" V FROM "INV_MARCA" WHERE "MAR_Nombre" = :n', { n: `QA Marca ${s}` });
  await ejecutar('INSERT INTO "INV_MODELO" ("MOD_Nombre", "MAR_Marca", "TAC_Tipo_Activo") VALUES (:n, :m, :t)', { n: `QA Modelo ${s}`, m: marca, t: tipo });
  const modelo = await uno('SELECT "MOD_Modelo" V FROM "INV_MODELO" WHERE "MOD_Nombre" = :n', { n: `QA Modelo ${s}` });
  return { tipo, modelo, prefijo, nombre: `QA Tipo ${s}` };
}

export async function area(sede: number): Promise<number> {
  const s = sufijo();
  await ejecutar('INSERT INTO "INV_EDIFICIO" ("EDI_Nombre", "SED_Sede") VALUES (:n, :s)', { n: `QA Edificio ${s}`, s: sede });
  const edi = await uno('SELECT "EDI_Edificio" V FROM "INV_EDIFICIO" WHERE "EDI_Nombre" = :n', { n: `QA Edificio ${s}` });
  await ejecutar('INSERT INTO "INV_NIVEL" ("NIV_Numero_Piso", "EDI_Edificio") VALUES (:n, :e)', { n: `QA Nivel ${s}`, e: edi });
  const niv = await uno('SELECT "NIV_Nivel" V FROM "INV_NIVEL" WHERE "NIV_Numero_Piso" = :n', { n: `QA Nivel ${s}` });
  await ejecutar('INSERT INTO "INV_AREA" ("ARE_Nombre", "ARE_Tipo_Acceso", "NIV_Nivel") VALUES (:n, :t, :v)', { n: `QA Área ${s}`, t: 'PUBLICO', v: niv });
  return uno('SELECT "ARE_Area" V FROM "INV_AREA" WHERE "ARE_Nombre" = :n', { n: `QA Área ${s}` });
}

export async function persona(sede: number, nombre = 'Persona'): Promise<string> {
  const id = (await consulta<{ V: string }>("SELECT LOWER(RAWTOHEX(SYS_GUID())) V FROM DUAL"))[0].V;
  const dep = await uno('SELECT MIN("DEP_Departamento") V FROM "INV_DEPARTAMENTO" WHERE "DEP_Activo" = 1');
  const car = await uno('SELECT MIN("CAR_Cargo") V FROM "INV_CARGO" WHERE "CAR_Es_Jefatura" = 0');
  await ejecutar('INSERT INTO "INV_PERSONA" ("PER_Persona", "PER_Primer_Nombre", "PER_Primer_Apellido", "PER_Email_Corporativo", '
    + '"PER_Estado", "DEP_Departamento", "CAR_Cargo", "SED_Sede") VALUES (:id, :n, :a, :c, 1, :d, :k, :s)',
  { id, n: nombre, a: `QA ${sufijo()}`, c: `p${id}@qa.inventario.local`, d: dep, k: car, s: sede });
  return uuid(id);
}

/** Recalcula la cadena del kardex en la base (independiente de la API): cada hash enlaza al anterior. */
export async function kardexDe(activo: string): Promise<{ KDX_TIPO: string; KDX_SECUENCIA: number; KDX_HASH: string }[]> {
  return consulta('SELECT "KDX_Tipo" KDX_TIPO, "KDX_Secuencia" KDX_SECUENCIA, "KDX_Hash" KDX_HASH FROM "INV_KARDEX" '
    + 'WHERE "ACT_Activo" = :a ORDER BY "KDX_Secuencia"', { a: hex(activo) });
}

/** Alta de un activo por la API (sede Norte por defecto) con serie y hostname únicos. */
export async function altaApi(s: { ctx: import('@playwright/test').APIRequestContext }, modelo: number, tipo: number,
  extra: Record<string, unknown> = {}): Promise<any> {
  const { expect } = await import('@playwright/test');
  const { SEDES } = await import('./soporte');
  const r = await s.ctx.post('/api/v1/core/activos', {
    data: { ACT_Serie_Fabricante: `QR-${sufijo()}`, ACT_Fecha_Compra: '2026-10-01', ACT_Costo: 100, MOD_Modelo: modelo, TAC_Tipo_Activo: tipo,
      EOP_Estado_Operativo: await estado('En Bodega'), SED_Sede: SEDES.A, ACT_Hostname: `HOST-${sufijo()}`, ...extra },
  });
  expect(r.status(), await r.text()).toBe(201);
  return r.json();
}
