"use client";

import { AnimatePresence, motion } from "motion/react";
import { type Ref, useEffect, useImperativeHandle, useRef, useState } from "react";
import { api } from "@/lib/api";
import { RETURN_OPTIONS } from "@/lib/format";
import { ReturnPicker } from "./ReturnPicker";

const PLACEHOLDERS = [
  "Escrever a proposta do projeto para o edital…",
  "Preparar a apresentação de segunda-feira…",
  "Planejar a viagem de fim de ano com a família…",
  "Responder aquele e-mail difícil do cliente…",
  "Organizar as ideias para o artigo sobre…",
];

export type PlanterHandle = { fill: (text: string) => void };

type Props = { onPlanted: (id: string) => void; ref?: Ref<PlanterHandle> };

export function Planter({ onPlanted, ref }: Props) {
  const [text, setText] = useState("");
  const [returnId, setReturnId] = useState("tomorrow");
  const [pending, setPending] = useState<"defer" | "plant" | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [hint, setHint] = useState(0);
  const area = useRef<HTMLTextAreaElement>(null);

  useImperativeHandle(ref, () => ({
    fill(value: string) {
      setText(value);
      requestAnimationFrame(() => {
        area.current?.focus();
        area.current?.setSelectionRange(value.length, value.length);
      });
    },
  }));

  useEffect(() => {
    if (text) return;
    const id = setInterval(() => setHint((h) => (h + 1) % PLACEHOLDERS.length), 4200);
    return () => clearInterval(id);
  }, [text]);

  useEffect(() => {
    const el = area.current;
    if (!el) return;
    el.style.height = "auto";
    el.style.height = `${el.scrollHeight}px`;
  }, [text]);

  async function plant(defer: boolean) {
    const prompt = text.trim();
    if (prompt.length < 3 || pending) {
      if (prompt.length < 3) area.current?.focus();
      return;
    }
    setPending(defer ? "defer" : "plant");
    setError(null);
    try {
      const option = RETURN_OPTIONS.find((o) => o.id === returnId) ?? RETURN_OPTIONS[1];
      const { id } = await api<{ id: string }>("/api/tasks", {
        method: "POST",
        body: { prompt, defer, returnAt: option.at().toISOString() },
      });
      setText("");
      onPlanted(id);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Não consegui plantar agora.");
    } finally {
      setPending(null);
    }
  }

  const ready = text.trim().length >= 3;

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        void plant(true);
      }}
      className="w-full"
    >
      <label htmlFor="planter" className="sr-only">
        O que você está deixando para depois?
      </label>
      <div className="relative">
        <textarea
          id="planter"
          ref={area}
          rows={1}
          value={text}
          maxLength={2000}
          onChange={(e) => setText(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter" && !e.shiftKey && !e.nativeEvent.isComposing) {
              e.preventDefault();
              void plant(true);
            }
          }}
          className="peer block w-full resize-none overflow-hidden border-0 border-b border-line-2 bg-transparent pb-4 pt-1 font-display text-[1.6rem] leading-snug tracking-tight text-ink outline-none transition-colors focus:border-ink sm:text-[2rem]"
        />
        <span className="label pointer-events-none absolute -bottom-6 right-0 hidden opacity-0 transition-opacity duration-300 peer-focus:opacity-100 md:block">
          enter planta · shift+enter quebra linha
        </span>
        <AnimatePresence mode="wait">
          {!text && (
            <motion.span
              key={hint}
              aria-hidden
              initial={{ opacity: 0, y: 6 }}
              animate={{ opacity: 1, y: 0 }}
              exit={{ opacity: 0, y: -6 }}
              transition={{ duration: 0.5 }}
              className="pointer-events-none absolute left-0 top-1 truncate pr-4 font-display text-[1.6rem] italic leading-snug tracking-tight text-ink-3/80 sm:text-[2rem]"
              style={{ maxWidth: "100%" }}
            >
              {PLACEHOLDERS[hint]}
            </motion.span>
          )}
        </AnimatePresence>
      </div>

      <div className="mt-8 flex flex-wrap items-center gap-x-3 gap-y-3">
        <motion.button
          type="submit"
          disabled={!!pending}
          whileTap={{ scale: 0.97 }}
          className={`focus-ring group inline-flex items-center gap-2.5 rounded-full py-3 pl-4 pr-5 text-[15px] font-medium transition-colors ${
            ready ? "bg-ink text-paper hover:bg-leaf-dark" : "bg-ink/80 text-paper/90"
          }`}
        >
          <svg
            viewBox="0 0 20 20"
            className="h-4 w-4 transition-transform duration-500 group-hover:-rotate-12"
            aria-hidden
          >
            <ellipse cx="10" cy="13" rx="4.5" ry="3" transform="rotate(-20 10 13)" fill="currentColor" />
            <path
              d="M10.5 10 C 10 6, 12 3.5, 15 3"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.6"
              strokeLinecap="round"
            />
          </svg>
          {pending === "defer" ? "Plantando…" : "Deixar amadurecer"}
        </motion.button>
        <ReturnPicker value={returnId} onChange={setReturnId} />
        <button
          type="button"
          disabled={!!pending}
          onClick={() => void plant(false)}
          className="focus-ring rounded-full px-2 py-1.5 text-[14px] text-ink-3 underline-offset-4 transition-colors hover:text-ink-2 hover:underline"
        >
          {pending === "plant" ? "plantando…" : "só plantar, sem começar"}
        </button>
      </div>
      <AnimatePresence>
        {error && (
          <motion.p
            initial={{ opacity: 0, height: 0 }}
            animate={{ opacity: 1, height: "auto" }}
            exit={{ opacity: 0, height: 0 }}
            className="mt-3 text-sm text-accent-ink"
            role="alert"
          >
            {error}
          </motion.p>
        )}
      </AnimatePresence>
    </form>
  );
}
