import { classicSourceMap } from "../classic-source-maps.js?v=1e1478bc";
import { viewerHeightFitEnabled, setViewerHeightFit, installViewportHeightFit } from "../viewport-height-fit.js?v=c2557c225fbc";

import { bindGameAvatar } from "../game-avatar.js?v=20260913-room-avatars";

const params = new URLSearchParams(location.search);
const context = Object.freeze({
  sessionId: params.get("session_id") || "",
  participantId: Number(params.get("participant_id") || params.get("user") || 0),
  joinToken: params.get("join_token") || "",
  gameSessionId: params.get("game_session_id") || params.get("lobby") || "",
  csrf: params.get("csrf") || ""
});
const connectionEpoch = crypto.randomUUID();
let activeGameSessionId = context.gameSessionId;
const LOOPBACK_HOST = /^(?:127(?:\.\d{1,3}){3}|localhost)$/i;
const GAME_REQUEST_TIMEOUT_MS = LOOPBACK_HOST.test(location.hostname) ? 8000 : 30000;
const activeRequestControllers = new Set();

const categoryLabels = Object.freeze({
  ones: "Ones", twos: "Twos", threes: "Threes", fours: "Fours", fives: "Fives", sixes: "Sixes",
  "three-kind": "Three of a Kind", "four-kind": "Four of a Kind", "full-house": "Full House",
  "small-straight": "Small Straight", "large-straight": "Large Straight", chance: "Chance", yahtzee: "Yahtzee"
});
const classicRows = Object.freeze({
  ones: 0, twos: 1, threes: 2, fours: 3, fives: 4, sixes: 5,
  "three-kind": 7, "four-kind": 8, "full-house": 9,
  "small-straight": 10, "large-straight": 11, chance: 12, yahtzee: 13
});
const classicGeometry = classicSourceMap("five-dice");
const diceGlyphs = ["⚀", "⚁", "⚂", "⚃", "⚄", "⚅"];
let session = null;
let options = null;
let optionsMutationRevision = 0;
let optionsSaveTail = Promise.resolve();
let records = null;
let busy = false;
let actionFailureStatusMessage = "";
let pollTimer = 0;
let refreshCount = 0;
let scoreRecordsVisible = false;
let gameOptionsVisible = false;
let fiveDiceHeightFitCleanup = null;
let fiveDiceStatusLayoutCleanup = null;
let fiveDiceScoreLayoutCleanup = null;
let gameOptionsInitialStateResolved = false;
let settingsDraft = null;
let settingsDraftSha256 = "";
let sharedLifecycleDeadlineTimer = 0;
let sharedLifecycleRenderTimer = 0;
let sharedLifecycleSettlementInFlight = false;
let sessionProjectedAtMs = Date.now();
let newGameVoteStatus = null;
let documentVisible = !document.hidden;
let gameSurfaceVisible = true;
const audioPlayers = new Map();
const classicVisualPreloads = new Map();
const classicRollVisualSlots = Object.freeze([
  "rolling-dice-a", "rolling-dice-b", "drum-motion",
  ...Array.from({ length: 6 }, (_, index) => `die-${index + 1}`),
  ...Array.from({ length: 6 }, (_, index) => `held-die-${index + 1}`),
]);
const mediaTrace = [];
const playedStateAudioKeys = new Set();
let rolling = false;
let drumRolling = false;
let drumFrame = classicGeometry.motion.drumRestFrame;
const motionTimers = new Set();
let builtInOpeningKey = "";
let builtInOpeningUntil = 0;
let builtInOpeningTimer = 0;
let builtInScoreMotion = null;
let builtInScoreMotionTimer = 0;
let builtInTerminalMotionStartedAt = 0;
let builtInTerminalMotionTimer = 0;
let builtInResultDismissedKey = "";

Object.defineProperty(window, "__fiveDiceMediaTrace", {
  value: mediaTrace,
  configurable: false,
  enumerable: false,
  writable: false
});

const el = id => document.getElementById(id);


let terminalSessionError = null;
let pendingVisibleFiveDiceReconnect = null;
let visibleFiveDiceReconnectAttemptKey = "";
function isTerminalFiveDiceSessionError(error) {
  return error?.sessionBound === true && String(error.gameSessionId) === String(activeGameSessionId)
    && Number(error.participantId) === Number(context.participantId) && String(error.roomSessionId) === String(context.sessionId)
    && [401,403,404,410].includes(Number(error.httpStatus));
}
function endUnavailableFiveDiceSession(error) {
  if (terminalSessionError || !isTerminalFiveDiceSessionError(error)) return;
  terminalSessionError=error;
  fiveDiceStatusLayoutCleanup?.();
  fiveDiceStatusLayoutCleanup = null;
  for (const timer of [pollTimer,sharedLifecycleDeadlineTimer,sharedLifecycleRenderTimer,builtInOpeningTimer,builtInScoreMotionTimer,builtInTerminalMotionTimer]) clearTimeout(timer);
  clearClassicMotionTimers(); pauseAllAudio('session-unavailable');
  gameSurfaceVisible=false; documentVisible=false; busy=false;
  session=null; options=null; records=null;
  document.body.classList.add('five-dice-session-unavailable');
  const surface=document.createElement('main'); surface.className='five-dice-unavailable';
  const heading=document.createElement('h1'); heading.textContent='Five Dice is unavailable';
  const notice=document.createElement('p'); notice.id='game-status'; notice.className='status'; notice.setAttribute('role','alert');
  notice.textContent='This game session is no longer available. Return to the room to choose a game.';
  const returnUrl=new URL(context.sessionId ? '../../chatroom.php' : '../../lobby.php',location.href);
  if (context.sessionId) returnUrl.searchParams.set('id',context.sessionId);
  const returnLink=document.createElement('a'); returnLink.className='five-dice-unavailable-return'; returnLink.href=returnUrl.href; returnLink.target='_top'; returnLink.textContent='Return to room';
  surface.append(heading,notice,returnLink);
  document.body.replaceChildren(surface);
  if (window.parent !== window) window.parent.postMessage({type:'corechat-game-session-unavailable',lobbyCode:String(activeGameSessionId),participantId:Number(context.participantId),httpStatus:error.httpStatus,code:error.code},location.origin);
}
function visibleFiveDiceReconnectMembership() {
  if (!session || session.status !== "active" || session.state?.completed !== false
    || !["master", "player"].includes(String(session.viewerRole || ""))) return null;
  const publicId = String(activeGameSessionId || "");
  const participantId = Number(context.participantId);
  if (!publicId || session.publicId !== publicId || !Number.isInteger(participantId) || participantId <= 0) return null;
  const member = session.members?.find(item => Number(item.participantId) === participantId);
  const userId = Number(member?.userId);
  if (!Number.isInteger(userId) || userId <= 0 || member?.membershipStatus !== "active"
    || member?.role !== session.viewerRole) return null;
  if (window.parent !== window) {
    try { if (!window.frameElement?.isConnected) return null; }
    catch { return null; }
  }
  return { publicId, participantId, userId, key: JSON.stringify([publicId, participantId, userId, connectionEpoch]) };
}

async function reconnectVisibleFiveDiceSession() {
  const binding = visibleFiveDiceReconnectMembership();
  if (!binding) return;
  const player = session.state?._framework?.players?.[String(binding.userId)];
  if (player?.disconnected === false) {
    visibleFiveDiceReconnectAttemptKey = "";
    return;
  }
  if (player?.disconnected !== true || terminalSessionError || !gameSurfaceVisible
    || !documentVisible || document.hidden || busy || session.state?._framework?.serviceInterruption?.active === true) return;
  const attemptKey = JSON.stringify([binding.key, String(player.disconnectedAt || "")]);
  if (pendingVisibleFiveDiceReconnect || visibleFiveDiceReconnectAttemptKey === attemptKey) return;
  const pending = {};
  pendingVisibleFiveDiceReconnect = pending;
  // Match shared OCX recovery: one request per observed disconnect episode.
  // Only the server may clear the disconnect and charge cumulative grace time.
  visibleFiveDiceReconnectAttemptKey = attemptKey;
  try {
    const nextSession = await post("reconnect", { client_epoch: connectionEpoch });
    const currentBinding = visibleFiveDiceReconnectMembership();
    if (pendingVisibleFiveDiceReconnect !== pending || terminalSessionError || !gameSurfaceVisible
      || !documentVisible || document.hidden || busy || !currentBinding || currentBinding.key !== binding.key) return;
    assertGameSessionEnvelope(nextSession, 200, {
      sessionBound: true, gameSessionId: binding.publicId,
      participantId: binding.participantId, roomSessionId: context.sessionId,
    });
    const member = nextSession.members.find(item => Number(item.participantId) === binding.participantId);
    if (Number(member?.userId) !== binding.userId || member?.membershipStatus !== "active"
      || member?.role !== session.viewerRole || nextSession.viewerRole !== session.viewerRole) {
      throw Object.assign(new Error("The game server returned a different reconnect player."), {
        code: "GAME_STATE_INVALID", retryable: false,
        facts: { validationReason: "reconnect-viewer-mismatch" },
      });
    }
    if (Number(nextSession.stateVersion) < Number(session.stateVersion)) return;
    replaceSession(nextSession);
  } finally {
    if (pendingVisibleFiveDiceReconnect === pending) pendingVisibleFiveDiceReconnect = null;
  }
}

async function startFiveDiceSessionConnection() {
  if (terminalSessionError) return;
  const requestGameId=activeGameSessionId;
  try { await post('reconnect',{client_epoch:connectionEpoch}); }
  catch (error) {
    if (terminalSessionError || requestGameId !== activeGameSessionId) return;
    if (isTerminalFiveDiceSessionError(error)) { endUnavailableFiveDiceSession(error); return; }
  }
  if (!terminalSessionError && requestGameId === activeGameSessionId) await refresh();
}
function gameSessionEnvelopeProblem(value) {
  const record = item => item !== null && typeof item === "object" && !Array.isArray(item);
  if (!record(value)) return "session-object";
  if (typeof value.publicId !== "string" || value.publicId.trim() === "") return "session-id";
  if (typeof value.status !== "string" || value.status.trim() === "") return "session-status";
  if (typeof value.settingsSha256 !== "string") return "settings-hash";
  const version = value.stateVersion;
  if (!(typeof version === "number" || (typeof version === "string" && /^\d+$/.test(version)))
    || !Number.isFinite(Number(version)) || !Number.isInteger(Number(version)) || Number(version) < 0) return "session-version";
  if (!Array.isArray(value.members) || !value.members.every(record)) return "session-members";
  if (value.state === null || typeof value.state !== "object") return "session-state-container";
  const framework = value.state._framework;
  if (framework !== undefined && framework !== null) {
    if (!record(framework)) return "framework-object";
    const players = framework.players;
    if (players !== undefined && players !== null
      && (typeof players !== "object" || !Object.values(players).every(record))) return "framework-players";
  }
  return "";
}

function assertGameSessionEnvelope(value, httpStatus = 0, binding = null) {
  const reason = gameSessionEnvelopeProblem(value)
    || (value.publicId !== (binding?.gameSessionId ?? activeGameSessionId) ? "session-id-mismatch" : "");
  if (!reason) return;
  const error = new Error("The game server returned an invalid game session.");
  error.code = "GAME_STATE_INVALID";
  error.httpStatus = Number(httpStatus || 0);
  error.retryable = false;
  error.facts = { validationReason: reason };
  throw Object.assign(error, binding || {});
}

async function readJsonResponse(response, fallbackMessage, binding = null) {
  if (terminalSessionError) throw terminalSessionError;
  const expired = response.redirected && /\/(?:login|logout)\.php$/i.test(new URL(response.url, location.href).pathname);
  let data;
  try { data = await response.json(); } catch { data = null; }
  if (terminalSessionError) throw terminalSessionError;
  if (!response.ok || expired || !data || typeof data !== 'object' || Array.isArray(data) || data.error) {
    const status = expired ? 401 : Number(response.status || 0);
    throw Object.assign(new Error(String(data?.error || (expired ? 'Your CoreChat session expired. Return to the room and reopen Five Dice.' : fallbackMessage))), {httpStatus:status, code:String(data?.code || (expired ? 'AUTHENTICATION_REQUIRED' : 'GAME_REQUEST_FAILED')), retryable:status === 0 || status === 429 || status >= 500}, binding || {});
  }
  return data;
}

function showLoadError(message) {
  if (terminalSessionError) return;

  const status = el("game-status");
  status.textContent = String(message || "The game could not be loaded.");
  status.classList.remove("sr-only");
  status.classList.add("status");
  status.dataset.loadError = "true";
}

function showActionFailure(message) {
  if (terminalSessionError) return;

  actionFailureStatusMessage = String(message || "The game action could not be completed.");
  const status = el("game-status");
  status.textContent = actionFailureStatusMessage;
  status.classList.remove("sr-only");
  status.classList.add("status");
  status.dataset.actionError = "true";
}

function clearActionFailure() {
  actionFailureStatusMessage = "";
  if (terminalSessionError) return;

  const status = el("game-status");
  if (status.dataset.actionError !== "true") return;
  delete status.dataset.actionError;
  if (status.dataset.loadError === "true") return;
  status.classList.remove("status");
  status.classList.add("sr-only");
}

function clearLoadError() {
  if (terminalSessionError) return;

  const status = el("game-status");
  if (status.dataset.loadError !== "true") return;
  delete status.dataset.loadError;
  if (actionFailureStatusMessage) {
    showActionFailure(actionFailureStatusMessage);
    return;
  }
  status.classList.remove("status");
  status.classList.add("sr-only");
}

async function gameFetch(input, init = {}) {
  if (typeof globalThis.AbortController !== "function") return fetch(input, init);
  const controller = new AbortController();
  activeRequestControllers.add(controller);
  let expired = false;
  const timeout = setTimeout(() => {
    expired = true;
    controller.abort("timeout");
  }, GAME_REQUEST_TIMEOUT_MS);
  try {
    return await fetch(input, { ...init, signal: controller.signal });
  } catch (error) {
    if (!expired) throw error;
    throw Object.assign(new Error("The game server did not respond in time."), {
      code: "GAME_REQUEST_TIMEOUT", retryable: true, cause: error,
    });
  } finally {
    clearTimeout(timeout);
    activeRequestControllers.delete(controller);
  }
}

async function getSession() {
  if (terminalSessionError) throw terminalSessionError;
  const requestGameId = activeGameSessionId;
  const query = new URLSearchParams({action:'session', session_id:context.sessionId, participant_id:String(context.participantId), join_token:context.joinToken, game_session_id:requestGameId});
  const response = await gameFetch('../../api/game_framework.php?' + query, {cache:'no-store',credentials:'same-origin'});
  const data = await readJsonResponse(response, 'The game could not be loaded.', {sessionBound:true, gameSessionId:requestGameId, participantId:context.participantId, roomSessionId:context.sessionId});
  if (requestGameId !== activeGameSessionId) throw Object.assign(new Error('An older game response was discarded.'), {staleResponse:true});
  assertGameSessionEnvelope(data, response.status, {sessionBound:true, gameSessionId:requestGameId, participantId:context.participantId, roomSessionId:context.sessionId});
  return data;
}

async function getOptions() {
  if (terminalSessionError) throw terminalSessionError;

  const query = new URLSearchParams({
    action: "options", session_id: context.sessionId, participant_id: String(context.participantId),
    join_token: context.joinToken, game_key: "g_4f8c2d71"
  });
  const response = await fetch(`../../api/game_framework.php?${query}`, { cache: "no-store", credentials: "same-origin" });
  const data = await readJsonResponse(response, "Game sound settings are unavailable.");
  if (!response.ok) throw new Error(data.error || "Game sound settings are unavailable.");
  return data;
}

async function getRecords() {
  if (terminalSessionError) throw terminalSessionError;

  const query = new URLSearchParams({
    action: "records", session_id: context.sessionId, participant_id: String(context.participantId),
    join_token: context.joinToken, game_key: "g_4f8c2d71"
  });
  const response = await fetch(`../../api/game_framework.php?${query}`, { cache: "no-store", credentials: "same-origin" });
  const data = await readJsonResponse(response, "Recorded results are unavailable.");
  if (!response.ok) throw new Error(data.error || "Recorded results are unavailable.");
  return data;
}

async function saveOptions(nextOptions) {
  const revision = ++optionsMutationRevision;
  const payload = {
    game_key: "g_4f8c2d71",
    options: {
      masterVolume: Number(nextOptions.masterVolume ?? 100),
      musicEnabled: Boolean(nextOptions.musicEnabled),
      voiceEnabled: Boolean(nextOptions.voiceEnabled),
      effectsEnabled: Boolean(nextOptions.effectsEnabled),
      categories: nextOptions.categories || {},
      individual: nextOptions.individual || {}
    }
  };
  const request = optionsSaveTail.then(() => post("options", payload));
  optionsSaveTail = request.catch(() => undefined);
  try {
    const data = await request;
    if (revision === optionsMutationRevision) {
      options = data;
      renderMediaControls();
    }
    return data;
  } catch (error) {
    error.currentOptionsMutation = revision === optionsMutationRevision;
    throw error;
  }
}

function traceMedia(event, slot = null, details = {}) {
  const record = { event, slot, at: new Date().toISOString(), ...details };
  mediaTrace.push(record);
  if (mediaTrace.length > 200) mediaTrace.shift();
  if (/^(?:audio-|effect-|music-|all-audio|state-audio|terminal-win-sound|terminal-score|positive-score|accepted-zero-score)/.test(event)) {
    document.body.dataset.lastMediaTrace = JSON.stringify(record);
  }
  if (/^(?:state-audio|terminal-win-sound)/.test(event)) {
    document.body.dataset.lastStateAudioTrace = JSON.stringify(record);
  }
  if (/^(?:dice-motion|drum-motion)/.test(event)) {
    document.body.dataset.lastMotionTrace = JSON.stringify(record);
  }
}

function fiveDicePresentationState(value = session) {
  const state = value?.state || {};
  const status = String(value?.status || "");
  if (state.completed === true || !["completed", "forfeited", "abandoned"].includes(status)) return state;
  // The shared forfeit path can end the session without a reducer result.
  // This copy is presentation-only; partial scores must never invent a winner.
  return { ...state, completed: true, winnerUserId: null, terminalReason: "session-" + status };
}

function fiveDiceWinnerUserIds(state = {}) {
  if (state.completed !== true) return [];
  const players = state.players || {};
  if (state.winnerUserId !== null && state.winnerUserId !== undefined) {
    const winner = Number(state.winnerUserId);
    return Number.isSafeInteger(winner) && winner > 0 && Object.hasOwn(players, String(winner)) ? [winner] : [];
  }
  const reason = String(state.terminalReason || "");
  const entries = Object.entries(players).filter(([userId]) => Number(userId) > 0);
  if (reason === "resignation") {
    const resignedUserId = Number(state.resignedUserId || 0);
    if (!Number.isSafeInteger(resignedUserId) || resignedUserId < 1) return [];
    const remaining = entries.filter(([userId]) => Number(userId) !== resignedUserId
      && Number.isFinite(players[userId]?.total));
    if (!remaining.length) return [];
    const maximum = Math.max(...remaining.map(([, player]) => player.total));
    return remaining.filter(([, player]) => player.total === maximum).map(([userId]) => Number(userId));
  }
  if (reason) return [];
  const categories = ["ones", "twos", "threes", "fours", "fives", "sixes", "three-kind", "four-kind", "full-house", "small-straight", "large-straight", "chance", "yahtzee"];
  if (!entries.length || entries.some(([, player]) => !Number.isFinite(player?.total)
      || categories.some(category => !Number.isFinite(player?.scorecard?.[category])))) return [];
  const maximum = Math.max(...entries.map(([, player]) => player.total));
  return entries.filter(([, player]) => player.total === maximum).map(([userId]) => Number(userId));
}

