/**
 * Lecturas en memoria, para que volver a una pantalla sea instantáneo.
 *
 * - Las lecturas simultáneas de la misma clave comparten una sola petición.
 * - Cualquier escritura (POST/PUT/PATCH/DELETE) y cualquier aviso en vivo de un
 *   cambio hecho por otra persona vacían la caché: nunca se muestra un dato
 *   desactualizado después de un cambio.
 * - Al cerrar la sesión también se vacía (otro usuario puede tener otro alcance).
 */
const TTL_MS = 2 * 60_000;
/** Lecturas generales de la API: cortas, por si algo cambia por el paso del tiempo (vencimientos, «hace 5 min»). */
export const GET_TTL_MS = 60_000;
const store = new Map<string, { at: number; value: Promise<unknown> }>();

export const apiCache = {
  get<T>(key: string, load: () => Promise<T>, ttl = TTL_MS): Promise<T> {
    const hit = store.get(key);
    if (hit && Date.now() - hit.at < ttl) return hit.value as Promise<T>;
    const value: Promise<T> = load().catch((err: unknown) => {
      if (store.get(key)?.value === value) store.delete(key);
      throw err;
    });
    store.set(key, { at: Date.now(), value });
    return value;
  },
  clear(): void {
    store.clear();
  },
};
