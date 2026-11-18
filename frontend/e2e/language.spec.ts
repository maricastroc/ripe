import { expect, test } from "@playwright/test";

test.use({ locale: "pt-BR" });

test("shows the greenhouse, the API's notes and the examples in Portuguese to a Portuguese browser", async ({
  page,
}) => {
  await page.goto("/");
  await expect(page.locator("html")).toHaveAttribute("lang", "pt-BR");
  await expect(page.getByText("Nada está crescendo ainda.")).toBeVisible();

  await page.getByLabel("O que você está adiando?").fill("Escrever um bilhete de agradecimento para a vizinha.");
  await page.getByRole("button", { name: "Deixar amadurecer" }).click();

  const plant = page.getByRole("link", { name: /Nº 001: .* Estágio Madura/ });
  await expect(plant).toBeVisible({ timeout: 45_000 });
  await plant.click();
  await expect(page.getByText("Sua vez")).toBeVisible();
  await expect(page.locator("#journal-panel-notes").getByText("Madura. Escrita e revisão concluídas.")).toBeVisible();

  await page.getByRole("link", { name: "Voltar para a estufa" }).click();
  await page.getByRole("button", { name: "English" }).click();
  await expect(page.locator("html")).toHaveAttribute("lang", "en");
  await expect(page.getByRole("link", { name: /Nº 001: .* Ripe stage/ })).toBeVisible();

  const examples = await page.evaluate(async () => {
    const answer = async (language: string) => {
      document.cookie = `ripe_locale=${language}; path=/`;
      const res = await fetch("/api/examples", { headers: { Accept: "application/json" } });
      const body = (await res.json()) as { examples: { prompt: string }[] };
      return body.examples.map((example) => example.prompt);
    };
    return { portuguese: await answer("pt-BR"), english: await answer("en") };
  });
  expect(examples.portuguese.length).toBeGreaterThan(0);
  expect(examples.portuguese.every((prompt) => !examples.english.includes(prompt))).toBe(true);
});
