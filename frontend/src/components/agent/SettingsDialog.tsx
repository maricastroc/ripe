"use client";

import * as Dialog from "@radix-ui/react-dialog";
import { X } from "lucide-react";
import { AnimatePresence, motion } from "motion/react";
import { useState } from "react";
import { api } from "@/lib/api";
import { useI18n } from "@/lib/i18n/context";
import { LIMITS, PACES } from "@/lib/limits";
import type { AgentStatus, Pace } from "@/lib/types";

type Patch = { pace?: Pace; paused?: boolean; model?: string };

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  agent: AgentStatus;
  onChange: (next: AgentStatus) => void;
};

function Command({ children }: { children: string }) {
  return (
    <code className="mt-2 block select-all rounded-md border border-line bg-paper px-3 py-2 font-mono text-[13px] text-ink">
      {children}
    </code>
  );
}

export function SettingsDialog({ open, onOpenChange, agent, onChange }: Props) {
  const { t } = useI18n();
  const st = t.settings;
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [customModel, setCustomModel] = useState("");

  async function save(patch: Patch) {
    setSaving(true);
    setError(null);
    try {
      onChange(await api<AgentStatus>("/api/agent", { method: "PUT", body: patch }));
    } catch (err) {
      setError(err instanceof Error ? err.message : st.failed);
    } finally {
      setSaving(false);
    }
  }

  const settings = agent.settings;
  if (!settings) return null;
  const models = agent.models.includes(agent.model) ? agent.models : [agent.model, ...agent.models];

  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <AnimatePresence>
        {open && (
          <Dialog.Portal forceMount>
            <Dialog.Overlay asChild forceMount>
              <motion.div
                className="fixed inset-0 z-50 bg-[rgb(var(--shadow-rgb)/0.28)] backdrop-blur-[2px]"
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                exit={{ opacity: 0 }}
              />
            </Dialog.Overlay>
            <Dialog.Content asChild forceMount>
              <motion.div
                className="paper fixed inset-x-0 bottom-0 z-50 max-h-[92dvh] overflow-y-auto rounded-t-3xl border border-line p-6 shadow-2xl sm:inset-auto sm:left-1/2 sm:top-1/2 sm:w-[min(560px,calc(100vw-2rem))] sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-3xl sm:p-8"
                initial={{ opacity: 0, y: 24 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0, y: 16 }}
                transition={{ type: "spring", stiffness: 260, damping: 28 }}
              >
                <div className="flex items-start justify-between gap-6">
                  <div>
                    <p className="label">{st.kicker}</p>
                    <Dialog.Title className="mt-1 font-display text-3xl tracking-tight">{st.title}</Dialog.Title>
                  </div>
                  <Dialog.Close
                    className="focus-ring -mr-2 flex h-11 w-11 items-center justify-center rounded-full text-ink-3 hover:bg-paper-2 hover:text-ink"
                    aria-label={st.close}
                  >
                    <X className="h-5 w-5" strokeWidth={1.75} aria-hidden />
                  </Dialog.Close>
                </div>
                <Dialog.Description className="mt-3 text-[15px] leading-relaxed text-ink-2">
                  {agent.driver === "fake" ? st.fake : st.ollama}
                </Dialog.Description>

                {agent.mode === "offline" && (
                  <div className="mt-5 rounded-2xl border border-accent/40 bg-accent-soft p-4 text-sm text-ink">
                    <p className="font-medium">{st.offline.title}</p>
                    <p className="mt-1 text-ink-2">
                      {st.offline.text} <span className="font-mono text-[13px]">.env</span>.
                    </p>
                    <Command>ollama serve</Command>
                  </div>
                )}
                {agent.mode === "stopped" && (
                  <div className="mt-5 rounded-2xl border border-accent/40 bg-accent-soft p-4 text-sm text-ink">
                    <p className="font-medium">{st.stopped.title}</p>
                    <p className="mt-1 text-ink-2">{st.stopped.text}</p>
                    <Command>composer dev</Command>
                  </div>
                )}
                {agent.mode === "model-missing" && (
                  <div className="mt-5 rounded-2xl border border-accent/40 bg-accent-soft p-4 text-sm text-ink">
                    <p className="font-medium">{st.missing.title(agent.model)}</p>
                    <p className="mt-1 text-ink-2">{st.missing.text}</p>
                    <Command>{`ollama pull ${agent.model}`}</Command>
                  </div>
                )}

                <section className="mt-7">
                  <h3 className="label">{st.model}</h3>
                  {agent.online && agent.models.length === 0 && (
                    <p className="mt-2 text-sm text-ink-2">{st.noModels}</p>
                  )}
                  <div className="mt-3 flex flex-wrap gap-2">
                    {models.map((m) => {
                      const active = m === agent.model;
                      const installed = agent.models.includes(m);
                      return (
                        <button
                          key={m}
                          type="button"
                          disabled={saving}
                          onClick={() => save({ model: m })}
                          className={`focus-ring rounded-full border px-3.5 py-1.5 font-mono text-[13px] transition-colors ${
                            active
                              ? "border-ink bg-ink text-paper"
                              : "border-line bg-paper text-ink-2 hover:border-line-2 hover:text-ink"
                          }`}
                          aria-pressed={active}
                        >
                          {m}
                          {!installed && <span className="ml-1.5 opacity-60">· {st.notInstalled}</span>}
                        </button>
                      );
                    })}
                  </div>
                  <form
                    className="mt-3 flex gap-2"
                    onSubmit={(e) => {
                      e.preventDefault();
                      if (customModel.trim()) void save({ model: customModel.trim() });
                      setCustomModel("");
                    }}
                  >
                    <input
                      value={customModel}
                      onChange={(e) => setCustomModel(e.target.value)}
                      placeholder={st.otherPlaceholder}
                      className="focus-ring min-w-0 flex-1 rounded-full border border-line bg-paper px-4 py-2 font-mono text-[13px] placeholder:text-ink-3"
                      aria-label={st.otherLabel}
                    />
                    <button
                      type="submit"
                      className="focus-ring rounded-full border border-line px-4 text-sm text-ink-2 hover:text-ink"
                    >
                      {st.use}
                    </button>
                  </form>
                </section>

                <section className="mt-7">
                  <h3 className="label">{st.pace}</h3>
                  <div className="mt-3 grid gap-2">
                    {PACES.map((p) => {
                      const active = p === settings.pace;
                      return (
                        <button
                          key={p}
                          type="button"
                          disabled={saving}
                          onClick={() => save({ pace: p })}
                          className={`focus-ring flex items-center gap-3 rounded-2xl border px-4 py-3 text-left text-sm transition-colors ${
                            active ? "border-leaf bg-leaf/10 text-ink" : "border-line text-ink-2 hover:border-line-2"
                          }`}
                          aria-pressed={active}
                        >
                          <span
                            className={`h-2.5 w-2.5 rounded-full border ${active ? "border-leaf bg-leaf" : "border-line-2"}`}
                          />
                          {t.pace[p]}
                        </button>
                      );
                    })}
                  </div>
                </section>

                <section className="mt-7 flex items-center justify-between gap-4 rounded-2xl border border-line p-4">
                  <div>
                    <p className="text-sm font-medium">{settings.paused ? st.paused : st.active}</p>
                    <p className="mt-0.5 text-[13px] text-ink-2">{settings.paused ? st.pausedText : st.activeText}</p>
                  </div>
                  <button
                    type="button"
                    role="switch"
                    aria-checked={!settings.paused}
                    aria-label={st.toggle}
                    disabled={saving}
                    onClick={() => save({ paused: !settings.paused })}
                    className={`focus-ring relative h-7 w-12 shrink-0 rounded-full transition-colors ${settings.paused ? "bg-line-2" : "bg-leaf"}`}
                  >
                    <motion.span
                      className="absolute top-1 h-5 w-5 rounded-full bg-surface shadow"
                      animate={{ left: settings.paused ? 4 : 24 }}
                      transition={{ type: "spring", stiffness: 500, damping: 30 }}
                    />
                  </button>
                </section>

                <section className="mt-7 border-t border-dashed border-line-2 pt-5">
                  <h3 className="label">{st.limits.heading}</h3>
                  <ul className="mt-3 grid grid-cols-1 gap-x-6 gap-y-1.5 text-[13px] text-ink-2 sm:grid-cols-2">
                    <li>{st.limits.oneAtATime}</li>
                    <li>{st.limits.steps(LIMITS.maxSteps)}</li>
                    <li>{st.limits.attempts(LIMITS.maxAttempts)}</li>
                    <li>{st.limits.resumes}</li>
                  </ul>
                </section>

                {error && <p className="mt-4 text-sm text-accent-ink">{error}</p>}
              </motion.div>
            </Dialog.Content>
          </Dialog.Portal>
        )}
      </AnimatePresence>
    </Dialog.Root>
  );
}