function fiveDiceTerminalSummary(state, viewerUserId, nameOf, displayName) {
  const winners = fiveDiceWinnerUserIds(state);
  const reason = String(state.terminalReason || "");
  const names = winners.map(nameOf);
  const isDraw = winners.length > 1;
  const viewerIsPlayer = Object.hasOwn(state.players || {}, String(viewerUserId));
  const viewerWon = winners.length === 1 && winners.includes(Number(viewerUserId));
  const outcome = !winners.length ? "ended" : isDraw ? "draw" : viewerWon ? "win" : viewerIsPlayer ? "loss" : "ended";
  const title = !winners.length
    ? reason === "session-forfeited" ? "Game forfeited" : reason === "session-abandoned" ? "Game abandoned" : "Game ended"
    : isDraw ? "Draw" : viewerWon ? "You win" : viewerIsPlayer ? "You lose" : names[0] + " wins";
  const resignedUserId = Number(state.resignedUserId || 0);
  const ending = reason === "resignation"
    ? resignedUserId === Number(viewerUserId) ? "Your resignation was recorded." : `${nameOf(resignedUserId)} resigned.`
    : reason === "ranked-inactivity-expiration" ? "Inactivity time expired."
    : reason === "session-forfeited" ? "The session was forfeited."
      : reason === "session-abandoned" ? "The session was abandoned."
        : reason === "session-completed" ? "The session has ended."
          : reason ? "The game ended under the session rules." : "";
  const highest = winners.length ? Math.max(...winners.map(userId => Number(state.players?.[String(userId)]?.total || 0))) : 0;
  const detail = !winners.length
    ? (ending ? ending + " Outcome unavailable." : "The result is not available in this game state.")
    : isDraw ? names.join(" & ") + " tied with " + highest + (ending ? ". " + ending : "")
    : ending ? names[0] + " wins. " + ending
    : names[0] + " finished with " + highest;
  return { winners, outcome, title, detail, gameText: displayName + (reason || !winners.length ? " ended" : " finished") };
}

function playStateAudioOnce(key, slot, details = {}) {
  if (playedStateAudioKeys.has(key)) {
    traceMedia("state-audio-suppressed", slot, { reason: "already-played", key, ...details });
    return;
  }
  playedStateAudioKeys.add(key);
  traceMedia("state-audio-owned", slot, { key, ...details });
  playOptionalSound(slot);
}

function observeSessionTransition(before, after) {
  if (!before || !after) return;
  const publicId = String(after.publicId || activeGameSessionId);
  const authoritativeRoundStarted = String(after.status || "") === "active"
    && (String(before.status || "") === "lobby"
      || (before.state?.completed && String(before.publicId || activeGameSessionId) !== publicId));
  if (authoritativeRoundStarted) {
    builtInOpeningKey = `opening:${publicId}:${String(after.startedAt || after.stateVersion || "start")}`;
    builtInOpeningUntil = Date.now() + 3800;
    clearTimeout(builtInOpeningTimer);
    builtInOpeningTimer = window.setTimeout(() => {
      builtInOpeningUntil = 0;
      render();
    }, 3820);
    playStateAudioOnce(`ready:${publicId}:${String(after.startedAt || after.stateVersion || "start")}`, "ready-sound", {
      owner: "authoritative-new-round-start",
      fromStatus: String(before.status || ""),
      toStatus: String(after.status || ""),
    });
  }
  const beforeTurnOrder = Array.isArray(before.state?.turnOrder) ? before.state.turnOrder : [];
  const afterTurnOrder = Array.isArray(after.state?.turnOrder) ? after.state.turnOrder : [];
  const beforeTurnUserId = Number(beforeTurnOrder[Number(before.state?.turnIndex ?? -1)] || 0);
  const afterTurnUserId = Number(afterTurnOrder[Number(after.state?.turnIndex ?? -1)] || 0);
  if (!authoritativeRoundStarted
      && String(after.status || "") === "active"
      && !after.state?.completed
      && afterTurnUserId > 0
      && beforeTurnUserId !== afterTurnUserId) {
    playStateAudioOnce(`turn:${publicId}:${Number(after.stateVersion || 0)}:${afterTurnUserId}`, "turn-start-sound", {
      owner: "authoritative-turn-start",
      turnUserId: afterTurnUserId,
    });
  }
  if (!before.state?.completed && after.state?.completed) {
    builtInTerminalMotionStartedAt = Date.now();
    builtInResultDismissedKey = "";
    clearTimeout(builtInTerminalMotionTimer);
    builtInTerminalMotionTimer = window.setTimeout(() => render(), 4720);
    const winners = fiveDiceWinnerUserIds(after.state);
    const viewerUserId = Number(after.members?.find(member => Number(member.participantId) === context.participantId)?.userId || 0);
    const key = `terminal:${publicId}:${Number(after.stateVersion || 0)}:${winners.join(",")}`;
    const viewerCanOwnResultAudio = winners.length > 0 && ["master", "player"].includes(String(after.viewerRole || ""));
    const tiedLeaders = winners.length > 1;
    const viewerIsWinner = winners.includes(viewerUserId);
    const slot = !winners.length ? null : tiedLeaders && viewerIsWinner ? "draw-sound" : viewerIsWinner ? "win-sound" : "loser-sound";
    if (viewerCanOwnResultAudio) {
      playStateAudioOnce(key, slot, {
        owner: tiedLeaders && viewerIsWinner ? "authoritative-terminal-draw" : viewerIsWinner ? "authoritative-terminal-winner" : "authoritative-terminal-loser",
        outcome: tiedLeaders && viewerIsWinner ? "draw" : viewerIsWinner ? "winner" : "loser",
        winnerUserIds: winners,
        viewerUserId,
      });
    } else {
      traceMedia("terminal-result-sound-suppressed", slot, {
        reason: winners.length ? "viewer-is-not-a-player" : "outcome-unavailable",
        winnerUserIds: winners,
        viewerUserId,
      });
    }
  }
}

function replaceSession(nextSession) {
  if (terminalSessionError) return session;

  assertGameSessionEnvelope(nextSession);
  const before = session;
  const beforeVersion = Number(before?.stateVersion || 0);
  const nextVersion = Number(nextSession?.stateVersion || 0);
  if (before && nextSession.publicId === before.publicId && nextVersion < beforeVersion) {
    traceMedia("stale-session-refresh-rejected", null, { beforeVersion, nextVersion });
    return session;
  }
  if (before && before.settingsSha256 !== nextSession?.settingsSha256) {
    settingsDraft = null;
    settingsDraftSha256 = "";
  }
  session = nextSession;
  sessionProjectedAtMs = Date.now();
  observeSessionTransition(before, session);
  return session;
}

function stableFiveDiceRenderIdentity(value) {
  const stable = structuredClone(value ?? null);
  if (stable && typeof stable === "object") {
    delete stable.now;
    delete stable.nowUnixMs;
    for (const member of stable.members || []) delete member.lastSeenAt;
    const framework = stable.state?._framework;
    if (framework) {
      for (const player of Object.values(framework.players || {})) {
        delete player.disconnectUsedSeconds;
        delete player.disconnectRemainingSeconds;
      }
      if (framework.pause) delete framework.pause.resumeRemainingSeconds;
      if (framework.inactivity) delete framework.inactivity.remainingProjectedSeconds;
    }
  }
  return JSON.stringify(stable);
}

function setFiveDiceActionPendingPresentation(pending) {
  const value = pending ? "true" : "false";
  document.body.dataset.gameActionPending = value;
  el("play-surface")?.setAttribute("aria-busy", value);
}

async function post(action, body = {}, requestOptions = {}) {
  if (terminalSessionError) throw terminalSessionError;
  const requestGameId = activeGameSessionId;
  const payload = {action, session_id:context.sessionId, participant_id:context.participantId, join_token:context.joinToken, game_session_id:requestGameId, _csrf:context.csrf, ...body};
  const response = await gameFetch('../../api/game_framework.php', {method:'POST',credentials:'same-origin',cache:'no-store',keepalive:requestOptions.keepalive === true,headers:{'Content-Type':'application/json','X-CSRF-Token':context.csrf},body:JSON.stringify(payload)});
  const bound = String(payload.game_session_id) === String(requestGameId) && Number(payload.participant_id) === Number(context.participantId) && String(payload.session_id) === String(context.sessionId) && payload.join_token === context.joinToken;
  const data = await readJsonResponse(response, 'The game action could not be completed.', {sessionBound:bound,gameSessionId:requestGameId,participantId:context.participantId,roomSessionId:context.sessionId});
  if (requestGameId !== activeGameSessionId) throw Object.assign(new Error('An older game response was discarded.'), {staleResponse:true});
  return data;
}

function randomId(prefix) {
  return `${prefix}-${crypto.randomUUID()}`;
}

function randomPracticeDice() {
  const dice = [];
  while (dice.length < 5) {
    const bytes = new Uint8Array(8);
    crypto.getRandomValues(bytes);
    for (const value of bytes) {
      if (value >= 252) continue;
      dice.push((value % 6) + 1);
      if (dice.length === 5) break;
    }
  }
  return dice;
}

async function sha256Canonical(value) {
  const keys = Object.keys(value).sort();
  const canonical = JSON.stringify(value, keys);
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(canonical));
  return Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, "0")).join("").toUpperCase();
}

async function reconcileFiveDiceActionFailure(error, actionType, expectedVersion) {
  let reconciled = false;
  try {
    const latest = await getSession();
    replaceSession(latest);
    reconciled = Number(session?.stateVersion || 0) >= Number(expectedVersion || 0);
  } catch (refreshError) {
    traceMedia("action-failure-reconciliation-unavailable", null, {
      actionType,
      expectedVersion: Number(expectedVersion || 0),
      currentVersion: Number(session?.stateVersion || 0),
      errorName: String(refreshError?.name || "Error"),
      serverCode: String(refreshError?.code || ""),
    });
  }
  const serverCode = String(error?.code || "");
  const stale = serverCode === "MULTIPLAYER_GAME_STATE_STALE";
  showActionFailure(stale && reconciled
    ? "The game changed before that action could be applied. The latest game state is shown; choose again."
    : String(error?.message || "The game action could not be completed."));
  traceMedia("action-rejected-reconciled", null, {
    actionType,
    serverCode,
    expectedVersion: Number(expectedVersion || 0),
    currentVersion: Number(session?.stateVersion || 0),
    reconciled,
  });
  return reconciled;
}

async function runAction(actionType, payload = {}) {
  if (busy || terminalSessionError || !session || session.status !== "active" || session.state?.completed) return { ok: false, reason: "not-playable" };
  clearActionFailure();
  const before = session;
  const expectedVersion = Number(session.stateVersion);
  busy = true;
  setFiveDiceActionPendingPresentation(true);
  try {
    const actionResult = await post("extension-action", {
      request_id: randomId("five-dice-action"), expected_version: expectedVersion,
      action_type: actionType, payload
    });
    replaceSession(actionResult?.session || await getSession());
    return { ok: true, before, after: session };
  } catch (error) {
    await reconcileFiveDiceActionFailure(error, actionType, expectedVersion);
    playOptionalSound("invalid-sound");
    return { ok: false, before, error, after: session };
  } finally {
    busy = false;
    setFiveDiceActionPendingPresentation(false);
    render();
  }
}

async function roll() {
  if (!canCurrentViewerRoll()) return;
  clearActionFailure();
  const expectedVersion = Number(session.stateVersion);
  busy = true;
  const classic = session.presentation?.effectivePack === "classic";
  const reducedMotion = matchMedia("(prefers-reduced-motion: reduce)").matches;
  const gfxEnabled = options?.categories?.gfxEnabled !== false;
  const musicEnabled = options?.musicEnabled === true;
  const diceMotionEnabled = gfxEnabled && !reducedMotion;
  const drumMotionEnabled = (classic ? musicEnabled : gfxEnabled) && !reducedMotion;
  let animationStartedAt = null;
  setFiveDiceActionPendingPresentation(true);
  try {
    if (classic) await ensureClassicRollVisuals();
    rolling = diceMotionEnabled;
    drumRolling = drumMotionEnabled;
    drumFrame = drumRolling ? classicGeometry.motion.drumSequence[0] : classicGeometry.motion.drumRestFrame;
    animationStartedAt = performance.now();
    if (classic) startClassicRollMotion();
    render();
    const randomnessRequestId = randomId("five-dice-roll");
    if (session.mode === "practice") {
      const reveal = { dice: randomPracticeDice(), nonce: crypto.randomUUID() };
      const commitment = await sha256Canonical(reveal);
      await post("randomness", { request_id: randomnessRequestId, commitment_sha256: commitment, purpose: "five-dice-roll" });
      await post("reveal-practice-randomness", { request_id: randomnessRequestId, reveal });
    } else {
      await post("randomness", { request_id: randomnessRequestId, purpose: "five-dice-roll" });
    }
    const actionResult = await post("extension-action", {
      request_id: randomId("five-dice-action"), expected_version: expectedVersion,
      action_type: "roll", payload: {}, randomness_request_id: randomnessRequestId
    });
    playOptionalBackgroundMusic();
    playOptionalSound("roll-sound");
    replaceSession(actionResult?.session || await getSession());
    traceMedia(diceMotionEnabled ? "dice-motion-started" : "dice-motion-suppressed", "rolling-dice-a", {
      durationMs: diceMotionEnabled ? classicGeometry.motion.diceDurationMs : 0,
      reason: diceMotionEnabled ? "gfx-on" : (reducedMotion ? "reduced-motion" : gfxEnabled ? "built-in" : "gfx-off"),
      source: "owner-private-ocx-motion-reference",
    });
    traceMedia(drumMotionEnabled ? "drum-motion-started" : "drum-motion-suppressed", "drum-motion", {
      durationMs: drumMotionEnabled ? classicGeometry.motion.drumFrameDurationsMs.reduce((total, duration) => total + duration, 0) : 0,
      reason: drumMotionEnabled ? "music-on" : (reducedMotion ? "reduced-motion" : musicEnabled ? "built-in" : "music-off"),
      source: "owner-private-ocx-motion-reference",
    });
  } catch (error) {
    await reconcileFiveDiceActionFailure(error, "roll", expectedVersion);
    playOptionalSound("invalid-sound");
  } finally {
    const sourceMotionDuration = classic
      ? Math.max(
        diceMotionEnabled ? classicGeometry.motion.diceDurationMs : 0,
        drumMotionEnabled ? classicGeometry.motion.drumFrameDurationsMs.reduce((total, duration) => total + duration, 0) : 0
      )
      : diceMotionEnabled ? 1260 : 0;
    const remaining = animationStartedAt === null
      ? 0
      : Math.max(0, sourceMotionDuration - (performance.now() - animationStartedAt));
    if (remaining > 0) await new Promise(resolve => setTimeout(resolve, remaining));
    clearClassicMotionTimers();
    rolling = false;
    drumRolling = false;
    drumFrame = classicGeometry.motion.drumRestFrame;
    busy = false;
    setFiveDiceActionPendingPresentation(false);
    render();
  }
}

function clearClassicMotionTimers() {
  for (const timer of motionTimers) clearTimeout(timer);
  motionTimers.clear();
}

function startClassicRollMotion() {
  clearClassicMotionTimers();
  if (!drumRolling) return;
  let elapsed = 0;
  classicGeometry.motion.drumSequence.forEach((frame, index) => {
    if (index === 0) return;
    elapsed += Number(classicGeometry.motion.drumFrameDurationsMs[index - 1] || 0);
    const timer = setTimeout(() => {
      drumFrame = Number(frame);
      renderDrumMotion();
      motionTimers.delete(timer);
    }, elapsed);
    motionTimers.add(timer);
  });
  elapsed += Number(classicGeometry.motion.drumFrameDurationsMs.at(-1) || 0);
  const restTimer = setTimeout(() => {
    drumRolling = false;
    drumFrame = classicGeometry.motion.drumRestFrame;
    renderDrumMotion();
    motionTimers.delete(restTimer);
  }, elapsed);
  motionTimers.add(restTimer);
}

function currentUserId() {
  return Number(session?.members?.find(member => Number(member.participantId) === context.participantId)?.userId || 0);
}

function memberName(userId) {
  return String(session?.members?.find(member => Number(member.userId) === Number(userId))?.displayName || "Player");
}

function gameLifecycleAvailable() {
  const userId = currentUserId();
  return ["master", "player"].includes(String(session?.viewerRole || ""))
    && session?.status === "active"
    && session?.state?.completed !== true
    && String(session?.state?._framework?.pause?.mode || "running") === "running"
    && session?.state?._framework?.serviceInterruption?.active !== true
    && session?.state?._framework?.players?.[String(userId)]?.disconnected !== true
    && !busy;
}

function setClassicSourceBox(node, geometry) {
  node.style.left = `${(Number(geometry.x) / classicGeometry.canvas.width) * 100}%`;
  node.style.top = `${(Number(geometry.y) / classicGeometry.canvas.height) * 100}%`;
  node.style.width = `${(Number(geometry.width) / classicGeometry.canvas.width) * 100}%`;
  node.style.height = `${(Number(geometry.height) / classicGeometry.canvas.height) * 100}%`;
  node.dataset.sourceBox = `${geometry.x},${geometry.y},${geometry.width},${geometry.height}`;
}

function clearClassicSourceBox(node) {
  for (const property of ["left", "top", "width", "height"]) node.style.removeProperty(property);
  delete node.dataset.sourceBox;
}

function bindExplicitKeyboardClick(button) {
  button.addEventListener("keydown", event => {
    if (event.key !== "Enter" && event.key !== " ") return;
    // Keep keyboard activation explicit and exactly once on the same native
    // button path used by pointer and touch-generated clicks.
    event.preventDefault();
    button.click();
  });
}

const fiveDiceBoardScaleSteps = [1, 1.25, 1.5, 1.75, 2];
const fiveDiceBoardScaleStorageKey = "corechat:five-dice:classic-board-scale";

function fiveDiceBoardScaleIndex() {
  const stored = Number(localStorage.getItem(fiveDiceBoardScaleStorageKey) || 1);
  const index = fiveDiceBoardScaleSteps.findIndex(scale => Math.abs(scale - stored) < 0.001);
  return index >= 0 ? index : 0;
}

function fiveDiceBoardScale() {
  return fiveDiceBoardScaleSteps[fiveDiceBoardScaleIndex()];
}

function fiveDiceCrispDieArtwork(value, held) {
  const crispSizeByScale = new Map([
    [1.25, 70],
    [1.5, 84],
    [1.75, 98],
    [2, 112],
  ]);
  const crispSize = crispSizeByScale.get(fiveDiceBoardScale());
  if (!crispSize) return null;
  const face = Math.max(1, Math.min(6, Math.trunc(Number(value) || 1)));
  const state = held ? "held" : "normal";
  const artwork = document.createElement("span");
  artwork.className = "classic-die-image classic-crisp-die-image";
  artwork.setAttribute("aria-hidden", "true");
  artwork.style.backgroundImage = `url("${new URL(`../../assets/images/five-dice-crisp-${state}-${crispSize}.png`, window.location.href).href}")`;
  artwork.style.backgroundRepeat = "no-repeat";
  artwork.style.backgroundSize = "600% 100%";
  artwork.style.backgroundPosition = `${(face - 1) * 20}% 0`;
  artwork.dataset.dieFace = String(face);
  artwork.dataset.dieState = state;
  artwork.dataset.crispAssetSize = `${crispSize}x${crispSize}`;
  return artwork;
}

