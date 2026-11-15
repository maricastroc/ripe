"use client";

import { useCallback, useEffect, useRef, useState, useSyncExternalStore } from "react";
import { type RealtimeConfig, connectionState, echoFor } from "@/lib/realtime";

type Listeners = Record<string, (payload: unknown) => void>;

function useConnected(config: RealtimeConfig | null) {
  const subscribe = useCallback(
    (notify: () => void) => {
      if (!config) return () => undefined;
      const connection = echoFor(config).connector.pusher.connection;
      connection.bind("state_change", notify);
      notify();
      return () => connection.unbind("state_change", notify);
    },
    [config],
  );
  return useSyncExternalStore(
    subscribe,
    () => connectionState(config) === "connected",
    () => false,
  );
}

export function useChannel(
  config: RealtimeConfig | null,
  name: string | null,
  listeners: Listeners,
  onSubscribed: () => void,
) {
  const connected = useConnected(config);
  const [subscribed, setSubscribed] = useState<string | null>(null);
  const handlers = useRef({ listeners, onSubscribed });
  const events = Object.keys(listeners).sort().join(",");

  useEffect(() => {
    handlers.current = { listeners, onSubscribed };
  });

  useEffect(() => {
    if (!config || !name) return;
    const echo = echoFor(config);
    const connection = echo.connector.pusher.connection;
    const dropped = ({ current }: { current: string }) => {
      if (current !== "connected") setSubscribed((subscribedTo) => (subscribedTo === name ? null : subscribedTo));
    };
    connection.bind("state_change", dropped);
    const channel = echo.private(name);
    for (const event of events.split(",").filter(Boolean)) {
      channel.listen(`.${event}`, (payload: unknown) => handlers.current.listeners[event]?.(payload));
    }
    channel.subscribed(() => {
      setSubscribed(name);
      handlers.current.onSubscribed();
    });
    channel.error(() => setSubscribed((current) => (current === name ? null : current)));
    return () => {
      connection.unbind("state_change", dropped);
      echo.leave(name);
    };
  }, [config, name, events]);

  return name !== null && connected && subscribed === name;
}
