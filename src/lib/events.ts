import { db } from "./db";
import type { EventKind } from "./types";

export async function logEvent(taskId: string, kind: EventKind, message: string, jobId?: string) {
  try {
    await db.event.create({ data: { taskId, kind, message, jobId } });
  } catch {
    return;
  }
}
