import Link from "next/link";
import { Specimen } from "@/components/flora/Specimen";
import { serverI18n } from "@/lib/server/locale";

const EMPTY = {
  seed: "not-found",
  fruit: null,
  growth: { stage: "seed" as const, maturity: 0, stepsDone: 0, stepsTotal: 0 },
  steps: [],
};

export default async function NotFound() {
  const { t } = await serverI18n();
  return (
    <main className="paper flex flex-1 flex-col items-center justify-center px-6 py-16 text-center">
      <div className="w-56">
        <Specimen
          state={EMPTY}
          mood="dormant"
          variant="plate"
          className="block h-auto w-full"
          title={t.notFound.soil}
        />
      </div>
      <h1 className="mt-6 font-display text-4xl tracking-tight">{t.notFound.title}</h1>
      <p className="ink mt-3 text-[1.1rem]">{t.notFound.text}</p>
      <Link
        href="/"
        className="focus-ring mt-8 rounded-full bg-ink px-5 py-2.5 text-[14px] font-medium text-paper hover:bg-leaf-dark"
      >
        {t.sheet.back}
      </Link>
    </main>
  );
}
