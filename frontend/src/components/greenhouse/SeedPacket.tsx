"use client";

import { Sprout } from "lucide-react";
import { AnimatePresence, motion } from "motion/react";
import { type Ref, useEffect, useImperativeHandle, useRef, useState } from "react";
import { ReturnPicker } from "@/components/greenhouse/ReturnPicker";
import { api } from "@/lib/api";
import type { TaskDetail } from "@/lib/types";
import { RETURN_OPTIONS, type ReturnId } from "@/lib/format";
import { useI18n } from "@/lib/i18n/context";
import { useTurnstile } from "@/lib/turnstile";
import { catalog } from "./PlantPlot";

export type PacketHandle = { fill: (text: string) => void };

type Props = {
  onPlanted: (id: string) => void;
  nextNumber: number;
  ref?: Ref<PacketHandle>;
  turnstile?: string | null;
  className?: string;
  style?: React.CSSProperties;
};

export function SeedPacket({ onPlanted, nextNumber, ref, turnstile = null, className, style }: Props) {
  const { t } = useI18n();
  const p = t.packet;
  const [text, setText] = useState("");
  const [returnId, setReturnId] = useState<ReturnId>("tomorrow");
  const [pending, setPending] = useState<"defer" | "plant" | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [hint, setHint] = useState(0);
  const [dropping, setDropping] = useState(0);
  const area = useRef<HTMLTextAreaElement>(null);
  const { element: proofSlot, token: proveVisitor } = useTurnstile(turnstile);

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
    const id = setInterval(() => setHint((h) => (h + 1) % p.hints.length), 4200);
    return () => clearInterval(id);
  }, [text, p.hints.length]);

  useEffect(() => {
    const el = area.current;
    if (!el) return;
    el.style.height = "auto";
    el.style.height = `${Math.min(el.scrollHeight, 150)}px`;
  }, [text]);

  async function sow(defer: boolean) {
    const prompt = text.trim();
    if (prompt.length < 3 || pending) {
      if (prompt.length < 3) area.current?.focus();
      return;
    }
    setPending(defer ? "defer" : "plant");
    setError(null);
    try {
      const option = RETURN_OPTIONS.find((o) => o.id === returnId) ?? RETURN_OPTIONS[1];
      const proof = await proveVisitor();
      const { task } = await api<{ task: TaskDetail }>("/api/tasks", {
        method: "POST",
        body: { prompt, defer, returnAt: option.at().toISOString(), ...(proof ? { turnstile: proof } : {}) },
      });
      setText("");
      setDropping((d) => d + 1);
      setTimeout(() => onPlanted(task.id), 520);
    } catch (err) {
      setError(err instanceof Error ? err.message : p.failed);
    } finally {
      setPending(null);
    }
  }

  return (
    <div
      className={`relative origin-top rotate-[1.6deg] drop-shadow-[0_8px_12px_rgb(40_30_18/0.3)] ${className ?? ""}`}
      style={style}
    >
      <svg
        viewBox="0 0 20 20"
        className="pointer-events-none absolute left-1/2 top-[6px] z-10 h-[18px] w-[18px] -translate-x-1/2"
        aria-hidden
      >
        <circle cx={10} cy={10} r={6.5} fill="var(--ochre)" stroke="var(--ink)" strokeWidth={1} />
        <circle cx={8} cy={8} r={2} fill="var(--butter)" />
      </svg>
      <AnimatePresence>
        {dropping > 0 && (
          <motion.svg
            key={dropping}
            viewBox="-8 -6 16 12"
            className="pointer-events-none absolute left-1/2 z-30 h-4 w-5"
            initial={{ bottom: 20, opacity: 1, rotate: 0 }}
            animate={{ bottom: -46, opacity: [1, 1, 0], rotate: 160 }}
            transition={{ duration: 0.6, ease: [0.55, 0, 0.8, 0.3] }}
            aria-hidden
          >
            <ellipse rx={6.5} ry={4.2} fill="#8a6646" stroke="#3a2a1c" strokeWidth={0.8} />
          </motion.svg>
        )}
      </AnimatePresence>
      <form
        onSubmit={(e) => {
          e.preventDefault();
          void sow(true);
        }}
        className="kraft packet-shadow relative flex flex-col rounded-[3px] px-5 pb-5 pt-9 sm:px-6"
        style={{ clipPath: "polygon(0 14px, 50% 0, 100% 14px, 100% 100%, 0 100%)" }}
      >
        <div className="pointer-events-none absolute inset-x-0 top-[13px] h-px bg-[#8b6a43]/30" />
        <div className="flex items-start justify-between gap-3">
          <div>
            <p className="typewriter text-[10.5px] uppercase tracking-[0.18em] text-[#4a3522]">{p.brand}</p>
            <p className="typewriter text-[10.5px] tracking-[0.1em] text-[#5d4430]">
              {catalog(nextNumber)} · {p.sowAnytime}
            </p>
          </div>
          <svg viewBox="0 0 54 54" className="-mt-1 h-11 w-11 shrink-0 -rotate-6 opacity-55" aria-hidden>
            <circle
              cx={27}
              cy={27}
              r={24}
              fill="none"
              stroke="#7a2e1c"
              strokeWidth={1.4}
              strokeDasharray="3 2"
              opacity={0.75}
            />
            <circle cx={27} cy={27} r={19} fill="none" stroke="#7a2e1c" strokeWidth={0.8} opacity={0.6} />
            <ellipse cx={27} cy={31} rx={7} ry={4.6} fill="#7a2e1c" opacity={0.8} />
            <path d="M 28 27 C 27 21, 30 17, 35 15" stroke="#7a2e1c" strokeWidth={1.4} fill="none" opacity={0.8} />
            <path d="M 31 19 C 34 15, 39 15, 41 17 C 38 20, 34 21, 31 19 Z" fill="#7a2e1c" opacity={0.8} />
          </svg>
        </div>

        <label
          htmlFor="packet"
          className="mt-3 block font-display text-[1.28rem] leading-[1.12] tracking-tight text-[#35281b] font-display-wonk italic"
        >
          {p.question}
        </label>

        <div className="relative mt-3 rounded-[2px] bg-[#f7efdd]/85 px-3 py-2 shadow-[inset_0_1px_2px_rgba(60,40,20,0.14)]">
          <textarea
            id="packet"
            ref={area}
            rows={2}
            value={text}
            maxLength={2000}
            onChange={(e) => setText(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter" && !e.shiftKey && !e.nativeEvent.isComposing) {
                e.preventDefault();
                void sow(true);
              }
            }}
            className="ruled block w-full resize-none bg-transparent font-display text-[16.5px] leading-[1.75em] text-ink outline-none"
          />
          <AnimatePresence mode="wait">
            {!text && (
              <motion.span
                key={hint}
                aria-hidden
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                exit={{ opacity: 0 }}
                transition={{ duration: 0.4 }}
                className="pointer-events-none absolute inset-x-3 top-2 line-clamp-2 font-display text-[16.5px] italic leading-[1.75em] text-ink-3"
              >
                {p.hints[hint % p.hints.length]}
              </motion.span>
            )}
          </AnimatePresence>
        </div>

        <div className="-ml-2 mt-3 flex items-center text-[#3d2c1c]">
          <ReturnPicker value={returnId} onChange={setReturnId} />
        </div>

        <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2">
          <motion.button
            type="submit"
            disabled={!!pending}
            whileTap={{ scale: 0.97 }}
            className="focus-ring inline-flex min-h-11 items-center gap-2 rounded-full bg-[#3d4b33] px-4 text-[14px] font-medium text-[#f6efdc] transition-colors hover:bg-[#2e3b27]"
          >
            <Sprout className="h-4 w-4" strokeWidth={1.75} aria-hidden />
            {pending === "defer" ? p.sowing : p.letRipen}
          </motion.button>
          <button
            type="button"
            disabled={!!pending}
            onClick={() => void sow(false)}
            className="focus-ring inline-flex min-h-11 items-center rounded-full px-1 text-[12.5px] text-[#4a3826] underline decoration-dotted underline-offset-4 hover:text-[#2b1f14]"
          >
            {pending === "plant" ? p.planting : p.justPlant}
          </button>
        </div>
        <div ref={proofSlot} className="mt-2 empty:hidden" />
        <AnimatePresence>
          {error && (
            <motion.p
              initial={{ opacity: 0, height: 0 }}
              animate={{ opacity: 1, height: "auto" }}
              exit={{ opacity: 0, height: 0 }}
              className="mt-2 text-[13px] text-vermilion-ink"
              role="alert"
            >
              {error}
            </motion.p>
          )}
        </AnimatePresence>
      </form>
    </div>
  );
}
