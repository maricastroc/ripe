import Link from "next/link";

export function Wordmark({ size = "md" }: { size?: "sm" | "md" }) {
  return (
    <Link
      href="/"
      className={`focus-ring group relative inline-flex items-baseline font-display font-display-wonk italic leading-none tracking-tight text-ink ${
        size === "sm" ? "text-2xl" : "text-[2rem]"
      }`}
      aria-label="Ripe — voltar ao parapeito"
    >
      <span>r</span>
      <span className="relative">
        ı
        <span className="absolute left-1/2 top-[0.02em] h-[0.2em] w-[0.2em] -translate-x-[30%] rounded-full bg-accent transition-transform duration-500 group-hover:-translate-y-0.5" />
      </span>
      <span>pe</span>
    </Link>
  );
}