function changeFiveDiceBoardScale(direction) {
  const current = fiveDiceBoardScaleIndex();
  const next = Math.max(0, Math.min(fiveDiceBoardScaleSteps.length - 1, current + Number(direction || 0)));
  localStorage.setItem(fiveDiceBoardScaleStorageKey, String(fiveDiceBoardScaleSteps[next]));
  render();
}

function applyFiveDiceBoardScale() {
  const board = el("play-surface");
  const classic = session?.presentation?.effectivePack === "classic";
  if (!classic) {
    board.style.removeProperty("--viewer-board-max-width");
    delete board.dataset.viewerScale;
    return;
  }
  const scale = fiveDiceBoardScale();
  board.style.setProperty("--viewer-board-max-width", `${Math.round(840 * scale)}px`);
  board.dataset.viewerScale = String(scale);
}

function renderDice(state, canAct) {
  if (terminalSessionError) return;

  const host = el("dice");
  host.replaceChildren();
  (state.dice || [1,1,1,1,1]).forEach((value, index) => {
    const classic = session?.presentation?.effectivePack === "classic";
    const displayValue = classic && Number(state.rollsThisTurn || 0) < 1 ? 0 : Number(value);
    const button = document.createElement("button");
    button.type = "button";
    const isRollingDie = rolling && !state.held?.[index];
    button.className = `die${isRollingDie ? " is-rolling" : ""}${state.held?.[index] ? " is-held" : ""}`;
    button.dataset.face = String(displayValue);
    button.dataset.held = state.held?.[index] ? "true" : "false";
    button.textContent = displayValue === 0 ? "·" : diceGlyphs[Math.max(1, Math.min(6, displayValue)) - 1];
    button.setAttribute("aria-label", displayValue === 0
      ? `Die ${index + 1}: not rolled`
      : `Die ${index + 1}: ${displayValue}, ${state.held?.[index] ? "kept" : "not kept"}`);
    button.setAttribute("aria-pressed", state.held?.[index] ? "true" : "false");
    const mediaSlot = displayValue > 0
      ? `${state.held?.[index] ? "held-die" : "die"}-${displayValue}`
      : null;
    if (classic) {
      button.classList.add("has-private-media");
      // The native button text would otherwise retain an anonymous line box
      // above the source-mapped image and push the die below its OCX recess.
      // The accessible name is owned by aria-label in Classic appearance.
      button.textContent = "";
      const geometry = isRollingDie ? classicGeometry.rollingDice[index] : classicGeometry.dice[index];
      const recess = classicGeometry.paintedRecesses[index];
      setClassicSourceBox(button, geometry);
      button.dataset.paintedRecessCenter = `${recess.x},${recess.y}`;
      button.dataset.alphaForegroundCentroid = `${classicGeometry.dieForegroundCentroid.x},${classicGeometry.dieForegroundCentroid.y}`;
      if (isRollingDie) {
        const strip = document.createElement("span");
        strip.className = "classic-roll-strip";
        strip.dataset.mediaSlot = index < 2 ? "rolling-dice-b" : "rolling-dice-a";
        strip.style.setProperty("--five-dice-roll-strip", `url("${mediaUrl(strip.dataset.mediaSlot)}")`);
        strip.setAttribute("aria-hidden", "true");
        button.append(strip);
      } else if (mediaSlot) {
        const crispArtwork = fiveDiceCrispDieArtwork(displayValue, state.held?.[index] === true);
        if (crispArtwork) {
          button.append(crispArtwork);
        } else {
          const image = document.createElement("img");
          image.className = "classic-die-image";
          image.dataset.mediaSlot = mediaSlot;
          image.src = mediaUrl(mediaSlot);
          image.alt = "";
          image.width = 56;
          image.height = 56;
          button.append(image);
        }
      }
    }
    button.disabled = !canAct || Number(state.rollsThisTurn) < 1 || Number(state.rollsThisTurn) >= 3 || busy;
    button.addEventListener("click", async () => {
      const wasHeld = Boolean(state.held?.[index]);
      const result = await runAction("toggle-hold", { index });
      if (result.ok) playOptionalSound(wasHeld ? "release-sound" : "hold-sound");
    });
    bindExplicitKeyboardClick(button);
    host.append(button);
  });
}

function renderDrumMotion() {
  if (terminalSessionError) return;

  const node = el("classic-drum-motion");
  const classic = session?.presentation?.effectivePack === "classic";
  node.hidden = !classic || !drumRolling;
  if (!classic) {
    clearClassicSourceBox(node);
    return;
  }
  setClassicSourceBox(node, classicGeometry.motion.drum);
  node.style.setProperty("--five-dice-drum-motion", `url("${mediaUrl("drum-motion")}")`);
  node.style.setProperty("--five-dice-drum-frame", String(drumFrame));
  node.style.setProperty("--five-dice-drum-offset", `${(drumFrame / Math.max(1, classicGeometry.motion.drumFrames - 1)) * 100}%`);
  node.dataset.frame = String(drumFrame);
  node.dataset.owner = "music";
}

function renderMicrophoneMotion() {
  if (terminalSessionError) return;

  const node = el("classic-microphone-motion");
  const strip = el("classic-microphone-strip");
  const classic = session?.presentation?.effectivePack === "classic";
  const legacyEffectsOn = options?.effectsEnabled !== false;
  const soundOn = options?.categories?.sfxEnabled ?? legacyEffectsOn;
  const active = classic && soundOn && documentVisible && gameSurfaceVisible;
  const previous = node.dataset.active === "true";
  node.hidden = !active;
  node.dataset.active = active ? "true" : "false";
  node.dataset.owner = "sfx";
  node.dataset.sourceFrames = String(classicGeometry.motion.microphoneFrames);
  node.dataset.sourceDurationMs = String(classicGeometry.motion.microphoneDurationMs);
  strip.classList.toggle("is-running", active);
  if (classic) {
    setClassicSourceBox(node, classicGeometry.motion.microphone);
    if (strip.dataset.mediaSlot !== "microphone-wave-strip") {
      strip.dataset.mediaSlot = "microphone-wave-strip";
      strip.src = mediaUrl("microphone-wave-strip");
    }
  } else {
    clearClassicSourceBox(node);
    delete strip.dataset.mediaSlot;
    strip.removeAttribute("src");
  }
  if (active !== previous) {
    traceMedia(active ? "microphone-cycle-started" : "microphone-cycle-stopped", "microphone-wave-strip", {
      owner: "sfx",
      sourceFrames: classicGeometry.motion.microphoneFrames,
      durationMs: classicGeometry.motion.microphoneDurationMs,
      reason: active ? "sfx-on" : (!classic ? "appearance" : !soundOn ? "sfx-off" : "hidden-lifecycle")
    });
  }
}

function scoreRowsForPlayer(playerState = {}) {
  const rows = Object.entries(categoryLabels).map(([category, label]) => ({
    label,
    value: playerState.scorecard?.[category]
  }));
  rows.splice(6, 0, {
    label: "Upper subtotal",
    value: Number(playerState.upperSubtotal || 0) + Number(playerState.upperBonus || 0),
    total: true
  });
  rows.splice(7, 0, { label: "Upper bonus", value: Number(playerState.upperBonus || 0), total: true });
  rows.splice(8, 0, { label: "Repeat Yahtzee bonuses", value: Number(playerState.yahtzeeBonus || 0), total: true });
  rows.push({ label: "Total", value: Number(playerState.total || 0), total: true });
  return rows;
}

function orderedPlayers(state) {
  const members = new Map(
    session.members
      .filter(member => ["master", "player"].includes(member.role) && member.membershipStatus === "active")
      .map(member => [Number(member.userId), member])
  );
  const activeOrder = Array.isArray(state.turnOrder) && state.turnOrder.length > 0
    ? state.turnOrder.map(Number)
    : [...members.keys()];
  const activeIds = new Set(activeOrder);
  const waitingOrder = [...members.values()]
    .filter(member => !activeIds.has(Number(member.userId)))
    .sort((left, right) => Number(left.seat ?? 999) - Number(right.seat ?? 999))
    .map(member => Number(member.userId));
  return [...activeOrder, ...waitingOrder].slice(0, 4).map((userId, index) => {
    const member = members.get(userId) || {};
    return {
      seat: index + 1,
      userId,
      displayName: member.displayName || `Player ${index + 1}`,
      avatarUrl: member.avatarUrl || member.avatarFallbackUrl || "",
      avatarFallbackUrl: member.avatarFallbackUrl || "",
      avatarHidden: member.avatarHidden === true,
      onHold: !activeIds.has(userId),
      state: state.players?.[String(userId)] || {}
    };
  });
}

function renderClassicAvatars(players) {
  if (terminalSessionError) return;

  const host = el("classic-avatars");
  const renderSignature = JSON.stringify(players.map(player => player ? [
    player.userId,
    player.seat,
    player.displayName,
    player.avatarUrl,
    player.avatarFallbackUrl,
    player.avatarHidden,
    player.onHold,
  ] : null));
  if (host.dataset.renderSignature === renderSignature && host.children.length === 4) return;
  host.dataset.renderSignature = renderSignature;
  host.replaceChildren();
  for (let column = 0; column < 4; column += 1) {
    const player = players[column];
    const seat = document.createElement("span");
    seat.className = `classic-avatar-seat${player ? " is-occupied" : " is-open"}`;
    seat.dataset.column = String(column + 1);
    seat.dataset.userId = player ? String(player.userId) : "";
    const avatarFrame = player
      ? classicGeometry.occupiedAvatarFrames?.[column] || classicGeometry.avatarFrames[column]
      : classicGeometry.avatarFrames[column];
    seat.dataset.avatarFrameBox = JSON.stringify(avatarFrame);
    seat.dataset.avatarApertureBox = JSON.stringify(classicGeometry.avatarWells[column]);
    setClassicSourceBox(seat, classicGeometry.avatarWells[column]);
    seat.setAttribute("role", "img");
    if (!player) {
      seat.setAttribute("aria-label", `Open player seat, score column ${column + 1}`);
      host.append(seat);
      continue;
    }
    seat.setAttribute("aria-label", `${player.displayName}, occupied player seat, score column ${column + 1}${player.onHold ? ", on hold and joining next round" : ""}${player.avatarHidden ? ", avatar hidden by your privacy preference" : ""}`);
    seat.dataset.identityBinding = `${player.userId}:${player.seat}:${column + 1}`;
    const image = document.createElement("img");
    image.alt = "";
    image.decoding = "async";
    const fallbackSvg = "data:image/svg+xml," + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#111b24"/><circle cx="32" cy="23" r="12" fill="#91a5ad"/><path d="M11 60c2-15 10-23 21-23s19 8 21 23" fill="#91a5ad"/></svg>');
    const fallbackUrl = String(player.avatarFallbackUrl || "");
    bindGameAvatar(image, { ...player, avatarFallbackUrl: fallbackUrl || fallbackSvg });
    seat.append(image);
    host.append(seat);
  }
}

function classicPosition(node, column, row) {
  const rowBox = classicGeometry.scoreRows[row];
  node.dataset.column = String(column + 1);
  node.dataset.row = String(row);
  setClassicSourceBox(node, { ...rowBox, x: classicGeometry.scoreColumns[column] });
}

function lowerTotal(playerState = {}) {
  return Object.keys(categoryLabels)
    .filter(category => Number(classicRows[category]) >= 7)
    .reduce((sum, category) => sum + Number(playerState.scorecard?.[category] || 0), 0);
}

function appendClassicCell(host, player, column, row, label, value, extraClass = "") {
  const cell = document.createElement("span");
  cell.className = `classic-score-cell ${extraClass}`.trim();
  cell.textContent = value === null || value === undefined ? "" : String(value);
  cell.setAttribute("aria-label", `${player.displayName}, ${label}: ${value === null || value === undefined ? "not scored" : value}`);
  classicPosition(cell, column, row);
  host.append(cell);
  return cell;
}

function setClassicScorePreview(category = null) {
  for (const button of el("classic-score-actions").querySelectorAll(".classic-score-choice")) {
    button.classList.toggle("is-previewing", button.dataset.category === category);
  }
  for (const cell of el("classic-score").querySelectorAll(".classic-score-preview")) {
    cell.hidden = cell.dataset.category !== category;
  }
}

async function chooseScore(category) {
  const beforePlayer = session?.state?.players?.[String(currentUserId())] || {};
  const beforeDice = Array.isArray(session?.state?.dice) ? session.state.dice.map(Number) : [];
  const result = await runAction("score", { category });
  if (!result.ok) {
    playOptionalSound("invalid-sound");
    return;
  }
  const afterPlayer = result.after?.state?.players?.[String(currentUserId())] || {};
  const categoryScore = Number(afterPlayer.scorecard?.[category] || 0);
  const repeatBonus = Number(afterPlayer.yahtzeeBonus || 0) - Number(beforePlayer.yahtzeeBonus || 0);
  const upperBonus = Number(afterPlayer.upperBonus || 0) - Number(beforePlayer.upperBonus || 0);
  const isFiveDice = beforeDice.length === 5 && beforeDice.every(value => value === beforeDice[0]);
  if (result.after?.presentation?.effectivePack !== "classic") {
    builtInScoreMotion = {
      category,
      upperBonus: upperBonus > 0,
      celebration: isFiveDice || repeatBonus > 0,
      startedAt: Date.now(),
    };
    clearTimeout(builtInScoreMotionTimer);
    builtInScoreMotionTimer = window.setTimeout(() => {
      builtInScoreMotion = null;
      render();
    }, upperBonus > 0 ? 1780 : 1580);
    render();
  }
  if (result.after?.state?.completed) {
    traceMedia("terminal-score-settled", null, {
      category,
      categoryScore,
      repeatBonus,
      winnerUserIds: fiveDiceWinnerUserIds(result.after.state),
    });
  } else {
    traceMedia("score-commit-cue", "score-commit-sound", { category, categoryScore, repeatBonus, upperBonus });
    playOptionalSound("score-commit-sound");
    if (upperBonus > 0) {
      window.setTimeout(() => playOptionalSound("upper-bonus-sound"), 260);
    }
    if (isFiveDice || repeatBonus > 0) {
      window.setTimeout(() => playOptionalSound("celebration-sound"), upperBonus > 0 ? 680 : 300);
    }
  }
}

const upperCategoryByFace = Object.freeze({ 1: "ones", 2: "twos", 3: "threes", 4: "fours", 5: "fives", 6: "sixes" });
const jokerLowerCategories = Object.freeze(["three-kind", "four-kind", "full-house", "small-straight", "large-straight", "chance"]);

function allowedScoreCategories(playerState = {}, dice = []) {
  const scorecard = playerState.scorecard || {};
  const open = Object.keys(categoryLabels).filter(category => scorecard[category] === null);
  const values = dice.map(Number);
  const isYahtzee = values.length === 5 && values.every(value => value === values[0]);
  if (!isYahtzee || scorecard.yahtzee === null) return new Set(open);
  const matchingUpper = upperCategoryByFace[values[0]];
  if (matchingUpper && scorecard[matchingUpper] === null) return new Set([matchingUpper]);
  const openLower = jokerLowerCategories.filter(category => scorecard[category] === null);
  if (openLower.length) return new Set(openLower);
  return new Set(Object.values(upperCategoryByFace).filter(category => scorecard[category] === null));
}

function fitBuiltInScorePlayerNames(headings) {
  for (const heading of headings) {
    if (heading.classList.contains("is-empty-seat")) continue;
    const fullName = String(heading.title || heading.textContent || "").trim();
    if (!fullName) continue;
    heading.title = fullName;
    heading.setAttribute("aria-label", fullName);
    heading.textContent = fullName;
    const fits = () => heading.scrollWidth <= heading.clientWidth + 2
      && heading.scrollHeight <= heading.clientHeight + 2;
    if (fits()) continue;

    const words = fullName.split(/\s+/).filter(Boolean);
    const candidates = [];
    if (words.length > 1) {
      candidates.push(`${Array.from(words[0])[0] || ""}. ${words.at(-1)}`);
      candidates.push(words.at(-1));
      candidates.push(words.map(word => Array.from(word)[0] || "").join(""));
    }
    let fitted = false;
    for (const candidate of [...new Set(candidates.filter(Boolean))]) {
      heading.textContent = candidate;
      if (fits()) {
        fitted = true;
        break;
      }
    }
    if (fitted) continue;

    const glyphs = Array.from(fullName);
    let low = 0;
    let high = glyphs.length;
    while (low < high) {
      const middle = Math.ceil((low + high) / 2);
      heading.textContent = `${glyphs.slice(0, middle).join("")}...`;
      if (fits()) low = middle;
      else high = middle - 1;
    }
    heading.textContent = low > 0 ? `${glyphs.slice(0, low).join("")}...` : glyphs[0];
  }
}

function ensureBuiltInScoreTargets(rows) {
  const targets = Array.from(rows).filter(row => row.matches?.("button.built-in-score-choice[data-category]"));
  for (const target of targets) {
    const rect = target.getBoundingClientRect();
    if (!Number.isFinite(rect.height) || rect.height <= 0 || rect.height >= 24.25) continue;
    const renderedScale = target.offsetHeight > 0 ? rect.height / target.offsetHeight : 1;
    const currentExpansion = Math.max(0, Number(target.dataset.pointerTargetExpansion || 0));
    const additionalExpansion = (24.25 - rect.height) / (2 * Math.max(.01, renderedScale));
    const expansion = Math.ceil(currentExpansion + additionalExpansion);
    const value = `${expansion}px`;
    target.dataset.pointerTargetExpansion = String(expansion);
    target.style.boxSizing = "content-box";
    target.style.borderTop = `${value} solid transparent`;
    target.style.borderBottom = `${value} solid transparent`;
    target.style.marginTop = `-${value}`;
    target.style.marginBottom = `-${value}`;
  }
  return targets.map(target => target.getBoundingClientRect());
}

