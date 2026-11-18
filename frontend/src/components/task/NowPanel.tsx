"use client";

import { AnimatePresence, motion } from "motion/react";
import { Apple, LoaderCircle } from "lucide-react";
import { useState } from "react";
import { Markdown } from "@/components/Markdown";
import { useNow, useTypewriter } from "@/hooks/useTicker";
import { returnWhen } from "@/lib/format";
import { useI18n } from "@/lib/i18n/context";
import type { Messages } from "@/lib/i18n/messages/en";
import { activityLabel } from "@/lib/labels";
import { countWords } from "@/lib/markers";
import { restText } from "@/lib/presence";
import type { AgentStatus, RunKind, TaskDetail } from "@/lib/types";

type Props = {
  task: TaskDetail;
  agent: AgentStatus;
  liveText?: string | null;
  onAct: (action: "resume" | "retry") => void;
  onHarvest?: () => void;
  busy: boolean;
};

function seconds(ms: number) {
  const s = Math.max(0, Math.round(ms / 1000));
  if (s < 60) return `${s}s`;
  return `${Math.floor(s / 60)}min ${String(s % 60).padStart(2, "0")}s`;
}

type PeekLabel = keyof Messages["now"]["peek"];

const PEEK_KEYS: Record<string, Record<string, PeekLabel>> = {
  interpret: { title: "name", outcome: "outcome", question: "question" },
  plan: { title: "step" },
  review: { summary: "summary", problem: "fix", nextActions: "next" },
};

