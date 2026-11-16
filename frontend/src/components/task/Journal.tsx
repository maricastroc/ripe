"use client";

import { AnimatePresence, motion } from "motion/react";
import { useState } from "react";
import { GrowthRings } from "@/components/task/GrowthRings";
import { formatDayStamp } from "@/lib/format";
import { useI18n } from "@/lib/i18n/context";
import type { EventView, NoteKind } from "@/lib/types";

const DOT: Record<NoteKind, string> = {
  user: "bg-ink",
  info: "bg-line-2",
  work: "bg-leaf-2",
  done: "bg-leaf",
  warn: "bg-ochre",
  error: "bg-accent",
};

const WHO: Record<NoteKind, "you" | "ripe" | "agent"> = {
  user: "you",
  info: "ripe",
  work: "agent",
  done: "agent",
  warn: "agent",
  error: "agent",
};

type View = "notes" | "rings";

const VIEWS: View[] = ["notes", "rings"];

export function Journal({ events, taskId, version }: { events: EventView[]; taskId: string; version: number }) {
  const j = useI18n().t.journal;
  const [view, setView] = useState<View>("notes");
  if (!events.length) return null;
  return (
    <section aria-labelledby="journal-title">
      <div className="mb-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
        <h2 id="journal-title" className="label">
          {j.heading}
        </h2>
        <div role="tablist" aria-label={j.views} className="flex gap-3 text-[13px]">
          {VIEWS.map((id) => (
            <button
              key={id}
              id={`journal-tab-${id}`}
              role="tab"
              type="button"
              aria-selected={view === id}
              aria-controls={`journal-panel-${id}`}
              onClick={() => setView(id)}
              className={`focus-ring rounded px-0.5 underline-offset-4 ${
                view === id ? "text-ink underline decoration-dotted" : "text-ink-3 hover:text-ink"
              }`}
            >
              {j.view[id]}
            </button>
          ))}
        </div>
      </div>
      <div role="tabpanel" id={`journal-panel-${view}`} aria-labelledby={`journal-tab-${view}`}>
        {view === "notes" ? <Notes events={events} /> : <GrowthRings taskId={taskId} version={version} />}
      </div>
    </section>
  );
}

function Notes({ events }: { events: EventView[] }) {
  const i18n = useI18n();
  const j = i18n.t.journal;
  const [all, setAll] = useState(false);
  const shown = all ? events : events.slice(0, 7);
  return (
    <>
      <ol className="font-mono text-[12.5px] leading-relaxed">
        <AnimatePresence initial={false}>
          {shown.map((e) => (
            <motion.li
              key={e.id}
              layout
              initial={{ opacity: 0, height: 0 }}
              animate={{ opacity: 1, height: "auto" }}
              exit={{ opacity: 0, height: 0 }}
              className="grid grid-cols-[auto_1fr] gap-x-3 border-b border-dashed border-line py-2.5 sm:grid-cols-[118px_1fr]"
            >
              <span className="whitespace-nowrap text-ink-3" suppressHydrationWarning>
                {formatDayStamp(i18n, e.createdAt)}
              </span>
              <span className="col-span-2 flex gap-2 sm:col-span-1">
                <span className={`mt-[0.55em] h-1.5 w-1.5 shrink-0 rounded-full ${DOT[e.kind]}`} aria-hidden />
                <span className={e.kind === "user" ? "text-ink" : "text-ink-2"}>
                  <span className="text-ink-3">{j.who[WHO[e.kind]]} · </span>
                  {e.message}
                </span>
              </span>
            </motion.li>
          ))}
        </AnimatePresence>
      </ol>
      {events.length > 7 && (
        <button
          type="button"
          onClick={() => setAll((a) => !a)}
          className="focus-ring mt-3 rounded-full px-1 text-[13px] text-ink-3 underline-offset-4 hover:text-ink hover:underline"
        >
          {all ? j.less : j.all(events.length)}
        </button>
      )}
    </>
  );
}
