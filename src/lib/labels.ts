import type { JobType } from "./types";

export function jobLabel(type: JobType | string, subject?: string | null) {
  switch (type) {
    case "interpret":
      return "Reading the request carefully";
    case "plan":
      return "Mapping out the steps";
    case "generate":
      return subject ? `Writing “${subject}”` : "Writing";
    case "review":
      return "Reviewing everything produced";
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
