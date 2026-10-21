import Link from "next/link";
import { EmptyPot } from "@/components/plant/Plant";
import { Board } from "@/components/garden/Shelf";

export default function NotFound() {
  return (
    <main className="mx-auto flex w-full max-w-xl flex-1 flex-col items-center justify-center px-6 py-20 text-center">
      <div className="w-40">
        <div className="px-[9%]">
          <EmptyPot seed="404" className="block h-auto w-full" />
        </div>
        <Board first last />
      </div>
      <h1 className="mt-10 font-display text-4xl tracking-tight">Esta planta não está mais aqui.</h1>
      <p className="mt-3 text-[15px] text-ink-2">Talvez tenha sido arrancada, ou o endereço mudou.</p>
      <Link
        href="/"
        className="focus-ring mt-8 rounded-full bg-ink px-5 py-2.5 text-[14px] font-medium text-paper hover:bg-leaf-dark"
      >
        Voltar ao parapeito
      </Link>
    </main>
  );
}
