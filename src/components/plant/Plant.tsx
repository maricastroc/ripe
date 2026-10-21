"use client";

import { AnimatePresence, motion } from "motion/react";
import { useEffect, useId, useState } from "react";
import { FRUITS, stageIndex, stageName } from "@/lib/growth";
import type { Fruit, Growth, LeafState, StepStatus } from "@/lib/types";
import {
  type Pt,
  bezier,
  bezierAngle,
  ellipsePath,
  hash,
  leafPath,
  mixHex,
  rand,
  ribPath,
  stemPath,
  teardropPath,
} from "./geometry";

export type PlantMood = "working" | "waiting" | "resting" | "dormant" | "stalled" | "idle" | "harvested";

type PlantProps = {
  seed: string;
  growth: Growth;
  leaves: LeafState[];
  fruit: Fruit | null;
  mood: PlantMood;
  highlight?: string | null;
  dropFruit?: boolean;
  entrance?: boolean;
  className?: string;
  title?: string;
};

export function EmptyPot({ seed, className }: { seed: string; className?: string }) {
  return (
    <svg viewBox="0 0 200 270" className={className} aria-hidden style={{ overflow: "visible" }}>
      <Pot variant={hash(seed) % POTS.length} />
    </svg>
  );
}

const GROUND = 201;
const CX = 100;
const grow = { duration: 1.2, ease: [0.22, 1, 0.36, 1] as const };

const POTS = [
  { body: "var(--pot-a)", rim: "var(--pot-a-rim)", shade: "var(--pot-a-shade)", band: false },
  { body: "var(--pot-b)", rim: "var(--pot-b-rim)", shade: "var(--pot-b-shade)", band: false },
  { body: "var(--pot-c)", rim: "var(--pot-c-rim)", shade: "var(--pot-c-shade)", band: true },
];

const LEAF_STYLE: Record<StepStatus, { spread: number; length: number; fill: string; rib: number }> = {
  done: { spread: 60, length: 40, fill: "var(--leaf)", rib: 0.5 },
  working: { spread: 42, length: 27, fill: "var(--leaf-2)", rib: 0.35 },
  pending: { spread: 24, length: 17, fill: "color-mix(in oklab, var(--leaf-2) 62%, var(--paper))", rib: 0 },
  blocked: { spread: 84, length: 25, fill: "var(--ochre)", rib: 0.3 },
};

function Pot({ variant }: { variant: number }) {
  const pot = POTS[variant];
  return (
    <g>
      <ellipse cx={CX} cy={271} rx={47} ry={5} style={{ fill: "rgb(var(--shadow-rgb) / 0.16)" }} />
      <path
        d="M63 212 L137 212 L130.5 263 Q129.6 269 123.5 269 L76.5 269 Q70.4 269 69.5 263 Z"
        style={{ fill: pot.body }}
      />
      <path
        d="M113 212 L137 212 L130.5 263 Q129.6 269 123.5 269 L109 269 Q115.5 240 113 212 Z"
        style={{ fill: pot.shade, opacity: 0.55 }}
      />
      {pot.band && (
        <path d="M66.5 236 L133.5 236" style={{ stroke: "var(--pot-band)", strokeWidth: 2.5, opacity: 0.6 }} />
      )}
      <rect x={57} y={198} width={86} height={15} rx={3.5} style={{ fill: pot.rim }} />
      <rect x={57} y={209} width={86} height={4} rx={2} style={{ fill: pot.shade, opacity: 0.4 }} />
      <ellipse cx={CX} cy={200.5} rx={40} ry={3.8} style={{ fill: "var(--soil-2)" }} />
      <ellipse cx={CX} cy={201} rx={37} ry={2.6} style={{ fill: "var(--soil)" }} />
    </g>
  );
}

