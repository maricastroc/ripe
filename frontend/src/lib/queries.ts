import type { Prisma } from "@/generated/prisma/client";
import { checkHealth, hasModel } from "@/agent/ollama";
import { ensureWorker } from "@/agent/worker";
import { db } from "./db";
import { computeGrowth } from "./growth";
import { jobLabel } from "./labels";
import { LIMITS } from "./limits";
import { extractGaps } from "./markers";
import { getSettings } from "./settings";
import type {
  Activity,
  AgentMode,
  AgentStatus,
  ArtifactView,
  Deliverable,
  EventKind,
  Fruit,
  JobType,
  QuestionStatus,
  StepStatus,
  TaskDetail,
  TaskStatus,
  TaskSummary,
} from "./types";

const activeJobs = {
  where: { status: { in: ["queued", "running"] } },
  orderBy: [{ status: "desc" }, { createdAt: "asc" }],
  take: 1,
} satisfies Prisma.Task$jobsArgs;

const summaryInclude = {
  steps: {
    orderBy: { position: "asc" },
    select: { id: true, status: true, title: true, artifact: { select: { id: true } } },
  },
  questions: { select: { status: true, blocking: true } },
  artifacts: { select: { id: true, title: true } },
  jobs: activeJobs,
} satisfies Prisma.TaskInclude;

const detailInclude = {
  steps: { orderBy: { position: "asc" }, include: { artifact: { select: { id: true } } } },
  questions: { orderBy: { position: "asc" } },
  artifacts: { orderBy: { createdAt: "asc" } },
  events: { orderBy: { createdAt: "desc" }, take: 80 },
  jobs: activeJobs,
  _count: { select: { jobs: { where: { status: { not: "cancelled" } } } } },
} satisfies Prisma.TaskInclude;

type SummaryRow = Prisma.TaskGetPayload<{ include: typeof summaryInclude }>;
type DetailRow = Prisma.TaskGetPayload<{ include: typeof detailInclude }>;

function iso(d: Date | null | undefined) {
  return d ? d.toISOString() : null;
}

function parseList(raw: string): string[] {
  try {
    const value = JSON.parse(raw);
    return Array.isArray(value) ? value.map(String) : [];
  } catch {
    return [];
  }
}

function toActivity(row: SummaryRow | DetailRow): Activity | null {
  const job = row.jobs[0];
  if (!job) return null;
  const subject =
    job.type === "generate"
      ? row.steps.find((s) => s.id === job.stepId)?.title
      : job.type === "revise"
        ? row.artifacts.find((a) => a.id === job.artifactId)?.title
        : null;
  return {
    jobId: job.id,
    type: job.type as JobType,
    label: jobLabel(job.type, subject),
    status: job.status as "queued" | "running",
    attempts: job.attempts,
    maxAttempts: job.maxAttempts,
    startedAt: iso(job.startedAt),
    runAfter: job.runAfter.toISOString(),
    partial: job.status === "running" ? job.partial : null,
    stepId: job.stepId,
    artifactId: job.artifactId,
    lastError: job.error,
  };
}

function toSummary(row: SummaryRow | DetailRow): TaskSummary {
  const open = row.questions.filter((q) => q.status === "open");
  return {
    id: row.id,
    title: row.title,
    prompt: row.prompt,
    status: row.status as TaskStatus,
    fruit: (row.fruit as Fruit | null) ?? null,
    isExample: row.isExample,
    returnAt: iso(row.returnAt),
    createdAt: row.createdAt.toISOString(),
    ripeAt: iso(row.ripeAt),
    harvestedAt: iso(row.harvestedAt),
    stallReason: row.stallReason,
    growth: computeGrowth(row),
    leaves: row.steps.map((s) => ({
      id: s.id,
      status: s.status as StepStatus,
      artifactId: s.artifact?.id ?? null,
      title: s.title,
    })),
    openQuestions: open.length,
    essentialOpen: open.filter((q) => q.blocking).length,
    activity: toActivity(row),
  };
}

