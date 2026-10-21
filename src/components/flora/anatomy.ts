import { stageIndex } from "@/lib/growth";
import type { Fruit, Growth, StepStatus } from "@/lib/types";
import { type Pt, bezier, bezierAngle, hash, lerp, polar, r1, rand, sample } from "./geometry";
import { type Species, speciesOf } from "./species";

export type SpecimenStep = { id: string; status: StepStatus; artifactId: string | null; title?: string };

export type SpecimenState = {
  seed: string;
  fruit: Fruit | null;
  growth: Growth;
  steps: SpecimenStep[];
};

export type LeafSpec = {
  key: string;
  base: Pt;
  angle: number;
  size: number;
  mirror: number;
  tone: number;
  autumn?: boolean;
};

export type Decor = {
  kind: "flower" | "fruitlet" | "fruit";
  pos: Pt;
  size: number;
  ripeness: number;
  angle: number;
  tilt: number;
};

export type BranchSpec = {
  stepId: string;
  index: number;
  status: StepStatus;
  artifactId: string | null;
  title?: string;
  side: number;
  node: Pt;
  twig: Pt[] | null;
  twigWidth: [number, number];
  leaves: LeafSpec[];
  bud: { pos: Pt; angle: number; size: number } | null;
  decor: Decor | null;
  thorn: { pos: Pt; angle: number } | null;
  anchor: Pt;
};

export type RootSpec = { key: string; points: Pt[]; w0: number; w1: number; stepId: string | null };

export type ApexSpec = {
  kind: "bud" | "flower" | "fruitlet" | "fruit";
  pos: Pt;
  angle: number;
  size: number;
  ripeness: number;
  stalk: Pt[] | null;
};

export type Anatomy = {
  species: Species;
  stageIndex: number;
  height: number;
  stem: Pt[] | null;
  stemWidth: [number, number];
  top: Pt;
  topAngle: number;
  cotyledons: LeafSpec[];
  baseLeaves: LeafSpec[];
  branches: BranchSpec[];
  apex: ApexSpec | null;
  roots: RootSpec[];
  hairs: string;
  seed: { pos: Pt; angle: number; open: boolean };
  tag: { knot: Pt; card: Pt } | null;
};

function along(points: Pt[], u: number) {
  const f = u * (points.length - 1);
  const i = Math.min(points.length - 2, Math.floor(f));
  const t = f - i;
  const a = points[i];
  const b = points[i + 1];
  return {
    pos: { x: lerp(a.x, b.x, t), y: lerp(a.y, b.y, t) },
    angle: (Math.atan2(b.y - a.y, b.x - a.x) * 180) / Math.PI,
  };
}

