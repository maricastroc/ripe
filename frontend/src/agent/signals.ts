const signals = globalThis as unknown as {
  __ripeWake?: () => void;
  __ripeCancel?: (taskId: string) => boolean;
};

export function registerSignals(wake: () => void, cancel: (taskId: string) => boolean) {
  signals.__ripeWake = wake;
  signals.__ripeCancel = cancel;
}

export function wakeWorker() {
  signals.__ripeWake?.();
}

export function cancelRunning(taskId: string) {
  return signals.__ripeCancel?.(taskId) ?? false;
}
