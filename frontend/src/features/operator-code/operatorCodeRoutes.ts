/** Cabeceras con las que el servidor recibe el código de operador (App\Seguridad\ConCodigoOperador). */
export const OPERATOR_USER_HEADER = 'X-Operator-User';
export const OPERATOR_CODE_HEADER = 'X-Operator-Code';

/** Qué se está autorizando: el diálogo lo dice con palabras. */
export type OperatorAction = 'assign' | 'return' | 'transfer' | 'undo' | 'handover_batch' | 'return_batch' | 'maintenance';

/** Espejo de las rutas con #[ConCodigoOperador] del servidor (todas POST). */
const RUTAS: Array<[RegExp, OperatorAction]> = [
  [/^\/trazabilidad\/movimientos\/revertir$/, 'undo'],
  [/^\/trazabilidad\/movimientos$/, 'assign'],
  [/^\/trazabilidad\/devolucion$/, 'return'],
  [/^\/trazabilidad\/transferencia$/, 'transfer'],
  [/^\/trazabilidad\/entrega-lote$/, 'handover_batch'],
  [/^\/trazabilidad\/devolucion-lote$/, 'return_batch'],
  [/^\/mantenimiento\/?$/, 'maintenance'],
];

/** La operación que pide código, o null. Acepta la ruta relativa del cliente o la dirección completa. */
export const operatorActionFor = (method: string | undefined, url: string | undefined): OperatorAction | null => {
  if ((method ?? 'get').toLowerCase() !== 'post' || !url) return null;
  const ruta = url.split(/[?#]/)[0].replace(/^https?:\/\/[^/]+/i, '').replace(/^\/api\/v1(?=\/)/, '');
  const conBarra = ruta.startsWith('/') ? ruta : `/${ruta}`;
  return RUTAS.find(([patron]) => patron.test(conBarra))?.[1] ?? null;
};
