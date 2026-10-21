import type { ChatMessage } from "./ollama";
import { LIMITS } from "@/lib/limits";

const SYSTEM = `You are Ripe's agent. You work slowly and carefully on tasks a person decided to put off, so that they find something concrete when they come back.

Principles:
- Write in the same language the person used in their request.
- Never invent specific facts the person did not provide: names, dates, amounts, numbers, places, decisions, quotes or third-party data. When something like that is needed, write the marker [[TBD: short description of what is missing]] in its place.
- Deliver usable material, not generic advice on how to do it. Write as someone handing over the work.
- Your work is limited to writing and planning. Never claim you sent, booked, searched the web or executed anything.
- Be direct. No preambles, no sign-offs, no mention of being an AI.`;

export type Brief = {
  prompt: string;
  title: string;
  outcome: string | null;
  deliverable: string | null;
  context: string[];
  assumptions: string[];
  questions: { text: string; blocking: boolean; status: string; answer: string | null }[];
};

export type PlanLine = { position: number; title: string; status: string };
export type PriorWork = { title: string; content: string };

const OUTPUT_GUIDE: Record<string, string> = {
  outline: "a hierarchical skeleton with the sections and what each one should contain",
  draft: "flowing prose, ready for the person to edit",
  checklist: "a checklist with boxes (- [ ]) grouped by theme",
  schedule: "a plan in phases or days, as a list or table; unknown dates become markers",
  message: "the complete message, with a subject line if it is an email",
  notes: "notes organized in short bullet points",
  slides: "a slide-by-slide outline: a title for each slide, 2 to 4 points and a short speaker note",
};

function quote(text: string, max: number = LIMITS.maxPromptChars) {
  return `"""\n${text.slice(0, max)}\n"""`;
}

function list(items: string[], empty = "- (none)") {
  return items.length ? items.map((i) => `- ${i}`).join("\n") : empty;
}

function briefBlock(brief: Brief) {
  const answered = brief.questions.filter((q) => q.status === "answered" && q.answer);
  const open = brief.questions.filter((q) => q.status !== "answered");
  return [
    `Task: ${brief.title}`,
    `The person's original request:\n${quote(brief.prompt)}`,
    brief.outcome ? `Expected outcome: ${brief.outcome}` : null,
    `Facts provided by the person:\n${list(brief.context)}`,
    answered.length
      ? `The person's answers:\n${answered.map((q) => `- ${q.text}\n  Answer: ${q.answer}`).join("\n")}`
      : null,
    open.length ? `Still unanswered (use [[TBD: ...]] where needed):\n${list(open.map((q) => q.text))}` : null,
    brief.assumptions.length ? `Assumptions adopted:\n${list(brief.assumptions)}` : null,
  ]
    .filter(Boolean)
    .join("\n\n");
}

function clip(text: string, max: number) {
  if (text.length <= max) return text;
  return `${text.slice(0, max)}\n[...]`;
}

export function interpretMessages(prompt: string): ChatMessage[] {
  return [
    { role: "system", content: SYSTEM },
    {
      role: "user",
      content: `The person's request:
${quote(prompt)}

Interpret the request and answer in JSON with:
- title: a short title (up to 6 words) naming the task.
- deliverable: the main kind of result. One of: "document" (article, proposal, essay, report), "plan" (trip, project, event, study, routine), "presentation", "message" (email, letter, reply), "checklist", "other".
- outcome: one or two sentences describing the concrete result expected when the task is done.
- context: facts the person provided in the request. Empty list if none. Do not add anything.
- assumptions: up to 3 safe, generic assumptions you will adopt to move forward without asking.
- questions: up to ${LIMITS.maxQuestions} missing pieces of information only the person can provide. For each one: question (direct and short), why (why it matters, in a few words), essential (true only if, without it, the final version would be wrong or useless even with markers).

Simple names, dates and numbers are almost never essential: they become [[TBD: ...]] markers in the text. Usually there is at most one essential question, and often none. If the request is already clear, return few or no questions.`,
    },
  ];
}

