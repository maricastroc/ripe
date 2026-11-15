import { execFileSync, spawn } from "node:child_process";
import { mkdirSync, writeFileSync } from "node:fs";
import path from "node:path";
import { BACKEND, backendEnv } from "./stack";

export const WORKER_PID = path.resolve(__dirname, "../test-results/.worker.pid");

export default function globalSetup() {
  mkdirSync(path.dirname(WORKER_PID), { recursive: true });
  execFileSync("php", ["artisan", "migrate:fresh", "--force"], { cwd: BACKEND, env: backendEnv, stdio: "inherit" });
  const worker = spawn("php", ["artisan", "queue:work", "agent", "--queue=agent", "--sleep=0", "--timeout=60"], {
    cwd: BACKEND,
    env: backendEnv,
    stdio: "ignore",
    detached: true,
  });
  worker.unref();
  writeFileSync(WORKER_PID, String(worker.pid));
}
