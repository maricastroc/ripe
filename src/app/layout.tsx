import type { Metadata, Viewport } from "next";
import { Courier_Prime, Fraunces, Instrument_Sans } from "next/font/google";
import { Providers } from "@/components/Providers";
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

export const metadata: Metadata = {
  title: "Ripe — tasks that ripen while you procrastinate",
  description:
    "Put a task off and a local AI agent running on Ollama works on it, little by little. When you come back, there's a plan, a draft or a first version waiting.",
};

export const viewport: Viewport = {
  themeColor: [
    { media: "(prefers-color-scheme: light)", color: "#c9ded3" },
    { media: "(prefers-color-scheme: dark)", color: "#0f1d24" },
  ],
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="en" className={`${fraunces.variable} ${instrument.variable} ${typewriter.variable} antialiased`}>
      <body>
        <Providers>
          <div className="relative flex min-h-dvh flex-col">{children}</div>
        </Providers>
      </body>
    </html>
  );
}
