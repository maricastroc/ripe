import { db } from "@/lib/db";
import { logEvent } from "@/lib/events";
import { LIMITS } from "@/lib/limits";
import type { JobType } from "@/lib/types";
import { wakeWorker } from "./signals";

function parseIds(raw: string): string[] {
  try {
    const value = JSON.parse(raw);
    return Array.isArray(value) ? value.filter((v): v is string => typeof v === "string") : [];
  } catch {
    return [];
  }
}

export async function enqueue(
  taskId: string,
  type: JobType,
  extra: { stepId?: string; artifactId?: string; instruction?: string } = {},
) {
  const job = await db.job.create({
    data: { taskId, type, maxAttempts: LIMITS.maxAttempts, ...extra },
  });
  wakeWorker();
  return job;
}

export async function jobsUsed(taskId: string) {
  return db.job.count({ where: { taskId, status: { not: "cancelled" } } });
}

export async function advance(taskId: string) {
  const task = await db.task.findUnique({
    where: { id: taskId },
    include: {
      steps: { orderBy: { position: "asc" } },
      questions: { orderBy: { position: "asc" } },
      jobs: { where: { status: { in: ["queued", "running"] } } },
    },
  });
  if (!task || task.isExample) return;

  const openIds = new Set(task.questions.filter((q) => q.status === "open").map((q) => q.id));
  const steps = [];
  for (const step of task.steps) {
    let status = step.status;
    if (status === "pending" || status === "blocked") {
      const blocked = parseIds(step.needs).some((id) => openIds.has(id));
      status = blocked ? "blocked" : "pending";
      if (status !== step.status) await db.step.update({ where: { id: step.id }, data: { status } });
    }
    steps.push({ ...step, status });
  }

  if (task.status !== "ripening" && task.status !== "waiting") return;

  if (task.jobs.length > 0) {
    if (task.status === "waiting") await db.task.update({ where: { id: taskId }, data: { status: "ripening" } });
    return;
  }

  let next: { type: JobType; stepId?: string } | null = null;
  if (!task.interpretedAt) next = { type: "interpret" };
  else if (!task.plannedAt) next = { type: "plan" };
  else {
    const pending = steps.find((s) => s.status === "pending");
    if (pending) next = { type: "generate", stepId: pending.id };
    else if (steps.length > 0 && steps.every((s) => s.status === "done") && !task.reviewedAt) next = { type: "review" };
  }

  if (next) {
    if ((await jobsUsed(taskId)) >= LIMITS.jobBudget) {
      await db.task.update({
        where: { id: taskId },
        data: { status: "stalled", stallReason: `This task used up its ${LIMITS.jobBudget} agent runs.` },
      });
      await logEvent(taskId, "error", `Stopped. This task has used all ${LIMITS.jobBudget} of its runs.`);
      return;
    }
    if (task.status !== "ripening") await db.task.update({ where: { id: taskId }, data: { status: "ripening" } });
    await enqueue(taskId, next.type, next.stepId ? { stepId: next.stepId } : {});
    return;
  }

  const blocked = steps.filter((s) => s.status === "blocked");
  if (blocked.length > 0) {
    if (task.status !== "waiting") {
      await db.task.update({ where: { id: taskId }, data: { status: "waiting" } });
      const needed = task.questions.filter(
        (q) => q.status === "open" && blocked.some((s) => parseIds(s.needs).includes(q.id)),
      );
      await logEvent(
        taskId,
        "warn",
        `I've done everything I can without you. To write ${new Intl.ListFormat("en").format(
          blocked.map((s) => `“${s.title}”`),
        )}, I need to know: ${needed.map((q) => q.text).join(" · ")}`,
      );
    }
    return;
  }

  if (task.reviewedAt) {
    await db.task.update({
      where: { id: taskId },
      data: { status: "ripe", ripeAt: task.ripeAt ?? new Date(), stallReason: null },
    });
    if (!task.ripeAt)
      await logEvent(taskId, "done", "Ripe. Everything is written and reviewed, ready for when you come back.");
  }
}
