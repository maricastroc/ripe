"use client";

import { AnimatePresence, motion } from "motion/react";
import { useEffect, useMemo, useRef, useState } from "react";
import { Markdown } from "@/components/Markdown";
import { api } from "@/lib/api";
import { formatRelative } from "@/lib/format";
import { countWords } from "@/lib/markers";
import type { Activity, ArtifactView, StepView } from "@/lib/types";

type Props = {
  artifacts: ArtifactView[];
  steps: StepView[];
  activity: Activity | null;
  selected: string | null;
  onSelect: (id: string) => void;
  readOnly: boolean;
  onRevise: (id: string, instruction: string) => Promise<void>;
  onRestore: (id: string) => Promise<void>;
};

const QUICK = ["mais curto e direto", "tom mais formal", "mais detalhado", "em tópicos", "mais caloroso"];

type SaveState = "idle" | "saving" | "saved" | "error";

function slug(text: string) {
  return text
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "")
    .slice(0, 60);
}

function Editor({
  artifact,
  initial,
  onChange,
}: {
  artifact: ArtifactView;
  initial: string;
  onChange: (content: string) => void;
}) {
  const [value, setValue] = useState(initial);
  const [state, setState] = useState<SaveState>("idle");
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const area = useRef<HTMLTextAreaElement>(null);
  const latest = useRef(value);

  useEffect(() => {
    const el = area.current;
    if (!el) return;
    el.style.height = "auto";
    el.style.height = `${Math.max(320, el.scrollHeight)}px`;
  }, [value]);

  useEffect(() => {
    return () => {
      if (timer.current) {
        clearTimeout(timer.current);
        void api(`/api/artifacts/${artifact.id}`, {
          method: "PATCH",
          body: { action: "edit", content: latest.current },
        });
      }
    };
  }, [artifact.id]);

  function change(next: string) {
    setValue(next);
    latest.current = next;
    onChange(next);
    setState("saving");
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(async () => {
      timer.current = null;
      try {
        await api(`/api/artifacts/${artifact.id}`, { method: "PATCH", body: { action: "edit", content: next } });
        setState("saved");
      } catch {
        setState("error");
      }
    }, 800);
  }

  return (
    <div>
      <label htmlFor={`edit-${artifact.id}`} className="sr-only">
        Editar {artifact.title}
      </label>
      <textarea
        id={`edit-${artifact.id}`}
        ref={area}
        value={value}
        onChange={(e) => change(e.target.value)}
        spellCheck
        className="block w-full resize-none bg-transparent font-mono text-[14px] leading-[1.75] text-ink outline-none"
      />
      <p className="label mt-3 text-right" aria-live="polite">
        {state === "saving"
          ? "salvando…"
          : state === "saved"
            ? "salvo"
            : state === "error"
              ? "não salvou — tente de novo"
              : "markdown · salva sozinho"}
      </p>
    </div>
  );
}

