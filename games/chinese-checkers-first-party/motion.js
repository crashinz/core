/** Presentation follows committed moves only; reconnects never replay old moves. */
export function createMoveMotionTracker(now = () => performance.now()) {
  let sessionId = null, moveNumber = null, active = null;
  function current() { return active && now() - active.startedAt < active.duration ? active : null; }
  function observe(session, enabled) {
    const id = String(session?.publicId || "preview"), state = session?.state || {};
    const number = Number(state.moveNumber || 0), previous = moveNumber;
    if (id !== sessionId) { sessionId = id; moveNumber = number; active = null; return null; }
    if (number !== moveNumber) {
      moveNumber = number; active = null;
      const move = state.lastAction, path = move?.path;
      if (enabled && number === previous + 1 && move?.type === "move" && Array.isArray(path)
          && path.length > 0 && path.at(-1) === move.to
          && [move.from, ...path].every(hole => /^r\d{2}c\d{2}$/.test(hole))) {
        active = { ...move, path: [move.from, ...path], startedAt: now(),
          duration: move.kind === "jump" ? Math.min(2400, Math.max(700, path.length * 360)) : 700 };
      }
    }
    if (!enabled) active = null;
    return current();
  }
  return { observe, current };
}

export function moveKeyframes(points, kind) {
  const frames = [], segments = points.length - 1;
  const frame = (point, offset) => ({ left: `${point[0] / 8}%`, top: `${point[1] / 6.8}%`, offset });
  frames.push(frame(points[0], 0));
  for (let i = 1; i < points.length; i++) {
    if (kind === "jump") frames.push(frame([(points[i-1][0]+points[i][0])/2, (points[i-1][1]+points[i][1])/2-15], (i-.5)/segments));
    frames.push(frame(points[i], i/segments));
  }
  return frames;
}
