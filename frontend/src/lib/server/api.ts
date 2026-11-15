import { cache } from "react";
import { headers } from "next/headers";
import { apiOrigin, serverHeaders } from "@/lib/gateway";

export const serverApi = cache(async <T>(path: string): Promise<T | null> => {
  const response = await fetch(new URL(path, apiOrigin()), {
    headers: serverHeaders(await headers()),
    cache: "no-store",
  });
  if (response.status === 404) return null;
  if (!response.ok) throw new Error(`Ripe's API answered ${response.status} for ${path}.`);
  return (await response.json()) as T;
});
