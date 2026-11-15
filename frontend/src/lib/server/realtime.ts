import type { RealtimeConfig } from "@/lib/realtime";

export function realtimeConfig(): RealtimeConfig | null {
  const key = process.env.RIPE_REALTIME_KEY;
  const host = process.env.RIPE_REALTIME_HOST;
  if (!key || !host) return null;
  const scheme = process.env.RIPE_REALTIME_SCHEME === "https" ? "https" : "http";
  const port = Number(process.env.RIPE_REALTIME_PORT ?? (scheme === "https" ? 443 : 8080));
  return { key, host, port: Number.isFinite(port) ? port : 8080, scheme };
}
