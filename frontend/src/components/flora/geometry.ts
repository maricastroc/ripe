export type Pt = { x: number; y: number };

export function hash(input: string) {
  let h = 2166136261;
  for (let i = 0; i < input.length; i++) {
    h ^= input.charCodeAt(i);
    h = Math.imul(h, 16777619);
  }
  return h >>> 0;
}

export function rand(seed: number, salt: number) {
  let t = (seed ^ Math.imul(salt + 0x9e3779b9, 0x85ebca6b)) >>> 0;
  t = Math.imul(t ^ (t >>> 16), 0x7feb352d);
  t = Math.imul(t ^ (t >>> 15), 0x846ca68b);
  t ^= t >>> 16;
  return (t >>> 0) / 4294967296;
}

export function r1(n: number) {
  return Math.round(n * 10) / 10;
}

export function deg(d: number) {
  return (d * Math.PI) / 180;
}

export function lerp(a: number, b: number, t: number) {
  return a + (b - a) * t;
}

export function polar(origin: Pt, angle: number, length: number): Pt {
  return { x: origin.x + Math.cos(deg(angle)) * length, y: origin.y + Math.sin(deg(angle)) * length };
}

export function bezier(p0: Pt, p1: Pt, p2: Pt, p3: Pt, t: number): Pt {
  const u = 1 - t;
  return {
    x: u * u * u * p0.x + 3 * u * u * t * p1.x + 3 * u * t * t * p2.x + t * t * t * p3.x,
    y: u * u * u * p0.y + 3 * u * u * t * p1.y + 3 * u * t * t * p2.y + t * t * t * p3.y,
  };
}

export function bezierAngle(p0: Pt, p1: Pt, p2: Pt, p3: Pt, t: number) {
  const u = 1 - t;
  const dx = 3 * u * u * (p1.x - p0.x) + 6 * u * t * (p2.x - p1.x) + 3 * t * t * (p3.x - p2.x);
  const dy = 3 * u * u * (p1.y - p0.y) + 6 * u * t * (p2.y - p1.y) + 3 * t * t * (p3.y - p2.y);
  if (Math.abs(dx) < 1e-6 && Math.abs(dy) < 1e-6) return -90;
  return (Math.atan2(dy, dx) * 180) / Math.PI;
}

export function sample(p0: Pt, p1: Pt, p2: Pt, p3: Pt, n: number) {
  return Array.from({ length: n }, (_, i) => bezier(p0, p1, p2, p3, i / (n - 1)));
}

export function place(points: Pt[], base: Pt, angle: number, scale: number, mirror = 1): Pt[] {
  const c = Math.cos(deg(angle));
  const s = Math.sin(deg(angle));
  return points.map((p) => {
    const x = p.x * scale;
    const y = p.y * scale * mirror;
    return { x: base.x + x * c - y * s, y: base.y + x * s + y * c };
  });
}

export function closedSmooth(points: Pt[]) {
  const n = points.length;
  if (n < 3) return "";
  let d = `M ${r1(points[0].x)} ${r1(points[0].y)}`;
  for (let i = 0; i < n; i++) {
    const p0 = points[(i - 1 + n) % n];
    const p1 = points[i];
    const p2 = points[(i + 1) % n];
    const p3 = points[(i + 2) % n];
    const c1 = { x: p1.x + (p2.x - p0.x) / 6, y: p1.y + (p2.y - p0.y) / 6 };
    const c2 = { x: p2.x - (p3.x - p1.x) / 6, y: p2.y - (p3.y - p1.y) / 6 };
    d += ` C ${r1(c1.x)} ${r1(c1.y)} ${r1(c2.x)} ${r1(c2.y)} ${r1(p2.x)} ${r1(p2.y)}`;
  }
  return `${d} Z`;
}

export function openSmooth(points: Pt[]) {
  const n = points.length;
  if (n < 2) return "";
  let d = `M ${r1(points[0].x)} ${r1(points[0].y)}`;
  for (let i = 0; i < n - 1; i++) {
    const p0 = points[Math.max(0, i - 1)];
    const p1 = points[i];
    const p2 = points[i + 1];
    const p3 = points[Math.min(n - 1, i + 2)];
    const c1 = { x: p1.x + (p2.x - p0.x) / 6, y: p1.y + (p2.y - p0.y) / 6 };
    const c2 = { x: p2.x - (p3.x - p1.x) / 6, y: p2.y - (p3.y - p1.y) / 6 };
    d += ` C ${r1(c1.x)} ${r1(c1.y)} ${r1(c2.x)} ${r1(c2.y)} ${r1(p2.x)} ${r1(p2.y)}`;
  }
  return d;
}

export function polygon(points: Pt[]) {
  return `M ${points.map((p) => `${r1(p.x)} ${r1(p.y)}`).join(" L ")} Z`;
}

export function tapered(center: Pt[], w0: number, w1: number) {
  const n = center.length;
  const left: Pt[] = [];
  const right: Pt[] = [];
  for (let i = 0; i < n; i++) {
    const a = center[Math.max(0, i - 1)];
    const b = center[Math.min(n - 1, i + 1)];
    let tx = b.x - a.x;
    let ty = b.y - a.y;
    const len = Math.hypot(tx, ty) || 1;
    tx /= len;
    ty /= len;
    const w = lerp(w0, w1, i / (n - 1)) / 2;
    left.push({ x: center[i].x - ty * w, y: center[i].y + tx * w });
    right.push({ x: center[i].x + ty * w, y: center[i].y - tx * w });
  }
  const end = center[n - 1];
  const prev = center[n - 2] ?? end;
  const dl = Math.hypot(end.x - prev.x, end.y - prev.y) || 1;
  const tip = { x: end.x + ((end.x - prev.x) / dl) * w1 * 0.6, y: end.y + ((end.y - prev.y) / dl) * w1 * 0.6 };
  return closedSmooth([...left, tip, ...right.reverse()]);
}

export function mixHex(a: string, b: string, t: number) {
  const pa = parseInt(a.slice(1), 16);
  const pb = parseInt(b.slice(1), 16);
  const ch = (p: number, shift: number) => (p >> shift) & 255;
  const mix = (shift: number) => Math.round(ch(pa, shift) + (ch(pb, shift) - ch(pa, shift)) * t);
  return `#${((mix(16) << 16) | (mix(8) << 8) | mix(0)).toString(16).padStart(6, "0")}`;
}
