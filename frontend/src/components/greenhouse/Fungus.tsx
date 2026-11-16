import { r1, tapered } from "@/components/flora/geometry";
import type { AgentMode } from "@/lib/types";

type Cap = { x: number; h: number; rx: number; ry: number; tilt: number };

const CAPS: Cap[] = [
  { x: -12, h: 92, rx: 30, ry: 17, tilt: -6 },
  { x: 20, h: 60, rx: 21, ry: 13, tilt: 9 },
  { x: -36, h: 36, rx: 13, ry: 8, tilt: -14 },
];

export function Fungus({ mode, paused }: { mode: AgentMode; paused: boolean }) {
  const down = mode === "offline" || mode === "stopped" || mode === "model-missing" || mode === "halted";
  const resting = mode === "resting";
  const active = !down && !paused && mode === "working";
  const capFill = down ? "#a8a196" : resting ? "#cbc7bc" : "#d8c49c";
  const capTop = down ? "#8c867b" : resting ? "#9d9b93" : "#b98f5e";
  const gill = down ? "#c9c3b5" : active ? "#eaff9a" : resting ? "#dde7ec" : "#f1ebd2";
  return (
    <svg viewBox="-56 -150 112 158" className="block h-auto w-full" aria-hidden style={{ overflow: "visible" }}>
      <defs>
        <radialGradient id="fungus-glow">
          <stop offset="0" stopColor="#efffb5" stopOpacity="0.9" />
          <stop offset="0.45" stopColor="#d9f57a" stopOpacity="0.35" />
          <stop offset="1" stopColor="#d9f57a" stopOpacity="0" />
        </radialGradient>
        <radialGradient id="fungus-rest">
          <stop offset="0" stopColor="#e4f1f7" stopOpacity="0.8" />
          <stop offset="0.5" stopColor="#b9d3e0" stopOpacity="0.3" />
          <stop offset="1" stopColor="#b9d3e0" stopOpacity="0" />
        </radialGradient>
      </defs>
      {!down && !paused && (
        <circle
          cx={-4}
          cy={-70}
          r={62}
          fill={resting ? "url(#fungus-rest)" : "url(#fungus-glow)"}
          className={active ? "glow-breathe" : resting ? "myc-hibernate" : "myc-breathe"}
          opacity={active ? 1 : resting ? 0.35 : 0.5}
        />
      )}
      {CAPS.map((c, i) => {
        const stipe = tapered(
          [
            { x: c.x * 0.6, y: 2 },
            { x: c.x * 0.8 + c.tilt * 0.2, y: -c.h * 0.5 },
            { x: c.x + c.tilt * 0.4, y: -c.h },
          ],
          c.rx * 0.42,
          c.rx * 0.28,
        );
        const cx = c.x + c.tilt * 0.4;
        const cy = -c.h;
        const cap = `M ${r1(cx - c.rx)} ${r1(cy + 2)} C ${r1(cx - c.rx)} ${r1(cy - c.ry * 1.5)} ${r1(cx + c.rx)} ${r1(cy - c.ry * 1.5)} ${r1(cx + c.rx)} ${r1(cy + 2)} Q ${r1(cx)} ${r1(cy - 3)} ${r1(cx - c.rx)} ${r1(cy + 2)} Z`;
        const gills = Array.from({ length: 9 }, (_, k) => {
          const f = (k + 0.5) / 9;
          const x = cx - c.rx * 0.92 + f * c.rx * 1.84;
          return `M ${r1(x)} ${r1(cy + 1)} L ${r1(cx + (x - cx) * 0.25)} ${r1(cy + 4)}`;
        }).join(" ");
        return (
          <g key={i} transform={`rotate(${c.tilt} ${r1(cx)} ${r1(cy)})`}>
            <path d={stipe} fill="#efe6cf" stroke="var(--ink-line)" strokeWidth={0.8} />
            {i === 0 && (
              <path
                d={`M ${r1(cx - 6)} ${r1(cy + c.h * 0.32)} Q ${r1(cx)} ${r1(cy + c.h * 0.36)} ${r1(cx + 6)} ${r1(cy + c.h * 0.32)}`}
                stroke="#c9b991"
                strokeWidth={2.4}
                fill="none"
              />
            )}
            <path
              d={`M ${r1(cx - c.rx * 0.95)} ${r1(cy + 2)} Q ${r1(cx)} ${r1(cy + 7)} ${r1(cx + c.rx * 0.95)} ${r1(cy + 2)} Z`}
              fill={gill}
              stroke="var(--ink-line)"
              strokeWidth={0.5}
            />
            <path d={gills} stroke={down ? "#8f897c" : active ? "#b7d64e" : "#c8bd96"} strokeWidth={0.6} />
            <path d={cap} fill={capFill} stroke="var(--ink-line)" strokeWidth={0.9} />
            <path
              d={`M ${r1(cx - c.rx * 0.6)} ${r1(cy - c.ry * 0.7)} C ${r1(cx - c.rx * 0.3)} ${r1(cy - c.ry * 1.25)} ${r1(cx + c.rx * 0.3)} ${r1(cy - c.ry * 1.25)} ${r1(cx + c.rx * 0.6)} ${r1(cy - c.ry * 0.7)} Q ${r1(cx)} ${r1(cy - c.ry * 0.9)} ${r1(cx - c.rx * 0.6)} ${r1(cy - c.ry * 0.7)} Z`}
              fill={capTop}
              opacity={0.75}
            />
            {[-0.45, 0.1, 0.5].map((f, k) => (
              <ellipse
                key={k}
                cx={r1(cx + f * c.rx)}
                cy={r1(cy - c.ry * (0.55 + (k % 2) * 0.25))}
                rx={r1(c.rx * 0.09)}
                ry={r1(c.ry * 0.09)}
                fill="#f6efdc"
                opacity={0.85}
              />
            ))}
          </g>
        );
      })}
      {mode === "working" &&
        !paused &&
        [0, 1, 2, 3, 4, 5].map((k) => (
          <circle
            key={k}
            className="spore"
            cx={-30 + k * 12}
            cy={-96 + (k % 3) * 8}
            r={1.3}
            fill="#f3ffc8"
            style={
              {
                "--dx": `${(k - 2.5) * 5}px`,
                "--delay": `${k * 0.6}s`,
                "--dur": `${4 + (k % 3)}s`,
              } as React.CSSProperties
            }
          />
        ))}
    </svg>
  );
}
