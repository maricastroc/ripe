import { headers } from "next/headers";
import { type I18n, i18nFor } from "@/lib/i18n";
import { localeOf } from "@/lib/i18n/locales";

export async function serverI18n(): Promise<I18n> {
  return i18nFor(localeOf(await headers()));
}
