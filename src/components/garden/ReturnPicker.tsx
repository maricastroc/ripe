"use client";

import * as Menu from "@radix-ui/react-dropdown-menu";
import { RETURN_OPTIONS } from "@/lib/format";

export function ReturnPicker({ value, onChange }: { value: string; onChange: (id: string) => void }) {
  const current = RETURN_OPTIONS.find((o) => o.id === value) ?? RETURN_OPTIONS[1];
  return (
    <Menu.Root>
      <Menu.Trigger
        className="focus-ring inline-flex items-center gap-1.5 rounded-full px-2 py-1.5 text-[15px] text-ink-2 transition-colors hover:text-ink data-[state=open]:text-ink"
        aria-label={`Quando você pretende voltar: ${current.label}`}
      >
        volto <span className="underline decoration-line-2 decoration-dashed underline-offset-4">{current.label}</span>
        <svg viewBox="0 0 12 12" className="h-3 w-3" aria-hidden>
          <path d="M3 4.5l3 3 3-3" fill="none" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" />
        </svg>
      </Menu.Trigger>
      <Menu.Portal>
        <Menu.Content
          align="start"
          sideOffset={6}
          className="z-50 min-w-[200px] rounded-2xl border border-line bg-surface p-1.5 shadow-xl"
        >
          <Menu.Label className="label px-3 pb-1 pt-2">quando você volta?</Menu.Label>
          {RETURN_OPTIONS.map((option) => (
            <Menu.Item
              key={option.id}
              onSelect={() => onChange(option.id)}
              className="flex cursor-pointer items-center justify-between rounded-xl px-3 py-2 text-[15px] text-ink-2 outline-none data-[highlighted]:bg-paper-2 data-[highlighted]:text-ink"
            >
              {option.label}
              {option.id === current.id && <span className="h-1.5 w-1.5 rounded-full bg-accent" />}
            </Menu.Item>
          ))}
        </Menu.Content>
      </Menu.Portal>
    </Menu.Root>
  );
}
