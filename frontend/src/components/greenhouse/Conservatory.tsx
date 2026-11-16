import { type ReactNode, useId } from "react";
import { r1 } from "@/components/flora/geometry";
import type { Sky } from "@/hooks/useSky";

export type GlassSpec = {
  left: number;
  right: number;
  axis: number;
  ground: number;
  pavilion: number;
  cornice: number;
  join: number;
  wall: number;
  dome: number;
  wash: number;
  bars: number;
  wingRules: number[];
  hallRules: number[];
};

export const FIGURE_GLASS: GlassSpec = {
  left: 500,
  right: 1060,
  axis: 780,
  ground: 560,
  pavilion: 260,
  cornice: 330,
  join: 372,
  wall: 440,
  dome: 126,
  wash: 0.62,
  bars: 0.42,
  wingRules: [490],
  hallRules: [400, 470],
};

function clamp(min: number, value: number, max: number) {
  return Math.max(min, Math.min(max, value));
}

function rules(ground: number, top: number, step: number) {
  const out: number[] = [];
  for (let y = ground - step; y > top + step * 0.45; y -= step) out.push(r1(y));
  return out;
}

export function bedGlass(left: number, right: number, ground: number, height: number, center?: number): GlassSpec {
  const span = Math.min(right, center ? center * 2 - left : right) - left;
  const pavilion = Math.round(clamp(180, span * 0.4, 280));
  const dome = r1(clamp(36, height * 0.21, pavilion / 2 - 6));
  const cornice = r1(ground - height + 82 + dome);
  const join = r1(cornice + 20);
  const wall = r1(Math.min(ground - 40, join + Math.max(30, height * 0.155)));
  return {
    left,
    right,
    axis: r1(center ?? (left + right) / 2),
    ground,
    pavilion,
    cornice,
    join,
    wall,
    dome,
    wash: 0.55,
    bars: 0.3,
    wingRules: rules(ground, wall, 65),
    hallRules: rules(ground, cornice, 70),
  };
}

export function Conservatory({
  spec,
  lit = false,
  children,
}: {
  spec: GlassSpec;
  lit?: boolean;
  children?: ReactNode;
}) {
  const clip = `glass-${useId().replace(/[^a-zA-Z0-9]/g, "")}`;
  const { left, right, axis, ground: g, pavilion, cornice: c, join, wall, dome } = spec;
  const h0 = r1(axis - pavilion / 2);
  const h1 = r1(axis + pavilion / 2);
  const rx = r1(pavilion / 2 - 6);
  const domeTop = r1(c - dome);
  const lantern = r1(domeTop - 26);
  const wingL = `M${r1(left)} ${g}V${wall}Q${r1(left + 22)} ${r1(join + 9)} ${h0} ${join}`;
  const wingR = `M${r1(right)} ${g}V${wall}Q${r1(right - 22)} ${r1(join + 9)} ${h1} ${join}`;
  const vault = `M${r1(h0 + 6)} ${c}A${rx} ${dome} 0 0 1 ${r1(h1 - 6)} ${c}`;
  const top = Math.min(domeTop, join) - 4;

  const wingBars = (from: number, to: number) => {
    const n = Math.max(2, Math.round(Math.abs(to - from) / 25));
    return Array.from({ length: n - 1 }, (_, i) => `M${r1(from + ((to - from) * (i + 1)) / n)} ${r1(top)}V${g}`).join(
      "",
    );
  };
  const hallN = Math.max(2, Math.round(pavilion / 26));
  const hallBars = Array.from({ length: hallN - 1 }, (_, i) => i + 1)
    .filter((i) => i * 2 !== hallN)
    .map((i) => `M${r1(h0 + (pavilion * i) / hallN)} ${r1(top)}V${g}`)
    .join("");
  const rows = [
    ...spec.wingRules.map((y) => `M${r1(left)} ${y}H${h0}M${h1} ${y}H${r1(right)}`),
    ...spec.hallRules.map((y) => `M${h0} ${y}H${h1}`),
    ...[c - dome * 0.27, c - dome * 0.54].map((y) => `M${h0} ${r1(y)}H${h1}`),
  ].join("");
  const meridians =
    [0.677, 0.339]
      .map((f) => `M${r1(axis - rx * f)} ${c}A${r1(rx * f)} ${dome} 0 0 1 ${r1(axis + rx * f)} ${c}`)
      .join("") + `M${axis} ${domeTop}V${c}`;

  const shapes = (
    <>
      <path d={`${wingL}V${g}Z`} />
      <path d={`${wingR}V${g}Z`} />
      <rect x={h0} y={c} width={pavilion} height={r1(g - c)} />
      <path d={`${vault}Z`} />
    </>
  );

  return (
    <g>
      <defs>
        <clipPath id={clip}>{shapes}</clipPath>
      </defs>
      <g fill="var(--glass-top)" opacity={spec.wash} className="transition-[fill] duration-[2000ms]">
        {shapes}
      </g>
      <rect
        x={axis - 24}
        y={lantern}
        width={48}
        height={28}
        fill={lit ? "var(--butter)" : "var(--glass-top)"}
        opacity={lit ? 0.95 : spec.wash}
      />
      {children}
      <g clipPath={`url(#${clip})`} fill="none" stroke="var(--ink)" strokeWidth={0.7} opacity={spec.bars}>
        <path d={wingBars(left, h0) + wingBars(right, h1) + hallBars} />
        <path d={rows} />
        <path d={meridians} />
      </g>
      <g fill="none" stroke="var(--ink)" strokeWidth={1.5} strokeLinejoin="round">
        <path d={wingL} />
        <path d={wingR} />
        <path d={`M${h0} ${g}V${c}M${h1} ${g}V${c}`} />
        <path d={vault} />
        <rect x={axis - 24} y={lantern} width={48} height={28} />
        <path d={`M${axis - 12} ${lantern}v28M${axis} ${lantern}v28M${axis + 12} ${lantern}v28`} strokeWidth={0.7} />
        <path d={`M${r1(left)} ${wall}H${h0}M${h1} ${wall}H${r1(right)}`} strokeWidth={1.1} />
      </g>
      <path
        d={`M${axis - 30} ${lantern}Q${axis} ${r1(lantern - 32)} ${axis + 30} ${lantern}Z`}
        fill="var(--glass-top)"
        stroke="var(--ink)"
        strokeWidth={1.5}
        strokeLinejoin="round"
      />
      <path d={`M${axis} ${r1(lantern - 16)}V${r1(lantern - 50)}`} stroke="var(--ink)" strokeWidth={1.4} />
      <path
        d={`M${axis} ${r1(lantern - 50)}l20 5.5l-20 5.5Z`}
        fill="var(--vermilion)"
        stroke="var(--ink)"
        strokeWidth={0.8}
      />
      <rect
        x={r1(h0 - 6)}
        y={r1(c - 6)}
        width={pavilion + 12}
        height={10}
        fill="var(--paper-2)"
        stroke="var(--ink)"
        strokeWidth={1.3}
      />
      <g fill="var(--ink)">
        {[left, h0, h1, right].map((x) => (
          <rect key={x} x={r1(x - 7)} y={g - 12} width={14} height={14} />
        ))}
      </g>
    </g>
  );
}

