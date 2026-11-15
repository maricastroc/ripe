import { connection } from "next/server";
import { Greenhouse } from "@/components/greenhouse/Greenhouse";
import { getAgentStatus, listTasks } from "@/lib/queries";

export default async function Home() {
  await connection();
  const [tasks, agent] = await Promise.all([listTasks(), getAgentStatus()]);
  return <Greenhouse initial={{ tasks, agent }} />;
}
