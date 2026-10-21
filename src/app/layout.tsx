import type { Metadata, Viewport } from "next";
import { Fraunces, Geist_Mono, Instrument_Sans } from "next/font/google";
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

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  title: "Ripe — tarefas que amadurecem enquanto você procrastina",
  description:
    "Adie uma tarefa e um agente de IA local, rodando no Ollama, trabalha nela aos poucos. Quando você voltar, encontra um plano, um rascunho ou uma primeira versão.",
};

export const viewport: Viewport = {
  themeColor: [
    { media: "(prefers-color-scheme: light)", color: "#f3eee4" },
    { media: "(prefers-color-scheme: dark)", color: "#111613" },
  ],
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="pt-BR" className={`${fraunces.variable} ${instrument.variable} ${geistMono.variable} antialiased`}>
      <body>
        <Providers>
          <div className="relative z-10 flex min-h-dvh flex-col">{children}</div>
        </Providers>
      </body>
    </html>
  );
}
