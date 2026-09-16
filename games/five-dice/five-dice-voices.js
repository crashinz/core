// Presentation preferences belong to the viewer, never the shared game state.
export const optionalVoices = Object.freeze([
  { key: "idleRoll", label: "Roll the dice", slot: "idle-roll-sound" },
  { key: "idleFeeling", label: "Feeling", slot: "idle-feeling-sound" },
  { key: "idleReady", label: "Ready", slot: "ready-sound" },
  { key: "idleSomething", label: "Something", slot: "idle-something-sound" },
  { key: "idleYawn", label: "Yawn", slot: "idle-yawn-sound" },
]);

export const originalVoices = Object.freeze([
  ...optionalVoices,
  { label: "Way to go", slot: "yahtzee-way-to-go-sound" },
  { label: "Your on", slot: "yahtzee-your-on-sound" },
  { label: "Your win", slot: "win-sound" },
  { label: "All right", slot: "upper-all-right-sound" },
  { label: "Crash", slot: "upper-crash-sound" },
  { label: "High power", slot: "repeat-yahtzee-sound" },
  { label: "Cheers", slot: "celebration-sound" },
  { label: "Low power (preview only)", slot: "low-power-sound" },
]);

export function voiceEnabled(options, key) {
  return options?.categories?.[key] === true;
}

export function classicScoreReaction(before, after, random = Math.random) {
  if (!before || !after || before.publicId !== after.publicId
      || after.presentation?.effectivePack !== "classic"
      || Number(after.stateVersion) !== Number(before.stateVersion) + 1
      || before.status !== "active" || before.state?.completed || after.state?.completed) return null;
  const actor = before.state?.turnOrder?.[before.state?.turnIndex];
  const a = before.state?.players?.[String(actor)], b = after.state?.players?.[String(actor)];
  if (!a || !b) return null;
  const changed = Object.keys(b.scorecard || {}).filter(k => a.scorecard?.[k] == null && b.scorecard[k] != null);
  if (changed.length !== 1) return null;
  let slots;
  if (Number(b.yahtzeeBonus || 0) > Number(a.yahtzeeBonus || 0)) slots = ["repeat-yahtzee-sound"];
  else if (changed[0] === "yahtzee" && Number(b.scorecard.yahtzee) === 50)
    slots = ["yahtzee-way-to-go-sound", "yahtzee-your-on-sound", "win-sound"];
  else if (Number(b.upperBonus || 0) > Number(a.upperBonus || 0)) slots = ["upper-crash-sound", "upper-all-right-sound"];
  if (!slots) return null;
  return { slot: slots[Math.min(slots.length - 1, Math.floor(Math.max(0, random()) * slots.length))],
    key: `classic-score:${after.publicId}:${after.stateVersion}:${actor}` };
}

export function chooseIdleVoice(options, rolls, index) {
  // Original idle sequence starts with Roll/Feeling, then Ready, Something, Yawn.
  const sequence = [optionalVoices[Number(rolls) < 2 ? 0 : 1], ...optionalVoices.slice(2)];
  const enabled = sequence.filter(v => voiceEnabled(options, v.key));
  return enabled.length ? enabled[index % enabled.length] : null;
}

// OCX 10012b24-10012d58: own best improves and is at least every seated best.
// Use authoritative, mode-scoped records; never infer a record from a live roll.
export function classicRecordReaction(before, after, viewerUserId) {
  if (!before?.publicId || before.publicId !== after?.publicId
      || before.mode !== after.mode || !["practice", "recorded"].includes(after.mode)
      || after.presentation?.effectivePack !== "classic"
      || !["master", "player"].includes(after.viewerRole)
      || Number(after.stateVersion) < Number(before.stateVersion) || !after.state?.completed) return null;
  const a = before.state?.players?.[String(viewerUserId)], b = after.state?.players?.[String(viewerUserId)];
  if (!a?.personalBest || !b?.personalBest || a.personalBest.mode !== after.mode || b.personalBest.mode !== after.mode) return null;
  const oldBest = a.personalBest.score == null ? 0 : a.personalBest.score, best = b.personalBest.score;
  const categories = ["ones","twos","threes","fours","fives","sixes","three-kind","four-kind","full-house","small-straight","large-straight","chance","yahtzee"];
  if (!Number.isFinite(oldBest) || !Number.isFinite(best) || best <= oldBest || best !== b.total
      || !categories.every(k => Number.isFinite(b.scorecard?.[k]))) return null;
  for (const [playerId, player] of Object.entries(after.state.players)) {
    // Practice bots have no account records; they must not suppress a human record cue.
    if (Number(playerId) < 0 && after.state.bots?.[playerId]?.userId === Number(playerId)) continue;
    if (player.personalBest?.mode !== after.mode) return null;
    const other = player.personalBest.score == null ? 0 : player.personalBest.score;
    if (!Number.isFinite(other) || other > best) return null;
  }
  return {slot:"celebration-sound", key:`classic-record:${after.publicId}:${viewerUserId}:${best}`, score:best};
}
