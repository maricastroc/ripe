const timeFormat = new Intl.DateTimeFormat("en-US", { hour: "numeric", minute: "2-digit" });
const dayFormat = new Intl.DateTimeFormat("en-US", { weekday: "long" });
const dateFormat = new Intl.DateTimeFormat("en-US", { month: "short", day: "numeric" });

function startOfDay(d: Date) {
  return new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
}

export function returnWhen(iso: string | null, now = new Date()) {
  if (!iso) return null;
  const d = new Date(iso);
  if (d.getTime() <= now.getTime()) return null;
  const days = Math.round((startOfDay(d) - startOfDay(now)) / 86_400_000);
  const time = timeFormat.format(d);
  if (days === 0) return `today at ${time}`;
  if (days === 1) return `tomorrow at ${time}`;
  if (days < 7) return `${dayFormat.format(d)} at ${time}`;
  return `on ${dateFormat.format(d)}`;
}

export function formatReturn(iso: string | null, now = new Date()) {
  if (!iso) return null;
  const when = returnWhen(iso, now);
  return when ? `back ${when}` : "you planned to be back by now";
}

export function formatClock(iso: string) {
  return timeFormat.format(new Date(iso));
}

export function formatRelative(iso: string, now = new Date()) {
  const diff = Math.round((now.getTime() - new Date(iso).getTime()) / 1000);
  if (diff < 10) return "just now";
  if (diff < 60) return `${diff}s ago`;
  if (diff < 3600) return `${Math.floor(diff / 60)} min ago`;
  if (diff < 86_400) return `${Math.floor(diff / 3600)} h ago`;
  return dateFormat.format(new Date(iso));
}

export function formatDayStamp(iso: string) {
  const d = new Date(iso);
  return `${dateFormat.format(d)} · ${timeFormat.format(d)}`;
}

export type ReturnOption = { id: string; label: string; at: () => Date };

function atHour(daysAhead: number, hour: number) {
  const d = new Date();
  d.setDate(d.getDate() + daysAhead);
  d.setHours(hour, 0, 0, 0);
  return d;
}

export const RETURN_OPTIONS: ReturnOption[] = [
  {
    id: "tonight",
    label: "tonight",
    at: () => {
      const d = atHour(0, 20);
      return d.getTime() - Date.now() < 60 * 60 * 1000 ? new Date(Date.now() + 2 * 3600 * 1000) : d;
    },
  },
  { id: "tomorrow", label: "tomorrow", at: () => atHour(1, 9) },
  { id: "days", label: "in three days", at: () => atHour(3, 9) },
  {
    id: "week",
    label: "next week",
    at: () => {
      const d = atHour(1, 9);
      while (d.getDay() !== 1) d.setDate(d.getDate() + 1);
      return d;
    },
  },
];
