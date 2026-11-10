import { apiCache } from './cache';

/**
 * Live updates from the Mercure hub (/.well-known/mercure, same origin).
 *
 * The API announces every successful write with the API path that changed
 * (`r`), never the data: each screen reloads what it shows through the normal
 * API, with its own permissions. `o` is the tab that made the change, which
 * already refreshed itself. `r === 'me'` comes from the user's own topic: a
 * closed session, a role change or an MFA exemption.
 *
 * The hub authorizes with an HttpOnly cookie that the API sets on sign-in and
 * on every token refresh. EventSource reconnects by itself; after a reconnect
 * every screen reloads once, since changes during the cut were not received.
 */
export interface LiveChange {
  /** API path without /api/v1 (e.g. `core/activos/…`), `me`, or `*` after a reconnect. */
  resource: string;
}

type Listener = (change: LiveChange) => void;

const HUB_URL = '/.well-known/mercure?match=*';
const RETRY_MS = 5_000;

/** Identifies this tab in the writes it sends (X-Client-Id). */
export const CLIENT_ID: string =
  typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`;

const listeners = new Set<Listener>();
let source: EventSource | null = null;
let retryTimer: number | undefined;
let wasConnected = false;
let wanted = false;

function emit(change: LiveChange): void {
  apiCache.clear();
  listeners.forEach((listener) => listener(change));
}

function connect(): void {
  if (!wanted || source || typeof EventSource === 'undefined') return;
  const es = new EventSource(HUB_URL, { withCredentials: true });
  source = es;
  es.onopen = () => {
    // Back after a cut: what changed meanwhile was not received.
    if (wasConnected) emit({ resource: '*' });
    wasConnected = true;
  };
  es.onmessage = (event: MessageEvent<string>) => {
    try {
      const data = JSON.parse(event.data) as { r?: string; o?: string };
      if (!data.r || data.o === CLIENT_ID) return;
      emit({ resource: data.r });
    } catch {
      /* not ours: ignored */
    }
  };
  es.onerror = () => {
    // A network cut is retried by EventSource itself; a closed stream (401 because the
    // cookie expired, hub restarting) is reopened here a moment later.
    if (es.readyState === EventSource.CLOSED) {
      source = null;
      window.clearTimeout(retryTimer);
      retryTimer = window.setTimeout(connect, RETRY_MS);
    }
  };
}

export const liveUpdates = {
  start(): void {
    wanted = true;
    connect();
  },
  stop(): void {
    wanted = false;
    wasConnected = false;
    window.clearTimeout(retryTimer);
    source?.close();
    source = null;
  },
  /** Reopens the stream (new cookie after sign-in or a refresh). */
  restart(): void {
    if (!wanted) return;
    source?.close();
    source = null;
    connect();
  },
  subscribe(listener: Listener): () => void {
    listeners.add(listener);
    return () => { listeners.delete(listener); };
  },
};
