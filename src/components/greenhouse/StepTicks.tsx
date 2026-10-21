import type { LeafState } from "@/lib/types";

export function StepTicks({ leaves, className }: { leaves: LeafState[]; className?: string }) {
  if (!leaves.length) return null;
  return (
    <span className={`inline-flex items-center gap-1 ${className ?? ""}`} aria-hidden>
      {leaves.map((leaf) => (
        <svg key={leaf.id} viewBox="0 0 12 12" className="h-3 w-3">
          {leaf.status === "done" && (
            <path
              d="M1.5 10.5 C 1.5 5, 5 1.5, 10.5 1.5 C 10.5 7, 7 10.5, 1.5 10.5 Z"
              fill="var(--leaf)"
              stroke="var(--ink-line)"
              strokeWidth={0.6}
            />
          )}
          {leaf.status === "working" && (
            <>
              <path
                d="M1.5 10.5 C 1.5 5, 5 1.5, 10.5 1.5 C 10.5 7, 7 10.5, 1.5 10.5 Z"
                fill="none"
                stroke="var(--leaf)"
                strokeWidth={1}
              />
              <path d="M1.5 10.5 C 1.5 5, 5 1.5, 10.5 1.5 L 1.5 10.5 Z" fill="var(--leaf-2)" className="glow-breathe" />
            </>
          )}
          {leaf.status === "pending" && (
            <path
              d="M6 11 C 3.5 8, 3.5 4, 6 1 C 8.5 4, 8.5 8, 6 11 Z"
              fill="none"
              stroke="var(--ink-3)"
              strokeWidth={0.9}
            />
          )}
          {leaf.status === "blocked" && (
            <path
              d="M6 11 C 3.5 8, 3.5 4, 6 1 C 8.5 4, 8.5 8, 6 11 Z"
              fill="var(--ochre)"
              stroke="var(--ink-line)"
              strokeWidth={0.6}
            />
          )}
        </svg>
      ))}
    </span>
  );
}
