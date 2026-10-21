"use client";

import { AnimatePresence, motion } from "motion/react";
import { useState } from "react";
import { Markdown } from "@/components/Markdown";
import { useNow, useTypewriter } from "@/hooks/useTicker";
import { formatRelative, formatReturn } from "@/lib/format";
import { countWords } from "@/lib/markers";
import type { AgentStatus, TaskDetail } from "@/lib/types";

type Props = {
  task: TaskDetail;
  agent: AgentStatus;
  onAct: (action: "resume" | "retry") => void;
  busy: boolean;
};

function seconds(ms: number) {
  const s = Math.max(0, Math.round(ms / 1000));
  if (s < 60) return `${s}s`;
  return `${Math.floor(s / 60)}min ${String(s % 60).padStart(2, "0")}s`;
}

const PEEK_KEYS: Record<string, Record<string, string>> = {
  interpret: { title: "nome", outcome: "resultado esperado", question: "pergunta" },
  plan: { title: "etapa" },
  review: { summary: "resumo", problem: "ajuste", nextActions: "próximo passo" },
};

function peek(type: string, raw: string) {
  const labels = PEEK_KEYS[type];
  if (!labels) return [];
  const keys = Object.keys(labels).join("|");
  const pattern = new RegExp(`"(${keys})"\\s*:\\s*"((?:[^"\\\\]|\\\\.)*)`, "g");
  const lines: { label: string; text: string }[] = [];
  for (const match of raw.matchAll(pattern)) {
    const text = match[2].replace(/\\n/g, " ").replace(/\\"/g, '"').trim();
    if (text) lines.push({ label: labels[match[1]], text });
  }
  return lines;
}

function LiveText({ text, type }: { text: string; type: string }) {
  const shown = useTypewriter(text);
  const [raw, setRaw] = useState(false);
  if (type === "generate" || type === "revise") {
    return (
      <div className="relative max-h-[360px] overflow-hidden">
        <Markdown content={shown || " "} className="live-caret text-[15px]" />
        <div className="pointer-events-none absolute inset-x-0 top-0 h-12 bg-gradient-to-b from-surface to-transparent" />
      </div>
    );
  }
  const lines = peek(type, shown).map((line, i, all) => ({
    ...line,
    tag: line.label === "etapa" ? `etapa ${all.slice(0, i + 1).filter((l) => l.label === "etapa").length}` : line.label,
  }));
  return (
    <div>
      {raw || !lines.length ? (
        <pre className="caret max-h-40 overflow-hidden whitespace-pre-wrap break-words font-mono text-[12px] leading-relaxed text-ink-3 [mask-image:linear-gradient(to_bottom,transparent,black_40%)]">
          {shown.slice(-520)}
        </pre>
      ) : (
        <ul className="space-y-1.5">
          {lines.map((line, i) => (
            <motion.li
              key={i}
              initial={{ opacity: 0, x: -4 }}
              animate={{ opacity: 1, x: 0 }}
              className={`flex gap-3 text-[14.5px] leading-snug ${i === lines.length - 1 ? "caret" : ""}`}
            >
              <span className="w-28 shrink-0 pt-0.5 font-mono text-[11px] uppercase tracking-wider text-ink-3">
                {line.tag}
              </span>
              <span className="min-w-0 text-ink">{line.text}</span>
            </motion.li>
          ))}
        </ul>
      )}
      <button
        type="button"
        onClick={() => setRaw((r) => !r)}
        className="focus-ring mt-3 rounded px-1 font-mono text-[11px] uppercase tracking-wider text-ink-3 hover:text-ink"
      >
        {raw ? "ver organizado" : "ver saída bruta do modelo"}
      </button>
    </div>
  );
}

function Shell({
  tone,
  title,
  children,
  live,
}: {
  tone: string;
  title: string;
  children?: React.ReactNode;
  live?: boolean;
}) {
  return (
    <motion.div
      initial={{ opacity: 0, y: 8 }}
      animate={{ opacity: 1, y: 0 }}
      exit={{ opacity: 0, y: -8 }}
      transition={{ duration: 0.45 }}
    >
      <div className="flex items-center gap-2.5">
        <span className={`h-2 w-2 rounded-full bg-current ${tone} ${live ? "dot-live" : ""}`} />
        <p className="font-display text-[1.45rem] leading-tight tracking-tight">{title}</p>
      </div>
      {children && <div className="mt-2 text-[15px] leading-relaxed text-ink-2">{children}</div>}
    </motion.div>
  );
}

export function NowPanel({ task, agent, onAct, busy }: Props) {
  const activity = task.activity;
  const running = activity?.status === "running";
  const now = useNow(Boolean(activity));

  let key: string = task.status;
  let content: React.ReactNode;

  if (task.isExample) {
    key = "example";
    content = (
      <Shell tone="text-ink-3" title="Exemplo ilustrativo">
        Este material foi escrito à mão para mostrar como fica uma tarefa madura. Nada aqui está rodando no agente.
      </Shell>
    );
  } else if (running && activity) {
    key = `run-${activity.jobId}`;
    const prose = activity.type === "generate" || activity.type === "revise";
    const partial = activity.partial ?? "";
    content = (
      <Shell tone="text-leaf" title={activity.label} live>
        <p className="font-mono text-[12px] text-ink-3" suppressHydrationWarning>
          {activity.startedAt ? `há ${seconds(now - new Date(activity.startedAt).getTime())}` : "começando"}
          {activity.attempts > 1 ? ` · tentativa ${activity.attempts} de ${activity.maxAttempts}` : ""}
          {prose && partial ? ` · ${countWords(partial)} palavras até agora` : ""}
          {` · ${agent.settings.model}`}
        </p>
        <div className="sheet mt-4 rounded-2xl px-5 py-4 sm:px-6">
          {partial ? (
            <LiveText text={partial} type={activity.type} />
          ) : (
            <p className="text-[14px] italic text-ink-3">Carregando o modelo e pensando no primeiro passo…</p>
          )}
        </div>
      </Shell>
    );
  } else if (task.status === "dormant") {
    content = (
      <Shell tone="text-ink-3" title="Adormecida">
        Nada roda até você deixar amadurecer. Tudo o que já foi feito continua guardado.
        <div className="mt-4">
          <button
            type="button"
            disabled={busy}
            onClick={() => onAct("resume")}
            className="focus-ring rounded-full bg-ink px-5 py-2.5 text-[14px] font-medium text-paper hover:bg-leaf-dark"
          >
            Deixar amadurecer agora
          </button>
        </div>
      </Shell>
    );
  } else if (task.status === "stalled") {
    content = (
      <Shell tone="text-accent" title="Precisa de cuidado">
        <p>{task.stallReason ?? "O agente parou depois de algumas tentativas sem sucesso."}</p>
        <p className="mt-1 text-[14px] text-ink-3">Nada do que já estava pronto foi perdido.</p>
        <div className="mt-4">
          <button
            type="button"
            disabled={busy}
            onClick={() => onAct("retry")}
            className="focus-ring rounded-full bg-ink px-5 py-2.5 text-[14px] font-medium text-paper hover:bg-leaf-dark"
          >
            Tentar de novo
          </button>
        </div>
      </Shell>
    );
  } else if (task.status === "harvested") {
    content = (
      <Shell tone="text-ink-3" title="Colhida">
        {task.harvestedAt
          ? `Você levou esta tarefa ${formatRelative(task.harvestedAt)}.`
          : "Você já levou esta tarefa."}{" "}
        Os materiais continuam aqui.
      </Shell>
    );
  } else if (task.status === "waiting") {
    const essentials = task.questions.filter((q) => q.status === "open" && q.blocking);
    content = (
      <Shell tone="text-accent" title="Parei onde dava sem você">
        Fiz tudo o que era possível com o que sei. Para seguir, preciso de{" "}
        {essentials.length === 1 ? "uma resposta" : `${essentials.length || "algumas"} respostas`} logo abaixo — ou você
        pode me dizer para seguir sem elas.
      </Shell>
    );
  } else if (activity && activity.status === "queued") {
    key = `queued-${agent.mode}`;
    const retryIn = new Date(activity.runAfter).getTime() - now;
    if (agent.settings.paused) {
      content = (
        <Shell tone="text-ochre" title="Agente pausado">
          O trabalho está na fila e volta quando você reativar o agente.
        </Shell>
      );
    } else if (agent.mode === "offline") {
      content = (
        <Shell tone="text-ochre" title="Esperando o Ollama">
          Não consigo falar com o Ollama em <span className="font-mono text-[13px]">{agent.settings.baseUrl}</span>. A
          etapa está guardada e eu continuo sozinho quando ele voltar. Para ligar:{" "}
          <code className="rounded bg-paper-2 px-1.5 py-0.5 font-mono text-[13px]">ollama serve</code>
        </Shell>
      );
    } else if (agent.mode === "model-missing") {
      content = (
        <Shell tone="text-ochre" title="Esperando o modelo">
          O modelo <span className="font-mono text-[13px]">{agent.settings.model}</span> não está instalado. Rode{" "}
          <code className="rounded bg-paper-2 px-1.5 py-0.5 font-mono text-[13px]">
            ollama pull {agent.settings.model}
          </code>{" "}
          ou escolha outro nas configurações do agente.
        </Shell>
      );
    } else if (retryIn > 1500 && activity.lastError) {
      content = (
        <Shell tone="text-ochre" title={`Tento de novo em ${seconds(retryIn)}`}>
          {activity.label}. Na última vez: <span className="italic">{activity.lastError}</span>
        </Shell>
      );
    } else if (agent.current && agent.current.taskId !== task.id) {
      content = (
        <Shell tone="text-ink-3" title="Na fila">
          O agente faz uma coisa por vez. Agora está em “{agent.current.taskTitle}”; esta tarefa vem logo depois.
        </Shell>
      );
    } else {
      content = (
        <Shell tone="text-leaf" title="Preparando o próximo passo" live>
          {activity.label}.
        </Shell>
      );
    }
  } else if (task.status === "ripe") {
    const materials = task.artifacts.filter((a) => a.kind === "step").length;
    const early = task.returnAt && task.ripeAt && new Date(task.ripeAt) < new Date(task.returnAt);
    content = (
      <Shell tone="text-leaf" title="Madura, esperando por você">
        {materials} {materials === 1 ? "material pronto" : "materiais prontos"} para revisar e aproveitar, mais a
        revisão do agente.{early ? " Ficou pronta antes da hora que você disse que voltaria." : ""}
      </Shell>
    );
  } else {
    content = (
      <Shell tone="text-leaf" title="Organizando o próximo passo" live>
        {task.returnAt ? `Você disse que ${formatReturn(task.returnAt)}.` : null}
      </Shell>
    );
  }

  return (
    <section aria-labelledby="now-title" aria-live="polite">
      <h2 id="now-title" className="label mb-3">
        agora
      </h2>
      <AnimatePresence mode="wait" initial={false}>
        <motion.div key={key}>{content}</motion.div>
      </AnimatePresence>
    </section>
  );
}
