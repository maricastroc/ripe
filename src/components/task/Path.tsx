"use client";

import { AnimatePresence, motion } from "motion/react";
import { OUTPUT_LABEL } from "@/lib/labels";
import type { QuestionView, StepStatus, StepView } from "@/lib/types";

function Node({ status }: { status: StepStatus }) {
  if (status === "done") {
    return (
      <svg viewBox="0 0 20 20" className="h-5 w-5" aria-hidden>
        <path d="M3 15 C 3 7, 9 3, 17 3 C 17 11, 12 16, 3 15 Z" style={{ fill: "var(--leaf)" }} />
        <path d="M4 14 Q 9 9, 15 5" fill="none" style={{ stroke: "var(--leaf-dark)", strokeWidth: 1 }} />
      </svg>
    );
  }
  if (status === "working") {
    return (
      <span className="relative flex h-5 w-5 items-center justify-center">
        <span className="dot-live h-2.5 w-2.5 rounded-full bg-leaf text-leaf" />
      </span>
    );
  }
  if (status === "blocked") {
    return (
      <svg viewBox="0 0 20 20" className="h-5 w-5" aria-hidden>
        <path d="M10 17 C 6 13, 6 7, 10 3 C 14 7, 14 13, 10 17 Z" style={{ fill: "var(--ochre)" }} />
      </svg>
    );
  }
  return (
    <svg viewBox="0 0 20 20" className="h-5 w-5" aria-hidden>
      <path
        d="M10 16 C 7.5 13, 7.5 8, 10 5 C 12.5 8, 12.5 13, 10 16 Z"
        style={{ fill: "none", stroke: "var(--line-2)", strokeWidth: 1.4 }}
      />
    </svg>
  );
}

const STATUS_TEXT: Record<StepStatus, string> = {
  done: "pronta",
  working: "em andamento",
  pending: "a fazer",
  blocked: "esperando você",
};

type Props = {
  steps: StepView[];
  questions: QuestionView[];
  reviewed: boolean;
  planned: boolean;
  onHover: (id: string | null) => void;
  onOpen: (artifactId: string) => void;
};

export function Path({ steps, questions, reviewed, planned, onHover, onOpen }: Props) {
  if (!planned) {
    return (
      <p className="text-[14px] leading-relaxed text-ink-3">
        O caminho aparece aqui assim que o agente entender o pedido e dividir o trabalho em etapas.
      </p>
    );
  }
  const questionText = new Map(questions.map((q) => [q.id, q.text]));
  return (
    <ol className="relative">
      <span className="absolute bottom-3 left-[9.5px] top-3 w-px bg-line-2" aria-hidden />
      <AnimatePresence initial={false}>
        {steps.map((step, i) => {
          const clickable = Boolean(step.artifactId);
          const waitingOn =
            step.status === "blocked" ? step.needs.map((id) => questionText.get(id)).filter(Boolean) : [];
          const body = (
            <>
              <span className="relative z-10 mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center bg-paper">
                <Node status={step.status} />
              </span>
              <span className="min-w-0 flex-1">
                <span className="flex flex-wrap items-baseline gap-x-2">
                  <span className={`text-[15px] leading-snug ${step.status === "done" ? "text-ink" : "text-ink-2"}`}>
                    {step.title}
                  </span>
                  <span className="font-mono text-[10.5px] uppercase tracking-wider text-ink-3">
                    {OUTPUT_LABEL[step.output] ?? step.output} · {STATUS_TEXT[step.status]}
                  </span>
                </span>
                {(step.status === "working" || step.status === "pending") && step.description && (
                  <span className="mt-0.5 block text-[13px] leading-snug text-ink-3">{step.description}</span>
                )}
                {waitingOn.length > 0 && (
                  <span className="mt-1 block text-[13px] leading-snug text-accent-ink">
                    Precisa de: {waitingOn.join(" · ")}
                  </span>
                )}
              </span>
            </>
          );
          return (
            <motion.li
              key={step.id}
              initial={{ opacity: 0, x: -6 }}
              animate={{ opacity: 1, x: 0 }}
              transition={{ delay: i * 0.06 }}
              onMouseEnter={() => onHover(step.id)}
              onMouseLeave={() => onHover(null)}
            >
              {clickable ? (
                <button
                  type="button"
                  onClick={() => onOpen(step.artifactId!)}
                  onFocus={() => onHover(step.id)}
                  onBlur={() => onHover(null)}
                  className="focus-ring group flex w-full items-start gap-3 rounded-lg py-2.5 text-left"
                >
                  {body}
                  <span className="mt-0.5 text-[12px] text-ink-3 opacity-0 transition-opacity group-hover:opacity-100">
                    ler →
                  </span>
                </button>
              ) : (
                <div className="flex items-start gap-3 py-2.5">{body}</div>
              )}
            </motion.li>
          );
        })}
      </AnimatePresence>
      <li className="flex items-start gap-3 py-2.5">
        <span className="relative z-10 mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center bg-paper">
          {reviewed ? (
            <svg viewBox="0 0 20 20" className="h-5 w-5" aria-hidden>
              <circle cx="10" cy="11" r="6.5" fill="#e3672f" />
              <path d="M10 4.5 Q 11 3, 13 3" stroke="var(--leaf-dark)" strokeWidth="1.4" fill="none" />
            </svg>
          ) : (
            <span className="h-2 w-2 rounded-full border border-line-2" />
          )}
        </span>
        <span className="text-[15px] text-ink-2">
          Revisão do conjunto{" "}
          <span className="font-mono text-[10.5px] uppercase tracking-wider text-ink-3">
            · {reviewed ? "pronta" : "no fim"}
          </span>
        </span>
      </li>
    </ol>
  );
}
