"use client";

import * as Menu from "@radix-ui/react-dropdown-menu";
import { ChevronDown } from "lucide-react";
import { RETURN_OPTIONS } from "@/lib/format";

export function ReturnPicker({ value, onChange }: { value: string; onChange: (id: string) => void }) {
  const current = RETURN_OPTIONS.find((o) => o.id === value) ?? RETURN_OPTIONS[1];
  return (
    <Menu.Root>
      <Menu.Trigger
        className="focus-ring inline-flex min-h-11 items-center gap-1.5 rounded-full px-2 text-[15px] text-ink-2 transition-colors hover:text-ink data-[state=open]:text-ink"
        aria-label={`When you plan to come back: ${current.label}`}
      >
        back <span className="underline decoration-line-2 decoration-dashed underline-offset-4">{current.label}</span>
        <ChevronDown className="h-3.5 w-3.5" strokeWidth={1.75} aria-hidden />
      </Menu.Trigger>
      <Menu.Portal>
        <Menu.Content
          align="start"
          sideOffset={6}
          className="z-50 min-w-[200px] rounded-2xl border border-line bg-surface p-1.5 shadow-xl"
        >
          <Menu.Label className="label px-3 pb-1 pt-2">when will you be back?</Menu.Label>
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
