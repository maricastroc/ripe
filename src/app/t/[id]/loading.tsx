export default function Loading() {
  return (
    <div className="mx-auto flex w-full max-w-[1240px] flex-1 flex-col px-4 py-6 sm:px-8 lg:px-12" aria-busy="true">
      <div className="h-8 w-16 animate-pulse rounded-full bg-paper-2" />
      <div className="mt-16 grid gap-12 lg:grid-cols-[5fr_7fr] lg:gap-16">
        <div className="mx-auto aspect-[200/270] w-full max-w-[300px] animate-pulse rounded-3xl bg-paper-2 lg:mx-0" />
        <div className="space-y-5">
          <div className="h-3 w-32 animate-pulse rounded bg-paper-2" />
          <div className="h-12 w-3/4 animate-pulse rounded-xl bg-paper-2" />
          <div className="h-4 w-2/3 animate-pulse rounded bg-paper-2" />
          <div className="mt-10 h-48 animate-pulse rounded-3xl bg-paper-2" />
        </div>
      </div>
      <span className="sr-only">Abrindo a planta…</span>
    </div>
  );
}
