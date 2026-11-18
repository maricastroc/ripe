import type { Messages } from "./i18n/messages/en";
import type { RunKind } from "./types";

function runLabel(a: Messages["activity"], kind: RunKind, subject?: string | null) {
  switch (kind) {
    case "interpret":
      return a.interpret;
    case "plan":
      return a.plan;
    case "generate":
      return subject ? a.generating(subject) : a.generate;
    case "review":
      return a.review;
    case "revise":
      return subject ? a.revising(subject) : a.revise;
    default:
      return a.working;
  }
}

export function activityLabel(t: Messages, kind: RunKind, subject?: string | null) {
  return runLabel(t.activity, kind, subject);
}

export function stepLabel(t: Messages, kind: RunKind, subject?: string | null) {
  return runLabel(t.rings.steps, kind, subject);
}

export function outputLabel(t: Messages, output: string): string {
  return (t.outputs as Record<string, string>)[output] ?? output;
}

export function deliverableLabel(t: Messages, deliverable: string): string {
  return (t.deliverables as Record<string, string>)[deliverable] ?? deliverable;
}
