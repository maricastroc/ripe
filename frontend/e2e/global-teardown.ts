import { readFileSync, rmSync } from "node:fs";
import { WORKER_PID } from "./global-setup";

export default function globalTeardown() {
  try {
    process.kill(Number(readFileSync(WORKER_PID, "utf8")), "SIGTERM");
  } catch {
    return;
  } finally {
    rmSync(WORKER_PID, { force: true });
  }
}
