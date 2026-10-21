"use client";

import { ArrowRight } from "lucide-react";
import { OUTPUT_LABEL } from "@/lib/labels";
import type { ArtifactView, QuestionView, StepStatus, StepView, TaskDetail } from "@/lib/types";

const STATE: Record<StepStatus, { organ: string; word: string; tone: string }> = {
  done: { organ: "leafy branch", word: "ready", tone: "text-leaf-dark" },
  working: { organ: "opening bud", word: "being written", tone: "text-cobalt" },
  pending: { organ: "closed bud", word: "not started", tone: "text-ink-3" },
  blocked: { organ: "dry bud", word: "waiting on you", tone: "text-vermilion-ink" },
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
  return step.needs.map((id) => map.get(id)).filter(Boolean) as string[];
}

export function Legend({ task, finalId, review, highlight, onHover, onOpen, compact = false }: Props) {
  if (!task.steps.length) {
    return (
      <p className="ink text-center text-[15px]">
        {task.status === "dormant"
          ? "Dormant. The parts appear once the agent maps out the work."
          : "No parts to label yet. The agent is still reading the request."}
      </p>
    );
  }
  const stage = task.growth.stage;
  const fruitWord =
    stage === "ripe"
      ? "ripe fruit"
      : stage === "fruit"
        ? "young fruit"
        : stage === "blossom"
          ? "blossom"
          : "growing tip";
  return (
    <div>
      <p className="label mb-2">key to the plate</p>
      <ol className="divide-y divide-dashed divide-line-2 border-y border-dashed border-line-2">
        {task.steps.map((step) => {
          const s = STATE[step.status];
          const lit = highlight === step.id;
          const waits = step.status === "blocked" ? needsText(step, task.questions) : [];
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
                      {s.organ} · {OUTPUT_LABEL[step.output] ?? step.output}
                    </span>
                  )}
                  {waits.length > 0 && (
                    <span className="mt-0.5 block text-[12px] leading-snug text-vermilion-ink">
                      needs: {waits.join(" · ")}
                    </span>
                  )}
                </span>
                <span
                  className={`typewriter inline-flex items-center gap-1 text-[10.5px] ${step.artifactId ? "text-cobalt" : s.tone}`}
                >
                  {step.artifactId ? (
                    <>
                      read <ArrowRight className="h-3 w-3" strokeWidth={1.75} aria-hidden />
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
                <span className="block text-[14.5px] text-ink">The {fruitWord}</span>
                {!compact && (
                  <span className="typewriter block text-[10.5px] text-ink-3">
                    the main result, still {stage === "ripe" ? "on the branch" : "forming"}
                  </span>
                )}
              </span>
              <span className="typewriter inline-flex items-center gap-1 text-[10.5px] text-cobalt">
                {finalId ? (
                  <>
                    read <ArrowRight className="h-3 w-3" strokeWidth={1.75} aria-hidden />
                  </>
                ) : (
                  <span className="text-ink-3">soon</span>
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
                <span className="block text-[14.5px] text-ink">Review tag</span>
                {!compact && (
                  <span className="typewriter block text-[10.5px] text-ink-3">
                    the agent&apos;s review of the whole set
                  </span>
                )}
              </span>
              <span className="typewriter inline-flex items-center gap-1 text-[10.5px] text-cobalt">
                read <ArrowRight className="h-3 w-3" strokeWidth={1.75} aria-hidden />
              </span>
            </button>
          </li>
        )}
      </ol>
    </div>
  );
}