export function computeAnatomy(state: SpecimenState): Anatomy {
  const species = speciesOf(state.fruit);
  const h = hash(state.seed);
  const rnd = (salt: number) => rand(h, salt);
  const stage = state.growth.stage;
  const idx = stageIndex(stage);
  const steps = state.steps;
  const n = steps.length;
  const vigor = 0.92 + rnd(3) * 0.16;
  const seed = { pos: { x: r1((rnd(4) - 0.5) * 16), y: 15 }, angle: -25 + rnd(5) * 50, open: idx >= 1 };

  const empty: Anatomy = {
    species,
    stageIndex: idx,
    height: 0,
    stem: null,
    stemWidth: [0, 0],
    top: { x: 0, y: 0 },
    topAngle: -90,
    cotyledons: [],
    baseLeaves: [],
    branches: [],
    apex: null,
    roots: [],
    hairs: "",
    seed,
    tag: null,
  };
  if (idx === 0) return empty;

  const height =
    stage === "sprout"
      ? 66 * vigor
      : Math.min(280, (98 + 24 * Math.min(Math.max(n, 2), 5)) * vigor + (idx >= 3 ? 14 : 0));
  const lean = (rnd(1) - 0.5) * species.stem.lean;
  const p0 = { x: 0, y: 0 };
  const p1 = { x: lean * 0.12, y: -height * 0.36 };
  const p2 = { x: lean * 0.95, y: -height * 0.7 };
  const p3 = { x: lean * 0.55, y: -height };
  const stem = sample(p0, p1, p2, p3, 22);
  const stemWidth: [number, number] = [
    species.stem.base * (stage === "sprout" ? 0.42 : idx === 2 ? 0.78 : 1),
    stage === "sprout" ? 2 : species.stem.tip,
  ];
  const top = p3;
  const topAngle = bezierAngle(p0, p1, p2, p3, 1);

  const cotyledons: LeafSpec[] =
    stage === "sprout"
      ? [-1, 1].map((side) => ({
          key: `cot-${side}`,
          base: top,
          angle: topAngle + side * 62,
          size: 21,
          mirror: side,
          tone: 0.35,
        }))
      : [];

  const baseLeaves: LeafSpec[] = [];
  if (idx >= 2) {
    const count = species.id === "fig" ? 1 : 2;
    for (let k = 0; k < count; k++) {
      const side = count === 1 ? (h & 1 ? 1 : -1) : k === 0 ? -1 : 1;
      const t = 0.07 + k * 0.03;
      baseLeaves.push({
        key: `base-${k}`,
        base: bezier(p0, p1, p2, p3, t),
        angle: bezierAngle(p0, p1, p2, p3, t) + side * 74,
        size: species.leafSize * (species.id === "fig" ? 0.78 : 0.62),
        mirror: side,
        tone: 0.2,
      });
    }
  }

  const ratio = state.growth.stepsTotal ? state.growth.stepsDone / state.growth.stepsTotal : 0;
  const decorKind: Decor["kind"] | null =
    idx >= 5
      ? "fruit"
      : idx === 4
        ? "fruitlet"
        : idx === 3
          ? species.flower === "none"
            ? "fruitlet"
            : "flower"
          : null;

  const branches: BranchSpec[] = steps.map((step, i) => {
    const t = n === 1 ? 0.5 : 0.2 + 0.62 * (i / (n - 1));
    const node = bezier(p0, p1, p2, p3, t);
    const stemAngle = bezierAngle(p0, p1, p2, p3, t);
    const side = (i + (h & 1)) % 2 === 1 ? 1 : -1;
    const dir = stemAngle + side * species.branch.angle * (0.9 + rnd(10 + i) * 0.22);
    const length = species.branch.length * (0.86 + rnd(20 + i) * 0.28) * (1.08 - t * 0.32) * vigor;
    const base: BranchSpec = {
      stepId: step.id,
      index: i + 1,
      status: step.status,
      artifactId: step.artifactId,
      title: step.title,
      side,
      node,
      twig: null,
      twigWidth: [species.stem.tip * 1.15, 1],
      leaves: [],
      bud: null,
      decor: null,
      thorn: null,
      anchor: node,
    };

    if (step.status === "pending" || step.status === "blocked") {
      const blocked = step.status === "blocked";
      const angle = stemAngle + side * (blocked ? 112 : 30);
      const pos = polar(node, angle, 2);
      return { ...base, bud: { pos, angle, size: blocked ? 11 : 9.5 }, anchor: polar(node, angle, 11) };
    }

    const reach = step.status === "working" ? length * 0.42 : length;
    const laden =
      step.status === "done" && idx >= 5 ? 9 + rnd(60 + i) * 6 : idx === 4 && step.status === "done" ? 4 : 0;
    const droop = species.branch.droop + laden;
    const end = polar(node, dir + side * droop, reach);
    const c1 = polar(node, dir, reach * 0.36);
    const c2 = polar(node, dir + side * droop * 0.55, reach * 0.74);
    const twig = sample(node, c1, c2, end, 9);
    const endInfo = along(twig, 1);

    if (step.status === "working") {
      return {
        ...base,
        twig,
        twigWidth: [species.stem.tip, 0.9],
        leaves: [
          {
            key: `${step.id}-unfurl`,
            base: endInfo.pos,
            angle: endInfo.angle - side * 18,
            size: species.leafSize * 0.42,
            mirror: side,
            tone: 0.1,
          },
        ],
        bud: { pos: endInfo.pos, angle: endInfo.angle + side * 14, size: 8 },
        anchor: polar(endInfo.pos, endInfo.angle, 10),
      };
    }

    const leaves: LeafSpec[] = [];
    const k = species.leavesPerBranch;
    for (let j = 0; j < k; j++) {
      const terminal = j === k - 1;
      const u = k === 1 ? 1 : 0.38 + 0.62 * (j / (k - 1));
      const at = along(twig, u);
      const flip = j % 2 === 0 ? 1 : -1;
      const angle = terminal
        ? at.angle + side * (species.id === "fig" ? -14 : 6)
        : at.angle + flip * side * (40 + rnd(30 + i * 7 + j) * 16);
      leaves.push({
        key: `${step.id}-l${j}`,
        autumn: idx >= 5 && rnd(55 + i * 7 + j) > 0.72,
        base: at.pos,
        angle,
        size: species.leafSize * (0.8 + rnd(40 + i * 7 + j) * 0.3) * (terminal ? 1.04 : 0.9),
        mirror: terminal ? side : flip * side,
        tone: rnd(50 + i * 7 + j),
      });
    }

    let decor: Decor | null = null;
    if (decorKind) {
      const at = along(twig, species.id === "fig" ? 0.5 + rnd(70 + i) * 0.12 : 0.55 + rnd(70 + i) * 0.18);
      const hang = { x: at.pos.x + side * 3, y: at.pos.y + 4 };
      const fruitSize =
        decorKind === "fruit"
          ? species.fruit.size * (0.5 + rnd(80 + i) * 0.16)
          : decorKind === "fruitlet"
            ? species.fruit.size * (0.3 + 0.2 * ratio)
            : 9;
      decor = {
        kind: decorKind,
        pos: decorKind === "flower" ? polar(at.pos, at.angle - side * 70, 7) : hang,
        size: fruitSize,
        ripeness: decorKind === "fruit" ? 0.9 + rnd(90 + i) * 0.1 : 0.15 + 0.5 * ratio,
        angle: at.angle - side * 70,
        tilt: (rnd(100 + i) - 0.5) * 18,
      };
    }

    const thorn = species.thorns
      ? (() => {
          const at = along(twig, 0.22);
          return { pos: at.pos, angle: at.angle - side * 60 };
        })()
      : null;

    const tip = leaves[leaves.length - 1];
    return {
      ...base,
      twig,
      leaves,
      decor,
      thorn,
      anchor: polar(tip.base, tip.angle, tip.size * 0.55),
    };
  });

  branches.forEach((branch, i) => {
    if (branch.status !== "done") return;
    const t = n === 1 ? 0.5 : 0.2 + 0.62 * (i / (n - 1));
    const at = bezier(p0, p1, p2, p3, Math.max(0.1, t - 0.07));
    const a = bezierAngle(p0, p1, p2, p3, t);
    baseLeaves.push({
      key: `stem-${branch.stepId}`,
      base: at,
      angle: a - branch.side * (58 + rnd(400 + i) * 14),
      size: species.leafSize * (species.id === "fig" ? 0.55 : 0.66),
      mirror: -branch.side,
      tone: 0.15 + rnd(410 + i) * 0.4,
    });
  });

  let apex: ApexSpec | null = null;
  if (idx >= 2) {
    const lastSide = branches.length ? branches[branches.length - 1].side : 1;
    const hangSide = -lastSide;
    if (idx === 2) apex = { kind: "bud", pos: top, angle: topAngle, size: 11, ripeness: 0, stalk: null };
    else if (idx === 3 && species.flower !== "none")
      apex = { kind: "flower", pos: top, angle: topAngle, size: 15, ripeness: 0, stalk: null };
    else {
      const ripe = idx >= 5;
      const size = ripe ? species.fruit.size * vigor : species.fruit.size * (0.42 + 0.42 * ratio);
      const attach = polar(top, topAngle + hangSide * 78, 13);
      const stalk = sample(top, polar(top, topAngle + hangSide * 30, 7), polar(attach, -90, 4), attach, 6);
      apex = {
        kind: ripe ? "fruit" : "fruitlet",
        pos: attach,
        angle: topAngle,
        size,
        ripeness: ripe ? 1 : 0.15 + 0.6 * ratio,
        stalk,
      };
    }
  }

  const roots: RootSpec[] = [];
  const hairs: string[] = [];
  const depth = stage === "sprout" ? 44 : 66 + 9 * Math.min(Math.max(n, 2), 5);
  const tap = sample(
    { x: 0, y: 0 },
    { x: lean * 0.08, y: depth * 0.32 },
    { x: -lean * 0.18 + (rnd(60) - 0.5) * 12, y: depth * 0.7 },
    { x: (rnd(61) - 0.5) * 14, y: depth },
    12,
  );
  roots.push({ key: "tap", points: tap, w0: stemWidth[0] * 0.85, w1: 0.8, stepId: null });
  const addHairs = (tip: Pt, salt: number) => {
    for (let k = 0; k < 6; k++) {
      const a = 40 + rnd(salt + k) * 100;
      const len = 3 + rnd(salt + k + 9) * 4;
      const s = polar(tip, -90 + rnd(salt + k + 3) * 180, rnd(salt + k + 5) * 6);
      const e = polar(s, a, len);
      hairs.push(`M ${r1(s.x)} ${r1(s.y)} L ${r1(e.x)} ${r1(e.y)}`);
    }
  };
  addHairs(tap[tap.length - 1], 70);

  if (idx >= 2) {
    branches.forEach((branch, i) => {
      const t = 0.16 + 0.64 * (n === 1 ? 0.4 : i / Math.max(1, n - 1));
      const start = along(tap, t).pos;
      const side = branch.side;
      const length = (40 + rnd(80 + i) * 38) * (1 - t * 0.25);
      const points = sample(
        start,
        { x: start.x + side * length * 0.38, y: start.y + 3 },
        { x: start.x + side * length * 0.8, y: start.y + length * 0.22 },
        { x: start.x + side * length * 0.95, y: start.y + length * 0.5 + 10 },
        9,
      );
      roots.push({ key: `root-${branch.stepId}`, points, w0: 2.8, w1: 0.6, stepId: branch.stepId });
      addHairs(points[points.length - 1], 100 + i * 13);
      const fork = along(points, 0.55).pos;
      const forkEnd = { x: fork.x + side * (8 + rnd(120 + i) * 10), y: fork.y + 14 + rnd(130 + i) * 10 };
      roots.push({
        key: `rootlet-${branch.stepId}`,
        points: sample(fork, { x: fork.x + side * 4, y: fork.y + 4 }, { x: forkEnd.x, y: forkEnd.y - 6 }, forkEnd, 5),
        w0: 1.2,
        w1: 0.4,
        stepId: null,
      });
    });
  }

  const tag =
    idx >= 5
      ? (() => {
          const knot = bezier(p0, p1, p2, p3, 0.44);
          const side = branches.length ? -branches[Math.floor(branches.length / 2)].side : 1;
          return { knot, card: { x: knot.x + side * 30, y: knot.y + 26 } };
        })()
      : null;

  return {
    species,
    stageIndex: idx,
    height,
    stem,
    stemWidth,
    top,
    topAngle,
    cotyledons,
    baseLeaves,
    branches,
    apex,
    roots,
    hairs: hairs.join(" "),
    seed,
    tag,
  };
}