export function Harvest({ artifacts, steps, activity, selected, onSelect, readOnly, onRevise, onRestore }: Props) {
  const [chosenMode, setMode] = useState<"read" | "edit">("read");
  const [revising, setRevising] = useState(false);
  const [instruction, setInstruction] = useState("");
  const [sending, setSending] = useState(false);
  const [copied, setCopied] = useState(false);
  const [local, setLocal] = useState<Record<string, { content: string; version: number }>>({});

  const current = artifacts.find((a) => a.id === selected) ?? artifacts[artifacts.length - 1] ?? null;
  const stepNumber = useMemo(() => new Map(steps.map((s) => [s.id, s.position + 1])), [steps]);
  const busyHere = activity?.type === "revise" && activity.artifactId === current?.id;
  const draft = current ? local[current.id] : undefined;
  const content = current ? (draft && draft.version === current.version ? draft.content : current.content) : "";

  const mode = busyHere ? "read" : chosenMode;

  if (!artifacts.length) {
    const next = steps.find((s) => s.status === "working") ?? steps.find((s) => s.status === "pending");
    return (
      <section id="colheita" aria-labelledby="harvest-title">
        <h2 id="harvest-title" className="label mb-3">
          colheita
        </h2>
        <div className="rounded-2xl border border-dashed border-line-2 px-6 py-10 text-center">
          <p className="font-display text-xl tracking-tight text-ink-2">Ainda não há nada para colher.</p>
          <p className="mx-auto mt-2 max-w-md text-[14px] leading-relaxed text-ink-3">
            {next
              ? `O primeiro material vai ser “${next.title}”. Ele aparece aqui assim que estiver pronto — e você pode editar tudo.`
              : "Cada etapa concluída vira um material aqui, pronto para ler, editar e copiar."}
          </p>
        </div>
      </section>
    );
  }

  async function copy() {
    if (!current) return;
    await navigator.clipboard.writeText(content);
    setCopied(true);
    setTimeout(() => setCopied(false), 1600);
  }

  function download() {
    if (!current) return;
    const blob = new Blob([content], { type: "text/markdown;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `${slug(current.title) || "material"}.md`;
    a.click();
    URL.revokeObjectURL(url);
  }

  async function revise(text: string) {
    if (!current || !text.trim()) return;
    setSending(true);
    try {
      await onRevise(current.id, text.trim());
      setInstruction("");
      setRevising(false);
    } finally {
      setSending(false);
    }
  }

  return (
    <section id="colheita" aria-labelledby="harvest-title" className="scroll-mt-6">
      <div className="mb-4 flex items-baseline justify-between gap-4">
        <h2 id="harvest-title" className="label">
          colheita · {artifacts.length} {artifacts.length === 1 ? "material" : "materiais"}
        </h2>
        <span className="text-[13px] text-ink-3">tudo editável</span>
      </div>

      <div
        role="tablist"
        aria-label="Materiais produzidos"
        className="scrollbar-none -mx-1 flex gap-1 overflow-x-auto px-1 pb-1 sm:flex-wrap sm:overflow-visible"
      >
        {artifacts.map((a) => {
          const active = a.id === current?.id;
          const n = a.stepId ? stepNumber.get(a.stepId) : null;
          return (
            <button
              key={a.id}
              role="tab"
              type="button"
              aria-selected={active}
              onClick={() => {
                onSelect(a.id);
                setMode("read");
                setRevising(false);
              }}
              className={`focus-ring relative shrink-0 rounded-full px-3.5 py-1.5 text-[13.5px] transition-colors ${
                active ? "text-paper" : "text-ink-2 hover:bg-paper-2 hover:text-ink"
              }`}
            >
              {active && (
                <motion.span
                  layoutId="harvest-tab"
                  className="absolute inset-0 rounded-full bg-ink"
                  transition={{ type: "spring", stiffness: 380, damping: 32 }}
                />
              )}
              <span className="relative flex items-center gap-1.5">
                {n ? <span className="font-mono text-[11px] opacity-60">{n}</span> : <span aria-hidden>✶</span>}
                <span className="max-w-[16ch] truncate">{a.title}</span>
              </span>
            </button>
          );
        })}
      </div>

      {current && (
        <div className="sheet mt-4 rounded-[22px]">
          <div className="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-line px-5 py-3 sm:px-8">
            <div
              className="flex min-w-0 flex-1 items-center gap-1 rounded-full bg-paper-2 p-0.5 sm:flex-none"
              role="group"
              aria-label="Modo"
            >
              {(["read", "edit"] as const).map((m) => (
                <button
                  key={m}
                  type="button"
                  disabled={readOnly || busyHere}
                  onClick={() => setMode(m)}
                  aria-pressed={mode === m}
                  className={`focus-ring flex-1 rounded-full px-3 py-1 text-[13px] transition-colors disabled:opacity-40 sm:flex-none ${
                    mode === m ? "bg-surface text-ink shadow-sm" : "text-ink-3 hover:text-ink"
                  }`}
                >
                  {m === "read" ? "Ler" : "Editar"}
                </button>
              ))}
            </div>
            <div className="flex items-center gap-1 text-[13px]">
              <button
                type="button"
                onClick={() => void copy()}
                className="focus-ring rounded-full px-2.5 py-1 text-ink-2 hover:bg-paper-2 hover:text-ink"
              >
                {copied ? "copiado ✓" : "copiar"}
              </button>
              <button
                type="button"
                onClick={download}
                className="focus-ring rounded-full px-2.5 py-1 text-ink-2 hover:bg-paper-2 hover:text-ink"
              >
                baixar .md
              </button>
              {!readOnly && (
                <button
                  type="button"
                  disabled={busyHere}
                  onClick={() => setRevising((r) => !r)}
                  aria-expanded={revising}
                  className="focus-ring rounded-full px-2.5 py-1 text-ink-2 hover:bg-paper-2 hover:text-ink disabled:opacity-40"
                >
                  reescrever…
                </button>
              )}
              {!readOnly && current.hasPrevious && !busyHere && (
                <button
                  type="button"
                  onClick={() => void onRestore(current.id)}
                  className="focus-ring rounded-full px-2.5 py-1 text-ink-3 hover:bg-paper-2 hover:text-ink"
                >
                  versão anterior
                </button>
              )}
            </div>
          </div>

          <AnimatePresence initial={false}>
            {revising && !busyHere && (
              <motion.form
                initial={{ height: 0, opacity: 0 }}
                animate={{ height: "auto", opacity: 1 }}
                exit={{ height: 0, opacity: 0 }}
                className="overflow-hidden border-b border-line"
                onSubmit={(e) => {
                  e.preventDefault();
                  void revise(instruction);
                }}
              >
                <div className="px-5 py-4 sm:px-8">
                  <label htmlFor="revise-input" className="text-[14px] text-ink-2">
                    O que você quer que mude em “{current.title}”?
                  </label>
                  <div className="mt-2 flex gap-2">
                    <input
                      id="revise-input"
                      value={instruction}
                      onChange={(e) => setInstruction(e.target.value)}
                      placeholder="ex.: corte pela metade e deixe mais pessoal"
                      maxLength={500}
                      className="focus-ring min-w-0 flex-1 rounded-full border border-line bg-paper px-4 py-2 text-[14px] placeholder:text-ink-3"
                    />
                    <button
                      type="submit"
                      disabled={sending || !instruction.trim()}
                      className="focus-ring rounded-full bg-ink px-4 text-[13px] font-medium text-paper disabled:opacity-40"
                    >
                      {sending ? "…" : "pedir"}
                    </button>
                  </div>
                  <div className="mt-2.5 flex flex-wrap gap-1.5">
                    {QUICK.map((q) => (
                      <button
                        key={q}
                        type="button"
                        disabled={sending}
                        onClick={() => void revise(q)}
                        className="focus-ring rounded-full border border-line px-2.5 py-1 text-[12.5px] text-ink-2 hover:border-line-2 hover:text-ink"
                      >
                        {q}
                      </button>
                    ))}
                  </div>
                  <p className="mt-3 text-[12px] text-ink-3">A versão atual fica guardada; dá para voltar depois.</p>
                </div>
              </motion.form>
            )}
          </AnimatePresence>

          <div className="relative px-5 py-6 sm:px-10 sm:py-9">
            <AnimatePresence mode="wait">
              <motion.div
                key={`${current.id}-${mode}-${current.version}`}
                initial={{ opacity: 0, y: 6 }}
                animate={{ opacity: busyHere ? 0.35 : 1, y: 0 }}
                exit={{ opacity: 0 }}
                transition={{ duration: 0.3 }}
              >
                {mode === "edit" && !readOnly ? (
                  <Editor
                    artifact={current}
                    initial={content}
                    onChange={(next) =>
                      setLocal((l) => ({ ...l, [current.id]: { content: next, version: current.version } }))
                    }
                  />
                ) : (
                  <Markdown content={content} />
                )}
              </motion.div>
            </AnimatePresence>
            {busyHere && (
              <div className="absolute inset-0 flex items-start justify-center pt-16">
                <p className="rounded-full border border-line bg-surface px-4 py-2 text-[14px] text-ink-2 shadow-sm">
                  <span className="dot-live mr-2 inline-block h-2 w-2 rounded-full bg-leaf text-leaf" />
                  reescrevendo agora — acompanhe em “agora”
                </p>
              </div>
            )}
          </div>

          <div className="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-line px-5 py-3 font-mono text-[11.5px] text-ink-3 sm:px-8">
            <span>{countWords(content)} palavras</span>
            <span>v{current.version}</span>
            {current.editedByUser && <span className="text-leaf-dark">editado por você</span>}
            {current.model && current.model !== "exemplo" && <span>{current.model}</span>}
            <span suppressHydrationWarning>atualizado {formatRelative(current.updatedAt)}</span>
            {current.gaps.length > 0 && (
              <span className="text-accent-ink">
                {current.gaps.length} {current.gaps.length === 1 ? "lacuna" : "lacunas"} em aberto
              </span>
            )}
          </div>
        </div>
      )}
    </section>
  );
}