function peek(type: string, raw: string) {
  const labels = PEEK_KEYS[type];
  if (!labels) return [];
  const keys = Object.keys(labels).join("|");
  const pattern = new RegExp(`"(${keys})"\\s*:\\s*"((?:[^"\\\\]|\\\\.)*)`, "g");
  const lines: { label: PeekLabel; text: string }[] = [];
  for (const match of raw.matchAll(pattern)) {
    const text = match[2].replace(/\\n/g, " ").replace(/\\"/g, '"').trim();
    if (text) lines.push({ label: labels[match[1]], text });
  }
  return lines;
}

function LiveText({ text, kind, language }: { text: string; kind: RunKind; language: string | null }) {
  const { t } = useI18n();
  const shown = useTypewriter(text);
  const [raw, setRaw] = useState(false);
  if (kind === "generate" || kind === "revise") {
    return (
      <div className="relative max-h-[360px] overflow-hidden">
        <Markdown content={shown || " "} className="live-caret text-[15px]" language={language} />
        <div className="pointer-events-none absolute inset-x-0 top-0 h-12 bg-gradient-to-b from-surface to-transparent" />
      </div>
    );
  }
  const lines = peek(kind, shown).map((line, i, all) => ({
    ...line,
    tag:
      line.label === "step"
        ? t.now.peek.step(all.slice(0, i + 1).filter((l) => l.label === "step").length)
        : t.now.peek[line.label],
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
        {raw ? t.now.readable : t.now.raw}
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
        <span className={`h-2 w-2 rounded-full bg-current ${tone} ${live ? "live-dot" : ""}`} />
        <p className="font-display text-[1.45rem] leading-tight tracking-tight">{title}</p>
      </div>
      {children && <div className="mt-2 text-[15px] leading-relaxed text-ink-2">{children}</div>}
    </motion.div>
  );
}

export function NowPanel({ task, agent, liveText = null, onAct, onHarvest, busy }: Props) {
  const activity = task.activity;
  const running = activity?.running === true;
  const now = useNow(Boolean(activity));
  const i18n = useI18n();
  const { t } = i18n;
  const n = t.now;

  let key: string = task.status;
  let content: React.ReactNode;

  if (task.isExample) {
    key = "example";
    content = (
      <Shell tone="text-ink-3" title={n.example.title}>
        {n.example.idle}{" "}
        {task.example
          ? n.example.recorded(
              task.example.model,
              task.example.generatedAt
                ? new Date(task.example.generatedAt).toLocaleDateString(i18n.locale, {
                    month: "long",
                    day: "numeric",
                    year: "numeric",
                  })
                : null,
            )
          : n.example.shows}
      </Shell>
    );
  } else if (running && activity) {
    key = `run-${activity.runId}-${activity.live?.attempt ?? 0}`;
    const prose = activity.kind === "generate" || activity.kind === "revise";
    const partial = liveText ?? activity.live?.text ?? "";
    content = (
      <Shell tone="text-leaf" title={activityLabel(t, activity.kind, activity.subject)} live>
        <p className="font-mono text-[12px] text-ink-3" suppressHydrationWarning>
          {activity.startedAt
            ? n.running.for(seconds(now - new Date(activity.startedAt).getTime()))
            : n.running.starting}
          {activity.attempts > 1 ? ` · ${n.running.attempt(activity.attempts, activity.maxAttempts)}` : ""}
          {prose && partial ? ` · ${n.running.words(countWords(partial))}` : ""}
          {` · ${agent.model}`}
        </p>
        <div className="sheet relative mt-4 overflow-hidden rounded-2xl px-5 pb-4 pt-5 sm:px-6">
          <span aria-hidden className="working-bar absolute inset-x-0 top-0 h-[3px]" />
          {partial ? (
            <LiveText text={partial} kind={activity.kind} language={task.language} />
          ) : (
            <div>
              <p className="flex items-center gap-2.5 text-[14.5px] text-ink-2">
                <LoaderCircle
                  className="h-4 w-4 shrink-0 animate-spin text-leaf-dark motion-reduce:animate-none"
                  strokeWidth={2}
                  aria-hidden
                />
                {n.running.loading}
              </p>
              <div aria-hidden className="mt-4 space-y-2.5">
                <span className="ink-skeleton block h-2.5 w-[92%] rounded-full" />
                <span className="ink-skeleton block h-2.5 w-[80%] rounded-full" />
                <span className="ink-skeleton block h-2.5 w-[56%] rounded-full" />
              </div>
            </div>
          )}
        </div>
      </Shell>
    );
  } else if (task.status === "dormant") {
    content = (
      <Shell tone="text-ink-3" title={n.dormant.title}>
        {n.dormant.text}
        <div className="mt-4">
          <button
            type="button"
            disabled={busy}
            onClick={() => onAct("resume")}
            className="focus-ring rounded-full bg-ink px-5 py-2.5 text-[14px] font-medium text-paper hover:bg-leaf-dark"
          >
            {n.dormant.resume}
          </button>
        </div>
      </Shell>
    );
  } else if (task.status === "stalled") {
    content = (
      <Shell tone="text-accent" title={n.stalled.title}>
        <p>{task.stallReason ?? n.stalled.reason}</p>
        <p className="mt-1 text-[14px] text-ink-3">{n.stalled.safe}</p>
        <div className="mt-4">
          <button
            type="button"
            disabled={busy}
            onClick={() => onAct("retry")}
            className="focus-ring rounded-full bg-ink px-5 py-2.5 text-[14px] font-medium text-paper hover:bg-leaf-dark"
          >
            {n.stalled.retry}
          </button>
        </div>
      </Shell>
    );
  } else if (task.status === "harvested") {
    content = (
      <Shell tone="text-ink-3" title={n.harvested.title}>
        {n.harvested.text}
      </Shell>
    );
  } else if (task.status === "waiting") {
    const essentials = task.questions.filter((q) => q.status === "open" && q.kind === "essential");
    content = (
      <Shell tone="text-accent" title={n.waiting.title}>
        {n.waiting.text(essentials.length)}
      </Shell>
    );
  } else if (activity && !activity.running) {
    key = `queued-${agent.mode}`;
    const retryIn = activity.availableAt ? new Date(activity.availableAt).getTime() - now : 0;
    if (agent.mode === "paused") {
      content = (
        <Shell tone="text-ochre" title={n.paused.title}>
          {n.paused.text}
        </Shell>
      );
    } else if (agent.mode === "resting") {
      content = (
        <Shell tone="text-ochre" title={n.resting.title}>
          <span suppressHydrationWarning>{restText(i18n, agent).long}</span> {n.resting.safe}
        </Shell>
      );
    } else if (agent.mode === "halted") {
      content = (
        <Shell tone="text-ochre" title={n.waitingModel}>
          {n.halted}
        </Shell>
      );
    } else if (agent.mode === "offline") {
      content =
        agent.driver === "ollama" ? (
          <Shell tone="text-ochre" title={n.ollama.title}>
            {n.ollama.text} <code className="rounded bg-paper-2 px-1.5 py-0.5 font-mono text-[13px]">ollama serve</code>
          </Shell>
        ) : (
          <Shell tone="text-ochre" title={n.waitingModel}>
            {n.offline}
          </Shell>
        );
    } else if (agent.mode === "model-missing") {
      content = (
        <Shell tone="text-ochre" title={n.waitingModel}>
          {n.missing.model} <span className="font-mono text-[13px]">{agent.model}</span> {n.missing.run}{" "}
          <code className="rounded bg-paper-2 px-1.5 py-0.5 font-mono text-[13px]">ollama pull {agent.model}</code>{" "}
          {n.missing.other}
        </Shell>
      );
    } else if (retryIn > 1500 && activity.lastError) {
      content = (
        <Shell tone="text-ochre" title={n.retryIn(seconds(retryIn))}>
          {activityLabel(t, activity.kind, activity.subject)}. {n.lastError}{" "}
          <span className="italic">{activity.lastError}</span>
        </Shell>
      );
    } else if ((agent.current && agent.current.taskId !== task.id) || agent.elsewhere) {
      content = (
        <Shell tone="text-ink-3" title={n.queue.title}>
          {n.queue.oneAtATime}{" "}
          {agent.current && agent.current.taskId !== task.id
            ? n.queue.busyWith(agent.current.taskTitle)
            : n.queue.elsewhere}
        </Shell>
      );
    } else {
      content = (
        <Shell tone="text-leaf" title={n.preparing} live>
          {activityLabel(t, activity.kind, activity.subject)}.
        </Shell>
      );
    }
  } else if (task.status === "ripe") {
    const materials = task.artifacts.filter((a) => a.kind === "step").length;
    const optional = task.questions.filter((q) => q.status === "open").length;
    const early = task.returnAt && task.ripeAt && new Date(task.ripeAt) < new Date(task.returnAt);
    content = (
      <Shell tone="text-leaf" title={n.ripe.title}>
        {n.ripe.text(materials, optional)}
        {early && <p className="mt-2 text-[13px] italic text-ink-3">{n.ripe.early}</p>}
        {onHarvest && (
          <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2">
            <button
              type="button"
              disabled={busy}
              onClick={onHarvest}
              className="focus-ring inline-flex min-h-11 items-center justify-center gap-2 rounded-full bg-ink px-5 py-2.5 text-[14px] font-medium text-paper transition-colors hover:bg-leaf-dark disabled:opacity-50"
            >
              <Apple className="h-4 w-4" strokeWidth={1.75} aria-hidden />
              {t.actions.harvest}
            </button>
            <span className="max-w-sm text-[12.5px] leading-snug text-ink-3">{t.actions.harvestHint}</span>
          </div>
        )}
      </Shell>
    );
  } else {
    content = (
      <Shell tone="text-leaf" title={n.lining} live>
        {returnWhen(i18n, task.returnAt) ? n.saidBack(returnWhen(i18n, task.returnAt) ?? "") : null}
      </Shell>
    );
  }

  return (
    <section aria-labelledby="now-title" aria-live="polite">
      <h2 id="now-title" className="label mb-3">
        {n.heading}
      </h2>
      <AnimatePresence mode="wait" initial={false}>
        <motion.div key={key}>{content}</motion.div>
      </AnimatePresence>
    </section>
  );
}
