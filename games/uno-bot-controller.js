import { BOT_ACTION_PAUSE_MS } from "./bot-pacing.js?v=31356370ce09";
/** Only opaque task identities cross the browser boundary; strategy runs on the server. */
export function createCardBotController({ gameName = "UNO", snapshot, submit, showStatus, schedule = setTimeout, cancel = clearTimeout }) {
  let job = null, failedKey = "";
  function stop() { if (job) { cancel(job.timer); job = null; } }
  function sync() {
    const current = snapshot();
    // An in-flight request owns its completion even when submit sets the UI busy.
    if (job?.submitted && current?.key === job.key) return;
    if (!current?.enabled || !current.task) { stop(); if (!current?.task) failedKey = ""; showStatus(""); return; }
    if (job?.key === current.key || failedKey === current.key) return;
    stop();
    const active = { key: current.key, submitted: false, timer: null }; job = active;
    showStatus(`${current.task.displayName || "The bot"} is thinking…`);
    active.timer = schedule(async () => {
      const latest = snapshot();
      if (job !== active || !latest?.enabled || latest.key !== active.key) { if (job === active) stop(); return; }
      active.submitted = true;
      try {
        const ok = await submit({ action: current.task.action, engine: current.task.engine, positionKey: current.task.positionKey });
        if (job !== active) return;
        if (!ok && snapshot()?.key === active.key) throw new Error("Not saved");
        stop(); showStatus(""); sync();
      } catch {
        if (job !== active) return;
        stop(); failedKey = active.key;
        showStatus(`The ${gameName} bot action could not be saved. Retry when the connection is available.`, () => { failedKey = ""; sync(); });
      }
    }, Math.max(BOT_ACTION_PAUSE_MS, Math.min(5000, Number(current.task.delayMs) || BOT_ACTION_PAUSE_MS)));
  }
  return { sync, stop };
}

export const createUnoBotController = createCardBotController;
