import { useCallback, useEffect, useRef } from "react";
import { documentI18n } from "./i18n";

type TurnstileApi = {
  render: (element: HTMLElement, options: Record<string, unknown>) => string;
  execute: (widget: string) => void;
  reset: (widget: string) => void;
  remove: (widget: string) => void;
};

declare global {
  interface Window {
    turnstile?: TurnstileApi;
  }
}

const SCRIPT = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";

function failed() {
  return new Error(documentI18n().t.errors.notAPerson);
}

let loading: Promise<TurnstileApi> | null = null;

function load(): Promise<TurnstileApi> {
  if (window.turnstile) return Promise.resolve(window.turnstile);
  loading ??= new Promise((resolve, reject) => {
    const script = document.createElement("script");
    script.src = SCRIPT;
    script.async = true;
    script.onload = () => (window.turnstile ? resolve(window.turnstile) : reject(failed()));
    script.onerror = () => {
      loading = null;
      reject(failed());
    };
    document.head.appendChild(script);
  });
  return loading;
}

type Pending = { resolve: (token: string) => void; reject: (error: Error) => void };

export function useTurnstile(siteKey: string | null) {
  const element = useRef<HTMLDivElement>(null);
  const widget = useRef<string | null>(null);
  const pending = useRef<Pending | null>(null);

  useEffect(() => {
    if (!siteKey) return;
    let gone = false;
    load().then(
      (turnstile) => {
        if (gone || !element.current) return;
        widget.current = turnstile.render(element.current, {
          sitekey: siteKey,
          execution: "execute",
          appearance: "interaction-only",
          language: documentI18n().locale.toLowerCase(),
          callback: (token: string) => {
            pending.current?.resolve(token);
            pending.current = null;
          },
          "error-callback": () => {
            pending.current?.reject(failed());
            pending.current = null;
          },
        });
      },
      () => undefined,
    );
    return () => {
      gone = true;
      if (widget.current) window.turnstile?.remove(widget.current);
      widget.current = null;
    };
  }, [siteKey]);

  const token = useCallback(async (): Promise<string | null> => {
    if (!siteKey) return null;
    const turnstile = await load();
    const id = widget.current;
    if (!id) throw failed();
    turnstile.reset(id);
    return new Promise<string>((resolve, reject) => {
      const timer = setTimeout(() => {
        pending.current = null;
        reject(failed());
      }, 30_000);
      pending.current = {
        resolve: (value) => {
          clearTimeout(timer);
          resolve(value);
        },
        reject: (error) => {
          clearTimeout(timer);
          reject(error);
        },
      };
      turnstile.execute(id);
    });
  }, [siteKey]);

  return { element, token };
}
