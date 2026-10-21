"use client";

import { AnimatePresence, motion } from "motion/react";
import { StageGlyph } from "@/components/plant/StageGlyph";
import { STAGES, stageIndex } from "@/lib/growth";
import type { Growth } from "@/lib/types";

export function StageStrip({ growth }: { growth: Growth }) {
  const current = stageIndex(growth.stage);
  const stage = STAGES[current];
  return (
    <div>
      <ol className="relative flex items-center justify-between" aria-label="Estágios de amadurecimento">
        <span className="absolute inset-x-3 top-1/2 h-px -translate-y-1/2 bg-line-2" aria-hidden />
        <motion.span
          className="absolute left-3 top-1/2 h-px -translate-y-1/2 bg-leaf"
          aria-hidden
          initial={false}
          animate={{
            width: `calc(${(current / (STAGES.length - 1)) * 100}% - ${(current / (STAGES.length - 1)) * 24}px)`,
          }}
          transition={{ duration: 1.2, ease: [0.22, 1, 0.36, 1] }}
        />
        {STAGES.map((s, i) => {
          const reached = i <= current;
          const active = i === current;
          return (
            <li key={s.id} className="relative z-10" aria-current={active ? "step" : undefined}>
              <span
                className={`flex items-center justify-center rounded-full bg-paper transition-all duration-700 ${
                  active ? "h-11 w-11 ring-1 ring-ink/70" : "h-7 w-7"
                } ${reached ? "opacity-100" : "opacity-35 grayscale"}`}
                title={`${s.name} — ${s.hint}`}
              >
                <StageGlyph stage={s.id} className={active ? "h-7 w-7" : "h-5 w-5"} />
              </span>
              <span className="sr-only">
                {s.name}
                {active ? " (atual)" : reached ? " (alcançado)" : ""}
              </span>
            </li>
          );
        })}
      </ol>
      <AnimatePresence mode="wait" initial={false}>
        <motion.div
          key={stage.id}
          initial={{ opacity: 0, y: 6 }}
          animate={{ opacity: 1, y: 0 }}
          exit={{ opacity: 0, y: -6 }}
          transition={{ duration: 0.5 }}
          className="mt-5"
        >
          <p className="font-display font-display-wonk text-[2rem] italic leading-none tracking-tight">{stage.name}</p>
          <p className="mt-2 text-[14px] text-ink-2">{stage.hint}.</p>
        </motion.div>
      </AnimatePresence>
    </div>
  );
}
