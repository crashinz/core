/** Viewing time, not extra search time. Engines can finish during this interval. */
export const BOT_ACTION_PAUSE_MS = 2000;
export function remainingBotPause(started, task = {}, now = performance.now()) {
  const requested = Number(task.presentationDelayMs ?? BOT_ACTION_PAUSE_MS);
  const duration = Number.isFinite(requested) ? Math.max(0, Math.min(5000, requested)) : BOT_ACTION_PAUSE_MS;
  return Math.max(0, duration - Math.max(0, now - started));
}
