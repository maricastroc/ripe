import { defineConfig, devices } from "@playwright/test";
import { API_PORT, BACKEND, WEB_PORT, backendEnv, webEnv } from "./e2e/stack";

export default defineConfig({
  testDir: "./e2e",
  timeout: 90_000,
  retries: 0,
  workers: 1,
  reporter: process.env.CI ? "github" : "list",
  globalSetup: "./e2e/global-setup.ts",
  globalTeardown: "./e2e/global-teardown.ts",
  use: {
    baseURL: `http://localhost:${WEB_PORT}`,
    trace: "retain-on-failure",
  },
  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
  webServer: [
    {
      command: `php artisan serve --no-reload --host=127.0.0.1 --port=${API_PORT}`,
      cwd: BACKEND,
      env: backendEnv as Record<string, string>,
      url: `http://127.0.0.1:${API_PORT}/up`,
      reuseExistingServer: false,
      timeout: 60_000,
    },
    {
      command: `npm run build && npx next start --port ${WEB_PORT}`,
      env: webEnv as Record<string, string>,
      url: `http://localhost:${WEB_PORT}`,
      reuseExistingServer: false,
      timeout: 240_000,
    },
  ],
});
