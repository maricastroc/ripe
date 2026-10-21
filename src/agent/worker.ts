import type { Job } from "@/generated/prisma/client";
import { db } from "@/lib/db";
import { logEvent } from "@/lib/events";
import { jobLabel } from "@/lib/labels";
import { LIMITS, PACE_MS } from "@/lib/limits";
import { getSettings } from "@/lib/settings";
import type { JobType, Settings } from "@/lib/types";
import { HANDLERS } from "./handlers";
import { ModelMissing, OllamaOffline, checkHealth, forgetHealth, hasModel } from "./ollama";
import { advance } from "./scheduler";
import { registerSignals } from "./signals";
import { setAgentState } from "./state";

class JobCancelled extends Error {
  name = "JobCancelled";
}

class JobTimeout extends Error {
  name = "JobTimeout";
}

type Running = { jobId: string; taskId: string; controller: AbortController };

function sleepMs(ms: number) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function labelFor(job: Pick<Job, "type" | "stepId" | "artifactId">) {
  if (job.type === "generate" && job.stepId) {
    const step = await db.step.findUnique({ where: { id: job.stepId }, select: { title: true } });
    return jobLabel(job.type, step?.title);
  }
  if (job.type === "revise" && job.artifactId) {
    const artifact = await db.artifact.findUnique({ where: { id: job.artifactId }, select: { title: true } });
    return jobLabel(job.type, artifact?.title);
  }
  return jobLabel(job.type);
}

async function releaseStep(job: Job) {
  if (job.type === "generate" && job.stepId) {
    await db.step.updateMany({ where: { id: job.stepId, status: "working" }, data: { status: "pending" } });
  }
}

class Worker {
  private running: Running | null = null;
  private wakeUp: (() => void) | null = null;
  private started = false;

  start() {
    if (this.started) return;
    this.started = true;
    registerSignals(
      () => this.wake(),
      (taskId) => this.cancel(taskId),
    );
    void this.boot();
  }

  private async boot() {
    await setAgentState("starting", "Acordando o agente.").catch(() => undefined);
    for (let attempt = 0; attempt < 5; attempt++) {
      try {
        await this.recover(true);
        break;
      } catch (err) {
        console.error("[ripe] recovery failed", err);
        await sleepMs(2000);
      }
    }
    void this.loop();
  }

  private async loop() {
    for (;;) {
      let delay = 3000;
      try {
        delay = await this.tick();
      } catch (err) {
        console.error("[ripe] worker tick failed", err);
        delay = 5000;
      }
      await this.sleep(delay);
    }
  }

  private sleep(ms: number) {
    return new Promise<void>((resolve) => {
      const timer = setTimeout(done, ms);
      function done() {
        clearTimeout(timer);
        resolve();
      }
      this.wakeUp = done;
    });
  }

  wake() {
    if (!this.running) this.wakeUp?.();
  }

  cancel(taskId: string) {
    if (this.running?.taskId !== taskId) return false;
    this.running.controller.abort(new JobCancelled("Cancelado pela pessoa"));
    return true;
  }

  private async recover(boot: boolean) {
    const now = new Date();
    const stuck = await db.job.findMany({
      where: boot ? { status: "running" } : { status: "running", leaseUntil: { lt: now } },
    });
    for (const job of stuck) {
      if (this.running?.jobId === job.id) continue;
      await db.job.update({
        where: { id: job.id },
        data: {
          status: "queued",
          attempts: Math.max(0, job.attempts - 1),
          runAfter: now,
          leaseUntil: null,
          partial: null,
        },
      });
      await releaseStep(job);
      await logEvent(
        job.taskId,
        "info",
        `Fui interrompido no meio de uma etapa (${await labelFor(job)}). Retomo de onde parei — nada do que já estava salvo se perdeu.`,
        job.id,
      );
    }
    if (boot) {
      await db.step.updateMany({ where: { status: "working" }, data: { status: "pending" } });
      const orphans = await db.task.findMany({
        where: { status: { in: ["ripening", "waiting"] }, isExample: false },
        select: { id: true },
      });
      for (const t of orphans) await advance(t.id);
    }
  }

  private async tick(): Promise<number> {
    const settings = await getSettings();
    await this.recover(false);

    if (settings.paused) {
      await setAgentState("paused", "Agente pausado nas configurações.");
      return 4000;
    }

    const now = new Date();
    const next = await db.job.findFirst({
      where: { status: "queued", runAfter: { lte: now }, task: { status: "ripening" } },
      orderBy: { createdAt: "asc" },
    });

    if (!next) {
      const later = await db.job.findFirst({
        where: { status: "queued", task: { status: "ripening" } },
        orderBy: { runAfter: "asc" },
      });
      await setAgentState("idle", later ? "Esperando para tentar de novo." : null);
      if (!later) return 3000;
      return Math.min(5000, Math.max(500, later.runAfter.getTime() - now.getTime()));
    }

    const health = await checkHealth(settings.baseUrl);
    if (!health.online) {
      await setAgentState("offline", `Não consegui falar com o Ollama em ${settings.baseUrl}.`);
      return 8000;
    }
    if (!hasModel(health.models, settings.model)) {
      await setAgentState("model-missing", `O modelo ${settings.model} não está instalado no Ollama.`);
      return 10000;
    }

    const job = await this.claim(next.id, settings);
    if (!job) return 300;
    await this.run(job, settings);

    const rest = PACE_MS[settings.pace];
    if (rest > 1500) await setAgentState("resting", "Respirando entre uma etapa e outra.");
    return rest;
  }

