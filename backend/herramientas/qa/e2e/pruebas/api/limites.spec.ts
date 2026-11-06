/*
 * Límites de tasa con los valores de PRODUCCIÓN (5 inicios de sesión por minuto e IP; 100 peticiones
 * por minuto y usuario). Solo corre con QA_LIMITES=produccion, después de
 * `bash herramientas/qa/entorno.sh limites-produccion` (y se vuelve con `limites-funcionales`).
 */
import { test, expect } from '@playwright/test';
import { contextoApi, sesionApi, sufijo } from '../soporte';

test.skip(process.env.QA_LIMITES !== 'produccion', 'Requiere los límites de producción en QA (entorno.sh limites-produccion).');

test('fuerza bruta de contraseñas: tras 5 intentos por minuto la IP recibe 429', async () => {
  const api = await contextoApi();
  const codigos: number[] = [];
  for (let i = 0; i < 8; i++) {
    codigos.push((await api.post('/api/v1/login/access-token', { form: { username: `bruto_${sufijo()}`, password: 'x' } })).status());
  }
  test.info().annotations.push({ type: 'códigos', description: codigos.join(',') });
  // Con 5/minute el quinto intento ya choca con el límite (la ventana cuenta el intento en curso).
  expect(codigos.slice(0, 4).every((c) => c === 400)).toBe(true);
  expect(codigos.slice(5).every((c) => c === 429)).toBe(true);
});

test('abuso de la API: un usuario autenticado recibe 429 pasado el límite por minuto, sin 5xx', async () => {
  await new Promise((r) => setTimeout(r, 61_000)); // ventana nueva para el inicio de sesión
  const s = await sesionApi('qa_contabilidad');
  const codigos: number[] = [];
  for (let i = 0; i < 130; i++) codigos.push((await s.ctx.get('/api/v1/me')).status());
  const c429 = codigos.filter((c) => c === 429).length;
  test.info().annotations.push({ type: '429', description: `${c429} de ${codigos.length}` });
  expect(c429).toBeGreaterThan(0);
  expect(codigos.every((c) => c === 200 || c === 429)).toBe(true);
});

test('ráfaga concurrente: el límite se respeta aunque las peticiones lleguen a la vez (inicio de sesión y API)', async () => {
  test.setTimeout(300_000);
  await new Promise((r) => setTimeout(r, 61_000));
  const api = await contextoApi();
  const login = await Promise.all(Array.from({ length: 40 }, () =>
    api.post('/api/v1/login/access-token', { form: { username: `bruto_${sufijo()}`, password: 'x' } }).then((r) => r.status())));
  const loginOk = login.filter((c) => c !== 429).length;
  await new Promise((r) => setTimeout(r, 61_000));
  const s = await sesionApi('qa_contabilidad');
  const me = await Promise.all(Array.from({ length: 160 }, () => s.ctx.get('/api/v1/me').then((r) => r.status())));
  const meOk = me.filter((c) => c === 200).length;
  test.info().annotations.push({ type: 'ráfaga', description: `login no limitados ${loginOk}/40 (límite 5); /me atendidos ${meOk}/160 (límite 100)` });
  expect(loginOk, 'intentos de contraseña atendidos en una ráfaga').toBeLessThanOrEqual(5);
  expect(meOk, 'peticiones atendidas en una ráfaga').toBeLessThanOrEqual(100);
});
