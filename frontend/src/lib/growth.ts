import type { Messages } from "./i18n/messages/en";
import type { Fruit, Stage, StepPhase } from "./types";

export const STAGES: Stage[] = ["seed", "sprout", "leafing", "blossom", "fruit", "ripe"];

export function shownPhase(phase: StepPhase, waitsOnAnswer: boolean): StepPhase {
  return phase === "blocked" && !waitsOnAnswer ? "ready" : phase;
}

export function stageName(t: Messages, stage: Stage): string {
  return t.stages[stage]?.name ?? stage;
}

export function stageIndex(stage: Stage) {
  return STAGES.indexOf(stage);
}

export const FRUITS: Record<Fruit, { name: string; ripe: string; deep: string; mid: string; unripe: string }> = {
  persimmon: { name: "persimmon", ripe: "#E3672F", deep: "#B8461C", mid: "#DDB04A", unripe: "#9DB07A" },
  plum: { name: "plum", ripe: "#7A3A63", deep: "#4F1F3F", mid: "#C0738A", unripe: "#A3B47F" },
  lemon: { name: "lemon", ripe: "#E9C43F", deep: "#C29A1D", mid: "#CBCB58", unripe: "#A8BC72" },
  fig: { name: "fig", ripe: "#8E4E6B", deep: "#5D2E47", mid: "#B98063", unripe: "#9FB283" },
};

export function fruitForDeliverable(deliverable: string | null | undefined): Fruit {
  switch (deliverable) {
    case "document":
      return "plum";
    case "presentation":
      return "lemon";
    case "message":
      return "fig";
    default:
      return "persimmon";
  }
}
