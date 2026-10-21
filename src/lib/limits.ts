import type { Pace } from "./types";

export const LIMITS = {
  maxSteps: 5,
  maxQuestions: 4,
  jobBudget: 24,
  maxAttempts: 3,
  callTimeoutMs: 4 * 60 * 1000,
  leaseGraceMs: 30 * 1000,
  offlineRetryMs: 15 * 1000,
  maxPromptChars: 2000,
  contextCharsPerArtifact: 1800,
} as const;

export const PACE_MS: Record<Pace, number> = {
  calmo: 30_000,
  constante: 6_000,
  ligeiro: 1_000,
};

export const PACE_LABEL: Record<Pace, string> = {
  calmo: "Calmo — respira 30s entre etapas",
  constante: "Constante — respira 6s entre etapas",
  ligeiro: "Ligeiro — quase sem pausa",
};

export const NUM_PREDICT = {
  interpret: 900,
  plan: 1100,
  generate: 2200,
  review: 1200,
  revise: 2400,
} as const;