function renderBuiltInScoreSheet(state, canAct) {
  if (terminalSessionError) return;

  const host = el("built-in-score-actions");
  const hint = el("built-in-score-hint");
  const rollSource = el("roll");
  const builtInRoll = el("built-in-roll");
  const players = orderedPlayers(state);
  const scoreSlots = players.slice(0, 4);
  while (scoreSlots.length < 4) scoreSlots.push(null);
  const playerState = state.players?.[String(currentUserId())] || {};
  const scorecard = playerState.scorecard || {};
  const allowed = allowedScoreCategories(playerState, state.dice);
  const hasRolled = Number(state.rollsThisTurn) > 0;

  // The below-board fallback is revealed asynchronously after layout. Its
  // initial hidden state must not suppress the drum's actual Roll control.
  builtInRoll.hidden = session?.presentation?.effectivePack === "classic";
  builtInRoll.disabled = rollSource.disabled;
  const rollsLeft = Math.max(0, 3 - Number(state.rollsThisTurn || 0));
  builtInRoll.textContent = "ROLL\nDICE";
  builtInRoll.dataset.rollsLeftLabel = state.completed
    ? "Game complete"
    : `${rollsLeft} ${rollsLeft === 1 ? "roll" : "rolls"} left`;
  builtInRoll.setAttribute(
    "aria-label",
    state.completed ? "Game complete" : `Roll dice, ${builtInRoll.dataset.rollsLeftLabel}`
  );
  if (builtInRoll.dataset.bound !== "true") {
    builtInRoll.addEventListener("click", () => el("roll").click());
    builtInRoll.dataset.bound = "true";
  }

  if (state.completed) {
    hint.textContent = !state.terminalReason && fiveDiceWinnerUserIds(state).length
      ? "Game complete. Your finished scorecard remains below."
      : "Game ended. Your scorecard remains below.";
  } else if (!canAct) {
    hint.textContent = "Your scorecard stays visible while you wait for your turn.";
  } else if (!hasRolled) {
    hint.textContent = "Roll the dice, then choose one unused row.";
  } else {
    hint.textContent = "Hover or focus a row for its points. Click once to record it.";
  }

  host.style.setProperty("--built-in-player-count", "4");
  const columnHeadings = document.createElement("div");
  const categoryHeading = document.createElement("span");
  columnHeadings.className = "built-in-score-columns";
  categoryHeading.className = "built-in-score-column-category";
  categoryHeading.textContent = "Category";
  columnHeadings.append(categoryHeading);
  for (const player of scoreSlots) {
    const playerHeading = document.createElement("span");
    playerHeading.className = "built-in-score-player-name";
    if (player) {
      playerHeading.textContent = player.displayName;
      playerHeading.title = player.displayName;
      if (player.userId === currentUserId()) playerHeading.dataset.viewer = "true";
    } else {
      playerHeading.classList.add("is-empty-seat");
      playerHeading.textContent = "\u00b7";
      playerHeading.setAttribute("aria-hidden", "true");
    }
    columnHeadings.append(playerHeading);
  }

  const categoryRows = Object.entries(categoryLabels).map(([category, label]) => {
    const recorded = scorecard[category];
    const isScored = recorded !== null && recorded !== undefined;
    const preview = state.scorePreviews?.[category];
    const isAvailable = Boolean(
      canAct && hasRolled && !isScored && allowed.has(category) && preview && !busy
    );
    const button = document.createElement("button");
    const categoryName = document.createElement("span");
    const previewScore = Number(preview?.score || 0);
    const repeatBonus = Number(preview?.repeatBonus || 0);
    const announcedScores = [];

    button.type = "button";
    button.className = "built-in-score-choice";
    button.dataset.category = category;
    button.dataset.state = isScored ? "scored" : isAvailable ? "available" : "waiting";
    if (builtInScoreMotion?.category === category) button.classList.add("is-score-committing");
    button.dataset.scoreTip = isAvailable
      ? `${label}: this roll scores ${previewScore} points. Click to record it.`
      : isScored
        ? `${label}: you already recorded ${Number(recorded || 0)} points.`
        : hasRolled && preview
          ? `${label}: this roll would score ${previewScore} points when the row is available.`
          : `${label}: roll the dice to calculate this category.`;
    button.title = button.dataset.scoreTip;
    button.disabled = !isAvailable;
    categoryName.className = "built-in-score-category";
    const builtInLabel = category === "yahtzee" ? "Five Dice" : label;
    categoryName.textContent = builtInLabel;
    categoryName.title = builtInLabel;
    button.append(categoryName);
    for (const player of scoreSlots) {
      if (!player) {
        const emptyCell = document.createElement("span");
        emptyCell.className = "built-in-score-player-value is-empty-seat";
        emptyCell.textContent = "\u00b7";
        emptyCell.setAttribute("aria-hidden", "true");
        button.append(emptyCell);
        continue;
      }
      const value = player.state.scorecard?.[category];
      const playerHasScored = value !== null && value !== undefined;
      const isViewer = player.userId === currentUserId();
      const showPreview = isViewer && !playerHasScored && hasRolled && preview;
      const valueCell = document.createElement("span");
      valueCell.className = "built-in-score-player-value";
      if (isViewer) valueCell.dataset.viewer = "true";
      if (showPreview) valueCell.dataset.preview = "true";
      valueCell.textContent = playerHasScored
        ? String(Number(value || 0))
        : showPreview ? String(previewScore) : "--";
      valueCell.title = playerHasScored
        ? `${player.displayName}: ${Number(value || 0)}`
        : showPreview ? `${player.displayName}: projected ${previewScore}` : `${player.displayName}: not scored`;
      announcedScores.push(valueCell.title);
      button.append(valueCell);
    }
    button.setAttribute(
      "aria-label",
      `${builtInLabel}. ${announcedScores.join(". ")}.${isAvailable
        ? ` Activate to record ${previewScore}${repeatBonus > 0 ? ` plus ${repeatBonus} repeat Yahtzee bonus` : ""} for ${players.find(player => player.userId === currentUserId())?.displayName || "you"}.`
        : ""}`
    );
    button.addEventListener("click", () => chooseScore(category));
    return button;
  });
  const summaryDefinitions = [
    ["Bonus", "upperBonus", "bonus"],
    ["Subtotal", "upperSubtotal", "subtotal"],
    ["Repeat Yahtzee bonuses", "yahtzeeBonus", "bonus"],
    ["Total", "total", "total"],
  ];
  const summaryRows = summaryDefinitions.map(([label, key, stateName]) => {
    const row = document.createElement("div");
    const categoryName = document.createElement("span");
    row.className = "built-in-score-choice built-in-score-summary";
    row.dataset.state = stateName;
    row.dataset.summaryKey = key;
    if (stateName === "bonus" && builtInScoreMotion?.upperBonus) row.classList.add("is-bonus-earned");
    categoryName.className = "built-in-score-category";
    categoryName.textContent = label;
    row.append(categoryName);
    for (const player of scoreSlots) {
      if (!player) {
        const emptyCell = document.createElement("span");
        emptyCell.className = "built-in-score-player-value is-empty-seat";
        emptyCell.textContent = "\u00b7";
        emptyCell.setAttribute("aria-hidden", "true");
        row.append(emptyCell);
        continue;
      }
      const valueCell = document.createElement("span");
      valueCell.className = "built-in-score-player-value";
      if (player.userId === currentUserId()) valueCell.dataset.viewer = "true";
      const displayedValue = key === "upperSubtotal"
        ? Number(player.state?.upperSubtotal || 0) + Number(player.state?.upperBonus || 0)
        : Number(player.state?.[key] || 0);
      valueCell.textContent = String(displayedValue);
      valueCell.title = `${player.displayName}: ${valueCell.textContent}`;
      row.append(valueCell);
    }
    row.setAttribute("aria-label", `${label}. ${players.map(player => `${player.displayName}: ${Number(player.state?.[key] || 0)}`).join(". ")}.`);
    return row;
  });
  const upperCategoryRows = categoryRows.slice(0, 6);
  const lowerCategoryRows = [
    "three-kind",
    "four-kind",
    "full-house",
    "small-straight",
    "large-straight",
    "yahtzee",
    "chance",
  ].map(category => categoryRows.find(row => row.dataset.category === category)).filter(Boolean);
  host.replaceChildren(
    columnHeadings,
    ...upperCategoryRows,
    summaryRows[0],
    summaryRows[1],
    ...lowerCategoryRows,
    summaryRows[2],
    summaryRows[3]
  );
  fitBuiltInScorePlayerNames(columnHeadings.querySelectorAll(".built-in-score-player-name"));
  ensureBuiltInScoreTargets(categoryRows);
}

function renderClassicScore(state, canAct) {
  if (terminalSessionError) return;

  renderBuiltInScoreSheet(state, canAct);
  const players = orderedPlayers(state);
  const scoreHost = el("classic-score");
  const actionHost = el("classic-score-actions");
  const labelHost = el("classic-seat-labels");
  scoreHost.replaceChildren();
  actionHost.replaceChildren();
  labelHost.replaceChildren();
  renderClassicAvatars(players);

  classicGeometry.scoreRows.forEach((rowBox, row) => {
    const art = document.createElement("span");
    art.className = "classic-score-row-art";
    art.dataset.mediaSlot = `score-row-${500 + row}`;
    art.style.setProperty("--five-dice-score-row-art", `url("${mediaUrl(art.dataset.mediaSlot)}")`);
    setClassicSourceBox(art, {
      x: classicGeometry.scoreAction.x,
      y: rowBox.y,
      width: classicGeometry.scoreAction.labelWidth,
      height: classicGeometry.scoreAction.height,
    });
    scoreHost.append(art);
  });

  for (let column = 0; column < 4; column += 1) {
    const player = players[column];
    const label = document.createElement("span");
    label.className = "sr-only";
    label.textContent = player
      ? `Column ${column + 1}: ${player.displayName}`
      : `Column ${column + 1}: unused`;
    labelHost.append(label);
    if (!player) continue;

    const currentTurn = Number(state.turnOrder?.[state.turnIndex] || session.turnUserId || 0) === player.userId;
    const lane = document.createElement("span");
    lane.className = `classic-player-lane${currentTurn ? " is-current" : ""}${player.onHold ? " is-on-hold" : ""}`;
    lane.setAttribute("role", "status");
    lane.setAttribute("aria-label", player.onHold
      ? `${player.displayName} is on hold, watching this game, and joins next round`
      : currentTurn
        ? `${player.displayName} is the current player in column ${column + 1}`
        : `${player.displayName} occupies score column ${column + 1}`);
    const laneSlot = currentTurn
      ? "current-player-lane"
      : player.onHold ? `on-hold-lane-${column + 1}` : `player-lane-${column + 1}`;
    lane.dataset.mediaSlot = laneSlot;
    lane.style.setProperty("--five-dice-player-lane", `url("${mediaUrl(laneSlot)}")`);
    setClassicSourceBox(lane, classicGeometry.playerLanes[column]);
    scoreHost.append(lane);
    if (player.onHold) continue;

    for (const [category, categoryLabel] of Object.entries(categoryLabels)) {
      appendClassicCell(scoreHost, player, column, classicRows[category], categoryLabel, player.state.scorecard?.[category]);
    }
    appendClassicCell(
      scoreHost,
      player,
      column,
      6,
      "Upper subtotal and bonus",
      Number(player.state.upperSubtotal || 0) + Number(player.state.upperBonus || 0),
      "classic-total-cell"
    );
    appendClassicCell(scoreHost, player, column, 14, "Lower total", lowerTotal(player.state), "classic-total-cell");
    appendClassicCell(scoreHost, player, column, 15, "Grand total", Number(player.state.total || 0), "classic-total-cell");
    if (Number.isFinite(Number(player.state.personalBest?.score))) {
      appendClassicCell(
        scoreHost,
        player,
        column,
        16,
        `${session.mode === "recorded" ? "Recorded" : "Practice"} personal best`,
        Number(player.state.personalBest.score),
        "classic-total-cell classic-best-cell"
      );
    }

  }

  const actingColumn = players.findIndex(player => player.userId === currentUserId());
  if (actingColumn < 0) return;
  const actingPlayer = players[actingColumn];
  const allowed = allowedScoreCategories(actingPlayer.state, state.dice);
  for (const [category, categoryLabel] of Object.entries(categoryLabels)) {
    const preview = state.scorePreviews?.[category];
    if (!canAct || Number(state.rollsThisTurn) < 1 || !allowed.has(category) || !preview || busy) continue;
    const row = classicRows[category];
    const rowBox = classicGeometry.scoreRows[row];
    const previewCell = appendClassicCell(
      scoreHost,
      actingPlayer,
      actingColumn,
      row,
      `${categoryLabel} preview`,
      Number(preview.score || 0),
      "classic-score-preview"
    );
    previewCell.dataset.category = category;
    previewCell.dataset.repeatBonus = String(Number(preview.repeatBonus || 0));
    previewCell.hidden = true;
    const choose = document.createElement("button");
    choose.type = "button";
    choose.className = "classic-score-choice";
    choose.dataset.category = category;
    choose.dataset.previewScore = String(Number(preview.score || 0));
    choose.dataset.repeatBonus = String(Number(preview.repeatBonus || 0));
    choose.setAttribute(
      "aria-label",
      `${categoryLabel}: preview ${Number(preview.score || 0)}${Number(preview.repeatBonus || 0) > 0 ? ` plus ${Number(preview.repeatBonus)} repeat Yahtzee bonus` : ""}. Activate to record this score for ${actingPlayer.displayName}.`
    );
    classicPosition(choose, actingColumn, row);
    choose.addEventListener("pointerenter", () => setClassicScorePreview(category));
    choose.addEventListener("pointerleave", () => {
      if (!choose.matches(":focus")) setClassicScorePreview(null);
    });
    choose.addEventListener("focus", () => setClassicScorePreview(category));
    choose.addEventListener("blur", () => setClassicScorePreview(null));
    choose.addEventListener("click", () => chooseScore(category));
    bindExplicitKeyboardClick(choose);
    actionHost.append(choose);
  }
}

function renderScores(state, canAct) {
  if (terminalSessionError) return;

  const activePlayerIds = new Set((state.turnOrder || []).map(Number));
  const players = session.members
    .filter(member => ["master", "player"].includes(member.role))
    .map(member => ({ ...member, onHold: !activePlayerIds.has(Number(member.userId)) }));
  el("score-head").innerHTML = `<tr><th scope="col">Category</th>${players.map(player => `<th scope="col" class="${player.onHold ? "is-on-hold" : ""}">${escapeHtml(player.displayName)}${player.onHold ? '<span class="score-player-state">On hold</span>' : ""}</th>`).join("")}</tr>`;
  el("score-body").replaceChildren(...Object.entries(categoryLabels).map(([category, label]) => {
    const row = document.createElement("tr");
    const labelCell = document.createElement("th");
    labelCell.scope = "row";
    const choose = document.createElement("button");
    choose.type = "button";
    choose.className = "category";
    choose.dataset.category = category;
    choose.textContent = label;
    const mine = state.players?.[String(currentUserId())]?.scorecard?.[category];
    const allowed = allowedScoreCategories(state.players?.[String(currentUserId())] || {}, state.dice);
    const preview = state.scorePreviews?.[category];
    choose.disabled = !canAct || Number(state.rollsThisTurn) < 1 || mine !== null || !allowed.has(category) || !preview || busy;
    if (preview) {
      choose.dataset.previewScore = String(Number(preview.score || 0));
      choose.setAttribute("aria-label", `${label}: preview ${Number(preview.score || 0)}. Activate to record this score.`);
    }
    choose.addEventListener("click", () => chooseScore(category));
    labelCell.append(choose);
    row.append(labelCell);
    let viewerCell = null;
    players.forEach(player => {
      const cell = document.createElement("td");
      const value = state.players?.[String(player.userId)]?.scorecard?.[category];
      cell.textContent = value === null || value === undefined ? "" : String(value);
      cell.classList.toggle("is-on-hold", player.onHold);
      if (player.onHold) cell.setAttribute("aria-label", `${player.displayName} is on hold and joins next round`);
      if (Number(player.userId) === currentUserId()) viewerCell = cell;
      row.append(cell);
    });
    if (preview && viewerCell) {
      const showPreview = () => {
        viewerCell.textContent = String(Number(preview.score || 0));
        viewerCell.classList.add("score-preview");
        viewerCell.setAttribute("aria-label", `${label} preview: ${Number(preview.score || 0)}`);
      };
      const clearPreview = () => {
        viewerCell.textContent = "—";
        viewerCell.classList.remove("score-preview");
        viewerCell.removeAttribute("aria-label");
      };
      choose.addEventListener("pointerenter", showPreview);
      choose.addEventListener("pointerleave", () => {
        if (!choose.matches(":focus")) clearPreview();
      });
      choose.addEventListener("focus", showPreview);
      choose.addEventListener("blur", clearPreview);
    }
    return row;
  }));
  const summaryRows = [
    ["Upper subtotal", "upperSubtotal"],
    ["Upper bonus", "upperBonus"],
    ["Repeat Yahtzee bonuses", "yahtzeeBonus"],
    ["Total", "total"],
    [`${session.mode === "recorded" ? "Recorded" : "Practice"} personal best`, "personalBest"]
  ].map(([label, key]) => {
    const row = document.createElement("tr");
    row.innerHTML = `<th scope="row">${label}</th>${players.map(player => {
      const raw = key === "personalBest"
        ? state.players?.[String(player.userId)]?.personalBest?.score
        : key === "upperSubtotal"
          ? Number(state.players?.[String(player.userId)]?.upperSubtotal || 0)
            + Number(state.players?.[String(player.userId)]?.upperBonus || 0)
          : state.players?.[String(player.userId)]?.[key];
      return `<td class="${player.onHold ? "is-on-hold" : ""}"${player.onHold ? ` aria-label="${escapeHtml(player.displayName)} is on hold and joins next round"` : ""}>${raw === null || raw === undefined ? "—" : Number(raw)}</td>`;
    }).join("")}`;
    return row;
  });
  el("score-foot").replaceChildren(...summaryRows);
  renderClassicScore(state, canAct);
}

function renderAppearance(displayName) {
  if (terminalSessionError) return;

  const presentation = session?.presentation || {};
  const effective = presentation.effectivePack === "classic" ? "classic" : "built-in";
  document.body.dataset.appearance = effective;
  el("play-surface").style.removeProperty("--five-dice-board");
  if (effective === "classic") {
    el("play-surface").style.setProperty("--five-dice-board", `url("${mediaUrl("classic-board")}")`);
  }
}

function renderSharedGameSettings(editable, locked) {
  if (terminalSessionError) return;

  const host = el("shared-game-settings");
  const controls = Array.isArray(session?.settingsControls?.controls) ? session.settingsControls.controls : [];
  host.replaceChildren();
  host.hidden = controls.length === 0;
  if (!controls.length) return;
  if (settingsDraftSha256 !== session.settingsSha256 || !settingsDraft) {
    settingsDraft = Object.fromEntries(controls.map(control => [String(control.key || ""), control.value]));
    settingsDraftSha256 = session.settingsSha256;
  }
  const heading = document.createElement("strong");
  heading.className = "shared-settings-heading";
  heading.textContent = "Ranked inactivity protection";
  host.append(heading);
  for (const control of controls) {
    const key = String(control.key || "");
    const wrapper = document.createElement("div");
    wrapper.className = "shared-game-setting";
    const labelRow = document.createElement("span");
    labelRow.className = "setting-label-with-info";
    const label = document.createElement("span");
    label.className = "shared-game-setting-label";
    label.id = `shared-setting-label-${key}`;
    label.textContent = String(control.label || key);
    const info = document.createElement("button");
    info.type = "button";
    info.className = "inline-info shared-setting-info";
    info.textContent = "i";
    info.setAttribute("aria-label", `About ${String(control.label || key)}`);
    info.setAttribute("aria-expanded", "false");
    const description = document.createElement("div");
    description.className = "compact-info-popover shared-game-setting-help";
    description.id = `shared-setting-help-${key}`;
    description.setAttribute("role", "note");
    description.textContent = String(control.description || "");
    description.hidden = true;
    info.setAttribute("aria-controls", description.id);
    info.addEventListener("click", () => {
      const opening = description.hidden;
      for (const openHelp of host.querySelectorAll(".shared-game-setting-help:not([hidden])")) {
        openHelp.hidden = true;
        host.querySelector(`[aria-controls="${openHelp.id}"]`)?.setAttribute("aria-expanded", "false");
      }
      description.hidden = !opening;
      info.setAttribute("aria-expanded", opening ? "true" : "false");
    });
    labelRow.append(label, info);
    let field;
    if (control.type === "stepper") {
      field = document.createElement("input");
      field.type = "number";
      field.min = String(Number(control.minimum));
      field.max = String(Number(control.maximum));
      field.step = String(Number(control.step || 1));
      field.value = String(Number(settingsDraft[key] ?? control.value));
      field.addEventListener("change", () => {
        const minimum = Number(control.minimum);
        const maximum = Number(control.maximum);
        const value = Math.max(minimum, Math.min(maximum, Number(field.value)));
        field.value = String(value);
        settingsDraft[key] = value;
      });
    } else {
      field = document.createElement("select");
      for (const option of control.options || []) {
        const item = document.createElement("option");
        item.value = String(option.value);
        item.textContent = String(option.label || option.value);
        field.append(item);
      }
      field.value = String(settingsDraft[key] ?? control.value ?? "");
      field.addEventListener("change", () => { settingsDraft[key] = field.value; });
    }
    field.dataset.sharedSettingKey = key;
    field.setAttribute("aria-labelledby", label.id);
    field.setAttribute("aria-describedby", description.id);
    field.disabled = locked || !editable || busy;
    wrapper.append(labelRow, field, description);
    host.append(wrapper);
  }
}

