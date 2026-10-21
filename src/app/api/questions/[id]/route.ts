import { z } from "zod";
import { respond } from "@/lib/http";
import { getTask } from "@/lib/queries";
import { answerQuestion, reopenQuestion, skipQuestion } from "@/lib/tasks";

const action = z.discriminatedUnion("action", [
  z.object({ action: z.literal("answer"), answer: z.string().max(1200) }),
  z.object({ action: z.literal("skip") }),
  z.object({ action: z.literal("reopen") }),
]);

export async function PATCH(request: Request, ctx: RouteContext<"/api/questions/[id]">) {
  return respond(async () => {
    const { id } = await ctx.params;
    const input = action.parse(await request.json());
    const taskId =
      input.action === "answer"
        ? await answerQuestion(id, input.answer)
        : input.action === "skip"
          ? await skipQuestion(id)
          : await reopenQuestion(id);
    return { task: await getTask(taskId) };
  });
}
