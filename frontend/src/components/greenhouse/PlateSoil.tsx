import { type ReactNode, useId, useMemo } from "react";
import { type Pt, openSmooth, r1, rand } from "@/components/flora/geometry";

type Props = {
  width: number;
  height: number;
  salt: number;
  strata?: [number, number];
  edgeAbove?: ReactNode;
  children?: ReactNode;
};

function groundEdge(width: number, salt: number): Pt[] {
  const count = Math.max(3, Math.round(width / 120)) + 1;
  return Array.from({ length: count }, (_, i) => ({
    x: (i / (count - 1)) * width,
    y: r1((i % 2 ? 1 : -1) * (2.2 + rand(salt, i + 9000) * 2.6)),
  }));
}

function wave(width: number, y: number, amp: number, salt: number) {
  const count = Math.max(3, Math.round(width / 220)) + 1;
  return Array.from({ length: count }, (_, i) => ({
    x: (i / (count - 1)) * width,
    y: r1(y + (rand(salt, i + 300) - 0.5) * 2 * amp),
  }));
}

const STRATA: [number, number] = [0.43, 0.83];

export function PlateSoil({ width, height, salt, strata = STRATA, edgeAbove, children }: Props) {
  const uid = useId().replace(/[^a-zA-Z0-9]/g, "");
  const scene = useMemo(() => {
    const edge = groundEdge(width, salt);
    const lower = wave(width, 26, 5, salt + 1).reverse();
    const crest = openSmooth(edge);
    const body = `${crest} L ${r1(width)} ${r1(height)} L 0 ${r1(height)} Z`;
    const band = `${crest} L ${r1(lower[0].x)} ${r1(lower[0].y)} ${openSmooth(lower).replace(/^M/, "L")} Z`;
    const lines = strata.map((f, i) => openSmooth(wave(width, height * f, 7, salt + 11 + i)));
    const pebbles = Array.from({ length: Math.max(4, Math.round(width / 150)) }, (_, i) => ({
      x: r1(10 + rand(salt, i + 4000) * (width - 20)),
      y: r1(40 + rand(salt, i + 5000) * Math.max(10, height - 54)),
      rx: r1(4.5 + rand(salt, i + 6000) * 2.5),
      ry: r1(3 + rand(salt, i + 7000) * 1.5),
    }));
    return { crest, body, band, lines, pebbles };
  }, [width, height, salt, strata]);

  return (
    <svg
      width={r1(width)}
      height={r1(height)}
      className="pointer-events-none absolute inset-0 block overflow-visible"
      aria-hidden
    >
      <defs>
        <linearGradient id={`${uid}-soil`} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="var(--plate-soil-0)" />
          <stop offset="0.45" stopColor="var(--plate-soil-1)" />
          <stop offset="1" stopColor="var(--soil-3)" />
        </linearGradient>
      </defs>
      <path d={scene.body} fill={`url(#${uid}-soil)`} />
      <path d={scene.band} fill="var(--plate-topsoil)" opacity={0.75} />
      <g fill="none" stroke="var(--plate-hollow)" strokeWidth={1} opacity={0.55} strokeDasharray="14 6">
        {scene.lines.map((d, i) => (
          <path key={i} d={d} />
        ))}
      </g>
      <g fill="var(--pebble)" opacity={0.5}>
        {scene.pebbles.map((p, i) => (
          <ellipse key={i} cx={p.x} cy={p.y} rx={p.rx} ry={p.ry} />
        ))}
      </g>
      {children}
      <path d={scene.crest} fill="none" stroke="var(--ink)" strokeWidth={1.7} />
      {edgeAbove}
    </svg>
  );
}

export function Tuft({ x, y = 0 }: { x: number; y?: number }) {
  return (
    <path
      d={`M${x} ${y}l-3-9M${x} ${y}l1-11M${x} ${y}l4-8`}
      stroke="var(--plate-stem)"
      strokeWidth={1.3}
      strokeLinecap="round"
      fill="none"
    />
  );
}
