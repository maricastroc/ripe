import type { Pace } from "./types";

export const LIMITS = {
  maxSteps: 5,
  maxAttempts: 3,
} as const;

export const PACE_LABEL: Record<Exclude<Pace, "instant">, string> = {
  calm: "Calm: a 30-second rest between steps",
  steady: "Steady: a 6-second rest between steps",
  brisk: "Brisk: hardly any rest",
};
