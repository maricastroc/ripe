import { type Locale } from "./locales";
import { en, type Messages } from "./messages/en";
import { ptBR } from "./messages/pt-BR";

export type I18n = { locale: Locale; t: Messages };

const DICTIONARIES: Record<Locale, Messages> = { en, "pt-BR": ptBR };

export function i18nFor(locale: Locale): I18n {
  return { locale, t: DICTIONARIES[locale] };
}
