"use client";

import { AnimatePresence, motion } from "motion/react";
import { LoaderCircle } from "lucide-react";
import { useState } from "react";
import { Markdown } from "@/components/Markdown";
import { useNow, useTypewriter } from "@/hooks/useTicker";
import { returnWhen } from "@/lib/format";
import { activityLabel } from "@/lib/labels";
import { countWords } from "@/lib/markers";
import type { AgentStatus, RunKind, TaskDetail } from "@/lib/types";

type Props = {
  task: TaskDetail;
  agent: AgentStatus;
  liveText?: string | null;
  onAct: (action: "resume" | "retry") => void;
  busy: boolean;
};

function seconds(ms: number) {
  const s = Math.max(0, Math.round(ms / 1000));
  if (s < 60) return `${s}s`;
  return `${Math.floor(s / 60)}min ${String(s % 60).padStart(2, "0")}s`;
}

const PEEK_KEYS: Record<string, Record<string, string>> = {
  interpret: { title: "name", outcome: "expected outcome", question: "question" },
  plan: { title: "step" },
  review: { summary: "summary", problem: "fix", nextActions: "next step" },
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

function LiveText({ text, kind }: { text: string; kind: RunKind }) {
  const shown = useTypewriter(text);
  const [raw, setRaw] = useState(false);
  if (kind === "generate" || kind === "revise") {
    return (
      <div className="relative max-h-[360px] overflow-hidden">
        <Markdown content={shown || " "} className="live-caret text-[15px]" />
        <div className="pointer-events-none absolute inset-x-0 top-0 h-12 bg-gradient-to-b from-surface to-transparent" />
      </div>
    );
  }
  const lines = peek(kind, shown).map((line, i, all) => ({
    ...line,
    tag: line.label === "step" ? `step ${all.slice(0, i + 1).filter((l) => l.label === "step").length}` : line.label,
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
        {raw ? "show readable version" : "show raw model output"}
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

export function NowPanel({ task, agent, liveText = null, onAct, busy }: Props) {
  const activity = task.activity;
  const running = activity?.running === true;
  const now = useNow(Boolean(activity));

  let key: string = task.status;
  let content: React.ReactNode;

  if (task.isExample) {
    key = "example";
    content = (
      <Shell tone="text-ink-3" title="Illustrative example">
        Nothing is running here. A scripted model wrote every piece ahead of time, to show what you find once a task is
        ripe.
      </Shell>
    );
  } else if (running && activity) {
    key = `run-${activity.runId}-${activity.live?.attempt ?? 0}`;
    const prose = activity.kind === "generate" || activity.kind === "revise";
    const partial = liveText ?? activity.live?.text ?? "";
    content = (
      <Shell tone="text-leaf" title={activityLabel(activity.kind, activity.subject)} live>
        <p className="font-mono text-[12px] text-ink-3" suppressHydrationWarning>
          {activity.startedAt ? `for ${seconds(now - new Date(activity.startedAt).getTime())}` : "starting"}
          {activity.attempts > 1 ? ` · attempt ${activity.attempts} of ${activity.maxAttempts}` : ""}
          {prose && partial ? ` · ${countWords(partial)} words so far` : ""}
          {` · ${agent.model}`}
        </p>
        <div className="sheet relative mt-4 overflow-hidden rounded-2xl px-5 pb-4 pt-5 sm:px-6">
          <span aria-hidden className="working-bar absolute inset-x-0 top-0 h-[3px]" />
          {partial ? (
            <LiveText text={partial} kind={activity.kind} />
          ) : (
            <div>
              <p className="flex items-center gap-2.5 text-[14.5px] text-ink-2">
                <LoaderCircle
                  className="h-4 w-4 shrink-0 animate-spin text-leaf-dark motion-reduce:animate-none"
                  strokeWidth={2}
                  aria-hidden
                />
                Loading the model. The first words can take a moment…
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
      <Shell tone="text-ink-3" title="Dormant">
        Nothing runs until you let it ripen. Everything done so far stays saved.
        <div className="mt-4">
          <button
            type="button"
            disabled={busy}
            onClick={() => onAct("resume")}
            className="focus-ring rounded-full bg-ink px-5 py-2.5 text-[14px] font-medium text-paper hover:bg-leaf-dark"
          >
            Let it ripen now
          </button>
        </div>
      </Shell>
    );
  } else if (task.status === "stalled") {
    content = (
      <Shell tone="text-accent" title="The agent got stuck">
        <p>{task.stallReason ?? "It stopped after several failed attempts."}</p>
        <p className="mt-1 text-[14px] text-ink-3">Nothing already done is lost.</p>
        <div className="mt-4">
          <button
            type="button"
            disabled={busy}
            onClick={() => onAct("retry")}
            className="focus-ring rounded-full bg-ink px-5 py-2.5 text-[14px] font-medium text-paper hover:bg-leaf-dark"
          >
            Try again
          </button>
        </div>
      </Shell>
    );
  } else if (task.status === "harvested") {
    content = (
      <Shell tone="text-ink-3" title="Harvested">
        The agent won&apos;t work on it again unless you replant it. Every piece is still here to read and copy.
      </Shell>
    );
  } else if (task.status === "waiting") {
    const essentials = task.questions.filter((q) => q.status === "open" && q.kind === "essential");
    content = (
      <Shell tone="text-accent" title="Answer below to keep it growing">
        Everything that didn&apos;t depend on you is done. The rest needs{" "}
        {essentials.length === 1 ? "one answer" : `${essentials.length || "a few"} answers`}. You can also tell the
        agent to go on without {essentials.length === 1 ? "it" : "them"}, and it will leave{" "}
        {essentials.length === 1 ? "a gap" : "the gaps"} marked.
      </Shell>
    );
  } else if (activity && !activity.running) {
    key = `queued-${agent.mode}`;
    const retryIn = activity.availableAt ? new Date(activity.availableAt).getTime() - now : 0;
    if (agent.mode === "paused") {
      content = (
        <Shell tone="text-ochre" title="Agent paused">
          The work stays in the queue until you turn the agent back on.
        </Shell>
      );
    } else if (agent.mode === "halted") {
      content = (
        <Shell tone="text-ochre" title="Resting for now">
          The greenhouse is resting. The step is saved and will continue on its own.
        </Shell>
      );
    } else if (agent.mode === "offline") {
      content =
        agent.driver === "ollama" ? (
          <Shell tone="text-ochre" title="Waiting for Ollama">
            The agent can’t reach Ollama. The step is saved and will continue on its own once Ollama is back. To start
            it, run <code className="rounded bg-paper-2 px-1.5 py-0.5 font-mono text-[13px]">ollama serve</code>
          </Shell>
        ) : (
          <Shell tone="text-ochre" title="Waiting for the model">
            The model can’t be reached right now. The step is saved and will continue on its own.
          </Shell>
        );
    } else if (agent.mode === "model-missing") {
      content = (
        <Shell tone="text-ochre" title="Waiting for the model">
          Model <span className="font-mono text-[13px]">{agent.model}</span> is not installed. Run{" "}
          <code className="rounded bg-paper-2 px-1.5 py-0.5 font-mono text-[13px]">ollama pull {agent.model}</code> or
          pick another one in the agent settings.
        </Shell>
      );
    } else if (retryIn > 1500 && activity.lastError) {
      content = (
        <Shell tone="text-ochre" title={`Trying again in ${seconds(retryIn)}`}>
          {activityLabel(activity.kind, activity.subject)}. What went wrong last time:{" "}
          <span className="italic">{activity.lastError}</span>
        </Shell>
      );
    } else if ((agent.current && agent.current.taskId !== task.id) || agent.elsewhere) {
      content = (
        <Shell tone="text-ink-3" title="In the queue">
          The agent works on one thing at a time.{" "}
          {agent.current && agent.current.taskId !== task.id
            ? `It’s busy with “${agent.current.taskTitle}”, and this task is next.`
            : "It’s feeding another greenhouse, and this task is next."}
        </Shell>
      );
    } else {
      content = (
        <Shell tone="text-leaf" title="Getting the next step ready" live>
          {activityLabel(activity.kind, activity.subject)}.
        </Shell>
      );
    }
  } else if (task.status === "ripe") {
    const materials = task.artifacts.filter((a) => a.kind === "step").length;
    const optional = task.questions.filter((q) => q.status === "open").length;
    const early = task.returnAt && task.ripeAt && new Date(task.ripeAt) < new Date(task.returnAt);
    content = (
      <Shell tone="text-leaf" title="Your turn">
        Read the {materials === 1 ? "piece" : `${materials} pieces`} and the agent&apos;s review.
        {optional
          ? ` If you want the gaps it marked filled in, answer the ${optional === 1 ? "optional question" : `${optional} optional questions`} below.`
          : ""}{" "}
        When you&apos;re happy with it, use Harvest under the plate.
        {early ? " It ripened before you said you'd be back." : ""}
      </Shell>
    );
  } else {
    content = (
      <Shell tone="text-leaf" title="Lining up the next step" live>
        {returnWhen(task.returnAt) ? `You said you'd be back ${returnWhen(task.returnAt)}.` : null}
      </Shell>
    );
  }

  return (
    <section aria-labelledby="now-title" aria-live="polite">
      <h2 id="now-title" className="label mb-3">
        now
      </h2>
      <AnimatePresence mode="wait" initial={false}>
        <motion.div key={key}>{content}</motion.div>
      </AnimatePresence>
    </section>
  );
}
