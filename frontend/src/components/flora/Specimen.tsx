"use client";

import { AnimatePresence, motion, useReducedMotion } from "motion/react";
import { useEffect, useId, useMemo, useState } from "react";
import type { PlantMood, WorkFocus } from "@/lib/presence";
import { type Anatomy, type BranchSpec, type LeafSpec, type SpecimenState, computeAnatomy } from "./anatomy";
import { type Pt, closedSmooth, hash, mixHex, openSmooth, polar, r1, rand, tapered } from "./geometry";
import { budPaths, ellipsePoints, fruitBody, leafPaths, petalPaths } from "./shapes";
import type { Species } from "./species";
import { SPECIES } from "./species";

export type SpecimenFocus = WorkFocus;

type Props = {
  state: SpecimenState;
  mood: PlantMood;
  focus?: SpecimenFocus;
  variant: "bed" | "plate";
  highlight?: string | null;
  onHover?: (key: string | null) => void;
  onSelect?: (key: string) => void;
  callouts?: boolean;
  entrance?: boolean;
  dropFruit?: boolean;
  className?: string;
  title?: string;
};

const grow = { duration: 1.3, ease: [0.22, 1, 0.36, 1] as const };
const INK = "var(--ink-line)";

type From = <T>(value: T) => T | false;

function ripeColor(species: Species, ripeness: number) {
  const f = species.fruit;
  return ripeness < 0.5 ? mixHex(f.unripe, f.mid, ripeness * 2) : mixHex(f.mid, f.ripe, (ripeness - 0.5) * 2);
}

function Leaf({
  spec,
  species,
  from,
  delay = 0,
  lit,
  cotyledon,
}: {
  spec: LeafSpec;
  species: Species;
  from: From;
  delay?: number;
  lit?: boolean;
  cotyledon?: boolean;
}) {
  const form = cotyledon ? SPECIES.seedling.leaf : species.leaf;
  const full = leafPaths(form, spec.base, spec.angle, spec.size, spec.mirror);
  const tiny = leafPaths(form, spec.base, spec.angle, spec.size * 0.04, spec.mirror);
  const [light, mid, dark] = cotyledon ? SPECIES.seedling.leafColors : species.leafColors;
  const green = mixHex(light, mid, 0.25 + spec.tone * 0.6);
  const fill = spec.autumn ? mixHex(green, "#c79a3e", 0.42) : green;
  const transition = { ...grow, delay };
  return (
    <g>
      <motion.path
        initial={from({ d: tiny.body })}
        animate={{ d: full.body }}
        transition={transition}
        style={{
          fill,
          stroke: lit ? "var(--cobalt)" : INK,
          strokeWidth: lit ? 1.6 : 0.8,
          strokeLinejoin: "round",
          transition: "stroke 0.25s ease",
        }}
      />
      <motion.path
        initial={from({ d: tiny.shade })}
        animate={{ d: full.shade }}
        transition={transition}
        style={{ fill: dark, opacity: 0.22, pointerEvents: "none" }}
      />
      <motion.path
        initial={from({ d: tiny.veins, opacity: 0 })}
        animate={{ d: full.veins, opacity: 0.55 }}
        transition={{ ...transition, delay: delay + 0.15 }}
        style={{ fill: "none", stroke: dark, strokeWidth: 0.55, strokeLinecap: "round", pointerEvents: "none" }}
      />
    </g>
  );
}

function Bud({
  pos,
  angle,
  size,
  tone,
  from,
}: {
  pos: Pt;
  angle: number;
  size: number;
  tone: "pending" | "blocked" | "working";
  from: From;
}) {
  const full = budPaths(pos, angle, size);
  const tiny = budPaths(pos, angle, size * 0.05);
  const fill = tone === "blocked" ? "#c9a24a" : tone === "working" ? "#a8c77c" : "#9fbd7a";
  const scale = tone === "blocked" ? "#8f6f2a" : "#4f7a3c";
  return (
    <g>
      <motion.path
        initial={from({ d: tiny.body })}
        animate={{ d: full.body }}
        transition={grow}
        style={{ fill, stroke: INK, strokeWidth: 0.7, transition: "fill 0.8s ease" }}
      />
      <motion.path
        initial={from({ d: tiny.scale })}
        animate={{ d: full.scale }}
        transition={grow}
        style={{ fill: scale, opacity: 0.35 }}
      />
    </g>
  );
}

