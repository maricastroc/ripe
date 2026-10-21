"use client";

import { useEffect, useState } from "react";

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
  const [state, setState] = useState<{ sky: Sky; time: string | null }>({ sky: "day", time: null });
  useEffect(() => {
    const read = () => {
      const forced = new URLSearchParams(window.location.search).get("sky") as Sky | null;
      const now = new Date();
      setState({
        sky: forced && SKIES.includes(forced) ? forced : skyAt(now),
        time: new Intl.DateTimeFormat("en-US", { hour: "numeric", minute: "2-digit" }).format(now),
      });
    };
    const first = requestAnimationFrame(read);
    const id = setInterval(read, 60_000);
    return () => {
      cancelAnimationFrame(first);
      clearInterval(id);
    };
  }, []);
  return state;
}
