"use client";

import { useEffect, useMemo, useState } from "react";
import { api } from "@/lib/api";
import { formatCount, formatDuration } from "@/lib/format";
import type { I18n } from "@/lib/i18n";
import { useI18n } from "@/lib/i18n/context";
import { stepLabel } from "@/lib/labels";
import type { Timeline, TimelineAttempt, RunKind } from "@/lib/types";

type Mark = "ring" | "crack" | "break" | "knot" | "bud";

type Entry = TimelineAttempt & {
  kind: RunKind;
  subject: string | null;
  mark: Mark;
  ring: number | null;
};

type Placed = Entry & { radius: number; angle: number };

const SIZE = 220;
const CENTER = SIZE / 2;
const PITH = 6;
const BARK = 6;
const MAX_RADIUS = CENTER - BARK - 4;
const MIN_WIDTH = 4;
const MAX_WIDTH = 10;
const GOLDEN = Math.PI * (3 - Math.sqrt(5));

function markOf(attempt: TimelineAttempt): Mark {
  if (attempt.savedWork) return "ring";
  switch (attempt.outcome) {
    case null:
      return "bud";
    case "interrupted":
    case "lease_lost":
      return "break";
    case "cancelled":
      return "knot";
    default:
      return "crack";
  }
}

function seed(text: string) {
  let hash = 2166136261;
  for (let i = 0; i < text.length; i++) {
    hash ^= text.charCodeAt(i);
    hash = Math.imul(hash, 16777619);
  }
  return (hash >>> 0) / 4294967295;
}

function ringWidth(durationMs: number | null) {
  const seconds = Math.max(0, (durationMs ?? 0) / 1000);
  const scaled = Math.log10(1 + seconds) / Math.log10(121);
  return MIN_WIDTH + (MAX_WIDTH - MIN_WIDTH) * Math.min(1, scaled);
}

function outline(radius: number, id: string) {
  const s = seed(id);
  const points: string[] = [];
  for (let i = 0; i <= 72; i++) {
    const t = (i / 72) * Math.PI * 2;
    const wobble =
      1 + 0.018 * Math.sin(3 * t + s * 6.28) + 0.011 * Math.sin(5 * t + s * 11.3) + 0.006 * Math.sin(9 * t + s * 3.1);
    const r = radius * wobble;
    points.push(`${(CENTER + r * Math.cos(t)).toFixed(2)},${(CENTER + r * Math.sin(t)).toFixed(2)}`);
  }
  return `M${points.join("L")}Z`;
}

function at(radius: number, angle: number) {
  return { x: CENTER + radius * Math.cos(angle), y: CENTER + radius * Math.sin(angle) };
}

function entriesOf(timeline: Timeline): Entry[] {
  const all = timeline.runs
    .flatMap((run) => run.attempts.map((attempt) => ({ ...attempt, kind: run.kind, subject: run.subject })))
    .sort((a, b) => a.startedAt.localeCompare(b.startedAt));
  let ring = 0;
  return all.map((attempt) => {
    const mark = markOf(attempt);
    return { ...attempt, mark, ring: mark === "ring" ? ++ring : null };
  });
}

function place(entries: Entry[]): { placed: Placed[]; outer: number } {
  const widths = entries.filter((e) => e.mark === "ring").map((e) => ringWidth(e.durationMs));
  const total = widths.reduce((sum, w) => sum + w, 0);
  const scale = PITH + total > MAX_RADIUS ? (MAX_RADIUS - PITH) / total : 1;
  let radius = PITH;
  let marks = 0;
  const placed = entries.map((entry) => {
    if (entry.mark === "ring") {
      radius += ringWidth(entry.durationMs) * scale;
      return { ...entry, radius, angle: 0 };
    }
    const angle = -Math.PI / 2 + marks++ * GOLDEN * 2.4 + seed(entry.id) * 0.4;
    return { ...entry, radius, angle };
  });
  return { placed, outer: radius };
}

function Glyph({ mark, className = "" }: { mark: Mark; className?: string }) {
  return (
    <svg viewBox="0 0 16 16" className={`h-4 w-4 shrink-0 ${className}`} aria-hidden>
      {mark === "ring" && <circle cx="8" cy="8" r="5.5" fill="var(--kraft)" stroke="var(--wood-2)" strokeWidth="1.4" />}
      {mark === "crack" && (
        <>
          <circle cx="8" cy="8" r="5.5" fill="none" stroke="var(--line-2)" strokeWidth="1" />
          <path
            d="M8 1.5 L9.2 5 L7.2 7.6 L8.6 10.5"
            fill="none"
            stroke="var(--vermilion-ink)"
            strokeWidth="1.5"
            strokeLinecap="round"
          />
        </>
      )}
      {mark === "break" && (
        <path
          d="M12.4 4.6 A5.5 5.5 0 1 1 6 2.9"
          fill="none"
          stroke="var(--wood-2)"
          strokeWidth="1.4"
          strokeLinecap="round"
        />
      )}
      {mark === "knot" && (
        <>
          <circle cx="8" cy="8" r="5.5" fill="none" stroke="var(--line-2)" strokeWidth="1" />
          <ellipse cx="8" cy="8" rx="2.6" ry="1.8" fill="var(--paper)" stroke="var(--ink-2)" strokeWidth="1.3" />
        </>
      )}
      {mark === "bud" && <circle cx="8" cy="8" r="3" fill="var(--leaf-2)" stroke="var(--leaf-dark)" strokeWidth="1" />}
    </svg>
  );
}

