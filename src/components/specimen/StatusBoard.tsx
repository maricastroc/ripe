import { BookOpen, Flag, MessageCircleQuestion, Sprout } from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { formatRelative, returnWhen } from "@/lib/format";
import type { AgentStatus, StepStatus, TaskDetail } from "@/lib/types";

type Tone = "ink" | "leaf" | "vermilion" | "muted";

const TONE: Record<Tone, string> = {
  ink: "text-ink",
  leaf: "text-leaf-dark",
  vermilion: "text-vermilion-ink",
  muted: "text-ink-2",
};

const SEGMENT: Record<StepStatus, string> = {
  done: "bg-leaf",
  working: "bg-leaf-2 glow-breathe",
  pending: "bg-line-2",
  blocked: "bg-ochre",
};

function plural(n: number, one: string, many: string) {
  return `${n} ${n === 1 ? one : many}`;
}

function overall(task: TaskDetail, optional: number): { value: string; detail: string; tone: Tone } {
  switch (task.status) {
    case "harvested":
      return {
        value: "Done — harvested",
        detail: `You marked it done${task.harvestedAt ? ` ${formatRelative(task.harvestedAt)}` : ""}. It stays readable in the pantry.`,
        tone: "ink",
      };
    case "ripe":
      return {
        value: "Ripe — your turn",
        detail: `The agent has finished. The task is done when you harvest it${
          optional ? "; answer the optional questions first if you want the gaps filled" : ""
        }.`,
        tone: "leaf",
      };
    case "waiting":
      return {
        value: "Growing — paused for you",
        detail: "The agent did everything it could. Answer below, or tell it to go on without the answer.",
        tone: "vermilion",
      };
    case "dormant":
      return {
        value: "Dormant",
        detail: "Nothing runs until you let it ripen. Everything so far is saved.",
        tone: "muted",
      };
    case "stalled":
      return {
        value: "Needs care",
        detail: "The agent stopped after several attempts. Try again — nothing is lost.",
        tone: "vermilion",
      };
    default: {
      const when = returnWhen(task.returnAt);
      return {
        value: "Growing",
        detail: when
          ? `The agent is working through the steps. You said you'd be back ${when}.`
          : "The agent is working through the steps.",
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
  if (task.activity?.status === "running")
    return { value: "Working now", detail: `${task.activity.label}. ${written}.`, tone: "leaf" };
  switch (task.status) {
    case "waiting":
      return { value: "Waiting on you", detail: `${written}. The next step needs your answer.`, tone: "vermilion" };
    case "dormant":
      return { value: "Dormant", detail: `${written}.`, tone: "muted" };
    case "stalled":
      return { value: "Stopped", detail: task.stallReason ?? `${written}.`, tone: "vermilion" };
    case "harvested":
      return { value: "Stopped at harvest", detail: `${written}.`, tone: "muted" };
  }
  if (agent.settings.paused)
    return { value: "Agent paused", detail: `${written}. It resumes when you turn it back on.`, tone: "muted" };
  if (agent.mode === "offline")
    return { value: "Waiting for Ollama", detail: `${written}. It continues on its own.`, tone: "muted" };
  if (agent.mode === "model-missing") return { value: "Waiting for the model", detail: `${written}.`, tone: "muted" };
  return { value: "In the queue", detail: total ? `${written}.` : "Reading the request comes first.", tone: "ink" };
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
  const essential = open.filter((q) => q.blocking).length;
  const optional = open.length - essential;
  const stepPieces = task.artifacts.filter((a) => a.kind === "step").length;
  const review = task.artifacts.some((a) => a.kind === "review");
  const pieces = task.artifacts.length;
  const state = overall(task, optional);
  const work = agentWork(task, agent);

  const you: { value: string; detail: string; tone: Tone } = essential
    ? {
        value: `${plural(essential, "answer", "answers")} needed`,
        detail: `Needed for the final version${optional ? `; ${optional} more ${optional === 1 ? "is" : "are"} optional` : ""}.`,
        tone: "vermilion",
      }
    : optional
      ? {
          value: `${plural(optional, "optional answer", "optional answers")}`,
          detail: "They would fill gaps the agent left marked instead of guessing.",
          tone: "ink",
        }
      : { value: "Nothing open", detail: "No questions are waiting for you.", tone: "muted" };

  return (
    <section aria-label="Where this task stands">
      <dl className="grid grid-cols-1 gap-px overflow-hidden rounded-[2px] border border-line-2 bg-line-2 sm:grid-cols-3">
        <Cell icon={Flag} label="this task" value={state.value} detail={state.detail} tone={state.tone} wide />
        <Cell icon={Sprout} label="agent's work" value={work.value} detail={work.detail} tone={work.tone}>
          {task.steps.length > 0 && (
            <span className="mt-2 flex gap-1" aria-hidden>
              {task.steps.map((step) => (
                <span key={step.id} className={`h-1.5 flex-1 rounded-full ${SEGMENT[step.status]}`} />
              ))}
              <span
                className={`h-1.5 flex-1 rounded-full ${task.growth.stage === "ripe" ? "bg-vermilion/80" : "bg-line-2"}`}
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
              ? `${plural(stepPieces, "step", "steps")}${review ? " + the agent's review" : ""} — all editable.`
              : "Each finished step adds a piece here."
          }
          tone={pieces ? "ink" : "muted"}
        />
      </dl>
    </section>
  );
}
