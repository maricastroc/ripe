import { z } from "zod";
import { respond } from "@/lib/http";
import { getTask } from "@/lib/queries";
import { editArtifact, restoreArtifact, reviseArtifact } from "@/lib/tasks";

const action = z.discriminatedUnion("action", [
  z.object({ action: z.literal("edit"), content: z.string().max(60_000) }),
  z.object({ action: z.literal("restore") }),
  z.object({ action: z.literal("revise"), instruction: z.string().max(500) }),
]);

export async function PATCH(request: Request, ctx: RouteContext<"/api/artifacts/[id]">) {
  return respond(async () => {
    const { id } = await ctx.params;
    const input = action.parse(await request.json());
    const taskId =
      input.action === "edit"
        ? await editArtifact(id, input.content)
        : input.action === "restore"
          ? await restoreArtifact(id)
          : await reviseArtifact(id, input.instruction);
    return input.action === "edit" ? { ok: true } : { task: await getTask(taskId) };
  });
}
