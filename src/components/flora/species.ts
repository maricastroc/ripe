import type { Fruit } from "@/lib/types";
import type { LeafForm } from "./shapes";

export type SpeciesId = Fruit | "seedling";

export type Species = {
  id: SpeciesId;
  latin: string;
  common: string;
  stem: { color: string; shade: string; woody: boolean; base: number; tip: number; lean: number };
  leaf: LeafForm;
  leafSize: number;
  leafColors: [string, string, string];
  leavesPerBranch: number;
  branch: { angle: number; length: number; droop: number };
  flower: "bell" | "round" | "star" | "none";
  flowerColors: [string, string, string];
  fruit: {
    shape: "squat" | "oval" | "ovoid" | "tear";
    unripe: string;
    mid: string;
    ripe: string;
    deep: string;
    size: number;
  };
  thorns: boolean;
};

export const SPECIES: Record<SpeciesId, Species> = {
  persimmon: {
    id: "persimmon",
    latin: "Diospyros kaki",
    common: "persimmon",
    stem: { color: "#6a4a33", shade: "#4a3222", woody: true, base: 12.5, tip: 3.6, lean: 34 },
    leaf: { type: "blade", width: 0.6, skew: 0.82, point: 0.85, teeth: 0, toothDepth: 0 },
    leafSize: 56,
    leafColors: ["#8bb066", "#4f7d40", "#2b4f2a"],
    leavesPerBranch: 3,
    branch: { angle: 54, length: 72, droop: 12 },
    flower: "bell",
    flowerColors: ["#f6ebc8", "#e2cf98", "#7e8f4a"],
    fruit: { shape: "squat", unripe: "#9db07a", mid: "#dcae48", ripe: "#e2652d", deep: "#b0421a", size: 21 },
    thorns: false,
  },
  plum: {
    id: "plum",
    latin: "Prunus domestica",
    common: "plum",
    stem: { color: "#5b3631", shade: "#3c2220", woody: true, base: 10.5, tip: 3, lean: 40 },
    leaf: { type: "blade", width: 0.44, skew: 1.12, point: 1, teeth: 13, toothDepth: 0.14 },
    leafSize: 48,
    leafColors: ["#9cbb72", "#5c8a47", "#355a2e"],
    leavesPerBranch: 4,
    branch: { angle: 46, length: 80, droop: 18 },
    flower: "round",
    flowerColors: ["#fffaf3", "#f2c4cf", "#e9b93c"],
    fruit: { shape: "oval", unripe: "#a6b67f", mid: "#c07189", ripe: "#6e2f57", deep: "#45193a", size: 19 },
    thorns: false,
  },
  lemon: {
    id: "lemon",
    latin: "Citrus × limon",
    common: "lemon",
    stem: { color: "#596537", shade: "#3c4524", woody: true, base: 11, tip: 3.2, lean: 26 },
    leaf: { type: "blade", width: 0.46, skew: 0.95, point: 1.15, teeth: 0, toothDepth: 0 },
    leafSize: 52,
    leafColors: ["#7fb15f", "#3f7539", "#22461f"],
    leavesPerBranch: 3,
    branch: { angle: 62, length: 66, droop: 8 },
    flower: "star",
    flowerColors: ["#fffdf8", "#e6d3ef", "#f1d34e"],
    fruit: { shape: "ovoid", unripe: "#a6bd6e", mid: "#ccce58", ripe: "#ecc63b", deep: "#c29a1d", size: 19 },
    thorns: true,
  },
  fig: {
    id: "fig",
    latin: "Ficus carica",
    common: "fig",
    stem: { color: "#8c8273", shade: "#625a4e", woody: true, base: 15, tip: 4.6, lean: 28 },
    leaf: {
      type: "palmate",
      lobes: [
        { angle: 0, reach: 1 },
        { angle: 58, reach: 0.86 },
        { angle: -58, reach: 0.86 },
        { angle: 122, reach: 0.58 },
        { angle: -122, reach: 0.58 },
      ],
      sinus: 0.38,
      width: 1,
    },
    leafSize: 78,
    leafColors: ["#9dbb73", "#5f8a4a", "#3a5d33"],
    leavesPerBranch: 1,
    branch: { angle: 40, length: 46, droop: 4 },
    flower: "none",
    flowerColors: ["#a7bd7a", "#7e9a5a", "#5d7a42"],
    fruit: { shape: "tear", unripe: "#9fb283", mid: "#b77f63", ripe: "#7c3f63", deep: "#4f2440", size: 18 },
    thorns: false,
  },
  seedling: {
    id: "seedling",
    latin: "species incerta",
    common: "seedling",
    stem: { color: "#6f8f4f", shade: "#4d6a36", woody: false, base: 5, tip: 2.2, lean: 20 },
    leaf: { type: "blade", width: 0.62, skew: 0.9, point: 0.8, teeth: 0, toothDepth: 0 },
    leafSize: 34,
    leafColors: ["#a2c27c", "#6c9a52", "#41663a"],
    leavesPerBranch: 2,
    branch: { angle: 50, length: 40, droop: 8 },
    flower: "round",
    flowerColors: ["#fffaf3", "#f0e1c6", "#e9b93c"],
    fruit: { shape: "squat", unripe: "#9db07a", mid: "#dcae48", ripe: "#e2652d", deep: "#b0421a", size: 18 },
    thorns: false,
  },
};

export function speciesOf(fruit: Fruit | null | undefined): Species {
  return fruit ? SPECIES[fruit] : SPECIES.seedling;
}
