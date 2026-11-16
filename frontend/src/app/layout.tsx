import type { Metadata, Viewport } from "next";
import { Courier_Prime, Fraunces, Instrument_Sans } from "next/font/google";
import { Providers } from "@/components/Providers";
import { serverI18n } from "@/lib/server/locale";
import "./globals.css";

const fraunces = Fraunces({
  variable: "--font-fraunces",
  subsets: ["latin"],
  axes: ["SOFT", "WONK", "opsz"],
  style: ["normal", "italic"],
});

const instrument = Instrument_Sans({
  variable: "--font-instrument",
  subsets: ["latin"],
});

const typewriter = Courier_Prime({
  variable: "--font-typewriter",
  subsets: ["latin"],
  weight: ["400", "700"],
});

export async function generateMetadata(): Promise<Metadata> {
  const { t } = await serverI18n();
  return { title: t.meta.title, description: t.meta.description };
}

export const viewport: Viewport = {
  themeColor: [
    { media: "(prefers-color-scheme: light)", color: "#c9ded3" },
    { media: "(prefers-color-scheme: dark)", color: "#0f1d24" },
  ],
};

export default async function RootLayout({ children }: LayoutProps<"/">) {
  const { locale } = await serverI18n();
  return (
    <html lang={locale} className={`${fraunces.variable} ${instrument.variable} ${typewriter.variable} antialiased`}>
      <body>
        <Providers locale={locale}>
          <div className="relative flex min-h-dvh flex-col">{children}</div>
        </Providers>
      </body>
    </html>
  );
}
