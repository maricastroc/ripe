import Echo from "laravel-echo";
import Pusher from "pusher-js";
import { api } from "@/lib/api";

export type RealtimeConfig = {
  key: string;
  host: string;
  port: number;
  scheme: "http" | "https";
};

type Shared = { id: string; echo: Echo<"reverb"> };

let shared: Shared | null = null;

function idOf(config: RealtimeConfig) {
  return `${config.scheme}://${config.host}:${config.port}/${config.key}`;
}

export function echoFor(config: RealtimeConfig): Echo<"reverb"> {
  const id = idOf(config);
  if (shared?.id === id) return shared.echo;
  shared?.echo.disconnect();
  const echo = new Echo<"reverb">({
    broadcaster: "reverb",
    key: config.key,
    wsHost: config.host,
    wsPort: config.port,
    wssPort: config.port,
    forceTLS: config.scheme === "https",
    enabledTransports: ["ws", "wss"],
    activityTimeout: 20_000,
    pongTimeout: 10_000,
    Pusher,
    channelAuthorization: {
      endpoint: "/api/broadcasting/auth",
      transport: "ajax",
      customHandler: ({ socketId, channelName }, callback) => {
        api<{ auth: string }>("/api/broadcasting/auth", {
          method: "POST",
          body: { socket_id: socketId, channel_name: channelName },
        })
          .then((signed) => callback(null, signed))
          .catch((err: unknown) => callback(err instanceof Error ? err : new Error("Not allowed."), null));
      },
    },
  });
  shared = { id, echo };
  return echo;
}

export function connectionState(config: RealtimeConfig | null) {
  if (!config || shared?.id !== idOf(config)) return "initialized";
  return shared.echo.connector.pusher.connection.state;
}
