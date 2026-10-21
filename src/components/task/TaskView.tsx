"use client";

import { AnimatePresence, motion } from "motion/react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useState } from "react";
import { AgentPulse } from "@/components/agent/AgentPulse";
import { Board } from "@/components/garden/Shelf";
import { Plant } from "@/components/plant/Plant";
import { Wordmark } from "@/components/Wordmark";
import { usePolling } from "@/hooks/usePolling";
import { api } from "@/lib/api";
import { formatReturn } from "@/lib/format";
import { FRUITS } from "@/lib/growth";
import { DELIVERABLE_LABEL } from "@/lib/labels";
import { moodOf } from "@/lib/presence";
import type { AgentStatus, TaskDetail } from "@/lib/types";
import { Actions } from "./Actions";
import { Harvest } from "./Harvest";
import { Journal } from "./Journal";
import { NowPanel } from "./NowPanel";
import { Path } from "./Path";
import { Questions } from "./Questions";
import { StageStrip } from "./StageStrip";
import { Understanding } from "./Understanding";

type Data = { task: TaskDetail; agent: AgentStatus };

function interval(d: Data) {
  const a = d.task.activity;
  if (a?.status === "running") return 1200;
  if (a || d.task.status === "ripening") return 2500;
  return 7000;
}

function count(n: number, one: string, many: string) {
  return `${n} ${n === 1 ? one : many}`;
}

function Glance({ task, agent }: { task: TaskDetail; agent: AgentStatus }) {
  const remaining = task.steps.filter((s) => s.status !== "done").length;
  const open = task.questions.filter((q) => q.status === "open").length;
  const materials = task.artifacts.length;
  const running = task.activity?.status === "running";
  const reviewed = task.growth.stage === "ripe";
  const facts = [
    {
      label: "feito",
      value: task.growth.stepsTotal
        ? `${task.growth.stepsDone} de ${count(task.growth.stepsTotal, "etapa", "etapas")}`
        : task.outcome
          ? "pedido entendido"
          : "nada ainda",
    },
    {
      label: "agora",
      value: running
        ? task.activity!.type === "generate" || task.activity!.type === "revise"
          ? "escrevendo"
          : task.activity!.type === "review"
            ? "revisando"
            : task.activity!.type === "plan"
              ? "planejando"
              : "lendo o pedido"
        : task.status === "ripe"
          ? "descansando"
          : task.status === "waiting"
            ? "esperando você"
            : task.status === "dormant"
              ? "adormecida"
              : task.status === "stalled"
                ? "parada"
                : task.status === "harvested"
                  ? "colhida"
                  : agent.settings.paused
                    ? "agente pausado"
                    : agent.mode === "offline"
                      ? "esperando o Ollama"
                      : agent.mode === "model-missing"
                        ? "esperando o modelo"
                        : "na fila",
    },
    {
      label: "falta",
      value: !task.steps.length
        ? task.status === "dormant"
          ? "começar"
          : "traçar o caminho"
        : remaining
          ? `${count(remaining, "etapa", "etapas")}${reviewed ? "" : " + revisão"}`
          : reviewed
            ? "nada"
            : "a revisão",
    },
    { label: "de você", value: open ? count(open, "resposta", "respostas") : "nada agora", alert: open > 0 },
    { label: "para revisar", value: materials ? count(materials, "material", "materiais") : "ainda nada" },
  ];
  return (
    <dl className="grid grid-cols-2 gap-x-6 gap-y-4 border-y border-line py-5 sm:grid-cols-5">
      {facts.map((f) => (
        <div key={f.label} className="min-w-0">
          <dt className="label">{f.label}</dt>
          <dd
            className={`mt-1 font-display text-[1.05rem] leading-tight tracking-tight ${f.alert ? "text-accent-ink" : "text-ink"}`}
          >
            {f.value}
          </dd>
        </div>
      ))}
    </dl>
  );
}