function Cross({ placed, outer, active }: { placed: Placed[]; outer: number; active: string | null }) {
  const rings = placed.filter((p) => p.mark === "ring").reverse();
  const marks = placed.filter((p) => p.mark !== "ring");
  return (
    <svg viewBox={frame(outer)} className="mx-auto w-full max-w-[220px]" aria-hidden>
      <path d={outline(outer + BARK, "bark")} fill="var(--wood-2)" />
      <path d={outline(outer + BARK * 0.45, "bark-inner")} fill="var(--wood)" opacity="0.55" />
      <path d={outline(Math.max(outer, PITH), "sapwood")} fill="var(--paper-3)" />
      {rings.map((ring, i) => (
        <path
          key={ring.id}
          d={outline(ring.radius, ring.id)}
          fill={i % 2 === 0 ? "var(--kraft)" : "var(--paper-3)"}
          stroke={ring.id === active ? "var(--ink)" : "var(--wood-2)"}
          strokeWidth={ring.id === active ? 1.6 : 0.8}
          strokeOpacity={ring.id === active ? 1 : 0.7}
        />
      ))}
      <circle cx={CENTER} cy={CENTER} r={PITH * 0.6} fill="var(--wood-2)" />
      {marks.map((mark) => {
        const lit = mark.id === active;
        const { x, y } = at(mark.radius, mark.angle);
        if (mark.mark === "crack") {
          const inner = at(Math.max(PITH, mark.radius - 6), mark.angle + 0.05);
          const outerPoint = at(mark.radius + 4, mark.angle - 0.04);
          return (
            <path
              key={mark.id}
              d={`M${inner.x} ${inner.y} L${x} ${y} L${outerPoint.x} ${outerPoint.y}`}
              fill="none"
              stroke="var(--vermilion-ink)"
              strokeWidth={lit ? 2.2 : 1.4}
              strokeLinecap="round"
            />
          );
        }
        if (mark.mark === "break") {
          const from = at(mark.radius, mark.angle - 0.14);
          const to = at(mark.radius, mark.angle + 0.14);
          return (
            <g key={mark.id}>
              <path
                d={`M${from.x} ${from.y} A${mark.radius} ${mark.radius} 0 0 1 ${to.x} ${to.y}`}
                fill="none"
                stroke="var(--paper)"
                strokeWidth={lit ? 3.6 : 2.6}
              />
              <circle cx={from.x} cy={from.y} r="0.9" fill="var(--wood-2)" />
              <circle cx={to.x} cy={to.y} r="0.9" fill="var(--wood-2)" />
            </g>
          );
        }
        if (mark.mark === "knot") {
          return (
            <ellipse
              key={mark.id}
              cx={x}
              cy={y}
              rx={lit ? 3.6 : 2.8}
              ry={lit ? 2.6 : 2}
              transform={`rotate(${(mark.angle * 180) / Math.PI} ${x} ${y})`}
              fill="var(--paper)"
              stroke="var(--ink-2)"
              strokeWidth="1.2"
            />
          );
        }
        return (
          <circle
            key={mark.id}
            cx={x}
            cy={y}
            r={lit ? 3.4 : 2.6}
            fill="var(--leaf-2)"
            stroke="var(--leaf-dark)"
            strokeWidth="1"
          />
        );
      })}
    </svg>
  );
}

function frame(outer: number) {
  const half = Math.max(outer + BARK + 6, 70);
  return `${CENTER - half} ${CENTER - half} ${half * 2} ${half * 2}`;
}

function outcomeText({ t }: I18n, entry: Entry) {
  if (entry.mark === "ring") return null;
  if (entry.mark === "bud") return t.rings.running;
  return t.rings.nothingSaved(entry.error ?? t.rings.unfinished);
}

