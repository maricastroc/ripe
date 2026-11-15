"use client";

import { ArrowLeft, LoaderCircle, PanelLeftOpen, Settings2 } from "lucide-react";
import { AnimatePresence, motion } from "motion/react";
import Link, { useLinkStatus } from "next/link";
import { useRouter } from "next/navigation";
import { ViewTransition, useCallback, useMemo, useState } from "react";
import { SettingsDialog } from "@/components/agent/SettingsDialog";
import { Specimen } from "@/components/flora/Specimen";
import { speciesOf } from "@/components/flora/species";
import { Glasshouse } from "@/components/greenhouse/Glasshouse";
import { catalog } from "@/components/greenhouse/PlantPlot";
import { Actions } from "@/components/task/Actions";
import { Harvest } from "@/components/task/Harvest";
import { Journal } from "@/components/task/Journal";
import { NowPanel } from "@/components/task/NowPanel";
import { Questions } from "@/components/task/Questions";
import { Understanding } from "@/components/task/Understanding";
import { usePolling } from "@/hooks/usePolling";
import { useSky } from "@/hooks/useSky";
import { api } from "@/lib/api";
import { returnWhen } from "@/lib/format";
import { DELIVERABLE_LABEL } from "@/lib/labels";
import { awaitsAnswer, focusOf, moodOf } from "@/lib/presence";
import type { AgentStatus, TaskDetail } from "@/lib/types";
import { GrowthSeries } from "./GrowthSeries";
import { StatusBoard } from "./StatusBoard";
import { Legend } from "./Legend";

type Data = { task: TaskDetail; agent: AgentStatus };

function interval(d: Data) {
  const a = d.task.activity;
  if (a?.running) return 1200;
  if (a || d.task.status === "ripening") return 2500;
  return 7000;
}

const dayFormat = new Intl.DateTimeFormat("en-US", { month: "short", day: "numeric" });

function BackIcon() {
  const { pending } = useLinkStatus();
  return pending ? (
    <>
      <LoaderCircle
        className="h-[18px] w-[18px] animate-spin motion-reduce:animate-none"
        strokeWidth={1.75}
        aria-hidden
      />
      <span role="status" className="sr-only">
        Opening the greenhouse…
      </span>
    </>
  ) : (
    <ArrowLeft className="h-[18px] w-[18px]" strokeWidth={1.75} aria-hidden />
  );
}

function Title({ task, onRename }: { task: TaskDetail; onRename: (title: string) => Promise<void> }) {
  const [editing, setEditing] = useState(false);
  const [value, setValue] = useState(task.title);
  return (
    <div>
      <p className="label">
        field notes · {task.deliverable ? DELIVERABLE_LABEL[task.deliverable].toLowerCase() : "request"}
      </p>
      {editing ? (
        <form
          className="mt-2"
          onSubmit={async (e) => {
            e.preventDefault();
            await onRename(value);
            setEditing(false);
          }}
        >
          <label htmlFor="title-input" className="sr-only">
            Task title
          </label>
          <input
            id="title-input"
            autoFocus
            value={value}
            maxLength={80}
            onChange={(e) => setValue(e.target.value)}
            onBlur={async () => {
              if (value.trim() && value !== task.title) await onRename(value);
              setEditing(false);
            }}
            onKeyDown={(e) => {
              if (e.key === "Escape") {
                setValue(task.title);
                setEditing(false);
              }
            }}
            className="w-full border-b border-ink bg-transparent pb-1 font-display text-[2.1rem] leading-tight tracking-tight outline-none sm:text-[2.7rem]"
          />
        </form>
      ) : (
        <h1 className="group mt-2 font-display text-[2.1rem] leading-[1.04] tracking-[-0.02em] text-ink sm:text-[2.7rem]">
          {task.title}
          {!task.isExample && (
            <button
              type="button"
              onClick={() => {
                setValue(task.title);
                setEditing(true);
              }}
              className="focus-ring ml-3 align-middle text-[12px] font-normal tracking-normal text-ink-3 opacity-0 transition-opacity hover:text-ink focus:opacity-100 group-hover:opacity-100"
            >
              rename
            </button>
          )}
        </h1>
      )}
      <blockquote className="ink mt-4 max-w-2xl border-l-2 border-cobalt/40 pl-4 text-[1.08rem] leading-snug">
        “{task.prompt}”
      </blockquote>
    </div>
  );
}

