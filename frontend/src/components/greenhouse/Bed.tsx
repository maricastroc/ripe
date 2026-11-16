"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { type Pt, r1, rand } from "@/components/flora/geometry";
import type { Sky } from "@/hooks/useSky";
import type { AgentStatus, TaskSummary } from "@/lib/types";
import { Astro, Conservatory, type GlassSpec, bedGlass } from "./Conservatory";
import { Fungus } from "./Fungus";
import type { Strip } from "./layout";
import { PlantPlot } from "./PlantPlot";
import { PlateSoil, Tuft } from "./PlateSoil";
import { SoilBand, type Thread, hyphaTone } from "./SoilBand";

const STRATA: [number, number] = [0.58, 0.88];
const FADE = 32;

function threadState(task: TaskSummary): Thread["state"] {
  if (task.activity?.running) return "working";
  if (task.activity && task.status === "ripening") return "queued";
  if (task.status === "dormant" || task.status === "stalled") return "dormant";
  return "idle";
}

function skyPoint(sky: Sky, glass: GlassSpec, width: number) {
  const top = glass.cornice - glass.dome;
  const half = glass.pavilion / 2;
  const low = Math.min(top + 64, glass.join - 72);
  const at =
    sky === "dawn"
      ? { x: glass.axis - half - 100, y: low }
      : sky === "dusk"
        ? { x: glass.axis + half + 112, y: low }
        : { x: glass.axis + half + 92, y: top + 2 };
  return { x: Math.max(48, Math.min(width - 56, at.x)), y: at.y };
}

function fade(start: boolean, end: boolean) {
  if (!start && !end) return undefined;
  const a = start ? `transparent, #000 ${FADE}px` : "#000";
  const b = end ? `#000 calc(100% - ${FADE}px), transparent` : "#000";
  return `linear-gradient(to right, ${a}, ${b})`;
}

type Props = {
  strip: Strip;
  bedX: number;
  visible: number;
  width: number;
  plants: TaskSummary[];
  agent: AgentStatus;
  numbers: Map<string, number>;
  freshId: string | null;
  sky: Sky;
  skyLabel: string;
  toward?: Pt;
  soilMin?: number;
  fungusLabel: string;
  onFungus: () => void;
};

export function Bed({
  strip,
  bedX,
  visible,
  width,
  plants,
  agent,
  numbers,
  freshId,
  sky,
  skyLabel,
  toward,
  soilMin = 96,
  fungusLabel,
  onFungus,
}: Props) {
  const el = useRef<HTMLDivElement>(null);
  const scroller = useRef<HTMLDivElement>(null);
  const [height, setHeight] = useState(strip.plantH + 220);
  const [edges, setEdges] = useState({ start: false, end: false });

  useEffect(() => {
    const node = el.current;
    if (!node) return;
    const read = () => setHeight(node.clientHeight);
    read();
    const observer = new ResizeObserver(read);
    observer.observe(node);
    return () => observer.disconnect();
  }, []);

  useEffect(() => {
    const node = scroller.current;
    if (!node) return;
    const read = () => {
      const next = {
        start: node.scrollLeft > 4,
        end: node.scrollLeft + node.clientWidth < node.scrollWidth - 4,
      };
      setEdges((e) => (e.start === next.start && e.end === next.end ? e : next));
    };
    read();
    node.addEventListener("scroll", read, { passive: true });
    const observer = new ResizeObserver(read);
    observer.observe(node);
    return () => {
      node.removeEventListener("scroll", read);
      observer.disconnect();
    };
  }, [strip.width]);

  const tufts = useMemo(
    () => Array.from({ length: Math.max(3, Math.round(width / 260)) }, (_, i) => r1(rand(23, i + 10000) * width)),
    [width],
  );

  const { plantH } = strip;
  const soilH = Math.max(soilMin, Math.round(height - plantH));
  const lead = strip.plots[0];
  const growing = strip.plots.filter((p) => p.kind === "plant");
  const threads: Thread[] = growing.map((p) => ({
    id: plants[p.index].id,
    x: p.x + p.w / 2,
    state: threadState(plants[p.index]),
  }));
  const first = growing[0];
  const end = growing[growing.length - 1];
  const overflows = strip.width > visible;
  const glass = first
    ? bedGlass(first.x, end.x + end.w, plantH, plantH - 6, overflows ? (first.x + visible) / 2 : undefined)
    : null;
  const astro = glass ? skyPoint(sky, glass, Math.min(strip.width, visible)) : null;
  const tone = hyphaTone(agent.mode, agent.mode === "paused");
  const reach =
    toward && first
      ? `M ${r1(bedX + visible - 30)} ${r1(soilH * 0.33)} C ${r1(bedX + visible + 14)} ${r1(soilH * 0.33 + 44)} ${r1(toward.x - 64)} ${r1(toward.y + 72)} ${r1(toward.x - 12)} ${r1(toward.y + 32)}`
      : null;

  return (
    <div ref={el} className="plate-bed0 relative" style={{ marginTop: -plantH, minHeight: plantH + soilMin }}>
      <div className="absolute inset-x-0" style={{ top: plantH, height: soilH }}>
        <PlateSoil
          width={width}
          height={soilH}
          salt={11}
          strata={STRATA}
          edgeAbove={tufts.map((x, i) => (
            <Tuft key={i} x={x} />
          ))}
        >
          {reach && (
            <path
              d={reach}
              fill="none"
              stroke={tone.color}
              strokeWidth={0.9}
              opacity={0.35}
              strokeDasharray="3 5"
              strokeLinecap="round"
            />
          )}
        </PlateSoil>
      </div>
      <div
        ref={scroller}
        className="plate-strip absolute inset-y-0 overflow-y-hidden overflow-x-auto"
        style={{ left: bedX, width: visible, maskImage: fade(edges.start, edges.end) }}
      >
        <div className="relative h-full overflow-x-clip" style={{ width: strip.width }}>
          {glass && (
            <svg
              className="pointer-events-none absolute left-0 top-0 overflow-visible"
              width={strip.width}
              height={plantH}
              aria-hidden
            >
              <Conservatory spec={glass} lit={sky === "night"} />
              {astro && <Astro sky={sky} x={astro.x} y={astro.y} label={skyLabel} />}
            </svg>
          )}
          <div className="absolute inset-x-0" style={{ top: plantH, height: soilH }}>
            <SoilBand
              bare
              width={strip.width}
              height={soilH}
              sourceX={lead.w / 2}
              fromAbove={false}
              continues={false}
              threads={threads}
              agentMode={agent.mode}
              paused={agent.mode === "paused"}
              salt={11}
            />
          </div>
          {strip.plots.map((plot) => {
            if (plot.kind === "fungus") {
              const h = (plot.w * 158) / 112;
              return (
                <button
                  key="fungus"
                  type="button"
                  onClick={onFungus}
                  className="focus-ring absolute z-10 block"
                  style={{ left: plot.x, width: plot.w, top: plantH - (h * 150) / 158, height: h }}
                  aria-label={fungusLabel}
                >
                  <Fungus mode={agent.mode} paused={agent.mode === "paused"} />
                </button>
              );
            }
            const task = plants[plot.index];
            return (
              <PlantPlot
                key={task.id}
                task={task}
                agent={agent}
                x={plot.x}
                w={plot.w}
                plantH={plantH}
                scaleH={strip.scaleH}
                number={numbers.get(task.id) ?? 0}
                fresh={task.id === freshId}
                compact={strip.compact}
              />
            );
          })}
        </div>
      </div>
    </div>
  );
}
