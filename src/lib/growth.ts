import type { Fruit, Growth, Stage, StepStatus } from "./types";

type GrowthInput = {
  interpretedAt: Date | string | null;
  plannedAt: Date | string | null;
  reviewedAt: Date | string | null;
  steps: { status: StepStatus | string }[];
};

export function computeGrowth(task: GrowthInput): Growth {
  const stepsTotal = task.steps.length;
  const stepsDone = task.steps.filter((s) => s.status === "done").length;

  let stage: Stage = "seed";
  if (task.interpretedAt) stage = "sprout";
  if (task.plannedAt && stepsTotal > 0) stage = "leafing";
  if (stepsDone >= 1) stage = "blossom";
  if (stepsTotal > 0 && stepsDone >= Math.max(2, Math.ceil(stepsTotal / 2))) stage = "fruit";
  if (task.reviewedAt) stage = "ripe";

  const maturity =
    (task.interpretedAt ? 0.12 : 0) +
    (task.plannedAt ? 0.13 : 0) +
    (stepsTotal ? 0.6 * (stepsDone / stepsTotal) : 0) +
    (task.reviewedAt ? 0.15 : 0);

  return { stage, maturity: Math.min(1, maturity), stepsDone, stepsTotal };
}

export const STAGES: { id: Stage; name: string; hint: string }[] = [
  { id: "seed", name: "Semente", hint: "Plantada, ainda sem leitura do pedido" },
  { id: "sprout", name: "Broto", hint: "O agente entendeu o que você quer" },
  { id: "leafing", name: "Folhas", hint: "Existe um caminho em etapas" },
  { id: "blossom", name: "Flor", hint: "O primeiro material ficou pronto" },
  { id: "fruit", name: "Fruto", hint: "A maior parte do trabalho está feita" },
  { id: "ripe", name: "Madura", hint: "Tudo produzido e revisado" },
];

export function stageName(stage: Stage) {
  return STAGES.find((s) => s.id === stage)?.name ?? stage;
}

export function stageIndex(stage: Stage) {
  return STAGES.findIndex((s) => s.id === stage);
}

export const FRUITS: Record<Fruit, { name: string; ripe: string; deep: string; mid: string; unripe: string }> = {
  caqui: { name: "caqui", ripe: "#E3672F", deep: "#B8461C", mid: "#DDB04A", unripe: "#9DB07A" },
  ameixa: { name: "ameixa", ripe: "#7A3A63", deep: "#4F1F3F", mid: "#C0738A", unripe: "#A3B47F" },
  limao: { name: "limão-siciliano", ripe: "#E9C43F", deep: "#C29A1D", mid: "#CBCB58", unripe: "#A8BC72" },
  figo: { name: "figo", ripe: "#8E4E6B", deep: "#5D2E47", mid: "#B98063", unripe: "#9FB283" },
};

export function fruitForDeliverable(deliverable: string | null | undefined): Fruit {
  switch (deliverable) {
    case "texto":
      return "ameixa";
    case "apresentacao":
      return "limao";
    case "mensagem":
      return "figo";
    default:
      return "caqui";
  }
}