// Use the viewer-safe projected receipt status, not a compatibility boolean, for display.
function fiveDiceAcceptanceLabel(member, locked) {
  const labels = {
    "accepted-current-options": "Accepted",
    "acceptance-needed": "Acceptance needed",
    "not-required-in-practice": "Not required in Practice",
    "accepted-at-start": "Accepted at start",
    "not-recorded-in-practice": "Not recorded (Practice)",
    "not-in-starting-roster": "Not in starting roster",
    "history-unavailable": "Acceptance history unavailable"
  };
  if (Object.hasOwn(labels, member.acceptanceStatus)) return labels[member.acceptanceStatus];
  return locked ? labels["history-unavailable"] : (member.accepted ? labels["accepted-current-options"] : labels["acceptance-needed"]);
}

function renderGameOptions() {
  if (terminalSessionError) return;

  const presentation = session?.presentation || {};
  const host = el("game-options");
  const select = el("appearance-select");
  const appearanceAvailable = presentation.selectionOwner === "viewer" && Array.isArray(presentation.packs);
  const sharedReviewRequired = session?.status === "lobby"
    && (session?.members || []).filter(member => ["master", "player"].includes(member.role)).length > 1;
  if (!gameOptionsInitialStateResolved && sharedReviewRequired) {
    gameOptionsVisible = true;
    gameOptionsInitialStateResolved = true;
  } else if (!gameOptionsInitialStateResolved && session?.status !== "lobby") {
    gameOptionsVisible = false;
    gameOptionsInitialStateResolved = true;
  }
  const toggle = el("game-options-toggle");
  toggle.textContent = gameOptionsVisible ? "Hide Game Options" : "Show Game Options";
  toggle.setAttribute("aria-expanded", gameOptionsVisible ? "true" : "false");
  host.hidden = !gameOptionsVisible;
  const appearanceOption = select.closest("label");
  appearanceOption.hidden = !appearanceAvailable;
  select.replaceChildren(...(appearanceAvailable ? presentation.packs : []).map(pack => {
    const item = document.createElement("option");
    item.value = String(pack.id || "");
    item.textContent = String(pack.label || pack.id || "Appearance");
    item.disabled = item.value === "classic" && presentation.classicAvailable === false;
    if (item.disabled) item.textContent += " (artwork unavailable)";
    item.selected = item.value === String(presentation.effectivePack || presentation.requestedPack || "");
    return item;
  }));
  if (appearanceAvailable) select.value = String(presentation.effectivePack || presentation.requestedPack || "");
  let appearanceNotice = el("appearance-fallback-notice");
  if (!appearanceNotice) {
    appearanceNotice = document.createElement("p");
    appearanceNotice.id = "appearance-fallback-notice";
    appearanceNotice.setAttribute("role", "status");
    appearanceOption.after(appearanceNotice);
  }
  appearanceNotice.hidden = !appearanceAvailable || presentation.requestedPack !== "classic" || presentation.effectivePack === "classic";
  appearanceNotice.textContent = appearanceNotice.hidden ? "" : "Classic was requested, but its artwork and sound pack is unavailable. Built-in is displayed. An installation owner can install the Classic pack under Admin > Settings > Rooms & Games.";

  const locked = session.status !== "lobby";
  const editable = !locked && session.viewerRole === "master";
  el("mode-option").hidden = locked;
  el("mode-select").value = session.mode === "recorded" ? "recorded" : "practice";
  el("mode-select").disabled = !editable || busy;
  el("mode-summary").hidden = !locked;
  el("mode-summary").querySelector("strong").textContent = session.mode === "recorded" ? "Ranked or Recorded Play" : "Practice Mode";
  el("mode-summary").dataset.mode = String(session.mode || "practice");
  el("save-game-options").hidden = !editable;
  el("save-game-options").disabled = busy;
  renderSharedGameSettings(editable, locked);

  const viewer = (session.members || []).find(member => Number(member.userId) === currentUserId());
  el("settings-acceptance").replaceChildren(...(session.members || [])
    .filter(member => ["master", "player"].includes(member.role))
    .map(member => {
      const item = document.createElement("span");
      item.className = locked
        ? (member.acceptanceStatus === "accepted-at-start" ? "is-accepted" : "")
        : (member.accepted ? "is-accepted" : "is-pending");
      item.textContent = `${member.displayName}: ${fiveDiceAcceptanceLabel(member, locked)}`;
      return item;
    }));
  const canAccept = !locked && ["master", "player"].includes(session.viewerRole);
  el("accept-game-options").hidden = !canAccept;
  el("accept-game-options").disabled = busy || Boolean(viewer?.accepted);
  el("accept-game-options").textContent = viewer?.accepted ? "Current Game Options accepted" : "Accept current Game Options";
  const legacyEffectsOn = options?.effectsEnabled !== false;
  const soundOn = options?.categories?.sfxEnabled ?? legacyEffectsOn;
  const visualOn = options?.categories?.gfxEnabled !== false;
  el("game-options-sound-fx-toggle").textContent = soundOn ? "On" : "Off";
  el("game-options-sound-fx-toggle").setAttribute("aria-pressed", soundOn ? "true" : "false");
  el("game-options-visual-fx-toggle").textContent = visualOn ? "On" : "Off";
  el("game-options-visual-fx-toggle").setAttribute("aria-pressed", visualOn ? "true" : "false");
  el("separate-controls-toggle").textContent = separateControlsEnabled() ? "On" : "Off";
  el("separate-controls-toggle").setAttribute("aria-pressed", separateControlsEnabled() ? "true" : "false");
  const heightFitEnabled = viewerHeightFitEnabled("five-dice");
  el("height-fit-option").hidden = presentation.effectivePack === "classic";
  el("height-fit-toggle").textContent = heightFitEnabled ? "On" : "Off";
  el("height-fit-toggle").setAttribute("aria-pressed", String(heightFitEnabled));
  const boardScaleOption = el("board-size-option");
  const boardScaleIndex = fiveDiceBoardScaleIndex();
  boardScaleOption.hidden = presentation.effectivePack !== "classic";
  el("board-size-smaller").disabled = boardScaleIndex === 0;
  el("board-size-larger").disabled = boardScaleIndex === fiveDiceBoardScaleSteps.length - 1;
  el("board-size-value").textContent = `${Math.round(fiveDiceBoardScale() * 100)}%`;
}

function setToggleButton(node, label, enabled) {
  node.setAttribute("aria-pressed", enabled ? "true" : "false");
  node.setAttribute("aria-label", enabled ? `Turn ${label} off` : `Turn ${label} on`);
  node.textContent = `${label} ${enabled ? "On" : "Off"}`;
  node.dataset.stateLabel = enabled ? "On" : "Off";
}

function setClassicControlState(node, geometry, restSlot, hoverSlot, pressedSlot = hoverSlot) {
  setClassicSourceBox(node, geometry);
  node.style.setProperty("--five-dice-control-rest", `url("${mediaUrl(restSlot)}")`);
  node.style.setProperty("--five-dice-control-hover", `url("${mediaUrl(hoverSlot)}")`);
  node.style.setProperty("--five-dice-control-pressed", `url("${mediaUrl(pressedSlot)}")`);
  node.dataset.restSlot = restSlot;
  node.dataset.hoverSlot = hoverSlot;
  node.dataset.pressedSlot = pressedSlot;
  if (node.dataset.probedSlot !== restSlot) {
    node.dataset.probedSlot = restSlot;
    node.dataset.hotspotReady = "pending";
    const probe = new Image();
    probe.decoding = "async";
    probe.addEventListener("load", () => {
      if (node.dataset.probedSlot !== restSlot) return;
      node.dataset.hotspotReady = "true";
      traceMedia("control-art-loaded", restSlot, { control: node.id, width: probe.naturalWidth, height: probe.naturalHeight });
      syncSeparateControlVisibility();
    }, { once: true });
    probe.addEventListener("error", () => {
      if (node.dataset.probedSlot !== restSlot) return;
      node.dataset.hotspotReady = "false";
      traceMedia("control-art-unavailable", restSlot, { control: node.id });
      syncSeparateControlVisibility();
    }, { once: true });
    probe.src = mediaUrl(restSlot);
  }
}

function separateControlsEnabled() {
  return options?.categories?.separateControlButtons === true;
}

function canCurrentViewerRoll() {
  const state = session?.state || {};
  const turnUserId = Number(session?.turnUserId || state.turnOrder?.[state.turnIndex] || 0);
  return gameLifecycleAvailable()
    && currentUserId() === turnUserId
    && Number(state.rollsThisTurn || 0) < 3
    && !busy;
}

function canCurrentViewerRequestNewGame() {
  return ["master", "player"].includes(session?.viewerRole)
    && ["completed", "forfeited", "abandoned"].includes(String(session?.status || ""))
    && !busy;
}

function renderMediaControls(canRoll = canCurrentViewerRoll()) {
  if (terminalSessionError) return;

  const legacyEffectsOn = options?.effectsEnabled !== false;
  const soundOn = options?.categories?.sfxEnabled ?? legacyEffectsOn;
  const musicOn = options?.musicEnabled === true;
  const gfxOn = options?.categories?.gfxEnabled !== false;
  el("game-options-sound-fx-toggle").textContent = soundOn ? "On" : "Off";
  el("game-options-sound-fx-toggle").setAttribute("aria-pressed", soundOn ? "true" : "false");
  el("game-options-visual-fx-toggle").textContent = gfxOn ? "On" : "Off";
  el("game-options-visual-fx-toggle").setAttribute("aria-pressed", gfxOn ? "true" : "false");
  setToggleButton(el("sound-toggle"), "SFX", soundOn);
  setToggleButton(el("music-toggle"), "Music", musicOn);
  setToggleButton(el("gfx-toggle"), "GFX", gfxOn);
  setToggleButton(el("sound-hotspot"), "SFX", soundOn);
  setToggleButton(el("music-hotspot"), "Music", musicOn);
  setToggleButton(el("gfx-hotspot"), "GFX", gfxOn);
  el("nroll-hotspot").setAttribute("aria-label", "Roll dice");
  el("nroll-hotspot").disabled = !canRoll || busy;
  const canRequestNewGame = canCurrentViewerRequestNewGame();
  el("start-new-game-hotspot").disabled = !canRequestNewGame;
  el("start-new-game-hotspot").setAttribute("aria-label", newGameVoteStatus?.status === "waiting"
    ? `New game acceptance recorded, ${newGameVoteStatus.consented} of ${newGameVoteStatus.required}`
    : "Request a new game");
  const preferenceOn = separateControlsEnabled();
  el("separate-controls-toggle").setAttribute("aria-pressed", preferenceOn ? "true" : "false");
  el("separate-controls-toggle").textContent = preferenceOn ? "On" : "Off";
  const classic = session?.presentation?.effectivePack === "classic";
  if (classic) {
    setClassicControlState(
      el("sound-hotspot"), classicGeometry.controls.sound,
      soundOn ? "sound-on" : "sound-off",
      soundOn ? "sound-on-hover" : "sound-off-hover"
    );
    setClassicControlState(
      el("music-hotspot"), classicGeometry.controls.music,
      musicOn ? "music-on" : "music-off",
      musicOn ? "music-on-hover" : "music-off-hover"
    );
    setClassicControlState(
      el("gfx-hotspot"), classicGeometry.controls.gfx,
      gfxOn ? "gfx-on" : "gfx-off",
      gfxOn ? "gfx-on-hover" : "gfx-off-hover"
    );
    setClassicControlState(
      el("nroll-hotspot"), classicGeometry.controls.nRoll,
      canRoll && !busy ? "roll-control" : "roll-control-disabled",
      canRoll && !busy ? "roll-control-hover" : "roll-control-disabled",
      canRoll && !busy ? "roll-control-pressed" : "roll-control-disabled"
    );
    setClassicControlState(
      el("start-new-game-hotspot"), classicGeometry.controls.startNewGame,
      "start-new-game", "start-new-game", "start-new-game"
    );
  } else {
    for (const node of [el("sound-hotspot"), el("music-hotspot"), el("gfx-hotspot"), el("nroll-hotspot"), el("start-new-game-hotspot")]) {
      clearClassicSourceBox(node);
      for (const property of ["--five-dice-control-rest", "--five-dice-control-hover", "--five-dice-control-pressed"]) {
        node.style.removeProperty(property);
      }
    }
  }
  renderDrumMotion();
  renderMicrophoneMotion();
  requestAnimationFrame(syncSeparateControlVisibility);
}

async function requestNewGame() {
  if (!canCurrentViewerRequestNewGame()) return;
  busy = true;
  render();
  try {
    const result = await post("rematch");
    newGameVoteStatus = result;
    if (result.status === "started" && result.session) {
      activeGameSessionId = String(result.session.publicId || activeGameSessionId);
      const nextUrl = new URL(location.href);
      nextUrl.searchParams.set("game_session_id", activeGameSessionId);
      history.replaceState(null, "", nextUrl);
      replaceSession({
        ...result.session,
        status: "active",
        startedAt: result.session.startedAt || new Date().toISOString()
      });
    }
    el("game-status").textContent = result.message || "New game acceptance recorded.";
    traceMedia("new-game-lifecycle", "start-new-game", {
      status: String(result.status || "unknown"),
      consented: Number(result.consented || 0),
      required: Number(result.required || 0),
      idempotentReplay: result.idempotentReplay === true,
    });
  } catch (error) {
    el("game-status").textContent = error.message;
  } finally {
    busy = false;
    render();
  }
}

function syncSeparateControlVisibility() {
  const preferenceOn = separateControlsEnabled();
  const classic = session?.presentation?.effectivePack === "classic";
  const pairs = [
    [el("roll"), el("nroll-hotspot")],
    [el("sound-toggle"), el("sound-hotspot")],
    [el("music-toggle"), el("music-hotspot")],
    [el("gfx-toggle"), el("gfx-hotspot")],
  ];
  for (const [modern, hotspot] of pairs) {
    const sourceArtAvailable = ["pending", "true"].includes(hotspot.dataset.hotspotReady);
    const box = hotspot.getBoundingClientRect();
    const minimumHotspotSize = window.matchMedia?.("(pointer: coarse)")?.matches ? 44 : 24;
    const safeHotspot = classic && sourceArtAvailable
      && box.width >= minimumHotspotSize && box.height >= minimumHotspotSize;
    const inactiveClassicAction = modern.id === "roll" && classic && busy;
    const isRollFallback = modern.id === "roll";
    const isMusicFallback = modern.id === "music-toggle";
    modern.hidden = isRollFallback
      ? (safeHotspot || inactiveClassicAction)
      : isMusicFallback ? safeHotspot : (!preferenceOn && safeHotspot);
    modern.dataset.classicHotspotSafe = safeHotspot ? "true" : "false";
  }
  const modernStateButtons = [el("sound-toggle"), el("music-toggle"), el("gfx-toggle")];
  el("separate-media-controls").hidden = modernStateButtons.every(button => button.hidden);
  document.body.dataset.separateControlButtons = preferenceOn ? "on" : "off";
}

function syncScoreScrollCue() {
  if (terminalSessionError) return;

  const scroll = el("score-table-scroll");
  const cue = el("score-scroll-cue");
  const controls = el("score-scroll-controls");
  const verticallyClipped = scroll.scrollHeight > scroll.clientHeight + 1;
  const horizontallyClipped = scroll.scrollWidth > scroll.clientWidth + 1;
  const clipped = !el("score-records").hidden && (verticallyClipped || horizontallyClipped);
  cue.hidden = !clipped;
  controls.hidden = !clipped;
  cue.textContent = horizontallyClipped && verticallyClipped
    ? "Scroll across and down for remaining scores"
    : horizontallyClipped ? "Scroll across for remaining scores" : "Scroll for remaining scores";
  scroll.dataset.clipped = clipped ? "true" : "false";
  scroll.dataset.clippedHorizontally = horizontallyClipped ? "true" : "false";
  scroll.dataset.clippedVertically = verticallyClipped ? "true" : "false";
  const atLeft = scroll.scrollLeft <= 1;
  const atRight = scroll.scrollLeft >= scroll.scrollWidth - scroll.clientWidth - 1;
  const atTop = scroll.scrollTop <= 1;
  const atBottom = scroll.scrollTop >= scroll.scrollHeight - scroll.clientHeight - 1;
  el("score-scroll-left").hidden = !horizontallyClipped;
  el("score-scroll-right").hidden = !horizontallyClipped;
  el("score-scroll-left").disabled = !horizontallyClipped || atLeft;
  el("score-scroll-right").disabled = !horizontallyClipped || atRight;
  el("score-scroll-up").hidden = !verticallyClipped;
  el("score-scroll-down").hidden = !verticallyClipped;
  el("score-scroll-up").disabled = !verticallyClipped || atTop;
  el("score-scroll-down").disabled = !verticallyClipped || atBottom;
}

function scrollScores(axis, direction) {
  const scroll = el("score-table-scroll");
  const amount = axis === "x" ? Math.max(160, scroll.clientWidth * .72) : Math.max(160, scroll.clientHeight * .72);
  scroll.scrollBy({ [axis === "x" ? "left" : "top"]: amount * direction, behavior: "smooth" });
  window.setTimeout(syncScoreScrollCue, 240);
}

function recordSummary(entry = {}) {
  return `${Number(entry.win ?? entry.wins ?? 0)} wins · ${Number(entry.loss ?? entry.losses ?? 0)} losses · ${Number(entry.draw ?? entry.draws ?? 0)} draws`;
}

function renderRecords() {
  if (terminalSessionError) return;

  const opponentSelect = el("record-opponent");
  const previous = opponentSelect.value;
  const opponents = Array.isArray(records?.opponents) ? records.opponents : [];
  opponentSelect.replaceChildren(...opponents.map(opponent => {
    const option = document.createElement("option");
    option.value = String(opponent.userId || opponent.opponentUserId || "");
    option.textContent = opponent.displayName || "Member";
    return option;
  }));
  if (opponents.length === 0) {
    const option = document.createElement("option");
    option.value = "";
    option.textContent = "No Recorded opponents yet";
    opponentSelect.append(option);
    opponentSelect.disabled = true;
  } else {
    opponentSelect.disabled = false;
    if ([...opponentSelect.options].some(option => option.value === previous)) opponentSelect.value = previous;
  }
  const selected = opponents.find(opponent => String(opponent.userId || opponent.opponentUserId || "") === opponentSelect.value) || opponents[0] || {};
  el("opponent-record").textContent = recordSummary(selected);
  el("lifetime-record").textContent = recordSummary(records?.lifetime || {});
}

