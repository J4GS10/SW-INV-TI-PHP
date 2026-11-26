/**
 * Código de operador: 6 dígitos que no sean obvios (mismas reglas que App\Dominio\CodigoOperador del servidor,
 * para avisar antes de enviar). Devuelve el código de error de la API o null si sirve.
 */
const OBVIOS = ['123123', '121212', '112233', '111222', '123321', '159753', '147258', '258369', '102030', '010203'];

const esSecuencia = (codigo: string): boolean => [1, -1].some((paso) =>
  [...codigo].every((d, i) => i === 0 || Number(d) - Number(codigo[i - 1]) === paso));

export const operatorCodeProblem = (codigo: string): 'OPERATOR_CODE_FORMAT' | 'OPERATOR_CODE_TOO_WEAK' | null => {
  if (!/^\d{6}$/.test(codigo)) return 'OPERATOR_CODE_FORMAT';
  if (/^(\d)\1+$/.test(codigo) || OBVIOS.includes(codigo) || esSecuencia(codigo)) return 'OPERATOR_CODE_TOO_WEAK';
  return null;
};