export function planMessages(brief: Brief): ChatMessage[] {
  return [
    { role: "system", content: SYSTEM },
    {
      role: "user",
      content: `${briefBlock(brief)}

Kind of result: ${brief.deliverable ?? "other"}

Split the work into 3 to ${LIMITS.maxSteps} small steps that can be done with writing and planning alone. Each step produces a concrete, self-contained piece the person can review and reuse. The first steps prepare the ground (structure, key points, options); the last one delivers the most complete version of the main result, building on the previous ones. Missing information does not block steps: it becomes [[TBD: ...]] markers.

Answer in JSON with steps. For each step:
- title: a short name starting with a noun (e.g. "Proposal outline").
- description: one sentence saying what will be produced.
- output: one of "outline", "draft", "checklist", "schedule", "message", "notes", "slides".`,
    },
  ];
}

export function generateMessages(
  brief: Brief,
  plan: PlanLine[],
  current: { position: number; title: string; description: string; output: string },
  prior: PriorWork[],
): ChatMessage[] {
  const planText = plan
    .map(
      (p) =>
        `${p.position + 1}. ${p.title}${p.position === current.position ? "  <- current step" : p.status === "done" ? "  (done)" : ""}`,
    )
    .join("\n");
  const priorText = prior.length
    ? prior.map((p) => `### ${p.title}\n${clip(p.content, LIMITS.contextCharsPerArtifact)}`).join("\n\n")
    : "(nothing yet)";
  return [
    { role: "system", content: SYSTEM },
    {
      role: "user",
      content: `${briefBlock(brief)}

Work plan:
${planText}

Material already produced:
${priorText}

Now produce step ${current.position + 1}: "${current.title}".
What it delivers: ${current.description}
Format: ${OUTPUT_GUIDE[current.output] ?? "organized markdown"}.

Write in Markdown, starting straight with the content, without repeating the step name on the first line. Use headings (##), lists and tables when they help. At most about 600 words. Where specific information is missing, use [[TBD: ...]] instead of inventing it.`,
    },
  ];
}

export function reviewMessages(brief: Brief, work: PriorWork[]): ChatMessage[] {
  return [
    { role: "system", content: SYSTEM },
    {
      role: "user",
      content: `${briefBlock(brief)}

Material produced:
${work.map((w) => `### ${w.title}\n${clip(w.content, LIMITS.contextCharsPerArtifact)}`).join("\n\n")}

Review the work like a careful, honest editor. Answer in JSON with:
- verdict: "ready" (usable with small tweaks), "almost" (needs additions) or "draft" (a good base, still far from final).
- summary: two sentences on the state of the work.
- strengths: up to 3 strong points.
- issues: up to 4 concrete problems, each with where (which piece), problem and suggestion.
- missing: information that still has to come from the person, including pending [[TBD]] markers.
- nextActions: up to 4 next steps the person can do in a few minutes when they come back.
Point out any passage that seems invented without basis in the request or the answers.`,
    },
  ];
}

export function reviseMessages(brief: Brief, title: string, content: string, instruction: string): ChatMessage[] {
  return [
    { role: "system", content: SYSTEM },
    {
      role: "user",
      content: `${briefBlock(brief)}

Current material: "${title}"
${quote(content, 6000)}

Rewrite request: ${instruction}

Rewrite the complete material applying the request. Keep what doesn't need to change. Keep [[TBD: ...]] for what is still unknown and replace the markers that now have an answer. Answer only with the final Markdown.`,
    },
  ];
}

export function cleanMarkdown(text: string) {
  let out = text.trim();
  const fenced = out.match(/^```(?:markdown|md)?\s*\n([\s\S]*?)\n```$/i);
  if (fenced) out = fenced[1].trim();
  return out;
}
