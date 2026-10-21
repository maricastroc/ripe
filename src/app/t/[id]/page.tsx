import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { SpecimenSheet } from "@/components/specimen/SpecimenSheet";
import { getAgentStatus, getTask } from "@/lib/queries";

export async function generateMetadata(props: PageProps<"/t/[id]">): Promise<Metadata> {
  const { id } = await props.params;
  const task = await getTask(id);
  return { title: task ? `${task.title} · Ripe` : "Ripe" };
}

export default async function TaskPage(props: PageProps<"/t/[id]">) {
  const [{ id }, query] = await Promise.all([props.params, props.searchParams]);
  const [task, agent] = await Promise.all([getTask(id), getAgentStatus()]);
  if (!task) notFound();
  const piece = typeof query.piece === "string" ? query.piece : null;
  return <SpecimenSheet key={task.id} initial={{ task, agent }} initialPiece={piece} />;
}
