import { act, render } from '@testing-library/react';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';

import { CLIENT_ID, liveUpdates } from '../api/liveUpdates';
import { useLiveRefresh } from './useLiveRefresh';

/** EventSource stand-in: the test plays the hub. */
class FakeEventSource {
  static CLOSED = 2;
  static last: FakeEventSource | null = null;
  readyState = 1;
  onopen: (() => void) | null = null;
  onmessage: ((e: MessageEvent<string>) => void) | null = null;
  onerror: (() => void) | null = null;
  constructor(public url: string, public init?: EventSourceInit) { FakeEventSource.last = this; }
  close() { this.readyState = FakeEventSource.CLOSED; }
  send(data: object) { this.onmessage?.({ data: JSON.stringify(data) } as MessageEvent<string>); }
}

function Screen({ reload, resources, enabled }: { reload: () => void; resources?: string[]; enabled?: boolean }) {
  useLiveRefresh(reload, { resources, enabled });
  return null;
}

beforeEach(() => {
  vi.useFakeTimers();
  vi.stubGlobal('EventSource', FakeEventSource);
  liveUpdates.start();
});

afterEach(() => {
  liveUpdates.stop();
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

test('opens the hub with the session cookie and listens to every allowed topic', () => {
  expect(FakeEventSource.last?.url).toBe('/.well-known/mercure?match=*');
  expect(FakeEventSource.last?.init).toEqual({ withCredentials: true });
});

test('a change by someone else reloads the screen once, not the tab that made it', () => {
  const reload = vi.fn();
  render(<Screen reload={reload} />);
  act(() => {
    FakeEventSource.last?.send({ r: 'core/activos', o: 'another-tab' });
    FakeEventSource.last?.send({ r: 'core/activos/1', o: 'another-tab' });
    FakeEventSource.last?.send({ r: 'core/activos', o: CLIENT_ID });
    vi.advanceTimersByTime(400);
  });
  expect(reload).toHaveBeenCalledTimes(1);
});

test('a screen with resources ignores other modules; the user topic never reloads screens', () => {
  const reload = vi.fn();
  render(<Screen reload={reload} resources={['gov/config']} />);
  act(() => {
    FakeEventSource.last?.send({ r: 'core/activos', o: 'x' });
    FakeEventSource.last?.send({ r: 'me', o: 'x' });
    vi.advanceTimersByTime(400);
  });
  expect(reload).not.toHaveBeenCalled();
  act(() => {
    FakeEventSource.last?.send({ r: 'gov/config', o: 'x' });
    vi.advanceTimersByTime(400);
  });
  expect(reload).toHaveBeenCalledTimes(1);
});

test('unsaved edits are not overwritten', () => {
  const reload = vi.fn();
  render(<Screen reload={reload} enabled={false} />);
  act(() => {
    FakeEventSource.last?.send({ r: 'gov/config', o: 'x' });
    vi.advanceTimersByTime(400);
  });
  expect(reload).not.toHaveBeenCalled();
});

test('after a cut every screen reloads once, and a closed stream is reopened', () => {
  const reload = vi.fn();
  render(<Screen reload={reload} resources={['gov/config']} />);
  const first = FakeEventSource.last!;
  act(() => {
    first.onopen?.();
    first.readyState = FakeEventSource.CLOSED;
    first.onerror?.();
    vi.advanceTimersByTime(5_000);
  });
  const second = FakeEventSource.last!;
  expect(second).not.toBe(first);
  act(() => {
    second.onopen?.();
    vi.advanceTimersByTime(400);
  });
  expect(reload).toHaveBeenCalledTimes(1);
});
