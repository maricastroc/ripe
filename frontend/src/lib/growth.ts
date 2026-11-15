import type { Fruit, Stage } from "./types";

export const STAGES: { id: Stage; name: string; hint: string }[] = [
  { id: "seed", name: "Seed", hint: "Planted. The agent hasn't read it yet" },
  { id: "sprout", name: "Sprout", hint: "The agent understood what you want" },
  { id: "leafing", name: "Leaves", hint: "The work is split into steps" },
  { id: "blossom", name: "Blossom", hint: "The first piece of work is ready" },
  { id: "fruit", name: "Fruit", hint: "Most of the work is done" },
  { id: "ripe", name: "Ripe", hint: "Everything written and reviewed" },
];

export function stageName(stage: Stage) {
  return STAGES.find((s) => s.id === stage)?.name ?? stage;
}

export function stageIndex(stage: Stage) {
  return STAGES.findIndex((s) => s.id === stage);
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
