"use client";

import * as Dialog from "@radix-ui/react-dialog";
import { Apple, Pause, RotateCcw, Sprout, Trash2, Undo2 } from "lucide-react";
import { useState } from "react";
import { ReturnPicker } from "@/components/greenhouse/ReturnPicker";
import { RETURN_OPTIONS, type ReturnId } from "@/lib/format";
import { useI18n } from "@/lib/i18n/context";
import type { TaskDetail } from "@/lib/types";

type Props = {
  task: TaskDetail;
  busy: boolean;
  onDefer: (returnAt: string) => void;
  onPause: () => void;
  onRetry: () => void;
  onHarvest: () => void;
  onReplant: () => void;
  onDelete: () => void;
};

const primary =
  "focus-ring inline-flex min-h-11 items-center justify-center gap-2 rounded-full bg-ink px-5 py-2.5 text-[14px] font-medium text-paper transition-colors hover:bg-leaf-dark disabled:opacity-50";
const secondary =
  "focus-ring inline-flex min-h-11 items-center justify-center gap-1.5 rounded-full border border-line-2 px-4 py-2 text-[14px] text-ink-2 transition-colors hover:border-ink hover:text-ink disabled:opacity-50";

export function Actions({ task, busy, onDefer, onPause, onRetry, onHarvest, onReplant, onDelete }: Props) {
  const a = useI18n().t.actions;
  const [returnId, setReturnId] = useState<ReturnId>("tomorrow");
  const [confirm, setConfirm] = useState(false);
  const s = task.status;

  return (
    <div className="flex flex-wrap items-center gap-x-2 gap-y-3">
      {!task.isExample && s === "dormant" && (
        <>
          <button
            type="button"
            disabled={busy}
            className={primary}
            onClick={() =>
              onDefer((RETURN_OPTIONS.find((o) => o.id === returnId) ?? RETURN_OPTIONS[1]).at().toISOString())
            }
          >
            <Sprout className="h-4 w-4" strokeWidth={1.75} aria-hidden />
            {a.letRipen}
          </button>
          <ReturnPicker value={returnId} onChange={setReturnId} />
        </>
      )}
      {!task.isExample && (s === "ripening" || s === "waiting") && (
        <button type="button" disabled={busy} className={secondary} onClick={onPause}>
          <Pause className="h-3.5 w-3.5" strokeWidth={1.75} aria-hidden />
          {a.pause}
        </button>
      )}
      {!task.isExample && s === "stalled" && (
        <>
          <button type="button" disabled={busy} className={primary} onClick={onRetry}>
            <RotateCcw className="h-4 w-4" strokeWidth={1.75} aria-hidden />
            {a.retry}
          </button>
          <button type="button" disabled={busy} className={secondary} onClick={onPause}>
            <Pause className="h-3.5 w-3.5" strokeWidth={1.75} aria-hidden />
            {a.pause}
          </button>
        </>
      )}
      {s === "ripe" && task.isExample && (
        <button type="button" disabled={busy} className={primary} onClick={onHarvest}>
          <Apple className="h-4 w-4" strokeWidth={1.75} aria-hidden />
          {a.harvest}
        </button>
      )}
      {s === "harvested" && (
        <button type="button" disabled={busy} className={secondary} onClick={onReplant}>
          <Undo2 className="h-4 w-4" strokeWidth={1.75} aria-hidden />
          {a.replant}
        </button>
      )}
      <Dialog.Root open={confirm} onOpenChange={setConfirm}>
        <Dialog.Trigger className="focus-ring inline-flex min-h-11 items-center gap-1.5 rounded-full px-2.5 text-[13px] text-ink-3 hover:text-accent-ink">
          <Trash2 className="h-3.5 w-3.5" strokeWidth={1.75} aria-hidden />
          {a.delete}
        </Dialog.Trigger>
        <Dialog.Portal>
          <Dialog.Overlay className="fixed inset-0 z-50 bg-[rgb(var(--shadow-rgb)/0.3)]" />
          <Dialog.Content className="fixed left-1/2 top-1/2 z-50 w-[min(420px,calc(100vw-2rem))] -translate-x-1/2 -translate-y-1/2 rounded-3xl border border-line bg-surface p-7 shadow-2xl">
            <Dialog.Title className="font-display text-2xl tracking-tight">{a.confirmTitle}</Dialog.Title>
            <Dialog.Description className="mt-2 text-[15px] leading-relaxed text-ink-2">
              {a.confirmText(task.title)}
            </Dialog.Description>
            <div className="mt-6 flex justify-end gap-2">
              <Dialog.Close className={secondary}>{a.keep}</Dialog.Close>
              <button
                type="button"
                disabled={busy}
                onClick={() => {
                  setConfirm(false);
                  onDelete();
                }}
                className="focus-ring rounded-full bg-accent px-5 py-2.5 text-[14px] font-medium text-white hover:bg-accent-ink"
              >
                {a.confirm}
              </button>
            </div>
          </Dialog.Content>
        </Dialog.Portal>
      </Dialog.Root>
    </div>
  );
}
