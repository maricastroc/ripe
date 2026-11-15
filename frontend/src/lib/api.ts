export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
  ) {
    super(message);
  }
}

export async function api<T>(
  url: string,
  init?: { method?: string; body?: unknown; signal?: AbortSignal },
): Promise<T> {
  let res: Response;
  try {
    res = await fetch(url, {
      method: init?.method ?? "GET",
      headers: init?.body !== undefined ? { "Content-Type": "application/json" } : undefined,
      body: init?.body !== undefined ? JSON.stringify(init.body) : undefined,
      cache: "no-store",
      signal: init?.signal,
    });
  } catch {
    throw new ApiError("Can't reach Ripe. Is the server still running?", 0);
  }
  const data = (await res.json().catch(() => ({}))) as T & { error?: string };
  if (!res.ok) throw new ApiError(data.error ?? `Error ${res.status}`, res.status);
  return data;
}
