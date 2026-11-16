"use client";

import { ArrowRight } from "lucide-react";
import { useI18n } from "@/lib/i18n/context";
import { outputLabel } from "@/lib/labels";
import type { ArtifactView, QuestionView, StepPhase, StepView, TaskDetail } from "@/lib/types";

const TONE: Record<StepPhase, string> = {
  done: "text-leaf-dark",
  working: "text-cobalt",
  ready: "text-ink-3",
  blocked: "text-vermilion-ink",
};

type Props = {
  task: TaskDetail;
  finalId: string | null;
  review: ArtifactView | null;
  highlight: string | null;
  onHover: (key: string | null) => void;
  onOpen: (key: string) => void;
  compact?: boolean;
};

function needsText(step: StepView, questions: QuestionView[]) {
  const map = new Map(questions.map((q) => [q.id, q.text]));
  return step.requires.map((id) => map.get(id)).filter((text): text is string => typeof text === "string");
}

export function Legend({ task, finalId, review, highlight, onHover, onOpen, compact = false }: Props) {
  const { t } = useI18n();
  const l = t.legend;
  if (!task.steps.length) {
    return <p className="ink text-center text-[15px]">{task.status === "dormant" ? l.dormant : l.reading}</p>;
  }
  const stage = task.growth.stage;
  const apex =
    stage === "ripe"
      ? l.apex.ripe
      : stage === "fruit"
        ? l.apex.fruit
        : stage === "blossom"
          ? l.apex.blossom
          : l.apex.tip;
  return (
    <div>
      <p className="label mb-2">{l.heading}</p>
      <ol className="divide-y divide-dashed divide-line-2 border-y border-dashed border-line-2">
        {task.steps.map((step) => {
          const s = { ...l.phase[step.phase], tone: TONE[step.phase] };
          const lit = highlight === step.id;
          const waits = step.phase === "blocked" && step.waitingFor === "answer" ? needsText(step, task.questions) : [];
          return (
            <li key={step.id}>
              <button
                type="button"
                onClick={() => onOpen(step.id)}
                onMouseEnter={() => onHover(step.id)}
                onMouseLeave={() => onHover(null)}
                onFocus={() => onHover(step.id)}
                onBlur={() => onHover(null)}
                className={`focus-ring grid w-full grid-cols-[26px_1fr_auto] items-baseline gap-x-2 py-2 text-left transition-colors ${lit ? "bg-cobalt-soft/60" : "hover:bg-paper-2/60"}`}
              >
                <span className="ink text-center text-[15px]">{step.position + 1}</span>
                <span className="min-w-0">
                  <span className="block truncate text-[14.5px] text-ink">{step.title}</span>
                  {!compact && (
                    <span className="typewriter block text-[10.5px] text-ink-3">
                      {s.organ} · {outputLabel(t, step.output)}
                    </span>
                  )}
                  {waits.length > 0 && (
                    <span className="mt-0.5 block text-[12px] leading-snug text-vermilion-ink">
                      {l.needs} {waits.join(" · ")}
                    </span>
                  )}
                </span>
                <span
                  className={`typewriter inline-flex items-center gap-1 text-[10.5px] ${step.artifactId ? "text-cobalt" : s.tone}`}
                >
                  {step.artifactId ? (
                    <>
                      {l.read} <ArrowRight className="h-3 w-3" strokeWidth={1.75} aria-hidden />
                    </>
                  ) : (
                    s.word
                  )}
                </span>
              </button>
            </li>
          );
        })}
        {stage !== "leafing" && stage !== "seed" && stage !== "sprout" && (
          <li>
            <button
              type="button"
              onClick={() => onOpen("apex")}
              onMouseEnter={() => onHover("apex")}
              onMouseLeave={() => onHover(null)}
              className={`focus-ring grid w-full grid-cols-[26px_1fr_auto] items-baseline gap-x-2 py-2 text-left ${highlight === "apex" ? "bg-cobalt-soft/60" : "hover:bg-paper-2/60"}`}
            >
              <span className="ink text-center text-[15px]">∗</span>
              <span>
                <span className="block text-[14.5px] text-ink">{apex}</span>
                {!compact && (
                  <span className="typewriter block text-[10.5px] text-ink-3">
                    {stage === "ripe" ? l.onBranch : l.forming}
                  </span>
                )}
              </span>
              <span className="typewriter inline-flex items-center gap-1 text-[10.5px] text-cobalt">
                {finalId ? (
                  <>
                    {l.read} <ArrowRight className="h-3 w-3" strokeWidth={1.75} aria-hidden />
                  </>
                ) : (
                  <span className="text-ink-3">{l.soon}</span>
                )}
              </span>
            </button>
          </li>
        )}
        {review && (
          <li>
            <button
              type="button"
              onClick={() => onOpen("tag")}
              onMouseEnter={() => onHover("tag")}
              onMouseLeave={() => onHover(null)}
              className={`focus-ring grid w-full grid-cols-[26px_1fr_auto] items-baseline gap-x-2 py-2 text-left ${highlight === "tag" ? "bg-cobalt-soft/60" : "hover:bg-paper-2/60"}`}
            >
              <span className="ink text-center text-[14px]">r</span>
              <span>
                <span className="block text-[14.5px] text-ink">{t.plot.tag.title}</span>
                {!compact && <span className="typewriter block text-[10.5px] text-ink-3">{l.review}</span>}
              </span>
              <span className="typewriter inline-flex items-center gap-1 text-[10.5px] text-cobalt">
                {l.read} <ArrowRight className="h-3 w-3" strokeWidth={1.75} aria-hidden />
              </span>
            </button>
          </li>
        )}
      </ol>
    </div>
  );
}
