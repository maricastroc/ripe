"use client";

import { useEffect, useState } from "react";
import { formatClock } from "@/lib/format";
import { useI18n } from "@/lib/i18n/context";

export type Sky = "dawn" | "day" | "dusk" | "night";

const SKIES: Sky[] = ["dawn", "day", "dusk", "night"];

export function skyAt(date: Date): Sky {
  const h = date.getHours() + date.getMinutes() / 60;
  if (h >= 5.5 && h < 8) return "dawn";
  if (h >= 8 && h < 17.5) return "day";
  if (h >= 17.5 && h < 20) return "dusk";
  return "night";
}

export function useSky(): { sky: Sky; time: string | null } {
  const i18n = useI18n();
  const [state, setState] = useState<{ sky: Sky; time: string | null }>({ sky: "day", time: null });
  useEffect(() => {
    const read = () => {
      const forced = new URLSearchParams(window.location.search).get("sky") as Sky | null;
      const now = new Date();
      setState({
        sky: forced && SKIES.includes(forced) ? forced : skyAt(now),
        time: formatClock(i18n, now),
      });
    };
    const first = requestAnimationFrame(read);
    const id = setInterval(read, 60_000);
    return () => {
      cancelAnimationFrame(first);
      clearInterval(id);
    };
  }, [i18n]);
  return state;
}
