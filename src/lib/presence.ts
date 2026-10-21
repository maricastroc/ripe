import type { PlantMood } from "@/components/plant/Plant";
import type { AgentStatus, TaskSummary } from "./types";

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
  if (task.isExample) return { text: "exemplo ilustrativo", tone: "muted" };
  const running = task.activity?.status === "running";
  switch (task.status) {
    case "waiting":
      return {
        text: task.essentialOpen > 1 ? `precisa de você · ${task.essentialOpen} respostas` : "precisa de você",
        tone: "accent",
      };
    case "stalled":
      return { text: "precisa de cuidado", tone: "accent" };
    case "dormant":
      return { text: "adormecida", tone: "muted" };
    case "harvested":
      return { text: "colhida", tone: "muted" };
    case "ripe":
      if (running && task.activity) return { text: lowerFirst(task.activity.label), tone: "leaf" };
      return { text: task.activity ? "madura · revisão na fila" : "madura", tone: "leaf" };
    default:
      if (running && task.activity) return { text: lowerFirst(task.activity.label), tone: "leaf" };
      if (agent.settings.paused) return { text: "agente pausado", tone: "ochre" };
      if (agent.mode === "offline") return { text: "esperando o Ollama", tone: "ochre" };
      if (agent.mode === "model-missing") return { text: "esperando o modelo", tone: "ochre" };
      if (task.activity?.lastError) return { text: "vai tentar de novo", tone: "ochre" };
      return { text: "na fila", tone: "muted" };
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
