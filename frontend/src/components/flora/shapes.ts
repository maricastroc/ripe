import { type Pt, closedSmooth, openSmooth, place, polygon, r1 } from "./geometry";

export type BladeForm = {
  type: "blade";
  width: number;
  skew: number;
  point: number;
  teeth: number;
  toothDepth: number;
};

export type PalmateForm = {
  type: "palmate";
  lobes: { angle: number; reach: number }[];
  sinus: number;
  width: number;
};

export type LeafForm = BladeForm | PalmateForm;

type Outline = { points: Pt[]; jagged: boolean; half: Pt[]; veins: Pt[][] };

const cache = new Map<string, Outline>();

function bladeOutline(form: BladeForm): Outline {
  const n = form.teeth > 0 ? form.teeth * 2 : 16;
  const upper: Pt[] = [];
  const lower: Pt[] = [];
  const halfWidth = (s: number) => (form.width / 2) * Math.pow(Math.sin(Math.PI * Math.pow(s, form.skew)), form.point);
  for (let i = 0; i <= n; i++) {
    const s = i / n;
    const inside = s > 0.1 && s < 0.94;
    const tooth = form.teeth > 0 && inside ? (i % 2 === 1 ? 1 : 1 - form.toothDepth) : 1;
    const w = halfWidth(s) * tooth;
    upper.push({ x: s, y: -w });
    lower.push({ x: s, y: w * 0.94 });
  }
  const points = [...upper, ...lower.slice(1, -1).reverse()];
  const mid = upper.map((p) => ({ x: p.x, y: p.y * 0.02 }));
  const half = [...upper, ...mid.slice(1, -1).reverse()];
  const veins: Pt[][] = [
    [
      { x: 0, y: 0 },
      { x: 0.5, y: -0.01 },
      { x: 0.96, y: 0 },
    ],
  ];
  for (const s of [0.2, 0.36, 0.52, 0.68, 0.82]) {
    const e = Math.min(0.97, s + 0.15);
    const hw = halfWidth(e) * 0.82;
    veins.push([
      { x: s, y: 0 },
      { x: s + 0.05, y: -hw * 0.45 },
      { x: e, y: -hw },
    ]);
    veins.push([
      { x: s, y: 0 },
      { x: s + 0.05, y: hw * 0.42 },
      { x: e, y: hw * 0.92 },
    ]);
  }
  return { points, jagged: form.teeth > 0, half, veins };
}

function angleDiff(a: number, b: number) {
  let d = a - b;
  while (d > Math.PI) d -= 2 * Math.PI;
  while (d < -Math.PI) d += 2 * Math.PI;
  return d;
}

function palmateOutline(form: PalmateForm): Outline {
  const m = 72;
  const raw: Pt[] = [];
  const radius = (phi: number) => {
    let r = form.sinus;
    for (const lobe of form.lobes) {
      const d = angleDiff(phi, (lobe.angle * Math.PI) / 180);
      r += (lobe.reach - form.sinus) * Math.exp(-Math.pow(d / 0.3, 2));
    }
    const back = Math.exp(-Math.pow(angleDiff(phi, Math.PI) / 0.25, 2));
    return r * (1 - back * 0.55);
  };
  for (let k = 0; k < m; k++) {
    const phi = -Math.PI + (2 * Math.PI * k) / m;
    const r = radius(phi);
    raw.push({ x: Math.cos(phi) * r, y: Math.sin(phi) * r * form.width });
  }
  const baseX = Math.cos(Math.PI) * radius(Math.PI);
  const tipX = radius(0);
  const span = tipX - baseX;
  const norm = (p: Pt) => ({ x: (p.x - baseX) / span, y: p.y / span });
  const points = raw.map(norm);
  const center = norm({ x: 0, y: 0 });
  const half = [center, ...points.filter((p, i) => i <= m / 2 && p.y <= 0.001)];
  const veins = form.lobes.map((lobe) => {
    const tip = norm({
      x: Math.cos((lobe.angle * Math.PI) / 180) * lobe.reach * 0.88,
      y: Math.sin((lobe.angle * Math.PI) / 180) * lobe.reach * 0.88 * form.width,
    });
    return [{ x: 0.02, y: 0 }, { x: (center.x + tip.x) / 2, y: tip.y * 0.45 }, tip];
  });
  return { points, jagged: false, half, veins };
}

