import type { Stage } from "@/lib/types";

export function StageGlyph({ stage, className }: { stage: Stage; className?: string }) {
  const leaf = "var(--leaf)";
  return (
    <svg viewBox="0 0 24 24" className={className} aria-hidden>
      {stage === "seed" && <ellipse cx="12" cy="15" rx="4.2" ry="2.8" transform="rotate(-20 12 15)" fill="#8a6748" />}
      {stage === "sprout" && (
        <g>
          <path d="M12 20 V12" stroke={leaf} strokeWidth="1.8" strokeLinecap="round" />
          <path d="M12 12 C9 8 6 9 5 10 C7 12 10 13 12 12 Z" fill="var(--leaf-2)" />
          <path d="M12 12 C15 8 18 9 19 10 C17 12 14 13 12 12 Z" fill="var(--leaf-2)" />
        </g>
      )}
      {stage === "leafing" && (
        <g>
          <path d="M12 21 V5" stroke={leaf} strokeWidth="1.8" strokeLinecap="round" />
          <path d="M12 15 C9 12 6 13 5 14 C7 16 10 16 12 15 Z" fill={leaf} />
          <path d="M12 11 C15 8 18 9 19 10 C17 12 14 12 12 11 Z" fill={leaf} />
          <path d="M12 7 C10 5 9 5 8 6 C9 7.5 11 7.5 12 7 Z" fill="var(--leaf-2)" />
        </g>
      )}
      {stage === "blossom" && (
        <g>
          {[0, 72, 144, 216, 288].map((a) => (
            <ellipse
              key={a}
              cx="12"
              cy="7.2"
              rx="2.8"
              ry="4"
              transform={`rotate(${a} 12 12)`}
              fill="var(--petal)"
              stroke="var(--petal-2)"
              strokeWidth="0.7"
            />
          ))}
          <circle cx="12" cy="12" r="2.6" fill="var(--butter)" />
        </g>
      )}
      {stage === "fruit" && (
        <g>
          <path d="M12 4 Q13 6 12.5 8" stroke="var(--leaf-dark)" strokeWidth="1.6" fill="none" strokeLinecap="round" />
          <circle cx="12" cy="14" r="6.5" fill="#a8b77c" />
        </g>
      )}
      {stage === "ripe" && (
        <g>
          <path d="M12 4 Q13 6 12.5 8" stroke="var(--leaf-dark)" strokeWidth="1.6" fill="none" strokeLinecap="round" />
          <path d="M12.5 7 C15 3.5 19 4 20 5.5 C17.5 7.5 14.5 7.8 12.5 7 Z" fill={leaf} />
          <ellipse cx="12" cy="14.5" rx="7.4" ry="6.6" fill="#e3672f" />
          <ellipse cx="9.4" cy="12" rx="1.8" ry="1.1" fill="#fff" opacity="0.45" />
        </g>
      )}
    </svg>
  );
}