function Flower({
  species,
  pos,
  angle,
  size,
  from,
}: {
  species: Species;
  pos: Pt;
  angle: number;
  size: number;
  from: From;
}) {
  const [petal, blush, center] = species.flowerColors;
  const entry = { initial: from({ scale: 0.1, opacity: 0 }), animate: { scale: 1, opacity: 1 }, transition: grow };
  if (species.flower === "bell") {
    const sepals = petalPaths("sepal", pos, 4, angle + 45, size * 0.75);
    const bell = closedSmooth(ellipsePoints(pos.x, pos.y - size * 0.35, size * 0.42, size * 0.55, 16));
    const mouth = polar(pos, angle, size * 0.85);
    return (
      <motion.g {...entry} style={{ transformBox: "fill-box", transformOrigin: "center" }}>
        {sepals.map((d, i) => (
          <path key={i} d={d} style={{ fill: "#7e8f4a", stroke: INK, strokeWidth: 0.6 }} />
        ))}
        <path d={bell} style={{ fill: petal, stroke: INK, strokeWidth: 0.7 }} />
        <path
          d={`M ${r1(mouth.x - size * 0.3)} ${r1(mouth.y + 2)} Q ${r1(mouth.x)} ${r1(mouth.y - 3)} ${r1(mouth.x + size * 0.3)} ${r1(mouth.y + 2)}`}
          style={{ fill: "none", stroke: blush, strokeWidth: 1.4 }}
        />
      </motion.g>
    );
  }
  const kind = species.flower === "star" ? "star" : "round";
  const petals = petalPaths(kind, pos, 5, angle + 18, size);
  const filaments = Array.from({ length: 9 }, (_, i) => polar(pos, (360 / 9) * i + angle, size * 0.38));
  return (
    <motion.g {...entry} style={{ transformBox: "fill-box", transformOrigin: "center" }}>
      {petals.map((d, i) => (
        <path key={i} d={d} style={{ fill: petal, stroke: INK, strokeWidth: 0.6 }} />
      ))}
      <circle cx={r1(pos.x)} cy={r1(pos.y)} r={r1(size * 0.32)} style={{ fill: blush, opacity: 0.85 }} />
      {filaments.map((f, i) => (
        <g key={i}>
          <line x1={r1(pos.x)} y1={r1(pos.y)} x2={r1(f.x)} y2={r1(f.y)} style={{ stroke: center, strokeWidth: 0.5 }} />
          <circle cx={r1(f.x)} cy={r1(f.y)} r={0.9} style={{ fill: center }} />
        </g>
      ))}
      <circle cx={r1(pos.x)} cy={r1(pos.y)} r={r1(size * 0.12)} style={{ fill: "#9c8a3a" }} />
    </motion.g>
  );
}

