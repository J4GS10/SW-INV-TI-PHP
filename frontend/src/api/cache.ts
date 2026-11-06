/**
 * Caché breve de lecturas que cambian poco (catálogos, áreas, tipos de
 * movimiento). Evita repetir las mismas consultas al navegar entre páginas.
 *
 * - Las lecturas simultáneas de la misma clave comparten una sola petición.
 * - Cualquier escritura (POST/PUT/PATCH/DELETE) vacía la caché: nunca se
 *   muestra un catálogo desactualizado después de editarlo.
 * - Al cerrar la sesión también se vacía (otro usuario puede tener otro alcance).
 */
const TTL_MS = 2 * 60_000;
const store = new Map<string, { at: number; value: Promise<unknown> }>();

export const apiCache = {
  get<T>(key: string, load: () => Promise<T>, ttl = TTL_MS): Promise<T> {
    const hit = store.get(key);
    if (hit && Date.now() - hit.at < ttl) return hit.value as Promise<T>;
    const value = load().catch((err: unknown) => {
      store.delete(key);
      throw err;
    });
    store.set(key, { at: Date.now(), value });
    return value;
  },
  clear(): void {
    store.clear();
  },
};
