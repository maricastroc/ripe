import { formatClock } from "./format";
import type { I18n } from "./i18n";
import type { Messages } from "./i18n/messages/en";
import { activityLabel } from "./labels";
import type { Activity, AgentStatus, TaskSummary } from "./types";

export type PlantMood = "working" | "waiting" | "resting" | "dormant" | "stalled" | "idle" | "harvested";

export type Tone = "accent" | "leaf" | "muted" | "ochre";

export function moodOf(task: TaskSummary): PlantMood {
  const running = task.activity?.running === true;
  switch (task.status) {
    case "dormant":
      return "dormant";
    case "waiting":
      return "waiting";
    case "stalled":
      return "stalled";
    case "harvested":
      return "harvested";
    case "ripe":
      return running ? "working" : "idle";
    default:
      return running ? "working" : "resting";
  }
}

export function resumeHint(i18n: I18n, agent: Pick<AgentStatus, "resumesAt">, now = Date.now()): string | null {
  if (!agent.resumesAt) return null;
  const at = new Date(agent.resumesAt).getTime();
  if (Number.isNaN(at)) return null;
  return at - now < 90_000 ? i18n.t.rest.soon : i18n.t.rest.around(formatClock(i18n, at));
}

export function restText(i18n: I18n, agent: AgentStatus): { short: string; long: string } {
  const hint = resumeHint(i18n, agent);
  const rest = i18n.t.rest;
  const kind = agent.rest === "budget" ? rest.budget : agent.rest === "capacity" ? rest.capacity : rest.quota;
  return { short: kind.short(hint), long: kind.long(hint) };
}

function lowerFirst(text: string) {
  return text.charAt(0).toLowerCase() + text.slice(1);
}

function doing(t: Messages, activity: Activity) {
  return lowerFirst(activityLabel(t, activity.kind, activity.subject));
}

export function statusLine({ t }: I18n, task: TaskSummary, agent: AgentStatus): { text: string; tone: Tone } {
  const s = t.status;
  if (task.isExample) return { text: s.example, tone: "muted" };
  const running = task.activity?.running === true;
  switch (task.status) {
    case "waiting":
      return { text: s.needsAnswers(task.essentialOpen), tone: "accent" };
    case "stalled":
      return { text: s.stalled, tone: "accent" };
    case "dormant":
      return { text: s.dormant, tone: "muted" };
    case "harvested":
      return { text: s.harvested, tone: "muted" };
    case "ripe":
      if (running && task.activity) return { text: doing(t, task.activity), tone: "leaf" };
      if (task.activity) return { text: s.rewriteQueued, tone: "leaf" };
      if (task.openQuestions) return { text: s.optionalAnswers(task.openQuestions), tone: "leaf" };
      return { text: s.readyToReview, tone: "leaf" };
    default:
      if (running && task.activity) return { text: doing(t, task.activity), tone: "leaf" };
      if (agent.mode === "paused") return { text: s.agentPaused, tone: "ochre" };
      if (agent.mode === "resting") return { text: s.resting, tone: "ochre" };
      if (agent.mode === "halted") return { text: s.waitingModel, tone: "ochre" };
      if (agent.mode === "offline")
        return { text: agent.driver === "ollama" ? s.waitingOllama : s.waitingModel, tone: "ochre" };
      if (agent.mode === "model-missing") return { text: s.waitingModel, tone: "ochre" };
      if (task.activity?.lastError) return { text: s.willRetry, tone: "ochre" };
      return { text: s.queued, tone: "muted" };
  }
}

export const TONE_CLASS: Record<Tone, string> = {
  accent: "text-accent-ink",
  leaf: "text-leaf-dark",
  muted: "text-ink-3",
  ochre: "text-ochre",
};

export const TONE_DOT: Record<Tone, string> = {
  accent: "bg-accent",
  leaf: "bg-leaf",
  muted: "bg-line-2",
  ochre: "bg-ochre",
};

export function awaitsAnswer(task: Pick<TaskSummary, "status" | "essentialOpen" | "growth">): boolean {
  return (
    task.status === "waiting" &&
    task.essentialOpen > 0 &&
    task.growth.stepsTotal > 0 &&
    task.growth.stepsDone === task.growth.stepsTotal
  );
}

export type WorkFocus =
  { kind: "step"; stepId: string } | { kind: "apex" } | { kind: "seed" } | { kind: "roots" } | null;

export function focusOf(task: Pick<TaskSummary, "activity" | "leaves">): WorkFocus {
  const a = task.activity;
  if (!a || !a.running) return null;
  switch (a.kind) {
    case "interpret":
      return { kind: "seed" };
    case "plan":
      return { kind: "roots" };
    case "generate":
      return a.stepId ? { kind: "step", stepId: a.stepId } : null;
    case "revise": {
      const leaf = task.leaves.find((l) => l.artifactId === a.artifactId);
      return leaf ? { kind: "step", stepId: leaf.id } : { kind: "apex" };
    }
    case "review":
      return { kind: "apex" };
    default:
      return null;
  }
}
