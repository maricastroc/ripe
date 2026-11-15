import { execFileSync } from "node:child_process";
import { start, stopAll } from "./processes";
import { BACKEND, backendEnv } from "./stack";

export default function globalSetup() {
  stopAll();
  execFileSync("php", ["artisan", "migrate:fresh", "--force"], { cwd: BACKEND, env: backendEnv, stdio: "inherit" });
  start("agent");
  start("realtime");
  start("reverb");
}