function toArtifact(a: DetailRow["artifacts"][number]): ArtifactView {
  return {
    id: a.id,
    stepId: a.stepId,
    kind: a.kind === "review" ? "review" : "step",
    title: a.title,
    content: a.content,
    hasPrevious: Boolean(a.previousContent),
    version: a.version,
    editedByUser: a.editedByUser,
    model: a.model,
    updatedAt: a.updatedAt.toISOString(),
    gaps: extractGaps(a.content),
  };
}

export async function listTasks(): Promise<TaskSummary[]> {
  const rows = await db.task.findMany({ include: summaryInclude, orderBy: { createdAt: "desc" } });
  return rows.map(toSummary);
}

export async function getTask(id: string): Promise<TaskDetail | null> {
  const row = await db.task.findUnique({ where: { id }, include: detailInclude });
  if (!row) return null;
  const number = await db.task.count({ where: { createdAt: { lte: row.createdAt } } });
  const artifacts = row.artifacts.map(toArtifact);
  const stepOrder = new Map(row.steps.map((s) => [s.id, s.position]));
  artifacts.sort((a, b) => {
    if (a.kind !== b.kind) return a.kind === "review" ? 1 : -1;
    return (stepOrder.get(a.stepId ?? "") ?? 0) - (stepOrder.get(b.stepId ?? "") ?? 0);
  });
  return {
    ...toSummary(row),
    number,
    deliverable: (row.deliverable as Deliverable | null) ?? null,
    outcome: row.outcome,
    context: parseList(row.context),
    assumptions: parseList(row.assumptions),
    steps: row.steps.map((s) => ({
      id: s.id,
      position: s.position,
      title: s.title,
      description: s.description,
      output: s.output,
      status: s.status as StepStatus,
      needs: parseList(s.needs),
      artifactId: s.artifact?.id ?? null,
    })),
    questions: row.questions.map((q) => ({
      id: q.id,
      text: q.text,
      why: q.why,
      blocking: q.blocking,
      answer: q.answer,
      status: q.status as QuestionStatus,
    })),
    artifacts,
    events: row.events.map((e) => ({
      id: e.id,
      kind: e.kind as EventKind,
      message: e.message,
      createdAt: e.createdAt.toISOString(),
    })),
    jobsUsed: row._count.jobs,
    jobBudget: LIMITS.jobBudget,
  };
}

export async function getAgentStatus(): Promise<AgentStatus> {
  ensureWorker();
  const settings = await getSettings();
  const [state, running, queued, health] = await Promise.all([
    db.agentState.findUnique({ where: { id: 1 } }),
    db.job.findFirst({
      where: { status: "running" },
      include: {
        task: {
          select: {
            id: true,
            title: true,
            steps: { select: { id: true, title: true } },
            artifacts: { select: { id: true, title: true } },
          },
        },
      },
    }),
    db.job.count({ where: { status: "queued", task: { status: "ripening" } } }),
    checkHealth(settings.baseUrl),
  ]);

  let mode = (state?.state as AgentMode | undefined) ?? "starting";
  let message = state?.message ?? null;
  const stale = !state || Date.now() - state.heartbeatAt.getTime() > 45_000;
  if (settings.paused) {
    mode = "paused";
    message = "Agent paused in settings.";
  } else if (running) {
    mode = "working";
    message = null;
  } else if (!health.online) {
    mode = "offline";
    message = `Couldn't reach Ollama at ${settings.baseUrl}.`;
  } else if (!hasModel(health.models, settings.model)) {
    mode = "model-missing";
    message = `Model ${settings.model} is not installed in Ollama.`;
  } else if (stale) {
    mode = "stopped";
    message = "The agent process is not responding.";
  } else if (["offline", "model-missing", "paused", "working", "stopped"].includes(mode)) {
    mode = "idle";
    message = null;
  }

  let current: AgentStatus["current"] = null;
  if (running) {
    const subject =
      running.type === "generate"
        ? running.task.steps.find((s) => s.id === running.stepId)?.title
        : running.type === "revise"
          ? running.task.artifacts.find((a) => a.id === running.artifactId)?.title
          : null;
    current = { taskId: running.task.id, taskTitle: running.task.title, label: jobLabel(running.type, subject) };
  }

  return {
    mode,
    message,
    online: health.online,
    models: health.models,
    settings,
    current,
    queued,
    heartbeatAt: iso(state?.heartbeatAt),
  };
}
