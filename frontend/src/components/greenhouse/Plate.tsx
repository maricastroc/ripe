import { LoaderCircle } from "lucide-react";
import type { ReactNode } from "react";
import { useI18n } from "@/lib/i18n/context";

export function PlateSheet({ label, children }: { label: string; children: ReactNode }) {
  const { t } = useI18n();
  return (
    <section aria-label={label} className="plate relative">
      <div aria-hidden className="plate-frame pointer-events-none absolute border-[1.2px] border-ink/75" />
      {children}
      <div
        aria-hidden
        className="plate-credit absolute flex justify-between font-display text-[12px] italic text-ink-2"
      >
        <span>{t.plate.credit}</span>
        <span>{t.plate.number}</span>
      </div>
    </section>
  );
}

type Census = { total: number; growing: number; waiting: number; ripe: number };

export function CensusKey({ census }: { census: Census }) {
  const { t } = useI18n();
  const c = t.greenhouse.counts;
  const lines = [
    { n: census.growing, text: c.growing(census.growing), dot: "var(--leaf)" },
    { n: census.waiting, text: c.waiting(census.waiting), dot: "var(--ochre)" },
    { n: census.ripe, text: c.ripe(census.ripe), dot: "var(--vermilion)" },
  ].filter((l) => l.n > 0);
  return (
    <div className="plate-key relative z-20 self-start pb-4 pl-1.5 pr-6 pt-6">
      <h2 className="typewriter text-[10.5px] uppercase tracking-[0.2em] text-ink-2">
        {t.plate.inHouse(census.total)}
      </h2>
      {lines.length > 0 && (
        <ul className="typewriter mt-2 flex flex-col gap-1 text-[12px] leading-[17px] text-ink">
          {lines.map((l) => (
            <li key={l.dot} className="flex items-center gap-2.5">
              <span
                aria-hidden
                className="h-2 w-2 flex-none rounded-full border border-ink"
                style={{ background: l.dot }}
              />
              {l.text}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export function MyceliumNote({
  text,
  dot,
  working,
  className = "",
}: {
  text: string;
  dot: string;
  working: boolean;
  className?: string;
}) {
  const { t } = useI18n();
  return (
    <div className={className}>
      <p className="plate-agent-label typewriter mb-1 text-[10.5px] uppercase tracking-[0.2em] text-line">
        {t.greenhouse.myceliumName}
      </p>
      <p className="flex items-start gap-2 text-[13.5px] leading-[19px] text-root">
        <span
          aria-hidden
          className={`mt-[6px] h-[7px] w-[7px] flex-none rounded-full ${working ? "live-dot text-myc" : ""}`}
          style={{ background: dot, boxShadow: working ? "0 0 8px var(--myc-glow)" : undefined }}
        />
        <span className="max-w-[24rem]">{text}</span>
      </p>
    </div>
  );
}

export function ExampleLink({ onOpen, opening }: { onOpen: () => void; opening: boolean }) {
  const { t } = useI18n();
  return (
    <button
      type="button"
      onClick={onOpen}
      disabled={opening}
      aria-busy={opening}
      className="focus-ring inline-flex min-h-11 items-center gap-1.5 text-left font-display text-[14.5px] italic text-root underline decoration-line/60 decoration-1 underline-offset-[3px] hover:text-paper disabled:no-underline lg:min-h-0"
    >
      {opening && (
        <LoaderCircle className="h-3.5 w-3.5 animate-spin motion-reduce:animate-none" strokeWidth={2} aria-hidden />
      )}
      {opening ? t.greenhouse.empty.openingExample : t.plate.example}
    </button>
  );
}

const SEED_TILT = [-20, 15, -35];

export function DormantSeeds({ onPick }: { onPick: (seed: string) => void }) {
  const { t } = useI18n();
  const g = t.greenhouse;
  return (
    <div className="plate-seeds relative z-20 self-start pb-6 pl-1.5 lg:pb-2 lg:pl-[26px]">
      <p className="mt-5 font-display text-[16px] leading-[21px] text-paper lg:ml-[128px] lg:mt-16">
        {g.empty.title} <em className="font-display-wonk">{g.empty.titleTail}</em>
      </p>
      <ul aria-label={t.plate.dormant} className="plate-seed-list mt-3 flex flex-col items-start lg:ml-2.5 lg:mt-5">
        {g.suggestions.slice(0, SEED_TILT.length).map((s, i) => (
          <li key={s}>
            <button
              type="button"
              onClick={() => onPick(s)}
              className="plate-seed focus-ring group inline-flex min-h-11 items-center gap-2.5 text-left font-display text-[15.5px] italic text-root lg:min-h-7"
            >
              <span
                aria-hidden
                className="h-[7px] w-[11px] flex-none rounded-full bg-wood-3"
                style={{ rotate: `${SEED_TILT[i]}deg` }}
              />
              <span className="font-display-wonk border-b border-dotted border-line/70 transition-colors group-hover:border-paper group-hover:text-paper">
                {s}
              </span>
            </button>
          </li>
        ))}
      </ul>
    </div>
  );
}
