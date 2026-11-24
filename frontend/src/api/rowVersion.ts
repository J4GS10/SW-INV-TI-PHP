/**
 * Concurrencia optimista: cada registro editable llega con `row_version` (la versión de su fila). Una edición
 * devuelve la versión del registro que cargó la pantalla; el cliente la envía como `If-Match` (api/axios.ts) y
 * el servidor responde 412 RECORD_CHANGED_BY_ANOTHER_USER si otra persona lo guardó mientras tanto, sin pisar nada.
 */
export const ROW_VERSION_FIELD = 'row_version';

/**
 * La edición `payload` con la versión del registro `loaded` tal como se abrió en el formulario. Sin versión
 * (registro nuevo, o una respuesta que no la trae) la edición se envía igual, sin comprobar.
 */
export function withRowVersion<T extends object>(payload: T, loaded: unknown): T {
  const version = (loaded as { row_version?: unknown } | null | undefined)?.row_version;
  return typeof version === 'number' ? { ...payload, [ROW_VERSION_FIELD]: version } : payload;
}

/**
 * La versión sale del cuerpo y va en `If-Match` (`"7"`): el servidor no la recibe como un dato más del registro.
 * Solo en PATCH y PUT con un objeto JSON; un archivo (FormData) o una lista se envían tal cual.
 */
export function moveRowVersionToHeader(method: string | undefined, data: unknown): { data: unknown; ifMatch?: string } {
  const verb = (method ?? 'get').toLowerCase();
  if ((verb !== 'patch' && verb !== 'put') || data === null || typeof data !== 'object' || Array.isArray(data)
    || Object.getPrototypeOf(data) !== Object.prototype) {
    return { data };
  }
  const { [ROW_VERSION_FIELD]: version, ...rest } = data as Record<string, unknown>;
  return typeof version === 'number' ? { data: rest, ifMatch: `"${version}"` } : { data };
}
