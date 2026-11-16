import path from "node:path";

export const BACKEND = path.resolve(__dirname, "../../backend");
export const API_PORT = 8100;
export const WEB_PORT = 3100;
export const REVERB_PORT = 8180;
export const GATEWAY_SECRET = "a-gateway-secret-for-end-to-end-tests";
export const REALTIME_KEY = "ripe-e2e";

export const backendEnv: NodeJS.ProcessEnv = {
  ...process.env,
  APP_ENV: "local",
  LOG_CHANNEL: "stderr",
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
  BROADCAST_CONNECTION: "reverb",
  REVERB_APP_ID: "ripe",
  REVERB_APP_KEY: REALTIME_KEY,
  REVERB_APP_SECRET: "a-realtime-secret-for-end-to-end-tests",
  REVERB_HOST: "127.0.0.1",
  REVERB_PORT: String(REVERB_PORT),
  REVERB_SCHEME: "http",
  REVERB_SERVER_PORT: String(REVERB_PORT),
  RIPE_REALTIME_PAUSE_SECONDS: "1",
};

export const webEnv: NodeJS.ProcessEnv = {
  ...process.env,
  RIPE_API_ORIGIN: `http://127.0.0.1:${API_PORT}`,
  RIPE_GATEWAY_SECRET: GATEWAY_SECRET,
  RIPE_REALTIME_KEY: REALTIME_KEY,
  RIPE_REALTIME_HOST: "127.0.0.1",
  RIPE_REALTIME_PORT: String(REVERB_PORT),
  RIPE_REALTIME_SCHEME: "http",
};
