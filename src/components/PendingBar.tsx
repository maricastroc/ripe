"use client";

import { useLinkStatus } from "next/link";

export function PendingBar({ className = "", also = false }: { className?: string; also?: boolean }) {
  const { pending } = useLinkStatus();
  if (!pending && !also) return null;
  return (
    <>
      <span aria-hidden className={`working-bar pointer-events-none absolute ${className}`} />
      <span role="status" className="sr-only">
        Opening…
      </span>
    </>
  );
}
