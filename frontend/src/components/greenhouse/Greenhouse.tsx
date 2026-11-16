"use client";

import { ChevronDown, LoaderCircle } from "lucide-react";
import { AnimatePresence, motion } from "motion/react";
import { useRouter } from "next/navigation";
import { Fragment, useCallback, useEffect, useMemo, useRef, useState } from "react";
import { SettingsDialog } from "@/components/agent/SettingsDialog";
import { LanguageSwitch } from "@/components/LanguageSwitch";
import { usePolling } from "@/hooks/usePolling";
import { useChannel } from "@/hooks/useRealtime";
import { useSky } from "@/hooks/useSky";
import { api } from "@/lib/api";
import type { I18n } from "@/lib/i18n";
import { useI18n } from "@/lib/i18n/context";
import type { Messages } from "@/lib/i18n/messages/en";
import { restText } from "@/lib/presence";
import type { RealtimeConfig } from "@/lib/realtime";
import type { AgentStatus, Garden as GardenData, TaskSummary } from "@/lib/types";
import { ForgetGreenhouse } from "./ForgetGreenhouse";
import { Fungus } from "./Fungus";
import { type GlassFrame, Glasshouse } from "./Glasshouse";
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

function agentLine(i18n: I18n, agent: AgentStatus) {
  const a = i18n.t.greenhouse.agent;
  switch (agent.mode) {
    case "paused":
      return { text: a.paused, tone: "text-ochre" };
    case "resting":
      return { text: restText(i18n, agent).short, tone: "text-cobalt" };
    case "halted":
      return { text: a.halted, tone: "text-vermilion-ink" };
    case "working":
      return { text: agent.current ? a.feeding(agent.current.taskTitle) : a.working, tone: "text-leaf-dark" };
    case "offline":
      return { text: agent.driver === "ollama" ? a.ollamaOff : a.unreachable, tone: "text-vermilion-ink" };
    case "model-missing":
      return { text: a.modelMissing(agent.model), tone: "text-vermilion-ink" };
    case "stopped":
      return { text: a.stopped, tone: "text-vermilion-ink" };
    default:
      if (agent.queued) return { text: a.queued(agent.queued), tone: "text-ink-2" };
      if (agent.elsewhere) return { text: a.elsewhere, tone: "text-leaf-dark" };
      return { text: a.idle, tone: "text-ink-2" };
  }
}

function myceliumOf(t: Messages, agent: AgentStatus) {
  if (agent.driver === "fake") return t.greenhouse.mycelium.fake;
  if (agent.hosting === "local" && agent.driver === "ollama") return t.greenhouse.mycelium.local;
  return t.greenhouse.mycelium.hosted(agent.model);
}

function unbroken(text: string, name: string) {
  const at = name ? text.indexOf(name) : -1;
  if (at < 0) return text;
  return (
    <>
      {text.slice(0, at)}
      <span className="whitespace-nowrap">{name}</span>
      {text.slice(at + name.length)}
    </>
  );
}

function agentNote(t: Messages, agent: AgentStatus) {
  if (agent.driver === "fake") return t.greenhouse.note.fake;
  if (agent.hosting === "local" && agent.driver === "ollama") return t.greenhouse.note.local(agent.model);
  return t.greenhouse.note.hosted(agent.model, agent.driver === "openai");
}

