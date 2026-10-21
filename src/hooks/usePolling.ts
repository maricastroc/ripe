"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { api } from "@/lib/api";

export function usePolling<T>(url: string, initial: T, interval: (data: T) => number) {
  const [data, setData] = useState<T>(initial);
  const [error, setError] = useState<string | null>(null);
  const dataRef = useRef(data);
  const intervalRef = useRef(interval);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const inflight = useRef<AbortController | null>(null);

  useEffect(() => {
    intervalRef.current = interval;
  }, [interval]);

  const apply = useCallback((next: T) => {
    dataRef.current = next;
    setData(next);
  }, []);

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
      setError(err instanceof Error ? err.message : "Falha ao atualizar");
    }
  }, [url, apply]);

  useEffect(() => {
    let alive = true;
    const schedule = () => {
      if (!alive) return;
      timer.current = setTimeout(async () => {
        if (document.visibilityState === "visible") await refresh();
        schedule();
      }, intervalRef.current(dataRef.current));
    };
    schedule();
    const onVisible = () => {
      if (document.visibilityState === "visible") void refresh();
    };
    document.addEventListener("visibilitychange", onVisible);
    window.addEventListener("focus", onVisible);
    return () => {
      alive = false;
      if (timer.current) clearTimeout(timer.current);
      inflight.current?.abort();
      document.removeEventListener("visibilitychange", onVisible);
      window.removeEventListener("focus", onVisible);
    };
  }, [refresh]);

  return { data, setData: apply, error, refresh };
}
