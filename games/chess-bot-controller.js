import { remainingBotPause } from "./bot-pacing.js?v=31356370ce09";
/** UCI transport for a separately licensed Stockfish worker. Game rules stay on the server. */
export function createChessBotController({snapshot, submit, showStatus, WorkerClass = globalThis.Worker}) {
  let job = null;
  let failedKey = "";
  function stop() {
    if (job) { clearTimeout(job.timeout); clearTimeout(job.delay); job.worker?.terminate(); job = null; }
  }
  function fail(key, message) {
    if (job?.key !== key) return;
    stop(); failedKey = key;
    showStatus(message, () => { failedKey = ""; sync(); });
  }
  function sync() {
    const current = snapshot();
    if (!current?.enabled || !current.task) { stop(); failedKey = ""; showStatus(""); return; }
    const key = current.key;
    if (job?.key === key || failedKey === key) return;
    stop();
    const task = current.task;
    const active = {started:performance.now(), key, worker: null, timeout: null, searching: false, submitted: false};
    job = active;
    async function finish(move, elapsedMs) {
      if (job !== active || active.submitted) return;
      const latest = snapshot();
      if (!latest?.enabled || latest.key !== key) { stop(); return; }
      active.submitted = true;
      clearTimeout(active.timeout); active.worker?.terminate(); active.worker = null;
      if (!task.respondToDraw) await new Promise(resolve => { active.delay = setTimeout(resolve, remainingBotPause(active.started, task)); });
      if (job !== active || !snapshot()?.enabled || snapshot()?.key !== key) return;
      showStatus("The bot is moving…");
      try {
        const ok = await submit({positionKey: task.positionKey, move, elapsedMs});
        if (!ok && snapshot()?.key === key) {
          fail(key, "The bot move could not be saved. Retry when the connection is available.");
          return;
        }
        if (job === active) { stop(); showStatus(""); }
        sync();
      } catch { fail(key, "The bot move could not be saved. Retry when the connection is available."); }
    }
    if (task.respondToDraw) { void finish("", 0); return; }
    showStatus("Loading the chess bot…");
    try {
      const worker = new WorkerClass(new URL("./vendor/stockfish/stockfish-18-lite-single.js", import.meta.url));
      active.worker = worker;
      active.timeout = setTimeout(() => fail(key, "The chess bot could not load. Check your connection, then retry."), 30000);
      let started = 0;
      worker.onerror = event => {
        event?.preventDefault?.();
        fail(key, "The chess engine could not run in this browser. Retry or use a current browser with WebAssembly support.");
      };
      worker.onmessage = event => {
        if (job !== active || typeof event.data !== "string") return;
        const line = event.data.trim();
        if (line === "uciok") {
          worker.postMessage("setoption name Hash value 16");
          worker.postMessage("setoption name Threads value 1");
          worker.postMessage("setoption name UCI_LimitStrength value true");
          worker.postMessage(`setoption name UCI_Elo value ${Number(task.rating)}`);
          worker.postMessage("ucinewgame");
          worker.postMessage("isready");
        } else if (line === "readyok" && !active.searching) {
          active.searching = true;
          clearTimeout(active.timeout);
          started = performance.now();
          showStatus("The bot is thinking…");
          worker.postMessage(task.position);
          worker.postMessage(`go movetime ${Number(task.moveTimeMs)}`);
          active.timeout = setTimeout(() => fail(key, "The chess engine took too long. Retry this move."), Math.max(5000, Number(task.moveTimeMs) + 2000));
        } else if (line.startsWith("bestmove ") && active.searching) {
          const move = line.split(/\s+/)[1];
          if (!/^[a-h][1-8][a-h][1-8][qrbn]?$/.test(move)) { fail(key, "The chess engine returned no usable move. Retry this position."); return; }
          void finish(move, Math.round(performance.now() - started));
        }
      };
      worker.postMessage("uci");
    } catch { fail(key, "The chess engine could not start. Retry or use a browser with WebAssembly support."); }
  }
  return {sync, stop};
}
