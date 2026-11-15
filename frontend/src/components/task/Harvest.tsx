"use client";

import { Check, Copy, Download, PanelLeftClose, PanelLeftOpen, PenLine, Tag, Undo2 } from "lucide-react";
import { AnimatePresence, motion } from "motion/react";
import { useEffect, useMemo, useRef, useState } from "react";
import { Markdown } from "@/components/Markdown";
import { type DraftConflict, type DraftState, useDraft } from "@/hooks/useDraft";
import { formatRelative } from "@/lib/format";
import { countWords } from "@/lib/markers";
import type { Activity, ArtifactView, StepView, TaskDetail } from "@/lib/types";

type Props = {
  artifacts: ArtifactView[];
  steps: StepView[];
  activity: Activity | null;
  selected: string | null;
  onSelect: (id: string) => void;
  onHover?: (id: string | null) => void;
  wide?: boolean;
  onToggleWide?: () => void;
  readOnly: boolean;
  onRevise: (id: string, instruction: string) => Promise<void>;
  onRestore: (id: string, baseVersionId: string) => Promise<void>;
  onTaskChange: (task: TaskDetail) => void;
  reload: () => Promise<TaskDetail | null>;
};

const tool =
  "focus-ring inline-flex min-h-9 items-center gap-1.5 rounded-full px-2.5 text-ink-2 transition-colors hover:bg-paper-2 hover:text-ink";

const QUICK = ["shorter and more direct", "more formal tone", "more detailed", "as bullet points", "warmer"];

function slug(text: string) {
  return text
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "")
    .slice(0, 60);
}

function Editor({
  artifact,
  value,
  onChange,
}: {
  artifact: ArtifactView;
  value: string;
  onChange: (next: string) => void;
}) {
  const area = useRef<HTMLTextAreaElement>(null);

  useEffect(() => {
    const el = area.current;
    if (!el) return;
    el.style.height = "auto";
    el.style.height = `${Math.max(320, el.scrollHeight)}px`;
  }, [value]);

  return (
    <div>
      <label htmlFor={`edit-${artifact.id}`} className="sr-only">
        Edit {artifact.title}
      </label>
      <textarea
        id={`edit-${artifact.id}`}
        ref={area}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        spellCheck
        className="block w-full resize-none bg-transparent font-mono text-[14px] leading-[1.75] text-ink outline-none"
      />
    </div>
  );
}

const SAVE_LABEL: Record<DraftState, string> = {
  idle: "markdown · saves as you type",
  saving: "saving…",
  saved: "draft saved · kept as a version when you stop",
  kept: "kept as a new version",
  error: "not saved yet · it tries again as you type",
};

function ConflictNotice({
  conflict,
  onKeepMine,
  onTakeTheirs,
}: {
  conflict: NonNullable<DraftConflict>;
  onKeepMine: () => void;
  onTakeTheirs: () => void;
}) {
  const overtaken = conflict.kind === "overtaken";
  return (
    <div role="alert" className="border-b border-line bg-accent-soft px-5 py-4 text-[14px] text-ink sm:px-8">
      <p className="font-medium">
        {overtaken
          ? "Ripe wrote a new version of this piece while you were editing."
          : "This piece was edited in another tab or window."}
      </p>
      <p className="mt-1 text-ink-2">Your edits are safe as a draft. Choose which one to go on with.</p>
      <div className="mt-3 flex flex-wrap gap-2">
        <button
          type="button"
          onClick={onKeepMine}
          className="focus-ring rounded-full bg-ink px-4 py-1.5 text-[13px] font-medium text-paper"
        >
          keep my edits
        </button>
        <button
          type="button"
          onClick={onTakeTheirs}
          className="focus-ring rounded-full border border-line px-4 py-1.5 text-[13px] text-ink-2 hover:text-ink"
        >
          {overtaken ? "use the new version" : "load the other edits"}
        </button>
      </div>
    </div>
  );
}