export function leafOutline(form: LeafForm) {
  const key = JSON.stringify(form);
  const hit = cache.get(key);
  if (hit) return hit;
  const outline = form.type === "blade" ? bladeOutline(form) : palmateOutline(form);
  cache.set(key, outline);
  return outline;
}

export type LeafPaths = { body: string; shade: string; veins: string };

export function leafPaths(form: LeafForm, base: Pt, angle: number, size: number, mirror: number): LeafPaths {
  const outline = leafOutline(form);
  const pts = place(outline.points, base, angle, size, mirror);
  const body = outline.jagged ? polygon(pts) : closedSmooth(pts);
  const shade = closedSmooth(place(outline.half, base, angle, size, mirror));
  const veins = outline.veins
    .map((v) => {
      const [a, b, c] = place(v, base, angle, size, mirror);
      return `M ${r1(a.x)} ${r1(a.y)} Q ${r1(b.x)} ${r1(b.y)} ${r1(c.x)} ${r1(c.y)}`;
    })
    .join(" ");
  return { body, shade, veins };
}

const BUD: BladeForm = { type: "blade", width: 0.52, skew: 1.5, point: 0.85, teeth: 0, toothDepth: 0 };

export function budPaths(base: Pt, angle: number, size: number) {
  const outline = leafOutline(BUD);
  const body = closedSmooth(place(outline.points, base, angle, size, 1));
  const scale = closedSmooth(place(outline.half, base, angle, size * 0.9, 1));
  return { body, scale };
}

const PETAL_ROUND: BladeForm = { type: "blade", width: 0.95, skew: 0.75, point: 0.6, teeth: 0, toothDepth: 0 };
const PETAL_STAR: BladeForm = { type: "blade", width: 0.42, skew: 0.9, point: 0.9, teeth: 0, toothDepth: 0 };
const SEPAL: BladeForm = { type: "blade", width: 0.5, skew: 0.8, point: 1.2, teeth: 0, toothDepth: 0 };

export function petalPaths(
  kind: "round" | "star" | "sepal",
  center: Pt,
  count: number,
  rotation: number,
  size: number,
) {
  const form = kind === "round" ? PETAL_ROUND : kind === "star" ? PETAL_STAR : SEPAL;
  const outline = leafOutline(form);
  return Array.from({ length: count }, (_, i) =>
    closedSmooth(place(outline.points, center, rotation + (360 / count) * i, size, 1)),
  );
}

export function ellipsePoints(cx: number, cy: number, rx: number, ry: number, n = 20, wobble = 0, seed = 0): Pt[] {
  return Array.from({ length: n }, (_, i) => {
    const t = (i / n) * Math.PI * 2;
    const w = 1 + wobble * Math.sin(t * 3 + seed);
    return { x: cx + Math.cos(t) * rx * w, y: cy + Math.sin(t) * ry * w };
  });
}

export function fruitBody(shape: "squat" | "oval" | "ovoid" | "tear", attach: Pt, r: number) {
  if (shape === "tear") {
    const pts: Pt[] = [];
    const n = 22;
    for (let i = 0; i < n; i++) {
      const t = (i / n) * Math.PI * 2;
      const y = (1 - Math.cos(t)) / 2;
      const w = Math.sin(t) * (0.35 + 0.72 * Math.pow(y, 0.9));
      pts.push({ x: attach.x + w * r, y: attach.y + y * r * 2.05 });
    }
    return closedSmooth(pts);
  }
  if (shape === "ovoid") {
    const pts = ellipsePoints(attach.x, attach.y + r * 1.08, r * 0.82, r * 1.08, 22);
    const tip = { x: attach.x, y: attach.y + r * 2.32 };
    const idx = pts.reduce((best, p, i) => (p.y > pts[best].y ? i : best), 0);
    pts.splice(idx, 1, { x: tip.x - r * 0.12, y: tip.y - r * 0.12 }, tip, { x: tip.x + r * 0.12, y: tip.y - r * 0.12 });
    return closedSmooth(pts);
  }
  if (shape === "oval") return closedSmooth(ellipsePoints(attach.x, attach.y + r * 1.02, r * 0.88, r * 1.04, 22));
  return closedSmooth(ellipsePoints(attach.x, attach.y + r * 0.86, r * 1.1, r * 0.88, 22, 0.035, 1.3));
}

export function stemPath(points: Pt[]) {
  return openSmooth(points);
}
