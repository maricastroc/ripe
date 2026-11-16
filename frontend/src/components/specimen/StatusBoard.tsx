"use client";

import { BookOpen, Flag, MessageCircleQuestion, Sprout } from "lucide-react";
import { restText } from "@/lib/presence";
import type { LucideIcon } from "lucide-react";
import { formatRelative, returnWhen } from "@/lib/format";
import { shownPhase } from "@/lib/growth";
import type { I18n } from "@/lib/i18n";
import { useI18n } from "@/lib/i18n/context";
import { activityLabel } from "@/lib/labels";
import type { AgentStatus, StepPhase, TaskDetail } from "@/lib/types";

type Tone = "ink" | "leaf" | "vermilion" | "muted";

const TONE: Record<Tone, string> = {
  ink: "text-ink",
  leaf: "text-leaf-dark",
  vermilion: "text-vermilion-ink",
  muted: "text-ink-2",
};

const SEGMENT: Record<StepPhase, string> = {
  done: "bg-leaf",
  working: "bg-leaf-2 glow-breathe",
  ready: "bg-line-2",
  blocked: "bg-ochre",
};

function since(i18n: I18n, iso: string) {
  const text = formatRelative(i18n, iso);
  return Date.now() - new Date(iso).getTime() < 86_400_000 ? text : i18n.t.when.date(text);
}

function overall(i18n: I18n, task: TaskDetail): { value: string; detail: string; tone: Tone } {
  const o = i18n.t.board.task;
  switch (task.status) {
    case "harvested":
      return {
        value: o.done,
        detail: o.harvested(task.harvestedAt ? since(i18n, task.harvestedAt) : null),
        tone: "ink",
      };
    case "ripe":
      return { value: o.ripe, detail: o.ripeDetail, tone: "leaf" };
    case "waiting":
      return { value: o.onHold, detail: o.onHoldDetail, tone: "vermilion" };
    case "dormant":
      return { value: o.dormant, detail: o.dormantDetail, tone: "muted" };
    case "stalled":
      return { value: o.stalled, detail: o.stalledDetail, tone: "vermilion" };
    default: {
      const when = returnWhen(i18n, task.returnAt);
      return { value: o.growing, detail: when ? o.away(when) : o.growingDetail, tone: "ink" };
    }
  }
}

function agentWork(i18n: I18n, task: TaskDetail, agent: AgentStatus): { value: string; detail: string; tone: Tone } {
  const w = i18n.t.board.work;
  const done = task.growth.stepsDone;
  const total = task.growth.stepsTotal;
  const written = total ? w.written(done, total) : w.noPlan;
  if (task.growth.stage === "ripe") return { value: w.finished, detail: w.all(total), tone: "leaf" };
  if (task.activity?.running)
    return {
      value: w.workingNow,
      detail: `${activityLabel(i18n.t, task.activity.kind, task.activity.subject)}. ${written}.`,
      tone: "leaf",
    };
  switch (task.status) {
    case "waiting":
      return { value: w.asFar, detail: `${written}. ${w.restNeedsYou}`, tone: "vermilion" };
    case "dormant":
      return { value: w.notRunning, detail: `${written}.`, tone: "muted" };
    case "stalled":
      return { value: w.stopped, detail: `${written}.`, tone: "vermilion" };
    case "harvested":
      return { value: w.stoppedHarvest, detail: `${written}.`, tone: "muted" };
  }
  if (agent.mode === "paused") return { value: w.paused, detail: `${written}. ${w.pausedDetail}`, tone: "muted" };
  if (agent.mode === "resting")
    return { value: w.resting, detail: `${written}. ${restText(i18n, agent).long}`, tone: "muted" };
  if (agent.mode === "halted") return { value: w.waitingModel, detail: `${written}. ${w.haltedDetail}`, tone: "muted" };
  if (agent.mode === "offline")
    return {
      value: agent.driver === "ollama" ? w.waitingOllama : w.waitingModel,
      detail: `${written}. ${w.offlineDetail}`,
      tone: "muted",
    };
  if (agent.mode === "model-missing") return { value: w.waitingModel, detail: `${written}.`, tone: "muted" };
  return { value: w.queued, detail: total ? `${written}.` : w.firstReads, tone: "ink" };
}