export function Harvest({
  artifacts,
  steps,
  activity,
  selected,
  onSelect,
  onHover,
  wide,
  onToggleWide,
  readOnly,
  onRevise,
  onRestore,
  onTaskChange,
  reload,
}: Props) {
  const [chosenMode, setMode] = useState<"read" | "edit">("read");
  const [revising, setRevising] = useState(false);
  const [instruction, setInstruction] = useState("");
  const [sending, setSending] = useState(false);
  const [copied, setCopied] = useState(false);
  const current = artifacts.find((a) => a.id === selected) ?? artifacts[artifacts.length - 1] ?? null;
  const stepNumber = useMemo(() => new Map(steps.map((s) => [s.id, s.position + 1])), [steps]);
  const busyHere = activity?.kind === "revise" && activity.artifactId === current?.id;
  const draft = useDraft(current, onTaskChange, reload);
  const keepRef = useRef(draft.keep);

  useEffect(() => {
    keepRef.current = draft.keep;
  }, [draft.keep]);

  useEffect(() => () => void keepRef.current(), []);

  const mode = busyHere ? "read" : chosenMode;
  const content = current ? (mode === "edit" ? draft.value : current.content) : "";

  async function leaveEditing() {
    setMode("read");
    await draft.keep();
  }

  if (!artifacts.length) {
    const next = steps.find((s) => s.phase === "working") ?? steps.find((s) => s.phase === "ready");
    return (
      <section id="harvest" aria-labelledby="harvest-title">
        <h2 id="harvest-title" className="label mb-3">
          harvest
        </h2>
        <div className="rounded-2xl border border-dashed border-line-2 px-6 py-10 text-center">
          <p className="font-display text-xl tracking-tight text-ink-2">Nothing to harvest yet.</p>
          <p className="mx-auto mt-2 max-w-md text-[14px] leading-relaxed text-ink-3">
            {next
              ? `The first piece will be “${next.title}”. It appears here when it's ready, and you can edit it however you like.`
              : "Every finished step becomes a piece here, ready to read, edit and copy."}
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
      await draft.keep();
      await onRevise(current.id, text.trim());
      setInstruction("");
      setRevising(false);
    } finally {
      setSending(false);
    }
  }

  return (
    <section id="harvest" aria-labelledby="harvest-title" className="scroll-mt-6">
      <div className="mb-4 flex items-baseline justify-between gap-4">
        <h2 id="harvest-title" className="label">
          harvest · {artifacts.length} {artifacts.length === 1 ? "piece" : "pieces"}
        </h2>
        <span className="text-[13px] text-ink-3">{readOnly ? "read only" : "all editable"}</span>
      </div>

      <div
        role="tablist"
        aria-label="Pieces"
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
              onMouseEnter={() => onHover?.(a.id)}
              onMouseLeave={() => onHover?.(null)}
              onClick={() => {
                void leaveEditing().then(() => onSelect(a.id));
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
                {n ? (
                  <span className="font-mono text-[11px] opacity-60">{n}</span>
                ) : (
                  <Tag className="h-3 w-3 opacity-70" strokeWidth={1.75} aria-hidden />
                )}
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
              aria-label="Mode"
            >
              {(["read", "edit"] as const).map((m) => (
                <button
                  key={m}
                  type="button"
                  disabled={readOnly || busyHere}
                  onClick={() => (m === "read" ? void leaveEditing() : setMode("edit"))}
                  aria-pressed={mode === m}
                  className={`focus-ring flex-1 rounded-full px-3 py-1 text-[13px] transition-colors disabled:opacity-40 sm:flex-none ${
                    mode === m ? "bg-surface text-ink shadow-sm" : "text-ink-3 hover:text-ink"
                  }`}
                >
                  {m === "read" ? "Read" : "Edit"}
                </button>
              ))}
            </div>
            <div className="flex flex-wrap items-center gap-0.5 text-[13px]">
              <button type="button" onClick={() => void copy()} className={tool}>
                {copied ? (
                  <Check className="h-3.5 w-3.5 text-leaf-dark" strokeWidth={2} aria-hidden />
                ) : (
                  <Copy className="h-3.5 w-3.5" strokeWidth={1.75} aria-hidden />
                )}
                {copied ? "copied" : "copy"}
              </button>
              <button type="button" onClick={download} className={tool}>
                <Download className="h-3.5 w-3.5" strokeWidth={1.75} aria-hidden />
                .md
              </button>
              {!readOnly && (
                <button
                  type="button"
                  disabled={busyHere}
                  onClick={() => setRevising((r) => !r)}
                  aria-expanded={revising}
                  className={`${tool} disabled:opacity-40`}
                >
                  <PenLine className="h-3.5 w-3.5" strokeWidth={1.75} aria-hidden />
                  rewrite…
                </button>
              )}
              {!readOnly && current.versions.length > 1 && current.version && !draft.hasDraft && !busyHere && (
                <button
                  type="button"
                  onClick={() => current.version && void onRestore(current.id, current.version.id)}
                  className={tool}
                >
                  <Undo2 className="h-3.5 w-3.5" strokeWidth={1.75} aria-hidden />
                  previous version
                </button>
              )}
              {onToggleWide && (
                <button
                  type="button"
                  onClick={onToggleWide}
                  aria-pressed={wide}
                  className={`${tool} max-lg:hidden`}
                  title={wide ? "Show the plate again" : "Fold the plate away and read on a wider page"}
                >
                  {wide ? (
                    <PanelLeftOpen className="h-3.5 w-3.5" strokeWidth={1.75} aria-hidden />
                  ) : (
                    <PanelLeftClose className="h-3.5 w-3.5" strokeWidth={1.75} aria-hidden />
                  )}
                  {wide ? "show plate" : "wider page"}
                </button>
              )}
            </div>
          </div>

          {draft.conflict && (
            <ConflictNotice
              conflict={draft.conflict}
              onKeepMine={() => void draft.keepMine()}
              onTakeTheirs={() => void draft.takeTheirs()}
            />
          )}

          {!draft.conflict && draft.hasDraft && mode === "read" && !readOnly && (
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line bg-paper-2/60 px-5 py-3 text-[13.5px] text-ink-2 sm:px-8">
              <span>You have edits to this piece that are not kept as a version yet.</span>
              <span className="flex gap-2">
                <button type="button" onClick={() => setMode("edit")} className={tool}>
                  go on editing
                </button>
                <button type="button" onClick={() => void draft.keep()} className={tool}>
                  keep them
                </button>
              </span>
            </div>
          )}

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
                    What should change in “{current.title}”?
                  </label>
                  <div className="mt-2 flex gap-2">
                    <input
                      id="revise-input"
                      value={instruction}
                      onChange={(e) => setInstruction(e.target.value)}
                      placeholder="e.g. cut it in half and make it more personal"
                      maxLength={500}
                      className="focus-ring min-w-0 flex-1 rounded-full border border-line bg-paper px-4 py-2 text-[14px] placeholder:text-ink-3"
                    />
                    <button
                      type="submit"
                      disabled={sending || !instruction.trim()}
                      className="focus-ring rounded-full bg-ink px-4 text-[13px] font-medium text-paper disabled:opacity-40"
                    >
                      {sending ? "…" : "ask"}
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
                  <p className="mt-3 text-[12px] text-ink-3">The current version is kept, so you can go back to it.</p>
                </div>
              </motion.form>
            )}
          </AnimatePresence>

          <div className="notebook relative py-7 pl-12 pr-5 sm:pl-14 sm:pr-10">
            <AnimatePresence mode="wait">
              <motion.div
                key={`${current.id}-${mode}-${current.version?.id ?? "none"}`}
                initial={{ opacity: 0, y: 6 }}
                animate={{ opacity: busyHere ? 0.35 : 1, y: 0 }}
                exit={{ opacity: 0 }}
                transition={{ duration: 0.3 }}
              >
                {mode === "edit" && !readOnly ? (
                  <>
                    <Editor artifact={current} value={draft.value} onChange={draft.edit} />
                    <p className="label mt-3 text-right" aria-live="polite">
                      {draft.conflict ? "edits paused until you choose" : SAVE_LABEL[draft.state]}
                    </p>
                  </>
                ) : (
                  <Markdown content={content} className="max-w-[72ch]" />
                )}
              </motion.div>
            </AnimatePresence>
            {busyHere && (
              <div className="absolute inset-0 flex items-start justify-center pt-16">
                <p className="rounded-full border border-line bg-surface px-4 py-2 text-[14px] text-ink-2 shadow-sm">
                  <span className="live-dot mr-2 inline-block h-2 w-2 rounded-full bg-leaf text-leaf" />
                  rewriting now · follow along under “now”
                </p>
              </div>
            )}
          </div>

          <div className="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-line px-5 py-3 font-mono text-[11.5px] text-ink-3 sm:px-8">
            <span>{countWords(content)} words</span>
            {current.version && <span>v{current.version.number}</span>}
            {current.editedByUser && <span className="text-leaf-dark">edited by you</span>}
            {current.model && current.model !== "example" && <span>{current.model}</span>}
            {current.updatedAt && <span suppressHydrationWarning>updated {formatRelative(current.updatedAt)}</span>}
            {current.gaps.length > 0 && (
              <span className="text-accent-ink">
                {current.gaps.length} open {current.gaps.length === 1 ? "gap" : "gaps"}
              </span>
            )}
          </div>
        </div>
      )}
    </section>
  );
}
