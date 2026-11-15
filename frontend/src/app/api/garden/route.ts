import { respond } from "@/lib/http";
import { getAgentStatus, listTasks } from "@/lib/queries";

export async function GET() {
  return respond(async () => {
    const [tasks, agent] = await Promise.all([listTasks(), getAgentStatus()]);
    return { tasks, agent };
  });
}