function FruitShape({
  species,
  pos,
  size,
  ripeness,
  uid,
  salt,
  from,
  sway = false,
  tilt = 0,
}: {
  species: Species;
  pos: Pt;
  size: number;
  ripeness: number;
  uid: string;
  salt: number;
  from: From;
  sway?: boolean;
  tilt?: number;
}) {
  const color = ripeColor(species, ripeness);
  const ripe = ripeness >= 0.88;
  const swing = 1.6 + rand(salt, 7) * 1.4;
  const swingDur = (7 + rand(salt, 8) * 4).toFixed(2);
  const swingBegin = (rand(salt, 9) * 3).toFixed(2);
  const deep = ripeness > 0.7 ? species.fruit.deep : mixHex(species.fruit.unripe, "#3e5a2e", 0.35);
  const grad = `${uid}-fruit-${salt}`;
  const shape = species.fruit.shape;
  const body = fruitBody(shape, pos, size);
  const tiny = fruitBody(shape, pos, size * 0.08);
  const highlight = closedSmooth(ellipsePoints(pos.x - size * 0.34, pos.y + size * 0.55, size * 0.22, size * 0.13, 10));
  return (
    <g transform={tilt ? `rotate(${r1(tilt)} ${r1(pos.x)} ${r1(pos.y)})` : undefined}>
      {sway && ripe && (
        <animateTransform
          attributeName="transform"
          type="rotate"
          additive="sum"
          values={`0 ${r1(pos.x)} ${r1(pos.y)}; ${r1(swing)} ${r1(pos.x)} ${r1(pos.y)}; 0 ${r1(pos.x)} ${r1(pos.y)}; ${r1(-swing * 0.7)} ${r1(pos.x)} ${r1(pos.y)}; 0 ${r1(pos.x)} ${r1(pos.y)}`}
          dur={`${swingDur}s`}
          begin={`${swingBegin}s`}
          repeatCount="indefinite"
          calcMode="spline"
          keyTimes="0; 0.25; 0.5; 0.75; 1"
          keySplines="0.45 0 0.55 1; 0.45 0 0.55 1; 0.45 0 0.55 1; 0.45 0 0.55 1"
        />
      )}
      <defs>
        <radialGradient id={grad} cx="0.36" cy="0.32" r="0.75">
          <motion.stop
            initial={false}
            offset="0"
            animate={{ stopColor: mixHex(color, "#ffffff", 0.28) }}
            transition={grow}
          />
          <motion.stop initial={false} offset="0.55" animate={{ stopColor: color }} transition={grow} />
          <motion.stop initial={false} offset="1" animate={{ stopColor: deep }} transition={grow} />
        </radialGradient>
      </defs>
      <motion.path
        initial={from({ d: tiny })}
        animate={{ d: body }}
        transition={{ ...grow, duration: 1.8 }}
        style={{ fill: `url(#${grad})`, stroke: INK, strokeWidth: 0.85 }}
      />
      {shape === "oval" && (
        <>
          <path
            d={`M ${r1(pos.x + size * 0.12)} ${r1(pos.y + size * 0.15)} Q ${r1(pos.x + size * 0.42)} ${r1(pos.y + size)} ${r1(pos.x + size * 0.05)} ${r1(pos.y + size * 1.9)}`}
            style={{ fill: "none", stroke: species.fruit.deep, strokeWidth: 0.8, opacity: 0.45 }}
          />
          <path d={body} style={{ fill: "#e9e3f2", opacity: ripe ? 0.26 : ripeness > 0.6 ? 0.16 : 0.06 }} />
        </>
      )}
      {shape === "ovoid" &&
        Array.from({ length: 12 }, (_, i) => (
          <circle
            key={i}
            cx={r1(pos.x + (rand(salt, i) - 0.5) * size * 1.1)}
            cy={r1(pos.y + size * 0.4 + rand(salt, i + 20) * size * 1.4)}
            r={0.55}
            style={{ fill: species.fruit.deep, opacity: 0.35 }}
          />
        ))}
      {shape === "tear" && (
        <>
          <path
            d={closedSmooth(ellipsePoints(pos.x, pos.y + size * 0.45, size * 0.38, size * 0.42, 10))}
            style={{ fill: "#9fb283", opacity: ripeness > 0.6 ? 0.35 : 0.2 }}
          />
          <circle cx={r1(pos.x)} cy={r1(pos.y + size * 2.02)} r={1.2} style={{ fill: species.fruit.deep }} />
          {ripe && (
            <path
              d={`M ${r1(pos.x - size * 0.18)} ${r1(pos.y + size * 1.72)} l ${r1(size * 0.08)} ${r1(size * 0.16)} M ${r1(pos.x + size * 0.12)} ${r1(pos.y + size * 1.66)} l ${r1(-size * 0.04)} ${r1(size * 0.2)}`}
              style={{ fill: "none", stroke: "#f2d9c8", strokeWidth: 0.7, strokeLinecap: "round", opacity: 0.8 }}
            />
          )}
        </>
      )}
      {shape === "squat" && ripe && (
        <path
          d={closedSmooth(ellipsePoints(pos.x + size * 0.38, pos.y + size * 1.05, size * 0.5, size * 0.42, 12))}
          style={{ fill: species.fruit.deep, opacity: 0.2 }}
        />
      )}
      <path d={highlight} style={{ fill: "#ffffff", opacity: 0.42 }} />
      {(shape === "squat" || shape === "ovoid") &&
        petalPaths("sepal", { x: pos.x, y: pos.y + 1 }, 4, 45 + salt * 7, size * (shape === "squat" ? 0.55 : 0.3)).map(
          (d, i) => <path key={i} d={d} style={{ fill: "#5a6b32", stroke: INK, strokeWidth: 0.5 }} />,
        )}
    </g>
  );
}

function Seed({ anatomy, glow, from }: { anatomy: Anatomy; glow: boolean; from: From }) {
  const { pos, angle, open } = anatomy.seed;
  const body = closedSmooth(ellipsePoints(0, 0, 7, 4.6, 14));
  return (
    <g transform={`translate(${r1(pos.x)} ${r1(pos.y)}) rotate(${r1(angle)})`}>
      {glow && <circle r={16} className="glow-breathe" style={{ fill: "var(--myc-glow)" }} />}
      {open ? (
        <>
          <path
            d={body}
            transform="translate(-2 2) rotate(-18)"
            style={{ fill: "#7a5a3c", stroke: INK, strokeWidth: 0.6, opacity: 0.85 }}
          />
          <path
            d="M -6 0 Q 0 -5 6 0"
            transform="translate(2 -3) rotate(14)"
            style={{ fill: "none", stroke: "#5b4128", strokeWidth: 1.6 }}
          />
        </>
      ) : (
        <motion.g
          initial={from({ y: -40, opacity: 0 })}
          animate={{ y: 0, opacity: 1 }}
          transition={{ type: "spring", stiffness: 120, damping: 12 }}
        >
          <path d={body} style={{ fill: "#8a6646", stroke: INK, strokeWidth: 0.7 }} />
          <path d="M -5 -0.5 Q 0 -2.5 5 -0.5" style={{ fill: "none", stroke: "#5b4128", strokeWidth: 0.6 }} />
          {glow && (
            <path
              d="M 4 -3 q 3 -4 2 -9"
              style={{ fill: "none", stroke: "#cfe6a0", strokeWidth: 1.4, strokeLinecap: "round" }}
            />
          )}
        </motion.g>
      )}
    </g>
  );
}

