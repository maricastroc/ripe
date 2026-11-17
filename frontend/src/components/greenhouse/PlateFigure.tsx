import { useId } from "react";
import { type Pt, r1 } from "@/components/flora/geometry";
import type { Sky } from "@/hooks/useSky";
import type { AgentMode } from "@/lib/types";
import { Astro, Conservatory, FIGURE_GLASS } from "./Conservatory";
import { Tuft } from "./PlateSoil";
import { hyphaTone } from "./SoilBand";

export const FIGURE_VIEW = { x: 480, y: 110, width: 600, height: 450, ground: 560 };

const SKY_AT: Record<Sky, [number, number]> = {
  dawn: [548, 236],
  day: [990, 196],
  dusk: [1024, 248],
  night: [990, 190],
};

const LEAFLETS: [number, number, number, number][] = [
  [32, -9, -70, 1.15],
  [32, -9, 46, 1.15],
  [64, -13, -60, 1.2],
  [64, -13, 56, 1.2],
  [95, -12, -50, 1.15],
  [95, -12, 66, 1.15],
  [125, -5, -40, 1.05],
  [125, -5, 76, 1.05],
  [154, 8, -30, 0.9],
  [154, 8, 86, 0.9],
  [182, 25, -22, 0.7],
  [182, 25, 95, 0.7],
  [200, 40, 38, 0.6],
];

const PALM: [number, number][] = [
  [-78, 0.42],
  [-46, 0.44],
  [-14, 0.42],
  [16, 0.36],
];

const FERN: [number, number][] = [
  [-84, 0.26],
  [-48, 0.27],
  [-14, 0.24],
];

type Leaf = [number, number, number, number];

const SEEDLING_LEAVES: Leaf[] = [
  [540, 482, -55, 0.3],
  [540, 482, 55, 0.3],
  [578, 473, -55, 0.45],
  [578, 473, 55, 0.45],
  [616, 463, -50, 0.5],
  [616, 463, 50, 0.5],
  [616, 474, -78, 0.42],
  [616, 474, 78, 0.42],
];

const YOUNG_LEAVES: Leaf[] = [
  [690, 506, -50, 0.9],
  [722, 482, 50, 0.9],
  [700, 534, -76, 0.8],
  [710, 546, 70, 0.75],
  [705, 468, -48, 0.85],
  [706, 456, 50, 0.8],
  [706, 440, 4, 0.9],
];

const FRUITING_LEAVES: Leaf[] = [
  [960, 494, -44, 1],
  [1010, 470, 44, 1],
  [966, 440, -36, 0.95],
  [985, 408, 4, 1],
  [986, 432, 56, 0.9],
  [1008, 518, 60, 0.9],
  [976, 512, -78, 0.8],
  [994, 488, 74, 0.8],
  [980, 546, -70, 0.8],
];

const MARKS: [number, number][] = [
  [566, 330],
  [630, 266],
  [1012, 332],
];

