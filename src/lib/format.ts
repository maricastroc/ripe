const timeFormat = new Intl.DateTimeFormat("pt-BR", { hour: "2-digit", minute: "2-digit" });
const dayFormat = new Intl.DateTimeFormat("pt-BR", { weekday: "long" });
const dateFormat = new Intl.DateTimeFormat("pt-BR", { day: "numeric", month: "short" });

function startOfDay(d: Date) {
  return new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
}

export function formatReturn(iso: string | null, now = new Date()) {
  if (!iso) return null;
  const d = new Date(iso);
  const days = Math.round((startOfDay(d) - startOfDay(now)) / 86_400_000);
  const time = timeFormat.format(d);
  if (d.getTime() <= now.getTime()) return "você já podia voltar";
  if (days === 0) return `volta hoje, ${time}`;
  if (days === 1) return `volta amanhã, ${time}`;
  if (days < 7) return `volta ${dayFormat.format(d)}, ${time}`;
  return `volta em ${dateFormat.format(d)}`;
}

export function formatClock(iso: string) {
  return timeFormat.format(new Date(iso));
}

export function formatRelative(iso: string, now = new Date()) {
  const diff = Math.round((now.getTime() - new Date(iso).getTime()) / 1000);
  if (diff < 10) return "agora";
  if (diff < 60) return `há ${diff}s`;
  if (diff < 3600) return `há ${Math.floor(diff / 60)} min`;
  if (diff < 86_400) return `há ${Math.floor(diff / 3600)} h`;
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
    label: "hoje à noite",
    at: () => {
      const d = atHour(0, 20);
      return d.getTime() - Date.now() < 60 * 60 * 1000 ? new Date(Date.now() + 2 * 3600 * 1000) : d;
    },
  },
  { id: "tomorrow", label: "amanhã", at: () => atHour(1, 9) },
  { id: "days", label: "em três dias", at: () => atHour(3, 9) },
  {
    id: "week",
    label: "semana que vem",
    at: () => {
      const d = atHour(1, 9);
      while (d.getDay() !== 1) d.setDate(d.getDate() + 1);
      return d;
    },
  },
];