function Title({ task, onRename }: { task: TaskDetail; onRename: (title: string) => Promise<void> }) {
  const [editing, setEditing] = useState(false);
  const [value, setValue] = useState(task.title);
  const fruit = task.fruit ? FRUITS[task.fruit].name : null;
  return (
    <div>
      <p className="label">
        {task.deliverable ? DELIVERABLE_LABEL[task.deliverable] : "pedido"} ·{" "}
        {fruit ? `vai dar ${fruit}` : "a espécie aparece quando brotar"}
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
            Título da tarefa
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
            className="w-full border-b border-ink bg-transparent pb-1 font-display text-[2.2rem] leading-tight tracking-tight outline-none sm:text-5xl"
          />
        </form>
      ) : (
        <h1 className="group mt-2 font-display text-[2.2rem] leading-[1.05] tracking-[-0.02em] text-ink sm:text-5xl">
          {task.title}
          {!task.isExample && (
            <button
              type="button"
              onClick={() => {
                setValue(task.title);
                setEditing(true);
              }}
              className="focus-ring ml-3 align-middle text-[13px] font-normal tracking-normal text-ink-3 opacity-0 transition-opacity hover:text-ink focus:opacity-100 group-hover:opacity-100"
            >
              renomear
            </button>
          )}
        </h1>
      )}
      <figure className="mt-5 max-w-2xl">
        <figcaption className="label">seu pedido</figcaption>
        <blockquote className="mt-1.5 text-[15px] leading-relaxed text-ink-2">“{task.prompt}”</blockquote>
      </figure>
    </div>
  );
}