function renderScoreRecordsVisibility(scheduleCue = true) {
  if (terminalSessionError) return;

  const readableScoring = el("score-records").dataset.readableScoring === "true";
  const visible = scoreRecordsVisible;
  el("score-records").hidden = !visible;
  el("score-readability-note").hidden = !readableScoring || !visible;
  document.querySelector(".presentation-grid")?.setAttribute("data-score-records-visible", visible ? "true" : "false");
  const toggle = el("score-records-toggle");
  toggle.setAttribute("aria-expanded", visible ? "true" : "false");
  toggle.disabled = false;
  toggle.textContent = visible ? "Hide Score & Records" : "Show Score & Records";
  if (scheduleCue) requestAnimationFrame(syncScoreScrollCue);
  else syncScoreScrollCue();
}

function renderRules() {
  if (terminalSessionError) return;

  const panel = el("rules-panel");
  const rules = session?.rules || {};
  panel.replaceChildren();
  const heading = document.createElement("h2");
  heading.id = "rules-panel-heading";
  const rulesLabel = String(rules.label || session?.displayName || "Five Dice").trim() || "Five Dice";
  heading.textContent = /\brules$/i.test(rulesLabel) ? rulesLabel : `${rulesLabel} Rules`;
  panel.append(heading);
  const description = document.createElement("p");
  description.textContent = String(rules.description || "The active game rules are unavailable.");
  panel.append(description);
  const sections = Array.isArray(rules.sections) ? rules.sections : [];
  if (sections.length) {
    const list = document.createElement("dl");
    for (const section of sections) {
      const term = document.createElement("dt");
      const detail = document.createElement("dd");
      term.textContent = String(section.label || "Rule");
      detail.textContent = String(section.text || "");
      list.append(term, detail);
    }
    panel.append(list);
  }
}

function renderAccessibility() {
  if (terminalSessionError) return;

  const panel = el("accessibility-panel");
  panel.replaceChildren();
  const heading = document.createElement("h2");
  heading.id = "accessibility-panel-heading";
  heading.textContent = "Accessibility";
  const intro = document.createElement("p");
  intro.textContent = "Keyboard commands act on the control that currently has focus; there are no hidden letter-key shortcuts.";
  const guidance = [
    ["Move focus", "Press Tab to move forward and Shift+Tab to move backward through available controls."],
    ["Activate", "Press Enter or Space to use the focused button, die, scoring category, or game action."],
    ["Hold or release dice", "Focus a die and press Enter or Space. Its accessible pressed state announces whether it is kept."],
    ["Choose a score", "Focus an available scoring category and press Enter or Space."],
    ["Board size", "Open Game Options and use the Board size minus and plus buttons. The setting is personal to this browser and never changes another player's view."],
    ["Close help", "Press Escape to close this Accessibility panel or the separate-controls information popover and return focus to its button."],
    ["Status", "Turn, roll, timer, game-state, and error changes are announced through live status regions."],
  ];
  const list = document.createElement("dl");
  for (const [label, detail] of guidance) {
    const term = document.createElement("dt");
    const description = document.createElement("dd");
    term.textContent = label;
    description.textContent = detail;
    list.append(term, description);
  }
  panel.append(heading, intro, list);
}

