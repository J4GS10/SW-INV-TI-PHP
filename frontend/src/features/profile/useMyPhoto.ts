import { useCallback, useEffect, useSyncExternalStore } from 'react';

import { authService } from '../auth/authService';

/** The signed-in person's photo, shared by the menu and anything else that shows it. */
let state: { user: string | null; url: string | null } = { user: null, url: null };
const listeners = new Set<() => void>();
const set = (next: typeof state) => {
  state = next;
  listeners.forEach((listener) => listener());
};
const subscribe = (listener: () => void) => {
  listeners.add(listener);
  return () => listeners.delete(listener);
};

export const useMyPhoto = (username: string | undefined): [string | null, (url: string | null) => void] => {
  const current = useSyncExternalStore(subscribe, () => state);
  useEffect(() => {
    if (!username || state.user === username) return;
    set({ user: username, url: null });
    authService.me()
      .then((me: { foto_url?: string | null }) => { if (state.user === username) set({ user: username, url: me.foto_url ?? null }); })
      .catch(() => undefined);
  }, [username]);
  const change = useCallback((url: string | null) => set({ user: state.user, url }), []);
  return [current.user === username ? current.url : null, change];
};