function Hit({
  id,
  label,
  at,
  r = 16,
  onHover,
  onSelect,
  children,
}: {
  id: string;
  label: string;
  at: Pt;
  r?: number;
  onHover?: (key: string | null) => void;
  onSelect?: (key: string) => void;
  children?: React.ReactNode;
}) {
  if (!onSelect) return <>{children}</>;
  return (
    <g
      role="button"
      tabIndex={0}
      aria-label={label}
      onClick={(e) => {
        e.stopPropagation();
        e.preventDefault();
        onSelect(id);
      }}
      onKeyDown={(e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          onSelect(id);
        }
      }}
      onMouseEnter={() => onHover?.(id)}
      onMouseLeave={() => onHover?.(null)}
      onFocus={() => onHover?.(id)}
      onBlur={() => onHover?.(null)}
      style={{ cursor: "pointer", outline: "none" }}
    >
      {children}
      <circle cx={r1(at.x)} cy={r1(at.y)} r={r1(r)} style={{ fill: "transparent" }} />
    </g>
  );
}

function branchLabel(b: BranchSpec) {
  const what =
    b.status === "done"
      ? "ready to read"
      : b.status === "working"
        ? "being written"
        : b.status === "blocked"
          ? "waiting on you"
          : "not started";
  return `Step ${b.index}${b.title ? `: ${b.title}` : ""}, ${what}`;
}

function Callout({ at, side, text, lit }: { at: Pt; side: number; text: string; lit: boolean }) {
  const mark = { x: at.x + side * 22, y: at.y - 10 };
  return (
    <g style={{ pointerEvents: "none" }}>
      <path
        d={`M ${r1(at.x)} ${r1(at.y)} Q ${r1(at.x + side * 12)} ${r1(at.y - 2)} ${r1(mark.x - side * 7)} ${r1(mark.y)}`}
        style={{ fill: "none", stroke: "var(--cobalt)", strokeWidth: 0.7, opacity: 0.7 }}
      />
      <circle
        cx={r1(mark.x)}
        cy={r1(mark.y)}
        r={7.5}
        style={{ fill: lit ? "var(--cobalt)" : "var(--paper)", stroke: "var(--cobalt)", strokeWidth: 0.9 }}
      />
      <text
        x={r1(mark.x)}
        y={r1(mark.y + 3.4)}
        textAnchor="middle"
        style={{
          fill: lit ? "var(--paper)" : "var(--cobalt)",
          fontSize: 9.5,
          fontStyle: "italic",
          fontFamily: "var(--font-fraunces), serif",
        }}
      >
        {text}
      </text>
    </g>
  );
}

function Soil({ uid, seed }: { uid: string; seed: number }) {
  const pebbles = Array.from({ length: 14 }, (_, i) => ({
    x: -160 + rand(seed, i) * 320,
    y: 22 + rand(seed, i + 40) * 128,
    rx: 2.5 + rand(seed, i + 80) * 6,
    ry: 1.8 + rand(seed, i + 120) * 3.5,
    a: rand(seed, i + 160) * 180,
  }));
  const specks = Array.from({ length: 70 }, (_, i) => ({
    x: -168 + rand(seed, i + 300) * 336,
    y: 6 + rand(seed, i + 500) * 150,
    r: 0.5 + rand(seed, i + 700) * 1.1,
  }));
  const tufts = Array.from({ length: 16 }, (_, i) => -158 + i * 21 + rand(seed, i + 900) * 10);
  return (
    <g>
      <defs>
        <linearGradient id={`${uid}-soil`} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#5a3d2a" />
          <stop offset="0.25" stopColor="#45301f" />
          <stop offset="0.65" stopColor="#36251a" />
          <stop offset="1" stopColor="#6b3f28" />
        </linearGradient>
      </defs>
      <path
        d="M -170 0 Q -120 -4 -70 1 T 30 0 T 170 -1 L 170 158 Q 120 166 60 160 T -60 162 T -170 156 Z"
        style={{ fill: `url(#${uid}-soil)` }}
      />
      <path
        d="M -170 52 Q -80 46 0 54 T 170 50"
        style={{ fill: "none", stroke: "#2a1d15", strokeWidth: 0.6, opacity: 0.45 }}
      />
      <path
        d="M -170 104 Q -60 112 40 102 T 170 108"
        style={{ fill: "none", stroke: "#8a5134", strokeWidth: 0.8, opacity: 0.35 }}
      />
      {specks.map((s, i) => (
        <circle
          key={i}
          cx={r1(s.x)}
          cy={r1(s.y)}
          r={r1(s.r)}
          style={{ fill: i % 3 ? "#7a5a40" : "#c9a879", opacity: 0.55 }}
        />
      ))}
      {pebbles.map((p, i) => (
        <g key={i} transform={`rotate(${r1(p.a)} ${r1(p.x)} ${r1(p.y)})`}>
          <ellipse
            cx={r1(p.x)}
            cy={r1(p.y)}
            rx={r1(p.rx)}
            ry={r1(p.ry)}
            style={{ fill: "#8c8172", stroke: "#2a1d15", strokeWidth: 0.5 }}
          />
          <ellipse
            cx={r1(p.x - p.rx * 0.3)}
            cy={r1(p.y - p.ry * 0.35)}
            rx={r1(p.rx * 0.35)}
            ry={r1(p.ry * 0.25)}
            style={{ fill: "#c4b9a6", opacity: 0.6 }}
          />
        </g>
      ))}
      {tufts.map((x, i) => (
        <path
          key={i}
          d={`M ${r1(x)} 0 q -2 -7 -5 -9 M ${r1(x)} 0 q 0 -8 2 -12 M ${r1(x)} 0 q 2 -6 6 -8`}
          style={{ fill: "none", stroke: "#5f7f45", strokeWidth: 0.9, strokeLinecap: "round" }}
        />
      ))}
    </g>
  );
}

