"use client";

import Link from "next/link";

export default function ErrorPage({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <main className="mx-auto flex w-full max-w-xl flex-1 flex-col items-center justify-center px-6 py-20 text-center">
      <p className="label">algo murchou</p>
      <h1 className="mt-3 font-display text-4xl tracking-tight">Não consegui abrir esta página.</h1>
      <p className="mt-3 text-[15px] leading-relaxed text-ink-2">
        O trabalho do agente fica salvo no banco local, então nada se perdeu. Se o problema continuar, confira se o
        banco foi criado com{" "}
        <code className="rounded bg-paper-2 px-1.5 py-0.5 font-mono text-[13px]">npm run setup</code>.
      </p>
      {error.digest && <p className="mt-2 font-mono text-[12px] text-ink-3">{error.digest}</p>}
      <div className="mt-8 flex gap-3">
        <button
          type="button"
          onClick={reset}
          className="focus-ring rounded-full bg-ink px-5 py-2.5 text-[14px] font-medium text-paper hover:bg-leaf-dark"
        >
          Tentar de novo
        </button>
        <Link
          href="/"
          className="focus-ring rounded-full border border-line-2 px-5 py-2.5 text-[14px] text-ink-2 hover:text-ink"
        >
          Parapeito
        </Link>
      </div>
    </main>
  );
}
