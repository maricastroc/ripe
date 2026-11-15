import { type Page, expect, test } from "@playwright/test";
import { start, stop } from "./processes";

function plant(page: Page, prompt: string) {
  return page.evaluate(async (text) => {
    const res = await fetch("/api/tasks", {
      method: "POST",
      headers: { Accept: "application/json", "Content-Type": "application/json" },
      body: JSON.stringify({ prompt: text }),
    });
    if (!res.ok) throw new Error(`Planting answered ${res.status}.`);
  }, prompt);
}

test("follows the greenhouse by push, by polling while Reverb is down, and by push once it is back", async ({
  page,
}) => {
  test.setTimeout(180_000);
  const greenhouse = page.locator("[data-realtime]");
  const plot = (number: string, stage = "") => page.getByRole("link", { name: new RegExp(`Nº ${number}: .*${stage}`) });

  await page.goto("/");
  await plant(page, "Write a thank-you note to the neighbour who lent me a ladder.");
  await page.reload();
  await expect(greenhouse).toHaveAttribute("data-realtime", "live", { timeout: 20_000 });

  await plant(page, "Plan a picnic for six friends in the park.");
  await expect(plot("002")).toBeVisible({ timeout: 3_000 });
  await expect(plot("002", "Ripe stage")).toBeVisible({ timeout: 45_000 });

  stop("reverb");
  await expect(greenhouse).toHaveAttribute("data-realtime", "polling", { timeout: 10_000 });
  await plant(page, "Draft a short speech for my sister's birthday.");
  await expect(plot("003", "Ripe stage")).toBeVisible({ timeout: 45_000 });

  start("reverb");
  await expect(greenhouse).toHaveAttribute("data-realtime", "live", { timeout: 60_000 });
  await plant(page, "Make a packing list for a weekend of hiking.");
  await expect(plot("004")).toBeVisible({ timeout: 3_000 });
  await expect(plot("004", "Ripe stage")).toBeVisible({ timeout: 45_000 });
});
