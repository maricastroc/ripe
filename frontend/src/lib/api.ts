import { documentI18n } from "./i18n";

export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
    public body: Record<string, unknown> = {},
  ) {
    super(message);
  }
}

function messageOf(body: Record<string, unknown>, status: number): string {
  const errors = body.errors;
  if (errors && typeof errors === "object") {
    const first = Object.values(errors as Record<string, unknown>)[0];
    if (Array.isArray(first) && typeof first[0] === "string") return first[0];
  }
  if (typeof body.message === "string" && body.message) return body.message;
  const { t } = documentI18n();
  if (status === 429) return t.errors.tooMany;
  return t.errors.status(status);
}

export async function api<T>(
  url: string,
  init?: { method?: string; body?: unknown; signal?: AbortSignal; keepalive?: boolean },
): Promise<T> {
  let res: Response;
  try {
    res = await fetch(url, {
      method: init?.method ?? "GET",
      headers: {
        Accept: "application/json",
        ...(init?.body !== undefined ? { "Content-Type": "application/json" } : {}),
      },
      body: init?.body !== undefined ? JSON.stringify(init.body) : undefined,
      cache: "no-store",
      credentials: "same-origin",
      signal: init?.signal,
      keepalive: init?.keepalive,
    });
  } catch (err) {
    if (init?.signal?.aborted) throw err;
    throw new ApiError(documentI18n().t.errors.unreachable, 0);
  }
  if (res.status === 204) return undefined as T;
  const body = (await res.json().catch(() => ({}))) as Record<string, unknown>;
  if (!res.ok) throw new ApiError(messageOf(body, res.status), res.status, body);
  return body as T;
}
