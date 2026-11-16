import { leafPaths } from "@/components/flora/shapes";
import { rand } from "@/components/flora/geometry";
import type { Sky } from "@/hooks/useSky";

const W = 1600;
const H = 1000;

const FROND = { type: "blade" as const, width: 0.34, skew: 0.9, point: 1.2, teeth: 0, toothDepth: 0 };

export type GlassFrame = { width: number; height: number; viewport: number };

function sceneBox(frame: GlassFrame) {
  const scale = Math.max(frame.width / W, (1.3 * frame.viewport) / H);
  const width = frame.width / scale;
  const height = frame.height / scale;
  return { x: (W - width) / 2, width, height };
}

function HangingPot({ night }: { night: boolean }) {
  const vines = Array.from({ length: 5 }, (_, i) => {
    const x0 = 1255 + i * 12;
    const len = 150 + rand(31, i) * 170;
    return { x0, len };
  });
  return (
    <svg
      className="pointer-events-none absolute top-0 hidden xl:block"
      style={{ right: 290, width: 180, height: 420 }}
      viewBox="1195 0 180 420"
      aria-hidden
    >
      <line x1={1285} y1={0} x2={1285} y2={70} stroke="var(--iron)" strokeWidth={1.4} opacity={0.6} />
      <path
        d="M 1225 70 Q 1285 130 1345 70 Z"
        fill="#8c5a3c"
        stroke="#3e2a1d"
        strokeWidth={1.5}
        opacity={night ? 0.7 : 1}
      />
      <path d="M 1222 70 L 1348 70" stroke="#5b3b26" strokeWidth={5} strokeLinecap="round" opacity={night ? 0.7 : 1} />
      {vines.map((v, i) => (
        <g key={i} opacity={night ? 0.55 : 0.95}>
          <path
            d={`M ${v.x0} 78 C ${v.x0 - 14} ${78 + v.len * 0.35} ${v.x0 + 16} ${78 + v.len * 0.7} ${v.x0 - 4} ${Math.round(78 + v.len)}`}
            stroke="#4d6b3a"
            strokeWidth={1.4}
            fill="none"
          />
          {Array.from({ length: Math.round(v.len / 26) }, (_, k) => {
            const y = 92 + k * 26;
            const side = k % 2 ? 1 : -1;
            return (
              <path
                key={k}
                d={leafPaths(FROND, { x: v.x0 + side * 2, y }, 90 + side * 50, 16, side).body}
                fill={k % 3 ? "#6c9a52" : "#4f7d40"}
                stroke="var(--ink-line)"
                strokeWidth={0.6}
              />
            );
          })}
        </g>
      ))}
    </svg>
  );
}

