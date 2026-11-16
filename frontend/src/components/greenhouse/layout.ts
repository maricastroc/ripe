export type Plot = { kind: "fungus" | "plant"; x: number; w: number; index: number };

export type Strip = { plots: Plot[]; plantH: number; scaleH: number; width: number; compact: boolean };

export function layoutStrip(visible: number, count: number, plantH: number): Strip {
  const compact = visible < 560;
  const fungusW = compact ? 46 : 100;
  const minPlant = compact ? 140 : 168;
  const available = visible - fungusW;
  const w = count ? Math.max(minPlant, Math.min(available / count, Math.max(minPlant, plantH * 1.15))) : 0;
  const used = fungusW + w * count;
  const offset = fungusW + Math.max(0, (visible - used) / 2);
  const plants: Plot[] = Array.from({ length: count }, (_, i) => ({ kind: "plant", x: offset + i * w, w, index: i }));
  return {
    plots: [{ kind: "fungus", x: 0, w: fungusW, index: -1 }, ...plants],
    plantH,
    scaleH: Math.round(plantH * 0.9),
    width: used > visible ? Math.ceil(used + 16) : visible,
    compact,
  };
}

export const VIEW = { left: -160, top: -330, width: 320, height: 460, ground: 330 };

export function specimenBox(scaleH: number, plotW: number) {
  const byHeight = (scaleH * VIEW.height) / VIEW.ground;
  const byWidth = (plotW * 1.28 * VIEW.height) / VIEW.width;
  const height = Math.min(byHeight, byWidth);
  const scale = height / VIEW.height;
  return { height, width: VIEW.width * scale, scale, ground: VIEW.ground * scale };
}
