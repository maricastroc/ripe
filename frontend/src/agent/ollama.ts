export class OllamaOffline extends Error {
  name = "OllamaOffline";
}

export class ModelMissing extends Error {
  name = "ModelMissing";
}

export type ChatMessage = { role: "system" | "user" | "assistant"; content: string };

export type ChatOptions = {
  baseUrl: string;
  model: string;
  messages: ChatMessage[];
  format?: object;
  numPredict: number;
  temperature?: number;
  signal: AbortSignal;
  onProgress?: (text: string, tokens: number) => void;
};

export type ChatResult = { text: string; tokens: number; truncated: boolean };

type Health = { online: boolean; models: string[]; error: string | null };

const cache = globalThis as unknown as {
  __ripeHealth?: { at: number; baseUrl: string; value: Health };
  __ripeCaps?: Map<string, string[]>;
};

function trimUrl(baseUrl: string) {
  return baseUrl.replace(/\/+$/, "");
}

export async function checkHealth(baseUrl: string, maxAgeMs = 4000): Promise<Health> {
  const hit = cache.__ripeHealth;
  if (hit && hit.baseUrl === baseUrl && Date.now() - hit.at < maxAgeMs) return hit.value;
  let value: Health;
  try {
    const res = await fetch(`${trimUrl(baseUrl)}/api/tags`, {
      signal: AbortSignal.timeout(2500),
      cache: "no-store",
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = (await res.json()) as { models?: { name: string; capabilities?: string[] }[] };
    const models = (data.models ?? [])
      .filter((m) => !m.capabilities || m.capabilities.includes("completion"))
      .map((m) => m.name)
      .sort();
    value = { online: true, models, error: null };
  } catch (err) {
    value = { online: false, models: [], error: err instanceof Error ? err.message : String(err) };
  }
  cache.__ripeHealth = { at: Date.now(), baseUrl, value };
  return value;
}

export function forgetHealth() {
  cache.__ripeHealth = undefined;
}

export function hasModel(models: string[], model: string) {
  return models.includes(model) || models.includes(`${model}:latest`);
}

async function capabilities(baseUrl: string, model: string) {
  const caps = (cache.__ripeCaps ??= new Map());
  const key = `${baseUrl}|${model}`;
  const known = caps.get(key);
  if (known) return known;
  try {
    const res = await fetch(`${trimUrl(baseUrl)}/api/show`, {
      method: "POST",
      body: JSON.stringify({ model }),
      signal: AbortSignal.timeout(5000),
    });
    if (!res.ok) return [];
    const data = (await res.json()) as { capabilities?: string[] };
    const list = data.capabilities ?? [];
    caps.set(key, list);
    return list;
  } catch {
    return [];
  }
}

export async function chat(options: ChatOptions): Promise<ChatResult> {
  const { baseUrl, model, messages, format, numPredict, temperature = 0.5, signal, onProgress } = options;
  const caps = await capabilities(baseUrl, model);
  const body: Record<string, unknown> = {
    model,
    messages,
    stream: true,
    keep_alive: "10m",
    options: { num_predict: numPredict, temperature, num_ctx: 8192 },
  };
  if (format) body.format = format;
  if (caps.includes("thinking")) body.think = false;

  let res: Response;
  try {
    res = await fetch(`${trimUrl(baseUrl)}/api/chat`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
      signal,
    });
  } catch (err) {
    if (signal.aborted) throw signal.reason ?? err;
    forgetHealth();
    throw new OllamaOffline(err instanceof Error ? err.message : String(err));
  }

  if (res.status === 404) throw new ModelMissing(`Model ${model} not found in Ollama`);
  if (!res.ok || !res.body) {
    const detail = await res.text().catch(() => "");
    throw new Error(`Ollama responded ${res.status}${detail ? `: ${detail.slice(0, 200)}` : ""}`);
  }

  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let buffer = "";
  let text = "";
  let tokens = 0;
  let truncated = false;

  try {
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      let newline = buffer.indexOf("\n");
      while (newline >= 0) {
        const line = buffer.slice(0, newline).trim();
        buffer = buffer.slice(newline + 1);
        newline = buffer.indexOf("\n");
        if (!line) continue;
        const chunk = JSON.parse(line) as {
          error?: string;
          message?: { content?: string };
          done?: boolean;
          done_reason?: string;
          eval_count?: number;
        };
        if (chunk.error) throw new Error(chunk.error);
        if (chunk.message?.content) {
          text += chunk.message.content;
          tokens += 1;
          onProgress?.(text, tokens);
        }
        if (chunk.done) {
          tokens = chunk.eval_count ?? tokens;
          truncated = chunk.done_reason === "length";
        }
      }
    }
  } catch (err) {
    if (signal.aborted) throw signal.reason ?? err;
    if (err instanceof TypeError) {
      forgetHealth();
      throw new OllamaOffline(err.message);
    }
    throw err;
  }

  return { text, tokens, truncated };
}
