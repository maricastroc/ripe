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
  const x = Math.sin(seed * 0.0001 + salt * 12.9898) * 43758.5453;
  return x - Math.floor(x);
}

function r1(n: number) {
  return Math.round(n * 10) / 10;
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

export function stemPath(p0: Pt, p1: Pt, p2: Pt, p3: Pt) {
  return `M ${r1(p0.x)} ${r1(p0.y)} C ${r1(p1.x)} ${r1(p1.y)} ${r1(p2.x)} ${r1(p2.y)} ${r1(p3.x)} ${r1(p3.y)}`;
}

export function leafPath(base: Pt, angle: number, length: number, width: number) {
  const a = (angle * Math.PI) / 180;
  const ux = Math.cos(a);
  const uy = Math.sin(a);
  const nx = -uy;
  const ny = ux;
  const at = (along: number, across: number) => ({
    x: base.x + ux * length * along + nx * width * across,
    y: base.y + uy * length * along + ny * width * across,
  });
  const c1 = at(0.22, 1);
  const c2 = at(0.72, 0.85);
  const tip = at(1, 0);
  const c3 = at(0.7, -0.72);
  const c4 = at(0.2, -0.66);
  return `M ${r1(base.x)} ${r1(base.y)} C ${r1(c1.x)} ${r1(c1.y)} ${r1(c2.x)} ${r1(c2.y)} ${r1(tip.x)} ${r1(tip.y)} C ${r1(c3.x)} ${r1(c3.y)} ${r1(c4.x)} ${r1(c4.y)} ${r1(base.x)} ${r1(base.y)} Z`;
}

export function ribPath(base: Pt, angle: number, length: number) {
  const a = (angle * Math.PI) / 180;
  const tip = { x: base.x + Math.cos(a) * length * 0.86, y: base.y + Math.sin(a) * length * 0.86 };
  const mid = {
    x: base.x + Math.cos(a) * length * 0.45 - Math.sin(a) * length * 0.04,
    y: base.y + Math.sin(a) * length * 0.45 + Math.cos(a) * length * 0.04,
  };
  return `M ${r1(base.x)} ${r1(base.y)} Q ${r1(mid.x)} ${r1(mid.y)} ${r1(tip.x)} ${r1(tip.y)}`;
}

export function ellipsePath(cx: number, cy: number, rx: number, ry: number) {
  const k = 0.5523;
  return [
    `M ${r1(cx - rx)} ${r1(cy)}`,
    `C ${r1(cx - rx)} ${r1(cy - k * ry)} ${r1(cx - k * rx)} ${r1(cy - ry)} ${r1(cx)} ${r1(cy - ry)}`,
    `C ${r1(cx + k * rx)} ${r1(cy - ry)} ${r1(cx + rx)} ${r1(cy - k * ry)} ${r1(cx + rx)} ${r1(cy)}`,
    `C ${r1(cx + rx)} ${r1(cy + k * ry)} ${r1(cx + k * rx)} ${r1(cy + ry)} ${r1(cx)} ${r1(cy + ry)}`,
    `C ${r1(cx - k * rx)} ${r1(cy + ry)} ${r1(cx - rx)} ${r1(cy + k * ry)} ${r1(cx - rx)} ${r1(cy)} Z`,
  ].join(" ");
}

export function teardropPath(top: Pt, w: number, h: number) {
  return [
    `M ${r1(top.x)} ${r1(top.y)}`,
    `C ${r1(top.x + w * 0.42)} ${r1(top.y + h * 0.12)} ${r1(top.x + w)} ${r1(top.y + h * 0.5)} ${r1(top.x + w * 0.62)} ${r1(top.y + h * 0.88)}`,
    `C ${r1(top.x + w * 0.3)} ${r1(top.y + h * 1.04)} ${r1(top.x - w * 0.3)} ${r1(top.y + h * 1.04)} ${r1(top.x - w * 0.62)} ${r1(top.y + h * 0.88)}`,
    `C ${r1(top.x - w)} ${r1(top.y + h * 0.5)} ${r1(top.x - w * 0.42)} ${r1(top.y + h * 0.12)} ${r1(top.x)} ${r1(top.y)} Z`,
  ].join(" ");
}

export function mixHex(a: string, b: string, t: number) {
  const pa = parseInt(a.slice(1), 16);
  const pb = parseInt(b.slice(1), 16);
  const ch = (p: number, shift: number) => (p >> shift) & 255;
  const mix = (shift: number) => Math.round(ch(pa, shift) + (ch(pb, shift) - ch(pa, shift)) * t);
  const out = (mix(16) << 16) | (mix(8) << 8) | mix(0);
  return `#${out.toString(16).padStart(6, "0")}`;
}