export function Specimen({
  state,
  mood,
  focus = null,
  variant,
  highlight = null,
  onHover,
  onSelect,
  callouts = false,
  entrance = false,
  dropFruit = false,
  className,
  title,
}: Props) {
  const uid = useId().replace(/[^a-zA-Z0-9]/g, "");
  const reduce = useReducedMotion();
  const [settled, setSettled] = useState(false);
  useEffect(() => {
    const frame = requestAnimationFrame(() => setSettled(true));
    return () => cancelAnimationFrame(frame);
  }, []);
  const animateEntry = entrance || settled;
  const from: From = (value) => (animateEntry ? value : false);

  const anatomy = useMemo(() => computeAnatomy(state), [state]);
  const { species } = anatomy;
  const seedNum = hash(state.seed);
  const plate = variant === "plate";
  const working = mood === "working";
  const sway = !reduce && mood !== "dormant" && mood !== "stalled" && anatomy.stageIndex > 0;

  const focusPoint: Pt | null = !working
    ? null
    : focus?.kind === "step"
      ? (anatomy.branches.find((b) => b.stepId === focus.stepId)?.anchor ?? anatomy.top)
      : focus?.kind === "apex"
        ? (anatomy.apex?.pos ?? anatomy.top)
        : focus?.kind === "roots"
          ? { x: 0, y: 30 }
          : anatomy.stageIndex === 0
            ? null
            : anatomy.top;
  const focusStep = working && focus?.kind === "step" ? focus.stepId : null;

  const filter =
    mood === "stalled"
      ? "grayscale(0.6) sepia(0.3)"
      : mood === "dormant"
        ? "saturate(0.55) brightness(0.98)"
        : undefined;

  const plateTop = Math.max(-340, Math.min(-170, -(anatomy.height + 74)));
  const viewBox = plate ? `-170 ${r1(plateTop)} 340 ${r1(164 - plateTop)}` : "-160 -330 320 460";
  const label =
    title ??
    `${species.common === "seedling" ? "Seedling" : species.common}: ${state.growth.stepsDone} of ${state.growth.stepsTotal} steps done`;

  return (
    <svg viewBox={viewBox} className={className} role="img" aria-label={label} style={{ overflow: "visible" }}>
      <defs>
        <radialGradient id={`${uid}-glow`}>
          <stop offset="0" stopColor="#f6ffd0" stopOpacity="0.95" />
          <stop offset="0.35" stopColor="#dcf780" stopOpacity="0.45" />
          <stop offset="1" stopColor="#dcf780" stopOpacity="0" />
        </radialGradient>
        <radialGradient id={`${uid}-ripe`}>
          <stop offset="0" stopColor={species.fruit.ripe} stopOpacity="0.42" />
          <stop offset="0.5" stopColor={species.fruit.ripe} stopOpacity="0.16" />
          <stop offset="1" stopColor={species.fruit.ripe} stopOpacity="0" />
        </radialGradient>
        <linearGradient id={`${uid}-root`} x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor="#f6ecd6" />
          <stop offset="1" stopColor="#d9c59b" />
        </linearGradient>
        <linearGradient id={`${uid}-stem`} x1="0" y1="0" x2="1" y2="0">
          <stop offset="0" stopColor={mixHex(species.stem.color, "#ffffff", 0.18)} />
          <stop offset="0.6" stopColor={species.stem.color} />
          <stop offset="1" stopColor={species.stem.shade} />
        </linearGradient>
      </defs>

      {plate && <Soil uid={uid} seed={seedNum} />}

      {plate && (
        <g>
          <path
            d="M -170 92 C -130 80 -100 104 -64 86 S -20 60 -2 22"
            style={{ fill: "none", stroke: "var(--myc)", strokeWidth: 3.2, opacity: 0.18 }}
          />
          <path
            d="M -170 92 C -130 80 -100 104 -64 86 S -20 60 -2 22"
            className={working ? undefined : "myc-breathe"}
            style={{ fill: "none", stroke: "var(--myc)", strokeWidth: 0.9, opacity: mood === "dormant" ? 0.3 : 0.8 }}
          />
          {working && (
            <path
              d="M -170 92 C -130 80 -100 104 -64 86 S -20 60 -2 22"
              pathLength={1000}
              className="myc-pulse"
              style={{ fill: "none", stroke: "#fbffe0", strokeWidth: 2.4, strokeLinecap: "round" }}
            />
          )}
        </g>
      )}

      <g style={{ opacity: plate ? 1 : 0.92 }}>
        {anatomy.roots.map((root) => {
          const full = tapered(root.points, root.w0, root.w1);
          const tiny = tapered(
            root.points.map((p) => ({
              x: root.points[0].x + (p.x - root.points[0].x) * 0.03,
              y: root.points[0].y + (p.y - root.points[0].y) * 0.03,
            })),
            root.w0 * 0.3,
            root.w1,
          );
          const lit = root.stepId !== null && root.stepId === focusStep;
          return (
            <g key={root.key}>
              {lit && (
                <path
                  d={openSmooth(root.points)}
                  className="glow-breathe"
                  style={{ fill: "none", stroke: "var(--myc)", strokeWidth: 6, opacity: 0.5, strokeLinecap: "round" }}
                />
              )}
              <motion.path
                initial={from({ d: tiny })}
                animate={{ d: full }}
                transition={{ ...grow, duration: 1.6 }}
                style={{ fill: `url(#${uid}-root)`, stroke: "rgba(60,40,25,0.55)", strokeWidth: 0.5 }}
              />
            </g>
          );
        })}
        {anatomy.hairs && (
          <motion.path
            initial={from({ opacity: 0 })}
            animate={{ opacity: 0.7 }}
            transition={{ duration: 1.2, delay: 0.8 }}
            d={anatomy.hairs}
            style={{ fill: "none", stroke: "#e9dbbb", strokeWidth: 0.45 }}
          />
        )}
      </g>

      <Seed anatomy={anatomy} glow={working && anatomy.stageIndex === 0} from={from} />

      {focusPoint && (
        <circle
          cx={r1(focusPoint.x)}
          cy={r1(focusPoint.y)}
          r={34}
          className="glow-breathe"
          style={{ fill: `url(#${uid}-glow)` }}
        />
      )}

      <g style={{ filter, transition: "filter 1s ease" }}>
        {sway && (
          <animateTransform
            attributeName="transform"
            type="rotate"
            values="-1.1 0 0; 1.3 0 0; -1.1 0 0"
            dur={working ? "6s" : "10s"}
            repeatCount="indefinite"
          />
        )}

        {anatomy.baseLeaves.map((leaf, i) => (
          <Leaf key={leaf.key} spec={leaf} species={species} from={from} delay={0.1 + i * 0.1} />
        ))}

        {anatomy.stem && (
          <g>
            <motion.path
              initial={from({
                d: tapered(
                  anatomy.stem.map((p) => ({ x: p.x * 0.02, y: p.y * 0.02 })),
                  1,
                  0.5,
                ),
              })}
              animate={{ d: tapered(anatomy.stem, anatomy.stemWidth[0], anatomy.stemWidth[1]) }}
              transition={{ ...grow, duration: 1.5 }}
              style={{ fill: `url(#${uid}-stem)`, stroke: INK, strokeWidth: 0.8 }}
            />
            {species.stem.woody &&
              anatomy.stageIndex >= 2 &&
              [0.18, 0.32, 0.5, 0.66].map((t, i) => {
                const p = anatomy.stem![Math.round(t * (anatomy.stem!.length - 1))];
                const w = anatomy.stemWidth[0] * (1 - t * 0.7) * 0.45;
                return (
                  <path
                    key={i}
                    d={`M ${r1(p.x - w)} ${r1(p.y + 1)} Q ${r1(p.x)} ${r1(p.y - 1.5)} ${r1(p.x + w)} ${r1(p.y + 0.5)}`}
                    style={{ fill: "none", stroke: species.stem.shade, strokeWidth: 0.7, opacity: 0.7 }}
                  />
                );
              })}
          </g>
        )}

        {anatomy.branches.map((branch) => {
          const lit = highlight === branch.stepId;
          const flutter = !reduce && branch.status === "done" && sway;
          const salt = rand(seedNum, branch.index + 200);
          return (
            <Hit
              key={branch.stepId}
              id={branch.stepId}
              label={branchLabel(branch)}
              at={branch.anchor}
              onHover={onHover}
              onSelect={onSelect}
            >
              <g>
                {flutter && (
                  <animateTransform
                    attributeName="transform"
                    type="rotate"
                    values={`0 ${r1(branch.node.x)} ${r1(branch.node.y)}; ${r1(2 * branch.side)} ${r1(branch.node.x)} ${r1(branch.node.y)}; 0 ${r1(branch.node.x)} ${r1(branch.node.y)}`}
                    dur={`${(4.5 + salt * 3).toFixed(2)}s`}
                    begin={`${(salt * 2).toFixed(2)}s`}
                    repeatCount="indefinite"
                  />
                )}
                {branch.twig && (
                  <motion.path
                    initial={from({
                      d: tapered(
                        branch.twig.map((p) => ({
                          x: branch.node.x + (p.x - branch.node.x) * 0.04,
                          y: branch.node.y + (p.y - branch.node.y) * 0.04,
                        })),
                        1,
                        0.5,
                      ),
                    })}
                    animate={{ d: tapered(branch.twig, branch.twigWidth[0], branch.twigWidth[1]) }}
                    transition={grow}
                    style={{ fill: species.stem.color, stroke: INK, strokeWidth: 0.6 }}
                  />
                )}
                {branch.thorn && (
                  <path
                    d={`M ${r1(branch.thorn.pos.x)} ${r1(branch.thorn.pos.y)} L ${r1(polar(branch.thorn.pos, branch.thorn.angle - 8, 7).x)} ${r1(polar(branch.thorn.pos, branch.thorn.angle - 8, 7).y)} L ${r1(polar(branch.thorn.pos, branch.thorn.angle + 30, 2).x)} ${r1(polar(branch.thorn.pos, branch.thorn.angle + 30, 2).y)} Z`}
                    style={{ fill: "#4f5a2c", stroke: INK, strokeWidth: 0.4 }}
                  />
                )}
                {branch.leaves.map((leaf, i) => (
                  <Leaf key={leaf.key} spec={leaf} species={species} from={from} delay={0.2 + i * 0.12} lit={lit} />
                ))}
                {branch.bud && (
                  <Bud
                    pos={branch.bud.pos}
                    angle={branch.bud.angle}
                    size={branch.bud.size}
                    tone={branch.status === "blocked" ? "blocked" : branch.status === "working" ? "working" : "pending"}
                    from={from}
                  />
                )}
                {branch.decor?.kind === "flower" && (
                  <Flower
                    species={species}
                    pos={branch.decor.pos}
                    angle={branch.decor.angle}
                    size={branch.decor.size}
                    from={from}
                  />
                )}
                {branch.decor && branch.decor.kind !== "flower" && (
                  <FruitShape
                    species={species}
                    pos={branch.decor.pos}
                    size={branch.decor.size}
                    ripeness={branch.decor.ripeness}
                    uid={uid}
                    salt={branch.index}
                    from={from}
                    tilt={branch.decor.tilt}
                    sway={!reduce}
                  />
                )}
                {branch.status === "blocked" && (
                  <g transform={`translate(${r1(branch.anchor.x + branch.side * 8)} ${r1(branch.anchor.y + 4)})`}>
                    <path
                      d="M 0 -9 C 4 -3 6 0 6 3 A 6 6 0 0 1 -6 3 C -6 0 -4 -3 0 -9 Z"
                      style={{ fill: "var(--cobalt)", stroke: "var(--paper)", strokeWidth: 0.8 }}
                    />
                    <text
                      y={5.5}
                      textAnchor="middle"
                      style={{
                        fill: "var(--paper)",
                        fontSize: 7.5,
                        fontWeight: 700,
                        fontFamily: "var(--font-fraunces), serif",
                      }}
                    >
                      ?
                    </text>
                  </g>
                )}
                {lit && (
                  <circle
                    cx={r1(branch.anchor.x)}
                    cy={r1(branch.anchor.y)}
                    r={14}
                    style={{ fill: "none", stroke: "var(--cobalt)", strokeWidth: 0.8, strokeDasharray: "2 3" }}
                  />
                )}
              </g>
            </Hit>
          );
        })}

        {anatomy.cotyledons.map((leaf, i) => (
          <Leaf key={leaf.key} spec={leaf} species={species} from={from} delay={0.6 + i * 0.1} cotyledon />
        ))}

        <AnimatePresence>
          {anatomy.apex && (
            <Hit
              key={`apex-${anatomy.apex.kind}`}
              id="apex"
              label={
                anatomy.apex.kind === "fruit"
                  ? "The fruit: the most complete version of the work"
                  : anatomy.apex.kind === "fruitlet"
                    ? "Fruit setting: most of the work is done"
                    : anatomy.apex.kind === "flower"
                      ? "In bloom: the first piece is ready"
                      : "The growing tip"
              }
              at={
                anatomy.apex.kind === "fruit" || anatomy.apex.kind === "fruitlet"
                  ? { x: anatomy.apex.pos.x, y: anatomy.apex.pos.y + anatomy.apex.size }
                  : anatomy.apex.pos
              }
              r={22}
              onHover={onHover}
              onSelect={onSelect}
            >
              <motion.g exit={{ opacity: 0 }} transition={{ duration: 0.8 }}>
                {anatomy.apex.stalk && (
                  <path
                    d={openSmooth(anatomy.apex.stalk)}
                    style={{ fill: "none", stroke: species.stem.shade, strokeWidth: 1.8, strokeLinecap: "round" }}
                  />
                )}
                {anatomy.apex.kind === "bud" && (
                  <Bud
                    pos={anatomy.apex.pos}
                    angle={anatomy.apex.angle}
                    size={anatomy.apex.size}
                    tone="pending"
                    from={from}
                  />
                )}
                {anatomy.apex.kind === "flower" && (
                  <Flower
                    species={species}
                    pos={anatomy.apex.pos}
                    angle={anatomy.apex.angle}
                    size={anatomy.apex.size}
                    from={from}
                  />
                )}
                {(anatomy.apex.kind === "fruit" || anatomy.apex.kind === "fruitlet") && (
                  <motion.g
                    animate={dropFruit ? { y: 120, rotate: 18, opacity: 0 } : { y: 0, rotate: 0, opacity: 1 }}
                    transition={dropFruit ? { duration: 0.9, ease: [0.55, 0, 0.75, 0.2] } : { duration: 0.3 }}
                  >
                    {anatomy.apex.kind === "fruit" && (
                      <circle
                        cx={r1(anatomy.apex.pos.x)}
                        cy={r1(anatomy.apex.pos.y + anatomy.apex.size)}
                        r={r1(anatomy.apex.size * 2.3)}
                        style={{ fill: `url(#${uid}-ripe)` }}
                      />
                    )}
                    <FruitShape
                      species={species}
                      pos={anatomy.apex.pos}
                      size={anatomy.apex.size}
                      ripeness={anatomy.apex.ripeness}
                      uid={uid}
                      salt={99}
                      from={from}
                      sway={!reduce && !dropFruit}
                    />
                  </motion.g>
                )}
                {highlight === "apex" && (
                  <circle
                    cx={r1(anatomy.apex.pos.x)}
                    cy={r1(
                      anatomy.apex.pos.y +
                        (anatomy.apex.kind === "fruit" || anatomy.apex.kind === "fruitlet" ? anatomy.apex.size : 0),
                    )}
                    r={r1(anatomy.apex.size * 1.7 + 6)}
                    style={{ fill: "none", stroke: "var(--cobalt)", strokeWidth: 0.8, strokeDasharray: "2 3" }}
                  />
                )}
              </motion.g>
            </Hit>
          )}
        </AnimatePresence>

        {anatomy.tag && (
          <Hit id="tag" label="The agent's review" at={anatomy.tag.card} r={18} onHover={onHover} onSelect={onSelect}>
            <motion.g initial={from({ opacity: 0 })} animate={{ opacity: 1 }} transition={{ duration: 0.8 }}>
              <path
                d={`M ${r1(anatomy.tag.knot.x)} ${r1(anatomy.tag.knot.y)} Q ${r1((anatomy.tag.knot.x + anatomy.tag.card.x) / 2)} ${r1(anatomy.tag.card.y + 6)} ${r1(anatomy.tag.card.x)} ${r1(anatomy.tag.card.y - 8)}`}
                style={{ fill: "none", stroke: "#8a6a45", strokeWidth: 0.7 }}
              />
              <g
                transform={`translate(${r1(anatomy.tag.card.x)} ${r1(anatomy.tag.card.y)}) rotate(${anatomy.tag.card.x > anatomy.tag.knot.x ? 8 : -8})`}
              >
                <path
                  d="M -13 -9 L 9 -9 L 14 0 L 9 9 L -13 9 Z"
                  style={{
                    fill: "#f4ead2",
                    stroke: highlight === "tag" ? "var(--cobalt)" : "#8a6a45",
                    strokeWidth: highlight === "tag" ? 1.2 : 0.6,
                  }}
                />
                <circle cx={9} cy={0} r={1.6} style={{ fill: "none", stroke: "#8a6a45", strokeWidth: 0.6 }} />
                <text
                  x={-2}
                  y={2.6}
                  textAnchor="middle"
                  style={{ fill: "#5a4630", fontSize: 6.4, fontFamily: "var(--font-typewriter), monospace" }}
                >
                  review
                </text>
              </g>
            </motion.g>
          </Hit>
        )}
      </g>

      {callouts &&
        anatomy.branches.map((branch) => (
          <Callout
            key={branch.stepId}
            at={branch.anchor}
            side={branch.side}
            text={String(branch.index)}
            lit={highlight === branch.stepId}
          />
        ))}
      {callouts && anatomy.apex && (anatomy.apex.kind === "fruit" || anatomy.apex.kind === "fruitlet") && (
        <Callout
          at={{ x: anatomy.apex.pos.x + anatomy.apex.size * 0.9, y: anatomy.apex.pos.y + anatomy.apex.size }}
          side={anatomy.apex.pos.x >= anatomy.top.x ? 1 : -1}
          text="∗"
          lit={highlight === "apex"}
        />
      )}
    </svg>
  );
}