export function SpecimenSheet({ initial, initialPiece }: { initial: Data; initialPiece: string | null }) {
  const { data, setData, error, refresh } = usePolling<Data>(`/api/tasks/${initial.task.id}`, initial, interval);
  const { task, agent } = data;
  const { sky } = useSky();
  const router = useRouter();
  const [hover, setHover] = useState<string | null>(null);
  const [picked, setPicked] = useState<string | null>(initialPiece);
  const [busy, setBusy] = useState(false);
  const [dropping, setDropping] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [settings, setSettings] = useState(false);
  const [wide, setWide] = useState(false);

  const applyTask = useCallback(
    (next: TaskDetail | null | undefined) => {
      if (next) setData({ ...data, task: next });
    },
    [data, setData],
  );

  const reload = useCallback(async () => {
    try {
      const next = await api<Data>(`/api/tasks/${initial.task.id}`);
      setData(next);
      return next.task;
    } catch {
      return null;
    }
  }, [initial.task.id, setData]);

  async function run<T extends { task?: TaskDetail | null }>(work: () => Promise<T>) {
    setBusy(true);
    setNotice(null);
    try {
      const res = await work();
      applyTask(res.task);
      void refresh();
    } catch (err) {
      setNotice(err instanceof Error ? err.message : "Something went wrong. Try again.");
    } finally {
      setBusy(false);
    }
  }

  type TaskAction = "defer" | "resume" | "pause" | "harvest" | "replant" | "refresh";

  const act = (action: TaskAction, body?: object) =>
    run(() => api<{ task: TaskDetail }>(`/api/tasks/${task.id}/${action}`, { method: "POST", body: body ?? {} }));

  const rename = (title: string) =>
    run(() => api<{ task: TaskDetail }>(`/api/tasks/${task.id}`, { method: "PATCH", body: { title } }));

  async function harvest() {
    setDropping(true);
    await new Promise((r) => setTimeout(r, 950));
    await act("harvest");
    router.push("/");
  }

  async function remove() {
    setBusy(true);
    try {
      await api(`/api/tasks/${task.id}`, { method: "DELETE" });
      router.push("/");
    } catch (err) {
      setNotice(err instanceof Error ? err.message : "Couldn't delete it.");
      setBusy(false);
    }
  }

  const stepArtifacts = task.artifacts.filter((a) => a.kind === "step");
  const review = task.artifacts.find((a) => a.kind === "review") ?? null;
  const lastStep = task.steps[task.steps.length - 1];
  const finalId = lastStep?.artifactId ?? null;

  const resolve = (piece: string | null) => {
    if (!piece) return null;
    if (piece === "final") return finalId ?? stepArtifacts[stepArtifacts.length - 1]?.id ?? null;
    if (piece === "review") return review?.id ?? null;
    return task.artifacts.some((a) => a.id === piece) ? piece : null;
  };
  const defaultSelected =
    task.status === "ripe"
      ? (finalId ?? stepArtifacts[stepArtifacts.length - 1]?.id)
      : [...task.artifacts].sort((a, b) => (b.updatedAt ?? "").localeCompare(a.updatedAt ?? ""))[0]?.id;
  const selectedId = resolve(picked) ?? defaultSelected ?? null;

  const organOf = (artifactId: string | null) => {
    if (!artifactId) return null;
    if (review && artifactId === review.id) return "tag";
    return task.steps.find((s) => s.artifactId === artifactId)?.id ?? null;
  };

  const specimenState = useMemo(
    () => ({
      seed: task.id,
      fruit: task.fruit,
      growth: task.growth,
      steps: task.steps.map((s) => ({ id: s.id, status: s.phase, artifactId: s.artifactId, title: s.title })),
      awaiting: awaitsAnswer(task),
    }),
    [task],
  );

  const scrollTo = (id: string) =>
    requestAnimationFrame(() => document.getElementById(id)?.scrollIntoView({ behavior: "smooth", block: "start" }));

  const openOrgan = (key: string) => {
    if (key === "apex") {
      if (finalId) {
        setPicked(finalId);
        scrollTo("harvest");
      } else scrollTo("now-title");
      return;
    }
    if (key === "waiting") {
      scrollTo("questions-title");
      return;
    }
    if (key === "tag") {
      if (review) {
        setPicked(review.id);
        scrollTo("harvest");
      }
      return;
    }
    const step = task.steps.find((s) => s.id === key);
    if (!step) return;
    if (step.artifactId) {
      setPicked(step.artifactId);
      scrollTo("harvest");
    } else if (step.phase === "blocked" && step.waitingFor === "answer") scrollTo("questions-title");
    else scrollTo("now-title");
  };

  const answered = task.questions.some((q) => q.status === "answered");
  const canRefresh = answered && stepArtifacts.some((a) => a.gaps.length > 0) && !task.isExample;
  const readOnly = task.isExample || task.status === "harvested";
  const collect = task.status !== "ripe" && task.status !== "harvested" ? returnWhen(task.returnAt) : null;
  const species = speciesOf(task.fruit);
  const highlight = hover ?? organOf(selectedId);

  return (
    <div
      data-sky={sky}
      className="relative flex min-h-dvh flex-1 flex-col overflow-x-clip"
      style={{ background: "var(--soil-3)" }}
    >
      <div className="absolute inset-x-0 top-0 h-[78vh] overflow-hidden">
        <Glasshouse sky={sky} />
        <div className="absolute inset-x-0 bottom-0 h-1/2 bg-gradient-to-b from-transparent to-[var(--soil-3)]" />
      </div>

      <header className="relative z-20 flex items-center justify-between gap-3 px-3 py-3 sm:px-8 sm:py-4">
        <div className="flex items-center gap-2 sm:gap-4">
          <span
            className="font-display font-display-wonk pl-1 text-[1.7rem] italic leading-none text-scene-ink"
            aria-hidden
          >
            ripe
          </span>
          <span className="h-7 w-px bg-scene-ink/25" aria-hidden />
          <Link
            href="/"
            aria-label="Back to the greenhouse"
            className="back-link group inline-flex min-h-11 min-w-11 items-center gap-2.5 rounded-full py-1.5 pl-1.5 pr-3 text-scene-ink transition-colors"
          >
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-scene-ink/35 transition-[transform,background-color,border-color] duration-300 group-hover:-translate-x-0.5 group-hover:border-scene-ink/70 group-hover:bg-scene-ink/[0.07]">
              <BackIcon />
            </span>
            <span className="font-display text-[16px] italic leading-none decoration-scene-ink/40 underline-offset-[5px] group-hover:underline">
              <span className="hidden sm:inline">Back to the greenhouse</span>
              <span className="sm:hidden">Greenhouse</span>
            </span>
          </Link>
        </div>
        <button
          type="button"
          onClick={() => setSettings(agent.hosting === "local")}
          className="focus-ring typewriter inline-flex min-h-11 min-w-11 items-center justify-center gap-2 whitespace-nowrap rounded-full border border-scene-ink/20 px-3 text-[11.5px] text-scene-ink-2 transition-colors hover:border-scene-ink/45 hover:text-scene-ink"
        >
          <Settings2 className="h-3.5 w-3.5" strokeWidth={1.75} aria-hidden />
          {agent.mode === "working" && (
            <span aria-hidden className="live-dot h-1.5 w-1.5 rounded-full bg-leaf-2 text-leaf-2" />
          )}
          <span className="sr-only sm:hidden">Agent settings</span>
          <span className="hidden sm:inline">
            mycelium ·{" "}
            {agent.mode === "working"
              ? "working"
              : agent.mode === "offline"
                ? agent.driver === "ollama"
                  ? "Ollama off"
                  : "offline"
                : agent.mode === "paused"
                  ? "paused"
                  : agent.mode === "halted" || agent.mode === "stopped"
                    ? "resting"
                    : "ready"}
          </span>
        </button>
      </header>

      <AnimatePresence>
        {(error || notice) && (
          <motion.p
            initial={{ opacity: 0, y: -6 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0 }}
            role="alert"
            className="paper relative z-20 mx-auto mb-4 w-[min(640px,calc(100%-2rem))] rounded-sm px-4 py-2 text-[14px] text-vermilion-ink"
          >
            {notice ?? `${error} Trying again…`}
          </motion.p>
        )}
      </AnimatePresence>

      <div className="relative z-10 mx-auto w-full max-w-[1380px] px-2 pb-16 sm:px-6">
        <div className={`spread paper paper-shadow relative grid rounded-[3px] ${wide ? "spread-wide" : ""}`}>
          <span className="tape -left-3 -top-2 rotate-[-8deg]" aria-hidden />
          <span className="tape -right-3 -top-2 rotate-[7deg]" aria-hidden />
          <div
            className="gutter pointer-events-none absolute inset-y-0 hidden w-12 -translate-x-1/2 transition-[left] motion-reduce:transition-none duration-500 ease-[cubic-bezier(0.22,1,0.36,1)] lg:block"
            style={{ left: wide ? "21%" : "40%" }}
            aria-hidden
          />

          <aside
            className={`relative self-start px-5 pb-8 pt-8 sm:px-10 lg:sticky lg:top-3 lg:max-h-[calc(100dvh-1.5rem)] lg:overflow-y-auto scrollbar-none ${wide ? "lg:px-6" : "lg:pr-12"}`}
          >
            {task.isExample && (
              <p className="mb-5 rounded-sm border border-dashed border-vermilion/50 px-3 py-2 text-[13px] text-ink-2">
                <span className="label mr-2 text-vermilion-ink">example</span>
                Grown once by a scripted model to show a ripe task. The agent never works on it again.
              </p>
            )}
            <div className="flex items-baseline justify-between gap-3">
              <p className="typewriter text-[13px] tracking-[0.06em] text-ink-2">Specimen {catalog(task.number)}</p>
              {wide ? (
                <button
                  type="button"
                  onClick={() => setWide(false)}
                  className="focus-ring -mr-2 hidden h-9 w-9 items-center justify-center rounded-full text-ink-3 transition-colors hover:bg-paper-2 hover:text-ink lg:inline-flex"
                  aria-label="Show the plate again"
                  title="Show the plate again"
                >
                  <PanelLeftOpen className="h-4 w-4" strokeWidth={1.75} aria-hidden />
                </button>
              ) : (
                <p className="typewriter text-[11.5px] text-ink-3" suppressHydrationWarning>
                  {task.createdAt ? `sown ${dayFormat.format(new Date(task.createdAt))}` : null}
                </p>
              )}
            </div>
            <p className={`ink mt-1 leading-tight ${wide ? "text-[1.05rem]" : "text-[1.35rem]"}`}>
              {species.latin}
              {!wide && <span className="text-[0.95rem] text-ink-3 not-italic"> · {species.common}</span>}
            </p>

            <div
              className={`relative mx-auto mt-4 w-full transition-[max-width] motion-reduce:transition-none duration-500 ease-[cubic-bezier(0.22,1,0.36,1)] ${wide ? "max-w-[260px]" : "max-w-[420px]"}`}
            >
              <ViewTransition name={`specimen-${task.id}`} share="specimen" default="none">
                <div>
                  <Specimen
                    state={specimenState}
                    mood={moodOf(task)}
                    focus={focusOf({ activity: task.activity, leaves: task.leaves })}
                    variant="plate"
                    callouts
                    highlight={highlight}
                    onHover={setHover}
                    onSelect={openOrgan}
                    dropFruit={dropping}
                    className="block h-auto w-full"
                    title={`Botanical plate: ${task.title}`}
                  />
                </div>
              </ViewTransition>
            </div>

            <div className="mt-5">
              <GrowthSeries growth={task.growth} compact={wide} />
            </div>
            {!wide && (
              <p className="typewriter mt-2 text-center text-[11px] text-ink-3" suppressHydrationWarning>
                {Math.round(task.growth.maturity * 100)}% ripe · grows only with finished work
                {collect ? ` · you said you'd be back ${collect}` : ""}
              </p>
            )}

            <div className="mt-6">
              <Legend
                task={task}
                finalId={finalId}
                review={review}
                highlight={highlight}
                onHover={setHover}
                onOpen={openOrgan}
                compact={wide}
              />
            </div>

            <div className="mt-6">
              <Actions
                task={task}
                busy={busy}
                onDefer={(returnAt) => act("defer", { returnAt })}
                onPause={() => act("pause")}
                onRetry={() => act("resume")}
                onHarvest={harvest}
                onReplant={() => act("replant")}
                onDelete={remove}
              />
            </div>
          </aside>

          <main className="min-w-0 space-y-14 border-t border-dashed border-line-2 px-5 pb-12 pt-10 sm:px-10 lg:border-t-0 lg:pl-14">
            <Title task={task} onRename={rename} />
            <StatusBoard task={task} agent={agent} />
            <NowPanel task={task} agent={agent} busy={busy} onAct={() => act("resume")} />
            <Questions
              questions={task.questions}
              disabled={readOnly}
              canRefresh={canRefresh}
              onAnswer={(id, answer) =>
                run(() =>
                  api<{ task: TaskDetail }>(`/api/questions/${id}/answer`, { method: "POST", body: { answer } }),
                )
              }
              onSkip={(id) => run(() => api<{ task: TaskDetail }>(`/api/questions/${id}/skip`, { method: "POST" }))}
              onReopen={(id) => run(() => api<{ task: TaskDetail }>(`/api/questions/${id}/reopen`, { method: "POST" }))}
              onRefresh={() => act("refresh")}
            />
            <Harvest
              artifacts={task.artifacts}
              steps={task.steps}
              activity={task.activity}
              selected={selectedId}
              onSelect={setPicked}
              onHover={(id) => setHover(organOf(id))}
              wide={wide}
              onToggleWide={() => setWide((w) => !w)}
              readOnly={readOnly}
              onRevise={(id, instruction) =>
                run(() =>
                  api<{ task: TaskDetail }>(`/api/artifacts/${id}/revise`, { method: "POST", body: { instruction } }),
                )
              }
              onRestore={(id, baseVersionId) =>
                run(() =>
                  api<{ task: TaskDetail }>(`/api/artifacts/${id}/restore`, {
                    method: "POST",
                    body: { baseVersionId },
                  }),
                )
              }
              onTaskChange={applyTask}
              reload={reload}
            />
            <Understanding task={task} />
            <Journal events={task.events} />
          </main>
        </div>
      </div>

      {agent.hosting === "local" && (
        <SettingsDialog
          open={settings}
          onOpenChange={setSettings}
          agent={agent}
          onChange={(next) => setData({ ...data, agent: next })}
        />
      )}
    </div>
  );
}
