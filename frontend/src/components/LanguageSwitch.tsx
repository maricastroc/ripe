"use client";

import { useI18n } from "@/lib/i18n/context";
import { type Locale, rememberLocale } from "@/lib/i18n/locales";

const OPTIONS: { locale: Locale; short: string; name: string }[] = [
  { locale: "en", short: "EN", name: "English" },
  { locale: "pt-BR", short: "PT", name: "Português" },
];

export function LanguageSwitch({ className = "" }: { className?: string }) {
  const { locale, t } = useI18n();

  function choose(next: Locale) {
    if (next === locale) return;
    rememberLocale(next);
    window.location.reload();
  }

  return (
    <div role="group" aria-label={t.language.label} className={`typewriter flex items-center text-[11px] ${className}`}>
      {OPTIONS.map((option, index) => (
        <span key={option.locale} className="flex items-center">
          {index > 0 && (
            <span aria-hidden className="px-0.5 opacity-60">
              ·
            </span>
          )}
          <button
            type="button"
            lang={option.locale}
            aria-label={option.name}
            aria-pressed={option.locale === locale}
            onClick={() => choose(option.locale)}
            className="focus-ring rounded px-1 py-0.5 opacity-70 transition-opacity hover:opacity-100 aria-pressed:font-bold aria-pressed:underline aria-pressed:underline-offset-4 aria-pressed:opacity-100"
          >
            {option.short}
          </button>
        </span>
      ))}
    </div>
  );
}
