import type { JobType } from "./types";

export function jobLabel(type: JobType | string, subject?: string | null) {
  switch (type) {
    case "interpret":
      return "Reading your request";
    case "plan":
      return "Mapping out the steps";
    case "generate":
      return subject ? `Writing “${subject}”` : "Writing";
    case "review":
      return "Reviewing the whole set";
    case "revise":
      return subject ? `Rewriting “${subject}”` : "Rewriting";
    default:
      return "Working";
  }
}

export const OUTPUT_LABEL: Record<string, string> = {
  outline: "outline",
  draft: "draft",
  checklist: "checklist",
  schedule: "schedule",
  message: "message",
  notes: "notes",
  slides: "slide outline",
  review: "review",
};

export const DELIVERABLE_LABEL: Record<string, string> = {
  document: "Document",
  plan: "Plan",
  presentation: "Presentation",
  message: "Message",
  checklist: "Checklist",
  other: "Written piece",
};
