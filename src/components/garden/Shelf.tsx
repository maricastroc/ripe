"use client";

import { motion } from "motion/react";
import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { EmptyPot, Plant } from "@/components/plant/Plant";
import { formatReturn } from "@/lib/format";
import { stageName } from "@/lib/growth";
import { TONE_CLASS, TONE_DOT, moodOf, statusLine } from "@/lib/presence";
import type { AgentStatus, TaskSummary } from "@/lib/types";

const glide = { type: "spring", stiffness: 120, damping: 20 } as const;

function useColumns(min: number, minMobile: number) {
  const ref = useRef<HTMLDivElement>(null);
  const [columns, setColumns] = useState(4);
  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    const measure = () => {
      const width = el.clientWidth;
      const target = width < 640 ? minMobile : min;
      setColumns(Math.max(2, Math.min(6, Math.floor(width / target))));
    };
    measure();
    const observer = new ResizeObserver(measure);
    observer.observe(el);
    return () => observer.disconnect();
  }, [min, minMobile]);
  return { ref, columns };
}

function chunk<T>(items: T[], size: number) {
  const rows: T[][] = [];
  for (let i = 0; i < items.length; i += size) rows.push(items.slice(i, i + size));
  return rows;
}

export function Board({ first, last }: { first: boolean; last: boolean }) {
  return (
    <div className="relative" aria-hidden>
      <div className={`shelf-board h-3 ${first ? "rounded-l-[3px]" : ""} ${last ? "rounded-r-[3px]" : ""}`} />
      {first && (
        <div className="absolute left-[16%] top-3 h-4 w-2 rounded-b-sm" style={{ background: "var(--wood-2)" }} />
      )}
      {last && (
        <div className="absolute right-[16%] top-3 h-4 w-2 rounded-b-sm" style={{ background: "var(--wood-2)" }} />
      )}
    </div>
  );
}

function Spot({
  task,
  agent,
  fresh,
  first,
  last,
}: {
  task: TaskSummary;
  agent: AgentStatus;
  fresh: boolean;
  first: boolean;
  last: boolean;
}) {
  const line = statusLine(task, agent);
  const mood = moodOf(task);
  const back = task.status !== "ripe" && task.status !== "harvested" ? formatReturn(task.returnAt) : null;
  return (
    <Link
      href={`/t/${task.id}`}
      className="focus-ring group block rounded-lg"
      aria-label={`${task.title} — ${stageName(task.growth.stage)}, ${line.text}`}
    >
      <motion.div layoutId={`plant-${task.id}`} transition={glide} className="relative z-10 px-[9%]">
        <div className="transition-transform duration-500 ease-out group-hover:-translate-y-1.5">
          <Plant
            seed={task.id}
            growth={task.growth}
            leaves={task.leaves}
            fruit={task.fruit}
            mood={mood}
            entrance={fresh}
            className="block h-auto w-full"
          />
        </div>
      </motion.div>
      <Board first={first} last={last} />
      <motion.div layoutId={`label-${task.id}`} transition={glide} className="px-2 pb-2 pt-6 sm:px-3">
        <div className="flex items-baseline gap-2">
          <span className="label">{stageName(task.growth.stage)}</span>
          {task.growth.stepsTotal > 0 && (
            <span className="font-mono text-[11px] text-ink-3">
              {task.growth.stepsDone}/{task.growth.stepsTotal}
            </span>
          )}
          {fresh && <span className="font-mono text-[11px] text-accent-ink">recém-plantada</span>}
        </div>
        <h3 className="mt-1.5 line-clamp-2 font-display text-[17px] leading-snug tracking-tight text-ink decoration-line-2 underline-offset-4 group-hover:underline sm:text-lg">
          {task.title}
        </h3>
        <p className={`mt-1.5 flex items-start gap-1.5 text-[13px] leading-snug ${TONE_CLASS[line.tone]}`}>
          <span
            className={`mt-[0.42em] h-1.5 w-1.5 shrink-0 rounded-full ${TONE_DOT[line.tone]} ${mood === "working" ? "dot-live" : ""}`}
          />
          <span className="line-clamp-2">{line.text}</span>
        </p>
        {back && (
          <p className="mt-1 text-[12px] text-ink-3" suppressHydrationWarning>
            {back}
          </p>
        )}
      </motion.div>
    </Link>
  );
}

export function Shelf({ tasks, agent, freshId }: { tasks: TaskSummary[]; agent: AgentStatus; freshId: string | null }) {
  const { ref, columns } = useColumns(232, 160);
  const rows = chunk(tasks, columns);

  return (
    <div ref={ref} className="flex w-full flex-col gap-8">
      {rows.map((row, r) => (
        <div key={r} className="flex items-start">
          {Array.from({ length: columns }).map((_, c) => {
            const task = row[c];
            const first = c === 0;
            const last = c === columns - 1;
            return (
              <div key={task?.id ?? `empty-${c}`} className="min-w-0" style={{ width: `${100 / columns}%` }}>
                {task ? (
                  <Spot task={task} agent={agent} fresh={task.id === freshId} first={first} last={last} />
                ) : (
                  <div aria-hidden>
                    <div className="px-[9%]">
                      <div style={{ aspectRatio: "200 / 270" }} />
                    </div>
                    <Board first={first} last={last} />
                  </div>
                )}
              </div>
            );
          })}
        </div>
      ))}
    </div>
  );
}

export function EmptyShelf({ onSuggest, onExample }: { onSuggest: (text: string) => void; onExample: () => void }) {
  const suggestions = [
    "Escrever uma proposta de projeto",
    "Preparar uma apresentação",
    "Planejar uma viagem",
    "Responder um e-mail difícil",
    "Organizar as ideias para um artigo",
  ];
  return (
    <div className="grid items-end gap-8 md:grid-cols-[210px_1fr] md:gap-14">
      <div className="mx-auto w-40 md:mx-0 md:w-full">
        <div className="px-[9%]">
          <EmptyPot seed="vazio" className="block h-auto w-full" />
        </div>
        <Board first last />
      </div>
      <div className="pb-1">
        <p className="font-display text-2xl leading-snug tracking-tight text-ink sm:text-[1.7rem]">
          O parapeito está vazio. <span className="italic text-ink-2">Por enquanto.</span>
        </p>
        <p className="mt-3 max-w-xl text-[15px] leading-relaxed text-ink-2">
          Escreva acima algo que você vem empurrando com a barriga. Quando você deixar para depois, o agente começa a
          trabalhar nisso aos poucos — e a planta só cresce quando existe trabalho de verdade pronto.
        </p>
        <div className="mt-5 flex flex-wrap gap-2">
          {suggestions.map((s) => (
            <button
              key={s}
              type="button"
              onClick={() => onSuggest(`${s} `)}
              className="focus-ring rounded-full border border-line bg-surface/60 px-3.5 py-1.5 text-[14px] text-ink-2 transition-colors hover:border-line-2 hover:text-ink"
            >
              {s}
            </button>
          ))}
        </div>
        <button
          type="button"
          onClick={onExample}
          className="focus-ring mt-6 text-[14px] text-ink-3 underline decoration-dashed underline-offset-4 hover:text-ink-2"
        >
          ou veja um exemplo já maduro, escrito à mão para demonstração
        </button>
      </div>
    </div>
  );
}
