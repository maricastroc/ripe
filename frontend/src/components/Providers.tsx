"use client";

import { MotionConfig } from "motion/react";
import { I18nProvider } from "@/lib/i18n/context";
import type { Locale } from "@/lib/i18n/locales";

export function Providers({ locale, children }: { locale: Locale; children: React.ReactNode }) {
  return (
    <I18nProvider locale={locale}>
      <MotionConfig reducedMotion="user">{children}</MotionConfig>
    </I18nProvider>
  );
}
