import { z } from "zod";
import { respond } from "@/lib/http";
import { getAgentStatus, getTask } from "@/lib/queries";
import {
  NotFound,
  deferTask,
  deleteTask,
  harvestTask,
  pauseTask,
  refreshWithAnswers,
  renameTask,
  replantTask,
} from "@/lib/tasks";

const action = z.discriminatedUnion("action", [
  z.object({ action: z.literal("defer"), returnAt: z.iso.datetime().optional() }),
  z.object({ action: z.literal("resume") }),
  z.object({ action: z.literal("pause") }),
  z.object({ action: z.literal("harvest") }),
  z.object({ action: z.literal("replant") }),
  z.object({ action: z.literal("refresh") }),
  z.object({ action: z.literal("rename"), title: z.string() }),
]);

export async function GET(_request: Request, ctx: RouteContext<"/api/tasks/[id]">) {
  return respond(async () => {
    const { id } = await ctx.params;
    const [task, agent] = await Promise.all([getTask(id), getAgentStatus()]);
    if (!task) throw new NotFound("Tarefa não encontrada");
    return { task, agent };
  });
}

export async function PATCH(request: Request, ctx: RouteContext<"/api/tasks/[id]">) {
  return respond(async () => {
    const { id } = await ctx.params;
    const input = action.parse(await request.json());
    switch (input.action) {
      case "defer":
        await deferTask(id, input.returnAt ? new Date(input.returnAt) : undefined);
        break;
      case "resume":
        await deferTask(id);
        break;
      case "pause":
        await pauseTask(id);
        break;
      case "harvest":
        await harvestTask(id);
        break;
      case "replant":
        await replantTask(id);
        break;
      case "refresh":
        await refreshWithAnswers(id);
        break;
      case "rename":
        await renameTask(id, input.title);
        break;
    }
    return { task: await getTask(id) };
  });
}

export async function DELETE(_request: Request, ctx: RouteContext<"/api/tasks/[id]">) {
  return respond(async () => {
    const { id } = await ctx.params;
    await deleteTask(id);
    return { ok: true };
  });
}
