import { expect, test } from "@playwright/test";

test("plants a task, watches it ripen, opens it, keeps a draft of a piece and reads its growth rings", async ({
  page,
}) => {
  await page.goto("/");
  await expect(page.getByText("Nothing is growing yet.")).toBeVisible();

  await page
    .getByLabel("What are you putting off?")
    .fill("Write a thank-you note to the neighbour who watered my plants.");
  await page.getByRole("button", { name: "Let it ripen" }).click();

  const plant = page.getByRole("link", { name: /Nº 001: .* Ripe stage/ });
  await expect(plant).toBeVisible({ timeout: 45_000 });
  await plant.click();
  await expect(page).toHaveURL(/\/t\/[0-9a-f-]{36}/);
  await expect(page.getByText("Your turn")).toBeVisible();

  await page.getByRole("button", { name: "Edit", exact: true }).click();
  const editor = page.locator('textarea[id^="edit-"]');
  const original = await editor.inputValue();
  await editor.fill(`${original}\n\nMy own closing line.`);
  await expect(page.getByText("draft saved")).toBeVisible();

  const api = page.url().replace("/t/", "/api/tasks/");
  const drafted = await page.evaluate(async (url) => {
    const res = await fetch(url, { headers: { Accept: "application/json" } });
    const body = (await res.json()) as { task: { artifacts: { draft: { content: string } | null }[] } };
    return body.task.artifacts.find((a) => a.draft)?.draft?.content ?? null;
  }, api);
  expect(drafted).toContain("My own closing line.");

  await page.getByRole("button", { name: "Read", exact: true }).click();
  await expect(page.getByText("edited by you")).toBeVisible();

  await page.reload();
  await expect(page.getByText("My own closing line.")).toBeVisible();
  await expect(page.getByText("edited by you")).toBeVisible();

  await page.getByRole("tab", { name: "growth rings" }).click();
  await expect(page.getByText(/\d+ rings of saved work/)).toBeVisible();
  await expect(page.getByText("1. Reading the request")).toBeVisible();
});
