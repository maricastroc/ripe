import { db } from "@/lib/db";
import type { AgentMode } from "@/lib/types";

const memo = globalThis as unknown as {
  __ripeAgentState?: { mode: AgentMode; message: string | null; jobId: string | null; at: number };
};

export async function setAgentState(mode: AgentMode, message: string | null = null, jobId: string | null = null) {
  const last = memo.__ripeAgentState;
  const same = last && last.mode === mode && last.message === message && last.jobId === jobId;
  if (same && Date.now() - last.at < 10_000) return;
  memo.__ripeAgentState = { mode, message, jobId, at: Date.now() };
  const data = { state: mode, message, jobId, heartbeatAt: new Date() };
  await db.agentState.upsert({ where: { id: 1 }, create: { id: 1, ...data }, update: data });
}
