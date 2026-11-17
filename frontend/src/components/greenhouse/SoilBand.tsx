import { useMemo } from "react";
import { type Pt, openSmooth, r1, rand } from "@/components/flora/geometry";
import type { AgentMode } from "@/lib/types";
import { PlateSoil, Tuft } from "./PlateSoil";

export type Thread = { id: string; x: number; state: "working" | "queued" | "idle" | "dormant" };

type Props = {
  width: number;
  height: number;
  sourceX: number;
  fromAbove: boolean;
  continues: boolean;
  threads: Thread[];
  agentMode: AgentMode;
  paused: boolean;
  salt: number;
  bare?: boolean;
};

const STRATA: [number, number] = [0.58, 0.88];

export function hyphaTone(mode: AgentMode, paused: boolean) {
  const down = mode === "offline" || mode === "stopped" || mode === "model-missing" || mode === "halted";
  const resting = mode === "resting";
  return {
    down,
    resting,
    color: down ? "#9b9585" : resting ? "#a9bcc6" : "var(--myc)",
    opacity: down ? 0.6 : paused ? 0.35 : resting ? 0.5 : 1,
    glow: !down && !paused,
    dashed: down,
  };
}

function hyphaPath(source: Pt, target: Pt, height: number, salt: number, fromAbove: boolean) {
  const midY = height * 0.3 + (rand(salt, 1) - 0.5) * height * 0.1;
  const pts: Pt[] = [source];
  if (fromAbove) pts.push({ x: source.x + 4, y: height * 0.3 });
  pts.push({ x: source.x + 10, y: midY });
  const span = target.x - source.x;
  const steps = Math.max(2, Math.round(Math.abs(span) / 90));
  for (let i = 1; i < steps; i++) {
    const f = i / steps;
    pts.push({
      x: source.x + span * f,
      y: midY + Math.sin(f * Math.PI * 2 + salt) * height * 0.05 + (rand(salt, i + 5) - 0.5) * 8,
    });
  }
  pts.push({ x: target.x - Math.sign(span || 1) * 18, y: Math.max(20, midY - height * 0.08) });
  pts.push(target);
  return pts;
}

function sprigs(points: Pt[], salt: number, count: number) {
  const out: string[] = [];
  for (let i = 0; i < count; i++) {
    const at = points[1 + Math.floor(rand(salt, i + 40) * (points.length - 2))];
    const angle = rand(salt, i + 50) * Math.PI * 2;
    const len = 14 + rand(salt, i + 60) * 34;
    const mid = { x: at.x + Math.cos(angle) * len * 0.5 + 6, y: at.y + Math.sin(angle) * len * 0.5 - 4 };
    const end = { x: at.x + Math.cos(angle) * len, y: at.y + Math.sin(angle) * len };
    out.push(`M ${r1(at.x)} ${r1(at.y)} Q ${r1(mid.x)} ${r1(mid.y)} ${r1(end.x)} ${r1(end.y)}`);
  }
  return out.join(" ");
}

export function SoilBand({
  width,
  height,
  sourceX,
  fromAbove,
  continues,
  threads,
  agentMode,
  paused,
  salt,
  bare = false,
}: Props) {
  const { down, resting, color } = hyphaTone(agentMode, paused);
  const tufts = useMemo(
    () => Array.from({ length: Math.max(3, Math.round(width / 260)) }, (_, i) => r1(rand(salt, i + 10000) * width)),
    [width, salt],
  );

  const source: Pt = { x: sourceX, y: fromAbove ? 0 : 14 };
  const paths = threads.map((t, i) => {
    const pts = hyphaPath(source, { x: t.x, y: 16 }, height, salt + i * 17 + 3, fromAbove);
    return { ...t, d: openSmooth(pts), sprigs: sprigs(pts, salt + i * 31, 4) };
  });
  const web = threads.slice(1).map((t, i) => {
    const a = threads[i];
    const y = height * 0.36 + (rand(salt, i + 300) - 0.5) * 14;
    return `M ${r1(a.x)} ${r1(height * 0.22)} C ${r1(a.x + 40)} ${r1(y)} ${r1(t.x - 40)} ${r1(y)} ${r1(t.x)} ${r1(height * 0.24)}`;
  });
  const baseOpacity = down ? 0.55 : paused ? 0.3 : resting ? 0.4 : 0.62;
  const strands = (
    <g>
      {web.map((d, i) => (
        <path
          key={`w${i}`}
          d={d}
          fill="none"
          stroke={color}
          strokeWidth={0.6}
          opacity={baseOpacity * 0.45}
          strokeDasharray={down ? "2 6" : undefined}
        />
      ))}
      {fromAbove && (
        <line
          x1={r1(sourceX)}
          y1={0}
          x2={r1(sourceX + 4)}
          y2={r1(height * 0.3)}
          stroke={color}
          strokeWidth={1.2}
          opacity={baseOpacity}
        />
      )}
      {continues && (
        <path
          d={`M ${r1(sourceX + 10)} ${r1(height * 0.3)} C ${r1(sourceX)} ${r1(height * 0.7)} ${r1(sourceX + 8)} ${r1(height * 0.86)} ${r1(sourceX + 2)} ${r1(height)}`}
          stroke={color}
          strokeWidth={1.2}
          fill="none"
          opacity={baseOpacity}
        />
      )}
      {paths.map((p) => {
        const lit = !down && !paused && !resting && p.state === "working";
        return (
          <g key={p.id}>
            {!down && (
              <path
                d={p.d}
                fill="none"
                stroke="var(--myc-glow)"
                strokeWidth={lit ? 9 : 5}
                opacity={lit ? 0.55 : 0.18}
                strokeLinecap="round"
              />
            )}
            <path
              d={p.d}
              fill="none"
              stroke={color}
              strokeWidth={lit ? 1.8 : 1.1}
              opacity={p.state === "dormant" ? baseOpacity * 0.5 : baseOpacity}
              strokeDasharray={down ? "3 5" : undefined}
              className={!down && !lit ? "myc-breathe" : undefined}
            />
            <path d={p.sprigs} fill="none" stroke={color} strokeWidth={0.55} opacity={baseOpacity * 0.6} />
            {lit && (
              <path
                d={p.d}
                pathLength={1000}
                className="myc-pulse"
                fill="none"
                stroke="#fbffe2"
                strokeWidth={3}
                strokeLinecap="round"
              />
            )}
            {!down && !paused && !resting && p.state === "queued" && (
              <path
                d={p.d}
                pathLength={1000}
                className="myc-pulse slow"
                fill="none"
                stroke="#f3ffc4"
                strokeWidth={2}
                strokeLinecap="round"
              />
            )}
          </g>
        );
      })}
    </g>
  );

  if (bare) {
    return (
      <svg
        width={r1(width)}
        height={r1(height)}
        className="pointer-events-none absolute inset-0 block overflow-visible"
        aria-hidden
      >
        {strands}
      </svg>
    );
  }

  return (
    <PlateSoil
      width={width}
      height={height}
      salt={salt}
      strata={STRATA}
      edgeAbove={tufts.map((x, i) => (
        <Tuft key={i} x={x} />
      ))}
    >
      {strands}
    </PlateSoil>
  );
}
