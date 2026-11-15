import type { TaskDetail } from "@/lib/types";

export type Live = {
  attemptId: string;
  attempt: number;
  text: string;
  length: number;
  since: number;
};

export type LiveDelta = {
  runId: string;
  attemptId: string;
  attempt: number;
  offset: number;
  delta: string;
};

export function codePoints(text: string) {
  return Array.from(text);
}

export function fromSnapshot(current: Live | null, task: Pick<TaskDetail, "activity" | "version">): Live | null {
  const shown = task.activity?.running ? task.activity.live : null;
  if (!shown) return current && task.version > current.since ? null : current;
  if (!current || current.attemptId !== shown.attemptId) {
    return {
      attemptId: shown.attemptId,
      attempt: shown.attempt,
      text: shown.text,
      length: shown.length,
      since: task.version,
    };
  }
  const since = Math.max(current.since, task.version);
  if (shown.length > current.length) return { ...current, text: shown.text, length: shown.length, since };
  return since === current.since ? current : { ...current, since };
}

export function withDelta(current: Live | null, delta: LiveDelta): { live: Live | null; resync: boolean } {
  if (!current || current.attemptId !== delta.attemptId) return { live: current, resync: true };
  if (delta.offset > current.length) return { live: current, resync: true };
  const added = codePoints(delta.delta);
  const end = delta.offset + added.length;
  if (end <= current.length) return { live: current, resync: false };
  const tail = added.slice(current.length - delta.offset).join("");
  return { live: { ...current, text: current.text + tail, length: end }, resync: false };
}

export class LiveStore {
  private live: Live | null;
  private readonly listeners = new Set<() => void>();

  constructor(task: Pick<TaskDetail, "activity" | "version">) {
    this.live = fromSnapshot(null, task);
  }

  subscribe = (listener: () => void) => {
    this.listeners.add(listener);
    return () => {
      this.listeners.delete(listener);
    };
  };

  current = () => this.live;

  snapshot(task: Pick<TaskDetail, "activity" | "version">) {
    this.set(fromSnapshot(this.live, task));
  }

  delta(delta: LiveDelta) {
    const next = withDelta(this.live, delta);
    this.set(next.live);
    return next.resync;
  }

  private set(next: Live | null) {
    if (next === this.live) return;
    this.live = next;
    for (const listener of this.listeners) listener();
  }
}

export type TaskChange = { taskId: string; version: number; deleted: boolean };

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

export function asDelta(value: unknown): LiveDelta | null {
  if (!isRecord(value)) return null;
  const { runId, attemptId, attempt, offset, delta } = value;
  if (typeof runId !== "string" || typeof attemptId !== "string" || typeof delta !== "string") return null;
  if (!Number.isInteger(attempt) || !Number.isInteger(offset) || (offset as number) < 0) return null;
  return { runId, attemptId, attempt: attempt as number, offset: offset as number, delta };
}

export function asChange(value: unknown): TaskChange | null {
  if (!isRecord(value)) return null;
  const { taskId, version, deleted } = value;
  if (typeof taskId !== "string" || !Number.isInteger(version) || typeof deleted !== "boolean") return null;
  return { taskId, version: version as number, deleted };
}
