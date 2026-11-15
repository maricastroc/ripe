import { BookOpen, Flag, MessageCircleQuestion, Sprout } from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { formatRelative, returnWhen } from "@/lib/format";
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

function plural(n: number, one: string, many: string) {
  return `${n} ${n === 1 ? one : many}`;
}

function since(iso: string) {
  const text = formatRelative(iso);
  return text.endsWith("ago") || text === "just now" ? text : `on ${text}`;
}

function overall(task: TaskDetail): { value: string; detail: string; tone: Tone } {
  switch (task.status) {
    case "harvested":
      return {
        value: "Done",
        detail: `You harvested it${task.harvestedAt ? ` ${since(task.harvestedAt)}` : ""}. It stays readable in the pantry.`,
        tone: "ink",
      };
    case "ripe":
      return {
        value: "Ripe",
        detail: "The agent has finished. The task only counts as done once you harvest it.",
        tone: "leaf",
      };
    case "waiting":
      return { value: "Growing, on hold", detail: "It stopped at the step that needs your answer.", tone: "vermilion" };
    case "dormant":
      return { value: "Dormant", detail: "Planted, but nothing is running.", tone: "muted" };
    case "stalled":
      return { value: "Needs care", detail: "The agent stopped after several failed attempts.", tone: "vermilion" };
    default: {
      const when = returnWhen(task.returnAt);
      return {
        value: "Growing",
        detail: when
          ? `It keeps ripening while you're away. You said you'd be back ${when}.`
          : "It keeps ripening, one step at a time.",
        tone: "ink",
      };
    }
  }
}

function agentWork(task: TaskDetail, agent: AgentStatus): { value: string; detail: string; tone: Tone } {
  const done = task.growth.stepsDone;
  const total = task.growth.stepsTotal;
  const written = total ? `${done} of ${plural(total, "step", "steps")} written` : "No plan yet";
  if (task.growth.stage === "ripe")
    return { value: "Finished", detail: `All ${plural(total, "step", "steps")} written and reviewed.`, tone: "leaf" };
  if (task.activity?.running)
    return {
      value: "Working now",
      detail: `${activityLabel(task.activity.kind, task.activity.subject)}. ${written}.`,
      tone: "leaf",
    };
  switch (task.status) {
    case "waiting":
      return { value: "As far as it can go", detail: `${written}. The rest needs your answer.`, tone: "vermilion" };
    case "dormant":
      return { value: "Not running", detail: `${written}.`, tone: "muted" };
    case "stalled":
      return { value: "Stopped", detail: `${written}.`, tone: "vermilion" };
    case "harvested":
      return { value: "Stopped when you harvested", detail: `${written}.`, tone: "muted" };
  }
  if (agent.mode === "paused")
    return { value: "Agent paused", detail: `${written}. It resumes when you turn it back on.`, tone: "muted" };
  if (agent.mode === "halted")
    return { value: "Resting for now", detail: `${written}. It picks up again on its own.`, tone: "muted" };
  if (agent.mode === "offline")
    return {
      value: agent.driver === "ollama" ? "Waiting for Ollama" : "Waiting for the model",
      detail: `${written}. It picks up again once the model is back.`,
      tone: "muted",
    };
  if (agent.mode === "model-missing") return { value: "Waiting for the model", detail: `${written}.`, tone: "muted" };
  return { value: "In the queue", detail: total ? `${written}.` : "First it reads your request.", tone: "ink" };
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
  const open = task.questions.filter((q) => q.status === "open");
  const essential = open.filter((q) => q.kind === "essential").length;
  const optional = open.length - essential;
  const stepPieces = task.artifacts.filter((a) => a.kind === "step").length;
  const review = task.artifacts.some((a) => a.kind === "review");
  const pieces = task.artifacts.length;
  const state = overall(task);
  const work = agentWork(task, agent);

  const you: { value: string; detail: string; tone: Tone } = essential
    ? {
        value: `${plural(essential, "answer", "answers")} needed`,
        detail: `The final version depends on ${essential === 1 ? "it" : "them"}.${
          optional ? ` ${optional} more ${optional === 1 ? "is" : "are"} optional.` : ""
        }`,
        tone: "vermilion",
      }
    : optional
      ? {
          value: `${plural(optional, "optional answer", "optional answers")}`,
          detail: "The agent marked these gaps rather than guess. Your answers fill them in.",
          tone: "ink",
        }
      : { value: "Nothing open", detail: "No questions for you right now.", tone: "muted" };

  return (
    <section aria-label="Where this task stands">
      <dl className="grid grid-cols-1 gap-px overflow-hidden rounded-[2px] border border-line-2 bg-line-2 sm:grid-cols-3">
        <Cell icon={Flag} label="this task" value={state.value} detail={state.detail} tone={state.tone} wide />
        <Cell icon={Sprout} label="agent's work" value={work.value} detail={work.detail} tone={work.tone}>
          {task.steps.length > 0 && (
            <span className="mt-2 flex gap-1" aria-hidden>
              {task.steps.map((step) => (
                <span key={step.id} className={`h-1.5 flex-1 rounded-full ${SEGMENT[step.phase]}`} />
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
        <Cell icon={MessageCircleQuestion} label="from you" value={you.value} detail={you.detail} tone={you.tone} />
        <Cell
          icon={BookOpen}
          label="ready to read"
          value={pieces ? plural(pieces, "piece", "pieces") : "Nothing yet"}
          detail={
            pieces
              ? `${plural(stepPieces, "finished step", "finished steps")}${
                  review ? " and the agent's review" : ""
                }.${task.isExample || task.status === "harvested" ? "" : " All editable."}`
              : "Each finished step adds a piece here."
          }
          tone={pieces ? "ink" : "muted"}
        />
      </dl>
    </section>
  );
}