export function Plant({
  seed,
  growth,
  leaves,
  fruit,
  mood,
  highlight,
  dropFruit,
  entrance,
  className,
  title,
}: PlantProps) {
  const uid = useId().replace(/:/g, "");
  const [settled, setSettled] = useState(false);
  useEffect(() => {
    const frame = requestAnimationFrame(() => setSettled(true));
    return () => cancelAnimationFrame(frame);
  }, []);
  const animateEntry = entrance || settled;
  const from = <T,>(value: T): T | false => (animateEntry ? value : false);
  const h = hash(seed);
  const variant = h % POTS.length;
  const bend = (rand(h, 1) - 0.5) * 24;
  const fruitSide = rand(h, 2) > 0.5 ? 1 : -1;
  const idx = stageIndex(growth.stage);
  const total = leaves.length;

  const vigor = 0.92 + rand(h, 3) * 0.16;
  const height =
    growth.stage === "seed"
      ? 0
      : growth.stage === "sprout"
        ? 44
        : (92 + 11 * Math.min(Math.max(total, 2), 5) + (idx >= 3 ? 10 : 0)) * vigor;

  const p0: Pt = { x: CX, y: GROUND };
  const p1: Pt = { x: CX, y: GROUND - height * 0.4 };
  const p2: Pt = { x: CX + bend, y: GROUND - height * 0.72 };
  const p3: Pt = { x: CX + bend * 0.45, y: GROUND - height };
  const top = p3;
  const topAngle = height > 0 ? bezierAngle(p0, p1, p2, p3, 1) : -90;

  const species = fruit ? FRUITS[fruit] : FRUITS.caqui;
  const doneRatio = growth.stepsTotal ? growth.stepsDone / growth.stepsTotal : 0;
  const ripe = growth.stage === "ripe";
  const fruitR = ripe ? 22 : 11 + 9 * doneRatio;
  const ripeness = Math.min(0.85, 0.15 + 0.7 * doneRatio);
  const fruitColor = ripe
    ? species.ripe
    : ripeness < 0.5
      ? mixHex(species.unripe, species.mid, ripeness * 2)
      : mixHex(species.mid, species.ripe, (ripeness - 0.5) * 2);
  const attach: Pt = { x: top.x + fruitSide * 15, y: top.y + 8 };
  const fruitShape = (r: number) => {
    const c = { x: attach.x, y: attach.y + r * 0.95 };
    if (fruit === "figo") return teardropPath({ x: attach.x, y: attach.y - 1 }, r * 0.95, r * 2.05);
    if (fruit === "ameixa") return ellipsePath(c.x, c.y, r * 0.9, r * 1.02);
    if (fruit === "limao") return ellipsePath(c.x, c.y + r * 0.1, r * 0.8, r * 1.1);
    return ellipsePath(c.x, c.y, r * 1.08, r * 0.9);
  };
  const shineShape = (r: number) => ellipsePath(attach.x - r * 0.38, attach.y + r * 0.62, r * 0.26, r * 0.16);

  const leafNodes = leaves.map((leaf, i) => {
    const t = 0.2 + 0.6 * (total === 1 ? 0.5 : i / (total - 1));
    const base = bezier(p0, p1, p2, p3, t);
    const stemA = bezierAngle(p0, p1, p2, p3, t);
    const side = (i + (h % 2)) % 2 === 0 ? 1 : -1;
    const style = LEAF_STYLE[leaf.status];
    const angle = stemA + side * style.spread;
    const length = style.length * (0.88 + rand(h, 10 + i) * 0.26) * (0.92 + (1 - t) * 0.2);
    return { leaf, base, angle, length, width: length * 0.42, style };
  });

  const stageLabel = stageName(growth.stage);
  const showFlower = growth.stage === "blossom";
  const showFruit = growth.stage === "fruit" || ripe;
  const showTopBud = idx >= 2 && !showFlower && !showFruit;
  const showSprout = growth.stage === "sprout";

  return (
    <svg
      viewBox="0 0 200 270"
      className={className}
      role="img"
      aria-label={title ?? `${stageLabel}: ${growth.stepsDone} de ${growth.stepsTotal} etapas concluídas`}
      style={{ overflow: "visible" }}
    >
      <defs>
        <radialGradient id={`glow-${uid}`}>
          <stop offset="0%" stopColor={species.ripe} stopOpacity="0.5" />
          <stop offset="100%" stopColor={species.ripe} stopOpacity="0" />
        </radialGradient>
        <radialGradient id={`work-${uid}`}>
          <stop offset="0%" stopColor="var(--butter)" stopOpacity="0.55" />
          <stop offset="100%" stopColor="var(--butter)" stopOpacity="0" />
        </radialGradient>
      </defs>

      {mood === "working" && (
        <circle className="halo" cx={top.x} cy={Math.min(top.y, GROUND - 12)} r={46} fill={`url(#work-${uid})`} />
      )}

      <Pot variant={variant} />

      <g
        className="plant-sway"
        data-mood={mood}
        style={{
          filter:
            mood === "stalled"
              ? "grayscale(0.65) sepia(0.25)"
              : mood === "dormant"
                ? "saturate(0.55) brightness(0.97)"
                : undefined,
          transition: "filter 1.2s ease",
        }}
      >
        {growth.stage === "seed" && (
          <g>
            <motion.ellipse
              cx={CX + bend * 0.15}
              cy={GROUND - 1.5}
              rx={5.6}
              ry={3.6}
              transform={`rotate(-18 ${CX + bend * 0.15} ${GROUND - 1.5})`}
              style={{ fill: "#8a6748" }}
              initial={from({ cy: GROUND - 60, opacity: 0 })}
              animate={{ cy: GROUND - 1.5, opacity: 1 }}
              transition={{ type: "spring", stiffness: 140, damping: 11 }}
            />
            {mood === "working" && (
              <motion.path
                d={`M ${CX + bend * 0.15 + 1} ${GROUND - 4} q 2 -6 6 -8`}
                style={{ stroke: "var(--leaf-2)", strokeWidth: 2, fill: "none", strokeLinecap: "round" }}
                initial={from({ pathLength: 0 })}
                animate={{ pathLength: [0, 1, 1, 0] }}
                transition={{ duration: 3.2, repeat: Infinity, ease: "easeInOut" }}
              />
            )}
          </g>
        )}

        <motion.path
          initial={from({ d: stemPath(p0, p0, p0, p0) })}
          animate={{ d: stemPath(p0, p1, p2, p3), opacity: height > 0 ? 1 : 0 }}
          transition={grow}
          style={{
            fill: "none",
            stroke: "var(--stem)",
            strokeWidth: idx >= 3 ? 4.2 : idx >= 2 ? 3.6 : 3,
            strokeLinecap: "round",
          }}
        />

        {idx >= 2 &&
          [-1, 1].map((side) => {
            const base = bezier(p0, p1, p2, p3, 0.07);
            const angle = bezierAngle(p0, p1, p2, p3, 0.07) + side * 68;
            return (
              <motion.path
                key={`base-${side}`}
                initial={from({ d: leafPath(base, angle, 0.5, 0.3) })}
                animate={{ d: leafPath(base, angle, 19, 10) }}
                transition={{ ...grow, delay: 0.2 }}
                style={{ fill: "var(--leaf-2)" }}
              />
            );
          })}

        {leafNodes.map(({ leaf, base, angle, length, width, style }, i) => {
          const lit = highlight === leaf.id;
          return (
            <g key={leaf.id}>
              <motion.path
                initial={from({ d: leafPath(base, angle, 0.5, 0.2) })}
                animate={{ d: leafPath(base, angle, length * (lit ? 1.12 : 1), width * (lit ? 1.12 : 1)) }}
                transition={{ ...grow, delay: 0.15 + i * 0.07 }}
                style={{
                  fill: style.fill,
                  stroke: lit ? "var(--accent)" : "transparent",
                  strokeWidth: 1.2,
                  transition: "fill 0.9s ease, stroke 0.3s ease",
                }}
              />
              {style.rib > 0 && (
                <motion.path
                  initial={from({ d: ribPath(base, angle, 0.5), opacity: 0 })}
                  animate={{ d: ribPath(base, angle, length), opacity: style.rib }}
                  transition={{ ...grow, delay: 0.25 + i * 0.07 }}
                  style={{ fill: "none", stroke: "var(--leaf-dark)", strokeWidth: 0.9, strokeLinecap: "round" }}
                />
              )}
              {leaf.status === "working" && (
                <motion.circle
                  cx={base.x}
                  cy={base.y}
                  r={2.6}
                  style={{ fill: "var(--butter)" }}
                  animate={{ opacity: [0.2, 1, 0.2] }}
                  transition={{ duration: 1.8, repeat: Infinity }}
                />
              )}
            </g>
          );
        })}

        <AnimatePresence>
          {showSprout && (
            <motion.g key="sprout" exit={{ opacity: 0 }} transition={{ duration: 0.6 }}>
              {[-1, 1].map((side) => (
                <motion.path
                  key={side}
                  initial={from({ d: leafPath(top, topAngle + side * 60, 0.5, 0.3) })}
                  animate={{ d: leafPath(top, topAngle + side * 58, 17, 9) }}
                  transition={{ ...grow, delay: 0.9 }}
                  style={{ fill: "var(--leaf-2)" }}
                />
              ))}
            </motion.g>
          )}

          {showTopBud && (
            <motion.g key="bud" exit={{ opacity: 0 }} transition={{ duration: 0.6 }}>
              {[-1, 1].map((side) => (
                <motion.path
                  key={side}
                  initial={from({ d: leafPath(top, topAngle + side * 22, 0.5, 0.3) })}
                  animate={{ d: leafPath(top, topAngle + side * 20, 11, 5) }}
                  transition={{ ...grow, delay: 0.6 }}
                  style={{ fill: "var(--leaf-2)" }}
                />
              ))}
            </motion.g>
          )}

          {showFlower && (
            <motion.g key="flower" exit={{ opacity: 0, y: 30 }} transition={{ duration: 1.2 }}>
              {[0, 1, 2, 3, 4].map((k) => {
                const angle = topAngle + 18 + k * 72;
                return (
                  <motion.path
                    key={k}
                    initial={from({ d: leafPath(top, angle, 0.5, 0.3) })}
                    animate={{ d: leafPath(top, angle, 16, 9.5) }}
                    transition={{ ...grow, delay: 0.5 + k * 0.08 }}
                    style={{ fill: "var(--petal)", stroke: "var(--petal-2)", strokeWidth: 0.8 }}
                  />
                );
              })}
              <motion.circle
                cx={top.x}
                cy={top.y}
                initial={from({ r: 0 })}
                animate={{ r: 4.6 }}
                transition={{ ...grow, delay: 0.9 }}
                style={{ fill: "var(--butter)" }}
              />
            </motion.g>
          )}
        </AnimatePresence>

        {showFruit && (
          <>
            {[0, 1].map((k) => (
              <path
                key={k}
                d={leafPath({ x: CX - 18 + k * 30, y: GROUND - 0.5 }, k ? -8 : 196, 7, 3.4)}
                style={{ fill: "var(--petal)", opacity: 0.85 }}
              />
            ))}
            <motion.path
              d={`M ${top.x} ${top.y} Q ${top.x + fruitSide * 9} ${top.y - 5} ${attach.x} ${attach.y}`}
              style={{ fill: "none", stroke: "var(--leaf-dark)", strokeWidth: 2.2, strokeLinecap: "round" }}
              initial={from({ pathLength: 0 })}
              animate={{ pathLength: 1 }}
              transition={grow}
            />
            <motion.g
              animate={dropFruit ? { y: 90, rotate: fruitSide * 25, opacity: 0 } : { y: 0, rotate: 0, opacity: 1 }}
              transition={dropFruit ? { duration: 0.9, ease: [0.55, 0, 0.75, 0.2] } : { duration: 0.4 }}
              style={{ originX: 0.5, originY: 0 }}
            >
              {ripe && (
                <circle
                  className="halo"
                  cx={attach.x}
                  cy={attach.y + fruitR}
                  r={fruitR * 2.1}
                  fill={`url(#glow-${uid})`}
                />
              )}
              <motion.path
                initial={from({ d: fruitShape(1), fill: species.unripe })}
                animate={{ d: fruitShape(fruitR), fill: fruitColor }}
                transition={{ ...grow, duration: 2 }}
              />
              {fruit === "ameixa" && (
                <motion.path
                  initial={from({ opacity: 0 })}
                  animate={{
                    d: `M ${attach.x + fruitR * 0.15} ${attach.y + fruitR * 0.1} q ${fruitR * 0.35} ${fruitR * 0.9} 0 ${fruitR * 1.8}`,
                    opacity: 0.35,
                  }}
                  transition={grow}
                  style={{ fill: "none", stroke: species.deep, strokeWidth: 1.2 }}
                />
              )}
              {fruit === "limao" && (
                <motion.circle
                  initial={from({ r: 0 })}
                  animate={{ cx: attach.x, cy: attach.y + fruitR * 2.12, r: fruitR * 0.16 }}
                  transition={grow}
                  style={{ fill: fruitColor }}
                />
              )}
              <motion.path
                initial={from({ d: shineShape(1), opacity: 0 })}
                animate={{ d: shineShape(fruitR), opacity: ripe ? 0.45 : 0.25 }}
                transition={{ ...grow, duration: 2 }}
                style={{ fill: "#fff" }}
              />
              {(fruit === "caqui" || !fruit) &&
                [-150, -105, -75, -30].map((a, k) => (
                  <motion.path
                    key={k}
                    initial={from({ d: leafPath(attach, a, 0.5, 0.2) })}
                    animate={{ d: leafPath({ x: attach.x, y: attach.y + 1 }, a, fruitR * 0.48, fruitR * 0.2) }}
                    transition={grow}
                    style={{ fill: "var(--leaf-dark)" }}
                  />
                ))}
              {ripe && (
                <motion.path
                  initial={from({ d: leafPath(attach, -90 + fruitSide * 40, 0.5, 0.2) })}
                  animate={{
                    d: leafPath({ x: attach.x - fruitSide * 2, y: attach.y - 2 }, -90 + fruitSide * 48, 15, 6),
                  }}
                  transition={{ ...grow, delay: 0.4 }}
                  style={{ fill: "var(--leaf)" }}
                />
              )}
            </motion.g>
          </>
        )}

        {mood === "working" &&
          [0, 1, 2, 3, 4, 5].map((k) => (
            <circle
              key={k}
              className="pollen"
              cx={top.x + (rand(h, 40 + k) - 0.5) * 50}
              cy={Math.min(top.y + 10, GROUND - 14) + rand(h, 50 + k) * 30}
              r={1.4 + rand(h, 60 + k) * 1.4}
              style={
                {
                  fill: "var(--butter)",
                  "--dx": `${(rand(h, 70 + k) - 0.5) * 30}px`,
                  "--delay": `${k * 0.7}s`,
                  "--dur": `${3.4 + rand(h, 80 + k) * 1.8}s`,
                } as React.CSSProperties
              }
            />
          ))}
      </g>

      {mood === "waiting" && (
        <g className="bob">
          <circle cx={top.x + 24} cy={Math.max(top.y - 18, 40)} r={9.5} style={{ fill: "var(--accent)" }} />
          <text
            x={top.x + 24}
            y={Math.max(top.y - 18, 40) + 4.2}
            textAnchor="middle"
            style={{ fill: "var(--paper)", fontSize: 12, fontWeight: 700, fontFamily: "var(--font-fraunces)" }}
          >
            ?
          </text>
        </g>
      )}
    </svg>
  );
}
