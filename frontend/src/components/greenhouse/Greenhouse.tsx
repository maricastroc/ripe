"use client";

import { LoaderCircle } from "lucide-react";
import { AnimatePresence, motion } from "motion/react";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { SettingsDialog } from "@/components/agent/SettingsDialog";
import { usePolling } from "@/hooks/usePolling";
import { useChannel } from "@/hooks/useRealtime";
import { useSky } from "@/hooks/useSky";
import { api } from "@/lib/api";
import type { RealtimeConfig } from "@/lib/realtime";
import type { AgentStatus, Garden as GardenData, TaskDetail, TaskSummary } from "@/lib/types";
import { Fungus } from "./Fungus";
import { Glasshouse } from "./Glasshouse";
import { layoutBed, naturalSize } from "./layout";
import { Pantry } from "./Pantry";
import { PlantPlot } from "./PlantPlot";
import { type PacketHandle, SeedPacket } from "./SeedPacket";
import { SoilBand, type Thread } from "./SoilBand";

function interval(data: GardenData) {
  if (data.agent.mode === "working" || data.tasks.some((t) => t.activity?.running)) return 2000;
  if (data.tasks.some((t) => t.status === "ripening")) return 3500;
  return 8000;
}

function threadState(task: TaskSummary): Thread["state"] {
  if (task.activity?.running) return "working";
  if (task.activity && task.status === "ripening") return "queued";
  if (task.status === "dormant" || task.status === "stalled") return "dormant";
  return "idle";
}

function agentLine(agent: AgentStatus) {
  switch (agent.mode) {
    case "paused":
      return { text: "paused in settings", tone: "text-ochre" };
    case "halted":
      return { text: "resting for now", tone: "text-ochre" };
    case "working":
      return { text: agent.current ? `feeding “${agent.current.taskTitle}”` : "working", tone: "text-[#d9f27a]" };
    case "offline":
      return {
        text: agent.driver === "ollama" ? "Ollama is off. Run ollama serve" : "the model can't be reached",
        tone: "text-[#ff9b7a]",
      };
    case "model-missing":
      return { text: `model not installed. Run ollama pull ${agent.model}`, tone: "text-[#ff9b7a]" };
    case "stopped":
      return { text: "not running", tone: "text-[#ff9b7a]" };
    default:
      if (agent.queued)
        return { text: `${agent.queued} step${agent.queued > 1 ? "s" : ""} queued`, tone: "text-[#efe3c7]" };
      if (agent.elsewhere) return { text: "feeding other greenhouses", tone: "text-[#d9f27a]" };
      return { text: "idle, nothing to feed", tone: "text-[#efe3c7]" };
  }
}

function myceliumOf(agent: AgentStatus) {
  if (agent.driver === "fake") return "a simulated model for development";
  if (agent.hosting === "local" && agent.driver === "ollama") return "an open model running on this machine";
  return `${agent.model}, a hosted model`;
}

function agentNote(agent: AgentStatus) {
  if (agent.driver === "fake") return "Written by a simulated model, for development.";
  if (agent.hosting === "local" && agent.driver === "ollama")
    return `Everything runs locally with ${agent.model} via Ollama. Nothing leaves this machine.`;
  return `Written by ${agent.model}${agent.driver === "openai" ? ", hosted by OpenAI" : ""}.`;
}

const SUGGESTIONS = [
  "Write a project proposal",
  "Prepare a presentation",
  "Plan a trip",
  "Reply to a difficult email",
  "Sort out ideas for an article",
];

