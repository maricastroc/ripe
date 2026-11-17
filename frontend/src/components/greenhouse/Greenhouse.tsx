"use client";

import { ChevronDown } from "lucide-react";
import { AnimatePresence, motion } from "motion/react";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
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
import { Bed } from "./Bed";
import { ForgetGreenhouse } from "./ForgetGreenhouse";
import { layoutStrip } from "./layout";
import { Pantry } from "./Pantry";
import { CensusKey, EmptyNote, MyceliumNote, PlateSheet, SeedShelf } from "./Plate";
import { FIGURE_VIEW, FigureUnderground, PlateFigure } from "./PlateFigure";
import { PlateSoil } from "./PlateSoil";
import { catalog } from "./PlantPlot";
import { type PacketHandle, SeedPacket } from "./SeedPacket";
import { HOLE, SowingMarks, packetFoot } from "./SowingMarks";

const SOIL_FALLBACK = "linear-gradient(var(--plate-soil-0), var(--plate-soil-1) 45%, var(--soil-3))";
const EASE = [0.22, 1, 0.36, 1] as const;

type Frame = {
  width: number;
  viewport: number;
  ground: number;
  soil: number;
  bedX: number;
  visible: number;
  band: number;
  packetX: number;
  packetW: number;
  fig: { x: number; w: number; h: number };
  textBottom: number;
  stacked: boolean;
  roots: number;
};

function guessFrame(growing: boolean): Frame {
  const width = 1372;
  const bedX = 24 + (growing ? 274 : 412);
  const packetW = 317;
  const packetX = width - 22 - packetW;
  return {
    width,
    viewport: 900,
    ground: 529,
    soil: 299,
    bedX,
    visible: packetX - bedX,
    band: 466,
    packetX,
    packetW,
    fig: { x: bedX, w: packetX - bedX, h: 466 },
    textBottom: 200,
    stacked: false,
    roots: 0,
  };
}

function stackedPlantH(viewport: number) {
  return Math.round(Math.max(190, Math.min(300, viewport * 0.3)));
}

function interval(data: GardenData) {
  if (data.agent.mode === "working" || data.tasks.some((t) => t.activity?.running)) return 2000;
  if (data.tasks.some((t) => t.status === "ripening")) return 3500;
  return 8000;
}