function Cell({
  icon: Icon,
  label,
  value,
  detail,
  tone,
  children,
  wide,
}: {
  icon: LucideIcon;
  label: string;
  value: string;
  detail: string;
  tone: Tone;
  children?: React.ReactNode;
  wide?: boolean;
}) {
  return (
    <div className={`min-w-0 bg-paper px-4 py-3.5 ${wide ? "sm:col-span-3" : ""}`}>
      <dt className="label flex items-center gap-1.5 text-[10px]">
        <Icon className="h-3.5 w-3.5" strokeWidth={1.75} aria-hidden />
        {label}
      </dt>
      <dd className="mt-1.5">
        <span className={`block font-display leading-tight ${TONE[tone]} ${wide ? "text-[1.3rem]" : "text-[1.05rem]"}`}>
          {value}
        </span>
        <span className="mt-1 block text-[13px] leading-snug text-ink-2">{detail}</span>
        {children}
      </dd>
    </div>
  );
}

export function StatusBoard({ task, agent }: { task: TaskDetail; agent: AgentStatus }) {
  const i18n = useI18n();
  const b = i18n.t.board;
  const open = task.questions.filter((q) => q.status === "open");
  const essential = open.filter((q) => q.kind === "essential").length;
  const optional = open.length - essential;
  const stepPieces = task.artifacts.filter((a) => a.kind === "step").length;
  const review = task.artifacts.some((a) => a.kind === "review");
  const pieces = task.artifacts.length;
  const state = overall(i18n, task);
  const work = agentWork(i18n, task, agent);

  const you: { value: string; detail: string; tone: Tone } = essential
    ? { value: b.you.needed(essential), detail: b.you.depends(essential, optional), tone: "vermilion" }
    : optional
      ? { value: b.you.optional(optional), detail: b.you.optionalDetail, tone: "ink" }
      : { value: b.you.none, detail: b.you.noneDetail, tone: "muted" };

  return (
    <section aria-label={b.label}>
      <dl className="grid grid-cols-1 gap-px overflow-hidden rounded-[2px] border border-line-2 bg-line-2 sm:grid-cols-3">
        <Cell icon={Flag} label={b.cells.task} value={state.value} detail={state.detail} tone={state.tone} wide />
        <Cell icon={Sprout} label={b.cells.work} value={work.value} detail={work.detail} tone={work.tone}>
          {task.steps.length > 0 && (
            <span className="mt-2 flex gap-1" aria-hidden>
              {task.steps.map((step) => (
                <span
                  key={step.id}
                  className={`h-1.5 flex-1 rounded-full ${SEGMENT[shownPhase(step.phase, step.waitingFor === "answer")]}`}
                />
              ))}
              <span
                className={`h-1.5 flex-1 rounded-full ${
                  task.growth.stage === "ripe"
                    ? "bg-vermilion/80"
                    : task.activity?.running && task.activity.kind === "review"
                      ? "glow-breathe bg-leaf-2"
                      : "bg-line-2"
                }`}
              />
            </span>
          )}
        </Cell>
        <Cell icon={MessageCircleQuestion} label={b.cells.you} value={you.value} detail={you.detail} tone={you.tone} />
        <Cell
          icon={BookOpen}
          label={b.cells.read}
          value={pieces ? b.read.pieces(pieces) : b.read.none}
          detail={
            pieces
              ? b.read.detail(stepPieces, review, !(task.isExample || task.status === "harvested"))
              : b.read.noneDetail
          }
          tone={pieces ? "ink" : "muted"}
        />
      </dl>
    </section>
  );
}
