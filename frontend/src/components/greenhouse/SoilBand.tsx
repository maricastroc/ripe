import { useMemo } from "react";
import { type Pt, openSmooth, r1, rand } from "@/components/flora/geometry";
import type { AgentMode } from "@/lib/types";

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
};

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

export function SoilBand({ width, height, sourceX, fromAbove, continues, threads, agentMode, paused, salt }: Props) {
  const down = agentMode === "offline" || agentMode === "stopped" || agentMode === "model-missing";
  const scene = useMemo(() => {
    const specks = Array.from({ length: Math.round(width / 6) }, (_, i) => ({
      x: r1(rand(salt, i) * width),
      y: r1(10 + rand(salt, i + 999) * (height - 14)),
      r: r1(0.6 + rand(salt, i + 1999) * 1.3),
      light: rand(salt, i + 2999) > 0.72,
    }));
    const pebbles = Array.from({ length: Math.round(width / 46) }, (_, i) => ({
      x: r1(rand(salt, i + 4000) * width),
      y: r1(height * 0.25 + rand(salt, i + 5000) * height * 0.7),
      rx: r1(3 + rand(salt, i + 6000) * 8),
      ry: r1(2 + rand(salt, i + 7000) * 4.5),
      a: r1(rand(salt, i + 8000) * 180),
    }));
    const edgeCount = Math.max(3, Math.round(width / 70)) + 1;
    const edge: Pt[] = Array.from({ length: edgeCount }, (_, i) => ({
      x: (i / (edgeCount - 1)) * width,
      y: 3 + rand(salt, i + 9000) * 5,
    }));
    const tufts = Array.from({ length: Math.round(width / 24) }, (_, i) => r1(rand(salt, i + 10000) * width));
    return { specks, pebbles, edge, tufts };
  }, [width, height, salt]);

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
  const color = down ? "#9b9585" : "var(--myc)";
  const baseOpacity = down ? 0.55 : paused ? 0.3 : 0.62;
  const edgePath = `${openSmooth(scene.edge)} L ${r1(width)} ${r1(height)} L 0 ${r1(height)} Z`;

  return (
    <svg
      width={width}
      height={height}
      viewBox={`0 0 ${r1(width)} ${r1(height)}`}
      className="absolute inset-0 block"
      aria-hidden
    >
      <defs>
        <linearGradient id={`soil-${salt}`} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#5d3f2b" />
          <stop offset="0.18" stopColor="#47311f" />
          <stop offset="0.6" stopColor="#35241a" />
          <stop offset="1" stopColor="#2b1d14" />
        </linearGradient>
        <linearGradient id={`plank-${salt}`} x1="0" y1="0" x2="1" y2="0">
          <stop offset="0" stopColor="#8a6040" />
          <stop offset="0.5" stopColor="#a8784f" />
          <stop offset="1" stopColor="#7a5233" />
        </linearGradient>
      </defs>
      <path d={edgePath} fill={`url(#soil-${salt})`} />
      <path
        d={`M 0 ${r1(height * 0.34)} Q ${r1(width * 0.3)} ${r1(height * 0.3)} ${r1(width * 0.6)} ${r1(height * 0.36)} T ${r1(width)} ${r1(height * 0.33)}`}
        stroke="#241810"
        strokeWidth={0.8}
        fill="none"
        opacity={0.5}
      />
      <path
        d={`M 0 ${r1(height * 0.72)} Q ${r1(width * 0.25)} ${r1(height * 0.78)} ${r1(width * 0.55)} ${r1(height * 0.7)} T ${r1(width)} ${r1(height * 0.74)}`}
        stroke="#7c4a2e"
        strokeWidth={1}
        fill="none"
        opacity={0.4}
      />
      {scene.specks.map((s, i) => (
        <circle key={i} cx={s.x} cy={s.y} r={s.r} fill={s.light ? "#c7a678" : "#7a573c"} opacity={0.55} />
      ))}
      {scene.pebbles.map((p, i) => (
        <g key={i} transform={`rotate(${p.a} ${p.x} ${p.y})`}>
          <ellipse cx={p.x} cy={p.y} rx={p.rx} ry={p.ry} fill="#857a6b" stroke="#21160f" strokeWidth={0.6} />
          <ellipse
            cx={r1(p.x - p.rx * 0.3)}
            cy={r1(p.y - p.ry * 0.35)}
            rx={r1(p.rx * 0.35)}
            ry={r1(p.ry * 0.25)}
            fill="#c4b9a6"
            opacity={0.55}
          />
        </g>
      ))}

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
          const lit = !down && !paused && p.state === "working";
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
              {!down && !paused && p.state === "queued" && (
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

      {scene.tufts.map((x, i) => (
        <path
          key={i}
          d={`M ${x} 6 q -2 -8 -6 -10 M ${x} 6 q 0 -9 2 -14 M ${x} 6 q 3 -7 7 -9`}
          stroke={i % 3 ? "#5d7d43" : "#7a9a52"}
          strokeWidth={1}
          fill="none"
          strokeLinecap="round"
        />
      ))}

      <rect
        x={0}
        y={-4}
        width={10}
        height={r1(height + 4)}
        fill={`url(#plank-${salt})`}
        stroke="#3e2a1b"
        strokeWidth={0.8}
      />
      <rect
        x={r1(width - 10)}
        y={-4}
        width={10}
        height={r1(height + 4)}
        fill={`url(#plank-${salt})`}
        stroke="#3e2a1b"
        strokeWidth={0.8}
      />
      {[0.2, 0.45, 0.7].map((f, i) => (
        <g key={i} stroke="#5e3f27" strokeWidth={0.6} opacity={0.6}>
          <line x1={3} y1={r1(height * f)} x2={7} y2={r1(height * f + 12)} />
          <line x1={r1(width - 7)} y1={r1(height * f + 6)} x2={r1(width - 3)} y2={r1(height * f + 18)} />
        </g>
      ))}
    </svg>
  );
}
