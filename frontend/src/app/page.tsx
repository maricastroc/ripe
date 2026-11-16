import { Greenhouse } from "@/components/greenhouse/Greenhouse";
import { serverApi } from "@/lib/server/api";
import { realtimeConfig } from "@/lib/server/realtime";
import { turnstileSiteKey } from "@/lib/server/turnstile";
import type { Garden } from "@/lib/types";

export default async function Home() {
  const garden = await serverApi<Garden>("/api/garden");
  if (!garden) throw new Error("The greenhouse could not be read.");
  return <Greenhouse initial={garden} realtime={realtimeConfig()} turnstile={turnstileSiteKey()} />;
}
