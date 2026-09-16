// Reviews keep view preferences in this frame only; ordinary games retain their existing keys.
const isolated = new URLSearchParams(typeof location === "undefined" ? "" : location.search).get('game_session_id')?.startsWith('review-') === true;
const values = new Map();
export const gameViewStorage = {
  getItem(key) { return isolated ? values.get(String(key)) ?? null : globalThis.localStorage.getItem(key); },
  setItem(key, value) { if (isolated) values.set(String(key), String(value)); else globalThis.localStorage.setItem(key, value); },
  removeItem(key) { if (isolated) values.delete(String(key)); else globalThis.localStorage.removeItem(key); }
};