export function Glasshouse({ sky, frame }: { sky: Sky; frame?: GlassFrame | null }) {
  const night = sky === "night";
  const box = frame ? sceneBox(frame) : null;
  const depth = Math.max(H, box?.height ?? H);
  const mullions = Array.from({ length: 13 }, (_, i) => 60 + i * 123);
  const bars = [380, 620, 840];
  for (let y = 1080; y < depth; y += 240) bars.push(y);
  const stars = Array.from({ length: 40 }, (_, i) => ({
    x: rand(7, i) * W,
    y: rand(9, i) * 360,
    r: 0.6 + rand(11, i) * 1.4,
  }));

  return (
    <div className="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden>
      <div
        className="absolute inset-0 transition-[background] duration-[2000ms]"
        style={{ background: "linear-gradient(180deg, var(--glass-top) 0%, var(--glass-bottom) 78%)" }}
      />
      <svg
        className={
          frame === undefined
            ? "absolute inset-x-0 top-0 h-[115%] w-full"
            : `absolute inset-0 h-full w-full transition-opacity duration-700 ${box ? "opacity-100" : "opacity-0"}`
        }
        viewBox={box ? `${box.x} 0 ${box.width} ${box.height}` : `0 0 ${W} ${H}`}
        preserveAspectRatio="xMidYMin slice"
      >
        <defs>
          <linearGradient id="gh-shaft" x1="0" y1="0" x2="0.6" y2="1">
            <stop offset="0" stopColor="var(--light)" />
            <stop offset="1" stopColor="var(--light)" stopOpacity="0" />
          </linearGradient>
          <linearGradient id="gh-pane" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stopColor="#ffffff" stopOpacity="0.35" />
            <stop offset="0.5" stopColor="#ffffff" stopOpacity="0" />
          </linearGradient>
          <radialGradient id="gh-moon">
            <stop offset="0" stopColor="#fbf6df" />
            <stop offset="0.6" stopColor="#efe8c8" stopOpacity="0.6" />
            <stop offset="1" stopColor="#efe8c8" stopOpacity="0" />
          </radialGradient>
        </defs>

        {night && (
          <g>
            {stars.map((s, i) => (
              <circle
                key={i}
                cx={Math.round(s.x)}
                cy={Math.round(s.y)}
                r={Math.round(s.r * 10) / 10}
                fill="#fffbe8"
                opacity={0.55}
              />
            ))}
            <circle cx={930} cy={140} r={90} fill="url(#gh-moon)" />
            <circle cx={930} cy={140} r={26} fill="#f8f2d8" />
          </g>
        )}

        {!night && (
          <g className="shaft" style={{ mixBlendMode: "screen" }}>
            <polygon points="180,0 420,0 980,1000 520,1000" fill="url(#gh-shaft)" opacity={0.55} />
            <polygon points="560,0 680,0 1220,1000 1000,1000" fill="url(#gh-shaft)" opacity={0.35} />
            <polygon points="900,0 960,0 1420,1000 1320,1000" fill="url(#gh-shaft)" opacity={0.25} />
          </g>
        )}

        <g style={{ stroke: "var(--iron)", fill: "none" }}>
          <path d={`M -20 250 Q ${W / 2} -150 ${W + 20} 250`} strokeWidth={9} opacity={0.5} />
          <path d={`M -20 290 Q ${W / 2} -100 ${W + 20} 290`} strokeWidth={2.5} opacity={0.4} />
          {mullions.map((x, i) => {
            const t = x / W;
            const yTop = 250 - 800 * t * (1 - t);
            return (
              <line
                key={i}
                x1={x}
                y1={Math.round(yTop + 12)}
                x2={x}
                y2={Math.round(depth)}
                strokeWidth={i % 3 === 0 ? 5 : 2.5}
                opacity={0.42}
              />
            );
          })}
          {bars.map((y, i) => (
            <line key={y} x1={-W} y1={y} x2={2 * W} y2={y} strokeWidth={i === 2 ? 6 : 2.2} opacity={0.35} />
          ))}
          {Array.from({ length: 9 }, (_, i) => {
            const t = (i + 1) / 10;
            const x = t * W;
            const y = 250 - 400 * 2 * t * (1 - t);
            return (
              <line
                key={i}
                x1={Math.round(W / 2)}
                y1={60}
                x2={Math.round(x)}
                y2={Math.round(y + 10)}
                strokeWidth={1.4}
                opacity={0.3}
              />
            );
          })}
        </g>

        <g opacity={night ? 0.15 : 0.6}>
          {mullions
            .slice(0, -1)
            .map((x, i) =>
              i % 2 === 0 ? (
                <polygon
                  key={i}
                  points={`${x + 8},${420} ${x + 70},${420} ${x + 20},${600} ${x + 8},${600}`}
                  fill="url(#gh-pane)"
                />
              ) : null,
            )}
        </g>
      </svg>
      <HangingPot night={night} />
      <div
        className="absolute inset-0"
        style={{ background: "radial-gradient(ellipse 80% 60% at 30% 10%, var(--haze), transparent 70%)" }}
      />
    </div>
  );
}
