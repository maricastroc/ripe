import type { AgentStatus, TaskSummary } from "./types";

export type PlantMood = "working" | "waiting" | "resting" | "dormant" | "stalled" | "idle" | "harvested";

export type Tone = "accent" | "leaf" | "muted" | "ochre";

export function moodOf(task: TaskSummary): PlantMood {
  const running = task.activity?.status === "running";
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

function lowerFirst(text: string) {
  return text.charAt(0).toLowerCase() + text.slice(1);
}

export function statusLine(task: TaskSummary, agent: AgentStatus): { text: string; tone: Tone } {
  if (task.isExample) return { text: "illustrative example", tone: "muted" };
  const running = task.activity?.status === "running";
  switch (task.status) {
    case "waiting":
      return {
        text: task.essentialOpen > 1 ? `needs ${task.essentialOpen} answers from you` : "needs your answer",
        tone: "accent",
      };
    case "stalled":
      return { text: "needs some care", tone: "accent" };
    case "dormant":
      return { text: "dormant", tone: "muted" };
    case "harvested":
      return { text: "harvested", tone: "muted" };
    case "ripe":
      if (running && task.activity) return { text: lowerFirst(task.activity.label), tone: "leaf" };
      if (task.activity) return { text: "ripe · rewrite queued", tone: "leaf" };
      if (task.openQuestions)
        return {
          text: `ripe · ${task.openQuestions} optional ${task.openQuestions === 1 ? "answer" : "answers"}`,
          tone: "leaf",
        };
      return { text: "ripe · ready to review", tone: "leaf" };
    default:
      if (running && task.activity) return { text: lowerFirst(task.activity.label), tone: "leaf" };
      if (agent.settings.paused) return { text: "agent paused", tone: "ochre" };
      if (agent.mode === "offline") return { text: "waiting for Ollama", tone: "ochre" };
      if (agent.mode === "model-missing") return { text: "waiting for the model", tone: "ochre" };
      if (task.activity?.lastError) return { text: "will try again", tone: "ochre" };
      return { text: "in the queue", tone: "muted" };
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

export type WorkFocus =
  { kind: "step"; stepId: string } | { kind: "apex" } | { kind: "seed" } | { kind: "roots" } | null;

export function focusOf(task: Pick<TaskSummary, "activity" | "leaves">): WorkFocus {
  const a = task.activity;
  if (!a || a.status !== "running") return null;
  switch (a.type) {
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
