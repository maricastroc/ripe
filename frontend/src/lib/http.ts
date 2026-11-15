import { ZodError } from "zod";
import { Invalid, NotFound } from "./tasks";

export async function respond(work: () => Promise<unknown>) {
  try {
    const data = await work();
    return Response.json(data ?? { ok: true });
  } catch (err) {
    if (err instanceof NotFound) return Response.json({ error: err.message }, { status: 404 });
    if (err instanceof Invalid) return Response.json({ error: err.message }, { status: 400 });
    if (err instanceof ZodError) return Response.json({ error: "Ripe couldn't read that request." }, { status: 400 });
    console.error("[ripe] api error", err);
    return Response.json(
      { error: "Something broke on the server. The details are in the terminal running Ripe." },
      { status: 500 },
    );
  }
}
