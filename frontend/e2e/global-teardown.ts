import { stopAll } from "./processes";

export default function globalTeardown() {
  stopAll();
}