export function Greenhouse({ initial, realtime }: { initial: GardenData; realtime: RealtimeConfig | null }) {
  const { data, setData, error, refresh, request, setRealtime } = usePolling<GardenData>(
    "/api/garden",
    initial,
    interval,
  );
  const live = useChannel(realtime, data.channel, { "task.changed": request }, request);
  const { sky, time } = useSky();
  const router = useRouter();
  const bed = useRef<HTMLDivElement>(null);
  const packet = useRef<PacketHandle>(null);
  const [size, setSize] = useState({ w: 1200, h: 860 });
  const [freshId, setFreshId] = useState<string | null>(null);
  const [settings, setSettings] = useState(false);
  const [openingExample, setOpeningExample] = useState(false);

  useEffect(() => {
    const el = bed.current;
    if (!el) return;
    const measure = () => setSize({ w: el.clientWidth, h: window.innerHeight });
    measure();
    const observer = new ResizeObserver(measure);
    observer.observe(el);
    window.addEventListener("resize", measure);
    return () => {
      observer.disconnect();
      window.removeEventListener("resize", measure);
    };
  }, []);

  useEffect(() => setRealtime(live), [live, setRealtime]);

  const onAgentChange = useCallback((agent: AgentStatus) => setData({ ...data, agent }), [data, setData]);

  const plants = useMemo(() => data.tasks.filter((t) => t.status !== "harvested").reverse(), [data.tasks]);
  const harvested = data.tasks.filter((t) => t.status === "harvested");
  const numbers = useMemo(() => {
    const ordered = [...data.tasks].sort((a, b) => (a.createdAt ?? "").localeCompare(b.createdAt ?? ""));
    return new Map(ordered.map((t, i) => [t.id, i + 1]));
  }, [data.tasks]);
  const layout = layoutBed(
    size.w,
    size.h,
    plants.map((p) => naturalSize(p.growth.stage, p.growth.stepsTotal)),
  );
  const agent = data.agent;
  const line = agentLine(agent);
  const configurable = agent.hosting === "local";
  const counts = {
    growing: plants.filter((t) => t.activity?.running).length,
    waiting: plants.filter((t) => t.status === "waiting" || t.status === "stalled").length,
    ripe: plants.filter((t) => t.status === "ripe").length,
  };

  async function planted(id: string) {
    setFreshId(id);
    await refresh();
  }

  async function example() {
    setOpeningExample(true);
    try {
      const { task } = await api<{ task: TaskDetail }>("/api/examples", { method: "POST" });
      router.push(`/t/${task.id}`);
    } catch {
      setOpeningExample(false);
    }
  }

  const packetEl = <SeedPacket ref={packet} onPlanted={planted} nextNumber={data.tasks.length + 1} />;

  return (
    <div
      data-sky={sky}
      data-realtime={live ? "live" : "polling"}
      className="relative flex flex-1 flex-col overflow-x-clip"
      style={{ background: "var(--soil-3)" }}
    >
      <section className="relative overflow-hidden" aria-label="The greenhouse">
        <Glasshouse sky={sky} />

        <header className="relative z-30 flex items-start justify-between gap-4 px-4 pt-5 sm:px-10 sm:pt-7">
          <div>
            <p className="font-display font-display-wonk text-[2rem] italic leading-none text-scene-ink">ripe</p>
            <p className="typewriter mt-1 text-[11px] uppercase tracking-[0.16em] text-scene-ink-2">
              greenhouse for put-off things
            </p>
          </div>
          <div className="flex flex-col items-end gap-1.5 text-right">
            <button
              type="button"
              onClick={() => setSettings(configurable)}
              className="focus-ring typewriter rounded-full border border-current/20 px-3 py-1 text-[11.5px] text-scene-ink-2 transition-colors hover:text-scene-ink"
              aria-label={configurable ? "Open agent settings" : `The agent: ${line.text}`}
            >
              mycelium<span className="hidden sm:inline"> · {agent.model}</span>
            </button>
            <p className="typewriter whitespace-nowrap text-[11px] text-scene-ink-2" suppressHydrationWarning>
              {sky}
              {time ? ` · ${time}` : ""}
            </p>
          </div>
        </header>

        <div className="relative z-20 px-4 pt-4 sm:px-10 sm:pt-2">
          <div className="relative isolate max-w-[46rem]">
            <div
              aria-hidden
              className="condensation pointer-events-none absolute -inset-x-16 -bottom-10 -top-28 -z-10 sm:-inset-x-24 sm:-inset-y-12"
            />
            <motion.h1
              initial={{ opacity: 0, y: 8 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.9, ease: [0.22, 1, 0.36, 1] }}
              className="font-display text-[2.3rem] leading-[1] tracking-[-0.02em] text-scene-ink sm:text-[3.4rem]"
            >
              Leave it for later. <span className="font-display-wonk italic">It ripens here.</span>
            </motion.h1>
            <p className="mt-3 max-w-xl text-[14.5px] leading-relaxed text-scene-ink sm:text-[15.5px]">
              Sow what you keep putting off. The mycelium under the soil is {myceliumOf(agent)}. It works on each task
              in small steps and saves as it goes, so a plant only grows when real work gets done.
            </p>
            {plants.length > 0 && (
              <p className="typewriter mt-3 text-[12px] text-scene-ink-2">
                {plants.length} {plants.length === 1 ? "plant" : "plants"}
                {counts.growing ? ` · ${counts.growing} growing now` : ""}
                {counts.waiting ? ` · ${counts.waiting} ${counts.waiting === 1 ? "needs" : "need"} you` : ""}
                {counts.ripe ? ` · ${counts.ripe} ripe` : ""}
              </p>
            )}
          </div>
        </div>

        <AnimatePresence>
          {error && (
            <motion.p
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              exit={{ opacity: 0 }}
              role="status"
              className="paper relative z-30 mx-4 mt-3 rounded-sm px-3 py-1.5 text-[13px] text-vermilion-ink sm:mx-10"
            >
              {error} Trying again…
            </motion.p>
          )}
        </AnimatePresence>

        {layout.packetAbove && <div className="relative z-20 mx-4 mt-6 max-w-sm">{packetEl}</div>}

        <div ref={bed} className="relative z-10 mx-2 mt-6 sm:mx-6 sm:mt-4">
          {layout.rows.map((row, r) => {
            const fungus = row.plots[0];
            const threads: Thread[] = row.plots
              .filter((p) => p.kind === "plant")
              .map((p) => ({ id: plants[p.index].id, x: p.x + p.w / 2, state: threadState(plants[p.index]) }));
            return (
              <div key={r} className="relative" style={{ height: row.plantH + row.soilH }}>
                <div className="absolute inset-x-0" style={{ top: row.plantH, height: row.soilH }}>
                  <SoilBand
                    width={size.w}
                    height={row.soilH}
                    sourceX={fungus.w / 2}
                    fromAbove={r > 0}
                    continues={r < layout.rows.length - 1}
                    threads={threads}
                    agentMode={agent.mode}
                    paused={agent.mode === "paused" || agent.mode === "halted"}
                    salt={r * 97 + 11}
                  />
                </div>

                {row.plots.map((plot) => {
                  if (plot.kind === "fungus") {
                    const h = (plot.w * 158) / 112;
                    return (
                      <div key="fungus">
                        <button
                          type="button"
                          onClick={() => setSettings(configurable)}
                          className="focus-ring absolute z-10 block"
                          style={{ left: plot.x, width: plot.w, top: row.plantH - (h * 150) / 158, height: h }}
                          aria-label={
                            configurable
                              ? `The mycelium, the local agent: ${line.text}. Open settings`
                              : `The mycelium, the agent: ${line.text}`
                          }
                        >
                          <Fungus mode={agent.mode} paused={agent.mode === "paused" || agent.mode === "halted"} />
                        </button>
                        {!layout.mobile && (
                          <button
                            type="button"
                            onClick={() => setSettings(configurable)}
                            className="focus-ring absolute z-20 rounded-[2px] border border-[#6f5a3c] bg-[#1d140e]/85 px-2 py-1.5 text-left"
                            style={{ left: plot.x + 8, width: plot.w - 4, top: row.plantH + 30 }}
                          >
                            <span className="typewriter block text-[9.5px] uppercase tracking-[0.14em] text-[#c9b48f]">
                              mycelium
                            </span>
                            <span className="typewriter block truncate text-[10.5px] text-[#efe3c7]">
                              {agent.model}
                            </span>
                            <span className={`mt-0.5 block text-[11px] leading-tight ${line.tone}`}>{line.text}</span>
                          </button>
                        )}
                      </div>
                    );
                  }
                  if (plot.kind === "plant") {
                    const task = plants[plot.index];
                    return (
                      <PlantPlot
                        key={task.id}
                        task={task}
                        agent={agent}
                        x={plot.x}
                        w={plot.w}
                        plantH={row.plantH}
                        scaleH={row.scaleH}
                        number={numbers.get(task.id) ?? 0}
                        fresh={task.id === freshId}
                        compact={layout.mobile}
                      />
                    );
                  }
                  if (plot.kind === "packet") {
                    return (
                      <div
                        key="packet"
                        className="absolute z-20"
                        style={{ left: plot.x + 10, width: plot.w - 26, bottom: row.soilH - 16 }}
                      >
                        {packetEl}
                      </div>
                    );
                  }
                  if (plot.kind === "empty") {
                    return (
                      <div
                        key="empty"
                        className="absolute z-10 flex flex-col justify-end px-4 pb-10 sm:px-10"
                        style={{ left: plot.x, width: plot.w, top: 0, height: row.plantH }}
                      >
                        <p className="font-display text-[1.55rem] leading-tight text-scene-ink">
                          Nothing is growing yet. <span className="italic">The soil is ready.</span>
                        </p>
                        <p className="mt-2 max-w-md text-[14px] leading-relaxed text-scene-ink-2">
                          Write something on the seed packet and leave it for later. The mycelium starts feeding it
                          right away, and the plant sprouts, branches and fruits as each piece of work gets done.
                        </p>
                        <div className="mt-4 flex flex-wrap gap-2">
                          {SUGGESTIONS.map((s) => (
                            <button
                              key={s}
                              type="button"
                              onClick={() => packet.current?.fill(`${s} `)}
                              className="focus-ring paper rounded-full px-3 py-1 text-[13px] text-ink-2 shadow-sm hover:text-ink"
                            >
                              {s}
                            </button>
                          ))}
                        </div>
                        <button
                          type="button"
                          onClick={example}
                          disabled={openingExample}
                          aria-busy={openingExample}
                          className="focus-ring mt-4 inline-flex items-center gap-2 self-start text-[13px] text-scene-ink-2 underline decoration-dotted underline-offset-4 hover:text-scene-ink disabled:no-underline"
                        >
                          {openingExample && (
                            <LoaderCircle
                              className="h-3.5 w-3.5 animate-spin motion-reduce:animate-none"
                              strokeWidth={2}
                              aria-hidden
                            />
                          )}
                          {openingExample
                            ? "opening the example…"
                            : "or open a ripe example, grown once by a scripted model"}
                        </button>
                      </div>
                    );
                  }
                  return null;
                })}
              </div>
            );
          })}
        </div>
      </section>

      <Pantry tasks={harvested} numbers={numbers} />

      <footer className="mt-auto flex flex-wrap items-center justify-between gap-3 px-4 pb-6 pt-10 text-[12px] text-[#b9a888] sm:px-10">
        <p className="typewriter">{agentNote(agent)}</p>
        <p className="font-display italic">slow and steady.</p>
      </footer>

      {configurable && (
        <SettingsDialog open={settings} onOpenChange={setSettings} agent={agent} onChange={onAgentChange} />
      )}
    </div>
  );
}