const STARS = [
  [-442, -28, 1.6],
  [-390, 26, 1.2],
  [-300, -46, 1.4],
  [-120, -56, 1.2],
  [-100, 20, 1.5],
  [60, -46, 1.3],
  [70, 84, 1.2],
  [-470, 104, 1.1],
];

export function Astro({
  sky,
  x,
  y,
  label,
  text = 11,
}: {
  sky: Sky;
  x: number;
  y: number;
  label: string;
  text?: number;
}) {
  const night = sky === "night";
  return (
    <g>
      {night ? (
        <>
          <g fill="var(--ink)">
            {STARS.map(([dx, dy, r], i) => (
              <circle key={i} cx={r1(x + dx)} cy={r1(y + dy)} r={r} />
            ))}
          </g>
          <path
            d={`M${x + 5} ${r1(y - 13.08)}A14 14 0 1 0 ${x + 5} ${r1(y + 13.08)}A15 15 0 0 1 ${x + 5} ${r1(y - 13.08)}Z`}
            fill="var(--butter)"
            fillOpacity={0.75}
            stroke="var(--ink)"
            strokeWidth={1.1}
            strokeLinejoin="round"
          />
        </>
      ) : (
        <>
          <circle cx={x} cy={y} r={15} fill="var(--butter)" fillOpacity={0.75} stroke="var(--ink)" strokeWidth={1.1} />
          <path
            d={[0, 45, 90, 135, 180, 225, 270, 315]
              .map((a) => {
                const c = Math.cos((a * Math.PI) / 180);
                const s = Math.sin((a * Math.PI) / 180);
                return `M${r1(x + c * 24)} ${r1(y + s * 24)}L${r1(x + c * 32)} ${r1(y + s * 32)}`;
              })
              .join("")}
            stroke="var(--ink)"
            strokeWidth={1.1}
            strokeLinecap="round"
          />
        </>
      )}
      <text
        x={x}
        y={r1(y + 43 + text)}
        textAnchor="middle"
        className="typewriter"
        fontSize={r1(text)}
        letterSpacing={r1((text * 0.8) / 11)}
        fill="var(--ink-2)"
      >
        {label}
      </text>
    </g>
  );
}
