"use client";

import * as Dialog from "@radix-ui/react-dialog";
import { AnimatePresence, motion } from "motion/react";
import { useState } from "react";
import { api } from "@/lib/api";
import { LIMITS, PACE_LABEL } from "@/lib/limits";
import type { AgentStatus, Pace, Settings } from "@/lib/types";

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
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [customModel, setCustomModel] = useState("");
  const [baseUrl, setBaseUrl] = useState(agent.settings.baseUrl);

  async function save(patch: Partial<Settings>) {
    setSaving(true);
    setError(null);
    try {
      onChange(await api<AgentStatus>("/api/agent", { method: "PUT", body: patch }));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Não foi possível salvar");
    } finally {
      setSaving(false);
    }
  }

  const { settings } = agent;
  const models = agent.models.includes(settings.model) ? agent.models : [settings.model, ...agent.models];

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
                className="fixed inset-x-0 bottom-0 z-50 max-h-[92dvh] overflow-y-auto rounded-t-3xl border border-line bg-surface p-6 shadow-2xl sm:inset-auto sm:left-1/2 sm:top-1/2 sm:w-[min(560px,calc(100vw-2rem))] sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-3xl sm:p-8"
                initial={{ opacity: 0, y: 24 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0, y: 16 }}
                transition={{ type: "spring", stiffness: 260, damping: 28 }}
              >
                <div className="flex items-start justify-between gap-6">
                  <div>
                    <p className="label">agente local</p>
                    <Dialog.Title className="mt-1 font-display text-3xl tracking-tight">
                      Quem cuida do jardim
                    </Dialog.Title>
                  </div>
                  <Dialog.Close
                    className="focus-ring -mr-2 rounded-full p-2 text-ink-3 hover:text-ink"
                    aria-label="Fechar"
                  >
                    <svg viewBox="0 0 20 20" className="h-5 w-5" aria-hidden>
                      <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
                    </svg>
                  </Dialog.Close>
                </div>
                <Dialog.Description className="mt-3 text-[15px] leading-relaxed text-ink-2">
                  Um modelo open-weight rodando no Ollama, nesta máquina. Ele faz uma etapa pequena por vez, salva o
                  resultado e descansa antes da próxima. Nenhum texto sai do seu computador.
                </Dialog.Description>

                {(agent.mode === "offline" || agent.mode === "stopped") && (
                  <div className="mt-5 rounded-2xl border border-accent/40 bg-accent-soft p-4 text-sm text-ink">
                    <p className="font-medium">O Ollama não está respondendo em {settings.baseUrl}.</p>
                    <p className="mt-1 text-ink-2">
                      As tarefas ficam guardadas na fila. Assim que ele voltar, o trabalho continua sozinho.
                    </p>
                    <Command>ollama serve</Command>
                  </div>
                )}
                {agent.mode === "model-missing" && (
                  <div className="mt-5 rounded-2xl border border-accent/40 bg-accent-soft p-4 text-sm text-ink">
                    <p className="font-medium">O modelo {settings.model} não está instalado.</p>
                    <p className="mt-1 text-ink-2">Escolha um dos modelos abaixo ou baixe este:</p>
                    <Command>{`ollama pull ${settings.model}`}</Command>
                  </div>
                )}

                <section className="mt-7">
                  <h3 className="label">modelo</h3>
                  {agent.online && agent.models.length === 0 && (
                    <p className="mt-2 text-sm text-ink-2">Nenhum modelo instalado ainda.</p>
                  )}
                  <div className="mt-3 flex flex-wrap gap-2">
                    {models.map((m) => {
                      const active = m === settings.model;
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
                          {!installed && <span className="ml-1.5 opacity-60">· ausente</span>}
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
                      placeholder="outro modelo, ex.: qwen3:8b"
                      className="focus-ring min-w-0 flex-1 rounded-full border border-line bg-paper px-4 py-2 font-mono text-[13px] placeholder:text-ink-3"
                      aria-label="Nome de outro modelo"
                    />
                    <button
                      type="submit"
                      className="focus-ring rounded-full border border-line px-4 text-sm text-ink-2 hover:text-ink"
                    >
                      usar
                    </button>
                  </form>
                </section>

                <section className="mt-7">
                  <h3 className="label">ritmo</h3>
                  <div className="mt-3 grid gap-2">
                    {(Object.keys(PACE_LABEL) as Pace[]).map((p) => {
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
                          {PACE_LABEL[p]}
                        </button>
                      );
                    })}
                  </div>
                </section>

                <section className="mt-7">
                  <h3 className="label">endereço do ollama</h3>
                  <form
                    className="mt-3 flex gap-2"
                    onSubmit={(e) => {
                      e.preventDefault();
                      void save({ baseUrl: baseUrl.trim() });
                    }}
                  >
                    <input
                      value={baseUrl}
                      onChange={(e) => setBaseUrl(e.target.value)}
                      className="focus-ring min-w-0 flex-1 rounded-full border border-line bg-paper px-4 py-2 font-mono text-[13px]"
                      aria-label="Endereço do Ollama"
                    />
                    <button
                      type="submit"
                      className="focus-ring rounded-full border border-line px-4 text-sm text-ink-2 hover:text-ink"
                    >
                      salvar
                    </button>
                  </form>
                </section>

                <section className="mt-7 flex items-center justify-between gap-4 rounded-2xl border border-line p-4">
                  <div>
                    <p className="text-sm font-medium">{settings.paused ? "Trabalho pausado" : "Trabalho ativo"}</p>
                    <p className="mt-0.5 text-[13px] text-ink-2">
                      {settings.paused
                        ? "Nada roda até você retomar."
                        : "O agente pega a próxima etapa quando estiver livre."}
                    </p>
                  </div>
                  <button
                    type="button"
                    role="switch"
                    aria-checked={!settings.paused}
                    aria-label="Ativar ou pausar o agente"
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
                  <h3 className="label">limites de execução</h3>
                  <ul className="mt-3 grid grid-cols-1 gap-x-6 gap-y-1.5 text-[13px] text-ink-2 sm:grid-cols-2">
                    <li>uma etapa por vez, em fila</li>
                    <li>até {LIMITS.maxSteps} etapas por tarefa</li>
                    <li>{LIMITS.maxAttempts} tentativas por etapa</li>
                    <li>{Math.round(LIMITS.callTimeoutMs / 60000)} min no máximo por chamada</li>
                    <li>{LIMITS.jobBudget} execuções por tarefa</li>
                    <li>retoma sozinho após interrupções</li>
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
