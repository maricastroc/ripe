import type { I18n } from "./i18n";
import type { Locale } from "./i18n/locales";

type Formats = {
  time: Intl.DateTimeFormat;
  day: Intl.DateTimeFormat;
  date: Intl.DateTimeFormat;
  seconds: Intl.NumberFormat;
  tenths: Intl.NumberFormat;
};

const FORMATS = new Map<Locale, Formats>();

function formats(locale: Locale): Formats {
  let found = FORMATS.get(locale);
  if (!found) {
    found = {
      time: new Intl.DateTimeFormat(locale, { hour: "numeric", minute: "2-digit" }),
      day: new Intl.DateTimeFormat(locale, { weekday: "long" }),
      date: new Intl.DateTimeFormat(locale, { month: "short", day: "numeric" }),
      seconds: new Intl.NumberFormat(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
      tenths: new Intl.NumberFormat(locale, { minimumFractionDigits: 1, maximumFractionDigits: 1 }),
    };
    FORMATS.set(locale, found);
  }
  return found;
}

function startOfDay(d: Date) {
  return new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
}

export function returnWhen({ locale, t }: I18n, iso: string | null, now = new Date()) {
  if (!iso) return null;
  const d = new Date(iso);
  if (d.getTime() <= now.getTime()) return null;
  const days = Math.round((startOfDay(d) - startOfDay(now)) / 86_400_000);
  const f = formats(locale);
  const time = f.time.format(d);
  if (days === 0) return t.when.today(time);
  if (days === 1) return t.when.tomorrow(time);
  if (days < 7) return t.when.weekday(f.day.format(d), time);
  return t.when.date(f.date.format(d));
}

export function formatReturn(i18n: I18n, iso: string | null, now = new Date()) {
  if (!iso) return null;
  const when = returnWhen(i18n, iso, now);
  return when ? i18n.t.when.back(when) : i18n.t.when.overdue;
}

export function formatClock({ locale }: I18n, iso: string | number | Date) {
  return formats(locale).time.format(new Date(iso));
}

export function formatRelative({ locale, t }: I18n, iso: string, now = new Date()) {
  const diff = Math.round((now.getTime() - new Date(iso).getTime()) / 1000);
  if (diff < 10) return t.relative.justNow;
  if (diff < 60) return t.relative.seconds(diff);
  if (diff < 3600) return t.relative.minutes(Math.floor(diff / 60));
  if (diff < 86_400) return t.relative.hours(Math.floor(diff / 3600));
  return formats(locale).date.format(new Date(iso));
}

export function formatDay({ locale }: I18n, iso: string) {
  return formats(locale).date.format(new Date(iso));
}

export function formatDayStamp({ locale }: I18n, iso: string) {
  const d = new Date(iso);
  const f = formats(locale);
  return `${f.date.format(d)} · ${f.time.format(d)}`;
}

export type ReturnId = "tonight" | "tomorrow" | "days" | "week";

export type ReturnOption = { id: ReturnId; at: () => Date };

function atHour(daysAhead: number, hour: number) {
  const d = new Date();
  d.setDate(d.getDate() + daysAhead);
  d.setHours(hour, 0, 0, 0);
  return d;
}

export const RETURN_OPTIONS: ReturnOption[] = [
  {
    id: "tonight",
    at: () => {
      const d = atHour(0, 20);
      return d.getTime() - Date.now() < 60 * 60 * 1000 ? new Date(Date.now() + 2 * 3600 * 1000) : d;
    },
  },
  { id: "tomorrow", at: () => atHour(1, 9) },
  { id: "days", at: () => atHour(3, 9) },
  {
    id: "week",
    at: () => {
      const d = atHour(1, 9);
      while (d.getDay() !== 1) d.setDate(d.getDate() + 1);
      return d;
    },
  },
];

export function formatDuration({ locale }: I18n, ms: number | null) {
  if (ms === null) return "—";
  if (ms < 1000) return `${ms} ms`;
  const seconds = ms / 1000;
  const f = formats(locale);
  if (seconds < 60) return `${f.seconds.format(seconds)} s`;
  return `${Math.floor(seconds / 60)} min ${f.tenths.format(seconds % 60)} s`;
}
