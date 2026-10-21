import Link from "next/link";
import { rand, hash } from "@/components/flora/geometry";
import { speciesOf } from "@/components/flora/species";
import { formatRelative } from "@/lib/format";
import type { TaskSummary } from "@/lib/types";
import { catalog } from "./PlantPlot";

function Jar({ task }: { task: TaskSummary }) {
  const species = speciesOf(task.fruit);
  const h = hash(task.id);
  const fruits = Array.from({ length: 5 }, (_, i) => ({
    x: 22 + rand(h, i) * 36,
    y: 60 + rand(h, i + 10) * 34,
    r: 8 + rand(h, i + 20) * 4,
  }));
  return (
    <svg viewBox="0 0 80 112" className="block h-auto w-full" aria-hidden>
      <defs>
        <linearGradient id={`jar-${task.id}`} x1="0" x2="1">
          <stop offset="0" stopColor="#ffffff" stopOpacity="0.32" />
          <stop offset="0.25" stopColor="#ffffff" stopOpacity="0.06" />
          <stop offset="0.8" stopColor="#ffffff" stopOpacity="0.02" />
          <stop offset="1" stopColor="#ffffff" stopOpacity="0.2" />
        </linearGradient>
      </defs>
      <rect x={14} y={6} width={52} height={12} rx={2} fill="#a5793f" stroke="#3a2818" strokeWidth={1} />
      <path
        d="M 18 18 L 62 18 L 66 30 Q 70 36 70 46 L 70 100 Q 70 108 62 108 L 18 108 Q 10 108 10 100 L 10 46 Q 10 36 14 30 Z"
        fill="rgba(210,230,220,0.18)"
        stroke="#d9e6df"
        strokeWidth={1.4}
        strokeOpacity={0.7}
      />
      {fruits.map((f, i) => (
        <circle
          key={i}
          cx={Math.round(f.x)}
          cy={Math.round(f.y)}
          r={Math.round(f.r)}
          fill={species.fruit.ripe}
          stroke={species.fruit.deep}
          strokeWidth={0.8}
          opacity={0.92}
        />
      ))}
      <path d="M 12 62 L 68 62" stroke="rgba(255,240,200,0.25)" strokeWidth={0.8} />
      <path
        d="M 18 18 L 62 18 L 66 30 Q 70 36 70 46 L 70 100 Q 70 108 62 108 L 18 108 Q 10 108 10 100 L 10 46 Q 10 36 14 30 Z"
        fill={`url(#jar-${task.id})`}
      />
      <path
        d="M 17 40 Q 15 70 17 98"
        stroke="#ffffff"
        strokeOpacity={0.5}
        strokeWidth={2.2}
        fill="none"
        strokeLinecap="round"
      />
    </svg>
  );
}

export function Pantry({ tasks, numbers }: { tasks: TaskSummary[]; numbers: Map<string, number> }) {
  if (!tasks.length) return null;
  return (
    <section className="relative px-4 pb-6 pt-14 sm:px-10" aria-labelledby="pantry-title">
      <div className="flex flex-wrap items-baseline justify-between gap-3">
        <h2 id="pantry-title" className="font-display text-2xl italic text-[#efe3c7] font-display-wonk">
          The pantry
        </h2>
        <p className="typewriter text-[12px] text-[#c9b48f]">{tasks.length} harvested · preserved, still readable</p>
      </div>
      <ul className="mt-6 flex flex-wrap items-end gap-x-5 gap-y-0 px-3">
        {tasks.map((task) => (
          <li key={task.id} className="w-[96px]">
            <Link
              href={`/t/${task.id}`}
              className="focus-ring group relative block"
              aria-label={`${task.title}, harvested`}
              title={task.harvestedAt ? `harvested ${formatRelative(task.harvestedAt)}` : undefined}
            >
              <div className="transition-transform duration-300 group-hover:-translate-y-1">
                <Jar task={task} />
              </div>
              <div className="paper absolute inset-x-[10px] top-[44%] rotate-[-2deg] rounded-[1px] px-1.5 py-1 text-center shadow-sm">
                <p className="typewriter text-[8.5px] text-ink-3">{catalog(numbers.get(task.id) ?? 0)}</p>
                <p className="line-clamp-2 font-display text-[10.5px] leading-tight text-ink">{task.title}</p>
              </div>
            </Link>
          </li>
        ))}
      </ul>
      <div
        className="h-3 rounded-sm bg-gradient-to-b from-[#a8784f] to-[#6e4a2d] shadow-[0_8px_14px_-6px_rgba(0,0,0,0.6)]"
        aria-hidden
      />
    </section>
  );
}
