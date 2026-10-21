import { z } from "zod";
import { wakeWorker } from "@/agent/signals";
import { forgetHealth } from "@/agent/ollama";
import { respond } from "@/lib/http";
import { getAgentStatus } from "@/lib/queries";
import { updateSettings } from "@/lib/settings";

const patch = z.object({
  model: z.string().trim().min(1).max(120).optional(),
  baseUrl: z.url().max(200).optional(),
  pace: z.enum(["calm", "steady", "brisk"]).optional(),
  paused: z.boolean().optional(),
});

export async function GET() {
  return respond(() => getAgentStatus());
}

export async function PUT(request: Request) {
  return respond(async () => {
    const input = patch.parse(await request.json());
    await updateSettings(input);
    forgetHealth();
    wakeWorker();
    return getAgentStatus();
  });
}