export function Greenhouse({
  initial,
  realtime,
  turnstile = null,
}: {
  initial: GardenData;
  realtime: RealtimeConfig | null;
  turnstile?: string | null;
}) {
  const { data, setData, error, refresh, request, setRealtime } = usePolling<GardenData>(
    "/api/garden",
    initial,
    interval,
  );
  const live = useChannel(realtime, data.channel, { "task.changed": request }, request);
  const { sky, time } = useSky();
  const router = useRouter();
  const i18n = useI18n();
  const { t } = i18n;
  const g = t.greenhouse;
  const bed = useRef<HTMLDivElement>(null);
  const scene = useRef<HTMLElement>(null);
  const intro = useRef<HTMLDivElement>(null);
  const packet = useRef<PacketHandle>(null);
  const [size, setSize] = useState({ w: 1200, h: 860, top: 360 });
  const [frame, setFrame] = useState<GlassFrame | null>(null);
  const [freshId, setFreshId] = useState<string | null>(null);
  const [settings, setSettings] = useState(false);
  const [sowing, setSowing] = useState(false);
  const [openingExample, setOpeningExample] = useState(false);

  useEffect(() => {
    const el = bed.current;
    const section = scene.current;
    if (!el || !section) return;
    const measure = () => {
      const next = {
        w: el.clientWidth,
        h: window.innerHeight,
        top: Math.round(el.getBoundingClientRect().top + window.scrollY),
      };
      setSize((s) => (s.w === next.w && s.h === next.h && s.top === next.top ? s : next));
      const glass = { width: section.clientWidth, height: section.clientHeight, viewport: window.innerHeight };
      setFrame((f) =>
        f && f.width === glass.width && f.height === glass.height && f.viewport === glass.viewport ? f : glass,
      );
    };
    measure();
    const observer = new ResizeObserver(measure);
    observer.observe(el);
    observer.observe(section);
    if (intro.current) observer.observe(intro.current);
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
    plants.length ? size.h - size.top - 215 : undefined,
  );
  const agent = data.agent;
  const line = agentLine(i18n, agent);
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
      const { examples } = await api<{ examples: TaskSummary[] }>("/api/examples");
      const first = examples[0];
      if (!first) throw new Error("No example is installed.");
      router.push(`/t/${first.id}`);
    } catch {
      setOpeningExample(false);
    }
  }

  const growing = plants.length > 0;
  const packetEl = (
    <SeedPacket
      ref={packet}
      onPlanted={planted}
      nextNumber={data.tasks.length + 1}
      turnstile={turnstile}
      stake={!layout.mobile}
    />
  );
  const p = t.packet;

  return (
    <div
      data-sky={sky}
      data-realtime={live ? "live" : "polling"}
      className="relative flex flex-1 flex-col overflow-x-clip"
      style={{ background: "var(--soil-3)" }}
    >
      <section ref={scene} className="relative overflow-hidden" aria-label={g.label}>
        <Glasshouse sky={sky} frame={frame} />

        <header className="relative z-30 flex items-start justify-between gap-4 px-4 pt-5 sm:px-10 sm:pt-7">
          <div>
            <p className="font-display font-display-wonk text-[2rem] italic leading-none text-scene-ink">ripe</p>
            <p className="typewriter mt-1 text-[10px] uppercase tracking-[0.12em] text-scene-ink-2 sm:text-[11px] sm:tracking-[0.16em]">
              {g.tagline}
            </p>
          </div>
          <div className="flex flex-col items-end gap-1.5 text-right">
            <button
              type="button"
              onClick={() => setSettings(configurable)}
              className="focus-ring typewriter rounded-full border border-current/20 px-3 py-1 text-[11.5px] text-scene-ink-2 transition-colors hover:text-scene-ink"
              aria-label={configurable ? g.openSettings : g.agentIs(line.text)}
            >
              {g.myceliumName}
              <span className="hidden sm:inline"> · {agent.model}</span>
            </button>
            <p className="typewriter whitespace-nowrap text-[11px] text-scene-ink-2" suppressHydrationWarning>
              {t.sky[sky]}
              {time ? ` · ${time}` : ""}
            </p>
            <LanguageSwitch className="-mr-1 text-scene-ink-2 hover:text-scene-ink" />
          </div>
        </header>

        <div ref={intro} className="relative z-20 px-4 pt-4 sm:px-10 sm:pt-2">
          <div className="relative isolate max-w-[46rem]">
            <div
              aria-hidden
              className="condensation pointer-events-none absolute -inset-x-16 -bottom-10 -top-28 -z-10 sm:-inset-x-24 sm:-inset-y-12"
            />
            <motion.h1
              initial={{ opacity: 0, y: 8 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.9, ease: [0.22, 1, 0.36, 1] }}
              className={`font-display leading-[1] tracking-[-0.02em] text-scene-ink transition-[font-size] duration-500 motion-reduce:transition-none ${growing ? "text-[1.85rem] sm:text-[2.5rem]" : "text-[2.3rem] sm:text-[3.4rem]"}`}
            >
              {g.headline} <span className="font-display-wonk italic">{g.headlineTail}</span>
            </motion.h1>
            <AnimatePresence initial={false}>
              {!growing && (
                <motion.p
                  key="intro"
                  initial={{ opacity: 0, height: 0 }}
                  animate={{ opacity: 1, height: "auto" }}
                  exit={{ opacity: 0, height: 0 }}
                  transition={{ duration: 0.5, ease: [0.22, 1, 0.36, 1] }}
                  className="max-w-xl overflow-hidden text-[14.5px] leading-relaxed text-scene-ink sm:text-[15.5px]"
                >
                  <span className="block pt-3">{unbroken(g.intro(myceliumOf(t, agent)), agent.model)}</span>
                </motion.p>
              )}
            </AnimatePresence>
            {growing && (
              <p className="typewriter mt-3 text-[12px] text-scene-ink-2">
                {g.counts.plants(plants.length)}
                {counts.growing ? ` · ${g.counts.growing(counts.growing)}` : ""}
                {counts.waiting ? ` · ${g.counts.waiting(counts.waiting)}` : ""}
                {counts.ripe ? ` · ${g.counts.ripe(counts.ripe)}` : ""}
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
              {error} {g.retrying}
            </motion.p>
          )}
        </AnimatePresence>

        {layout.packetAbove &&
          (growing && !sowing ? (
            <button
              type="button"
              onClick={() => {
                setSowing(true);
                requestAnimationFrame(() => packet.current?.fill(""));
              }}
              aria-expanded={false}
              className="kraft packet-shadow focus-ring relative z-20 mx-4 mt-5 flex w-[calc(100%-2rem)] max-w-sm -rotate-[0.8deg] items-center justify-between gap-3 rounded-[3px] px-5 py-3 text-left"
            >
              <span>
                <span className="typewriter block text-[10px] uppercase tracking-[0.18em] text-[#4a3522]">
                  {p.brand}
                </span>
                <span className="font-display-wonk mt-0.5 block font-display text-[1.12rem] leading-tight italic text-[#35281b]">
                  {p.question}
                </span>
              </span>
              <ChevronDown className="h-4 w-4 shrink-0 text-[#4a3522]" strokeWidth={1.75} aria-hidden />
            </button>
          ) : (
            <div className="relative z-20 mx-4 mt-6 max-w-sm">{packetEl}</div>
          ))}

        <div ref={bed} className="relative z-10 mx-2 mt-6 sm:mx-6 sm:mt-4">
          {layout.rows.map((row, r) => {
            const fungus = row.plots[0];
            const threads: Thread[] = row.plots
              .filter((p) => p.kind === "plant")
              .map((p) => ({ id: plants[p.index].id, x: p.x + p.w / 2, state: threadState(plants[p.index]) }));
            return (
              <div key={r} className="relative grid" style={{ minHeight: row.plantH + row.soilH }}>
                <div className="relative self-end [grid-area:1/1]" style={{ height: row.soilH }}>
                  <SoilBand
                    width={size.w}
                    height={row.soilH}
                    sourceX={fungus.w / 2}
                    fromAbove={r > 0}
                    continues={r < layout.rows.length - 1}
                    threads={threads}
                    agentMode={agent.mode}
                    paused={agent.mode === "paused"}
                    salt={r * 97 + 11}
                  />
                </div>

                {row.plots.map((plot) => {
                  if (plot.kind === "fungus") {
                    const h = (plot.w * 158) / 112;
                    return (
                      <Fragment key="fungus">
                        <button
                          type="button"
                          onClick={() => setSettings(configurable)}
                          className="focus-ring absolute z-10 block"
                          style={{ left: plot.x, width: plot.w, bottom: row.soilH - (h * 8) / 158, height: h }}
                          aria-label={configurable ? g.fungusLocal(line.text) : g.fungus(line.text)}
                        >
                          <Fungus mode={agent.mode} paused={agent.mode === "paused"} />
                        </button>
                        {!layout.mobile && (
                          <button
                            type="button"
                            onClick={() => setSettings(configurable)}
                            className="paper paper-shadow focus-ring absolute z-20 -rotate-[1.5deg] rounded-[2px] px-2.5 pb-2 pt-2.5 text-left transition-[translate] duration-300 hover:-translate-y-0.5"
                            style={{
                              left: plot.x + 14,
                              width: plot.w - 12,
                              bottom: row.soilH - 30,
                              transform: "translateY(100%)",
                            }}
                          >
                            <span
                              aria-hidden
                              className="pointer-events-none absolute inset-[3px] rounded-[1px] border border-line"
                            />
                            <span
                              aria-hidden
                              className="absolute -top-[4px] left-1/2 h-2 w-2 -translate-x-1/2 rounded-full bg-[#b3925a] shadow-[0_1px_1px_rgba(0,0,0,0.45),inset_0_1px_0_rgba(255,255,255,0.45)]"
                            />
                            <span className="typewriter relative block text-[9.5px] uppercase tracking-[0.14em] text-ink-3">
                              {g.myceliumName}
                            </span>
                            <span className="typewriter relative block truncate text-[10.5px] text-ink-2">
                              {agent.model}
                            </span>
                            <span className={`relative mt-0.5 line-clamp-3 text-[11px] leading-tight ${line.tone}`}>
                              {line.text}
                            </span>
                          </button>
                        )}
                      </Fragment>
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
                        className="relative z-10 flex flex-col justify-end self-start justify-self-start pl-2 pr-4 [grid-area:1/1] sm:pl-4 sm:pr-10"
                        style={{
                          width: plot.x + plot.w,
                          minHeight: row.plantH,
                          marginBottom: row.soilH,
                          paddingBottom: (fungus.w * 158) / 112 + 20,
                        }}
                      >
                        <div className="relative isolate">
                          <div
                            aria-hidden
                            className="condensation pointer-events-none absolute -inset-x-12 -inset-y-8 -z-10"
                          />
                          <p className="font-display text-[1.55rem] leading-tight text-scene-ink">
                            {g.empty.title} <span className="italic">{g.empty.titleTail}</span>
                          </p>
                          <p className="mt-2 max-w-md text-[14px] leading-relaxed text-scene-ink-2">{g.empty.text}</p>
                          <div className="mt-4 flex flex-wrap gap-2">
                            {g.suggestions.map((s) => (
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
                            className="focus-ring mt-4 inline-flex items-center gap-2 self-start text-left text-[13px] text-pretty text-scene-ink-2 underline decoration-dotted underline-offset-4 hover:text-scene-ink disabled:no-underline"
                          >
                            {openingExample && (
                              <LoaderCircle
                                className="h-3.5 w-3.5 animate-spin motion-reduce:animate-none"
                                strokeWidth={2}
                                aria-hidden
                              />
                            )}
                            {openingExample ? g.empty.openingExample : g.empty.openExample}
                          </button>
                        </div>
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
        <div className="space-y-1">
          <p className="typewriter">{agentNote(t, agent)}</p>
          <p>
            {g.retention(data.retention.daysAfterVisit, data.retention.maximumDays)}
            {data.channel && (
              <>
                {" "}
                <ForgetGreenhouse />
              </>
            )}
          </p>
        </div>
        <p className="font-display italic">{g.motto}</p>
      </footer>

      {configurable && (
        <SettingsDialog open={settings} onOpenChange={setSettings} agent={agent} onChange={onAgentChange} />
      )}
    </div>
  );
}
