import { spawn } from "node:child_process";
import { mkdirSync, openSync, readFileSync, readdirSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";
import { BACKEND, backendEnv } from "./stack";

const PIDS = path.resolve(__dirname, "../test-results/processes");

const COMMANDS = {
  agent: ["artisan", "queue:work", "agent", "--queue=agent", "--sleep=0", "--timeout=60"],
  realtime: ["artisan", "queue:work", "realtime", "--queue=realtime", "--sleep=0.1", "--timeout=30"],
  reverb: ["artisan", "reverb:start"],
} as const;

export type Process = keyof typeof COMMANDS;

export function start(name: Process) {
  mkdirSync(PIDS, { recursive: true });
  const log = openSync(path.join(PIDS, `${name}.log`), "a");
  const child = spawn("php", [...COMMANDS[name]], {
    cwd: BACKEND,
    env: backendEnv,
    stdio: ["ignore", log, log],
    detached: true,
  });
  child.unref();
  writeFileSync(path.join(PIDS, `${name}.pid`), String(child.pid));
}

export function stop(name: Process) {
  const file = path.join(PIDS, `${name}.pid`);
  try {
    process.kill(Number(readFileSync(file, "utf8")), "SIGKILL");
  } catch {
    return;
  } finally {
    rmSync(file, { force: true });
  }
}

export function stopAll() {
  let names: string[] = [];
  try {
    names = readdirSync(PIDS)
      .filter((file) => file.endsWith(".pid"))
      .map((file) => path.basename(file, ".pid"));
  } catch {
    return;
  }
  for (const name of names) if (name in COMMANDS) stop(name as Process);
}
