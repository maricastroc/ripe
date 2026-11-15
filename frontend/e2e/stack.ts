import path from "node:path";

export const BACKEND = path.resolve(__dirname, "../../backend");
export const API_PORT = 8100;
export const WEB_PORT = 3100;
export const GATEWAY_SECRET = "a-gateway-secret-for-end-to-end-tests";

export const backendEnv: NodeJS.ProcessEnv = {
  ...process.env,
  APP_ENV: "local",
  DB_DATABASE: process.env.RIPE_E2E_DATABASE ?? "ripe_testing",
  CACHE_STORE: "database",
  RIPE_MODE: "local",
  RIPE_INFERENCE_DRIVER: "fake",
  RIPE_PACE: "instant",
  RIPE_GATEWAY_SECRET: GATEWAY_SECRET,
  RIPE_GATEWAY_REQUIRED: "true",
  RIPE_VISITOR_COOKIE: "ripe_visitor",
  RIPE_VISITOR_COOKIE_SECURE: "false",
  RIPE_ALLOWED_ORIGINS: `http://localhost:${WEB_PORT}`,
};

export const webEnv: NodeJS.ProcessEnv = {
  ...process.env,
  RIPE_API_ORIGIN: `http://127.0.0.1:${API_PORT}`,
  RIPE_GATEWAY_SECRET: GATEWAY_SECRET,
};
