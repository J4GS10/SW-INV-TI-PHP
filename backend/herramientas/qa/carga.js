// Carga de punta a punta por el borde de QA (Caddy -> api_php -> Oracle/Redis), con k6 (gratuito).
// Escenarios (ESCENARIO): carga (30 usuarios, 3 min), estres (rampa a 150), prolongada (20 usuarios,
// DURACION, por defecto 15 min) y fallas (15 usuarios, 6 min, mientras carga.sh inyecta fallas).
// Mezcla con el rol TECNICO de la sede Norte (no global: el VPD filtra cada consulta):
// 70 % lecturas (listado, búsqueda, tablero, kardex, by-code, /me) y 30 % altas de activos.
// El certificado de QA lo emite la CA interna de Caddy: se acepta solo aquí.
import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';

const BASE = __ENV.QA_URL || 'https://qa.inventario.local';
const errores5xx = new Counter('errores_5xx');
// El 404 del by-code inexistente es la respuesta esperada, no una falla.
http.setResponseCallback(http.expectedStatuses({ min: 200, max: 404 }));
const escenarios = {
  carga: { executor: 'constant-vus', vus: 30, duration: '3m' },
  estres: { executor: 'ramping-vus', startVUs: 10, stages: [{ duration: '1m', target: 50 }, { duration: '2m', target: 150 }, { duration: '1m', target: 150 }, { duration: '30s', target: 0 }] },
  prolongada: { executor: 'constant-vus', vus: 20, duration: __ENV.DURACION || '15m' },
  fallas: { executor: 'constant-vus', vus: 15, duration: '6m' },
};
export const options = {
  insecureSkipTLSVerify: true,
  scenarios: { principal: escenarios[__ENV.ESCENARIO || 'carga'] },
  thresholds: { http_req_failed: ['rate<0.01'], http_req_duration: ['p(95)<1500'], errores_5xx: ['count<1'] },
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export function setup() {
  const r = http.post(`${BASE}/api/v1/login/access-token`, { username: __ENV.QA_USUARIO, password: __ENV.QA_CLAVE });
  const token = r.json('access_token');
  if (!token) throw new Error(`login falló: ${r.status}`);
  return { token, cat: JSON.parse(__ENV.QA_CATALOGO) };
}

export default function (datos) {
  const p = (nombre) => ({ headers: { Authorization: `Bearer ${datos.token}`, 'Content-Type': 'application/json' }, tags: { nombre } });
  const x = Math.random();
  let r;
  if (x < 0.3) {
    r = http.post(`${BASE}/api/v1/core/activos`, JSON.stringify({
      ACT_Serie_Fabricante: `K6-${__VU}-${__ITER}-${Date.now()}`, ACT_Fecha_Compra: '2026-10-01', MOD_Modelo: datos.cat.modelo,
      TAC_Tipo_Activo: datos.cat.tipo, EOP_Estado_Operativo: datos.cat.estado, SED_Sede: datos.cat.sede,
    }), p('alta'));
    check(r, { 'alta 201': (s) => s.status === 201 });
  } else if (x < 0.45) {
    r = http.get(`${BASE}/api/v1/core/activos?limit=50`, p('listado'));
  } else if (x < 0.6) {
    r = http.post(`${BASE}/api/v1/core/activos/search`, JSON.stringify({ q: 'K6', per_page: 20 }), p('busqueda'));
  } else if (x < 0.7) {
    r = http.get(`${BASE}/api/v1/stats/dashboard`, p('tablero'));
  } else if (x < 0.8) {
    r = http.get(`${BASE}/api/v1/inventario/kardex?limit=50`, p('kardex'));
  } else if (x < 0.9) {
    r = http.get(`${BASE}/api/v1/core/activos/by-code/NOEXISTE`, p('by-code'));
  } else {
    r = http.get(`${BASE}/api/v1/me`, p('me'));
  }
  if (r.status >= 500 || r.status === 0) errores5xx.add(1);
}
