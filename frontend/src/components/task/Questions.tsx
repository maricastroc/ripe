"use client";

import { AnimatePresence, motion } from "motion/react";
import { useState } from "react";
import { useI18n } from "@/lib/i18n/context";
import type { QuestionView } from "@/lib/types";

type Props = {
  questions: QuestionView[];
  disabled: boolean;
  canRefresh: boolean;
  onAnswer: (id: string, answer: string) => Promise<void>;
  onSkip: (id: string) => Promise<void>;
  onReopen: (id: string) => Promise<void>;
  onRefresh: () => Promise<void>;
};

function OpenQuestion({
  q,
  disabled,
  onAnswer,
  onSkip,
}: {
  q: QuestionView;
  disabled: boolean;
  onAnswer: Props["onAnswer"];
  onSkip: Props["onSkip"];
}) {
  const q2 = useI18n().t.questions;
  const [value, setValue] = useState("");
  const [sending, setSending] = useState(false);
  const submit = async () => {
    if (!value.trim()) return;
    setSending(true);
    try {
      await onAnswer(q.id, value);
    } finally {
      setSending(false);
    }
  };
  return (
    <motion.li
      layout
      initial={{ opacity: 0, y: 8 }}
      animate={{ opacity: 1, y: 0 }}
      exit={{ opacity: 0, height: 0 }}
      className={`border-l-2 py-1 pl-5 ${q.kind === "essential" ? "border-accent" : "border-line-2"}`}
    >
      <p className="font-display text-[1.2rem] leading-snug tracking-tight">{q.text}</p>
      <p className="mt-1 text-[13px] text-ink-3">
        {q.kind === "essential" ? (
          <span className="font-mono uppercase tracking-wider text-accent-ink">{q2.essential} · </span>
        ) : null}
        {q.why}
      </p>
      {disabled ? (
        <p className="mt-2 text-[14px] italic text-ink-3">{q2.notAnswered}</p>
      ) : (
        <form
          className="mt-3"
          onSubmit={(e) => {
            e.preventDefault();
            void submit();
          }}
        >
          <label className="sr-only" htmlFor={`q-${q.id}`}>
            {q2.answerTo(q.text)}
          </label>
          <textarea
            id={`q-${q.id}`}
            rows={2}
            value={value}
            disabled={disabled || sending}
            onChange={(e) => setValue(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter" && (e.metaKey || e.ctrlKey)) void submit();
            }}
            placeholder={q2.placeholder}
            className="focus-ring block w-full resize-y rounded-xl border border-line bg-surface px-4 py-3 text-[15px] leading-relaxed placeholder:text-ink-3 focus:border-line-2 focus:outline-none"
          />
          <div className="mt-2.5 flex flex-wrap items-center gap-3">
            <button
              type="submit"
              disabled={disabled || sending || !value.trim()}
              className="focus-ring rounded-full bg-ink px-4 py-2 text-[14px] font-medium text-paper transition-opacity hover:bg-leaf-dark disabled:opacity-40"
            >
              {sending ? q2.sending : q2.answer}
            </button>
            <button
              type="button"
              disabled={disabled || sending}
              onClick={() => void onSkip(q.id)}
              className="focus-ring rounded-full px-2 py-1.5 text-[13px] text-ink-3 underline-offset-4 hover:text-ink-2 hover:underline"
            >
              {q2.skip}
            </button>
          </div>
        </form>
      )}
    </motion.li>
  );
}

export function Questions({ questions, disabled, canRefresh, onAnswer, onSkip, onReopen, onRefresh }: Props) {
  const q2 = useI18n().t.questions;
  const [refreshing, setRefreshing] = useState(false);
  const open = questions
    .filter((q) => q.status === "open")
    .sort((a, b) => Number(b.kind === "essential") - Number(a.kind === "essential"));
  const closed = questions.filter((q) => q.status !== "open");
  const essential = open.some((q) => q.kind === "essential");
  if (!questions.length) return null;

  return (
    <section aria-labelledby="questions-title">
      <div className="mb-4 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2 id="questions-title" className="label shrink-0 whitespace-nowrap">
          {essential ? q2.forYou : open.length ? q2.optional : q2.yours}
        </h2>
        {open.length > 0 && (
          <span className="text-[13px] text-ink-3">{essential ? q2.wontGuess(open.length) : q2.fillGaps}</span>
        )}
      </div>

      <ul className="space-y-7">
        <AnimatePresence initial={false}>
          {open.map((q) => (
            <OpenQuestion key={q.id} q={q} disabled={disabled} onAnswer={onAnswer} onSkip={onSkip} />
          ))}
        </AnimatePresence>
      </ul>

      {closed.length > 0 && (
        <ul className={`${open.length ? "mt-8" : ""} divide-y divide-line border-y border-line`}>
          {closed.map((q) => (
            <motion.li layout key={q.id} className="flex items-start gap-4 py-3">
              <div className="min-w-0 flex-1">
                <p className="text-[14px] text-ink-2">{q.text}</p>
                <p className={`mt-0.5 text-[15px] ${q.status === "answered" ? "text-ink" : "italic text-ink-3"}`}>
                  {q.status === "answered" ? q.answer : q2.skipped}
                </p>
              </div>
              {!disabled && (
                <button
                  type="button"
                  onClick={() => void onReopen(q.id)}
                  className="focus-ring shrink-0 rounded-full px-2 py-1 text-[12px] text-ink-3 hover:text-ink"
                >
                  {q2.change}
                </button>
              )}
            </motion.li>
          ))}
        </ul>
      )}

      {canRefresh && !disabled && (
        <div className="mt-5 flex flex-wrap items-center gap-3 rounded-2xl border border-dashed border-line-2 px-4 py-3">
          <p className="min-w-0 flex-1 text-[14px] text-ink-2">{q2.stale}</p>
          <button
            type="button"
            disabled={refreshing}
            onClick={async () => {
              setRefreshing(true);
              try {
                await onRefresh();
              } finally {
                setRefreshing(false);
              }
            }}
            className="focus-ring rounded-full border border-ink px-4 py-1.5 text-[13px] font-medium hover:bg-ink hover:text-paper"
          >
            {refreshing ? q2.asking : q2.update}
          </button>
        </div>
      )}
    </section>
  );
}
