import { db } from "./db";
import type { Pace, Settings } from "./types";

const PACES: Pace[] = ["calmo", "constante", "ligeiro"];

export function defaultSettings(): Settings {
  return {
    model: process.env.OLLAMA_MODEL ?? "gemma4:e4b",
    baseUrl: process.env.OLLAMA_URL ?? "http://127.0.0.1:11434",
    pace: "constante",
    paused: false,
  };
}

export async function getSettings(): Promise<Settings> {
  const rows = await db.setting.findMany();
  const map = new Map(rows.map((r) => [r.key, r.value]));
  const base = defaultSettings();
  const pace = map.get("pace") as Pace | undefined;
  return {
    model: map.get("model") || base.model,
    baseUrl: map.get("baseUrl") || base.baseUrl,
    pace: pace && PACES.includes(pace) ? pace : base.pace,
    paused: map.get("paused") === "true",
  };
}

export async function updateSettings(patch: Partial<Settings>) {
  const entries = Object.entries(patch).filter(([, v]) => v !== undefined);
  await db.$transaction(
    entries.map(([key, value]) =>
      db.setting.upsert({
        where: { key },
        create: { key, value: String(value) },
        update: { value: String(value) },
      }),
    ),
  );
  return getSettings();
}
