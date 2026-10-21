"use client";

import { motion } from "motion/react";
import { StageGlyph } from "@/components/plant/StageGlyph";
import { STAGES } from "@/lib/growth";

export function Legend() {
  return (
    <aside
      className="hidden self-end border-l border-dashed border-line-2 pl-8 lg:block"
      aria-labelledby="legend-title"
    >
      <h2 id="legend-title" className="label">
        como uma tarefa amadurece
      </h2>
      <ol className="mt-5 space-y-3.5">
        {STAGES.map((stage, i) => (
          <motion.li
            key={stage.id}
            initial={{ opacity: 0, x: 8 }}
            animate={{ opacity: 1, x: 0 }}
            transition={{ delay: 0.6 + i * 0.09, duration: 0.6 }}
            className="flex items-start gap-3"
          >
            <StageGlyph stage={stage.id} className="mt-0.5 h-6 w-6 shrink-0" />
            <div>
              <p className="font-display text-[1.05rem] italic leading-none">{stage.name}</p>
              <p className="mt-1 text-[12.5px] leading-snug text-ink-3">{stage.hint}</p>
            </div>
          </motion.li>
        ))}
      </ol>
      <p className="mt-6 text-[12.5px] leading-relaxed text-ink-3">
        A planta só cresce quando o agente termina trabalho de verdade. Tempo parado não amadurece nada.
      </p>
    </aside>
  );
}
