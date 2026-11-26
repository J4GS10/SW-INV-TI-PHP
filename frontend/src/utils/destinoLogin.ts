/**
 * Adónde volver después de iniciar sesión: la pantalla que se pidió sin sesión (por ejemplo, la ficha
 * que abrió el QR de una etiqueta). Solo rutas internas: nada de otro sitio («//x», «https://…») ni el login.
 */
export const destinoTrasLogin = (state: unknown): string => {
  const desde = (state as { from?: unknown } | null)?.from;
  if (typeof desde !== 'string' || !desde.startsWith('/') || desde.startsWith('//') || desde.startsWith('/\\')) return '/';
  if (/^\/(login|sso|reset-password)(\/|$|\?|#)/.test(desde)) return '/';
  return desde;
};
