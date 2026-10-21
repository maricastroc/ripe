"use client";

import { ArrowRight } from "lucide-react";
import { AnimatePresence, motion } from "motion/react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { ViewTransition, useMemo, useState } from "react";
import { computeAnatomy } from "@/components/flora/anatomy";
import { Specimen } from "@/components/flora/Specimen";
import { formatReturn } from "@/lib/format";
import { stageName } from "@/lib/growth";
import { TONE_CLASS, TONE_DOT, focusOf, moodOf, statusLine } from "@/lib/presence";
import type { AgentStatus, TaskSummary } from "@/lib/types";
import { VIEW, specimenBox } from "./layout";
import { StepTicks } from "./StepTicks";

type Props = {
  task: TaskSummary;
  agent: AgentStatus;
  x: number;
  w: number;
  plantH: number;
  scaleH: number;
  number: number;
  fresh: boolean;
  compact: boolean;
};

export function catalog(n: number) {
  return `Nº ${String(n).padStart(3, "0")}`;
}

export function PlantPlot({ task, agent, x, w, plantH, scaleH, number, fresh, compact }: Props) {
  const router = useRouter();
  const [hover, setHover] = useState<string | null>(null);
  const box = specimenBox(scaleH, w);
  const left = x + w / 2 - box.width / 2;
  const top = plantH - box.ground;
  const state = useMemo(
    () => ({
      seed: task.id,
      fruit: task.fruit,
      growth: task.growth,
      steps: task.leaves.map((l) => ({ id: l.id, status: l.status, artifactId: l.artifactId, title: l.title })),
    }),
    [task.id, task.fruit, task.growth, task.leaves],
  );
  const anatomy = useMemo(() => computeAnatomy(state), [state]);
  const line = statusLine(task, agent);
  const mood = moodOf(task);
  const back = task.status !== "ripe" ? formatReturn(task.returnAt) : null;

  const open = (key: string) => {
    if (key === "apex") return router.push(`/t/${task.id}?piece=final`);
    if (key === "tag") return router.push(`/t/${task.id}?piece=review`);
    const leaf = task.leaves.find((l) => l.id === key);
    router.push(leaf?.artifactId ? `/t/${task.id}?piece=${leaf.artifactId}` : `/t/${task.id}`);
  };

  let note: { at: { x: number; y: number }; title: string; detail: string } | null = null;
  if (hover) {
    const branch = anatomy.branches.find((b) => b.stepId === hover);
    if (branch) {
      note = {
        at: branch.anchor,
        title: `${branch.index} · ${branch.title ?? "Step"}`,
        detail:
          branch.status === "done"
            ? "ready · click to read"
            : branch.status === "working"
              ? "being written right now"
              : branch.status === "blocked"
                ? "waiting on your answer"
                : "not started yet",
      };
    } else if (hover === "apex" && anatomy.apex) {
      note = {
        at: anatomy.apex.pos,
        title:
          anatomy.apex.kind === "fruit"
            ? "The fruit"
            : anatomy.apex.kind === "fruitlet"
              ? "Fruit setting"
              : anatomy.apex.kind === "flower"
                ? "In bloom"
                : "Growing tip",
        detail:
          anatomy.apex.kind === "fruit" ? "the finished work · click to read" : "the main result is still forming",
      };
    } else if (hover === "tag" && anatomy.tag) {
      note = { at: anatomy.tag.card, title: "Review tag", detail: "the agent's review · click to read" };
    }
  }
  const notePos = note
    ? { x: left + (note.at.x - VIEW.left) * box.scale, y: top + (note.at.y - VIEW.top) * box.scale }
    : null;
  const labelW = Math.min(w - (compact ? 8 : 20), compact ? 176 : 244);

  return (
    <>
      <div
        className="specimen-bed pointer-events-none absolute z-10 cursor-pointer [&_svg_*]:pointer-events-auto"
        style={{ left, top, width: box.width, height: box.height }}
        onClick={() => router.push(`/t/${task.id}`)}
      >
        <ViewTransition name={`specimen-${task.id}`} share="specimen" default="none">
          <div className="h-full w-full">
            <Specimen
              state={state}
              mood={mood}
              focus={focusOf(task)}
              variant="bed"
              highlight={hover}
              onHover={setHover}
              onSelect={open}
              entrance={fresh}
              className="block h-full w-full"
              title={`${task.title}: ${stageName(task.growth.stage)}`}
            />
          </div>
        </ViewTransition>
      </div>

      <AnimatePresence>
        {note && notePos && (
          <motion.div
            key={hover}
            initial={{ opacity: 0, y: 4 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.18 }}
            className="paper paper-shadow pointer-events-none absolute z-30 max-w-[220px] rounded-sm px-3 py-2"
            style={{ left: notePos.x + 14, top: notePos.y - 44 }}
          >
            <p className="font-display text-[14px] leading-tight text-ink">{note.title}</p>
            <p className="ink mt-0.5 text-[12.5px] leading-tight">{note.detail}</p>
          </motion.div>
        )}
      </AnimatePresence>

      <Link
        href={`/t/${task.id}`}
        className={`plot-label paper focus-ring group absolute z-20 block rounded-[2px] pb-2.5 pt-3 ${compact ? "px-2.5" : "px-3.5"} transition-[transform,box-shadow] duration-300 hover:z-30 hover:-translate-y-0.5 focus-visible:z-30`}
        style={{ left: x + w / 2 - labelW / 2, bottom: compact ? 10 : 14, width: labelW }}
        aria-label={`${catalog(number)}: ${task.title}. ${stageName(task.growth.stage)} stage, ${line.text}`}
      >
        <span aria-hidden className="pointer-events-none absolute inset-[3px] rounded-[1px] border border-line" />
        <span
          aria-hidden
          className="absolute -top-[5px] left-1/2 h-2.5 w-2.5 -translate-x-1/2 rounded-full bg-[#b3925a] shadow-[0_1px_1px_rgba(0,0,0,0.45),inset_0_1px_0_rgba(255,255,255,0.45)]"
        />
        <span className="relative flex items-baseline justify-between gap-2">
          <span className="typewriter text-[10px] tracking-[0.06em] text-ink-3">{catalog(number)}</span>
          <span className="typewriter text-[10px] uppercase tracking-[0.08em] text-ink-3">
            {stageName(task.growth.stage)}
          </span>
        </span>
        <span
          className={`relative mt-1 font-display leading-[1.12] tracking-[-0.005em] text-ink ${compact ? "line-clamp-3 text-[15px]" : "line-clamp-2 text-[17.5px]"}`}
          style={{ fontWeight: 500 }}
        >
          {task.title}
        </span>
        <span className={`relative mt-1.5 flex items-start gap-1.5 text-[12px] leading-snug ${TONE_CLASS[line.tone]}`}>
          <span
            className={`mt-[0.42em] h-1.5 w-1.5 shrink-0 rounded-full ${TONE_DOT[line.tone]} ${mood === "working" ? "live-dot" : ""}`}
          />
          <span className={compact ? "line-clamp-2" : "line-clamp-1"}>{line.text}</span>
        </span>
        {!compact && (
          <span className="relative grid grid-rows-[0fr] transition-[grid-template-rows] duration-300 ease-out group-hover:grid-rows-[1fr] group-focus-visible:grid-rows-[1fr]">
            <span className="overflow-hidden">
              <span className="block pt-2">
                <span className="ink block truncate text-[12.5px] leading-tight">{anatomy.species.latin}</span>
                {back && (
                  <span className="typewriter mt-0.5 block truncate text-[10.5px] text-ink-3" suppressHydrationWarning>
                    {back}
                  </span>
                )}
                <span className="mt-1 flex items-center gap-1 text-[12px] text-cobalt">
                  {task.growth.stepsDone
                    ? `${task.growth.stepsDone} ${task.growth.stepsDone === 1 ? "piece" : "pieces"} to read`
                    : "open the specimen sheet"}
                  <ArrowRight className="h-3.5 w-3.5" strokeWidth={1.75} aria-hidden />
                </span>
              </span>
            </span>
          </span>
        )}
        <span className="relative mt-2 flex items-center justify-between gap-2 border-t border-dashed border-line-2 pt-1.5">
          <StepTicks leaves={task.leaves} />
          <span className="typewriter text-[10px] text-ink-3">
            {task.growth.stepsTotal ? `${task.growth.stepsDone}/${task.growth.stepsTotal}` : "no plan yet"}
          </span>
        </span>
      </Link>
    </>
  );
}
