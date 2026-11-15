import { advance, enqueue, jobsUsed } from "@/agent/scheduler";
import { cancelRunning } from "@/agent/signals";
import { db } from "./db";
import { logEvent } from "./events";
import { returnWhen } from "./format";
import { LIMITS } from "./limits";
import { extractGaps } from "./markers";

export class NotFound extends Error {
  name = "NotFound";
}

export class Invalid extends Error {
  name = "Invalid";
}

function provisionalTitle(prompt: string) {
  const first = prompt.trim().split("\n")[0].replace(/\s+/g, " ");
  return first.length > 64 ? `${first.slice(0, 61).trimEnd()}…` : first;
}

async function findTask(id: string) {
  const task = await db.task.findUnique({ where: { id } });
  if (!task) throw new NotFound("This task doesn't exist anymore.");
  return task;
}

async function stopWork(taskId: string) {
  await db.job.updateMany({
    where: { taskId, status: "queued" },
    data: { status: "cancelled", finishedAt: new Date() },
  });
  cancelRunning(taskId);
}

async function ensureBudget(taskId: string, needed: number) {
  if ((await jobsUsed(taskId)) + needed > LIMITS.jobBudget) {
    throw new Invalid(`This task doesn't have enough agent runs left. Each task gets ${LIMITS.jobBudget}.`);
  }
}

export async function createTask(prompt: string, options: { defer: boolean; returnAt?: Date }) {
  const clean = prompt.trim().slice(0, LIMITS.maxPromptChars);
  if (clean.length < 3) throw new Invalid("Write a little more about the task.");
  const task = await db.task.create({
    data: { prompt: clean, title: provisionalTitle(clean), status: "dormant", returnAt: options.returnAt },
  });
  await logEvent(task.id, "user", "Planted the seed.");
  if (options.defer) await deferTask(task.id, options.returnAt);
  return task.id;
}

export async function deferTask(id: string, returnAt?: Date) {
  const task = await findTask(id);
  if (task.isExample) throw new Invalid("The agent doesn't work on examples.");
  if (task.status === "harvested") throw new Invalid("This task has already been harvested.");
  if (task.status === "ripe") throw new Invalid("This task is already ripe.");
  await db.task.update({
    where: { id },
    data: {
      status: "ripening",
      deferredAt: new Date(),
      returnAt: returnAt ?? task.returnAt,
      stallReason: null,
    },
  });
  const when = returnAt ? returnWhen(returnAt.toISOString()) : null;
  await logEvent(
    id,
    "user",
    task.status === "stalled"
      ? "Asked the agent to try again. It picks up where it left off."
      : when
        ? `Put it off, planning to be back ${when}. The agent starts now, at its own pace.`
        : "Resumed. The agent picks up where it left off.",
  );
  await advance(id);
}

export async function pauseTask(id: string) {
  const task = await findTask(id);
  if (task.status !== "ripening" && task.status !== "waiting" && task.status !== "stalled") return;
  await db.task.update({ where: { id }, data: { status: "dormant" } });
  await stopWork(id);
  await logEvent(id, "user", "Paused. Everything so far is saved, and nothing runs until you resume.");
}

export async function harvestTask(id: string) {
  await findTask(id);
  await db.task.update({ where: { id }, data: { status: "harvested", harvestedAt: new Date() } });
  await stopWork(id);
  await logEvent(id, "user", "Harvested. Everything stays here to read.");
}

export async function replantTask(id: string) {
  const task = await findTask(id);
  if (task.status !== "harvested") return;
  await db.task.update({
    where: { id },
    data: { status: task.reviewedAt ? "ripe" : "dormant", harvestedAt: null },
  });
  await logEvent(id, "user", "Replanted in the greenhouse.");
}

export async function renameTask(id: string, title: string) {
  const clean = title.trim().slice(0, 80);
  if (!clean) throw new Invalid("The title can't be empty.");
  await db.task.update({ where: { id }, data: { title: clean } });
}

export async function deleteTask(id: string) {
  await findTask(id);
  cancelRunning(id);
  await db.task.delete({ where: { id } });
}

