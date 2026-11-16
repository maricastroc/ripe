export const LOCALES = ["en", "pt-BR"] as const;

export type Locale = (typeof LOCALES)[number];

export const DEFAULT_LOCALE: Locale = "en";

export const LOCALE_COOKIE = "ripe_locale";

export function isLocale(value: unknown): value is Locale {
  return typeof value === "string" && (LOCALES as readonly string[]).includes(value);
}

function matching(tag: string): Locale | null {
  const lower = tag.toLowerCase();
  if (lower === "pt" || lower.startsWith("pt-")) return "pt-BR";
  if (lower === "en" || lower.startsWith("en-")) return "en";
  return null;
}

export function preferredLocale(acceptLanguage: string | null): Locale | null {
  if (!acceptLanguage) return null;
  const ranked = acceptLanguage
    .split(",")
    .map((part, index) => {
      const [tag, ...params] = part.split(";").map((piece) => piece.trim());
      const q = params.find((param) => param.startsWith("q="));
      const quality = q === undefined ? 1 : Number(q.slice(2));
      return { tag, quality: Number.isFinite(quality) ? quality : 0, index };
    })
    .filter((entry) => entry.tag && entry.quality > 0)
    .sort((a, b) => b.quality - a.quality || a.index - b.index);
  for (const entry of ranked) {
    const locale = matching(entry.tag);
    if (locale) return locale;
  }
  return null;
}

function cookieValue(header: string | null, name: string): string | null {
  if (!header) return null;
  for (const pair of header.split(";")) {
    const separator = pair.indexOf("=");
    if (separator === -1) continue;
    if (pair.slice(0, separator).trim() === name) return decodeURIComponent(pair.slice(separator + 1).trim());
  }
  return null;
}

export function localeOf(headers: Headers): Locale {
  const chosen = cookieValue(headers.get("cookie"), LOCALE_COOKIE);
  if (isLocale(chosen)) return chosen;
  return preferredLocale(headers.get("accept-language")) ?? DEFAULT_LOCALE;
}

export function rememberLocale(locale: Locale) {
  const secure = window.location.protocol === "https:" ? "; secure" : "";
  document.cookie = `${LOCALE_COOKIE}=${locale}; path=/; max-age=31536000; samesite=lax${secure}`;
}
