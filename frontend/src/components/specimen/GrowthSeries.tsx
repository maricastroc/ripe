"use client";

import { StageGlyph } from "@/components/flora/StageGlyph";
import { useI18n } from "@/lib/i18n/context";
import { STAGES, stageIndex } from "@/lib/growth";
import type { Growth } from "@/lib/types";

export function GrowthSeries({ growth, compact = false }: { growth: Growth; compact?: boolean }) {
  const { t } = useI18n();
  const current = stageIndex(growth.stage);
  const stage = t.stages[STAGES[current]];
  return (
    <div>
      <ol className="grid grid-cols-6 gap-1" aria-label={t.series.label}>
        {STAGES.map((s, i) => {
          const reached = i <= current;
          const active = i === current;
          return (
            <li key={s} className="flex flex-col items-center gap-1" aria-current={active ? "step" : undefined}>
              <span
                className={`relative flex items-center justify-center rounded-full transition-all duration-700 ${compact ? "h-8 w-8" : "h-10 w-10"} ${
                  active ? "" : reached ? "opacity-90" : "opacity-30 grayscale"
                }`}
              >
                {active && (
                  <svg viewBox="0 0 40 40" className="absolute inset-0 h-full w-full" aria-hidden>
                    <path
                      d="M 20 2.5 C 31 2, 38 9, 37.5 20 C 37 31, 30 38, 19 37.5 C 8 37, 2 30, 2.5 19 C 3 9, 10 3, 21 3.5"
                      fill="none"
                      stroke="var(--cobalt)"
                      strokeWidth={1.3}
                      strokeLinecap="round"
                    />
                  </svg>
                )}
                <StageGlyph stage={s} className={compact ? "h-5 w-5" : "h-6 w-6"} />
              </span>
              {!compact && (
                <span className={`typewriter text-[9.5px] ${active ? "text-cobalt" : "text-ink-3"}`}>fig. {i + 1}</span>
              )}
              <span className="sr-only">
                {t.stages[s].name}
                {active ? ` ${t.series.current}` : reached ? ` ${t.series.reached}` : ""}
              </span>
            </li>
          );
        })}
      </ol>
      <p className="mt-3 text-center">
        <span className={`ink ${compact ? "text-[1rem]" : "text-[1.15rem]"}`}>
          fig. {current + 1} · {stage.name}.
        </span>
        {!compact && (
          <>
            {" "}
            <span className="text-[13.5px] text-ink-2">{stage.hint}.</span>
          </>
        )}
      </p>
    </div>
  );
}