export function PlateFigure({
  sky,
  label,
  marks,
  scale = 1,
}: {
  sky: Sky;
  label: string;
  marks: [string, string, string];
  scale?: number;
}) {
  const uid = useId().replace(/[^a-zA-Z0-9]/g, "");
  const lf = `#${uid}-lf`;
  const frond = `#${uid}-frond`;
  const pot = `#${uid}-pot`;
  const [sx, sy] = SKY_AT[sky];
  const leaves = (list: Leaf[]) =>
    list.map(([x, y, a, s], i) => (
      <use key={i} href={lf} transform={`translate(${x} ${y}) rotate(${a}) scale(${s})`} />
    ));
  const fronds = (list: [number, number][]) =>
    list.flatMap(([a, s], i) => [
      <use key={`r${i}`} href={frond} transform={`rotate(${a}) scale(${s})`} />,
      <use key={`l${i}`} href={frond} transform={`scale(${-s} ${s}) rotate(${a})`} />,
    ]);

  return (
    <svg
      viewBox={`${FIGURE_VIEW.x} ${FIGURE_VIEW.y} ${FIGURE_VIEW.width} ${FIGURE_VIEW.height}`}
      preserveAspectRatio="xMidYMax meet"
      className="pointer-events-none absolute inset-0 h-full w-full overflow-visible"
      aria-hidden
    >
      <defs>
        <path id={`${uid}-lf`} d="M0 0C-9-8-10-22 0-31C10-22 9-8 0 0Z" />
        <path id={`${uid}-lt`} d="M0 0Q20-8 40 0Q20 6 0 0Z" />
        <g id={`${uid}-frond`}>
          <path d="M0 0Q110-40 200 40" fill="none" strokeWidth={2.4} />
          {LEAFLETS.map(([x, y, a, s], i) => (
            <use key={i} href={`#${uid}-lt`} transform={`translate(${x} ${y}) rotate(${a}) scale(${s})`} />
          ))}
        </g>
        <g id={`${uid}-pot`}>
          <path d="M-20 0L-25-34H25L20 0Z" fill="var(--plate-pot)" stroke="var(--ink)" strokeWidth={1.6} />
          <rect
            x={-28}
            y={-43}
            width={56}
            height={10}
            rx={2}
            fill="var(--plate-pot-rim)"
            stroke="var(--ink)"
            strokeWidth={1.6}
          />
        </g>
      </defs>

      <path d="M780 110V560" stroke="var(--ink)" strokeWidth={0.6} strokeDasharray="12 4 2 4" opacity={0.35} />
      <Astro sky={sky} x={sx} y={sy} label={label} text={Math.min(18, 11 / Math.max(scale, 0.1))} />

      <Conservatory spec={FIGURE_GLASS} lit={sky === "night"}>
        <path
          d="M780 560C776 500 784 440 780 384"
          fill="none"
          stroke="var(--ink)"
          strokeWidth={8.5}
          strokeLinecap="round"
        />
        <path
          d="M780 560C776 500 784 440 780 384"
          fill="none"
          stroke="var(--wood)"
          strokeWidth={6}
          strokeLinecap="round"
        />
        <path
          d="M776 530H784M776 500H784M777 470H785M777 440H785M776 410H784"
          stroke="var(--ink)"
          strokeWidth={0.8}
          opacity={0.7}
        />
        <g transform="translate(780 382)" fill="var(--leaf)" stroke="var(--ink)" strokeWidth={1.5}>
          {fronds(PALM)}
        </g>

        <rect x={514} y={508} width={124} height={6} fill="var(--wood-3)" stroke="var(--ink)" strokeWidth={0.9} />
        <path d="M522 514V560M630 514V560M522 540H630" stroke="var(--ink)" strokeWidth={1} fill="none" />
        {[540, 578, 616].map((x) => (
          <use key={x} href={pot} transform={`translate(${x} 508) scale(0.5)`} />
        ))}
        <path d="M540 487V481M578 487V472M616 487V462" stroke="var(--plate-stem)" strokeWidth={1.4} fill="none" />
        <g fill="var(--leaf-2)" stroke="var(--ink)" strokeWidth={0.6}>
          {leaves(SEEDLING_LEAVES)}
        </g>
        <path
          d="M527 487V470C527 452 553 452 553 470V487Z"
          fill="white"
          fillOpacity={0.35}
          stroke="var(--ink)"
          strokeWidth={0.8}
        />
        <circle cx={540} cy={455} r={2.4} fill="var(--paper)" stroke="var(--ink)" strokeWidth={0.8} />

        <path
          d="M706 560C704 530 709 500 705 468C703 456 707 448 706 440M706 524C698 518 694 514 690 506M705 500C714 494 718 490 722 482"
          fill="none"
          stroke="var(--plate-stem)"
          strokeWidth={2}
          strokeLinecap="round"
        />
        <g fill="var(--plate-leaf)" stroke="var(--ink)" strokeWidth={0.6}>
          {leaves(YOUNG_LEAVES)}
        </g>

        <use href={pot} transform="translate(852 560) scale(0.7)" />
        <g transform="translate(852 530)" fill="var(--plate-leaf-2)" stroke="var(--ink)" strokeWidth={2}>
          {fronds(FERN)}
        </g>

        <path
          d="M985 560C983 520 989 480 984 440C982 425 986 416 985 408M985 520C972 512 966 506 960 494M985 496C998 488 1004 482 1010 470M984 462C974 456 970 450 966 440M985 536C996 530 1002 526 1008 518"
          fill="none"
          stroke="var(--plate-stem)"
          strokeWidth={2.2}
          strokeLinecap="round"
        />
        <g fill="var(--leaf)" stroke="var(--ink)" strokeWidth={0.6}>
          {leaves(FRUITING_LEAVES)}
        </g>
        <g fill="var(--vermilion)" stroke="var(--plate-fruit-line)" strokeWidth={0.8}>
          <circle cx={958} cy={504} r={6.5} />
          <circle cx={1012} cy={481} r={6.5} />
          <circle cx={965} cy={451} r={6} />
          <circle cx={1009} cy={529} r={6} />
        </g>
      </Conservatory>

      <path d="M568 341L578 470M636 276L702 446M1012 343L1010 470" stroke="var(--ink)" strokeWidth={0.8} fill="none" />
      <g
        className="ink"
        fill="var(--cobalt)"
        textAnchor="middle"
        fontSize={r1(Math.min(24, 15 / Math.max(scale, 0.1)))}
      >
        {MARKS.map(([x, y], i) => (
          <text key={i} x={x} y={y + 4}>
            {marks[i]}
          </text>
        ))}
      </g>
      <Tuft x={448} y={560} />
      <Tuft x={476} y={560} />
    </svg>
  );
}

