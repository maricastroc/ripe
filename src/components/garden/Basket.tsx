"use client";

import { AnimatePresence, motion } from "motion/react";
import Link from "next/link";
import { useState } from "react";
import { formatRelative } from "@/lib/format";
import { FRUITS } from "@/lib/growth";
import type { TaskSummary } from "@/lib/types";

export function Basket({ tasks, onReplant }: { tasks: TaskSummary[]; onReplant: (id: string) => void }) {
  const [open, setOpen] = useState(false);
  if (!tasks.length) return null;
  return (
    <section className="mt-20" aria-labelledby="basket-title">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        className="focus-ring group flex w-full items-center gap-4 text-left"
      >
        <svg viewBox="0 0 64 40" className="h-9 w-14 shrink-0" aria-hidden>
          <path d="M6 14 H58 L52 36 Q51.5 38 49 38 H15 Q12.5 38 12 36 Z" style={{ fill: "var(--wood)" }} />
          <path d="M9 22 H55 M11 29 H53" style={{ stroke: "var(--wood-2)", strokeWidth: 1.4 }} />
          <path d="M14 14 Q32 -6 50 14" fill="none" style={{ stroke: "var(--wood-2)", strokeWidth: 2.4 }} />
          {tasks.slice(0, 4).map((t, i) => (
            <circle key={t.id} cx={18 + i * 9.5} cy={12} r={5.5} fill={FRUITS[t.fruit ?? "caqui"].ripe} />
          ))}
        </svg>
        <div className="min-w-0 flex-1">
          <h2 id="basket-title" className="font-display text-xl tracking-tight">
            Cesta{" "}
            <span className="text-ink-3">
              · {tasks.length} colhida{tasks.length > 1 ? "s" : ""}
            </span>
          </h2>
          <p className="text-[13px] text-ink-3">Tarefas que você já levou. Os materiais continuam guardados.</p>
        </div>
        <span className="label transition-colors group-hover:text-ink-2">{open ? "fechar" : "abrir"}</span>
      </button>
      <AnimatePresence initial={false}>
        {open && (
          <motion.ul
            initial={{ height: 0, opacity: 0 }}
            animate={{ height: "auto", opacity: 1 }}
            exit={{ height: 0, opacity: 0 }}
            className="mt-4 overflow-hidden border-t border-line"
          >
            {tasks.map((t) => (
              <li key={t.id} className="flex items-center gap-4 border-b border-line py-3.5">
                <span
                  className="h-3 w-3 shrink-0 rounded-full"
                  style={{ background: FRUITS[t.fruit ?? "caqui"].ripe }}
                />
                <Link
                  href={`/t/${t.id}`}
                  className="focus-ring min-w-0 flex-1 truncate font-display text-[17px] hover:underline"
                >
                  {t.title}
                </Link>
                <span className="hidden text-[12px] text-ink-3 sm:inline" suppressHydrationWarning>
                  {t.harvestedAt ? formatRelative(t.harvestedAt) : ""}
                </span>
                <button
                  type="button"
                  onClick={() => onReplant(t.id)}
                  className="focus-ring rounded-full px-2 py-1 text-[13px] text-ink-3 hover:text-ink"
                >
                  devolver ao parapeito
                </button>
              </li>
            ))}
          </motion.ul>
        )}
      </AnimatePresence>
    </section>
  );
}
