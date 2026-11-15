"use client";

import { AnimatePresence, motion } from "motion/react";
import { useState } from "react";
import { formatDayStamp } from "@/lib/format";
import type { EventKind, EventView } from "@/lib/types";

const DOT: Record<EventKind, string> = {
  user: "bg-ink",
  info: "bg-line-2",
  work: "bg-leaf-2",
  done: "bg-leaf",
  warn: "bg-ochre",
  error: "bg-accent",
};

const WHO: Record<EventKind, string> = {
  user: "you",
  info: "ripe",
  work: "agent",
  done: "agent",
  warn: "agent",
  error: "agent",
};

export function Journal({ events }: { events: EventView[] }) {
  const [all, setAll] = useState(false);
  const shown = all ? events : events.slice(0, 7);
  if (!events.length) return null;
  return (
    <section aria-labelledby="journal-title">
      <div className="mb-3 flex items-baseline justify-between">
        <h2 id="journal-title" className="label">
          agent journal
        </h2>
        <span className="text-[13px] text-ink-3">what actually happened</span>
      </div>
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
                {formatDayStamp(e.createdAt)}
              </span>
              <span className="col-span-2 flex gap-2 sm:col-span-1">
                <span className={`mt-[0.55em] h-1.5 w-1.5 shrink-0 rounded-full ${DOT[e.kind]}`} aria-hidden />
                <span className={e.kind === "user" ? "text-ink" : "text-ink-2"}>
                  <span className="text-ink-3">{WHO[e.kind]} · </span>
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
          {all ? "show less" : `show the whole journal (${events.length})`}
        </button>
      )}
    </section>
  );
}
