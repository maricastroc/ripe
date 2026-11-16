import { localeOf } from "@/lib/i18n/locales";

const SECRET_HEADER = "x-ripe-gateway";
const CLIENT_HEADER = "x-ripe-client-ip";
const PLATFORM_CLIENT_HEADER = "x-vercel-forwarded-for";

const UNTRUSTED = [
  SECRET_HEADER,
  CLIENT_HEADER,
  "host",
  "forwarded",
  "x-forwarded-for",
  "x-forwarded-host",
  "x-forwarded-port",
  "x-forwarded-proto",
  "x-real-ip",
  PLATFORM_CLIENT_HEADER,
];

export function apiOrigin(): string {
  return process.env.RIPE_API_ORIGIN ?? "http://127.0.0.1:8000";
}

function clientAddress(incoming: Headers): string | null {
  if (process.env.VERCEL !== "1") return null;
  const reported = incoming.get(PLATFORM_CLIENT_HEADER)?.split(",")[0]?.trim();
  return reported ? reported : null;
}

function sign(headers: Headers, incoming: Headers) {
  headers.set("accept-language", localeOf(incoming));
  const secret = process.env.RIPE_GATEWAY_SECRET;
  if (secret) headers.set(SECRET_HEADER, secret);
  const address = clientAddress(incoming);
  if (address) headers.set(CLIENT_HEADER, address);
  return headers;
}

export function forwardedHeaders(incoming: Headers): Headers {
  const headers = new Headers(incoming);
  for (const name of UNTRUSTED) headers.delete(name);
  return sign(headers, incoming);
}

export function serverHeaders(incoming: Headers): Headers {
  const headers = new Headers({ accept: "application/json" });
  const cookie = incoming.get("cookie");
  if (cookie) headers.set("cookie", cookie);
  return sign(headers, incoming);
}
