import { start, stopAll } from "./processes";

export default function globalSetup() {
  stopAll();
  start("agent");
  start("realtime");
  start("reverb");
}