const ROOTS = [
  {
    d: "M780 562C778 590 786 610 780 640M780 568C760 584 748 600 730 612M780 568C800 586 814 598 832 612M778 590C764 606 762 622 752 640M782 590C796 608 800 624 810 642",
    w: 1.5,
  },
  { d: "M706 562C704 580 710 596 706 616M706 568C694 580 688 592 678 602M706 568C718 580 724 590 734 598", w: 1.2 },
  {
    d: "M985 562C983 584 990 604 985 628M985 568C970 582 962 596 950 606M985 568C1000 584 1010 594 1022 604M984 590C974 604 974 616 966 630",
    w: 1.3,
  },
  { d: "M852 562C851 574 855 584 852 596M852 566C844 574 842 582 836 588", w: 1 },
];

const STRANDS = [
  [520, 800, 580, 770, 640, 800, 700, 772, 740, 754, 760, 700, 778, 646],
  [706, 620, 716, 660, 700, 690, 720, 720, 740, 750, 800, 740, 840, 760, 900, 790, 960, 760, 1010, 780],
  [780, 646, 800, 690, 860, 690, 900, 716, 940, 742, 990, 720, 1040, 742],
];

const TO_HOLE = [985, 632, 990, 670, 1030, 680, 1060, 700, 1100, 726];

const LEFTWARD = [520, 800, 460, 820, 380, 800, 300, 824, 220, 846, 140, 826, 40, 842];

const SPRIGS = [
  [840, 760, 830, 800, 860, 830, 850, 860],
  [1010, 780, 1020, 810, 1000, 836, 1014, 860],
  [700, 772, 690, 810, 720, 836, 706, 860],
  [900, 716, 910, 690, 896, 668, 906, 644],
  [720, 720, 690, 730, 664, 722, 640, 736],
  [1060, 700, 1070, 730, 1056, 752, 1068, 776],
  [600, 782, 596, 810, 612, 834, 604, 860],
  [1040, 742, 1056, 760, 1050, 790, 1066, 812],
];

const NODES: [number, number, number][] = [
  [520, 800, 2.4],
  [700, 772, 2.6],
  [778, 646, 2.4],
  [706, 620, 2.2],
  [720, 720, 2.4],
  [840, 760, 2.6],
  [1010, 780, 2.4],
  [985, 632, 2.2],
  [1060, 700, 2.6],
  [900, 716, 2.4],
  [1040, 742, 2.2],
];

function curve(points: Pt[]) {
  let d = `M${r1(points[0].x)} ${r1(points[0].y)}`;
  for (let i = 1; i + 2 < points.length; i += 3) {
    const [a, b, c] = [points[i], points[i + 1], points[i + 2]];
    d += `C${r1(a.x)} ${r1(a.y)} ${r1(b.x)} ${r1(b.y)} ${r1(c.x)} ${r1(c.y)}`;
  }
  return d;
}

function pairs(list: number[], at: (x: number, y: number) => Pt) {
  const out: Pt[] = [];
  for (let i = 0; i + 1 < list.length; i += 2) out.push(at(list[i], list[i + 1]));
  return out;
}

type Underground = {
  ox: number;
  scale: number;
  height: number;
  hole: Pt;
  textBottom: number;
  drawHole?: boolean;
  flat?: boolean;
  mode: AgentMode;
  paused: boolean;
};

