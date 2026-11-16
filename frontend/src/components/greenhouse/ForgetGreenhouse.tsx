"use client";

import * as Dialog from "@radix-ui/react-dialog";
import { useState } from "react";
import { api } from "@/lib/api";
import { useI18n } from "@/lib/i18n/context";

export function ForgetGreenhouse() {
  const f = useI18n().t.forget;
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function forget() {
    setBusy(true);
    setError(null);
    try {
      await api("/api/greenhouse", { method: "DELETE" });
      window.location.reload();
    } catch (err) {
      setError(err instanceof Error ? err.message : f.failed);
      setBusy(false);
    }
  }

  return (
    <Dialog.Root open={open} onOpenChange={setOpen}>
      <Dialog.Trigger className="focus-ring rounded px-1 underline decoration-dotted underline-offset-4 hover:text-[#efe3c7]">
        {f.trigger}
      </Dialog.Trigger>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 z-50 bg-[rgb(var(--shadow-rgb)/0.3)]" />
        <Dialog.Content className="fixed left-1/2 top-1/2 z-50 w-[min(440px,calc(100vw-2rem))] -translate-x-1/2 -translate-y-1/2 rounded-3xl border border-line bg-surface p-7 text-ink shadow-2xl">
          <Dialog.Title className="font-display text-2xl tracking-tight">{f.title}</Dialog.Title>
          <Dialog.Description className="mt-2 text-[15px] leading-relaxed text-ink-2">
            {f.description}
          </Dialog.Description>
          {error && <p className="mt-3 text-[14px] text-accent-ink">{error}</p>}
          <div className="mt-6 flex justify-end gap-2">
            <Dialog.Close className="focus-ring inline-flex min-h-11 items-center rounded-full border border-line-2 px-4 py-2 text-[14px] text-ink-2 hover:text-ink">
              {f.keep}
            </Dialog.Close>
            <button
              type="button"
              disabled={busy}
              onClick={forget}
              className="focus-ring rounded-full bg-accent px-5 py-2.5 text-[14px] font-medium text-white hover:bg-accent-ink disabled:opacity-60"
            >
              {busy ? f.clearing : f.confirm}
            </button>
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
