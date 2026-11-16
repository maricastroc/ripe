"use client";

import { createContext, useContext, useMemo } from "react";
import { type I18n, i18nFor } from ".";
import { DEFAULT_LOCALE, type Locale } from "./locales";

const I18nContext = createContext<I18n>(i18nFor(DEFAULT_LOCALE));

export function I18nProvider({ locale, children }: { locale: Locale; children: React.ReactNode }) {
  const value = useMemo(() => i18nFor(locale), [locale]);
  return <I18nContext value={value}>{children}</I18nContext>;
}

export function useI18n(): I18n {
  return useContext(I18nContext);
}