export function TaskView({ initial }: { initial: Data }) {
  const { data, setData, error, refresh } = usePolling<Data>(`/api/tasks/${initial.task.id}`, initial, interval);
  const { task, agent } = data;
  const router = useRouter();
  const [hover, setHover] = useState<string | null>(null);
  const [selected, setSelected] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [dropping, setDropping] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  const applyTask = useCallback(
    (next: TaskDetail | null | undefined) => {
      if (next) setData({ ...data, task: next });
    },
    [data, setData],
  );

  async function run<T extends { task?: TaskDetail | null }>(work: () => Promise<T>) {
    setBusy(true);
    setNotice(null);
    try {
      const res = await work();
      applyTask(res.task);
      void refresh();
    } catch (err) {
      setNotice(err instanceof Error ? err.message : "Algo deu errado.");
    } finally {
      setBusy(false);
    }
  }

  const patchTask = (body: object) =>
    run(() => api<{ task: TaskDetail }>(`/api/tasks/${task.id}`, { method: "PATCH", body }));

  async function harvest() {
    setDropping(true);
    await new Promise((r) => setTimeout(r, 950));
    await patchTask({ action: "harvest" });
    router.push("/");
  }

  async function remove() {
    setBusy(true);
    try {
      await api(`/api/tasks/${task.id}`, { method: "DELETE" });
      router.push("/");
    } catch (err) {
      setNotice(err instanceof Error ? err.message : "Não consegui excluir.");
      setBusy(false);
    }
  }

  const stepArtifacts = task.artifacts.filter((a) => a.kind === "step");
  const defaultSelected =
    task.status === "ripe"
      ? stepArtifacts[stepArtifacts.length - 1]?.id
      : [...task.artifacts].sort((a, b) => b.updatedAt.localeCompare(a.updatedAt))[0]?.id;
  const selectedId = selected && task.artifacts.some((a) => a.id === selected) ? selected : (defaultSelected ?? null);

  const answered = task.questions.some((q) => q.status === "answered");
  const canRefresh = answered && stepArtifacts.some((a) => a.gaps.length > 0) && !task.isExample;
  const readOnly = task.isExample || task.status === "harvested";
  const back = task.status !== "ripe" && task.status !== "harvested" ? formatReturn(task.returnAt) : null;
  const leaves = task.steps.map((s) => ({ id: s.id, status: s.status }));

  const openArtifact = (id: string) => {
    setSelected(id);
    requestAnimationFrame(() =>
      document.getElementById("colheita")?.scrollIntoView({ behavior: "smooth", block: "start" }),
    );
  };

  return (
    <div className="mx-auto w-full max-w-[1240px] flex-1 px-4 pb-20 sm:px-8 lg:px-12">
      <header className="flex items-center justify-between gap-4 py-6">
        <div className="flex items-center gap-5">
          <Wordmark size="sm" />
          <Link href="/" className="focus-ring hidden rounded-full text-[14px] text-ink-3 hover:text-ink sm:inline">
            ← parapeito
          </Link>
        </div>
        <AgentPulse agent={agent} onChange={(next) => setData({ ...data, agent: next })} />
      </header>

      <AnimatePresence>
        {(error || notice) && (
          <motion.p
            initial={{ opacity: 0, y: -6 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0 }}
            role="alert"
            className="mb-4 rounded-2xl border border-accent/40 bg-accent-soft px-4 py-2.5 text-[14px] text-ink"
          >
            {notice ?? `${error} Tentando de novo…`}
          </motion.p>
        )}
      </AnimatePresence>

      {task.isExample && (
        <div className="mb-6 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-2xl border border-dashed border-line-2 px-4 py-3 text-[14px] text-ink-2">
          <span className="label text-accent-ink">exemplo</span>
          Conteúdo escrito à mão para demonstrar uma tarefa madura. Não foi gerado pelo agente e não será processado.
        </div>
      )}

      <div className="mb-8 lg:hidden">
        <Title task={task} onRename={(title) => patchTask({ action: "rename", title })} />
      </div>

      <div className="grid gap-12 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-16">
        <aside className="self-start lg:sticky lg:top-6">
          <div className="mx-auto max-w-[300px] sm:max-w-[340px] lg:mx-0">
            <div className="px-[10%]">
              <Plant
                seed={task.id}
                growth={task.growth}
                leaves={leaves}
                fruit={task.fruit}
                mood={moodOf(task)}
                highlight={hover}
                dropFruit={dropping}
                className="block h-auto w-full"
              />
            </div>
            <Board first last />
          </div>

          <div className="mt-10 max-w-[420px]">
            <StageStrip growth={task.growth} />
            <p className="mt-3 font-mono text-[12px] text-ink-3">
              {Math.round(task.growth.maturity * 100)}% madura · cresce só com trabalho pronto
            </p>
            {back && (
              <p className="mt-1 text-[13px] text-ink-3" suppressHydrationWarning>
                Você disse que {back}.
              </p>
            )}
            <div className="mt-6">
              <Actions
                task={task}
                busy={busy}
                onDefer={(returnAt) => patchTask({ action: "defer", returnAt })}
                onPause={() => patchTask({ action: "pause" })}
                onRetry={() => patchTask({ action: "resume" })}
                onHarvest={harvest}
                onReplant={() => patchTask({ action: "replant" })}
                onDelete={remove}
              />
            </div>

            <div className="mt-10">
              <h2 className="label mb-2">o caminho</h2>
              <Path
                steps={task.steps}
                questions={task.questions}
                reviewed={task.growth.stage === "ripe"}
                planned={task.steps.length > 0}
                onHover={setHover}
                onOpen={openArtifact}
              />
            </div>
          </div>
        </aside>

        <main className="min-w-0 space-y-14">
          <div className="hidden lg:block">
            <Title task={task} onRename={(title) => patchTask({ action: "rename", title })} />
          </div>
          <Glance task={task} agent={agent} />
          <NowPanel task={task} agent={agent} busy={busy} onAct={() => patchTask({ action: "resume" })} />
          <Questions
            questions={task.questions}
            disabled={readOnly}
            canRefresh={canRefresh}
            onAnswer={(id, answer) =>
              run(() =>
                api<{ task: TaskDetail }>(`/api/questions/${id}`, {
                  method: "PATCH",
                  body: { action: "answer", answer },
                }),
              )
            }
            onSkip={(id) =>
              run(() =>
                api<{ task: TaskDetail }>(`/api/questions/${id}`, { method: "PATCH", body: { action: "skip" } }),
              )
            }
            onReopen={(id) =>
              run(() =>
                api<{ task: TaskDetail }>(`/api/questions/${id}`, { method: "PATCH", body: { action: "reopen" } }),
              )
            }
            onRefresh={() => patchTask({ action: "refresh" })}
          />
          <Harvest
            artifacts={task.artifacts}
            steps={task.steps}
            activity={task.activity}
            selected={selectedId}
            onSelect={setSelected}
            readOnly={readOnly}
            onRevise={(id, instruction) =>
              run(() =>
                api<{ task: TaskDetail }>(`/api/artifacts/${id}`, {
                  method: "PATCH",
                  body: { action: "revise", instruction },
                }),
              )
            }
            onRestore={(id) =>
              run(() =>
                api<{ task: TaskDetail }>(`/api/artifacts/${id}`, { method: "PATCH", body: { action: "restore" } }),
              )
            }
          />
          <Understanding task={task} />
          <Journal events={task.events} />
        </main>
      </div>
    </div>
  );
}