function details(i18n: I18n, entry: Entry) {
  const r = i18n.t.rings;
  const parts = [formatDuration(i18n, entry.durationMs)];
  if (entry.firstTokenMs !== null) parts.push(r.firstWord(formatDuration(i18n, entry.firstTokenMs)));
  if (entry.promptTokens !== null || entry.completionTokens !== null)
    parts.push(
      r.tokens(
        entry.promptTokens === null ? "?" : formatCount(i18n, entry.promptTokens),
        entry.completionTokens === null ? "?" : formatCount(i18n, entry.completionTokens),
        entry.usageEstimated,
      ),
    );
  if (entry.waitedMs > 0) parts.push(r.waited(formatDuration(i18n, entry.waitedMs)));
  return parts.join(" · ");
}

export function GrowthRings({ taskId, version }: { taskId: string; version: number }) {
  const i18n = useI18n();
  const r = i18n.t.rings;
  const [timeline, setTimeline] = useState<Timeline | null>(null);
  const [failed, setFailed] = useState(false);
  const [active, setActive] = useState<string | null>(null);

  const [retry, setRetry] = useState(0);

  useEffect(() => {
    let current = true;
    api<{ timeline: Timeline }>(`/api/tasks/${taskId}/timeline`).then(
      ({ timeline: next }) => {
        if (!current) return;
        setTimeline(next);
        setFailed(false);
      },
      () => {
        if (current) setFailed(true);
      },
    );
    return () => {
      current = false;
    };
  }, [taskId, version, retry]);

  const entries = useMemo(() => (timeline ? entriesOf(timeline) : []), [timeline]);
  const { placed, outer } = useMemo(() => place(entries), [entries]);

  if (failed && !timeline) {
    return (
      <p className="text-[14px] text-ink-2">
        {r.unreadable}{" "}
        <button
          type="button"
          onClick={() => setRetry((n) => n + 1)}
          className="focus-ring rounded px-1 underline underline-offset-4"
        >
          {r.retry}
        </button>
      </p>
    );
  }

  if (!timeline) return <p className="typewriter text-[13px] text-ink-3">{r.reading}</p>;

  if (!entries.length) return <p className="text-[14px] text-ink-2">{r.none}</p>;

  const { totals } = timeline;
  const marks = totals.attempts - totals.savedWork;
  const models = [...new Set(entries.map((e) => e.model).filter(Boolean))];

  return (
    <div className="grid gap-6 sm:grid-cols-[200px_1fr] sm:items-start">
      <figure className="space-y-3 sm:sticky sm:top-6">
        <Cross placed={placed} outer={outer} active={active} />
        <figcaption className="typewriter text-[11.5px] leading-relaxed text-ink-3">
          {r.caption(totals.savedWork, marks)}
        </figcaption>
      </figure>

      <div className="min-w-0 space-y-4">
        <ol className="space-y-1" aria-label={r.list}>
          {entries.map((entry) => (
            <li
              key={entry.id}
              tabIndex={0}
              onMouseEnter={() => setActive(entry.id)}
              onMouseLeave={() => setActive(null)}
              onFocus={() => setActive(entry.id)}
              onBlur={() => setActive(null)}
              className={`focus-ring flex gap-2.5 rounded-md px-2 py-1.5 transition-colors ${
                active === entry.id ? "bg-paper-2" : ""
              }`}
            >
              <Glyph mark={entry.mark} className="mt-0.5" />
              <span className="sr-only">{r.marks[entry.mark]}.</span>
              <span className="min-w-0 text-[13.5px] leading-snug">
                <span className="text-ink">
                  {entry.ring !== null ? `${entry.ring}. ` : ""}
                  {stepLabel(i18n.t, entry.kind, entry.subject)}
                  {entry.number > 1 ? `, ${r.attempt(entry.number)}` : ""}
                </span>
                {outcomeText(i18n, entry) && <span className="text-ink-2"> — {outcomeText(i18n, entry)}</span>}
                <span className="block font-mono text-[11.5px] text-ink-3">{details(i18n, entry)}</span>
              </span>
            </li>
          ))}
        </ol>

        <dl className="typewriter grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-[11.5px] text-ink-3">
          <dt>{r.working}</dt>
          <dd>{formatDuration(i18n, totals.workingMs)}</dd>
          <dt>{r.waiting}</dt>
          <dd>{formatDuration(i18n, totals.waitingMs)}</dd>
          <dt>{r.tokensLabel}</dt>
          <dd>{r.tokenTotals(formatCount(i18n, totals.promptTokens), formatCount(i18n, totals.completionTokens))}</dd>
          {models.length > 0 && (
            <>
              <dt>{r.model}</dt>
              <dd className="truncate">{models.join(", ")}</dd>
            </>
          )}
        </dl>

        <ul className="flex flex-wrap gap-x-4 gap-y-1 text-[12px] text-ink-3" aria-label={r.legend}>
          {(["ring", "crack", "break", "knot", "bud"] as const).map((mark) => (
            <li key={mark} className="flex items-center gap-1.5">
              <Glyph mark={mark} />
              {r.key[mark]}
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}
