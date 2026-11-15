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
  calm: 30_000,
  steady: 6_000,
  brisk: 1_000,
};

export const PACE_LABEL: Record<Pace, string> = {
  calm: "Calm: a 30-second rest between steps",
  steady: "Steady: a 6-second rest between steps",
  brisk: "Brisk: hardly any rest",
};

export const NUM_PREDICT = {
  interpret: 900,
  plan: 1100,
  generate: 2200,
  review: 1200,
  revise: 2400,
} as const;
