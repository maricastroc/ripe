export type PlotKind = "fungus" | "trunk" | "plant" | "packet" | "empty";

export type Plot = { kind: PlotKind; x: number; w: number; index: number };

export type Row = { plots: Plot[]; plantH: number; soilH: number; scaleH: number };

export type BedLayout = { rows: Row[]; mobile: boolean; packetAbove: boolean };

function clamp(min: number, value: number, max: number) {
  return Math.max(min, Math.min(max, value));
}

export function layoutBed(width: number, viewport: number, sizes: number[], room?: number): BedLayout {
  const mobile = width < 640;
  const plants = sizes.length;
  const fungusW = mobile ? 46 : 108;
  const packetW = mobile ? 0 : clamp(272, width * 0.22, 322);
  const minPlant = mobile ? 148 : 230;
  const firstCap = Math.max(1, Math.floor((width - fungusW - packetW) / minPlant));
  const restCap = Math.max(1, Math.floor((width - fungusW) / minPlant));

  const fit = (share: number) => room ?? viewport * share;
  const baseH = mobile
    ? plants <= 1
      ? 330
      : 270
    : plants <= 1
      ? clamp(330, fit(0.52), 540)
      : plants <= 3
        ? clamp(320, fit(0.46), 480)
        : plants <= firstCap
          ? clamp(310, fit(0.42), 420)
          : clamp(310, fit(0.42), 340);

  const counts: number[] = [Math.min(plants, firstCap)];
  const rest = plants - counts[0];
  if (rest > 0) {
    const rows = Math.ceil(rest / restCap);
    for (let r = 0; r < rows; r++)
      counts.push(Math.ceil((rest - counts.slice(1).reduce((a, b) => a + b, 0)) / (rows - r)));
  }

  let index = 0;
  const rows: Row[] = counts.map((count, r) => {
    const tallest = count ? Math.max(...sizes.slice(index, index + count)) : 1;
    const withPacket = r === 0 && !mobile;
    const plantH = Math.round(withPacket ? Math.max(baseH, 330) : baseH * Math.max(0.5, tallest));
    const plots: Plot[] = [{ kind: r === 0 ? "fungus" : "trunk", x: 0, w: fungusW, index: -1 }];
    const available = width - fungusW - (withPacket ? packetW : 0);
    if (count === 0) {
      plots.push({ kind: "empty", x: fungusW, w: available, index: -1 });
    } else {
      const w = Math.min(available / count, Math.max(minPlant, plantH * 1.15));
      const offset = fungusW + (available - w * count) / 2;
      for (let i = 0; i < count; i++) plots.push({ kind: "plant", x: offset + i * w, w, index: index++ });
    }
    if (withPacket) plots.push({ kind: "packet", x: width - packetW, w: packetW, index: -1 });
    return {
      plots,
      plantH,
      scaleH: Math.round(baseH),
      soilH: Math.round(Math.max(mobile ? 204 : 222, Math.min(baseH, plantH * 1.3) * 0.44)),
    };
  });

  return { rows, mobile, packetAbove: mobile };
}

export function naturalSize(stage: string, steps: number) {
  if (stage === "seed") return 0.32;
  if (stage === "sprout") return 0.42;
  if (stage === "leafing") return 0.62 + Math.min(steps, 5) * 0.06;
  return 1;
}

export const VIEW = { left: -160, top: -330, width: 320, height: 460, ground: 330 };

export function specimenBox(scaleH: number, plotW: number) {
  const byHeight = (scaleH * VIEW.height) / VIEW.ground;
  const byWidth = (plotW * 1.28 * VIEW.height) / VIEW.width;
  const height = Math.min(byHeight, byWidth);
  const scale = height / VIEW.height;
  return { height, width: VIEW.width * scale, scale, ground: VIEW.ground * scale };
}