function formatLifecycleTime(seconds) {
  if (seconds === null || !Number.isFinite(Number(seconds))) return "—";
  const whole = Math.max(0, Math.ceil(Number(seconds)));
  return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, "0")}`;
}

function lifecycleProjectedRemaining(value) {
  const initial = Number(value);
  if (!Number.isFinite(initial)) return null;
  const elapsed = session?.state?._framework?.serviceInterruption?.active === true
    ? 0
    : Math.max(0, Date.now() - sessionProjectedAtMs) / 1000;
  return Math.max(0, initial - elapsed);
}

function fiveDicePlayerTimerText(userId) {
  const id = String(Number(userId || 0));
  const framework = session?.state?._framework || {};
  const pause = framework.pause || {};
  const inactivity = framework.inactivity || {};
  const active = inactivity.enabled === true && Number(inactivity.ownerUserId || 0) === Number(id);
  const base = inactivity.enabled !== true
    ? ""
    : active
      ? formatLifecycleTime(lifecycleProjectedRemaining(inactivity.remainingProjectedSeconds))
      : "Waiting";
  const withBase = label => base ? `${label} · ${base}` : label;
  if (session?.state?.completed) return base ? withBase("Final") : "";
  if (framework.serviceInterruption?.active) return withBase("Service interruption");
  if (framework.players?.[id]?.disconnected === true) {
    return withBase(`Reconnect ${formatLifecycleTime(lifecycleProjectedRemaining(framework.players[id].disconnectRemainingSeconds))}`);
  }
  if (pause.mode === "resuming") return withBase(`Resumes in ${formatLifecycleTime(lifecycleProjectedRemaining(pause.resumeRemainingSeconds))}`);
  if (pause.mode === "paused") return withBase("Paused");
  return base;
}

function fiveDiceBuiltInPlayerAvatar(player) {
  const image = document.createElement("img");
  image.className = "built-in-board-player-avatar";
  image.alt = "";
  image.decoding = "async";
  const fallbackSvg = "data:image/svg+xml," + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#111b24"/><circle cx="32" cy="23" r="12" fill="#91a5ad"/><path d="M11 60c2-15 10-23 21-23s19 8 21 23" fill="#91a5ad"/></svg>');
  const fallbackUrl = String(player.avatarFallbackUrl || "");
  bindGameAvatar(image, { ...player, avatarFallbackUrl: fallbackUrl || fallbackSvg });
  image.dataset.playerUserId = String(player.userId);
  return image;
}

function installFiveDiceReadablePlayerStatus(artboard, strip, scope = "") {
  if (!artboard || !strip || !artboard.isConnected || !strip.isConnected) return () => {};
  const owner = artboard.ownerDocument, view = owner.defaultView;
  let rows = Array.from(strip.children).filter(node => node.matches("section.player-status-block"))
    .map(block => ({ block, nodes: Array.from(block.children).filter(node => !node.matches(".built-in-board-player-avatar")), row: null }));
  const companion = owner.createElement("div");
  companion.id = "five-dice-readable-player-status";
  companion.setAttribute("aria-label", "Player status");
  companion.hidden = true;
  let disposed = false, pending = 0, rebuilding = false;
  const originalFontScale = strip.style.getPropertyValue("--five-dice-status-font-scale");
  const originalFontPriority = strip.style.getPropertyPriority("--five-dice-status-font-scale");
  const restore = () => {
    for (const entry of rows) for (const node of entry.nodes) if (node.parentElement !== entry.block) entry.block.append(node);
    companion.hidden = true;
    strip.dataset.statusLayout = "artboard";
  };
  const overflows = node => {
    if (node.hidden || view.getComputedStyle(node).display === "none") return false;
    return node.scrollWidth > node.clientWidth || node.scrollHeight > node.clientHeight;
  };
  const sync = () => {
    pending = 0;
    if (disposed) return;
    if (!artboard.isConnected || !strip.isConnected) { cleanup(); return; }
    if (owner.hidden) return;
    // Do not reparent focused metadata. Retry after focus leaves naturally.
    const focused = owner.activeElement;
    if (focused && rows.some(entry => entry.nodes.some(node => node === focused || node.contains(focused)))) return;
    restore();
    const scale = artboard.offsetWidth > 0 ? artboard.getBoundingClientRect().width / artboard.offsetWidth : 0;
    if (!Number.isFinite(scale) || scale <= 0) return;
    // Text may grow to a readable size; it is never shrunk to make a lane pass.
    const fontScale = String(1 / scale);
    if (strip.style.getPropertyValue("--five-dice-status-font-scale") !== fontScale) strip.style.setProperty("--five-dice-status-font-scale", fontScale);
    const needsCompanion = rows.some(entry => entry.nodes.some(node => overflows(node)));
    if (!needsCompanion) return;
    if (!companion.isConnected) artboard.after(companion);
    for (const entry of rows) {
      if (!entry.row) {
        entry.row = owner.createElement("section");
        entry.row.className = "five-dice-readable-player";
        entry.row.dataset.playerUserId = entry.block.dataset.playerUserId;
        companion.append(entry.row);
      }
      for (const node of entry.nodes) entry.row.append(node);
    }
    companion.hidden = false;
    strip.dataset.statusLayout = "companion";
  };
  const schedule = () => { if (!disposed && !pending) pending = view.requestAnimationFrame(sync); };
  const observer = typeof view.ResizeObserver === "function" ? new view.ResizeObserver(schedule) : null;
  observer?.observe(artboard);
  if (artboard.parentElement) observer?.observe(artboard.parentElement);
  const mutations = typeof view.MutationObserver === "function" ? new view.MutationObserver(schedule) : null;
  mutations?.observe(artboard, { attributes: true, attributeFilter: ["style", "class"] });
  for (const entry of rows) for (const node of entry.nodes) mutations?.observe(node, { childList: true, characterData: true, subtree: true });
  view.addEventListener("resize", schedule, { passive: true });
  view.addEventListener("focusout", schedule, { passive: true });
  owner.addEventListener?.("visibilitychange", schedule);
  view.visualViewport?.addEventListener("resize", schedule, { passive: true });
  const cleanup = () => {
    if (disposed) return;
    disposed = true;
    if (pending) view.cancelAnimationFrame(pending);
    observer?.disconnect(); mutations?.disconnect();
    view.removeEventListener("resize", schedule);
    view.removeEventListener("focusout", schedule);
    owner.removeEventListener?.("visibilitychange", schedule);
    view.visualViewport?.removeEventListener("resize", schedule);
    view.removeEventListener("pagehide", cleanup);
    restore(); companion.remove();
    if (originalFontScale) strip.style.setProperty("--five-dice-status-font-scale", originalFontScale, originalFontPriority);
    else strip.style.removeProperty("--five-dice-status-font-scale");
    delete strip.dataset.statusLayout;
  };
  // Keep a settled fallback through same-roster refreshes; never carry it across viewers or games.
  const matchesScope = (nextArtboard, nextStrip, nextScope) => !disposed && scope !== ""
    && nextScope === scope && nextArtboard === artboard && nextStrip === strip
    && artboard.isConnected && strip.isConnected;
  const retainMetadata = (previous, next) => {
    if (!previous || previous.tagName !== next.tagName || previous.className !== next.className
        || previous.children.length !== next.children.length) return next;
    previous.hidden = next.hidden;
    const label = next.getAttribute("aria-label");
    if (label === null) previous.removeAttribute("aria-label");
    else if (previous.getAttribute("aria-label") !== label) previous.setAttribute("aria-label", label);
    if (next.dataset.lifecycleCountdown === undefined) delete previous.dataset.lifecycleCountdown;
    else previous.dataset.lifecycleCountdown = next.dataset.lifecycleCountdown;
    if (!next.children.length) {
      if (previous.textContent !== next.textContent) previous.textContent = next.textContent;
    } else {
      for (let index = 0; index < next.children.length; index += 1) {
        const oldChild = previous.children[index], newChild = next.children[index];
        const keptChild = retainMetadata(oldChild, newChild);
        if (keptChild !== oldChild) oldChild.replaceWith(keptChild);
      }
    }
    return previous;
  };
  cleanup.prepareForRender = (nextArtboard, nextStrip, nextScope) => {
    if (!matchesScope(nextArtboard, nextStrip, nextScope)
        || strip.dataset.statusLayout !== "companion" || !companion.isConnected || companion.hidden) return false;
    if (pending) view.cancelAnimationFrame(pending);
    pending = 0;
    rebuilding = true;
    return true;
  };
  cleanup.update = (nextArtboard, nextStrip, nextScope) => {
    if (!rebuilding || !matchesScope(nextArtboard, nextStrip, nextScope)) return false;
    rebuilding = false;
    const blocks = Array.from(strip.children).filter(node => node.matches("section.player-status-block"));
    if (blocks.length !== rows.length || blocks.some((block, index) => block.dataset.playerUserId !== rows[index].block.dataset.playerUserId)) return false;
    mutations?.disconnect();
    rows = blocks.map((block, index) => {
      const previous = rows[index], row = previous.row;
      const nodes = Array.from(block.children).filter(node => !node.matches(".built-in-board-player-avatar"))
        .map(next => {
          const prior = previous.nodes.find(node => node.className === next.className && node.tagName === next.tagName);
          const kept = retainMetadata(prior, next);
          if (kept !== next) next.remove();
          return kept;
        });
      for (const node of previous.nodes) if (!nodes.includes(node)) node.remove();
      for (const [position, node] of nodes.entries()) if (row.children[position] !== node) row.insertBefore(node, row.children[position] || null);
      return { block, nodes, row };
    });
    mutations?.observe(artboard, { attributes: true, attributeFilter: ["style", "class"] });
    for (const entry of rows) for (const node of entry.nodes) mutations?.observe(node, { childList: true, characterData: true, subtree: true });
    // Prior readable placement stays connected until the existing post-fit measurement.
    strip.dataset.statusLayout = "companion";
    schedule();
    return true;
  };
  view.addEventListener("pagehide", cleanup, { once: true });
  owner.fonts?.ready?.then(schedule).catch(() => {});
  sync();
  return cleanup;
}

function renderFiveDicePlayerStatusStrip() {
  if (terminalSessionError) return;
  const host = el("five-dice-player-status-strip");
  const terminal = fiveDicePresentationState().completed === true;
  const players = orderedPlayers(session?.state || {});
  const showBuiltInBoardAvatars = document.body.dataset.appearance === "built-in";
  const scope = showBuiltInBoardAvatars && session?.publicId
    ? JSON.stringify([session.publicId, currentUserId(), session.viewerRole, players.map(player => String(player.userId))]) : "";
  if (!scope || !fiveDiceStatusLayoutCleanup?.prepareForRender?.(el("five-dice-approved-artboard"), host, scope)) {
    fiveDiceStatusLayoutCleanup?.();
    fiveDiceStatusLayoutCleanup = null;
  }
  host.dataset.statusScope = scope;
  host.replaceChildren();
  host.classList.toggle("has-built-in-board-avatars", showBuiltInBoardAvatars);
  host.hidden = players.length === 0;
  host.style.setProperty("--player-status-columns", String(Math.max(1, Math.min(4, players.length))));
  host.dataset.playerCount = String(players.length);
  if (host.hidden) return;
  if (showBuiltInBoardAvatars) {
    const displayName = String(session?.displayName || "Five Dice").trim() || "Five Dice";
    const words = displayName.split(/\s+/);
    const brand = document.createElement("div");
    const primary = document.createElement("strong");
    const secondary = document.createElement("span");
    brand.className = "built-in-five-dice-brand";
    brand.setAttribute("aria-label", displayName);
    primary.textContent = words.shift() || displayName;
    secondary.textContent = words.join(" ");
    brand.append(primary);
    if (secondary.textContent) brand.append(secondary);
    host.append(brand);
  }
  const viewerIsPlayer = ["master", "player"].includes(String(session?.viewerRole || ""));
  const currentTurnUserId = Number(session?.turnUserId || session?.state?.turnOrder?.[session?.state?.turnIndex] || 0);
  for (const player of players) {
    const current = !terminal && currentTurnUserId > 0 && currentTurnUserId === Number(player.userId);
    const block = document.createElement("section");
    block.className = `player-status-block${current ? " is-current" : ""}${player.onHold ? " is-on-hold" : ""}${showBuiltInBoardAvatars ? " has-built-in-board-avatar" : ""}`;
    block.dataset.playerUserId = String(player.userId);
    block.setAttribute("aria-label", terminal
      ? `${player.displayName} final score`
      : player.onHold ? `${player.displayName} is on hold and joins next round` : `${player.displayName} timer status`);
    const identity = document.createElement("strong");
    identity.className = "player-status-name";
    identity.textContent = showBuiltInBoardAvatars
      ? player.displayName
      : viewerIsPlayer && Number(player.userId) === currentUserId() ? "You" : player.displayName;
    const timer = document.createElement("span");
    timer.className = "player-status-timer";
    timer.dataset.lifecycleCountdown = `player-timer:${player.userId}`;
    const timerStatus = fiveDicePlayerTimerText(player.userId);
    timer.textContent = timerStatus ? `${session?.state?._framework?.inactivity?.enabled === true ? "Timer " : ""}${timerStatus}` : "";
    timer.hidden = terminal || player.onHold || timerStatus === "";
    if (showBuiltInBoardAvatars) block.append(fiveDiceBuiltInPlayerAvatar(player));
    block.append(identity);
    if (showBuiltInBoardAvatars) {
      const score = document.createElement("span");
      const scoreLabel = document.createElement("span");
      const scoreValue = document.createElement("strong");
      score.className = "player-status-score";
      scoreLabel.className = "player-status-score-label";
      scoreValue.className = "player-status-score-value";
      scoreLabel.textContent = "Score";
      scoreValue.textContent = String(Number(player.state?.total || 0));
      score.append(scoreLabel, scoreValue);
      block.append(score);
    }
    block.append(timer);
    if (player.onHold && !terminal) {
      const hold = document.createElement("span");
      hold.className = "player-status-hold";
      if (showBuiltInBoardAvatars) {
        const label = document.createElement("span");
        const detail = document.createElement("span");
        label.textContent = "On hold";
        detail.textContent = "Joins next round";
        hold.append(label, detail);
        hold.setAttribute("aria-label", "On hold / Joins next round");
      } else {
        hold.textContent = "On hold / Joins next round";
      }
      block.append(hold);
    }
    if (current && session?.state?.completed !== true) {
      const turn = document.createElement("span");
      turn.className = "player-status-turn";
      turn.textContent = "Current turn";
      block.append(turn);
    }
    host.append(block);
  }
  if (showBuiltInBoardAvatars) {
    for (let seat = players.length; seat < 4; seat += 1) {
      const emptySeat = document.createElement("span");
      emptySeat.className = "player-status-block has-built-in-board-avatar is-empty-seat";
      emptySeat.setAttribute("aria-hidden", "true");
      host.append(emptySeat);
    }
  }
}

function syncSharedLifecycleCountdowns() {
  clearTimeout(sharedLifecycleRenderTimer);
  sharedLifecycleRenderTimer = 0;
  if (!session || session.status !== "active" || session.state?.completed) return;
  const framework = session.state?._framework || {};
  const pause = framework.pause || {};
  let active = false;
  for (const node of document.querySelectorAll("[data-lifecycle-countdown]")) {
    const kind = String(node.dataset.lifecycleCountdown || "");
    if (kind === "resume") {
      const remaining = lifecycleProjectedRemaining(pause.resumeRemainingSeconds);
      node.textContent = `Resuming in ${formatLifecycleTime(remaining)}.`;
      active ||= remaining !== null && remaining > 0;
    } else if (kind === "inactivity") {
      const remaining = lifecycleProjectedRemaining(framework.inactivity?.remainingProjectedSeconds);
      node.textContent = `Inactivity time remaining: ${formatLifecycleTime(remaining)}.`;
      active ||= remaining !== null && remaining > 0;
    } else if (kind.startsWith("disconnect:")) {
      const userId = kind.slice("disconnect:".length);
      const remaining = lifecycleProjectedRemaining(framework.players?.[userId]?.disconnectRemainingSeconds);
      node.textContent = `${memberName(userId)} disconnected · ${formatLifecycleTime(remaining)} cumulative reconnect time remaining.`;
      active ||= remaining !== null && remaining > 0;
    } else if (kind.startsWith("player-timer:")) {
      const userId = kind.slice("player-timer:".length);
      const timerStatus = fiveDicePlayerTimerText(userId);
      node.textContent = timerStatus ? `${framework.inactivity?.enabled === true ? "Timer " : ""}${timerStatus}` : "";
      node.hidden = timerStatus === "";
      active ||= framework.inactivity?.enabled === true
        && Number(framework.inactivity.ownerUserId || 0) === Number(userId)
        && lifecycleProjectedRemaining(framework.inactivity.remainingProjectedSeconds) > 0;
    }
  }
  if (active) sharedLifecycleRenderTimer = setTimeout(syncSharedLifecycleCountdowns, 250);
}

function currentSharedLifecycleDeadline() {
  if (terminalSessionError || busy || !session || session.status !== "active"
      || session.state?.completed || !["master", "player"].includes(String(session.viewerRole || ""))) return null;
  const actorId = Number(currentUserId());
  const framework = session.state?._framework || {};
  if (!Number.isInteger(actorId) || actorId < 1 || framework.serviceInterruption?.active
      || framework.players?.[String(actorId)]?.disconnected === true) return null;
  const pause = framework.pause || {};
  let action = "";
  let remaining = null;
  if (pause.mode === "resuming") {
    action = "settle-resume";
    remaining = pause.resumeRemainingSeconds;
  } else if (pause.mode === "running" && framework.inactivity?.enabled === true) {
    action = "settle-inactivity";
    remaining = framework.inactivity.remainingProjectedSeconds;
  }
  if (!action || typeof remaining !== "number" || !Number.isFinite(remaining) || remaining < 0) return null;
  return { action, remaining, actorId, publicId: String(session.publicId || ""), version: Number(session.stateVersion) };
}

function scheduleSharedLifecycleDeadline() {
  clearTimeout(sharedLifecycleDeadlineTimer);
  sharedLifecycleDeadlineTimer = 0;
  syncSharedLifecycleCountdowns();
  const deadline = currentSharedLifecycleDeadline();
  if (!deadline) return;
  sharedLifecycleDeadlineTimer = setTimeout(async () => {
    const current = currentSharedLifecycleDeadline();
    if (sharedLifecycleSettlementInFlight || !current
        || current.publicId !== deadline.publicId || current.actorId !== deadline.actorId
        || current.version !== deadline.version || current.action !== deadline.action
        || current.remaining > deadline.remaining) return;
    sharedLifecycleSettlementInFlight = true;
    try {
      await runAction(deadline.action);
    } finally {
      sharedLifecycleSettlementInFlight = false;
    }
  }, Math.max(0, Math.ceil(deadline.remaining * 1000) + 75));
}

function appendLifecycleButton(host, label, action, enabled, confirm = false) {
  const button = document.createElement("button");
  button.type = "button";
  button.className = `secondary${confirm ? " lifecycle-confirm" : ""}`;
  button.textContent = label;
  if (action === "pause-game") button.dataset.externalGameControl = "pause";
  else button.dataset.externalGameRow = "pause";
  button.disabled = !enabled || busy;
  button.addEventListener("click", () => runAction(action));
  host.append(button);
}

function renderLifecycleControls() {
  if (terminalSessionError) return;

  const host = el("five-dice-lifecycle");
  host.replaceChildren();
  if (!session || session.status !== "active" || session.state?.completed) return;
  const framework = session?.state?._framework || {};
  const pause = framework.pause || { mode: "running" };
  const actions = framework.actions || {};
  const viewerIsPlayer = ["master", "player"].includes(String(session?.viewerRole || ""));
  if (viewerIsPlayer && !session?.state?.completed) {
    appendLifecycleButton(host, "Pause game", "pause-game", actions.canPause === true);
  }
  if (framework.serviceInterruption?.active) {
    const status = document.createElement("span");
    status.className = "lifecycle-status";
    status.textContent = "Game time is frozen for a confirmed CoreChat service interruption.";
    host.append(status);
  }
  for (const member of (session?.members || []).filter(item => ["master", "player"].includes(String(item.role || "")))) {
    const userId = String(Number(member.userId || 0));
    if (framework.players?.[userId]?.disconnected !== true) continue;
    const status = document.createElement("span");
    status.className = "lifecycle-status lifecycle-countdown";
    status.dataset.lifecycleCountdown = `disconnect:${userId}`;
    host.append(status);
  }
  if (pause.mode === "running" && framework.inactivity?.enabled && !framework.serviceInterruption?.active) {
    const status = document.createElement("span");
    status.className = "lifecycle-status lifecycle-countdown";
    status.dataset.lifecycleCountdown = "inactivity";
    host.append(status);
  }
  if (pause.mode === "proposed") {
    const status = document.createElement("span");
    status.className = "lifecycle-status";
    status.textContent = `Pause proposed by ${memberName(pause.proposedByUserId)}.`;
    host.append(status);
    if (actions.canAcceptPause) appendLifecycleButton(host, "Accept pause", "accept-pause", true);
    if (actions.canDeclinePause) appendLifecycleButton(host, "Decline pause", "decline-pause", true);
  }
  if (actions.canPauseForReconnect) appendLifecycleButton(host, "Pause for reconnect", "pause-for-reconnect", true);
  if (pause.mode === "paused") {
    const status = document.createElement("span");
    status.className = "lifecycle-status";
    status.textContent = pause.reason === "reconnect" ? "Paused while waiting for reconnect." : "Game paused by agreement.";
    host.append(status);
    if (actions.canStartResume) appendLifecycleButton(host, "Start 1-minute resume", "start-resume", true);
  }
  if (pause.mode === "resuming") {
    const status = document.createElement("span");
    status.className = "lifecycle-status lifecycle-countdown";
    status.dataset.lifecycleCountdown = "resume";
    host.append(status);
    if (actions.canResumeNow) appendLifecycleButton(host, "Resume now", "resume-now", true);
  }
  if (actions.canSelectDisconnectWin) appendLifecycleButton(host, "End game and win", "select-disconnect-win", true);
  if (actions.canConfirmDisconnectWin) appendLifecycleButton(host, "Confirm end game and win", "confirm-disconnect-win", true, true);
  if (actions.canSelectDisconnectDraw) appendLifecycleButton(host, "End game as draw", "select-disconnect-draw", true);
  if (actions.canConfirmDisconnectDraw) appendLifecycleButton(host, "Confirm end game as draw", "confirm-disconnect-draw", true, true);
  host.hidden = host.childElementCount === 0;
  requestAnimationFrame(syncSharedLifecycleCountdowns);
}

function renderBuiltInMotionState(state, displayName) {
  if (terminalSessionError) return;

  const board = el("play-surface");
  const classic = session?.presentation?.effectivePack === "classic";
  const visualFx = options?.categories?.gfxEnabled !== false
    && !matchMedia("(prefers-reduced-motion: reduce)").matches;
  const sessionKey = `${String(session?.publicId || activeGameSessionId)}:${String(session?.startedAt || "")}`;
  if (!classic && String(session?.status || "") === "active" && !state.completed && builtInOpeningKey !== sessionKey) {
    builtInOpeningKey = sessionKey;
    builtInOpeningUntil = visualFx ? Date.now() + 3800 : 0;
    clearTimeout(builtInOpeningTimer);
    if (builtInOpeningUntil) {
      builtInOpeningTimer = window.setTimeout(() => {
        builtInOpeningUntil = 0;
        render();
      }, 3820);
    }
  }
  const opening = !classic && visualFx && builtInOpeningUntil > Date.now();
  const scoring = !classic && visualFx && builtInScoreMotion
    && Date.now() - Number(builtInScoreMotion.startedAt || 0) < 1800;
  const viewerUserId = currentUserId();
  const terminal = fiveDiceTerminalSummary(state, viewerUserId, memberName, displayName);
  const winners = terminal.winners;
  const isDraw = winners.length > 1;
  const viewerWon = terminal.outcome === "win";
  const winning = !classic && visualFx && state.completed && viewerWon
    && builtInTerminalMotionStartedAt > 0
    && Date.now() - builtInTerminalMotionStartedAt < 4700;
  board.classList.toggle("is-built-in-opening", opening);
  board.classList.toggle("is-built-in-rolling", !classic && visualFx && rolling);
  board.classList.toggle("is-built-in-scoring", Boolean(scoring));
  board.classList.toggle("is-built-in-winning", Boolean(winning));
  board.classList.toggle("is-built-in-losing", !classic && state.completed && terminal.outcome === "loss");
  board.classList.toggle("is-built-in-draw", !classic && state.completed && isDraw);
  board.dataset.modernScoreCategory = scoring ? String(builtInScoreMotion.category || "") : "";
  el("built-in-opening-name").textContent = displayName;

  const result = el("built-in-five-dice-result");
  const resultKey = `${String(session?.publicId || activeGameSessionId)}:${Number(session?.stateVersion || 0)}`;
  const showResult = !classic && state.completed && builtInResultDismissedKey !== resultKey;
  result.hidden = !showResult;
  if (showResult) {
    result.dataset.outcome = terminal.outcome;
    el("built-in-result-name").textContent = terminal.title;
    el("built-in-result-score").textContent = terminal.detail;
    el("built-in-result-game-name").textContent = terminal.gameText;
  }
}

function installFiveDiceReadableScoreControls(artboard, scope) {
  const owner = artboard?.ownerDocument;
  const view = owner?.defaultView;
  const recordsPanel = owner?.getElementById("score-records");
  const scroll = owner?.getElementById("score-table-scroll");
  if (!artboard?.isConnected || !recordsPanel?.isConnected || !scroll) return () => {};
  let disposed = false, pending = 0;
  let retainedFocus = null;
  const disabledBefore = new Map();
  const currentScope = () => String(session?.publicId || activeGameSessionId) + ":" + String(currentUserId());
  const artRows = () => Array.from(artboard.querySelectorAll("button.built-in-score-choice[data-category]"));
  const tableRows = () => Array.from(recordsPanel.querySelectorAll("button.category[data-category]"));
  const restoreArt = () => {
    for (const [node, disabled] of disabledBefore) if (node.isConnected) node.disabled = disabled;
    disabledBefore.clear();
  };
  const showFallback = (active, scheduleCue = true) => {
    const value = String(active);
    if (recordsPanel.dataset.readableScoring === value) return;
    recordsPanel.dataset.readableScoring = value;
    if (active) owner.body.dataset.readableScoring = "true";
    else delete owner.body.dataset.readableScoring;
    renderScoreRecordsVisibility(scheduleCue);
  };
  const sync = () => {
    pending = 0;
    if (disposed) return;
    if (!artboard.isConnected || !recordsPanel.isConnected || currentScope() !== scope) { cleanup(); return; }
    if (owner.hidden) return;
    fitBuiltInScorePlayerNames(artboard.querySelectorAll(".built-in-score-player-name"));
    const rows = artRows();
    if (rows.length !== Object.keys(categoryLabels).length) return;
    const rects = ensureBuiltInScoreTargets(rows);
    if (rects.some(rect => !Number.isFinite(rect.width) || !Number.isFinite(rect.height) || rect.width <= 0 || rect.height <= 0)) return;
    const artboardRect = artboard.getBoundingClientRect();
    const scoreSheetRect = artboard.querySelector('.built-in-score-sheet')?.getBoundingClientRect();
    const undersized = rects.some(rect => rect.width < 24 || rect.height < 24)
      || artboardRect.width < 760 || Number(scoreSheetRect?.width || 0) < 288;
    if (!undersized) {
      restoreArt();
      delete recordsPanel.dataset.readableScoringState;
      // Do not hide a score control while the user is still interacting with it.
      if (!retainedFocus && !recordsPanel.contains(owner.activeElement)) showFallback(false);
      return;
    }
    // Reveal the existing scoring route first, then measure its actual controls.
    showFallback(true);
    if (recordsPanel.hidden) {
      restoreArt();
      recordsPanel.dataset.readableScoringState = "available";
      return;
    }
    const choices = tableRows();
    const categorySet = new Set(choices.map(node => node.dataset.category));
    const usable = !recordsPanel.hidden && choices.length === rows.length && categorySet.size === rows.length
      && rows.every(row => categorySet.has(row.dataset.category))
      && choices.every(node => {
        const rect = node.getBoundingClientRect();
        return Number.isFinite(rect.width) && Number.isFinite(rect.height) && rect.width >= 24 && rect.height >= 24;
      })
      && scroll.clientWidth > 0 && scroll.scrollWidth <= scroll.clientWidth
      && scroll.scrollHeight <= scroll.clientHeight;
    recordsPanel.dataset.readableScoringState = usable ? "ready" : "unavailable";
    if (!usable) { restoreArt(); return; }
    const focused = owner.activeElement;
    if (rows.includes(focused)) {
      const replacement = choices.find(node => node.dataset.category === focused.dataset.category);
      (replacement && !replacement.disabled ? replacement : recordsPanel).focus({ preventScroll: true });
    }
    for (const node of disabledBefore.keys()) if (!node.isConnected) disabledBefore.delete(node);
    for (const row of rows) {
      if (!disabledBefore.has(row)) disabledBefore.set(row, row.disabled);
      row.disabled = true;
    }
  };
  const schedule = () => { if (!disposed && !pending) pending = view.requestAnimationFrame(sync); };
  const observer = typeof view.ResizeObserver === "function" ? new view.ResizeObserver(schedule) : null;
  observer?.observe(artboard);
  if (artboard.parentElement) observer?.observe(artboard.parentElement);
  observer?.observe(scroll);
  const mutations = typeof view.MutationObserver === "function" ? new view.MutationObserver(schedule) : null;
  mutations?.observe(artboard, { attributes: true, attributeFilter: ["style", "class"], childList: true, subtree: true });
  view.addEventListener("resize", schedule, { passive: true });
  view.addEventListener("focusout", schedule, { passive: true });
  view.visualViewport?.addEventListener("resize", schedule, { passive: true });
  owner.addEventListener("visibilitychange", schedule);
  const cleanup = () => {
    if (disposed) return;
    disposed = true;
    if (pending) view.cancelAnimationFrame(pending);
    observer?.disconnect(); mutations?.disconnect();
    view.removeEventListener("resize", schedule);
    view.removeEventListener("focusout", schedule);
    view.visualViewport?.removeEventListener("resize", schedule);
    owner.removeEventListener("visibilitychange", schedule);
    view.removeEventListener("pagehide", cleanup);
    restoreArt();
    delete recordsPanel.dataset.readableScoringState;
    retainedFocus = null;
    showFallback(false, false);
  };
  cleanup.update = (nextArtboard, nextScope) => {
    if (disposed || nextArtboard !== artboard || nextScope !== scope) return false;
    sync();
    return !disposed;
  };
  cleanup.visibilityChanged = schedule;
  cleanup.captureFocus = () => {
    const node = owner.activeElement;
    if (currentScope() !== scope || !node?.dataset.category || !recordsPanel.contains(node)) { retainedFocus = null; return null; }
    retainedFocus = { scope, category: node.dataset.category, node };
    return retainedFocus;
  };
  cleanup.restoreFocus = saved => {
    retainedFocus = null;
    if (!saved || disposed || saved.scope !== scope || currentScope() !== scope) return;
    const focused = owner.activeElement;
    if (focused && focused !== owner.body && focused.isConnected && focused !== saved.node) return;
    if (recordsPanel.hidden) return;
    const replacement = tableRows().find(node => node.dataset.category === saved.category);
    (replacement && !replacement.disabled ? replacement : recordsPanel).focus({ preventScroll: true });
  };
  view.addEventListener("pagehide", cleanup, { once: true });
  sync();
  owner.fonts?.ready?.then(schedule).catch(() => {});
  return cleanup;
}

function fiveDiceMinimumScoreTargetScale(artboard) {
  let minimumDimension = Infinity;
  for (const row of artboard.querySelectorAll(".built-in-score-choice[data-category]")) {
    const rect = row.getBoundingClientRect();
    if (Number.isFinite(rect.width) && rect.width > 0 && Number.isFinite(rect.height) && rect.height > 0) {
      minimumDimension = Math.min(minimumDimension, rect.width, rect.height);
    }
  }
  return Number.isFinite(minimumDimension) ? Math.max(0.75, 24 / minimumDimension) : 0.75;
}

function render() {
  if (terminalSessionError) return;

  if (!session) return;
  const scoringFocus = fiveDiceScoreLayoutCleanup?.captureFocus?.();
  const state = fiveDicePresentationState();
  const sessionEndedWithoutResult = state.completed === true && session.state?.completed !== true;
  const displayName = session.displayName || "Five Dice";
  document.title = displayName;
  el("game-title").textContent = displayName;
  el("board-size-smaller").setAttribute("aria-label", `Make ${displayName} board smaller`);
  el("board-size-value").setAttribute("aria-label", `${displayName} board size`);
  el("board-size-larger").setAttribute("aria-label", `Make ${displayName} board larger`);
  el("built-in-score-actions").setAttribute("aria-label", `${displayName} player scorecard and scoring categories`);
  el("separate-media-controls").setAttribute("aria-label", `${displayName} presentation controls`);
  renderAppearance(displayName);
  renderBuiltInMotionState(state, displayName);
  renderGameOptions();
  applyFiveDiceBoardScale();
  el("rules").setAttribute("aria-label", `How to play ${displayName}`);
  renderRules();
  renderAccessibility();
  el("score-table-scroll").setAttribute("aria-label", `${displayName} scorecards`);
  const turnUserId = Number(session.turnUserId || state.turnOrder?.[state.turnIndex] || 0);
  const turnMember = session.members.find(member => Number(member.userId) === turnUserId);
  const mine = currentUserId();
  const canAct = gameLifecycleAvailable() && mine === turnUserId;
  el("turn-name").textContent = state.completed ? (sessionEndedWithoutResult ? "Game ended" : "Game complete") : (turnMember?.displayName || "Waiting for players");
  el("roll-count").textContent = `Rolls used: ${Number(state.rollsThisTurn || 0)} of 3`;
  el("roll").disabled = !canAct || Number(state.rollsThisTurn) >= 3 || busy;
  renderMediaControls(canAct && Number(state.rollsThisTurn) < 3);
  const pauseMode = String(state._framework?.pause?.mode || "running");
  const gameStatus = el("game-status");
  const currentStatusMessage = state.completed
    ? (sessionEndedWithoutResult ? `${displayName} has ended.` : `${displayName} is complete.`)
    : session.viewerRole === "spectator"
      ? `Watching ${displayName}.`
      : state._framework?.serviceInterruption?.active
        ? `${displayName} is frozen for a confirmed CoreChat service interruption.`
        : ["paused", "resuming"].includes(pauseMode)
          ? `${displayName} is ${pauseMode === "paused" ? "paused" : "preparing to resume"}.`
          : canAct ? "Your turn." : `Waiting for ${turnMember?.displayName || "the next player"}.`;
  if (actionFailureStatusMessage) showActionFailure(actionFailureStatusMessage);
  else gameStatus.textContent = currentStatusMessage;
  renderFiveDicePlayerStatusStrip();
  renderLifecycleControls();
  renderDice(state, canAct);
  renderScores(state, canAct);
  renderRecords();
  renderScoreRecordsVisibility();
  fiveDiceHeightFitCleanup?.();
  fiveDiceHeightFitCleanup = null;
  if (session.presentation?.effectivePack !== "classic") {
    const artboard = el("five-dice-approved-artboard");
    fiveDiceHeightFitCleanup = installViewportHeightFit(artboard, artboard.parentElement, {
      enabled: () => viewerHeightFitEnabled("five-dice"),
      minimumScale: () => fiveDiceMinimumScoreTargetScale(artboard),
      reserveBottom: () => 24 + Number(artboard.parentElement.querySelector(".roll-actions")?.offsetHeight || 0),
    });
    const strip = el("five-dice-player-status-strip");
    const scope = strip.dataset.statusScope || "";
    if (!fiveDiceStatusLayoutCleanup?.update?.(artboard, strip, scope)) {
      fiveDiceStatusLayoutCleanup?.();
      fiveDiceStatusLayoutCleanup = installFiveDiceReadablePlayerStatus(artboard, strip, scope);
    }
    const scoringScope = String(session.publicId || activeGameSessionId) + ":" + String(currentUserId());
    if (!fiveDiceScoreLayoutCleanup?.update?.(artboard, scoringScope)) {
      fiveDiceScoreLayoutCleanup?.();
      fiveDiceScoreLayoutCleanup = installFiveDiceReadableScoreControls(artboard, scoringScope);
    }
    fiveDiceScoreLayoutCleanup?.restoreFocus?.(scoringFocus);
  } else {
    fiveDiceScoreLayoutCleanup?.();
    fiveDiceScoreLayoutCleanup = null;
  }
  requestAnimationFrame(syncScoreScrollCue);
  scheduleSharedLifecycleDeadline();
}

function mediaUrl(slot) {
  const query = new URLSearchParams({ game_session_id: activeGameSessionId, slot });
  return `../../api/five_dice_media.php?${query}`;
}

function preloadClassicVisual(slot) {
  const existing = classicVisualPreloads.get(slot);
  if (existing) return existing;
  const request = new Promise((resolve, reject) => {
    const image = new Image();
    image.decoding = "async";
    image.onload = async () => {
      try {
        if (image.decode) await image.decode();
        resolve(image);
      } catch (error) {
        reject(error);
      }
    };
    image.onerror = () => reject(new Error(`Classic visual media ${slot} is unavailable.`));
    image.src = mediaUrl(slot);
  }).catch(error => {
    classicVisualPreloads.delete(slot);
    throw error;
  });
  classicVisualPreloads.set(slot, request);
  return request;
}

async function ensureClassicRollVisuals() {
  if (session?.presentation?.effectivePack !== "classic") return;
  document.body.dataset.classicRollMediaReady = "pending";
  try {
    await Promise.all(classicRollVisualSlots.map(preloadClassicVisual));
    document.body.dataset.classicRollMediaReady = "true";
  } catch (error) {
    document.body.dataset.classicRollMediaReady = "false";
    throw new Error("The Classic roll artwork could not be prepared. Try Roll dice again.", { cause: error });
  }
}

const BUILT_IN_PUBLIC_SOUND_ROOT = "../../assets/audio/built-in-games";
  const BUILT_IN_PUBLIC_SOUND_BY_SLOT = Object.freeze({
    "ready-sound": "five-dice-turn-start.wav",
    "turn-start-sound": "five-dice-turn-start.wav",
    "invalid-sound": "five-dice-invalid.wav",
    "roll-sound": "five-dice-roll.wav",
    "hold-sound": "five-dice-hold.wav",
    "release-sound": "five-dice-release.wav",
    "score-commit-sound": "five-dice-score-commit.wav",
    "upper-bonus-sound": "five-dice-upper-bonus.wav",
    "celebration-sound": "five-dice-celebration.wav",
    "win-sound": "five-dice-victory.wav",
    "loser-sound": "five-dice-loss.wav",
    "draw-sound": "five-dice-draw.wav",
  });

function playOptionalSound(slot) {
  const classic = session?.presentation?.effectivePack === "classic";
  const builtInAsset = classic ? "" : String(BUILT_IN_PUBLIC_SOUND_BY_SLOT[slot] ?? "").trim();
  if (!classic && !builtInAsset) {
    traceMedia("effect-suppressed", slot, { reason: "built-in-unmapped", soundOwner: "built-in-public-cc0" });
    return;
  }
  const legacyEffectsOn = options?.effectsEnabled !== false;
  const category = "sfx";
  const enabled = options?.categories?.sfxEnabled ?? legacyEffectsOn;
  if (!enabled) {
    traceMedia("effect-suppressed", slot, { reason: `${category}-off`, category });
    return;
  }
  const audioKey = classic ? slot : `built-in-public:${builtInAsset}`;
  let audio = audioPlayers.get(audioKey);
  if (!audio) {
    audio = new Audio(classic ? mediaUrl(slot) : new URL(`${BUILT_IN_PUBLIC_SOUND_ROOT}/${builtInAsset}`, window.location.href).toString());
    audio.preload = classic ? "none" : "auto";
    audioPlayers.set(audioKey, audio);
    traceMedia("audio-created", classic ? slot : builtInAsset, { kind: "effect", soundOwner: classic ? "classic-private" : "built-in-public-cc0" });
  }
  audio.volume = Math.max(0, Math.min(1, Number(options?.masterVolume ?? 100) / 100));
  audio.currentTime = 0;
  traceMedia("effect-play-requested", classic ? slot : builtInAsset, { category, soundOwner: classic ? "classic-private" : "built-in-public-cc0" });
  audio.play()
    .then(() => traceMedia("effect-playing", classic ? slot : builtInAsset))
    .catch(() => traceMedia("effect-unavailable", classic ? slot : builtInAsset));
}

function playOptionalBackgroundMusic() {
  if (session?.presentation?.effectivePack !== "classic" || options?.musicEnabled !== true) return;
  let audio = audioPlayers.get("background-music");
  if (!audio) {
    audio = new Audio(mediaUrl("background-music"));
    audio.preload = "none";
    audio.loop = true;
    audioPlayers.set("background-music", audio);
    traceMedia("audio-created", "background-music", { kind: "music" });
  }
  audio.volume = Math.max(0, Math.min(1, Number(options?.masterVolume ?? 100) / 100));
  if (audio.paused) {
    traceMedia("music-play-requested", "background-music");
    audio.play()
      .then(() => traceMedia("music-playing", "background-music"))
      .catch(() => traceMedia("music-unavailable", "background-music"));
  }
}

function pauseAllAudio(reason) {
  for (const [slot, audio] of audioPlayers) {
    if (!audio.paused) audio.pause();
    if (slot !== "background-music") audio.currentTime = 0;
  }
  traceMedia("all-audio-paused", null, { reason, playerCount: audioPlayers.size });
}

function escapeHtml(value) {
  const node = document.createElement("span");
  node.textContent = String(value);
  return node.innerHTML;
}

async function toggleEffectCategory(key, label, slots) {
  if (!options) return;
  const previous = options;
  const legacyEffectsOn = options.effectsEnabled !== false;
  const categories = { ...(options.categories || {}) };
  const current = categories[key] ?? legacyEffectsOn;
  categories[key] = !current;
  const nextSfx = key === "sfxEnabled" ? categories[key] : (categories.sfxEnabled ?? legacyEffectsOn);
  options = { ...options, effectsEnabled: Boolean(nextSfx), categories };
  renderMediaControls();
  traceMedia("effect-category-toggled", null, { category: label, enabled: categories[key] });
  if (!categories[key]) {
    for (const [slot, audio] of audioPlayers) {
      if (!slots.has(slot)) continue;
      audio.pause();
      audio.currentTime = 0;
    }
  }
  try {
    await saveOptions(options);
  } catch (error) {
    if (error.currentOptionsMutation !== false) options = previous;
    renderMediaControls();
    el("game-status").textContent = error.message;
  }
}

function toggleSound() {
  return toggleEffectCategory("sfxEnabled", "sfx", new Set([
    "roll-sound", "hold-sound", "release-sound", "invalid-sound",
    "ready-sound", "turn-start-sound", "score-commit-sound", "upper-bonus-sound",
    "celebration-sound", "win-sound", "loser-sound", "draw-sound"
  ]));
}

async function toggleViewerCategory(key, label, fallback = true) {
  if (!options) return;
  const previous = options;
  const categories = { ...(options.categories || {}) };
  const current = typeof categories[key] === "boolean" ? categories[key] : fallback;
  categories[key] = !current;
  options = { ...options, categories };
  renderMediaControls();
  traceMedia("viewer-presentation-toggled", null, { category: label, enabled: categories[key] });
  try {
    await saveOptions(options);
  } catch (error) {
    if (error.currentOptionsMutation !== false) options = previous;
    renderMediaControls();
    el("game-status").textContent = error.message;
  }
}

function toggleGfx() {
  return toggleViewerCategory("gfxEnabled", "gfx");
}

function toggleSeparateControls() {
  return toggleViewerCategory("separateControlButtons", "separate-control-buttons", false);
}

async function toggleMusic() {
  if (!options) return;
  const previous = options;
  const enabling = options.musicEnabled !== true;
  options = { ...options, musicEnabled: enabling };
  renderMediaControls();
  traceMedia("music-toggled", "background-music", { enabled: enabling });
  if (enabling) {
    playOptionalBackgroundMusic();
  } else {
    const audio = audioPlayers.get("background-music");
    if (audio) audio.pause();
    traceMedia("music-paused", "background-music", { reason: "music-off" });
  }
  try {
    await saveOptions(options);
  } catch (error) {
    if (error.currentOptionsMutation !== false) options = previous;
    if (error.currentOptionsMutation !== false && previous.musicEnabled !== true) {
      const audio = audioPlayers.get("background-music");
      if (audio) audio.pause();
    }
    renderMediaControls();
    el("game-status").textContent = error.message;
  }
}

el("roll").addEventListener("click", roll);
el("nroll-hotspot").addEventListener("click", roll);
el("start-new-game-hotspot").addEventListener("click", requestNewGame);
el("sound-toggle").addEventListener("click", toggleSound);
el("sound-hotspot").addEventListener("click", toggleSound);
el("music-toggle").addEventListener("click", toggleMusic);
el("music-hotspot").addEventListener("click", toggleMusic);
el("gfx-toggle").addEventListener("click", toggleGfx);
el("gfx-hotspot").addEventListener("click", toggleGfx);
el("game-options-sound-fx-toggle").addEventListener("click", toggleSound);
el("game-options-visual-fx-toggle").addEventListener("click", toggleGfx);
el("built-in-result-dismiss").addEventListener("click", () => {
  builtInResultDismissedKey = `${String(session?.publicId || activeGameSessionId)}:${Number(session?.stateVersion || 0)}`;
  render();
});
el("separate-controls-toggle").addEventListener("click", toggleSeparateControls);
for (const id of [
  "roll", "nroll-hotspot", "start-new-game-hotspot",
  "sound-toggle", "sound-hotspot", "music-toggle", "music-hotspot",
  "gfx-toggle", "gfx-hotspot", "game-options-sound-fx-toggle",
  "game-options-visual-fx-toggle", "separate-controls-toggle",
  "board-size-smaller", "board-size-larger", "height-fit-toggle",
]) bindExplicitKeyboardClick(el(id));
el("height-fit-toggle").addEventListener("click", () => {
  setViewerHeightFit("five-dice", !viewerHeightFitEnabled("five-dice"));
  render();
});
el("board-size-smaller").addEventListener("click", () => changeFiveDiceBoardScale(-1));
el("board-size-larger").addEventListener("click", () => changeFiveDiceBoardScale(1));
el("save-game-options").addEventListener("click", async () => {
  if (busy || session?.status !== "lobby" || session?.viewerRole !== "master") return;
  busy = true; render();
  try {
    const mode = el("mode-select").value;
    const settings = { ...(session.settings || {}), ...(settingsDraft || {}) };
    if (mode === "recorded" && settings.inactivityProfile === "unlimited") settings.inactivityProfile = "default";
    replaceSession(await post("update-settings", { settings, mode }));
  } catch (error) {
    el("game-status").textContent = error.message;
  } finally {
    busy = false; render();
  }
});
el("accept-game-options").addEventListener("click", async () => {
  if (busy || session?.status !== "lobby") return;
  busy = true; render();
  try {
    replaceSession(await post("accept", { settings_sha256: session.settingsSha256, mode: session.mode }));
  } catch (error) {
    el("game-status").textContent = error.message;
  } finally {
    busy = false; render();
  }
});
el("appearance-select").addEventListener("change", async event => {
  if (busy) return;
  busy = true;
  try {
    await post("presentation-pack", { game_key: "g_4f8c2d71", pack_id: event.currentTarget.value });
    replaceSession(await getSession());
  } catch (error) {
    el("game-status").textContent = error.message;
  } finally {
    busy = false;
    render();
  }
});
el("separate-controls-info").addEventListener("click", () => {
  const help = el("separate-controls-help");
  help.hidden = !help.hidden;
  el("separate-controls-info").setAttribute("aria-expanded", help.hidden ? "false" : "true");
});
document.addEventListener("keydown", event => {
  if (event.key !== "Escape") return;
  const help = document.querySelector(".compact-info-popover:not([hidden])");
  if (!help) return;
  event.preventDefault();
  help.hidden = true;
  const owner = document.querySelector(`[aria-controls="${help.id}"]`);
  owner?.setAttribute("aria-expanded", "false");
  owner?.focus();
});
el("score-scroll-left").addEventListener("click", () => scrollScores("x", -1));
el("score-scroll-right").addEventListener("click", () => scrollScores("x", 1));
el("score-scroll-up").addEventListener("click", () => scrollScores("y", -1));
el("score-scroll-down").addEventListener("click", () => scrollScores("y", 1));
el("score-table-scroll").addEventListener("scroll", syncScoreScrollCue, { passive: true });
el("score-records-toggle").addEventListener("click", () => {
  scoreRecordsVisible = !scoreRecordsVisible;
  renderScoreRecordsVisibility();
  fiveDiceScoreLayoutCleanup?.visibilityChanged?.();
  if (scoreRecordsVisible) el("score-records").focus({ preventScroll: true });
});
el("game-options-toggle").addEventListener("click", () => {
  gameOptionsVisible = !gameOptionsVisible;
  renderGameOptions();
  if (gameOptionsVisible) el("game-options").focus({ preventScroll: true });
});
el("record-opponent").addEventListener("change", renderRecords);
el("rules").addEventListener("click", () => {
  const panel = el("rules-panel");
  panel.hidden = !panel.hidden;
  el("rules").setAttribute("aria-expanded", panel.hidden ? "false" : "true");
  if (!panel.hidden) panel.focus({ preventScroll: true });
});
el("accessibility").addEventListener("click", () => {
  const panel = el("accessibility-panel");
  panel.hidden = !panel.hidden;
  el("accessibility").setAttribute("aria-expanded", panel.hidden ? "false" : "true");
  if (!panel.hidden) panel.focus({ preventScroll: true });
});
document.addEventListener("keydown", event => {
  const panel = el("accessibility-panel");
  if (event.key !== "Escape" || panel.hidden) return;
  event.preventDefault();
  panel.hidden = true;
  el("accessibility").setAttribute("aria-expanded", "false");
  el("accessibility").focus({ preventScroll: true });
});
new ResizeObserver(syncScoreScrollCue).observe(el("score-table-scroll"));

async function refresh() {
  if (terminalSessionError) return;
  const requestGameId = activeGameSessionId;
  try {
    if (gameSurfaceVisible === false || documentVisible === false || document.hidden) return;
    const previousSessionIdentity = stableFiveDiceRenderIdentity(session);
    const previousOptionsIdentity = stableFiveDiceRenderIdentity(options);
    const previousRecordsIdentity = stableFiveDiceRenderIdentity(records);
    const refreshRecords = records === null || refreshCount % 10 === 0;
    const [nextSession,nextOptions,nextRecords] = await Promise.all([getSession(),options ? Promise.resolve(options) : getOptions(),refreshRecords ? getRecords() : Promise.resolve(records)]);
    if (terminalSessionError || requestGameId !== activeGameSessionId) return;
    replaceSession(nextSession); options=nextOptions; records=nextRecords; refreshCount++;
    await reconnectVisibleFiveDiceSession();
    if (terminalSessionError || requestGameId !== activeGameSessionId) return;
    const renderRequired = previousSessionIdentity !== stableFiveDiceRenderIdentity(session)
      || previousOptionsIdentity !== stableFiveDiceRenderIdentity(options)
      || previousRecordsIdentity !== stableFiveDiceRenderIdentity(records);
    clearLoadError();
    if (renderRequired) render();
    else document.body.dataset.lastSuppressedBoardStateVersion = String(session?.stateVersion || 0);
    if (session?.presentation?.effectivePack === 'classic' && document.body.dataset.classicRollMediaReady !== 'true') void ensureClassicRollVisuals().catch(()=>{});
  } catch (error) {
    if (terminalSessionError || requestGameId !== activeGameSessionId || error.staleResponse) return;
    if (isTerminalFiveDiceSessionError(error)) { endUnavailableFiveDiceSession(error); return; }
    showLoadError(error.message);
  } finally {
    if (!terminalSessionError && requestGameId === activeGameSessionId) {
      clearTimeout(pollTimer);
      const localFixture = /^(?:127(?:\.\d{1,3}){3}|localhost)$/.test(location.hostname);
      pollTimer=setTimeout(refresh,localFixture ? 2500 : 1500);
    }
  }
}

addEventListener("message", event => {
  if (terminalSessionError || event.source !== window.parent) return;

  if (event.origin !== location.origin || event.data?.type !== "corechat-game-surface-visibility") return;
  if (event.data.visible === false) {
    gameSurfaceVisible = false;
    pauseAllAudio("game-surface-hidden");
  } else if (event.data.visible === true) {
    gameSurfaceVisible = true;
    playOptionalBackgroundMusic();
  }
  renderMicrophoneMotion();
});
document.addEventListener("visibilitychange", () => {
  if (terminalSessionError) return;

  documentVisible = !document.hidden;
  if (!documentVisible) pauseAllAudio("document-hidden");
  else playOptionalBackgroundMusic();
  renderMicrophoneMotion();
});
addEventListener("pagehide", () => {
  if (terminalSessionError) return;

  for (const controller of activeRequestControllers) controller.abort("pagehide");
  activeRequestControllers.clear();
  void post("disconnect", {}, { keepalive: true }).catch(() => {});
  documentVisible = false;
  gameSurfaceVisible = false;
  renderMicrophoneMotion();
  clearTimeout(pollTimer);
  clearTimeout(sharedLifecycleDeadlineTimer);
  clearTimeout(sharedLifecycleRenderTimer);
  clearTimeout(builtInOpeningTimer);
  clearTimeout(builtInScoreMotionTimer);
  clearTimeout(builtInTerminalMotionTimer);
  pauseAllAudio("pagehide");
}, { once: true });
void startFiveDiceSessionConnection();

if (LOOPBACK_HOST.test(location.hostname) && /^\d+$/.test(params.get("capture_audit") || "")) {
  Object.defineProperty(globalThis, "__corechatAuditGameAction", {
    value: Object.freeze({
      driver: "runAction",
      ready: () => Boolean(
        session
        && session.status === "active"
        && !session.state?.completed
        && !terminalSessionError
        && !busy
      ),
      run: (actionType, payload = {}) => runAction(
        String(actionType || ""),
        payload && typeof payload === "object" ? payload : {},
      ),
    }),
    configurable: false,
    enumerable: false,
    writable: false,
  });
}



// Block browser image/link ghost dragging, not game clicks or touch scrolling.
document.addEventListener("dragstart", event => {
  if (document.body.dataset.appearance === "classic"
      && event.target?.closest?.("#play-surface")) {
    event.preventDefault();
  }
});