  private async claim(id: string, settings: Settings) {
    const now = new Date();
    const claimed = await db.job.updateMany({
      where: { id, status: "queued" },
      data: {
        status: "running",
        attempts: { increment: 1 },
        startedAt: now,
        leaseUntil: new Date(now.getTime() + LIMITS.callTimeoutMs + LIMITS.leaseGraceMs),
        model: settings.model,
        partial: null,
      },
    });
    if (claimed.count !== 1) return null;
    return db.job.findUnique({ where: { id } });
  }

  private async run(job: Job, settings: Settings) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(new JobTimeout()), LIMITS.callTimeoutMs);
    this.running = { jobId: job.id, taskId: job.taskId, controller };
    const label = await labelFor(job);
    await setAgentState("working", label, job.id);
    if (job.type === "generate" && job.stepId) {
      await db.step.updateMany({ where: { id: job.stepId }, data: { status: "working" } });
    }
    if (job.attempts === 1) await logEvent(job.taskId, "work", `${label}…`, job.id);

    const started = Date.now();
    let lastFlush = 0;
    let flushing = false;
    const progress = (text: string, tokens: number) => {
      const now = Date.now();
      if (flushing || now - lastFlush < 700) return;
      flushing = true;
      lastFlush = now;
      db.job
        .update({
          where: { id: job.id },
          data: { partial: text.slice(-12000), tokens },
        })
        .catch(() => undefined)
        .finally(() => {
          flushing = false;
        });
    };

    try {
      const outcome = await HANDLERS[job.type as JobType](job, {
        model: settings.model,
        baseUrl: settings.baseUrl,
        signal: controller.signal,
        progress,
      });
      while (flushing) await sleepMs(20);
      await db.job.update({
        where: { id: job.id },
        data: {
          status: "done",
          finishedAt: new Date(),
          durationMs: Date.now() - started,
          tokens: outcome.tokens,
          partial: null,
          leaseUntil: null,
          error: null,
        },
      });
      await advance(job.taskId);
    } catch (err) {
      while (flushing) await sleepMs(20);
      await this.fail(job, controller.signal.reason ?? err, err).catch((e) => console.error("[ripe] fail handler", e));
    } finally {
      clearTimeout(timeout);
      this.running = null;
    }
  }

  private async fail(job: Job, reason: unknown, err: unknown) {
    const exists = await db.job.findUnique({ where: { id: job.id }, select: { id: true } });
    if (!exists) return;
    await releaseStep(job);

    if (reason instanceof JobCancelled) {
      await db.job.update({
        where: { id: job.id },
        data: { status: "cancelled", finishedAt: new Date(), partial: null, leaseUntil: null },
      });
      return;
    }

    if (err instanceof OllamaOffline || err instanceof ModelMissing) {
      forgetHealth();
      await db.job.update({
        where: { id: job.id },
        data: {
          status: "queued",
          attempts: Math.max(0, job.attempts - 1),
          runAfter: new Date(Date.now() + LIMITS.offlineRetryMs),
          partial: null,
          leaseUntil: null,
          error: err.message,
        },
      });
      await logEvent(
        job.taskId,
        "warn",
        err instanceof ModelMissing
          ? "O modelo configurado sumiu do Ollama. A etapa volta para a fila e continuo quando ele estiver disponível."
          : "Perdi a conexão com o Ollama no meio da etapa. Ela volta para a fila e eu retomo sozinho quando ele voltar.",
        job.id,
      );
      return;
    }

    const message =
      reason instanceof JobTimeout
        ? `passou do limite de ${Math.round(LIMITS.callTimeoutMs / 60000)} min`
        : err instanceof Error
          ? err.message
          : String(err);

    if (job.attempts < job.maxAttempts) {
      const backoff = 10_000 * 3 ** (job.attempts - 1);
      await db.job.update({
        where: { id: job.id },
        data: {
          status: "queued",
          runAfter: new Date(Date.now() + backoff),
          partial: null,
          leaseUntil: null,
          error: message,
        },
      });
      await logEvent(
        job.taskId,
        "warn",
        `Não saiu como esperado (${message}). Tento de novo em ${Math.round(backoff / 1000)}s — tentativa ${job.attempts + 1} de ${job.maxAttempts}.`,
        job.id,
      );
      return;
    }

    await db.job.update({
      where: { id: job.id },
      data: { status: "failed", finishedAt: new Date(), partial: null, leaseUntil: null, error: message },
    });
    await db.task.update({
      where: { id: job.taskId },
      data: { status: "stalled", stallReason: `${await labelFor(job)}: ${message}` },
    });
    await logEvent(
      job.taskId,
      "error",
      `Desisti depois de ${job.maxAttempts} tentativas (${message}). Nada do que já estava pronto foi perdido — você pode pedir para eu tentar de novo.`,
      job.id,
    );
  }
}

const holder = globalThis as unknown as { __ripeWorker?: Worker };

export function ensureWorker() {
  if (process.env.NEXT_PHASE === "phase-production-build") return;
  if (!holder.__ripeWorker) {
    holder.__ripeWorker = new Worker();
    holder.__ripeWorker.start();
  }
}