export function FigureUnderground({
  ox,
  scale,
  height,
  hole,
  textBottom,
  drawHole = false,
  flat = false,
  mode,
  paused,
}: Underground) {
  const uid = useId().replace(/[^a-zA-Z0-9]/g, "");
  const tone = hyphaTone(mode, paused);
  const ky = Math.max(scale * (flat ? 0.2 : 0.85), Math.min(scale * 1.35, (height - 2) / 300));
  const floor = height - 4;
  const fx = (x: number) => ox + x * scale;
  const at = (x: number, y: number): Pt => ({ x: fx(x), y: Math.min(floor, (y - 560) * ky) });

  const leftEnd = fx(520);
  const lift = Math.max(0, textBottom + 16 - (800 - 560) * ky);
  const leftward =
    textBottom + 22 < floor
      ? pairs(LEFTWARD, (x, y) => {
          const share = x >= 520 ? 0 : x >= 460 ? 0.5 : 1;
          return {
            x: x >= 520 ? fx(x) : 6 + ((x - 40) / 480) * (leftEnd - 6),
            y: Math.min(floor, (y - 560) * ky + lift * share),
          };
        })
      : null;
  const leftSprig = leftward
    ? (() => {
        const base = leftward[3];
        return curve([
          base,
          { x: base.x + 10, y: base.y + 16 },
          { x: base.x - 4, y: base.y + 26 },
          { x: base.x + 4, y: floor },
        ]);
      })()
    : null;

  const holeEnd = { x: hole.x - 10, y: hole.y + 30 };
  const toHole = curve([...pairs(TO_HOLE, at), { x: hole.x - 30, y: hole.y + 66 }, holeEnd]);
  const strands = [...STRANDS.map((s) => curve(pairs(s, at))), toHole, ...(leftward ? [curve(leftward)] : [])];
  const sprigs = [...SPRIGS.map((s) => curve(pairs(s, at))), ...(leftSprig ? [leftSprig] : [])];
  const nodes: [Pt, number][] = [
    ...NODES.map(([x, y, r]): [Pt, number] => [at(x, y), r]),
    [holeEnd, 2.8],
    ...(leftward ? [[leftward[3], 2.2] as [Pt, number]] : []),
  ];

  const draw = (w: number) => (
    <>
      <g strokeWidth={1.3 * w} opacity={0.9}>
        {strands.map((d, i) => (
          <path key={i} d={d} />
        ))}
      </g>
      <g strokeWidth={0.8 * w} opacity={0.6}>
        {sprigs.map((d, i) => (
          <path key={i} d={d} />
        ))}
      </g>
    </>
  );

  return (
    <g>
      <defs>
        <filter id={`${uid}-glow`} x="-20%" y="-40%" width="140%" height="180%">
          <feGaussianBlur stdDeviation={4.5} />
        </filter>
      </defs>
      <g
        fill="none"
        stroke="var(--root)"
        strokeLinecap="round"
        transform={`translate(${r1(ox)} ${r1(-560 * scale)}) scale(${Math.round(scale * 1000) / 1000})`}
      >
        {ROOTS.map((r, i) => (
          <path key={i} d={r.d} strokeWidth={r.w} />
        ))}
      </g>
      {tone.glow && (
        <g
          fill="none"
          stroke="var(--myc-glow)"
          strokeLinecap="round"
          filter={`url(#${uid}-glow)`}
          className="myc-breathe"
        >
          {draw(2.2)}
        </g>
      )}
      <g
        fill="none"
        stroke={tone.color}
        strokeLinecap="round"
        opacity={tone.opacity}
        strokeDasharray={tone.dashed ? "3 5" : undefined}
      >
        {draw(1)}
      </g>
      <g fill={tone.color} opacity={tone.opacity}>
        {nodes.map(([p, r], i) => (
          <circle key={i} cx={r1(p.x)} cy={r1(p.y)} r={r} />
        ))}
      </g>
      {drawHole && (
        <g>
          <ellipse cx={r1(hole.x)} cy={r1(hole.y)} rx={20} ry={12} fill="var(--plate-hollow)" />
          <ellipse
            cx={r1(hole.x)}
            cy={r1(hole.y + 1)}
            rx={7}
            ry={4.5}
            fill="none"
            stroke="var(--paper)"
            strokeWidth={1.1}
            strokeDasharray="2.5 2.5"
            opacity={0.9}
          />
        </g>
      )}
    </g>
  );
}
