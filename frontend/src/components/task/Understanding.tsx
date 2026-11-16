"use client";

import { useI18n } from "@/lib/i18n/context";
import { deliverableLabel } from "@/lib/labels";
import type { TaskDetail } from "@/lib/types";

export function Understanding({ task }: { task: TaskDetail }) {
  const { t } = useI18n();
  const u = t.understanding;
  if (!task.outcome) return null;
  return (
    <section aria-labelledby="understood-title">
      <h2 id="understood-title" className="label mb-3">
        {u.heading}
      </h2>
      <p className="font-display text-[1.3rem] leading-snug tracking-tight text-ink">{task.outcome}</p>
      <dl className="mt-5 grid gap-6 sm:grid-cols-2">
        <div>
          <dt className="text-[13px] text-ink-3">{u.said}</dt>
          <dd className="mt-1.5">
            {task.context.length ? (
              <ul className="space-y-1 text-[14.5px] leading-snug text-ink-2">
                {task.context.map((c) => (
                  <li key={c} className="flex gap-2">
                    <span className="mt-[0.55em] h-1 w-1 shrink-0 rounded-full bg-ink-3" />
                    {c}
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-[14.5px] italic text-ink-3">{u.saidNothing}</p>
            )}
          </dd>
        </div>
        <div>
          <dt className="text-[13px] text-ink-3">{u.assumed}</dt>
          <dd className="mt-1.5">
            {task.assumptions.length ? (
              <ul className="space-y-1 text-[14.5px] leading-snug text-ink-2">
                {task.assumptions.map((a) => (
                  <li key={a} className="flex gap-2">
                    <span className="mt-[0.55em] h-1 w-1 shrink-0 rounded-full bg-ink-3" />
                    {a}
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-[14.5px] italic text-ink-3">{u.assumedNothing}</p>
            )}
          </dd>
        </div>
      </dl>
      {task.deliverable && (
        <p className="mt-5 text-[13px] text-ink-3">
          {u.kind} <span className="text-ink-2">{deliverableLabel(t, task.deliverable)}</span>
        </p>
      )}
    </section>
  );
}
