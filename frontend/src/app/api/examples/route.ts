import { createExample } from "@/lib/examples";
import { respond } from "@/lib/http";

export async function POST() {
  return respond(async () => ({ id: await createExample() }));
}
