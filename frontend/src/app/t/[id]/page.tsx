import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { SpecimenSheet } from "@/components/specimen/SpecimenSheet";
import { serverApi } from "@/lib/server/api";
import type { TaskPage as TaskPageData } from "@/lib/types";

export async function generateMetadata(props: PageProps<"/t/[id]">): Promise<Metadata> {
  const { id } = await props.params;
  const page = await serverApi<TaskPageData>(`/api/tasks/${encodeURIComponent(id)}`);
  return { title: page ? `${page.task.title} · Ripe` : "Ripe" };
}

export default async function TaskPage(props: PageProps<"/t/[id]">) {
  const [{ id }, query] = await Promise.all([props.params, props.searchParams]);
  const page = await serverApi<TaskPageData>(`/api/tasks/${encodeURIComponent(id)}`);
  if (!page) notFound();
  const piece = typeof query.piece === "string" ? query.piece : null;
  return <SpecimenSheet key={page.task.id} initial={page} initialPiece={piece} />;
}
