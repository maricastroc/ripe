import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { TaskView } from "@/components/task/TaskView";
import { getAgentStatus, getTask } from "@/lib/queries";

export async function generateMetadata(props: PageProps<"/t/[id]">): Promise<Metadata> {
  const { id } = await props.params;
  const task = await getTask(id);
  return { title: task ? `${task.title} · Ripe` : "Ripe" };
}

export default async function TaskPage(props: PageProps<"/t/[id]">) {
  const { id } = await props.params;
  const [task, agent] = await Promise.all([getTask(id), getAgentStatus()]);
  if (!task) notFound();
  return <TaskView key={task.id} initial={{ task, agent }} />;
}
