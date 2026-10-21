import { leafPaths } from "@/components/flora/shapes";
import { rand } from "@/components/flora/geometry";
import type { Sky } from "@/hooks/useSky";

const W = 1600;
const H = 1000;

const FROND = { type: "blade" as const, width: 0.34, skew: 0.9, point: 1.2, teeth: 0, toothDepth: 0 };
const MONSTERA = {
  type: "palmate" as const,
  lobes: [
    { angle: 0, reach: 1 },
    { angle: 40, reach: 0.95 },
    { angle: -40, reach: 0.95 },
    { angle: 85, reach: 0.85 },
    { angle: -85, reach: 0.85 },
    { angle: 130, reach: 0.6 },
    { angle: -130, reach: 0.6 },
  ],
  sinus: 0.62,
  width: 1,
};

function Palm({ x, y, scale, flip, salt }: { x: number; y: number; scale: number; flip: number; salt: number }) {
  const fronds = Array.from({ length: 7 }, (_, i) => {
    const angle = -90 + flip * (-70 + i * 22) + (rand(salt, i) - 0.5) * 10;
    return leafPaths(FROND, { x, y }, angle, 220 * scale * (0.75 + rand(salt, i + 9) * 0.35), i % 2 ? 1 : -1).body;
  });
  return (
    <g>
      {fronds.map((d, i) => (
        <path key={i} d={d} />
      ))}
    </g>
  );
}

export function Glasshouse({ sky }: { sky: Sky }) {
  const night = sky === "night";
  const mullions = Array.from({ length: 13 }, (_, i) => 60 + i * 123);
  const stars = Array.from({ length: 40 }, (_, i) => ({
    x: rand(7, i) * W,
    y: rand(9, i) * 360,
    r: 0.6 + rand(11, i) * 1.4,
  }));
  const monstera = leafPaths(MONSTERA, { x: 1490, y: 760 }, -128, 300, 1);
  const monstera2 = leafPaths(MONSTERA, { x: 1560, y: 820 }, -100, 240, -1);
  const vines = Array.from({ length: 5 }, (_, i) => {
    const x0 = 1255 + i * 12;
    const len = 150 + rand(31, i) * 170;
    return { x0, len };
  });

  return (
    <div className="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden>
      <div
        className="absolute inset-0 transition-[background] duration-[2000ms]"
        style={{ background: "linear-gradient(180deg, var(--glass-top) 0%, var(--glass-bottom) 78%)" }}
      />
      <svg
        className="absolute inset-x-0 top-0 h-[115%] w-full"
        viewBox={`0 0 ${W} ${H}`}
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
            <circle cx={1290} cy={150} r={90} fill="url(#gh-moon)" />
            <circle cx={1290} cy={150} r={26} fill="#f8f2d8" />
          </g>
        )}

        {!night && (
          <g className="shaft" style={{ mixBlendMode: "screen" }}>
            <polygon points="180,0 420,0 980,1000 520,1000" fill="url(#gh-shaft)" opacity={0.55} />
            <polygon points="560,0 680,0 1220,1000 1000,1000" fill="url(#gh-shaft)" opacity={0.35} />
            <polygon points="900,0 960,0 1420,1000 1320,1000" fill="url(#gh-shaft)" opacity={0.25} />
          </g>
        )}

        <g style={{ fill: night ? "#0d1a1a" : "#3d6152", opacity: night ? 0.55 : 0.13 }}>
          <Palm x={60} y={1000} scale={1.25} flip={1} salt={3} />
          <Palm x={260} y={1040} scale={0.85} flip={1} salt={5} />
          <path d={monstera.body} />
          <path d={monstera2.body} />
        </g>

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
                y2={H}
                strokeWidth={i % 3 === 0 ? 5 : 2.5}
                opacity={0.42}
              />
            );
          })}
          {[0.38, 0.62, 0.84].map((f, i) => (
            <line
              key={i}
              x1={0}
              y1={Math.round(H * f)}
              x2={W}
              y2={Math.round(H * f)}
              strokeWidth={i === 2 ? 6 : 2.2}
              opacity={0.35}
            />
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

        <g>
          <line x1={1285} y1={0} x2={1285} y2={70} stroke="var(--iron)" strokeWidth={1.4} opacity={0.6} />
          <path
            d="M 1225 70 Q 1285 130 1345 70 Z"
            fill="#8c5a3c"
            stroke="#3e2a1d"
            strokeWidth={1.5}
            opacity={night ? 0.7 : 1}
          />
          <path
            d="M 1222 70 L 1348 70"
            stroke="#5b3b26"
            strokeWidth={5}
            strokeLinecap="round"
            opacity={night ? 0.7 : 1}
          />
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
        </g>
      </svg>
      <div
        className="absolute inset-0"
        style={{ background: "radial-gradient(ellipse 80% 60% at 30% 10%, var(--haze), transparent 70%)" }}
      />
    </div>
  );
}
