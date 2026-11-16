"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { api } from "@/lib/api";
import { documentI18n } from "@/lib/i18n";

export const REALTIME_POLL_MS = 30_000;
const REQUEST_DELAY_MS = 150;

type Options<T> = {
  version?: (data: T) => number;
};

export function usePolling<T>(url: string, initial: T, interval: (data: T) => number, options: Options<T> = {}) {
  const { version } = options;
  const [data, setState] = useState<T>(initial);
  const [error, setError] = useState<string | null>(null);
  const dataRef = useRef(data);
  const intervalRef = useRef(interval);
  const versionRef = useRef(version);
  const realtimeRef = useRef(false);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const requested = useRef<ReturnType<typeof setTimeout> | null>(null);
  const inflight = useRef<AbortController | null>(null);
  const reschedule = useRef<() => void>(() => undefined);

  useEffect(() => {
    intervalRef.current = interval;
    versionRef.current = version;
  }, [interval, version]);

  const apply = useCallback((next: T) => {
    const of = versionRef.current;
    if (of && of(next) < of(dataRef.current)) return;
    dataRef.current = next;
    setState(next);
  }, []);

  const setData = useCallback(
    (next: T) => {
      inflight.current?.abort();
      apply(next);
    },
    [apply],
  );

  const refresh = useCallback(async () => {
    inflight.current?.abort();
    const controller = new AbortController();
    inflight.current = controller;
    try {
      const next = await api<T>(url, { signal: controller.signal });
      if (controller.signal.aborted) return;
      apply(next);
      setError(null);
    } catch (err) {
      if (controller.signal.aborted) return;
      setError(err instanceof Error ? err.message : documentI18n().t.errors.refresh);
    }
  }, [url, apply]);

  const request = useCallback(() => {
    if (requested.current) return;
    requested.current = setTimeout(() => {
      requested.current = null;
      void refresh();
    }, REQUEST_DELAY_MS);
  }, [refresh]);

  useEffect(() => {
    let alive = true;
    const schedule = () => {
      if (timer.current) clearTimeout(timer.current);
      if (!alive) return;
      const wait = realtimeRef.current ? REALTIME_POLL_MS : intervalRef.current(dataRef.current);
      timer.current = setTimeout(async () => {
        if (document.visibilityState === "visible") await refresh();
        schedule();
      }, wait);
    };
    reschedule.current = schedule;
    schedule();
    const onVisible = () => {
      if (document.visibilityState === "visible") void refresh();
    };
    document.addEventListener("visibilitychange", onVisible);
    window.addEventListener("focus", onVisible);
    return () => {
      alive = false;
      reschedule.current = () => undefined;
      if (timer.current) clearTimeout(timer.current);
      if (requested.current) clearTimeout(requested.current);
      requested.current = null;
      inflight.current?.abort();
      document.removeEventListener("visibilitychange", onVisible);
      window.removeEventListener("focus", onVisible);
    };
  }, [refresh]);

  const setRealtime = useCallback(
    (live: boolean) => {
      if (realtimeRef.current === live) return;
      realtimeRef.current = live;
      void refresh();
      reschedule.current();
    },
    [refresh],
  );

  return { data, setData, error, refresh, request, setRealtime };
}
