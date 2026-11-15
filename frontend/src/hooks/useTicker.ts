"use client";

import { useEffect, useRef, useState } from "react";

export function useNow(active: boolean, every = 1000) {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    if (!active) return;
    const id = setInterval(() => setNow(Date.now()), every);
    return () => clearInterval(id);
  }, [active, every]);
  return now;
}

export function useTypewriter(text: string) {
  const [shown, setShown] = useState(text.length);
  const shownRef = useRef(text.length);
  const previous = useRef(text);

  useEffect(() => {
    const head = previous.current.slice(0, 40);
    if (!text.startsWith(head) || text.length < shownRef.current) shownRef.current = 0;
    previous.current = text;
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
      shownRef.current = text.length;
      const frame = requestAnimationFrame(() => setShown(text.length));
      return () => cancelAnimationFrame(frame);
    }
    let frame = 0;
    let last = performance.now();
    const step = (t: number) => {
      const dt = t - last;
      last = t;
      const gap = text.length - shownRef.current;
      if (gap <= 0) {
        shownRef.current = text.length;
        setShown(text.length);
        return;
      }
      const speed = Math.max(45, gap * 1.6);
      shownRef.current = Math.min(text.length, shownRef.current + Math.max(1, Math.round((speed * dt) / 1000)));
      setShown(shownRef.current);
      frame = requestAnimationFrame(step);
    };
    frame = requestAnimationFrame(step);
    return () => cancelAnimationFrame(frame);
  }, [text]);

  return text.slice(0, shown);
}