export async function answerQuestion(questionId: string, answer: string) {
  const clean = answer.trim().slice(0, 1200);
  if (!clean) throw new Invalid("Write an answer first.");
  const question = await db.question.findUnique({ where: { id: questionId } });
  if (!question) throw new NotFound("That question doesn't exist anymore.");
  await db.question.update({
    where: { id: questionId },
    data: { answer: clean, status: "answered", answeredAt: new Date() },
  });
  await logEvent(question.taskId, "user", `Answered “${question.text}”: ${clean}`);
  await advance(question.taskId);
  return question.taskId;
}

export async function skipQuestion(questionId: string) {
  const question = await db.question.findUnique({ where: { id: questionId } });
  if (!question) throw new NotFound("That question doesn't exist anymore.");
  await db.question.update({ where: { id: questionId }, data: { status: "skipped", answeredAt: new Date() } });
  await logEvent(
    question.taskId,
    "user",
    `Chose to go on without “${question.text}”. It stays marked as a gap in the text.`,
  );
  await advance(question.taskId);
  return question.taskId;
}

export async function reopenQuestion(questionId: string) {
  const question = await db.question.findUnique({ where: { id: questionId } });
  if (!question) throw new NotFound("That question doesn't exist anymore.");
  await db.question.update({ where: { id: questionId }, data: { status: "open", answer: null, answeredAt: null } });
  return question.taskId;
}

export async function editArtifact(id: string, content: string) {
  const artifact = await db.artifact.findUnique({ where: { id } });
  if (!artifact) throw new NotFound("That piece doesn't exist anymore.");
  if (content === artifact.content) return artifact.taskId;
  await db.artifact.update({ where: { id }, data: { content: content.slice(0, 60_000), editedByUser: true } });
  if (!artifact.editedByUser) await logEvent(artifact.taskId, "user", `Started editing “${artifact.title}”.`);
  return artifact.taskId;
}

export async function restoreArtifact(id: string) {
  const artifact = await db.artifact.findUnique({ where: { id } });
  if (!artifact) throw new NotFound("That piece doesn't exist anymore.");
  if (artifact.previousContent === null) throw new Invalid("There is no previous version.");
  await db.artifact.update({
    where: { id },
    data: { content: artifact.previousContent, previousContent: artifact.content, editedByUser: true },
  });
  await logEvent(artifact.taskId, "user", `Went back to the previous version of “${artifact.title}”.`);
  return artifact.taskId;
}

async function startRevisions(taskId: string, items: { id: string; title: string }[], instruction?: string) {
  const task = await findTask(taskId);
  if (task.isExample) throw new Invalid("The agent doesn't work on examples.");
  if (task.status === "harvested") throw new Invalid("Replant the task before asking for rewrites.");
  await ensureBudget(taskId, items.length);
  const busy = await db.job.findMany({
    where: { taskId, type: "revise", status: { in: ["queued", "running"] } },
    select: { artifactId: true },
  });
  const pending = new Set(busy.map((b) => b.artifactId));
  const fresh = items.filter((i) => !pending.has(i.id));
  if (!fresh.length) return 0;
  if (task.status !== "ripening")
    await db.task.update({ where: { id: taskId }, data: { status: "ripening", stallReason: null } });
  for (const item of fresh) await enqueue(taskId, "revise", { artifactId: item.id, instruction });
  return fresh.length;
}

export async function reviseArtifact(id: string, instruction: string) {
  const artifact = await db.artifact.findUnique({ where: { id } });
  if (!artifact) throw new NotFound("That piece doesn't exist anymore.");
  const clean = instruction.trim().slice(0, 500);
  if (!clean) throw new Invalid("Say what you want to change.");
  const count = await startRevisions(artifact.taskId, [artifact], clean);
  if (count) await logEvent(artifact.taskId, "user", `Asked for a rewrite of “${artifact.title}”: ${clean}`);
  return artifact.taskId;
}

export async function refreshWithAnswers(taskId: string) {
  const artifacts = await db.artifact.findMany({ where: { taskId, kind: "step" } });
  const withGaps = artifacts.filter((a) => extractGaps(a.content).length > 0);
  if (!withGaps.length) throw new Invalid("None of the pieces has gaps left to fill.");
  const count = await startRevisions(taskId, withGaps);
  if (count) {
    await logEvent(
      taskId,
      "user",
      `Asked the agent to update ${count === 1 ? "one piece" : `${count} pieces`} with your answers.`,
    );
  }
  return count;
}
