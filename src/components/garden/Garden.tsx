"use client";

import { AnimatePresence, motion } from "motion/react";
import { useRouter } from "next/navigation";
import { useCallback, useRef, useState } from "react";
import { AgentPulse } from "@/components/agent/AgentPulse";
import { Wordmark } from "@/components/Wordmark";
import { usePolling } from "@/hooks/usePolling";
import { api } from "@/lib/api";
import type { Garden as GardenData } from "@/lib/types";
import { Basket } from "./Basket";
import { type PlanterHandle, Planter } from "./Planter";
import { Legend } from "./Legend";
import { EmptyShelf, Shelf } from "./Shelf";

function interval(data: GardenData) {
  if (data.agent.mode === "working" || data.tasks.some((t) => t.activity?.status === "running")) return 2000;
  if (data.tasks.some((t) => t.status === "ripening")) return 3500;
  return 8000;
}

function plural(n: number, one: string, many: string) {
  return `${n} ${n === 1 ? one : many}`;
}

export function Garden({ initial }: { initial: GardenData }) {
  const { data, setData, error, refresh } = usePolling<GardenData>("/api/garden", initial, interval);
  const [freshId, setFreshId] = useState<string | null>(null);
  const planter = useRef<PlanterHandle>(null);
  const router = useRouter();

  const onAgentChange = useCallback((agent: GardenData["agent"]) => setData({ ...data, agent }), [data, setData]);

  const shelf = data.tasks.filter((t) => t.status !== "harvested");
  const basket = data.tasks.filter((t) => t.status === "harvested");
  const working = shelf.filter((t) => t.activity?.status === "running").length;
  const waiting = shelf.filter((t) => t.status === "waiting" || t.status === "stalled").length;
  const ripe = shelf.filter((t) => t.status === "ripe").length;

  async function planted(id: string) {
    setFreshId(id);
    await refresh();
  }

  async function example() {
    const { id } = await api<{ id: string }>("/api/examples", { method: "POST" });
    router.push(`/t/${id}`);
  }

  async function replant(id: string) {
    await api(`/api/tasks/${id}`, { method: "PATCH", body: { action: "replant" } });
    await refresh();
  }

  return (
    <div className="mx-auto w-full max-w-[1240px] flex-1 px-4 pb-16 sm:px-8 lg:px-12">
      <header className="flex items-center justify-between gap-4 py-6 sm:py-8">
        <Wordmark />
        <AgentPulse agent={data.agent} onChange={onAgentChange} />
      </header>

      <AnimatePresence>
        {error && (
          <motion.p
            initial={{ opacity: 0, y: -6 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0 }}
            role="status"
            className="mb-4 rounded-full border border-line bg-surface px-4 py-2 text-center text-[13px] text-ink-2"
          >
            {error} Tentando de novo…
          </motion.p>
        )}
      </AnimatePresence>

      <section
        className="grid gap-12 pt-6 sm:pt-12 lg:grid-cols-[minmax(0,1fr)_260px] lg:gap-16"
        aria-labelledby="hero"
      >
        <div>
          <motion.h1
            id="hero"
            initial={{ opacity: 0, y: 14 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.9, ease: [0.22, 1, 0.36, 1] }}
            className="max-w-4xl font-display text-[2.6rem] leading-[1.02] tracking-[-0.025em] text-ink sm:text-6xl lg:text-[4.6rem]"
          >
            O que você está <br className="hidden sm:block" />
            <span className="font-display-wonk italic text-ink-2">deixando para depois?</span>
          </motion.h1>
          <motion.p
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ delay: 0.35, duration: 0.8 }}
            className="mt-5 max-w-xl text-[16px] leading-relaxed text-ink-2 sm:text-[17px]"
          >
            Plante aqui. Um modelo aberto, rodando na sua máquina, trabalha nisso devagar enquanto você descansa — e
            quando você voltar, tem um plano, um rascunho ou uma primeira versão esperando.
          </motion.p>
          <motion.div
            initial={{ opacity: 0, y: 10 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.5, duration: 0.8 }}
            className="mt-10 max-w-3xl"
          >
            <Planter ref={planter} onPlanted={planted} />
          </motion.div>
        </div>
        <Legend />
      </section>

      <section className="mt-20 sm:mt-24" aria-labelledby="shelf-title">
        <div className="mb-8 flex flex-wrap items-baseline justify-between gap-x-6 gap-y-2">
          <h2 id="shelf-title" className="label">
            no parapeito{shelf.length ? ` · ${plural(shelf.length, "planta", "plantas")}` : ""}
          </h2>
          {shelf.length > 0 && (
            <p className="text-[13px] text-ink-3">
              {[
                working ? plural(working, "trabalhando agora", "trabalhando agora") : null,
                waiting ? plural(waiting, "precisa de você", "precisam de você") : null,
                ripe ? plural(ripe, "madura", "maduras") : null,
              ]
                .filter(Boolean)
                .join(" · ") || "tudo calmo"}
            </p>
          )}
        </div>
        {shelf.length ? (
          <Shelf tasks={shelf} agent={data.agent} freshId={freshId} />
        ) : (
          <EmptyShelf onSuggest={(text) => planter.current?.fill(text)} onExample={example} />
        )}
      </section>

      <Basket tasks={basket} onReplant={replant} />

      <footer className="mt-24 flex flex-wrap items-center justify-between gap-3 border-t border-dashed border-line-2 pt-6 text-[12.5px] text-ink-3">
        <p>
          Ripe roda localmente com <span className="font-mono">{data.agent.settings.model}</span> via Ollama. Seus
          textos não saem desta máquina.
        </p>
        <p className="font-display italic">devagar e sempre.</p>
      </footer>
    </div>
  );
}
