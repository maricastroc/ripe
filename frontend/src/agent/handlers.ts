import type { Job } from "@/generated/prisma/client";
import { db } from "@/lib/db";
import { logEvent } from "@/lib/events";
import { fruitForDeliverable } from "@/lib/growth";
import { LIMITS, NUM_PREDICT } from "@/lib/limits";
import { countWords, extractGaps } from "@/lib/markers";
import type { JobType } from "@/lib/types";
import { chat } from "./ollama";
import {
  type Brief,
  cleanMarkdown,
  generateMessages,
  interpretMessages,
  planMessages,
  reviewMessages,
  reviseMessages,
} from "./prompts";
import { type Review, formatOf, interpretSchema, parseJson, planSchema, reviewSchema } from "./schemas";

export type JobContext = {
  model: string;
  baseUrl: string;
  signal: AbortSignal;
  progress: (text: string, tokens: number) => void;
};

export type JobOutcome = { tokens: number };

function parseList(raw: string): string[] {
  try {
    const value = JSON.parse(raw);
    return Array.isArray(value) ? value.map(String) : [];
  } catch {
    return [];
  }
}

async function loadBrief(taskId: string) {
  const task = await db.task.findUniqueOrThrow({
    where: { id: taskId },
    include: { questions: { orderBy: { position: "asc" } } },
  });
  const brief: Brief = {
    prompt: task.prompt,
    title: task.title,
    outcome: task.outcome,
    deliverable: task.deliverable,
    context: parseList(task.context),
    assumptions: parseList(task.assumptions),
    questions: task.questions.map((q) => ({ text: q.text, blocking: q.blocking, status: q.status, answer: q.answer })),
  };
  return { task, brief };
}

function gapNote(content: string) {
  const gaps = extractGaps(content).length;
  if (!gaps) return "";
  return ` Left ${gaps} ${gaps === 1 ? "gap" : "gaps"} marked instead of making things up.`;
}

async function interpret(job: Job, ctx: JobContext): Promise<JobOutcome> {
  const { task } = await loadBrief(job.taskId);
  const res = await chat({
    baseUrl: ctx.baseUrl,
    model: ctx.model,
    messages: interpretMessages(task.prompt),
    format: formatOf(interpretSchema),
    numPredict: NUM_PREDICT.interpret,
    temperature: 0.3,
    signal: ctx.signal,
    onProgress: ctx.progress,
  });
  const result = parseJson(interpretSchema, res.text);
  const questions = result.questions.slice(0, LIMITS.maxQuestions);

  await db.$transaction([
    db.task.update({
      where: { id: task.id },
      data: {
        title: result.title.trim().slice(0, 80) || task.title,
        deliverable: result.deliverable,
        outcome: result.outcome.trim(),
        context: JSON.stringify(result.context.slice(0, 8)),
        assumptions: JSON.stringify(result.assumptions.slice(0, 3)),
        fruit: fruitForDeliverable(result.deliverable),
        interpretedAt: new Date(),
      },
    }),
    ...questions.map((q, position) =>
      db.question.create({
        data: { taskId: task.id, position, text: q.question.trim(), why: q.why.trim(), blocking: q.essential },
      }),
    ),
  ]);

  await logEvent(task.id, "done", `Understood the request. ${result.outcome.trim()}`, job.id);
  if (questions.length) {
    const essential = questions.filter((q) => q.essential).length;
    await logEvent(
      task.id,
      "warn",
      `Noted ${questions.length} ${questions.length === 1 ? "question" : "questions"} only you can answer${
        essential ? ` (${essential} essential)` : ""
      }. I'll keep going with what I can in the meantime.`,
      job.id,
    );
  }
  return { tokens: res.tokens };
}

async function plan(job: Job, ctx: JobContext): Promise<JobOutcome> {
  const { task, brief } = await loadBrief(job.taskId);
  const res = await chat({
    baseUrl: ctx.baseUrl,
    model: ctx.model,
    messages: planMessages(brief),
    format: formatOf(planSchema),
    numPredict: NUM_PREDICT.plan,
    temperature: 0.4,
    signal: ctx.signal,
    onProgress: ctx.progress,
  });
  const result = parseJson(planSchema, res.text);
  const steps = result.steps.slice(0, LIMITS.maxSteps);
  const essential = task.questions.filter((q) => q.status === "open" && q.blocking).map((q) => q.id);

  await db.$transaction([
    ...steps.map((s, position) =>
      db.step.create({
        data: {
          taskId: task.id,
          position,
          title: s.title.trim().slice(0, 90),
          description: s.description.trim(),
          output: s.output,
          needs: JSON.stringify(position === steps.length - 1 ? essential : []),
        },
      }),
    ),
    db.task.update({ where: { id: task.id }, data: { plannedAt: new Date() } }),
  ]);

  await logEvent(
    task.id,
    "done",
    `Mapped out ${steps.length} steps: ${steps.map((s) => s.title.trim()).join(" → ")}.${
      essential.length
        ? " The early steps can go ahead with gaps marked. The final version waits for the essential answers."
        : ""
    }`,
    job.id,
  );
  return { tokens: res.tokens };
}

