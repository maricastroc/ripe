import { z } from "zod";
import { respond } from "@/lib/http";
import { createTask } from "@/lib/tasks";

const body = z.object({
  prompt: z.string().min(1).max(4000),
  defer: z.boolean().default(true),
  returnAt: z.iso.datetime().optional(),
});

export async function POST(request: Request) {
  return respond(async () => {
    const input = body.parse(await request.json());
    const id = await createTask(input.prompt, {
      defer: input.defer,
      returnAt: input.returnAt ? new Date(input.returnAt) : undefined,
    });
    return { id };
  });
}
