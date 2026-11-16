import { DEFAULT_LOCALE, type Locale, isLocale } from "./locales";
import { en, type Messages } from "./messages/en";
import { ptBR } from "./messages/pt-BR";

export type I18n = { locale: Locale; t: Messages };

const DICTIONARIES: Record<Locale, Messages> = { en, "pt-BR": ptBR };

export function i18nFor(locale: Locale): I18n {
  return { locale, t: DICTIONARIES[locale] };
}

export function documentI18n(): I18n {
  const lang = typeof document === "undefined" ? null : document.documentElement.lang;
  return i18nFor(isLocale(lang) ? lang : DEFAULT_LOCALE);
}
