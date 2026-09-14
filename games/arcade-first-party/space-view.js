// Display prediction only. No hit, kill, score, clock or position is submitted.
const clamp = x => Math.max(30, Math.min(930, x));
export class SpaceArcadeView {
  constructor() { this.reset(); }
  reset() {
    this.x = null; this.target = null; this.version = null;
    this.updated = 0; this.previous = null; this.nextShot = 0;
    this.shots = []; this.serverShots = []; this.firing = false;
  }
  accept(state, version, now) {
    // Repeated renders/polls of the same snapshot must not restart prediction.
    const key = `${version ?? ''}:${state.elapsedMs ?? 0}:${JSON.stringify(state.inputSequences || {})}`;
    if (key === this.version) return;
    this.version = key; this.updated = now;
    this.target = clamp(Number(state.ship.x));
    if (this.x === null || state.completed) this.x = this.target;
    if (this.previous === null) this.previous = now;
    this.serverShots = (state.orbs || []).map(orb => ({x:orb.x,y:orb.y,at:now}));
    if (state.completed) { this.shots = []; this.firing = false; }
  }
  fire(now) {
    if (this.x === null || now < this.nextShot || now - this.updated > 1200) return;
    this.shots.push({x:this.x,y:546,at:now});
    this.nextShot = now + 280;
  }
  frame(now, controls, active) {
    const dt = Math.max(0, Math.min(100, now - (this.previous ?? now)));
    this.previous = now;
    if (this.x === null) return {x:268.8,orbs:[]};
    // Match the server's finite input lease; do not animate endless offline play.
    const responsive = active && now - this.updated <= 1200;
    const direction = responsive ? Number(controls.right) - Number(controls.left) : 0;
    if (direction) {
      this.x = clamp(this.x + direction * 380 * dt / 1000);
    } else if (active && this.target !== null) {
      // Authoritative correction is blended, never a receipt-time teleport.
      this.x = clamp(this.x + (this.target - this.x) * Math.min(1, dt / 180));
    }
    if (responsive && controls.fire) this.fire(now);
    this.firing = responsive && controls.fire;
    this.shots = this.shots.filter(shot => now - shot.at < 1080);
    const project = shot => ({x:shot.x,y:shot.y - 520 * Math.max(0,now-shot.at)/1000});
    // Local muzzle shots bridge acknowledgement latency. Server projectiles are
    // retained when no matching visible shot exists; neither path predicts hits.
    const orbs = active ? this.shots.map(project) : [];
    for (const shot of this.serverShots.map(project)) {
      if (shot.y >= -8 && !orbs.some(local => Math.abs(local.x-shot.x)<16 && Math.abs(local.y-shot.y)<150)) orbs.push(shot);
    }
    return {x:this.x,orbs};
  }
}
