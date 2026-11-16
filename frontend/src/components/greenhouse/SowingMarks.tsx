import { Tuft } from "./PlateSoil";

const PACKET_INSET = 26;
export const HOLE = { x: -80, y: 74 };

export function packetFoot(columnWidth: number) {
  return PACKET_INSET + (columnWidth - PACKET_INSET) / 2;
}

const FOOT = `calc(${PACKET_INSET}px + (100% - ${PACKET_INSET}px) / 2)`;

export function SowingMarks({ empty, next, note }: { empty: boolean; next: string; note: string }) {
  return (
    <div className="plate-marks pointer-events-none relative z-20 h-0 self-start" aria-hidden>
      <svg
        className="absolute overflow-visible"
        style={{ left: `calc(${FOOT} - 146px)`, top: -90, width: 220, height: 200 }}
        viewBox="1094 470 220 200"
      >
        {empty && (
          <g>
            <path
              d="M1081 560C1080 550 1082 546 1082 540H1087C1087 546 1089 550 1088 560Z"
              fill="var(--root)"
              stroke="var(--ink)"
              strokeWidth={0.9}
            />
            <path
              d="M1071 541C1073 527 1097 527 1099 541Z"
              fill="var(--plate-cap)"
              stroke="var(--ink)"
              strokeWidth={1}
            />
            <path
              d="M1097.5 560C1097 554 1098 551 1098 548H1101C1101 551 1102 554 1101.5 560Z"
              fill="var(--root)"
              stroke="var(--ink)"
              strokeWidth={0.9}
            />
            <path
              d="M1092 549C1093 540 1106 540 1107 549Z"
              fill="var(--plate-cap-2)"
              stroke="var(--ink)"
              strokeWidth={1}
            />
          </g>
        )}
        {!empty && <Tuft x={1088} y={560} />}
        <Tuft x={1118} y={560} />
        <path
          d="M1240 484C1256 508 1244 536 1216 556"
          fill="none"
          stroke="var(--cobalt)"
          strokeWidth={1.5}
          strokeDasharray="5 5"
          strokeLinecap="round"
        />
        <path
          d="M1210 566C1190 580 1174 596 1166 614"
          fill="none"
          stroke="var(--paper)"
          strokeWidth={1.5}
          strokeDasharray="5 5"
          strokeLinecap="round"
        />
        <path
          d="M1158 604L1165 617L1175 608"
          fill="none"
          stroke="var(--paper)"
          strokeWidth={1.5}
          strokeLinecap="round"
          strokeLinejoin="round"
        />
        <ellipse cx={1160} cy={634} rx={23} ry={14} fill="var(--plate-hollow)" />
        <ellipse
          cx={1160}
          cy={635}
          rx={8}
          ry={5}
          fill="none"
          stroke="var(--paper)"
          strokeWidth={1.1}
          strokeDasharray="2.5 2.5"
          opacity={0.9}
        />
        {!empty && (
          <text x={1194} y={639} className="typewriter" fontSize={11} letterSpacing={1} fill="var(--line)">
            {next}
          </text>
        )}
      </svg>
      <svg
        className="absolute overflow-visible"
        style={{ right: 0, top: -90, width: 110, height: 200 }}
        viewBox="1274 470 110 200"
      >
        <Tuft x={1296} y={562} />
        <Tuft x={1322} y={562} />
        <Tuft x={1346} y={561} />
        <path d="M1376 496V524" stroke="var(--ink)" strokeWidth={9.5} strokeLinecap="round" />
        <path d="M1376 496V524" stroke="var(--wood)" strokeWidth={7} strokeLinecap="round" />
        <rect x={1372} y={524} width={8} height={11} fill="var(--pebble)" stroke="var(--ink)" strokeWidth={0.9} />
        <path
          d="M1367 535H1385L1382 580Q1376 590 1370 580Z"
          fill="var(--plate-steel)"
          stroke="var(--ink)"
          strokeWidth={1}
        />
      </svg>
      <span
        className="ink absolute -rotate-3 whitespace-nowrap text-[16px]"
        style={{ left: `min(${FOOT} + 24px, 100% - 104px)`, top: "calc(22px - var(--reach, 76px))" }}
      >
        {note}
      </span>
    </div>
  );
}
