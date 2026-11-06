/**
 * Coordinación de sesión entre pestañas del mismo navegador (BroadcastChannel).
 *
 * - logout: al cerrar sesión en una pestaña, las demás limpian su access token
 *   en memoria (si no, seguirían operando hasta que expire).
 * - ping/pong: una pestaña nueva pregunta si hay otra con sesión activa; si la
 *   hay, adopta la sesión (la cookie HttpOnly es compartida) en vez de pedir
 *   login. Sin pestañas abiertas con sesión, se exige login.
 */
type AuthMessage = { type: 'logout' } | { type: 'ping' } | { type: 'pong' };

const channel: BroadcastChannel | null =
  typeof BroadcastChannel !== 'undefined' ? new BroadcastChannel('iti-auth') : null;

export const authSync = {
  announceLogout(): void {
    channel?.postMessage({ type: 'logout' } satisfies AuthMessage);
  },

  subscribe(handlers: { onLogout: () => void; isAuthenticated: () => boolean }): () => void {
    if (!channel) return () => undefined;
    const listener = (event: MessageEvent<AuthMessage>) => {
      if (event.data?.type === 'logout') handlers.onLogout();
      if (event.data?.type === 'ping' && handlers.isAuthenticated()) {
        channel.postMessage({ type: 'pong' } satisfies AuthMessage);
      }
    };
    channel.addEventListener('message', listener);
    return () => channel.removeEventListener('message', listener);
  },

  /** true si otra pestaña con sesión activa responde a tiempo. */
  askPeersForSession(timeoutMs = 300): Promise<boolean> {
    if (!channel) return Promise.resolve(false);
    return new Promise((resolve) => {
      const listener = (event: MessageEvent<AuthMessage>) => {
        if (event.data?.type === 'pong') finish(true);
      };
      const timer = window.setTimeout(() => finish(false), timeoutMs);
      function finish(result: boolean) {
        window.clearTimeout(timer);
        channel?.removeEventListener('message', listener);
        resolve(result);
      }
      channel.addEventListener('message', listener);
      channel.postMessage({ type: 'ping' } satisfies AuthMessage);
    });
  },
};
