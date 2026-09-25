// Play only pinned, server-provided review frames. Ordinary games never enter
// this path. Replay is local so network polling cannot skip short animations.
export function createFrozenReviewPlayback(deliver) {
  let identity = null, generation = 0, timer = 0, version = -1;
  function cancel() { generation++; clearTimeout(timer); timer = 0; }
  function receive(session) {
    if (!session?.frozenReference || !session.referencePlayback) return false;
    if (Number(session.stateVersion) < version) return true;
    version = Number(session.stateVersion);
    const replay = session.referencePlayback;
    if (identity === replay.id) return true;
    cancel(); identity = replay.id;
    const epoch = generation;
    const frames = replay.frames;
    if (!Array.isArray(frames) || !frames.length) return false;
    let index = 0;
    async function advance() {
      if (epoch !== generation) return;
      const item = frames[index];
      await deliver(item.frame);
      if (epoch !== generation || ++index >= frames.length) return;
      timer = setTimeout(advance, Math.max(0, frames[index].atMs - item.atMs));
    }
    void advance();
    return true;
  }
  addEventListener('pagehide', cancel, {once:true});
  return {receive, cancel};
}