async function generate(job: Job, ctx: JobContext): Promise<JobOutcome> {
  if (!job.stepId) throw new Error("this writing job has no step");
  const { task, brief } = await loadBrief(job.taskId);
  const steps = await db.step.findMany({
    where: { taskId: task.id },
    orderBy: { position: "asc" },
    include: { artifact: true },
  });
  const step = steps.find((s) => s.id === job.stepId);
  if (!step) throw new Error("the step no longer exists");
  const prior = steps
    .filter((s) => s.position < step.position && s.artifact)
    .map((s) => ({ title: s.title, content: s.artifact!.content }));

  const res = await chat({
    baseUrl: ctx.baseUrl,
    model: ctx.model,
    messages: generateMessages(
      brief,
      steps.map((s) => ({ position: s.position, title: s.title, status: s.status })),
      step,
      prior,
    ),
    numPredict: NUM_PREDICT.generate,
    temperature: 0.6,
    signal: ctx.signal,
    onProgress: ctx.progress,
  });
  const content = cleanMarkdown(res.text);
  if (countWords(content) < 15) throw new Error("the generated text was empty or too short");

  const existing = step.artifact;
  await db.$transaction([
    existing
      ? db.artifact.update({
          where: { id: existing.id },
          data: {
            previousContent: existing.content,
            content,
            version: { increment: 1 },
            editedByUser: false,
            model: ctx.model,
            title: step.title,
          },
        })
      : db.artifact.create({
          data: { taskId: task.id, stepId: step.id, kind: "step", title: step.title, content, model: ctx.model },
        }),
    db.step.update({ where: { id: step.id }, data: { status: "done" } }),
  ]);

  await logEvent(
    task.id,
    "done",
    `Finished “${step.title}” (${countWords(content)} words).${gapNote(content)}${
      res.truncated ? " It hit the length limit, so check the ending." : ""
    }`,
    job.id,
  );
  return { tokens: res.tokens };
}

const VERDICT: Record<Review["verdict"], string> = {
  ready: "Ready to use, with small tweaks",
  almost: "Almost there, but it needs a few additions",
  draft: "A good base, but still a draft",
};

function reviewMarkdown(review: Review) {
  const parts = [`**Verdict:** ${VERDICT[review.verdict]}.`, review.summary.trim()];
  if (review.strengths.length) parts.push(`## What already works\n${review.strengths.map((s) => `- ${s}`).join("\n")}`);
  if (review.issues.length)
    parts.push(
      `## What to improve\n${review.issues
        .map((i) => `- **${i.where}:** ${i.problem}${i.suggestion ? ` → ${i.suggestion}` : ""}`)
        .join("\n")}`,
    );
  if (review.missing.length)
    parts.push(`## What still depends on you\n${review.missing.map((m) => `- ${m}`).join("\n")}`);
  if (review.nextActions.length)
    parts.push(`## Next steps when you come back\n${review.nextActions.map((a) => `- [ ] ${a}`).join("\n")}`);
  return parts.join("\n\n");
}

async function review(job: Job, ctx: JobContext): Promise<JobOutcome> {
  const { task, brief } = await loadBrief(job.taskId);
  const steps = await db.step.findMany({
    where: { taskId: task.id },
    orderBy: { position: "asc" },
    include: { artifact: true },
  });
  const work = steps.filter((s) => s.artifact).map((s) => ({ title: s.title, content: s.artifact!.content }));
  const res = await chat({
    baseUrl: ctx.baseUrl,
    model: ctx.model,
    messages: reviewMessages(brief, work),
    format: formatOf(reviewSchema),
    numPredict: NUM_PREDICT.review,
    temperature: 0.3,
    signal: ctx.signal,
    onProgress: ctx.progress,
  });
  const result = parseJson(reviewSchema, res.text);
  const content = reviewMarkdown({
    ...result,
    strengths: result.strengths.slice(0, 3),
    issues: result.issues.slice(0, 4),
    nextActions: result.nextActions.slice(0, 4),
  });

  const existing = await db.artifact.findFirst({ where: { taskId: task.id, kind: "review" } });
  await db.$transaction([
    existing
      ? db.artifact.update({
          where: { id: existing.id },
          data: {
            previousContent: existing.content,
            content,
            version: { increment: 1 },
            editedByUser: false,
            model: ctx.model,
          },
        })
      : db.artifact.create({
          data: { taskId: task.id, kind: "review", title: "Agent review", content, model: ctx.model },
        }),
    db.task.update({ where: { id: task.id }, data: { reviewedAt: new Date() } }),
  ]);

  await logEvent(task.id, "done", `Reviewed the whole set: ${VERDICT[result.verdict].toLowerCase()}.`, job.id);
  return { tokens: res.tokens };
}

async function revise(job: Job, ctx: JobContext): Promise<JobOutcome> {
  if (!job.artifactId) throw new Error("there's no piece to rewrite");
  const { task, brief } = await loadBrief(job.taskId);
  const artifact = await db.artifact.findUniqueOrThrow({ where: { id: job.artifactId } });
  const instruction =
    job.instruction?.trim() ||
    "Incorporate the person's latest answers and replace the [[TBD]] markers that now have an answer.";
  const res = await chat({
    baseUrl: ctx.baseUrl,
    model: ctx.model,
    messages: reviseMessages(brief, artifact.title, artifact.content, instruction),
    numPredict: NUM_PREDICT.revise,
    temperature: 0.5,
    signal: ctx.signal,
    onProgress: ctx.progress,
  });
  const content = cleanMarkdown(res.text);
  if (countWords(content) < 15) throw new Error("the rewrite was empty or too short");

  await db.artifact.update({
    where: { id: artifact.id },
    data: {
      previousContent: artifact.content,
      content,
      version: { increment: 1 },
      editedByUser: false,
      model: ctx.model,
    },
  });
  await logEvent(
    task.id,
    "done",
    `Rewrote “${artifact.title}”${job.instruction ? ` as asked: “${job.instruction.trim()}”` : " with your answers"}.${gapNote(content)}`,
    job.id,
  );
  return { tokens: res.tokens };
}

export const HANDLERS: Record<JobType, (job: Job, ctx: JobContext) => Promise<JobOutcome>> = {
  interpret,
  plan,
  generate,
  review,
  revise,
};
