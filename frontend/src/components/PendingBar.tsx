"use client";

import { useLinkStatus } from "next/link";
import { useI18n } from "@/lib/i18n/context";

export function PendingBar({ className = "", also = false }: { className?: string; also?: boolean }) {
  const { pending } = useLinkStatus();
  const { t } = useI18n();
  if (!pending && !also) return null;
  return (
    <>
      <span aria-hidden className={`working-bar pointer-events-none absolute ${className}`} />
      <span role="status" className="sr-only">
        {t.opening}
      </span>
    </>
  );
}
