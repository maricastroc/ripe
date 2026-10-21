"use client";

import { useState } from "react";
import type { AgentMode, AgentStatus } from "@/lib/types";
import { SettingsDialog } from "./SettingsDialog";

const MODE: Record<AgentMode, { text: string; tone: string; live?: boolean }> = {
  starting: { text: "acordando", tone: "text-ink-3" },
  idle: { text: "em repouso", tone: "text-leaf" },
  working: { text: "trabalhando", tone: "text-leaf", live: true },
  resting: { text: "respirando entre etapas", tone: "text-leaf-2", live: true },
  offline: { text: "Ollama desligado", tone: "text-accent" },
  "model-missing": { text: "modelo não instalado", tone: "text-accent" },
  paused: { text: "agente pausado", tone: "text-ochre" },
  stopped: { text: "agente parado", tone: "text-accent" },
};

export function agentModeText(agent: AgentStatus) {
  if (agent.mode === "idle" && agent.queued > 0) return "na fila";
  return MODE[agent.mode].text;
}

export function AgentPulse({ agent, onChange }: { agent: AgentStatus; onChange: (next: AgentStatus) => void }) {
  const [open, setOpen] = useState(false);
  const mode = MODE[agent.mode];
  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="focus-ring group flex max-w-[70vw] items-center gap-2.5 rounded-full border border-line bg-surface/70 py-1.5 pl-3 pr-3.5 text-left backdrop-blur-sm transition-colors hover:border-line-2 sm:max-w-md"
        aria-label={`Agente: ${agentModeText(agent)}. Abrir configurações do agente`}
      >
        <span
          className={`relative inline-flex h-2 w-2 shrink-0 rounded-full bg-current ${mode.tone} ${mode.live ? "dot-live" : ""}`}
        />
        <span className="min-w-0 truncate text-[13px] text-ink-2">
          <span className="text-ink">{agentModeText(agent)}</span>
          {agent.current && (
            <span className="hidden sm:inline">
              {" · "}
              {agent.current.label.charAt(0).toLowerCase() + agent.current.label.slice(1)}
            </span>
          )}
        </span>
        <span className="hidden shrink-0 font-mono text-[11px] text-ink-3 md:inline">{agent.settings.model}</span>
        <svg
          viewBox="0 0 20 20"
          className="h-3.5 w-3.5 shrink-0 text-ink-3 transition-transform duration-500 group-hover:rotate-90"
          aria-hidden
        >
          <path
            fill="currentColor"
            d="M10 6.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7Zm0 5.5a2 2 0 1 1 0-4 2 2 0 0 1 0 4Zm7.2-3.1-1.5-.3a5.9 5.9 0 0 0-.6-1.4l.9-1.3a.8.8 0 0 0-.1-1l-1.3-1.3a.8.8 0 0 0-1-.1l-1.3.9a5.9 5.9 0 0 0-1.4-.6l-.3-1.5a.8.8 0 0 0-.8-.6H8.2a.8.8 0 0 0-.8.6l-.3 1.5a5.9 5.9 0 0 0-1.4.6l-1.3-.9a.8.8 0 0 0-1 .1L2.1 4.4a.8.8 0 0 0-.1 1l.9 1.3a5.9 5.9 0 0 0-.6 1.4l-1.5.3a.8.8 0 0 0-.6.8v1.6c0 .4.3.7.6.8l1.5.3c.1.5.3 1 .6 1.4l-.9 1.3a.8.8 0 0 0 .1 1l1.3 1.3c.3.3.7.3 1 .1l1.3-.9c.4.3.9.5 1.4.6l.3 1.5c.1.4.4.6.8.6h1.6c.4 0 .7-.3.8-.6l.3-1.5c.5-.1 1-.3 1.4-.6l1.3.9c.3.2.7.2 1-.1l1.3-1.3c.3-.3.3-.7.1-1l-.9-1.3c.3-.4.5-.9.6-1.4l1.5-.3c.4-.1.6-.4.6-.8V9.7a.8.8 0 0 0-.6-.8Z"
          />
        </svg>
      </button>
      <SettingsDialog open={open} onOpenChange={setOpen} agent={agent} onChange={onChange} />
    </>
  );
}
