import { connection } from "next/server";
import { Garden } from "@/components/garden/Garden";
import { getAgentStatus, listTasks } from "@/lib/queries";

export default async function Home() {
  await connection();
  const [tasks, agent] = await Promise.all([listTasks(), getAgentStatus()]);
  return <Garden initial={{ tasks, agent }} />;
}