function agentLine(i18n: I18n, agent: AgentStatus) {
  const a = i18n.t.greenhouse.agent;
  switch (agent.mode) {
    case "paused":
      return { text: a.paused, dot: "var(--ochre)" };
    case "resting":
      return { text: restText(i18n, agent).short, dot: "var(--cobalt-soft)" };
    case "halted":
      return { text: a.halted, dot: "var(--vermilion)" };
    case "working":
      return { text: agent.current ? a.feeding(agent.current.taskTitle) : a.working, dot: "var(--myc)" };
    case "offline":
      return { text: agent.driver === "ollama" ? a.ollamaOff : a.unreachable, dot: "var(--vermilion)" };
    case "model-missing":
      return { text: a.modelMissing(agent.model), dot: "var(--vermilion)" };
    case "stopped":
      return { text: a.stopped, dot: "var(--vermilion)" };
    default:
      if (agent.queued) return { text: a.queued(agent.queued), dot: "var(--line)" };
      if (agent.elsewhere) return { text: a.elsewhere, dot: "var(--leaf-2)" };
      return { text: a.idle, dot: "var(--line)" };
  }
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
  const grid = useRef<HTMLDivElement>(null);
  const figure = useRef<HTMLDivElement>(null);
  const packetCol = useRef<HTMLDivElement>(null);
  const notes = useRef<HTMLDivElement>(null);
  const band = useRef<HTMLDivElement>(null);
  const packet = useRef<PacketHandle>(null);
  const [measured, setMeasured] = useState<Frame | null>(null);
  const [freshId, setFreshId] = useState<string | null>(null);
  const [settings, setSettings] = useState(false);
  const [sowing, setSowing] = useState(false);
  const [openingExample, setOpeningExample] = useState(false);

  const plants = useMemo(() => data.tasks.filter((t) => t.status !== "harvested").reverse(), [data.tasks]);
  const harvested = data.tasks.filter((t) => t.status === "harvested");
  const growing = plants.length > 0;

  useEffect(() => {
    const root = grid.current;
    if (!root) return;
    const measure = () => {
      const fig = figure.current;
      const pk = packetCol.current;
      if (!fig || !pk) return;
      const ground = fig.offsetTop + fig.offsetHeight;
      const text = notes.current;
      const stacked = window.matchMedia("(max-width: 1023.98px)").matches;
      const next: Frame = {
        width: root.clientWidth,
        viewport: window.innerHeight,
        ground,
        soil: root.clientHeight - ground,
        bedX: stacked ? 4 : fig.offsetLeft,
        visible: stacked ? root.clientWidth - 8 : fig.offsetWidth,
        band: stacked ? stackedPlantH(window.innerHeight) : fig.offsetHeight,
        packetX: pk.offsetLeft,
        packetW: pk.offsetWidth,
        fig: { x: fig.offsetLeft, w: fig.offsetWidth, h: fig.offsetHeight },
        textBottom: text ? text.offsetTop + text.offsetHeight - ground : 0,
        stacked,
        roots: band.current?.offsetHeight ?? 0,
      };
      setMeasured((f) => (f && JSON.stringify(f) === JSON.stringify(next) ? f : next));
    };
    measure();
    const observer = new ResizeObserver(measure);
    for (const el of [root, figure.current, packetCol.current, notes.current, band.current])
      if (el) observer.observe(el);
    window.addEventListener("resize", measure);
    return () => {
      observer.disconnect();
      window.removeEventListener("resize", measure);
    };
  }, [growing]);

  useEffect(() => setRealtime(live), [live, setRealtime]);

  const onAgentChange = useCallback((agent: AgentStatus) => setData({ ...data, agent }), [data, setData]);

  const frame = measured ?? guessFrame(growing);
  const numbers = useMemo(() => {
    const ordered = [...data.tasks].sort((a, b) => (a.createdAt ?? "").localeCompare(b.createdAt ?? ""));
    return new Map(ordered.map((t, i) => [t.id, i + 1]));
  }, [data.tasks]);
  const strip = layoutStrip(frame.visible, plants.length, frame.band);
  const agent = data.agent;
  const line = agentLine(i18n, agent);
  const configurable = agent.hosting === "local";
  const census = {
    total: plants.length,
    growing: plants.filter((t) => t.activity?.running).length,
    waiting: plants.filter((t) => t.status === "waiting" || t.status === "stalled").length,
    ripe: plants.filter((t) => t.status === "ripe").length,
  };
  const skyLabel = `${t.sky[sky]}${time ? ` · ${time}` : ""}`;
  const marks: [string, string, string] = [
    t.stages.sprout.name.toLocaleLowerCase(i18n.locale),
    t.stages.leafing.name.toLocaleLowerCase(i18n.locale),
    t.stages.ripe.name.toLocaleLowerCase(i18n.locale),
  ];
  const hole = frame.stacked
    ? { x: frame.width - 52, y: Math.round(frame.roots * 0.42) }
    : { x: frame.packetX + packetFoot(frame.packetW) + HOLE.x, y: HOLE.y };
  const scale = Math.min(frame.fig.w / FIGURE_VIEW.width, frame.fig.h / FIGURE_VIEW.height);
  const figureOx = frame.fig.x + (frame.fig.w - FIGURE_VIEW.width * scale) / 2 - FIGURE_VIEW.x * scale;
  const collapsed = frame.stacked && frame.width < 600 && growing && !sowing;
  const p = t.packet;

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

  return (
    <div
      data-sky={sky}
      data-realtime={live ? "live" : "polling"}
      className="relative flex flex-1 flex-col overflow-x-clip"
      style={{ background: "var(--soil-3)" }}
    >
      <PlateSheet label={g.label}>
        <div
          ref={grid}
          className="plate-grid relative border-[0.5px] border-ink/60"
          data-growing={growing ? "" : undefined}
        >
          <header className="plate-head relative z-30 grid grid-cols-[1fr_auto] items-center gap-x-6 gap-y-2 border-b-[0.6px] border-ink/60 py-3 lg:grid-cols-[1fr_minmax(0,max-content)_1fr] lg:py-0">
            <p className="font-display font-display-wonk -mt-1 justify-self-start text-[38px] italic leading-none tracking-[-0.01em] text-ink">
              ripe
            </p>
            <p className="typewriter order-last col-span-2 hidden text-center text-[10.5px] uppercase leading-[1.6] tracking-[0.2em] text-ink-2 min-[480px]:block lg:order-none lg:col-span-1">
              {t.plate.title}
            </p>
            <div className="col-start-2 flex items-center gap-3 justify-self-end sm:gap-[18px] lg:col-start-3">
              <button
                type="button"
                onClick={() => setSettings(configurable)}
                title={agent.model}
                className="focus-ring typewriter inline-flex min-h-11 items-center gap-2 whitespace-nowrap text-[11.5px] tracking-[0.08em] text-ink-2 transition-colors hover:text-ink sm:min-h-0"
                aria-label={configurable ? g.openSettings : g.agentIs(line.text)}
              >
                <span
                  aria-hidden
                  className={`h-1.5 w-1.5 rounded-full ${agent.mode === "working" ? "live-dot text-myc" : ""}`}
                  style={{ background: agent.mode === "idle" ? "var(--line-2)" : line.dot }}
                />
                {g.myceliumName}
              </button>
              <LanguageSwitch className="text-ink-2 max-sm:[&_button]:min-h-11 max-sm:[&_button]:min-w-11" />
            </div>
          </header>

          <AnimatePresence>
            {error && (
              <motion.p
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                exit={{ opacity: 0 }}
                role="status"
                className="plate-alert paper paper-shadow relative z-40 mt-3 self-start justify-self-center rounded-sm px-3 py-1.5 text-[13px] text-vermilion-ink"
              >
                {error} {g.retrying}
              </motion.p>
            )}
          </AnimatePresence>

          {growing && <CensusKey census={census} />}

          <h1 className="plate-title plate-h1 relative z-20 grid pl-0.5 font-display">
            <motion.span
              initial={{ opacity: 0, y: 8 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.9, ease: EASE }}
              className="block self-end pt-4 text-ink [text-wrap:balance]"
              style={{ paddingBottom: "calc(15px - 0.115em)" }}
            >
              {g.headline}
            </motion.span>{" "}
            <motion.span
              initial={{ opacity: 0, y: -8 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.9, delay: 0.15, ease: EASE }}
              className={`font-display-wonk block w-max self-start justify-self-start pt-3 italic text-paper [text-wrap:balance] ${growing ? "max-w-[3.9em]" : "max-w-full lg:max-w-[calc(100%+7.5rem)]"}`}
            >
              {g.headlineTail}
            </motion.span>
          </h1>

          <div
            ref={figure}
            className="plate-figure relative z-10"
            style={
              (growing && frame.stacked ? { "--plate-band": `${frame.band}px` } : undefined) as
                React.CSSProperties | undefined
            }
          >
            {!growing && <PlateFigure sky={sky} label={skyLabel} marks={marks} scale={scale} />}
          </div>

          <div ref={packetCol} className="plate-packet relative z-30">
            {collapsed ? (
              <button
                type="button"
                onClick={() => {
                  setSowing(true);
                  requestAnimationFrame(() => packet.current?.fill(""));
                }}
                aria-expanded={false}
                className="kraft packet-shadow focus-ring relative flex w-full -rotate-[0.8deg] items-center justify-between gap-3 rounded-[3px] px-5 py-3 text-left"
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
              <SeedPacket ref={packet} onPlanted={planted} nextNumber={data.tasks.length + 1} turnstile={turnstile} />
            )}
          </div>

          {!growing && (
            <>
              <div className="plate-ground relative" style={{ background: measured ? undefined : SOIL_FALLBACK }}>
                {measured && (
                  <PlateSoil width={frame.width} height={frame.soil} salt={7}>
                    <FigureUnderground
                      ox={figureOx}
                      scale={scale}
                      height={frame.stacked ? frame.roots : frame.soil}
                      hole={hole}
                      textBottom={frame.stacked ? -1000 : frame.textBottom}
                      drawHole={frame.stacked}
                      flat={frame.stacked}
                      mode={agent.mode}
                      paused={agent.mode === "paused"}
                    />
                  </PlateSoil>
                )}
              </div>
              <div ref={band} className="plate-band" aria-hidden />
            </>
          )}

          {growing && (
            <>
              <div className="plate-undersoil" aria-hidden />
              <Bed
                strip={strip}
                bedX={frame.bedX}
                visible={frame.visible}
                width={frame.width}
                plants={plants}
                agent={agent}
                numbers={numbers}
                freshId={freshId}
                sky={sky}
                skyLabel={skyLabel}
                toward={frame.stacked ? undefined : hole}
                soilMin={frame.stacked ? 156 : 96}
                fungusLabel={configurable ? g.fungusLocal(line.text) : g.fungus(line.text)}
                onFungus={() => setSettings(configurable)}
              />
            </>
          )}

          <SowingMarks empty={!growing} next={catalog(data.tasks.length + 1)} note={t.plate.sowHere} />
          {!growing && (
            <>
              <SeedShelf onPick={(s) => packet.current?.fill(`${s} `)} onExample={example} opening={openingExample} />
              <EmptyNote />
            </>
          )}

          <div ref={notes} className="plate-notes relative z-20 self-start pb-3 pl-1.5">
            <AnimatePresence initial={false} mode="popLayout">
              {growing ? (
                <motion.div
                  key="agent"
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                  exit={{ opacity: 0 }}
                  transition={{ duration: 0.4 }}
                >
                  <MyceliumNote
                    text={line.text}
                    dot={line.dot}
                    working={agent.mode === "working"}
                    className="plate-agent mt-5"
                  />
                </motion.div>
              ) : (
                <motion.div
                  key="lede"
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                  exit={{ opacity: 0 }}
                  transition={{ duration: 0.4 }}
                >
                  <p className="plate-lede mt-4 w-full max-w-[400px] text-[15px] leading-[22px] text-root">
                    {t.plate.lede}
                  </p>
                </motion.div>
              )}
            </AnimatePresence>
          </div>
        </div>
      </PlateSheet>

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
