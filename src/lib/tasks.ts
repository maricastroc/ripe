import { advance, enqueue, jobsUsed } from "@/agent/scheduler";
import { cancelRunning } from "@/agent/signals";
import { db } from "./db";
import { logEvent } from "./events";
import { formatReturn } from "./format";
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
  if (!task) throw new NotFound("Tarefa não encontrada");
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
    throw new Invalid(`Esta tarefa já usou quase todo o limite de ${LIMITS.jobBudget} execuções do agente.`);
  }
}

export async function createTask(prompt: string, options: { defer: boolean; returnAt?: Date }) {
  const clean = prompt.trim().slice(0, LIMITS.maxPromptChars);
  if (clean.length < 3) throw new Invalid("Escreva um pouco mais sobre a tarefa.");
  const task = await db.task.create({
    data: { prompt: clean, title: provisionalTitle(clean), status: "dormant", returnAt: options.returnAt },
  });
  await logEvent(task.id, "user", "Semente plantada.");
  if (options.defer) await deferTask(task.id, options.returnAt);
  return task.id;
}

export async function deferTask(id: string, returnAt?: Date) {
  const task = await findTask(id);
  if (task.isExample) throw new Invalid("Exemplos não são processados pelo agente.");
  if (task.status === "harvested") throw new Invalid("Esta tarefa já foi colhida.");
  if (task.status === "ripe") throw new Invalid("Esta tarefa já está madura.");
  await db.task.update({
    where: { id },
    data: {
      status: "ripening",
      deferredAt: new Date(),
      returnAt: returnAt ?? task.returnAt,
      stallReason: null,
    },
  });
  const when = returnAt ? formatReturn(returnAt.toISOString()) : null;
  await logEvent(
    id,
    "user",
    task.status === "stalled"
      ? "Você pediu para eu tentar de novo. Retomo de onde parei."
      : when
        ? `Adiada — ${when}. Começo a trabalhar agora, no meu ritmo.`
        : "Retomada. Volto a trabalhar de onde parei.",
  );
  await advance(id);
}

export async function pauseTask(id: string) {
  const task = await findTask(id);
  if (task.status !== "ripening" && task.status !== "waiting" && task.status !== "stalled") return;
  await db.task.update({ where: { id }, data: { status: "dormant" } });
  await stopWork(id);
  await logEvent(id, "user", "Pausada. Guardei tudo o que já estava feito; nada roda até você retomar.");
}

export async function harvestTask(id: string) {
  await findTask(id);
  await db.task.update({ where: { id }, data: { status: "harvested", harvestedAt: new Date() } });
  await stopWork(id);
  await logEvent(id, "user", "Colhida. Os materiais continuam guardados aqui.");
}

export async function replantTask(id: string) {
  const task = await findTask(id);
  if (task.status !== "harvested") return;
  await db.task.update({
    where: { id },
    data: { status: task.reviewedAt ? "ripe" : "dormant", harvestedAt: null },
  });
  await logEvent(id, "user", "Voltou para o parapeito.");
}

export async function renameTask(id: string, title: string) {
  const clean = title.trim().slice(0, 80);
  if (!clean) throw new Invalid("O título não pode ficar vazio.");
  await db.task.update({ where: { id }, data: { title: clean } });
}

export async function deleteTask(id: string) {
  await findTask(id);
  cancelRunning(id);
  await db.task.delete({ where: { id } });
}

export async function answerQuestion(questionId: string, answer: string) {
  const clean = answer.trim().slice(0, 1200);
  if (!clean) throw new Invalid("A resposta está vazia.");
  const question = await db.question.findUnique({ where: { id: questionId } });
  if (!question) throw new NotFound("Pergunta não encontrada");
  await db.question.update({
    where: { id: questionId },
    data: { answer: clean, status: "answered", answeredAt: new Date() },
  });
  await logEvent(question.taskId, "user", `Você respondeu “${question.text}”: ${clean}`);
  await advance(question.taskId);
  return question.taskId;
}

export async function skipQuestion(questionId: string) {
  const question = await db.question.findUnique({ where: { id: questionId } });
  if (!question) throw new NotFound("Pergunta não encontrada");
  await db.question.update({ where: { id: questionId }, data: { status: "skipped", answeredAt: new Date() } });
  await logEvent(
    question.taskId,
    "user",
    `Você preferiu seguir sem “${question.text}”. Fica marcado como lacuna no texto.`,
  );
  await advance(question.taskId);
  return question.taskId;
}

export async function reopenQuestion(questionId: string) {
  const question = await db.question.findUnique({ where: { id: questionId } });
  if (!question) throw new NotFound("Pergunta não encontrada");
  await db.question.update({ where: { id: questionId }, data: { status: "open", answer: null, answeredAt: null } });
  return question.taskId;
}

export async function editArtifact(id: string, content: string) {
  const artifact = await db.artifact.findUnique({ where: { id } });
  if (!artifact) throw new NotFound("Material não encontrado");
  if (content === artifact.content) return artifact.taskId;
  await db.artifact.update({ where: { id }, data: { content: content.slice(0, 60_000), editedByUser: true } });
  if (!artifact.editedByUser) await logEvent(artifact.taskId, "user", `Você começou a editar “${artifact.title}”.`);
  return artifact.taskId;
}

export async function restoreArtifact(id: string) {
  const artifact = await db.artifact.findUnique({ where: { id } });
  if (!artifact) throw new NotFound("Material não encontrado");
  if (artifact.previousContent === null) throw new Invalid("Não há versão anterior.");
  await db.artifact.update({
    where: { id },
    data: { content: artifact.previousContent, previousContent: artifact.content, editedByUser: true },
  });
  await logEvent(artifact.taskId, "user", `Você voltou para a versão anterior de “${artifact.title}”.`);
  return artifact.taskId;
}

async function startRevisions(taskId: string, items: { id: string; title: string }[], instruction?: string) {
  const task = await findTask(taskId);
  if (task.isExample) throw new Invalid("Exemplos não são processados pelo agente.");
  if (task.status === "harvested") throw new Invalid("Replante a tarefa antes de pedir revisões.");
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
  if (!artifact) throw new NotFound("Material não encontrado");
  const clean = instruction.trim().slice(0, 500);
  if (!clean) throw new Invalid("Diga o que quer mudar.");
  const count = await startRevisions(artifact.taskId, [artifact], clean);
  if (count) await logEvent(artifact.taskId, "user", `Você pediu para reescrever “${artifact.title}”: ${clean}`);
  return artifact.taskId;
}

export async function refreshWithAnswers(taskId: string) {
  const artifacts = await db.artifact.findMany({ where: { taskId, kind: "step" } });
  const withGaps = artifacts.filter((a) => extractGaps(a.content).length > 0);
  if (!withGaps.length) throw new Invalid("Nenhum material tem lacunas para preencher.");
  const count = await startRevisions(taskId, withGaps);
  if (count) {
    await logEvent(
      taskId,
      "user",
      `Você pediu para eu atualizar ${count === 1 ? "o material" : `${count} materiais`} com as suas respostas.`,
    );
  }
  return count;
}
