import { useEffect, useRef, useState } from 'react';

import { liveUpdates } from '../api/liveUpdates';

interface Options {
  /** API path prefixes this screen shows (e.g. `gov/config`). Without them, any change reloads it. */
  resources?: string[];
  /** false while the user edits a form loaded from the server: their edits are not overwritten. */
  enabled?: boolean;
}

const DEBOUNCE_MS = 300;

/**
 * Reloads a screen when another user (or a background task) changes data, without
 * refreshing the page. Several changes in a row cause one reload; in a hidden tab the
 * reload waits until the tab is visible again.
 */
export function useLiveRefresh(reload: () => unknown, { resources, enabled = true }: Options = {}): void {
  const reloadRef = useRef(reload);
  const enabledRef = useRef(enabled);
  const resourcesKey = resources?.join('|') ?? '';

  useEffect(() => {
    reloadRef.current = reload;
    enabledRef.current = enabled;
  });

  useEffect(() => {
    const prefixes = resourcesKey ? resourcesKey.split('|') : [];
    let timer: number | undefined;
    let pending = false;

    const run = () => {
      if (document.visibilityState !== 'visible') {
        pending = true;
        return;
      }
      pending = false;
      if (enabledRef.current) void Promise.resolve(reloadRef.current()).catch(() => undefined);
    };
    const onVisible = () => {
      if (pending && document.visibilityState === 'visible') run();
    };
    const unsubscribe = liveUpdates.subscribe(({ resource }) => {
      if (resource === 'me') return;
      const matches = resource === '*' || prefixes.length === 0 || prefixes.some((p) => resource.startsWith(p));
      if (!matches) return;
      window.clearTimeout(timer);
      timer = window.setTimeout(run, DEBOUNCE_MS);
    });
    document.addEventListener('visibilitychange', onVisible);
    return () => {
      unsubscribe();
      window.clearTimeout(timer);
      document.removeEventListener('visibilitychange', onVisible);
    };
  }, [resourcesKey]);
}

/**
 * For screens that load inside a plain effect: a number that grows with each change.
 * Adding it to the effect's dependencies makes the effect load again.
 */
export function useLiveTick(options: Options = {}): number {
  const [tick, setTick] = useState(0);
  useLiveRefresh(() => setTick((n) => n + 1), options);
  return tick;
}
