import { gameViewStorage } from "./game-view-storage.js?v=1dd11e938aa8";
import { originalAudioCatalog, originalVoiceEnabled, originalRollAnnouncement, originalPlacementCue, originalReminderPlan, originalPointRoll } from "./classic-game-audio.js?v=b0d39dcd022c";
import { createCardBotController } from "./uno-bot-controller.js?v=d1c32d65552f";
import { createBackgammonBotController } from "./backgammon-bot-controller.js?v=65d896c667ff";
import { createCheckersBotController } from "./checkers-bot-controller.js?v=729241a2b45b";
import { createChessBotController } from "./chess-bot-controller.js?v=4f211cad590f";
import { classicSourceMap as immutableClassicSourceMap } from "./classic-source-maps.js?v=f69e083b7fee";
import { viewerHeightFitEnabled, setViewerHeightFit, installViewportHeightFit } from "./viewport-height-fit.js?v=f9547db55052";

import { bindGameAvatar } from "./game-avatar.js?v=20260913-room-avatars";
import { renderGameSeatControls, renderGameBotControls } from "../assets/js/runtime/game/renderers/game-seat-controls.js?v=8829b14ae801";

const params = new URLSearchParams(location.search);
const context = Object.freeze({
  extensionId: document.body.dataset.extension || "",
  gameKey: document.body.dataset.gameKey || "",
  fallbackName: document.body.dataset.gameName || "Installed game",
  sessionId: params.get("session_id") || "",
  participantId: Number(params.get("participant_id") || params.get("user") || 0),
  joinToken: params.get("join_token") || "",
  gameSessionId: params.get("game_session_id") || params.get("lobby") || "",
  csrf: params.get("csrf") || ""
});
const connectionEpoch = crypto.randomUUID();

function checkersCaptureAuditId() {
  if (context.extensionId !== "checkers" || !/^(?:127(?:\.\d{1,3}){3}|localhost)$/.test(location.hostname)) return "";
  try {
    const parentParams = new URLSearchParams(window.parent.location.search);
    return /^\d+$/.test(parentParams.get("capture_audit") || "") ? parentParams.get("capture_audit") : "";
  } catch {
    return "";
  }
}

function chessCaptureAuditId() {
  if (context.extensionId !== "chess" || !/^(?:127(?:\.\d{1,3}){3}|localhost)$/.test(location.hostname)) return "";
  const ownAuditId = params.get("capture_audit") || "";
  if (/^\d+$/.test(ownAuditId)) return ownAuditId;
  if (params.get("game_session_id") === "543c4f07-33b9-49e8-8c6a-c8996a794295") return "1";
  try {
    const parentParams = new URLSearchParams(window.parent.location.search);
    return /^\d+$/.test(parentParams.get("capture_audit") || "") ? parentParams.get("capture_audit") : "";
  } catch {
    return "";
  }
}

function unoCallAuditId() {
  if (context.extensionId !== "uno" || !/^(?:127(?:\.\d{1,3}){3}|localhost)$/.test(location.hostname)) return "";
  const auditId = params.get("uno_call_audit") || "";
  return /^\d+$/.test(auditId) ? auditId : "";
}

function spadesNilAuditId() {
  if (context.extensionId !== "spades" || !/^(?:127(?:\.\d{1,3}){3}|localhost)$/.test(location.hostname)) return "";
  const auditId = params.get("spades_nil_audit") || "";
  return /^\d+$/.test(auditId) ? auditId : "";
}

const root = document.getElementById("game-root");
let session = null;
let records = null;
let recordsScope = "";
let recordsRequestSerial = 0;
let recordsRequest = null;
let recordsTerminalKey = "";
let recordsError = "";
let options = null;
let optionsMutationRevision = 0;
let busy = false;
let pendingActionType = "";
let pollTimer = 0;
let selectedSquare = null;
let selectedPointOrigin = null;
let selectedBattleshipShipId = "";
let dismissedBuiltInBattleshipResultKey = "";
let builtInBattleshipDragState = null;
let battleshipLastTouchShipId = "";
let battleshipLastTouchAt = 0;
let battleshipSuppressClickUntil = 0;
let battleshipSelectionTimer = 0;
let actionStatusError = "";
let actionStatusErrorUntil = 0;
let selectedSpadesCard = null;
let selectedSpadesBid = null;
let selectedSpadesPassCards = [];
let pendingSpadesAutomaticKey = "";
let spadesAutomaticTimer = 0;
let pendingBlackjackAutomaticKey = "";
let blackjackAutomaticTimer = 0;
let selectedBlackjackBet = 50;
let selectedUnoWildCard = "";
let pendingUnoAutomaticKey = "";
let unoAutomaticTimer = 0;
let gameSurfaceVisible = true;
let pendingVisibleReconnect = null;
let visibleReconnectAttemptKey = "";
let terminalSessionError = null;
let lastRematchSuccessorNotice = "";
let surfaceReachabilityObserver = null;
const surfaceReachabilityFrames = new Set();
let previewDestination = null;
let touchPreviewDestination = null;
let openCheckersDrawer = null;
let pendingChessPromotion = null;
let pendingGameEventDialog = null;
let dismissedGameEventDialogKey = "";
let gameEventDialogReturnFocus = null;
let drawProposalReturnFocus = null;
let gameOptionsVisible = false;
let gameOptionsInitialStateResolved = false;
let chessClockRenderTimer = 0;
let chessClockDeadlineTimer = 0;
let chessClockSettlementInFlight = false;
let sharedLifecycleDeadlineTimer = 0;
let sharedLifecycleRenderTimer = 0;
let sharedLifecycleSettlementInFlight = false;
let sessionProjectedAtMs = performance.now();
let boardRenderRevision = 0;
const audioPlayers = new Map();
const BLACKJACK_PUBLIC_SOUND_ROOT = "assets/audio/blackjack";
const BLACKJACK_PUBLIC_SOUNDS = Object.freeze({
  deal: ["card-deal-1.mp3", "card-deal-2.mp3", "card-deal-3.mp3", "card-deal-4.mp3"],
  shuffle: "card-shuffle.mp3",
  reveal: "card-reveal.mp3",
  wager: ["chip-wager-1.mp3", "chip-wager-2.mp3"],
  adjust: "chip-adjust.mp3",
  payout: "chip-payout.mp3",
  stand: "turn-stand.mp3",
  surrender: "hand-surrender.mp3",
  button: "ui-button.mp3",
  win: "result-win.mp3",
  push: "result-push.mp3",
  loss: "result-loss.mp3",
});
const UNO_PUBLIC_SOUND_ROOT = "assets/audio/card-games";
const UNO_PUBLIC_SOUNDS = Object.freeze({
  shuffle: "hearts-shuffle.mp3",
  deal1: "hearts-deal-1.mp3",
  deal2: "hearts-deal-2.mp3",
  draw: "hearts-deal-1.mp3",
  play1: "hearts-play-1.mp3",
  play2: "hearts-play-2.mp3",
  reverse: "hearts-pass.mp3",
  skip: "hearts-trick.wav",
  wild: "hearts-broken.wav",
  declare: "uno-call-plain.wav",
  catch: "hearts-point-trick.wav",
  win: "hearts-win.wav",
  loss: "hearts-loss.wav",
});
const BUILT_IN_PUBLIC_SOUND_ROOT = "assets/audio/built-in-games";
const BUILT_IN_PUBLIC_SOUNDS = Object.freeze({
  select: "ui-select.ogg",
  error: "game-error.ogg",
  success: "game-success.ogg",
  loss: "game-loss.ogg",
  move: "piece-move.ogg",
  capture: "piece-capture.ogg",
  backgammonDice: "backgammon-dice-roll.wav",
  aceyDeucyDice: "acey-deucy-dice-roll.wav",
  aceyDeucy: "acey-deucy-call.wav",
  pointHitToBar: "point-hit-to-bar.wav",
  aceyDeucyBootStomp: "acey-deucy-boot-stomp.wav",
  aceyDeucyBlockedWall: "acey-deucy-blocked-wall.wav",
  aceyDeucyBooted: "acey-deucy-booted.wav",
  gammon: "backgammon-gammon.wav",
  backgammon: "backgammon-backgammon.wav",
  chessCheck: "chess-check.wav",
  chessCheckmate: "chess-checkmate.wav",
  chessPieceSlide: "chess-piece-slide.mp3",
  chessPieceCapture: "chess-piece-capture.mp3",
  cardShuffle: "card-shuffle.ogg",
  cardPlay: "card-play.ogg",
  shipPlace: "ship-place.ogg",
  shipShot: "ship-shot.ogg",
  checkerMissileLaunch: "checker-missile-launch.ogg",
  checkerExplosion: "checker-explosion.ogg",
  checkerExplosionRumble: "checker-explosion-rumble.ogg",
  chessPortalSink: "chess-portal-descent.wav",
  shipMiss: "ship-miss.ogg",
  shipHit: "ship-hit.ogg",
  shipSink: "ship-sink.ogg",
  battleshipPlacement: "battleship-placement.wav",
  battleshipRotation: "battleship-rotation.wav",
  battleshipInvalidPlacement: "battleship-invalid-placement.wav",
  battleshipFleetReady: "battleship-fleet-ready.wav",
  battleshipVictory: "battleship-victory.wav",
  battleshipDefeat: "battleship-defeat.wav",
  battleshipMissileLaunch: "ship-missile-launch.wav",
  battleshipFireHit: "ship-fire-hit.wav",
  battleshipWaterMiss: "ship-water-miss.wav",
  battleshipSinking: "ship-sinking-sequence.wav",
  spadesYourTeamNilFailed: "spades-your-team-nil-failed.wav",
  spadesOtherTeamNilFailed: "spades-other-team-nil-failed.wav",
  spadesNilFailed: "spades-nil-failed.wav",
});
const CORECHAT_GENERATED_BUILT_IN_SOUNDS = new Set([
  BUILT_IN_PUBLIC_SOUNDS.aceyDeucy,
  BUILT_IN_PUBLIC_SOUNDS.pointHitToBar,
  BUILT_IN_PUBLIC_SOUNDS.aceyDeucyBootStomp,
  BUILT_IN_PUBLIC_SOUNDS.aceyDeucyBlockedWall,
  BUILT_IN_PUBLIC_SOUNDS.aceyDeucyBooted,
  BUILT_IN_PUBLIC_SOUNDS.gammon,
  BUILT_IN_PUBLIC_SOUNDS.backgammon,
  BUILT_IN_PUBLIC_SOUNDS.chessCheck,
  BUILT_IN_PUBLIC_SOUNDS.chessCheckmate,
  BUILT_IN_PUBLIC_SOUNDS.battleshipPlacement,
  BUILT_IN_PUBLIC_SOUNDS.battleshipRotation,
  BUILT_IN_PUBLIC_SOUNDS.battleshipInvalidPlacement,
  BUILT_IN_PUBLIC_SOUNDS.battleshipFleetReady,
  BUILT_IN_PUBLIC_SOUNDS.battleshipVictory,
  BUILT_IN_PUBLIC_SOUNDS.battleshipDefeat,
  BUILT_IN_PUBLIC_SOUNDS.battleshipMissileLaunch,
  BUILT_IN_PUBLIC_SOUNDS.battleshipFireHit,
  BUILT_IN_PUBLIC_SOUNDS.battleshipWaterMiss,
  BUILT_IN_PUBLIC_SOUNDS.battleshipSinking,
  BUILT_IN_PUBLIC_SOUNDS.spadesYourTeamNilFailed,
  BUILT_IN_PUBLIC_SOUNDS.spadesOtherTeamNilFailed,
  BUILT_IN_PUBLIC_SOUNDS.spadesNilFailed,
]);
const BUILT_IN_PUBLIC_SOUND_GAMES = new Set([
  "acey-deucy",
  "backgammon-first-party",
  "battleship",
  "checkers",
  "chess",
  "spades",
]);

function backgammonSourceRole(state = session?.state || {}) {
  const turnOrder = Array.isArray(state.turnOrder) ? state.turnOrder.map(Number) : [];
  return turnOrder[1] === currentUserId() ? "role-2" : "role-1";
}

function classicSourceMap(gameId) {
  return immutableClassicSourceMap(
    gameId,
    gameId === "backgammon-first-party" ? backgammonSourceRole() : "role-1",
  );
}
const mediaTrace = [];
const MEDIA_TRACE_LIMIT = 2048;
let musicPlayer = null;
let classicAnimationTimer = 0;
let pendingClassicMotion = null;
let optimisticBuiltInCheckersMotion = null;
let reconciledBuiltInCheckersSound = null;
let optimisticBuiltInChessMotion = null;
let reconciledBuiltInChessSound = null;
let pendingBuiltInPointWin = null;
let builtInPointWinTimer = 0;
const scheduledSoundTimers = new Set();
const classicMotionMediaPreloads = new Map();
let classicMotionMediaReadyKey = "";
let classicMotionMediaReadyPromise = Promise.resolve();
const battleshipPlacementMediaPreloads = new Map();
let battleshipPlacementMediaReadyKey = "";
let battleshipPlacementMediaReadyPromise = Promise.resolve();
let lastDrawProgressAnnouncementKey = "";
let lastSoundedStateVersion = 0;
let settingsDraft = null;
let settingsDraftSha256 = "";
let settingsDraftMode = "";

function setSourceBox(node, box, sourceWidth, sourceHeight) {
  node.style.left = `${(Number(box.x) / sourceWidth) * 100}%`;
  node.style.top = `${(Number(box.y) / sourceHeight) * 100}%`;
  node.style.width = `${(Number(box.width) / sourceWidth) * 100}%`;
  node.style.height = `${(Number(box.height) / sourceHeight) * 100}%`;
  node.dataset.sourceBox = `${box.x},${box.y},${box.width},${box.height}`;
}

function setNestedSourceBox(node, inner, outer) {
  node.style.left = `${((Number(inner.x) - Number(outer.x)) / Number(outer.width)) * 100}%`;
  node.style.top = `${((Number(inner.y) - Number(outer.y)) / Number(outer.height)) * 100}%`;
  node.style.width = `${(Number(inner.width) / Number(outer.width)) * 100}%`;
  node.style.height = `${(Number(inner.height) / Number(outer.height)) * 100}%`;
  node.dataset.sourceBox = `${inner.x},${inner.y},${inner.width},${inner.height}`;
}

function fitClassicAssetToSourcePixels(image, sourceBox, anchor = "center", followArtworkChanges = false) {
  const apply = () => {
    if (!image.naturalWidth || !image.naturalHeight) return;
    image.style.width = `${(image.naturalWidth / Number(sourceBox.width)) * 100}%`;
    image.style.height = `${(image.naturalHeight / Number(sourceBox.height)) * 100}%`;
    image.dataset.sourceAssetSize = `${image.naturalWidth},${image.naturalHeight}`;
  };
  image.classList.add(`is-source-anchored-${anchor}`);
  image.addEventListener("load", apply, { once: !followArtworkChanges });
  if (image.complete) apply();
  return image;
}

function classicDestinationCue(slot, className, sourceBox, anchor = "center") {
  const cue = document.createElement("img");
  const privateSlot = /^gif-/.test(slot);
  // Source-map assets are relative to the game entry, including its ../../.
  // Applying appUrl as well would strip a deployment prefix such as /core/.
  cue.src = privateSlot ? mediaUrl(slot) : new URL(slot, window.location.href).href;
  cue.alt = "";
  cue.decoding = "async";
  cue.draggable = false;
  cue.className = `classic-source-destination-cue ${className}`;
  if (privateSlot) cue.dataset.cueSlot = slot;
  else cue.dataset.cueAsset = slot;
  cue.dataset.cueKind = className;
  fitClassicAssetToSourcePixels(cue, sourceBox, anchor);
  return cue;
}

function playerMembers() {
  return (session?.members || [])
    .filter(member => ["master", "player"].includes(String(member.role || "")))
    .sort((left, right) => Number(left.seat || 0) - Number(right.seat || 0));
}

function appUrl(value) {
  const source = String(value || "").trim();
  if (!source) return "";
  try {
    const applicationRoot = new URL("../../", window.location.href);
    return new URL(source.replace(/^\/+/, ""), applicationRoot).href;
  } catch {
    return source;
  }
}

function appMediaUrl(value) {
  const source = String(value || "").trim();
  if (!source) return "";
  if (source.startsWith("/")) return appUrl(source);
  try {
    return new URL(source, new URL("../../", window.location.href)).href;
  } catch {
    return source;
  }
}

const classicBoardScaleSteps = [1, 1.25, 1.5, 1.75, 2];

function supportsViewerBoardScale() {
  return session?.presentation?.effectivePack === "classic"
    && ["checkers", "chess", "acey-deucy", "backgammon-first-party", "battleship", "spades"].includes(context.extensionId);
}

function defaultClassicBoardScale() {
  return context.extensionId === "chess" ? 1 : 1.5;
}

function classicBoardScaleStorageKey() {
  return `corechat:${context.extensionId}:classic-board-scale`;
}

function classicBoardScaleIndex() {
  const saved = gameViewStorage.getItem(classicBoardScaleStorageKey());
  const stored = Number(saved === null ? defaultClassicBoardScale() : saved);
  const index = classicBoardScaleSteps.findIndex(scale => Math.abs(scale - stored) < 0.001);
  return index >= 0 ? index : 0;
}

function classicBoardScale() {
  return classicBoardScaleSteps[classicBoardScaleIndex()];
}

function changeClassicBoardScale(direction) {
  const current = classicBoardScaleIndex();
  const next = Math.max(0, Math.min(classicBoardScaleSteps.length - 1, current + Number(direction || 0)));
  gameViewStorage.setItem(classicBoardScaleStorageKey(), String(classicBoardScaleSteps[next]));
  render();
}

function applyClassicBoardScale(board, source) {
  const nativeWidth = Math.max(1, Number(source?.canvas?.width || 460));
  const scale = classicBoardScale();
  board.style.width = `${Math.round(nativeWidth * scale)}px`;
  board.style.maxWidth = "100%";
  board.dataset.nativeCanvasWidth = String(nativeWidth);
  board.dataset.viewerScale = String(scale);
}


const builtInBoardScaleSteps = [0.5, 0.75, 1, 1.25, 1.5];
let builtInBoardSizeCleanup = null;
function supportsBuiltInBoardScale() {
  return Boolean(session) && session.presentation?.effectivePack !== "classic"
    && ["battleship", "puppy-panic"].includes(context.extensionId);
}
function builtInBoardScaleIndex() {
  try {
    const saved = gameViewStorage.getItem("corechat:" + context.extensionId + ":built-in-board-scale");
    const index = builtInBoardScaleSteps.indexOf(saved === null ? 1 : Number(saved));
    return index < 0 ? 2 : index;
  } catch { return 2; }
}
function changeBuiltInBoardScale(direction) {
  const index = Math.max(0, Math.min(builtInBoardScaleSteps.length - 1, builtInBoardScaleIndex() + direction));
  try { gameViewStorage.setItem("corechat:" + context.extensionId + ":built-in-board-scale", String(builtInBoardScaleSteps[index])); }
  catch { return; }
  render();
}
function supportsBuiltInHeightFit() {
  return Boolean(session) && session.presentation?.effectivePack !== "classic"
    && ["battleship", "puppy-panic", "chess", "checkers", "spades", "hearts"].includes(context.extensionId);
}
function builtInHeightFitEnabled() {
  return viewerHeightFitEnabled(context.extensionId, ["battleship", "puppy-panic"].includes(context.extensionId));
}
function installBuiltInBoardSize(board, host) {
  builtInBoardSizeCleanup?.();
  builtInBoardSizeCleanup = null;
  if (!supportsBuiltInHeightFit()) return;
  builtInBoardSizeCleanup = installViewportHeightFit(board, host, {
    enabled: builtInHeightFitEnabled,
    scale: () => supportsBuiltInBoardScale() ? builtInBoardScaleSteps[builtInBoardScaleIndex()] : 1,
    maximumWidth: supportsBuiltInBoardScale() ? 1120 : 0,
    reserveBottom: () => 24 + Number(el("controls")?.offsetHeight || 0),
  });
}

function memberAvatar(member, className = "") {
  const image = document.createElement("img");
  image.className = className;
  const fallbackUrl = appMediaUrl(member?.avatarFallbackUrl) || "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='32' fill='%230f2e33'/%3E%3Ccircle cx='32' cy='23' r='11' fill='%23808f97'/%3E%3Cpath d='M17 54c0-10 9-17 15-17s15 7 15 17' fill='%23808f97'/%3E%3C/svg%3E";
  image.alt = "";
  image.decoding = "sync";
  bindGameAvatar(image, { ...member, avatarFallbackUrl: fallbackUrl });
  return image;
}

function appendClassicPlayerAvatars(stage, gameId, orderedMembers = playerMembers()) {
  const geometry = classicSourceMap(gameId);
  if (!geometry?.avatarWells) return;
  orderedMembers.slice(0, geometry.avatarWells.length).forEach((member, index) => {
    const box = geometry.avatarWells[index];
    const frame = geometry.avatarFrames?.[index];
    const borderDefinition = Array.isArray(geometry.avatarBorders) ? geometry.avatarBorders[index] : null;
    if (!box || !member) return;
    if (frame && borderDefinition) {
      const activePhase = !borderDefinition.activePhase || safe(session?.state?.phase) === borderDefinition.activePhase;
      const illuminated = activePhase && !session?.state?.completed
        && Number(session?.turnUserId || 0) === Number(member.userId || 0);
      const frameSlot = illuminated ? borderDefinition.illuminated : borderDefinition.normal;
      const border = mediaImage(frameSlot, "classic-player-avatar-frame", "");
      setSourceBox(border, frame, geometry.canvas.width, geometry.canvas.height);
      border.dataset.avatarFrameState = illuminated ? "illuminated" : "normal";
      border.dataset.avatarFrameSlot = frameSlot;
      border.dataset.avatarFrameBox = JSON.stringify(frame);
      border.dataset.avatarApertureBox = JSON.stringify(box);
      border.dataset.identityBinding = `${Number(member.userId || 0)}:${Number(member.seat || index + 1)}:${index + 1}`;
      stage.append(border);
    }
    const well = make("div", `classic-player-avatar is-seat-${Number(member.seat || index + 1)}`);
    setSourceBox(well, box, geometry.canvas.width, geometry.canvas.height);
    well.setAttribute("role", "img");
    well.setAttribute("aria-label", `${member.displayName || `Player ${index + 1}`} avatar, ${playerPositionLabel(member)}`);
    well.dataset.userId = String(Number(member.userId || 0));
    well.dataset.seat = String(Number(member.seat || index + 1));
    well.dataset.avatarWell = String(index + 1);
    well.dataset.avatarFrameBox = frame ? JSON.stringify(frame) : "";
    well.dataset.avatarApertureBox = JSON.stringify(box);
    well.dataset.identityBinding = `${Number(member.userId || 0)}:${Number(member.seat || index + 1)}:${index + 1}`;
    const avatar = memberAvatar(member, "classic-player-avatar-image");
    const imageBox = geometry.avatarImageBoxes?.[index] || box;
    setNestedSourceBox(avatar, imageBox, box);
    well.dataset.avatarImageBox = JSON.stringify(imageBox);
    well.append(avatar);
    stage.append(well);
  });
}

function classicMembersBySourceIdentity(gameId) {
  const members = playerMembers();
  const state = session?.state || {};
  if (gameId === "chess") {
    const viewerSide = safe(state.colorAssignments?.[String(currentUserId())]);
    const sourceOrder = viewerSide === "b" ? ["b", "w"] : ["w", "b"];
    return sourceOrder.map(side => members.find(member => state.colorAssignments?.[String(member.userId)] === side)).filter(Boolean);
  }
  if (gameId === "checkers") {
    const viewerSide = safe(state.sideAssignments?.[String(currentUserId())]);
    const sourceOrder = viewerSide === "a" ? ["a", "b"] : ["b", "a"];
    return sourceOrder.map(side => members.find(member => state.sideAssignments?.[String(member.userId)] === side)).filter(Boolean);
  }
  if (gameId === "battleship") {
    const viewer = members.find(member => Number(member.userId) === currentUserId());
    if (viewer) return [viewer, members.find(member => member !== viewer)].filter(Boolean);
    const turnOrder = Array.isArray(state.turnOrder) ? state.turnOrder.map(Number) : [];
    const ordered = turnOrder.map(userId => members.find(member => Number(member.userId) === userId)).filter(Boolean);
    return [...ordered, ...members.filter(member => !ordered.includes(member))];
  }
  if (gameId === "backgammon-first-party") {
    const turnOrder = Array.isArray(state.turnOrder) ? state.turnOrder.map(Number) : [];
    const sourceOrder = backgammonSourceRole(state) === "role-2" ? [...turnOrder].reverse() : turnOrder;
    return sourceOrder.map(userId => members.find(member => Number(member.userId) === userId)).filter(Boolean);
  }
  if (gameId === "acey-deucy") {
    const turnOrder = Array.isArray(state.turnOrder) ? state.turnOrder.map(Number) : [];
    return turnOrder.map(userId => members.find(member => Number(member.userId) === userId)).filter(Boolean);
  }
  return members;
}

Object.defineProperty(window, "__ocxGameMediaTrace", { value: mediaTrace, writable: false });

root.className = "game";
root.innerHTML = `
  <header class="game-header">
    <div><h1 id="game-title"></h1></div>
    <div class="header-actions">
      <button id="game-options-toggle" type="button" aria-expanded="false" aria-controls="game-settings">Show Game Options</button>
      <button id="rules-button" type="button" class="rules-button" aria-expanded="false" aria-controls="rules-panel">Rules</button>
      <button id="accessibility-button" type="button" aria-expanded="false" aria-controls="accessibility-panel">Accessibility</button>
      <button id="score-records-toggle" type="button" aria-expanded="false" aria-controls="score-records">Show Score &amp; Records</button>
    </div>
  </header>
  <p id="status" class="sr-only" role="status" aria-live="polite" aria-atomic="true">Loading the game…</p>
  <p id="game-action-error" class="game-action-error" role="alert" aria-live="assertive" aria-atomic="true" hidden></p>
  <section id="game-settings" class="game-settings" tabindex="-1" aria-labelledby="game-settings-heading" hidden>
    <div class="panel-heading"><div><h2 id="game-settings-heading">Game Options</h2><p id="game-settings-status" class="minor"></p></div></div>
    <div id="game-settings-body"></div>
  </section>
  <section id="rules-panel" class="rules-panel" tabindex="-1" aria-labelledby="rules-panel-heading" hidden aria-live="polite"></section>
  <section id="accessibility-panel" class="accessibility-panel" tabindex="-1" aria-labelledby="accessibility-panel-heading" hidden></section>
  <div class="layout" data-score-records-visible="false">
    <section id="surface" class="surface" aria-labelledby="surface-heading">
      <h2 id="surface-heading" class="sr-only">Game</h2>
      <p id="turn" class="sr-only" role="status" aria-live="polite" aria-atomic="true">Waiting for the next turn.</p>
      <div id="player-status-strip" class="player-status-strip" aria-label="Player move and timer status" hidden></div>
      <div id="board-host"></div>
      <div id="board-external-controls" class="board-external-controls" role="group" aria-label="Below-board game controls">
        <div id="external-pause" class="external-pause"></div>
        <div id="external-resign" class="external-resign"></div>
        <div id="surface-options" class="external-effect-control"></div>
        <div id="surface-options-visual" class="external-effect-control"></div>
        <div id="surface-options-music" class="external-music-control"></div>
      </div>
      <div id="controls" class="controls"></div>
    </section>
    <aside id="score-records" class="records-panel" tabindex="-1" aria-labelledby="score-records-heading" hidden>
      <div class="panel-heading"><div><h2 id="score-records-heading">Score &amp; Records</h2><p class="minor">Live state and Recorded-play history.</p></div></div>
      <section aria-labelledby="live-score-heading"><h3 id="live-score-heading">Live game state</h3><div id="live-score"></div></section>
      <section id="draw-progress" class="draw-progress" aria-labelledby="draw-progress-heading" hidden><h3 id="draw-progress-heading">Draw progress</h3><div id="draw-progress-body"></div><p id="draw-progress-status" class="sr-only" role="status" aria-live="polite" aria-atomic="true"></p></section>
      <section aria-labelledby="record-heading"><h3 id="record-heading">Recorded results</h3><div id="records" class="records-grid"></div><p class="minor">Practice Mode never changes Recorded results.</p></section>
    </aside>
  </div>`;

const el = id => document.getElementById(id);
const safe = value => String(value ?? "");
const make = (tag, className = "", text = "") => {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== "") node.textContent = text;
  return node;
};
const HEARTS_PUBLIC_SOUND_ROOT = "assets/audio/card-games";
const HEARTS_PUBLIC_SOUNDS = {
  deal1: "hearts-deal-1.mp3",
  deal2: "hearts-deal-2.mp3",
  play1: "hearts-play-1.mp3",
  play2: "hearts-play-2.mp3",
  shuffle: "hearts-shuffle.mp3",
  pass: "hearts-pass.mp3",
  trick: "hearts-trick.wav",
  pointTrick: "hearts-point-trick.wav",
  broken: "hearts-broken.wav",
  queen: "hearts-queen-spades.wav",
  moon: "hearts-moon.wav",
  win: "hearts-win.wav",
  loss: "hearts-loss.wav",
};
const LOOPBACK_REQUEST_LOCK = "corechat-loopback-single-worker-http";
const LOOPBACK_HOST = /^(?:127(?:\.\d{1,3}){3}|localhost)$/i;
const LOOPBACK_REQUEST_LOCK_WAIT_MS = 2000;
const GAME_REQUEST_TIMEOUT_MS = LOOPBACK_HOST.test(location.hostname) ? 8000 : 30000;

async function withLoopbackRequestLock(callback) {
  const locks = globalThis.navigator?.locks;
  if (!LOOPBACK_HOST.test(location.hostname) || typeof locks?.request !== "function") {
    return callback();
  }
  if (typeof globalThis.AbortController !== "function") {
    return locks.request(LOOPBACK_REQUEST_LOCK, { mode: "exclusive" }, callback);
  }
  const controller = new AbortController();
  let acquired = false;
  let waitExpired = false;
  const timeout = setTimeout(() => {
    waitExpired = true;
    controller.abort();
  }, LOOPBACK_REQUEST_LOCK_WAIT_MS);
  try {
    return await locks.request(
      LOOPBACK_REQUEST_LOCK,
      { mode: "exclusive", signal: controller.signal },
      async () => {
        acquired = true;
        clearTimeout(timeout);
        return callback();
      },
    );
  } catch (error) {
    if (!acquired && waitExpired && controller.signal.aborted) {
      // An abandoned tab must not permanently strand every game request for
      // this loopback origin. Request ids and server-side transactions still
      // own idempotency and contention when this bounded fallback is needed.
      return callback();
    }
    throw error;
  } finally {
    clearTimeout(timeout);
  }
}

async function gameFetch(input, init = {}, consumeJson = false) {
  if (typeof globalThis.AbortController !== "function") {
    const response = await fetch(input, init);
    return consumeJson ? { response, data: await readGameResponseJson(response) } : response;
  }
  const controller = new AbortController();
  const callerSignal = init.signal;
  let expired = false;
  const abortError = () => {
    const error = new Error("The game request was cancelled.");
    error.name = "AbortError";
    return error;
  };
  let rejectInterrupted;
  const interrupted = new Promise((resolve, reject) => { rejectInterrupted = reject; });
  const onAbort = () => rejectInterrupted(abortError());
  const abortFromCaller = () => controller.abort(callerSignal?.reason);
  controller.signal.addEventListener("abort", onAbort, { once: true });
  if (callerSignal?.aborted) abortFromCaller();
  else callerSignal?.addEventListener?.("abort", abortFromCaller, { once: true });
  const timeout = setTimeout(() => {
    expired = true;
    controller.abort();
  }, GAME_REQUEST_TIMEOUT_MS);
  try {
    // Keep the same deadline through body consumption. Racing also settles a
    // body reader that ignores abort; its late result cannot reach the caller.
    // This boundary never retries a request, especially an uncertain mutation.
    return await Promise.race([interrupted, (async () => {
      if (controller.signal.aborted) throw abortError();
      const response = await fetch(input, { ...init, signal: controller.signal });
      return consumeJson ? { response, data: await readGameResponseJson(response) } : response;
    })()]);
  } catch (error) {
    if (!expired) throw error;
    const timeoutError = new Error("The game server did not respond in time.");
    timeoutError.code = "GAME_REQUEST_TIMEOUT";
    timeoutError.retryable = true;
    timeoutError.cause = error;
    throw timeoutError;
  } finally {
    clearTimeout(timeout);
    controller.signal.removeEventListener("abort", onAbort);
    callerSignal?.removeEventListener?.("abort", abortFromCaller);
  }
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

function assertGameSessionEnvelope(value, httpStatus = 0) {
  const reason = gameSessionEnvelopeProblem(value)
    || (value.publicId !== context.gameSessionId ? "session-id-mismatch" : "");
  if (!reason) return;
  const error = new Error("The game server returned an invalid game session.");
  error.code = "GAME_STATE_INVALID";
  error.httpStatus = Number(httpStatus || 0);
  error.retryable = false;
  error.facts = { validationReason: reason };
  throw error;
}

async function readGameResponseJson(response) {
  try {
    return await response.json();
  } catch (cause) {
    // A failed body transport is not malformed JSON. Preserve active network
    // failures and cancellation for the request owner and diagnostic observer.
    if (cause?.name !== "SyntaxError") throw cause;
    const error = new Error("The game server returned an invalid JSON response.");
    error.code = "GAME_RESPONSE_INVALID_JSON";
    error.httpStatus = Number(response.status || 0);
    error.retryable = error.httpStatus === 503;
    error.facts = {};
    throw error;
  }
}

async function apiGet(action, extra = {}) {
  if (terminalSessionError) throw terminalSessionError;
  const request = async () => {
    if (terminalSessionError) throw terminalSessionError;
    const query = new URLSearchParams({
      action, session_id: context.sessionId, participant_id: String(context.participantId),
      join_token: context.joinToken, game_session_id: context.gameSessionId, ...extra
    });
    const { response, data } = await gameFetch(`../../api/game_framework.php?${query}`, { cache: "no-store", credentials: "same-origin" }, true);
    if (action === "session" && response.ok && !data?.error) assertGameSessionEnvelope(data, response.status);
    if (!response.ok || data.error) {
      const error = new Error(data.error || "The game could not be loaded.");
      error.code = String(data.code || "");
      error.httpStatus = Number(response.status || 0);
      error.retryable = data.retryable === true || Number(response.status) === 503;
      error.facts = data;
      throw error;
    }
    return data;
  };
  // Records must not occupy the local gameplay request queue either.
  return action === "records" ? request() : withLoopbackRequestLock(request);
}

async function apiPost(action, body = {}, requestOptions = {}) {
  if (terminalSessionError) throw terminalSessionError;
  const request = async () => {
    if (terminalSessionError) throw terminalSessionError;
    const { response, data } = await gameFetch("../../api/game_framework.php", {
      method: "POST", credentials: "same-origin", cache: "no-store",
      keepalive: requestOptions.keepalive === true,
      headers: { "Content-Type": "application/json", "X-CSRF-Token": context.csrf },
      body: JSON.stringify({
        action, session_id: context.sessionId, participant_id: context.participantId,
        join_token: context.joinToken, game_session_id: context.gameSessionId,
        _csrf: context.csrf, ...body
      })
    }, true);
    if (!response.ok || data.error) {
      const error = new Error(data.error || "The game action could not be completed.");
      error.code = String(data.code || "");
      error.httpStatus = Number(response.status || 0);
      error.retryable = data.retryable === true || Number(response.status) === 503;
      error.facts = data;
      throw error;
    }
    return data;
  };
  return requestOptions.keepalive === true ? request() : withLoopbackRequestLock(request);
}

function canonicalize(value) {
  if (Array.isArray(value)) return value.map(canonicalize);
  if (value && typeof value === "object") return Object.keys(value).sort().reduce((result, key) => {
    result[key] = canonicalize(value[key]);
    return result;
  }, {});
  return value;
}

async function sha256(value) {
  const bytes = new TextEncoder().encode(JSON.stringify(canonicalize(value)));
  const digest = await crypto.subtle.digest("SHA-256", bytes);
  return [...new Uint8Array(digest)].map(byte => byte.toString(16).padStart(2, "0")).join("").toUpperCase();
}

function requestId(prefix) { return `${prefix}-${crypto.randomUUID()}`; }

let pendingSquareMove = null;

async function resetCheckersCaptureAuditFixture() {
  const auditId = checkersCaptureAuditId();
  if (!auditId || busy) return;
  busy = true;
  render();
  try {
    const response = await fetch(appUrl("framework/tmp/checkers-capture-audit-reset.php"), {
      method: "POST",
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": context.csrf },
      body: JSON.stringify({
        _csrf: context.csrf,
        audit_id: auditId,
        session_id: context.sessionId,
        join_token: context.joinToken,
        game_session_id: context.gameSessionId,
      }),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || result.error) {
      throw new Error(result.error || "The test move could not be reset.");
    }
    pendingSquareMove = null;
    optimisticBuiltInCheckersMotion = null;
    reconciledBuiltInCheckersSound = null;
    stopClassicAnimation("checkers-capture-audit-reset");
    selectedSquare = null;
    previewDestination = null;
    touchPreviewDestination = null;
    pendingGameEventDialog = null;
    dismissedGameEventDialogKey = "";
    await refreshSession(false, result.session || null);
  } catch (error) {
    actionStatusError = String(error?.message || "The test move could not be reset.");
    actionStatusErrorUntil = Date.now() + 8000;
  } finally {
    busy = false;
    render();
  }
}

async function resetChessCaptureAuditFixture() {
  const auditId = chessCaptureAuditId();
  if (!auditId || busy) return;
  busy = true;
  render();
  try {
    const response = await fetch(appUrl("framework/tmp/chess-capture-audit-reset.php"), {
      method: "POST",
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": context.csrf },
      body: JSON.stringify({
        _csrf: context.csrf,
        audit_id: auditId,
        session_id: context.sessionId,
        join_token: context.joinToken,
        game_session_id: context.gameSessionId,
      }),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || result.error || !result.session) {
      throw new Error(result.error || "The test move could not be reset.");
    }
    pendingSquareMove = null;
    optimisticBuiltInChessMotion = null;
    reconciledBuiltInChessSound = null;
    stopClassicAnimation("chess-capture-audit-reset");
    selectedSquare = null;
    previewDestination = null;
    touchPreviewDestination = null;
    pendingChessPromotion = null;
    pendingGameEventDialog = null;
    dismissedGameEventDialogKey = "";
    await refreshSession(false, result.session);
  } catch (error) {
    actionStatusError = String(error?.message || "The test move could not be reset.");
    actionStatusErrorUntil = Date.now() + 8000;
  } finally {
    busy = false;
    render();
  }
}

async function resetUnoCallAuditFixture() {
  const auditId = unoCallAuditId();
  if (!auditId || busy) return;
  busy = true;
  render();
  try {
    const response = await fetch(appUrl("framework/tmp/uno-call-audit-reset.php"), {
      method: "POST",
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": context.csrf },
      body: JSON.stringify({
        _csrf: context.csrf,
        audit_id: auditId,
        session_id: context.sessionId,
        join_token: context.joinToken,
        game_session_id: context.gameSessionId,
      }),
    });
    const rawResult = await response.text();
    let result = {};
    try {
      result = JSON.parse(rawResult);
    } catch {
      result = {};
    }
    document.body.dataset.unoAuditTransport = JSON.stringify({
      status: response.status,
      preview: rawResult.slice(0, 360),
    });
    if (!response.ok || result.error || !result.session) {
      throw new Error(result.error || `The UNO call test could not be reset (${response.status}).`);
    }
    selectedUnoWildCard = "";
    pendingUnoAutomaticKey = "";
    pendingGameEventDialog = null;
    dismissedGameEventDialogKey = "";
    document.body.dataset.unoAuditResult = JSON.stringify({ ok: true, auditId: result.auditId || auditId });
    await refreshSession(false, result.session);
  } catch (error) {
    document.body.dataset.unoAuditResult = JSON.stringify({ ok: false, error: String(error?.message || error || "unknown") });
    actionStatusError = String(error?.message || "The UNO call test could not be reset.");
    actionStatusErrorUntil = Date.now() + 8000;
  } finally {
    busy = false;
    render();
  }
}

async function triggerSpadesNilAuditFixture() {
  const auditId = spadesNilAuditId();
  if (!auditId || busy) return;
  busy = true;
  render();
  try {
    const response = await fetch(appUrl("framework/tmp/spades-nil-audit-trigger.php"), {
      method: "POST",
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": context.csrf },
      body: JSON.stringify({
        _csrf: context.csrf,
        audit_id: auditId,
        nil_bidder_user_id: Number(params.get("nil_bidder") || currentUserId()),
        session_id: context.sessionId,
        join_token: context.joinToken,
        game_session_id: context.gameSessionId,
      }),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || result.error || !result.session) {
      throw new Error(result.error || "The Spades Nil test could not be triggered.");
    }
    document.body.dataset.spadesAuditResult = JSON.stringify({ ok: true, auditId: result.auditId || auditId });
    await refreshSession(false, result.session);
  } catch (error) {
    document.body.dataset.spadesAuditResult = JSON.stringify({ ok: false, error: String(error?.message || error || "unknown") });
    actionStatusError = String(error?.message || "The Spades Nil test could not be triggered.");
    actionStatusErrorUntil = Date.now() + 8000;
  } finally {
    busy = false;
    render();
  }
}

function battleshipPlacementVersionConflict(error, actionType) {
  return context.extensionId === "battleship"
    && ["manual-place", "auto-place", "start"].includes(actionType)
    && error?.httpStatus === 409
    && error?.code === "MULTIPLAYER_GAME_STATE_STALE";
}

function setGameActionPendingPresentation(pending) {
  const value = pending ? "true" : "false";
  document.body.dataset.gameActionPending = value;
  root?.setAttribute("aria-busy", value);
}

let queuedArcadeLifecycleAction = null;
async function performAction(actionType, payload = {}, randomnessPurpose = "") {
  if (busy && session && ["tetris-versus", "space-invasion"].includes(context.extensionId)
      && ["pause-game", "accept-pause", "decline-pause", "pause-for-reconnect", "start-resume", "resume-now", "resign"].includes(actionType)) {
    if (queuedArcadeLifecycleAction) return queuedArcadeLifecycleAction.promise;
    let resolve;
    const promise = new Promise(done => { resolve = done; });
    queuedArcadeLifecycleAction = { actionType, payload, randomnessPurpose, publicId: session.publicId, resolve, promise };
    return promise;
  }
  if (busy || !session) return;
  let succeeded = false;
  let restoreFocusAfterActionRender = false;
  actionStatusError = "";
  actionStatusErrorUntil = 0;
  pendingActionType = String(actionType || "");
  busy = true;
  setGameActionPendingPresentation(true);
  try {
    if (((actionType === "move" && context.extensionId === "checkers")
      || (actionType === "attack" && context.extensionId === "battleship"))
      && session?.presentation?.effectivePack === "classic") {
      await ensureClassicMotionMediaReady();
    }
    if (actionType === "auto-place" && context.extensionId === "battleship"
      && session?.presentation?.effectivePack === "classic") {
      await ensureBattleshipPlacementMediaReady();
    }
    let randomnessRequestId = "";
    if (randomnessPurpose) {
      randomnessRequestId = requestId(`${context.extensionId}-random`);
      if (session.mode === "practice") {
        const reveal = { nonce: crypto.randomUUID(), generatedAt: new Date().toISOString() };
        await apiPost("randomness", { request_id: randomnessRequestId, commitment_sha256: await sha256(reveal), purpose: randomnessPurpose });
        await apiPost("reveal-practice-randomness", { request_id: randomnessRequestId, reveal });
      } else {
        await apiPost("randomness", { request_id: randomnessRequestId, purpose: randomnessPurpose });
      }
    }
    const expectedVersion = Number(session.stateVersion);
    const settlementActorId = context.extensionId === "puppy-panic"
      && actionType === "settle-action" && !randomnessPurpose
      ? currentUserId() : 0;
    const actionRequestId = Number.isSafeInteger(settlementActorId) && settlementActorId > 0
      ? `puppy-panic-settle-${await sha256({
        gameSessionId: String(context.gameSessionId || ""),
        actorUserId: settlementActorId, expectedVersion, actionType, payload,
      })}`
      : requestId(`${context.extensionId}-action`);
    const actionResult = await apiPost("extension-action", {
      request_id: actionRequestId, expected_version: expectedVersion,
      action_type: actionType, payload, ...(randomnessRequestId ? { randomness_request_id: randomnessRequestId } : {})
    });
    const builtInSquareMoveRelease = actionType === "move"
      && ["checkers", "chess"].includes(context.extensionId)
      && session?.presentation?.effectivePack !== "classic";
    const classicChessMoveRelease = actionType === "move"
      && context.extensionId === "chess"
      && session?.presentation?.effectivePack === "classic";
    const pointGameMoveRelease = actionType === "move"
      && ["acey-deucy", "backgammon-first-party"].includes(context.extensionId);
    if (builtInSquareMoveRelease || classicChessMoveRelease || pointGameMoveRelease) {
      playClassicSelectionCue("wav-unlock", "successful-move-release");
    }
    const deferCheckersRecords = actionType === "move" && context.extensionId === "checkers";
    const deferArcadeRecords = ["tetris-versus", "space-invasion"].includes(context.extensionId)
      && !actionResult?.terminal;
    const refreshResult = await refreshSession(
      !deferCheckersRecords && !deferArcadeRecords,
      actionResult?.session || null,
      { deferRender: true },
    );
    restoreFocusAfterActionRender = refreshResult?.restoreFocusAfterOptionsClose === true;
    if (deferCheckersRecords && session?.state?.completed) {
      setTimeout(() => refreshSession(true, session).catch(error => {
        trace("records-refresh-unavailable", null, { errorName: String(error?.name || "Error") });
      }), 0);
    }
    succeeded = true;
  } catch (error) {
    if (actionType === "move" && ((context.extensionId === "checkers" && optimisticBuiltInCheckersMotion)
      || (context.extensionId === "chess" && optimisticBuiltInChessMotion))) {
      optimisticBuiltInCheckersMotion = null;
      reconciledBuiltInCheckersSound = null;
      optimisticBuiltInChessMotion = null;
      reconciledBuiltInChessSound = null;
      stopClassicAnimation("optimistic-square-action-rejected");
    }
    // A rejected or transport-uncertain action must release the pending UI and
    // reconcile against the authoritative session immediately. This covers a
    // response lost after commit as well as a bounded database-busy response.
    const stalePlacement = battleshipPlacementVersionConflict(error, actionType);
    const rejectedSession = session;
    let latestPlacementStateAvailable = false;
    try {
      const refreshResult = await refreshSession(false, null, { deferRender: true });
      restoreFocusAfterActionRender ||= refreshResult?.restoreFocusAfterOptionsClose === true;
      latestPlacementStateAvailable = session !== rejectedSession
        && session?.publicId === rejectedSession?.publicId;
    } catch (refreshError) {
      trace("action-failure-reconciliation-unavailable", null, {
        actionType,
        errorName: String(refreshError?.name || "Error"),
      });
    }
    actionStatusError = stalePlacement
      ? (latestPlacementStateAvailable
        ? "The game changed before this action could be applied. The board is up to date; review it before continuing."
        : "The game changed before this action could be applied. Waiting for the latest board; do not repeat the action yet.")
      : String(error?.message || "The game action could not be completed.");
    actionStatusErrorUntil = Date.now() + 8000;
    if (!stalePlacement && context.extensionId === "battleship"
      && session?.presentation?.effectivePack !== "classic"
      && ["manual-place", "auto-place", "start"].includes(actionType)) {
      playBuiltInGameSound(
        BUILT_IN_PUBLIC_SOUNDS.battleshipInvalidPlacement,
        "authoritative-placement-rejected",
        .8,
      );
    } else {
      trace("effect-suppressed", null, { reason: "no-source-backed-invalid-action-sound" });
    }
  } finally {
    pendingActionType = "";
    busy = false;
    setGameActionPendingPresentation(false);
    render();
    if (restoreFocusAfterActionRender) el("game-options-toggle")?.focus({ preventScroll: true });
    const queued = queuedArcadeLifecycleAction;
    queuedArcadeLifecycleAction = null;
    if (queued) {
      if (queued.publicId === session?.publicId && !session?.state?.completed) {
        void performAction(queued.actionType, queued.payload, queued.randomnessPurpose).then(queued.resolve, () => queued.resolve(false));
      } else queued.resolve(false);
    }
  }
  return succeeded;
}

function clearSpadesAutomaticAction() {
  clearTimeout(spadesAutomaticTimer);
  spadesAutomaticTimer = 0;
  pendingSpadesAutomaticKey = "";
}

function canCoordinateSpadesAutomaticAction(phase) {
  if (phase !== "settling") return canAct();
  if (!gameLifecycleAvailable()) return false;
  const state = session?.state || {};
  const humanPlayers = (Array.isArray(state.turnOrder) ? state.turnOrder : [])
    .map(Number).filter(userId => Number.isSafeInteger(userId) && userId > 0);
  const connectedPlayers = humanPlayers.filter(userId =>
    state?._framework?.players?.[String(userId)]?.disconnected !== true);
  return Number((connectedPlayers[0] ?? humanPlayers[0]) || 0) === currentUserId();
}

function spadesAutomaticSettlementDelayMs(currentSession, localNow = Date.now()) {
  const settleAfterUnixMs = Number(currentSession?.state?.settlement?.settleAfterUnixMs || 0);
  const serverNowUnixMs = Number(currentSession?.nowUnixMs || 0);
  const referenceNow = Number.isFinite(serverNowUnixMs) && serverNowUnixMs > 0
    ? serverNowUnixMs
    : Number(localNow || 0);
  return Math.max(0, settleAfterUnixMs - referenceNow + 18);
}

function scheduleSpadesAutomaticAction() {
  const phase = String(session?.state?.phase || "");
  const cancelPending = () => { if (spadesAutomaticTimer) clearSpadesAutomaticAction(); };
  if (!gameSurfaceVisible || context.extensionId !== "spades" || !session || busy || !canCoordinateSpadesAutomaticAction(phase) || session.state?.completed || !["deal","settling"].includes(phase)) {
    cancelPending();
    return;
  }
  const key = `${phase}:${Number(session.stateVersion || 0)}`;
  if (pendingSpadesAutomaticKey === key) return;
  clearSpadesAutomaticAction();
  pendingSpadesAutomaticKey = key;
  const expectedPublicId = String(session.publicId || "");
  const timerId = setTimeout(async () => {
    if (spadesAutomaticTimer !== timerId || pendingSpadesAutomaticKey !== key) return;
    spadesAutomaticTimer = 0;
    const currentPhase = String(session?.state?.phase || "");
    if (!gameSurfaceVisible || context.extensionId !== "spades" || !session || busy || !canCoordinateSpadesAutomaticAction(currentPhase) || session.state?.completed
      || String(session.publicId || "") !== expectedPublicId
      || `${currentPhase}:${Number(session.stateVersion || 0)}` !== key) {
      if (pendingSpadesAutomaticKey === key) pendingSpadesAutomaticKey = "";
      return;
    }
    const succeeded = phase === "deal"
      ? await performAction("deal", {}, "spades-deal")
      : await performAction("settle-trick");
    if (!succeeded && pendingSpadesAutomaticKey === key) pendingSpadesAutomaticKey = "";
  }, phase === "settling" ? spadesAutomaticSettlementDelayMs(session) : 0);
  spadesAutomaticTimer = timerId;
}

function clearBlackjackAutomaticAction() {
  clearTimeout(blackjackAutomaticTimer);
  blackjackAutomaticTimer = 0;
  pendingBlackjackAutomaticKey = "";
}

function scheduleBlackjackAutomaticAction() {
  const phase = String(session?.state?.phase || "");
  const cancelPending = () => { if (blackjackAutomaticTimer) clearBlackjackAutomaticAction(); };
  if (!gameSurfaceVisible || context.extensionId !== "blackjack" || !session || busy || !canAct() || session.state?.completed || !["deal","dealer"].includes(phase)) {
    cancelPending();
    return;
  }
  const key = `${phase}:${Number(session.stateVersion || 0)}`;
  if (pendingBlackjackAutomaticKey === key) return;
  clearBlackjackAutomaticAction();
  pendingBlackjackAutomaticKey = key;
  const expectedPublicId = String(session.publicId || "");
  const timerId = setTimeout(async () => {
    if (blackjackAutomaticTimer !== timerId || pendingBlackjackAutomaticKey !== key) return;
    blackjackAutomaticTimer = 0;
    const currentPhase = String(session?.state?.phase || "");
    if (!gameSurfaceVisible || context.extensionId !== "blackjack" || !session || busy || !canAct() || session.state?.completed
      || String(session.publicId || "") !== expectedPublicId
      || `${currentPhase}:${Number(session.stateVersion || 0)}` !== key) {
      if (pendingBlackjackAutomaticKey === key) pendingBlackjackAutomaticKey = "";
      return;
    }
    const succeeded = phase === "deal"
      ? await performAction("deal", {}, "blackjack-shoe")
      : await performAction("dealer-play");
    if (!succeeded && pendingBlackjackAutomaticKey === key) pendingBlackjackAutomaticKey = "";
  }, phase === "dealer" ? 420 : 0);
  blackjackAutomaticTimer = timerId;
}

function clearUnoAutomaticAction() {
  clearTimeout(unoAutomaticTimer);
  unoAutomaticTimer = 0;
  pendingUnoAutomaticKey = "";
}

function scheduleUnoAutomaticAction() {
  const phase = String(session?.state?.phase || "");
  const cancelPending = () => { if (unoAutomaticTimer) clearUnoAutomaticAction(); };
  if (!gameSurfaceVisible || context.extensionId !== "uno" || !session || busy || !canAct() || session.state?.completed || !["deal"].includes(phase)) {
    cancelPending();
    return;
  }
  const key = `${phase}:${Number(session.stateVersion || 0)}`;
  if (pendingUnoAutomaticKey === key) return;
  clearUnoAutomaticAction();
  pendingUnoAutomaticKey = key;
  const expectedPublicId = String(session.publicId || "");
  const timerId = setTimeout(async () => {
    if (unoAutomaticTimer !== timerId || pendingUnoAutomaticKey !== key) return;
    unoAutomaticTimer = 0;
    const currentPhase = String(session?.state?.phase || "");
    if (!gameSurfaceVisible || context.extensionId !== "uno" || !session || busy || !canAct() || session.state?.completed
      || String(session.publicId || "") !== expectedPublicId
      || `${currentPhase}:${Number(session.stateVersion || 0)}` !== key) {
      if (pendingUnoAutomaticKey === key) pendingUnoAutomaticKey = "";
      return;
    }
    const succeeded = await performAction("deal", {}, "uno-deal");
    if (!succeeded && pendingUnoAutomaticKey === key) pendingUnoAutomaticKey = "";
  }, 80);
  unoAutomaticTimer = timerId;
}

function currentUserId() {
  return Number(session?.members?.find(member => Number(member.participantId) === context.participantId)?.userId || 0);
}

function memberName(userId) {
  return session?.members?.find(member => Number(member.userId) === Number(userId))?.displayName || "Member";
}

function gameSessionIsTerminal(value = session) {
  return ["completed", "forfeited", "abandoned", "ended", "cancelled", "expired"].includes(String(value?.status || ""))
    || value?.state?.completed === true;
}

function gameTerminalOutcome(value = session, extensionId = context.extensionId) {
  const state = value?.state || {};
  const status = String(value?.status || "");
  const eligibleParticipant = id => Number.isSafeInteger(id) && (id > 0
    || (id < 0 && value?.mode === "practice" && ["checkers", "chess", "backgammon-first-party"].includes(extensionId)
      && state.bots?.[String(id)]?.userId === id));
  const participants = Array.isArray(state.turnOrder)
    ? state.turnOrder.filter(eligibleParticipant)
    : (value?.members || []).filter(member => ["master", "player"].includes(member.role)).map(member => Number(member.userId));
  const outcome = {
    terminal: gameSessionIsTerminal(value), status,
    reason: state.completed === true ? String(state.terminalReason || state.terminalCause || state.terminalClassification || "") : "",
    participantIds: [...new Set(participants)], winnerIds: [], drawIds: [], winningTeam: null,
    draw: false, known: false, score: null,
  };
  if (!outcome.terminal || state.completed !== true) return outcome;
  // A shared timeout/disconnect winner takes precedence over unfinished scores.
  if (eligibleParticipant(state.winnerUserId)) {
    if (!outcome.participantIds.includes(state.winnerUserId)) return outcome;
    outcome.known = true;
    outcome.winnerIds = [state.winnerUserId];
  } else if (state.winnerUserId === null && outcome.reason === "disconnect-allowance-draw") {
    outcome.known = true;
    outcome.draw = true;
    outcome.drawIds = outcome.participantIds;
  } else if (extensionId === "blackjack") {
    if (outcome.reason && !["round-limit", "all-players-below-minimum-bet", "resignation"].includes(outcome.reason)) return outcome;
    const ids = outcome.participantIds;
    const scores = ids.map(id => state.bankrolls?.[String(id)]);
    if (!ids.length || !scores.every(score => typeof score === "number" && Number.isFinite(score))) return outcome;
    const high = Math.max(...scores);
    outcome.known = true;
    outcome.score = high;
    const leaders = ids.filter((id, index) => scores[index] === high);
    outcome.draw = leaders.length > 1;
    if (outcome.draw) outcome.drawIds = leaders;
    else outcome.winnerIds = leaders;
  } else if (extensionId === "spades" && [0, 1].includes(state.winningTeam)
    && Array.isArray(state.turnOrder) && state.turnOrder.length === 4
    && state.turnOrder.every(id => Number.isSafeInteger(id) && id !== 0)
    && new Set(state.turnOrder).size === 4) {
    outcome.known = true;
    outcome.winningTeam = state.winningTeam;
    outcome.winnerIds = state.turnOrder.filter((id, index) => index % 2 === state.winningTeam && id > 0);
  } else if (state.winnerUserId === null) {
    // Null also represents unrecorded or multiple-winner outcomes. Only an
    // explicit game-owned draw reason can classify it without a result receipt.
    const drawReasons = {
      chess: ["mutual-agreement", "stalemate", "insufficient-material", "claimed-threefold-repetition",
        "claimed-fifty-move-rule", "automatic-fivefold-repetition", "automatic-seventy-five-move-rule",
        "clock-expiration-insufficient-mating-material"],
      checkers: ["mutual-agreement", "third-repetition", "forty-move-rule"],
      "nested-four": ["threefold-repetition"],
    };
    if ((drawReasons[extensionId] || []).includes(outcome.reason)) {
      outcome.known = true;
      outcome.draw = true;
      outcome.drawIds = outcome.participantIds;
    }
  }
  return outcome;
}

function gameMemberTerminalLabel(userId, value = session, extensionId = context.extensionId) {
  const outcome = gameTerminalOutcome(value, extensionId);
  if (outcome.known && outcome.participantIds.includes(Number(userId))) {
    if (outcome.drawIds.includes(Number(userId))) return "Draw";
    return outcome.winnerIds.includes(Number(userId)) ? "Winner" : "Lost";
  }
  return outcome.status === "forfeited" ? "Game forfeited"
    : outcome.status === "abandoned" ? "Game abandoned"
      : ["ended", "cancelled", "expired"].includes(outcome.status) ? "Game ended" : "Game completed";
}

function gameTerminalStatus(value = session, extensionId = context.extensionId, viewerUserId = currentUserId()) {
  const outcome = gameTerminalOutcome(value, extensionId);
  const members = value?.members || [];
  const name = id => Number(id) === Number(viewerUserId)
    && ["master", "player"].includes(String(value?.viewerRole || "")) ? "You"
    : String(members.find(member => Number(member.userId) === Number(id))?.displayName || "A player");
  let text = gameMemberTerminalLabel(0, value, extensionId);
  if (outcome.known && outcome.draw) text = "Game drawn";
  else if (outcome.known && outcome.winningTeam !== null) {
    text = `Team ${outcome.winningTeam + 1} wins`;
    if (outcome.winnerIds.length) text += ` (${outcome.winnerIds.map(name).join(" and ")})`;
  }
  else if (outcome.known && outcome.winnerIds.length) {
    const names = outcome.winnerIds.map(name);
    text = names.join(" and ") + (names.length === 1 && names[0] !== "You" ? " wins" : " win");
  }
  if (extensionId === "blackjack" && outcome.known && outcome.score !== null) {
    text += outcome.draw
      ? outcome.drawIds.length === outcome.participantIds.length
        ? `; all players finished with ${outcome.score.toLocaleString()} chips`
        : `; ${outcome.drawIds.map(name).join(" and ")} tied with ${outcome.score.toLocaleString()} chips`
      : ` with ${outcome.score.toLocaleString()} chips`;
  }
  if (outcome.reason) text += ` (${outcome.reason.replaceAll("-", " ")})`;
  return text + ".";
}

function blackjackDisplayFacts(value = session) {
  const lobby = value?.status === "lobby";
  const state = value?.state || {};
  const accepted = value?.settings || {};
  const settings = lobby ? accepted : (state.settings || accepted);
  const count = value => typeof value === "number" && Number.isSafeInteger(value) && value > 0 ? value : null;
  return { lobby, rounds: count(settings.rounds), round: lobby ? null : count(state.round), startingChips: count(accepted.startingChips) };
}

function blackjackBankrollLabel(userId, value = session) {
  const facts = blackjackDisplayFacts(value);
  if (facts.lobby) return facts.startingChips === null ? "Starting chips pending" : `${facts.startingChips.toLocaleString()} starting chips`;
  const chips = value?.state?.bankrolls?.[String(userId)];
  if (typeof chips !== "number" || !Number.isFinite(chips) || chips < 0) return "Chip count unavailable";
  return `${chips.toLocaleString()} chips`;
}

function blackjackLiveState(userId, value = session) {
  const phase = gameSessionIsTerminal(value) ? gameMemberTerminalLabel(userId, value, "blackjack")
    : value?.status === "lobby" ? "Waiting to start"
      : value?.state?.phase === "round-complete" ? "Round complete" : "Playing";
  return phase + " · " + blackjackBankrollLabel(userId, value);
}

function gameLifecycleAvailable() {
  return ["master", "player"].includes(String(session?.viewerRole || ""))
    && session?.status === "active"
    && !session?.state?.completed
    && String(session?.state?._framework?.pause?.mode || "running") === "running"
    && session?.state?._framework?.serviceInterruption?.active !== true
    && session?.state?._framework?.players?.[String(currentUserId())]?.disconnected !== true
    && !busy;
}

function canAct() {
  return gameLifecycleAvailable() && Number(session?.turnUserId || 0) === currentUserId();
}

function spadesPassSelectionForModernBoard() {
  const viewerId = currentUserId();
  const passes = Object.values(session?.state?.partnerPasses || {});
  const offer = passes.find(pass => Number(pass.fromUserId) === viewerId && pass.status === "awaiting-offer") || null;
  const response = passes.find(pass => Number(pass.toUserId) === viewerId && pass.status === "awaiting-response") || null;
  const activePass = offer || response;
  const requiredCount = Math.max(1, Math.min(2, Number(activePass?.cardCount || 2)));
  const hand = session?.state?.hands?.[String(viewerId)];
  const active = session?.state?.phase === "partner-pass" && canAct() && Boolean(activePass) && Array.isArray(hand);
  if (!active) selectedSpadesPassCards = [];
  else selectedSpadesPassCards = selectedSpadesPassCards.filter(card => hand.includes(card)).slice(0, requiredCount);
  return {
    active,
    requiredCount,
    selectedCards: [...selectedSpadesPassCards],
    toggle(card) {
      card = String(card || "");
      if (!active || !hand.includes(card)) return false;
      if (selectedSpadesPassCards.includes(card)) {
        selectedSpadesPassCards = selectedSpadesPassCards.filter(item => item !== card);
      } else if (requiredCount === 1) {
        selectedSpadesPassCards = [card];
      } else if (selectedSpadesPassCards.length < requiredCount) {
        selectedSpadesPassCards = [...selectedSpadesPassCards, card];
      } else {
        selectedSpadesPassCards = [...selectedSpadesPassCards.slice(1), card].slice(-requiredCount);
      }
      render();
      return true;
    },
  };
}

function squareBoardAriaLabel(gameName) {
  const prefix = `${gameName} board.`;
  if (session?.state?.completed) return `${prefix} The game is complete and the board is read-only.`;
  if (!["master", "player"].includes(String(session?.viewerRole || ""))) {
    return `${prefix} Spectator view; the board is read-only.`;
  }
  if (String(session?.state?._framework?.pause?.mode || "running") !== "running") {
    return `${prefix} The game is paused and the board is read-only.`;
  }
  if (session?.state?._framework?.serviceInterruption?.active === true) {
    return `${prefix} Play is temporarily unavailable and the board is read-only.`;
  }
  if (session?.state?._framework?.players?.[String(currentUserId())]?.disconnected === true) {
    return `${prefix} You are reconnecting and the board is read-only.`;
  }
  if (Number(session?.turnUserId || 0) !== currentUserId()) {
    return `${prefix} Waiting for ${memberName(session?.turnUserId)} to move; the board is read-only.`;
  }
  if (busy) return `${prefix} The board is updating and temporarily read-only.`;
  return `${prefix} Choose one of your movable pieces, then a legal destination. Activate the selected piece again to cancel.`;
}

function optionCategory(key, fallback = true) {
  const value = options?.categories?.[key];
  // A saved game choice takes precedence; the device preference is the default.
  if (typeof value === "boolean") return value;
  if (key === "visualFxEnabled") return fallback && !matchMedia("(prefers-reduced-motion: reduce)").matches;
  return fallback;
}

async function toggleOptionCategory(key, fallback = true) {
  const categories = { ...(options?.categories || {}), [key]: !optionCategory(key, fallback) };
  if (await updateViewerOptions({ ...options, categories })) render();
}

async function updateViewerOptions(next) {
  const revision = ++optionsMutationRevision;
  const returned = await apiPost("options", { game_key: context.gameKey, options: next });
  if (revision !== optionsMutationRevision) return false;
  options = returned;
  if (options.effectsEnabled===false) {cancelOriginalAudio();for(const audio of audioPlayers.values())audio.pause();}
  else if(options.voiceEnabled===false)stopOriginalVoice();
  return true;
}

function spadesCardActivationMode() {
  return options?.categories?.spadesCardActivationMode === "legacy-ocx-one-click"
    ? "legacy-ocx-one-click"
    : "two-step";
}

function chessPieceSizeMode() {
  return options?.categories?.chessPieceSize === "smaller" ? "smaller" : "normal";
}

function chessPieceStyleMode() {
  return options?.categories?.chessPieceStyle === "unicode" ? "unicode" : "sculpted";
}

function pointCheckerStyleMode() {
  return options?.categories?.pointCheckerStyle === "css" ? "css" : "high-quality";
}

async function setChessPieceSizeMode(mode) {
  const nextMode = mode === "smaller" ? "smaller" : "normal";
  const categories = { ...(options?.categories || {}), chessPieceSize: nextMode };
  if (await updateViewerOptions({ ...options, categories })) {
    selectedSquare = null;
    render();
  }
}

async function setChessPieceStyleMode(mode) {
  const nextMode = mode === "unicode" ? "unicode" : "sculpted";
  const categories = { ...(options?.categories || {}), chessPieceStyle: nextMode };
  if (await updateViewerOptions({ ...options, categories })) {
    selectedSquare = null;
    render();
  }
}

async function setPointCheckerStyleMode(mode) {
  const nextMode = mode === "css" ? "css" : "high-quality";
  const categories = { ...(options?.categories || {}), pointCheckerStyle: nextMode };
  if (await updateViewerOptions({ ...options, categories })) {
    selectedPointOrigin = null;
    render();
  }
}

async function setSpadesCardActivationMode(mode) {
  const nextMode = mode === "legacy-ocx-one-click" ? "legacy-ocx-one-click" : "two-step";
  const categories = { ...(options?.categories || {}), spadesCardActivationMode: nextMode };
  if (await updateViewerOptions({ ...options, categories })) {
    selectedSpadesCard = null;
    render();
  }
}

function canRequestOpeningRoll() {
  const opening = (context.extensionId === "acey-deucy" && session?.state?.aceyStage === "opening-roll")
    || (context.extensionId === "backgammon-first-party" && session?.state?.backgammonStage === "opening-roll");
  return opening
    && gameLifecycleAvailable()
    && Number(session?.state?.openingCoordinatorUserId || 0) === currentUserId()
    && !busy;
}

function openingAnnouncement() {
  const state = session?.state || {};
  const round = Math.max(1, Number(state.roundNumber || 1));
  if (context.extensionId === "spades" && state.initialDealerPending) {
    return `Round ${round}: a fair verified draw will select the initial dealer.`;
  }
  if ((context.extensionId === "acey-deucy" && state.aceyStage === "opening-roll")
    || (context.extensionId === "backgammon-first-party" && state.backgammonStage === "opening-roll")) {
    const attempts = Array.isArray(state.openingRollAttempts) ? state.openingRollAttempts : [];
    const last = attempts.at(-1);
    return last?.tie
      ? `Round ${round}: the opening roll was tied, so both players roll again.`
      : `Round ${round}: a verified one-die-per-player opening roll will choose who starts.`;
  }
  if (Number(state.starterUserId || 0) > 0) {
    return `Round ${round}: ${memberName(state.starterUserId)} starts. ${safe(state.starterReason)}`.trim();
  }
  return safe(state.starterReason || "");
}

function playerPositionLabel(member) {
  const state = session?.state || {};
  if (context.extensionId === "chess") {
    return state.colorAssignments?.[String(member.userId)] === "w" ? "White" : "Black";
  }
  if (context.extensionId === "checkers") {
    const side = state.sideAssignments?.[String(member.userId)];
    return safe(state.sideLabels?.[side] || (side === "a" ? "Starting side" : "Opposing side"));
  }
  if (context.extensionId === "battleship") {
    return Number(state.starterUserId || 0) === Number(member.userId) ? "First attacker" : "Other attacker";
  }
  if (context.extensionId === "spades") {
    const dealer = Number(state.dealerUserId || 0) === Number(member.userId) ? " · Dealer" : "";
    return `Seat ${Number(member.seat || 0)}${dealer}`;
  }
  if (context.extensionId === "backgammon-first-party") {
    return Number(member.seat || 1) === 1 ? "First side" : "Second side";
  }
  return member.seat || "—";
}

function boardSquareKey(row, column) { return `${Number(row)}:${Number(column)}`; }

function projectedBoardInteraction(state) {
  const interaction = state?.interaction || {};
  const origins = new Set(Array.isArray(interaction.selectableOrigins) ? interaction.selectableOrigins.map(String) : []);
  if (selectedSquare && !origins.has(boardSquareKey(selectedSquare[0], selectedSquare[1]))) selectedSquare = null;
  const selectedKey = selectedSquare ? boardSquareKey(selectedSquare[0], selectedSquare[1]) : "";
  const projected = interaction.legalDestinationsByOrigin?.[selectedKey];
  const destinations = new Set(Array.isArray(projected) ? projected.map(String) : []);
  if (previewDestination && !destinations.has(previewDestination)) {
    previewDestination = null;
    touchPreviewDestination = null;
  }
  return {
    authority: interaction.authority || "",
    origins,
    destinations,
    selectedKey,
  };
}

function projectedPointInteraction(state) {
  const interaction = state?.interaction || {};
  const origins = new Set(Array.isArray(interaction.selectableOrigins) ? interaction.selectableOrigins.map(String) : []);
  if (selectedPointOrigin && !origins.has(selectedPointOrigin)) selectedPointOrigin = null;
  const projected = interaction.legalDestinationsByOrigin?.[selectedPointOrigin || ""];
  const moves = interaction.legalMovesByOrigin?.[selectedPointOrigin || ""];
  const destinations = new Set(Array.isArray(projected) ? projected.map(String) : []);
  if (previewDestination && !destinations.has(previewDestination)) {
    previewDestination = null;
    touchPreviewDestination = null;
  }
  return {
    authority: interaction.authority || "",
    origins,
    destinations,
    moves: Array.isArray(moves) ? moves : [],
    selectedOrigin: selectedPointOrigin,
  };
}

function bindDestinationPreview(button, destination, legal) {
  if (!legal) return;
  const show = (preserveTouch = false) => {
    if (!preserveTouch) touchPreviewDestination = null;
    const previewRoot = button.closest(".ocx-game-root") || document;
    for (const peer of previewRoot.querySelectorAll(".is-preview-destination")) {
      if (peer === button) continue;
      peer.classList.remove("is-preview-destination");
      delete peer.dataset.touchPreviewArmed;
      delete peer.dataset.touchPreviewOnly;
    }
    previewDestination = destination;
    button.classList.add("is-preview-destination");
  };
  const hide = event => {
    if (event?.pointerType === "touch") return;
    if (touchPreviewDestination === destination) return;
    if (previewDestination === destination) {
      previewDestination = null;
      touchPreviewDestination = null;
    }
    button.classList.remove("is-preview-destination");
    delete button.dataset.pointerLegalCue;
    button.style.removeProperty("--legal-pointer-x");
    button.style.removeProperty("--legal-pointer-y");
  };
  button.addEventListener("pointerenter", event => {
    // Touch browsers may synthesize pointerenter immediately before touchstart.
    // Touchstart must remain the sole first-preview owner or one tap can be
    // mistaken for the confirming second activation.
    if (event.pointerType === "touch") return;
    show();
  });
  button.addEventListener("pointermove", event => {
    if (event.pointerType === "touch") return;
    const bounds = button.getBoundingClientRect();
    if (!bounds.width || !bounds.height) return;
    button.dataset.pointerLegalCue = "true";
    button.style.setProperty("--legal-pointer-x", `${((event.clientX - bounds.left) / bounds.width) * 100}%`);
    button.style.setProperty("--legal-pointer-y", `${((event.clientY - bounds.top) / bounds.height) * 100}%`);
  });
  button.addEventListener("pointerleave", hide);
  button.addEventListener("focus", () => show(touchPreviewDestination === destination));
  button.addEventListener("blur", hide);
  button.addEventListener("touchstart", () => {
    const alreadyPreviewed = previewDestination === destination
      && touchPreviewDestination === destination;
    touchPreviewDestination = destination;
    show(true);
    button.dataset.touchPreviewArmed = "true";
    button.dataset.touchPreviewOnly = alreadyPreviewed ? "false" : "true";
  }, { passive: true });
}

function consumeTouchPreviewOnly(button) {
  const previewOnly = button.dataset.touchPreviewOnly === "true";
  delete button.dataset.touchPreviewOnly;
  return previewOnly;
}

function mediaUrl(slot) {
  if (context.extensionId === "spades" && ["svg-joker-big", "svg-joker-little"].includes(slot)) {
    return new URL(`../../assets/images/spades-modern/joker-${slot.endsWith("big") ? "big" : "little"}.svg`, window.location.href).href;
  }
  const query = new URLSearchParams({ game: context.extensionId, game_session_id: context.gameSessionId, slot });
  return new URL(`../../api/game_media.php?${query}`, window.location.href).href;
}

function mediaImage(slot, className = "", alt = "") {
  const image = document.createElement("img");
  image.src = mediaUrl(slot);
  image.className = className;
  image.alt = alt;
  image.decoding = "sync";
  image.draggable = false;
  image.addEventListener("load", () => trace("image-loaded", slot, { width: image.naturalWidth, height: image.naturalHeight }));
  image.addEventListener("error", () => trace("image-unavailable", slot));
  return image;
}

let classicDiePaintId = 0;
function pointGameClassicDie(value) {
  const face = Math.max(1, Math.min(6, Math.trunc(Number(value) || 1)));
  if (!["acey-deucy", "backgammon-first-party"].includes(context.extensionId)) {
    return mediaImage(`gif-dice${face}`, "classic-die", `Die ${face}`);
  }
  // Every value uses the same rounded body and shaded sides. Mixing a new
  // six with the legacy faces made the two dice look like different sets.
  const acey = context.extensionId === "acey-deucy";
  const die = make("span", `classic-die classic-vector-die${face === 6 ? " classic-six-die" : ""}`);
  die.setAttribute("role", "img");
  die.setAttribute("aria-label", `Die ${face}`);
  die.dataset.dieFace = String(face);
  const colors = acey
    ? { paper:"#eff2d8", rim:"#74805a", side:"#849367", bottom:"#596944", ink:"#1f2d19" }
    : { paper:"#f7f8f1", rim:"#a2a79f", side:"#b1b7ad", bottom:"#7d857a", ink:"#1f2220" };
  const pipPositions = {
    1:[[9,9]],
    2:[[5.8,5.8],[12.2,12.2]],
    3:[[5.8,5.8],[9,9],[12.2,12.2]],
    4:[[5.8,5.8],[12.2,5.8],[5.8,12.2],[12.2,12.2]],
    5:[[5.8,5.8],[12.2,5.8],[9,9],[5.8,12.2],[12.2,12.2]],
    6:[[5.8,5.3],[12.2,5.3],[5.8,9],[12.2,9],[5.8,12.7],[12.2,12.7]],
  }[face];
  const paintId = `classic-die-face-${++classicDiePaintId}`;
  die.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="100%" height="100%" aria-hidden="true" focusable="false"><defs><linearGradient id="${paintId}" x2=".8" y2="1"><stop stop-color="#ffffff"/><stop offset="1" stop-color="${colors.paper}"/></linearGradient></defs><path fill="#182017" opacity=".35" d="M3 16q7-1 15 0l1 2q-7 2-15 0z"/><path fill="${colors.side}" stroke="${colors.bottom}" stroke-width=".6" d="M14 2.5l3.5 2.7q.6.5.6 1.5v9q0 1-.8 1.4L14 18z"/><path fill="${colors.bottom}" d="M3.2 14.5l2.5 3.2q.4.5 1.5.5h8q1.1 0 2.1-1.1l-2.2-2.6z"/><rect x="2" y="2" width="14" height="14" rx="2.2" fill="url(#${paintId})" stroke="${colors.rim}" stroke-width=".7"/><path d="M3.2 6V4.5q0-1.3 1.4-1.3H13" fill="none" stroke="#ffffff" stroke-width=".7" opacity=".95"/><g fill="${colors.ink}" stroke="${colors.bottom}" stroke-width=".18">${pipPositions.map(([x,y]) => `<circle cx="${x}" cy="${y}" r="1.3"/>`).join("")}</g></svg>`;
  return die;
}

const nativeClassicMedia = new Map();
function optionalClassicStrip(slot) {
  const entry = session?.presentation?.mediaPack?.optional?.find(item => item.slot === slot && item.state === "installed");
  const cached = nativeClassicMedia.get(mediaUrl(slot));
  return entry && cached && cached.image.complete && cached.image.naturalWidth > 0 ? cached.image : null;
}

function ensureNativeClassicMediaReady() {
  if (!["chess", "acey-deucy"].includes(context.extensionId) || session?.presentation?.effectivePack !== "classic") return;
  const wanted = new Set();
  if (context.extensionId === "chess") {
    const source = classicSourceMap("chess");
    // Preload the two king variants first, followed by current capture variants.
    for (const kings of [true,false]) for (let row=0;row<8;row++) for(let column=0;column<8;column++) {
      const piece = session.state?.board?.[row]?.[column];
      if (!piece || (piece[1] === "K") !== kings) continue;
      const base = kings ? 600 : source.motion.nativeCapture.baseByPiece[piece[1]];
      if (base === undefined) continue;
      const level = source.pieceSizeByRow[squareVisualCoordinates("chess",row,column).row];
      wanted.add(`bitmap-${base+(piece[0] === "w" ? 4 : 0)+level-1}`);
    }
  } else for (const base of [515,519,523,527]) for (const side of [0,2]) wanted.add(`bitmap-${base+side}`);
  for (const slot of wanted) {
    const entry = session.presentation.mediaPack?.optional?.find(item=>item.slot===slot && item.state==="installed");
    if (!entry) continue;
    const url = mediaUrl(entry.slot);
    if (nativeClassicMedia.has(url)) continue;
    const image = new Image();
    image.decoding = "sync";
    nativeClassicMedia.set(url, { image, sha256:entry.sha256 });
    image.src = url;
  }
  document.body.dataset.nativeClassicMediaReady = String([...wanted].every(slot=>
    !session.presentation.mediaPack?.optional?.some(item=>item.slot===slot && item.state==="installed") || optionalClassicStrip(slot)));
}

function chessNativeCapture(motion) {
  const victim = chessCaptureVictim(motion);
  if (!victim) return null;
  const source = classicSourceMap("chess");
  const native = source.motion.nativeCapture;
  const projected = squareVisualCoordinates("chess", victim.row, victim.column);
  const level = source.pieceSizeByRow[projected.row];
  const base = native.baseByPiece[victim.piece[1]];
  if (base === undefined) return null;
  const slot = `bitmap-${base + (victim.piece[0] === "w" ? 4 : 0) + level - 1}`;
  if (!optionalClassicStrip(slot)) return null;
  const box = bottomCenteredSourceAssetBox(checkerCellBox("chess",victim.row,victim.column),native.frameSizes[level-1]);
  box.y -= native.rowYOffset[projected.row];
  return { slot, box, victim, ...native };
}

function chessCaptureDuration(motion = pendingClassicMotion) {
  return chessNativeCapture(motion)?.durationMs || classicSourceMap("chess").motion.captureFall.durationMs;
}

function aceyNativeWin(motion = pendingClassicMotion) {
  const state = motion?.after || session?.state;
  const index = (state?.turnOrder || []).map(Number).indexOf(Number(state?.winnerUserId));
  if (index < 0) return null;
  const definition = classicSourceMap("acey-deucy").motion.nativeWin;
  const slots = definition.baseSlots.map(id => `bitmap-${id + (index === 0 ? 0 : 2)}`);
  return slots.every(slot => optionalClassicStrip(slot))
    ? { ...definition, slots, positions:definition.positions[index === 0 ? "white" : "black"] } : null;
}

// Cache by authorized session URL, not just slot: one game's artwork must not
// become another game's fallback. A slow/failed replacement never erases what
// the player already sees, and detached selections cannot update a new render.
const pointPresentationMedia = new Map();
function pointPresentationEntry(slot) {
  const url = mediaUrl(slot);
  if (pointPresentationMedia.has(url)) return pointPresentationMedia.get(url);
  const image = new Image();
  const entry = { image, ready: false, promise: null };
  entry.promise = new Promise(resolve => {
    image.onload = async () => {
      try {
        await image.decode();
        entry.ready = image.naturalWidth > 0 && image.naturalHeight > 0;
      } catch { entry.ready = false; }
      resolve(entry.ready);
    };
    image.onerror = () => resolve(false);
  });
  pointPresentationMedia.set(url, entry);
  if (pointPresentationMedia.size > 64) pointPresentationMedia.delete(pointPresentationMedia.keys().next().value);
  image.src = url;
  return entry;
}

function pointSelectionImage(slot, fallbackSlot, className) {
  if (slot === fallbackSlot) return mediaImage(slot, className, "");
  const entry = pointPresentationEntry(slot);
  const image = mediaImage(entry.ready ? slot : fallbackSlot, className, "");
  if (!entry.ready) entry.promise.then(ready => {
    if (ready && image.isConnected) image.src = entry.image.src;
  });
  return image;
}

// A focus/selection change may arrive before its artwork does. Keep the
// decoded predecessor, and let only the latest request update this node.
function setSquarePieceArtwork(image, slot) {
  const url = mediaUrl(slot);
  image.dataset.requestedPieceArtwork = url;
  image.dataset.requestedPieceArtworkSlot = slot;
  if (image.src === url) return;
  const entry = pointPresentationEntry(slot);
  if (entry.ready) image.src = url;
  else entry.promise.then(ready => {
    if (ready && image.isConnected && image.dataset.requestedPieceArtwork === url) image.src = url;
  });
}

function squareSelectionImage(slot, restSlot, className) {
  const image = mediaImage(restSlot, className, "");
  setSquarePieceArtwork(image, slot);
  return image;
}

function connectSquarePieceArtwork(board) {
  // Chess mounts a decoded board asynchronously. A highlight may have become
  // ready while that board was detached; retry only the board being mounted.
  for (const image of board.querySelectorAll("[data-requested-piece-artwork-slot]")) {
    setSquarePieceArtwork(image, image.dataset.requestedPieceArtworkSlot);
  }
}

function setPointBoardArtwork(stage, slot, fallbackSlot) {
  const entry = pointPresentationEntry(slot);
  stage.style.setProperty("--classic-board", `url("${mediaUrl(entry.ready ? slot : fallbackSlot)}")`);
  if (!entry.ready) entry.promise.then(ready => {
    if (ready && stage.isConnected) stage.style.setProperty("--classic-board", `url("${entry.image.src}")`);
  });
}

function ensurePointPresentationMediaReady() {
  if (!["backgammon-first-party", "acey-deucy"].includes(context.extensionId)
    || session?.presentation?.effectivePack !== "classic") return;
  ["gif-w-h", "gif-b-h", ...(context.extensionId === "acey-deucy" ? ["classic-board-result"] : [])]
    .forEach(pointPresentationEntry);
}

const pointNativeDimensions = Object.fromEntries([
  [501,25,175],[502,25,175],[505,30,444],[506,30,444],[507,32,256],[508,32,285],
  [509,31,248],[510,31,248],[511,32,192],[512,32,192],[513,31,124],[514,31,124],
].map(([id,w,h]) => [`bitmap-${id}`,[w,h]]));
function pointNativeReady() {
  return ["backgammon-first-party","acey-deucy"].includes(context.extensionId)
    && session?.presentation?.effectivePack === "classic"
    && Object.entries(pointNativeDimensions).every(([slot,[w,h]]) => {
      const image = pointBearOffMedia.get(mediaUrl(slot));
      return image?.complete && image.naturalWidth === w && image.naturalHeight === h;
    });
}
// Freeze readiness in classifyClassicMotion: loading must not switch a running timeline.
function pointMoveDuration(gameId, type, motion) {
  if (motion?.pointNative) return type === "point-hit" ? 2480 : type === "point-bear-off" ? 1120 : 960;
  const source = classicSourceMap(gameId).motion;
  return type === "point-hit" ? pointHitMotionLength(source.hitToBar)
    : type === "point-bear-off" ? source.bearOff.durationMs : source.checkerSlide.durationMs;
}
const pointBearOffMedia = new Map();
function ensurePointBearOffMediaReady() {
  if (!["backgammon-first-party", "acey-deucy"].includes(context.extensionId)
    || session?.presentation?.effectivePack !== "classic") return;
  for (const entry of session.presentation.mediaPack?.optional || []) {
    if (entry.state !== "installed" || !Object.hasOwn(pointNativeDimensions, entry.slot)) continue;
    const url = mediaUrl(entry.slot);
    if (pointBearOffMedia.has(url)) continue;
    const image = new Image();
    image.decoding = "sync";
    pointBearOffMedia.set(url, image);
    // Optional artwork never delays a move or replaces a working board.
    image.src = url;
  }
}

function ensureClassicMotionMediaReady() {
  if (!["checkers", "battleship"].includes(context.extensionId) || session?.presentation?.effectivePack !== "classic") {
    return Promise.resolve();
  }
  const source = classicSourceMap(context.extensionId);
  const slots = context.extensionId === "battleship"
    ? Array.from({ length:17 }, (_, index) => `dib-${index + 2}`)
    : [
      ...Array.from({ length:16 }, (_, index) => `bitmap-${500 + index}`),
      ...Array.from({ length:4 }, (_, index) => `bitmap-${520 + index}`),
      "bitmap-524", "bitmap-525", "bitmap-526",
      ...Object.keys(source.motion.decisiveTerminal.assetDimensions).map(suffix => `gif-disco${suffix}`),
    ];
  const key = `${context.gameSessionId}:${slots.join(",")}`;
  if (classicMotionMediaReadyKey === key) return classicMotionMediaReadyPromise;
  classicMotionMediaReadyKey = key;
  document.body.dataset.classicMotionMediaReady = "false";
  classicMotionMediaReadyPromise = Promise.all(slots.map(slot => {
    if (classicMotionMediaPreloads.has(slot)) return classicMotionMediaPreloads.get(slot).ready;
    const image = document.createElement("img");
    image.alt = "";
    image.decoding = "sync";
    const ready = new Promise((resolve, reject) => {
      image.addEventListener("load", resolve, { once: true });
      image.addEventListener("error", () => reject(new Error(`Classic motion media ${slot} could not be loaded.`)), { once: true });
    });
    image.src = mediaUrl(slot);
    classicMotionMediaPreloads.set(slot, { image, ready });
    return ready;
  })).then(() => {
    document.body.dataset.classicMotionMediaReady = "true";
    trace("classic-motion-media-ready", null, { slotCount: slots.length });
  }).catch(error => {
    document.body.dataset.classicMotionMediaReady = "failed";
    trace("classic-motion-media-failed", null, { message: String(error?.message || error) });
    throw error;
  });
  return classicMotionMediaReadyPromise;
}

function ensureBattleshipPlacementMediaReady() {
  if (context.extensionId !== "battleship" || session?.presentation?.effectivePack !== "classic") {
    return Promise.resolve();
  }
  const slots = ["gif-1", ...[2, 3, 4, 5].flatMap(length => [`gif-${length}-h`, `gif-${length}-v`])];
  const key = `${context.gameSessionId}:${slots.join(",")}`;
  if (battleshipPlacementMediaReadyKey === key) return battleshipPlacementMediaReadyPromise;
  battleshipPlacementMediaReadyKey = key;
  document.body.dataset.battleshipPlacementMediaReady = "false";
  battleshipPlacementMediaReadyPromise = Promise.all(slots.map(slot => {
    if (battleshipPlacementMediaPreloads.has(slot)) return battleshipPlacementMediaPreloads.get(slot).ready;
    const image = document.createElement("img");
    image.alt = "";
    image.decoding = "sync";
    const ready = new Promise((resolve, reject) => {
      image.addEventListener("load", async () => {
        try {
          if (typeof image.decode === "function") await image.decode();
          resolve();
        } catch (error) {
          reject(new Error(`Battleship placement media ${slot} could not be decoded: ${String(error?.message || error)}`));
        }
      }, { once: true });
      image.addEventListener("error", () => reject(new Error(`Battleship placement media ${slot} could not be loaded.`)), { once: true });
    });
    image.src = mediaUrl(slot);
    battleshipPlacementMediaPreloads.set(slot, { image, ready });
    return ready;
  })).then(() => {
    document.body.dataset.battleshipPlacementMediaReady = "true";
    trace("battleship-placement-media-ready", null, { slotCount: slots.length });
  }).catch(error => {
    document.body.dataset.battleshipPlacementMediaReady = "failed";
    trace("battleship-placement-media-failed", null, { message: String(error?.message || error) });
    throw error;
  });
  return battleshipPlacementMediaReadyPromise;
}

function classicStage(boardSlot, className) {
  const stage = make("div", `classic-board-stage ${className}`);
  if (context.extensionId === "acey-deucy" && boardSlot === "classic-board-result") {
    setPointBoardArtwork(stage, boardSlot, "classic-board-alternate");
  } else stage.style.setProperty("--classic-board", `url("${mediaUrl(boardSlot)}")`);
  stage.dataset.boardSlot = boardSlot;
  appendClassicSourceControls(stage, context.extensionId);
  appendClassicSourceActions(stage, context.extensionId);
  return stage;
}

function classicControlEnabled(controlId) {
  if (controlId === "soundFx") return options?.effectsEnabled !== false;
  if (controlId === "music") return options?.musicEnabled === true;
  if (controlId === "visualFx") return optionCategory("visualFxEnabled", true);
  return false;
}

function classicControlLabel(controlId) {
  if (controlId === "soundFx") return "Sound FX";
  if (controlId === "music") return "Music";
  if (controlId === "visualFx") return "Visual FX";
  return "Game control";
}

function classicControlAction(controlId) {
  if (controlId === "soundFx") return toggleSound();
  if (controlId === "music") return toggleMusic();
  if (controlId === "visualFx") return toggleOptionCategory("visualFxEnabled", true);
  return Promise.resolve();
}

function sourceControlSlot(definition, enabled, highlighted = false) {
  const state = enabled ? definition.on : definition.off;
  return highlighted ? state.hover : state.rest;
}

const classicControlImages = new Map();
function showClassicControlImage(button, image, slot, nativeSize) {
  image.dataset.requestedSlot = slot;
  button.dataset.hotspotReady = "pending";
  const url = mediaUrl(slot);
  let ready = classicControlImages.get(url);
  if (!ready) {
    ready = new Promise(resolve => {
      const preload = new Image();
      preload.onload = () => resolve(preload);
      preload.onerror = () => { classicControlImages.delete(url); resolve(null); };
      preload.src = url;
    });
    classicControlImages.set(url, ready);
  }
  ready.then(preload => {
    if (image.dataset.requestedSlot !== slot) return;
    const matched = preload && (!nativeSize || (preload.naturalWidth === Number(nativeSize[0]) && preload.naturalHeight === Number(nativeSize[1])));
    if (!matched) {
      button.dataset.hotspotReady = "false";
      trace("image-unavailable", slot);
      syncSeparateControlVisibility();
      return;
    }
    image.decoding = "sync";
    image.src = url;
    image.dataset.slot = slot;
    button.dataset.hotspotReady = "true";
  });
}

function setSourceControlImage(button, highlighted = false) {
  const definition = button.__sourceControlDefinition;
  const image = button.querySelector(".classic-source-control-art");
  if (!definition || !image) return;
  const slot = sourceControlSlot(definition, classicControlEnabled(definition.controlId), highlighted);
  if (image.dataset.slot === slot) return;
  image.dataset.slot = slot;
  image.dataset.loaded = "false";
  showClassicControlImage(button, image, slot, definition.nativeSize);
  button.dataset.controlSlot = slot;
  button.dataset.hotspotReady = "pending";
}

function appendClassicSourceControls(stage, gameId) {
  const source = classicSourceMap(gameId);
  if (!source?.controls || !source?.controlSprites) return;
  for (const [controlId, definition] of Object.entries(source.controlSprites)) {
    const geometry = source.controls[definition.geometryKey || controlId];
    if (!geometry) continue;
    const button = make("button", "classic-source-control-hotspot");
    button.type = "button";
    button.__sourceControlDefinition = { ...definition, controlId };
    button.dataset.classicControl = controlId;
    button.setAttribute("aria-pressed", classicControlEnabled(controlId) ? "true" : "false");
    button.setAttribute("aria-label", `${classicControlLabel(controlId)} ${classicControlEnabled(controlId) ? "On" : "Off"}. Activate to turn ${classicControlEnabled(controlId) ? "off" : "on"}.`);
    setSourceBox(button, geometry, source.canvas.width, source.canvas.height);
    const image = make("img", "classic-source-control-art");
    image.alt = "";
    image.decoding = "sync";
    image.draggable = false;
    image.addEventListener("load", () => {
      const expected = Array.isArray(definition.nativeSize) ? definition.nativeSize.map(Number) : null;
      const nativeSizeMatches = !expected
        || (image.naturalWidth === expected[0] && image.naturalHeight === expected[1]);
      image.dataset.loaded = nativeSizeMatches ? "true" : "false";
      image.dataset.nativeWidth = String(image.naturalWidth);
      image.dataset.nativeHeight = String(image.naturalHeight);
      button.dataset.hotspotReady = nativeSizeMatches ? "true" : "false";
      button.dataset.sourceAspectMatched = nativeSizeMatches ? "true" : "false";
      trace("image-loaded", image.dataset.slot, { width: image.naturalWidth, height: image.naturalHeight, controlId });
      syncSeparateControlVisibility();
    });
    image.addEventListener("error", () => {
      image.dataset.loaded = "false";
      button.dataset.hotspotReady = "false";
      trace("image-unavailable", image.dataset.slot, { controlId });
      syncSeparateControlVisibility();
    });
    button.append(image);
    button.addEventListener("pointerenter", () => setSourceControlImage(button, true));
    button.addEventListener("pointerleave", () => setSourceControlImage(button, false));
    button.addEventListener("focus", () => setSourceControlImage(button, true));
    button.addEventListener("blur", () => setSourceControlImage(button, false));
    button.addEventListener("pointerdown", () => button.dataset.pressed = "true");
    button.addEventListener("pointerup", () => delete button.dataset.pressed);
    button.addEventListener("pointercancel", () => delete button.dataset.pressed);
    button.addEventListener("click", () => classicControlAction(controlId).catch(error => { el("status").textContent = error.message; }));
    setSourceControlImage(button, false);
    stage.append(button);
  }
}

function currentAceyRollAgain() {
  return context.extensionId === "acey-deucy"
    && session?.status === "active"
    && session?.state?.rulesProfile === "current"
    && session?.state?.completed === false
    && session?.state?.aceyStage === "roll-again";
}

function classicSourceActionState(actionId) {
  if (context.extensionId === "chess" && actionId === "draw") {
    const drawOfferBy = Number(session.state?.drawOfferBy || 0);
    const offeredByViewer = drawOfferBy === currentUserId();
    return {
      disabled: !gameLifecycleAvailable() || drawOfferBy > 0,
      label: offeredByViewer ? "Draw offered" : drawOfferBy > 0 ? "Draw decision pending" : "Offer draw",
      run: () => performAction("offer-draw"),
    };
  }
  if (context.extensionId === "chess" && actionId === "resign") {
    return {
      disabled: !gameLifecycleAvailable() || Number(session.state?.drawOfferBy || 0) > 0,
      label: "Resign game",
      run: () => performAction("resign"),
    };
  }
  if (context.extensionId === "battleship" && actionId === "autoPlacement") {
    return {
      disabled:!gameLifecycleAvailable() || session.state?.phase !== "placement",
      label:"Auto Placement",
      run:() => performAction("auto-place", {}, "battleship-auto-placement"),
    };
  }
  if (context.extensionId === "battleship" && actionId === "startGame") {
    const ownFleet = session.state?.fleets?.[String(currentUserId())] || {};
    return {
      disabled:!gameLifecycleAvailable() || session.state?.phase !== "placement" || !ownFleet.placed || ownFleet.accepted,
      label:ownFleet.accepted ? "Waiting for opponent" : "Start Game",
      run:() => performAction("start"),
    };
  }
  if (actionId === "roll") {
    if (context.extensionId === "acey-deucy") {
      const opening = session.state?.aceyStage === "opening-roll";
      return {
        disabled: opening ? !canRequestOpeningRoll() : (!canAct() || !["roll", "roll-again"].includes(session.state?.aceyStage)),
        label: opening ? "Roll to choose starter" : (currentAceyRollAgain() ? "Roll again" : "Roll dice"),
        run: () => performAction("roll", {}, "acey-deucy-roll"),
      };
    }
    if (context.extensionId === "backgammon-first-party") {
      const opening = session.state?.backgammonStage === "opening-roll";
      return {
        disabled: opening ? !canRequestOpeningRoll() : (!canAct() || session.state?.backgammonStage !== "roll"),
        label: opening ? "Roll to choose starter" : "Roll dice",
        run: () => performAction("roll", {}, "backgammon-roll"),
      };
    }
  }
  return { disabled: true, label: "Game action", run: () => Promise.resolve() };
}

function setSourceActionImage(button, state = "rest") {
  const definition = button.__sourceActionDefinition;
  const image = button.querySelector(".classic-source-action-art");
  if (!definition || !image) return;
  const slot = definition[state] || definition.rest;
  if (image.dataset.slot === slot) return;
  image.dataset.slot = slot;
  image.dataset.loaded = "false";
  button.dataset.hotspotReady = "pending";
  button.dataset.sourceAspectMatched = "false";
  showClassicControlImage(button, image, slot, definition.nativeSize);
  button.dataset.actionSlot = slot;
}

function appendClassicSourceActions(stage, gameId) {
  const source = classicSourceMap(gameId);
  if (!source?.actionSprites) return;
  for (const [actionId, definition] of Object.entries(source.actionSprites)) {
    const geometry = source.controls?.[definition.geometryKey || actionId];
    if (!geometry) continue;
    const action = classicSourceActionState(actionId);
    const button = make("button", "classic-source-action-hotspot");
    button.type = "button";
    button.__sourceActionDefinition = definition;
    button.dataset.classicAction = actionId;
    button.setAttribute("aria-label", action.label);
    button.disabled = Boolean(action.disabled);
    setSourceBox(button, geometry, source.canvas.width, source.canvas.height);
    const image = make("img", "classic-source-action-art");
    image.alt = "";
    image.decoding = "sync";
    image.draggable = false;
    image.addEventListener("load", () => {
      const expected = Array.isArray(definition.nativeSize) ? definition.nativeSize.map(Number) : null;
      const matched = !expected || (image.naturalWidth === expected[0] && image.naturalHeight === expected[1]);
      image.dataset.loaded = matched ? "true" : "false";
      image.dataset.nativeWidth = String(image.naturalWidth);
      image.dataset.nativeHeight = String(image.naturalHeight);
      button.dataset.hotspotReady = matched ? "true" : "false";
      button.dataset.sourceAspectMatched = matched ? "true" : "false";
      trace("image-loaded", image.dataset.slot, { width: image.naturalWidth, height: image.naturalHeight, actionId });
      if (actionId === "resign") syncExternalResignVisibility();
    });
    image.addEventListener("error", () => {
      image.dataset.loaded = "false";
      button.dataset.hotspotReady = "false";
      trace("image-unavailable", image.dataset.slot, { actionId });
      if (actionId === "resign") syncExternalResignVisibility();
    });
    button.append(image);
    const restore = highlighted => setSourceActionImage(
      button,
      button.disabled ? "disabled" : button.dataset.pressed === "true" ? "pressed" : highlighted ? "hover" : "rest",
    );
    button.addEventListener("pointerenter", () => restore(true));
    button.addEventListener("pointerleave", () => restore(false));
    button.addEventListener("focus", () => restore(true));
    button.addEventListener("blur", () => restore(false));
    button.addEventListener("pointerdown", event => {
      if (button.disabled) return;
      delete button.dataset.cancelClick;
      button.setPointerCapture?.(event.pointerId);
      button.dataset.pressed = "true";
      setSourceActionImage(button, "pressed");
    });
    button.addEventListener("pointerup", event => {
      const bounds = button.getBoundingClientRect();
      const highlighted = event.clientX >= bounds.left && event.clientX <= bounds.right
        && event.clientY >= bounds.top && event.clientY <= bounds.bottom;
      if (!highlighted) button.dataset.cancelClick = "true";
      if (button.hasPointerCapture?.(event.pointerId)) button.releasePointerCapture(event.pointerId);
      delete button.dataset.pressed;
      restore(highlighted);
    });
    button.addEventListener("pointercancel", event => {
      button.dataset.cancelClick = "true";
      if (button.hasPointerCapture?.(event.pointerId)) button.releasePointerCapture(event.pointerId);
      delete button.dataset.pressed;
      restore(false);
    });
    button.addEventListener("keydown", event => {
      if (!button.disabled && ["Enter", " "].includes(event.key)) {
        button.dataset.pressed = "true";
        setSourceActionImage(button, "pressed");
      }
    });
    button.addEventListener("keyup", event => {
      if (["Enter", " "].includes(event.key)) {
        delete button.dataset.pressed;
        restore(true);
      }
    });
    button.addEventListener("click", event => {
      const canceled = button.dataset.cancelClick === "true";
      delete button.dataset.cancelClick;
      if (canceled) {
        event.preventDefault();
        return;
      }
      action.run().catch(error => { el("status").textContent = error.message; });
    });
    setSourceActionImage(button, button.disabled ? "disabled" : "rest");
    stage.append(button);
  }
}

function applyClassicActionArt(button, baseSlot) {
  // The intact Classic board owns the original control artwork. External
  // accessible controls remain ordinary CoreChat buttons and never repeat an
  // unrelated crop from that board.
  return button;
}

function stopClassicAnimation(reason) {
  if (classicAnimationTimer) clearTimeout(classicAnimationTimer);
  classicAnimationTimer = 0;
  for (const timer of scheduledSoundTimers) clearTimeout(timer);
  scheduledSoundTimers.clear();
  pendingClassicMotion = null;
  trace("classic-animation-stopped", null, { reason });
}

function motionLength(gameId, type) {
  const source = classicSourceMap(gameId)?.motion || {};
  if (["checkers-move", "checkers-capture"].includes(type)) {
    return checkersMoveMotionLength(pendingClassicMotion?.move, pendingClassicMotion?.before);
  }
  if (type === "checkers-win") return Number(pendingClassicMotion?.leadDurationMs || 0)
    + source.decisiveTerminal.durationMs;
  if (type === "chess-move") return source.pieceSlide.durationMs;
  if (type === "chess-capture") return chessCaptureDuration() + source.pieceSlide.durationMs;
  if (type === "chess-checkmate") return Number(pendingClassicMotion?.leadDurationMs || 0) + source.checkmateFlag.durationMs;
  if (["point-move", "point-bear-off"].includes(type)) return pointMoveDuration(gameId, type, pendingClassicMotion)
    + Number(pendingClassicMotion?.noLegalMove?.durationMs || 0);
  if (type === "point-hit") return pointMoveDuration(gameId, type, pendingClassicMotion)
    + Number(pendingClassicMotion?.noLegalMove?.durationMs || 0);
  if (type === "backgammon-dice-roll") return 1467 + Number(pendingClassicMotion?.noLegalMove?.durationMs || 0);
  if (type === "point-no-legal-move") return Number(pendingClassicMotion?.noLegalMove?.durationMs || 0);
  if (["backgammon-win", "acey-deucy-win"].includes(type)) {
    return Number(pendingClassicMotion?.leadDurationMs || 0) + (gameId === "acey-deucy" ? aceyNativeWin()?.durationMs || source.win.durationMs : source.win.durationMs);
  }
  if (type === "battleship-auto-placement") return source.autoPlacement.durationMs;
  if (type === "battleship-shot") return source.shot.durationMs;
  if (type === "battleship-hit") return source.hit.durationMs;
  if (type === "battleship-miss") return source.miss.durationMs;
  if (type === "battleship-sunk") return battleshipAttackTimeline(pendingClassicMotion).settleMs;
  if (type === "battleship-win") return Number(pendingClassicMotion?.leadDurationMs || 0) + source.victoryFlag.durationMs;
  if (type === "spades-card-play") return source.cardPlay.durationMs;
  return 0;
}

function pointHitMotionLength(hitToBar = {}) {
  if (hitToBar.sequence === "captured-then-mover") {
    return Math.max(
      Number(hitToBar.capturedDurationMs || 0),
      Number(hitToBar.moverDelayMs || 0) + Number(hitToBar.moverDurationMs || 0),
    );
  }
  return Math.max(
    Number(hitToBar.moverDurationMs || 0),
    Number(hitToBar.capturedDelayMs || 0) + Number(hitToBar.capturedDurationMs || 0),
  );
}

function checkerCellBox(gameId, row, column) {
  const source = classicSourceMap(gameId);
  if (gameId === "checkers") {
    const projected = checkersSourceCoordinates(row, column);
    const geometry = source.gridRows[projected.row];
    return { x: geometry.x + geometry.step * projected.column, y: geometry.y, width: geometry.width, height: geometry.height };
  }
  const projected = squareVisualCoordinates(gameId, row, column);
  const top = source.grid.rowEdges[projected.row];
  const bottom = source.grid.rowEdges[projected.row + 1];
  const centerY = (top + bottom) / 2;
  const perspective = (centerY - source.grid.rowEdges[0]) / (source.grid.rowEdges.at(-1) - source.grid.rowEdges[0]);
  const left = source.grid.topLeft + (source.grid.bottomLeft - source.grid.topLeft) * perspective;
  const right = source.grid.topRight + (source.grid.bottomRight - source.grid.topRight) * perspective;
  const width = (right - left) / 8;
  return { x: left + width * projected.column, y: top, width, height: bottom - top };
}

function squareBoardPerspective(gameId) {
  const state = session?.state || {};
  if (gameId === "chess") {
    const side = safe(state.colorAssignments?.[String(currentUserId())]);
    return side === "b"
      ? { flip:true, projection:"black-viewer" }
      : { flip:false, projection:side === "w" ? "white-viewer" : "spectator-stable-white" };
  }
  if (gameId === "checkers") {
    const side = safe(state.sideAssignments?.[String(currentUserId())]);
    return side === "a"
      ? { flip:true, projection:"role-a" }
      : { flip:false, projection:side === "b" ? "role-b" : "spectator-stable-role-b" };
  }
  return { flip:false, projection:"fixed" };
}

function squareVisualCoordinates(gameId, row, column) {
  const perspective = squareBoardPerspective(gameId);
  return perspective.flip
    ? { row:7 - Number(row), column:7 - Number(column), projection:perspective.projection }
    : { row:Number(row), column:Number(column), projection:perspective.projection };
}

function squareLogicalCoordinates(gameId, visualRow, visualColumn) {
  return squareVisualCoordinates(gameId, visualRow, visualColumn);
}

function checkersSourceCoordinates(row, column) {
  return squareVisualCoordinates("checkers", row, column);
}

function checkersPromotionStrip(piece, destination) {
  const side = String(piece || "").toLowerCase();
  if (!['a', 'b'].includes(side) || !Array.isArray(destination)) return null;
  const visualRow = checkersSourceCoordinates(Number(destination[0]), Number(destination[1])).row;
  const perspective = visualRow >= 4 ? "near" : "far";
  const source = classicSourceMap("checkers").motion.promotion;
  return Object.entries(source.strips).find(([, definition]) => definition.side === side && definition.perspective === perspective) || null;
}

function checkersMoveMotionLength(move, before) {
  if (!move) return 0;
  const source = classicSourceMap("checkers").motion;
  let duration = Number(source.checkerSlide.durationMs);
  if (move.captured) {
    duration = Math.max(duration,
      Number(source.capture.projectile.delayMs)
      + Number(source.capture.projectile.durationMs)
      + Number(source.capture.explosion.durationMs));
  }
  if (move.promoted) {
    const piece = before?.board?.[move.from?.[0]]?.[move.from?.[1]];
    const strip = checkersPromotionStrip(piece, move.to);
    if (strip) duration += Number(strip[1].frameCount) * Number(source.promotion.frameDurationMs);
  }
  return duration;
}

function pointSourceBox(gameId, location, actorUserId, state) {
  const source = classicSourceMap(gameId);
  const playerIndex = Math.max(0, (state?.turnOrder || []).map(Number).indexOf(Number(actorUserId)));
  if (location === "bar") return source.bar;
  if (location === "borne-off") return source.borneOff[playerIndex] || source.borneOff[0];
  const point = typeof location === "string" && location.startsWith("point:") ? Number(location.slice(6)) : Number(location);
  const row = source.pointRows[point < 12 ? 0 : 1];
  const pointColumn = Number(source.pointOrder?.[point] ?? point % 12);
  return { x: source.pointX[pointColumn], y: row.y, width: row.width, height: row.height };
}

function pointStateCount(state, actorUserId, location) {
  const userKey = String(actorUserId);
  if (location === "bar") return Number(state?.bar?.[userKey] || 0);
  if (location === "borne-off") return Number(state?.borneOff?.[userKey] || 0);
  const point = typeof location === "string" && location.startsWith("point:") ? Number(location.slice(6)) : Number(location);
  return Number(state?.points?.[userKey]?.[point] || 0);
}

function pointBorneDirection(gameId, playerIndex) {
  // Both original rack coordinate branches subtract seven pixels per checker.
  return "up";
}

function sourceStackCheckerBox(gameId, location, actorUserId, state) {
  const source = classicSourceMap(gameId);
  const sourceGeometry = pointSourceBox(gameId, location, actorUserId, state);
  const count = Math.max(1, pointStateCount(state, actorUserId, location));
  const checkerSize = 25;
  const playerIndex = Math.max(0, (state?.turnOrder || []).map(Number).indexOf(Number(actorUserId)));
  const geometry = location === "bar"
    ? {
        ...sourceGeometry,
        y:Number(sourceGeometry.y) + (playerIndex === 0 ? Number(sourceGeometry.height) / 2 : 0),
        height:Number(sourceGeometry.height) / 2,
      }
    : sourceGeometry;
  const step = count <= 1 ? 0 : Math.max(4, Math.min(checkerSize, (Number(geometry.height) - checkerSize) / (count - 1)));
  const point = typeof location === "string" && location.startsWith("point:") ? Number(location.slice(6)) : Number(location);
  const direction = location === "bar"
    ? (playerIndex === 0 ? "down" : "up")
    : location === "borne-off"
      ? pointBorneDirection(gameId, playerIndex)
      : point < 12 ? "down" : "up";
  const borneProfile = location === "borne-off" ? source.borneOffCheckers : null;
  if (borneProfile) {
    const profileWidth = Number(borneProfile.nativeSizes?.[playerIndex]?.[0] || 25);
    const profileHeight = Number(borneProfile.nativeSizes?.[playerIndex]?.[1] || 5);
    const inset = Number(borneProfile.edgeInsets?.[playerIndex] ?? borneProfile.edgeInset ?? 0);
    const profileStep = Number(borneProfile.stackStep || profileHeight);
    const notchInsets = borneProfile.notchLeftInsets?.[playerIndex] || [];
    const notchCandidate = notchInsets.length ? Number(notchInsets[(count - 1) % notchInsets.length]) : NaN;
    const notchLeftInset = Number.isFinite(notchCandidate)
      ? notchCandidate
      : (Number(geometry.width) - profileWidth) / 2;
    const profileY = direction === "down"
      ? Number(geometry.y) + inset + (count - 1) * profileStep
      : Number(geometry.y) + Number(geometry.height) - inset - profileHeight - (count - 1) * profileStep;
    return {
      x: Number(geometry.x) + notchLeftInset + (profileWidth - checkerSize) / 2,
      y: profileY + (profileHeight - checkerSize) / 2,
      width: checkerSize,
      height: checkerSize,
    };
  }
  return {
    x: Number(geometry.x) + (Number(geometry.width) - checkerSize) / 2,
    y: direction === "down"
      ? Number(geometry.y) + (count - 1) * step
      : Number(geometry.y) + Number(geometry.height) - checkerSize - (count - 1) * step,
    width: checkerSize,
    height: checkerSize,
  };
}

function boardCenter(box) {
  return { x: Number(box.x) + Number(box.width) / 2, y: Number(box.y) + Number(box.height) / 2 };
}

function pointMoveHitOpponent(before, after, move) {
  const actor = Number(move?.actorUserId || 0);
  const opponent = (after?.turnOrder || []).map(Number).find(userId => userId !== 0 && userId !== actor && (userId > 0 || after?.bots?.[String(userId)]?.userId === userId)) || 0;
  return opponent !== 0
    && Number(after?.bar?.[String(opponent)] || 0) > Number(before?.bar?.[String(opponent)] || 0);
}

function isCheckersDecisiveTerminalReason(reason) {
  return ["no-legal-move", "all-pieces-captured", "resignation", "forfeit", "forfeiture", "clock-expiration"]
    .includes(String(reason || "").toLowerCase());
}

function backgammonRolledDice(before, after) {
  if ((after.history || []).length !== (before.history || []).length) return null;
  const opening = (after.openingRollAttempts || []).length > (before.openingRollAttempts || []).length;
  const blocked = after.lastNoLegalMove || after.lastBlockedRoll;
  const newBlocked = blocked && JSON.stringify(blocked) !== JSON.stringify(before.lastNoLegalMove || before.lastBlockedRoll);
  const rolled = before.backgammonStage === "roll"
    && (after.backgammonStage !== "roll" || (after.remainingDice || []).length > 0 || newBlocked);
  if (!opening && !rolled) return null;
  const dice = opening ? Object.values(after.openingRollAttempts.at(-1).rolls || {})
    : newBlocked ? blocked.dice : after.dice;
  return Array.isArray(dice) && dice.length === 2 && dice.every(value => Number.isInteger(Number(value)) && Number(value) >= 1 && Number(value) <= 6)
    ? dice.map(Number) : null;
}

function classifyClassicMotion(previous, current) {
  if (!previous || !current || Number(previous.stateVersion) === Number(current.stateVersion)) return null;
  const before = previous.state || {};
  const after = current.state || {};
  const base = { pointNative: pointNativeReady(), gameId: context.extensionId, version: Number(current.stateVersion), before, after, startedAt: performance.now() };
  if (!before.completed && after.completed && (Number(after.winnerUserId || 0) > 0 || (["backgammon-first-party", "uno", "hearts"].includes(context.extensionId) && Number(after.winnerUserId || 0) < 0 && after.bots?.[String(after.winnerUserId)]?.userId === after.winnerUserId))) {
    const latestMove = listLength(after.history) > listLength(before.history) ? latest(after.history) : null;
    const terminalReason = String(after.terminalReason || "").toLowerCase();
    if (context.extensionId === "chess" && terminalReason.includes("checkmate")) return {
      ...base, type: "chess-checkmate", move: latestMove,
      leadDurationMs: latestMove
        ? classicSourceMap("chess").motion.pieceSlide.durationMs
          + (latestMove.capture ? chessCaptureDuration({before,after,move:latestMove}) : 0)
        : 0,
    };
    if (context.extensionId === "checkers" && isCheckersDecisiveTerminalReason(terminalReason)) {
      return {
        ...base, type: "checkers-win", move: latestMove,
        winnerSide: String(after.sideAssignments?.[String(after.winnerUserId)] || ""),
        leadDurationMs: latestMove ? checkersMoveMotionLength(latestMove, before) : 0,
      };
    }
    if (context.extensionId === "battleship" && terminalReason === "fleet-sunk") {
      const attack = listLength(after.attackHistory) > listLength(before.attackHistory) ? latest(after.attackHistory) : null;
      const resultType = String(attack?.result || "sunk") === "sunk" ? "battleship-sunk" : "battleship-hit";
      return {
        ...base, type: "battleship-win", attack, resultType,
        leadDurationMs: attack ? battleshipAttackTimeline({ attack, after }).settleMs : 0,
      };
    }
    if (context.extensionId === "backgammon-first-party" && String(after.terminalCause || "") === "bear-off") return {
      ...base, type: "backgammon-win", move: latestMove,
      leadDurationMs: latestMove ? pointMoveDuration("backgammon-first-party", "point-bear-off", base) : 0,
    };
    if (context.extensionId === "acey-deucy" && latestMove?.to === "borne-off") return {
      ...base, type: "acey-deucy-win", move: latestMove,
      leadDurationMs: pointMoveDuration("acey-deucy", "point-bear-off", base),
    };
  }
  if (["checkers", "chess"].includes(context.extensionId) && listLength(after.history) > listLength(before.history)) {
    const move = latest(after.history) || {};
    return { ...base, type: context.extensionId === "checkers"
      ? (move.captured ? "checkers-capture" : "checkers-move")
      : (move.capture ? "chess-capture" : "chess-move"), move };
  }
  if (["acey-deucy", "backgammon-first-party"].includes(context.extensionId)) {
    if (listLength(after.history) > listLength(before.history)) {
      const move = latest(after.history) || {};
      const opponentBarIncreased = pointMoveHitOpponent(before, after, move);
      const noLegalMove = after.lastNoLegalMove || null;
      const changedNoLegalMove = noLegalMove
        && JSON.stringify(before.lastNoLegalMove || null) !== JSON.stringify(noLegalMove);
      return {
        ...base,
        type: move.to === "borne-off" ? "point-bear-off" : opponentBarIncreased ? "point-hit" : "point-move",
        move,
        ...(changedNoLegalMove ? { noLegalMove } : {}),
      };
    }
    const rolledDice = context.extensionId === "backgammon-first-party" ? backgammonRolledDice(before, after) : null;
    if (rolledDice) {
      const blocked = after.lastNoLegalMove || after.lastBlockedRoll || null;
      const changedBlocked = blocked && JSON.stringify(blocked) !== JSON.stringify(before.lastNoLegalMove || before.lastBlockedRoll || null);
      return { ...base, type: "backgammon-dice-roll", dice: rolledDice, ...(changedBlocked ? { noLegalMove: blocked } : {}) };
    }
    const actor = Number(previous.turnUserId || 0);
    const noLegalMove = after.lastNoLegalMove || after.lastBlockedRoll || null;
    if (noLegalMove
      && Number(noLegalMove.actorUserId || 0) === actor
      && JSON.stringify(before.lastNoLegalMove || before.lastBlockedRoll || null) !== JSON.stringify(noLegalMove)
      && JSON.stringify(before.points || {}) === JSON.stringify(after.points || {})
      && JSON.stringify(before.bar || {}) === JSON.stringify(after.bar || {})
      && JSON.stringify(before.borneOff || {}) === JSON.stringify(after.borneOff || {})
      && listLength(after.history) === listLength(before.history)) {
      return {
        ...base,
        type: "point-no-legal-move",
        actorUserId: actor,
        dice: noLegalMove.dice || [],
        noLegalMove,
      };
    }
  }
  if (context.extensionId === "battleship") {
    if (listLength(after.attackHistory) > listLength(before.attackHistory)) {
      const attack = latest(after.attackHistory) || {};
      const result = String(attack.result || "miss");
      return { ...base, type: result === "sunk" ? "battleship-sunk" : result === "hit" ? "battleship-hit" : "battleship-miss", attack };
    }
    const viewer = String(currentUserId());
    if (after.phase === "placement" && JSON.stringify(before.fleets?.[viewer]?.ships || []) !== JSON.stringify(after.fleets?.[viewer]?.ships || [])) {
      return after.lastPlacement?.kind === "auto"
        ? { ...base, type: "battleship-auto-placement" }
        : null;
    }
  }
  if (context.extensionId === "spades") {
    const viewer = String(currentUserId());
    const beforeHand = Array.isArray(before.hands?.[viewer]) ? before.hands[viewer].map(String) : [];
    const afterHand = Array.isArray(after.hands?.[viewer]) ? after.hands[viewer].map(String) : [];
    const playedCard = beforeHand.find(card => !afterHand.includes(card)) || "";
    const play = [...(after.currentTrick || []), ...(after.lastCompletedTrick?.cards || [])]
      .find(item => Number(item?.userId || 0) === currentUserId() && String(item?.card || "") === playedCard);
    if (playedCard && play && beforeHand.length === afterHand.length + 1) {
      return {
        ...base,
        type: "spades-card-play",
        card: playedCard,
        actorUserId: currentUserId(),
        fromIndex: beforeHand.indexOf(playedCard),
        fromCount: beforeHand.length,
        destinationPosition: "bottom",
      };
    }
  }
  return null;
}

function checkersMoveSquareMatches(left, right) {
  const leftSquare = builtInModernSquare(left);
  const rightSquare = builtInModernSquare(right);
  return Boolean(leftSquare && rightSquare
    && leftSquare.row === rightSquare.row
    && leftSquare.column === rightSquare.column);
}

function checkersMotionMatchesOptimistic(motion, optimistic) {
  return Boolean(motion?.move && optimistic
    && checkersMoveSquareMatches(motion.move.from, optimistic.from)
    && checkersMoveSquareMatches(motion.move.to, optimistic.to));
}

function startOptimisticBuiltInCheckersMove(from, to) {
  if (context.extensionId !== "checkers" || session?.presentation?.effectivePack === "classic") return;
  const before = JSON.parse(JSON.stringify(session?.state || {}));
  const board = Array.isArray(before.board) ? before.board : null;
  const movingPiece = board?.[Number(from?.[0])]?.[Number(from?.[1])];
  if (!movingPiece) return;
  const after = JSON.parse(JSON.stringify(before));
  const afterBoard = after.board;
  const rowDistance = Math.abs(Number(to[0]) - Number(from[0]));
  const columnDistance = Math.abs(Number(to[1]) - Number(from[1]));
  const capturedSquare = rowDistance === 2 && columnDistance === 2
    ? [Math.round((Number(from[0]) + Number(to[0])) / 2), Math.round((Number(from[1]) + Number(to[1])) / 2)]
    : null;
  const capturedPiece = capturedSquare ? afterBoard?.[capturedSquare[0]]?.[capturedSquare[1]] : "";
  const movingSide = String(movingPiece).toLowerCase();
  const promoted = String(movingPiece) === movingSide
    && ((movingSide === "a" && Number(to[0]) === 7) || (movingSide === "b" && Number(to[0]) === 0));
  afterBoard[Number(from[0])][Number(from[1])] = "";
  if (capturedSquare) afterBoard[capturedSquare[0]][capturedSquare[1]] = "";
  afterBoard[Number(to[0])][Number(to[1])] = promoted ? String(movingPiece).toUpperCase() : movingPiece;
  const opponentRemains = afterBoard.some((row) => Array.isArray(row)
    && row.some((piece) => piece && String(piece).toLowerCase() !== movingSide));
  const likelyWin = Boolean(capturedPiece) && !opponentRemains;
  const startedAt = performance.now();
  const move = {
    actorUserId: currentUserId(),
    from: [...from],
    to: [...to],
    captured: Boolean(capturedPiece),
    promoted,
  };
  clearTimeout(classicAnimationTimer);
  classicAnimationTimer = 0;
  pendingClassicMotion = {
    gameId: "checkers",
    version: `optimistic-${Number(session?.stateVersion || 0)}`,
    before,
    after,
    startedAt,
    type: likelyWin ? "checkers-win" : capturedPiece ? "checkers-capture" : "checkers-move",
    move,
    winnerSide: likelyWin ? movingSide : "",
    winnerName: likelyWin ? memberName(currentUserId()) : "",
    leadDurationMs: capturedPiece ? 2100 : 760,
  };
  optimisticBuiltInCheckersMotion = {
    from: [...from],
    to: [...to],
    startedAt,
    captureSoundScheduled: Boolean(capturedPiece),
    terminalSoundScheduled: likelyWin,
  };
  if (capturedPiece) {
    playBuiltInGameSoundSequence([
      [BUILT_IN_PUBLIC_SOUNDS.checkerMissileLaunch, "checkers-missile-launch-optimistic", 260, .84],
      [BUILT_IN_PUBLIC_SOUNDS.checkerExplosion, "checkers-missile-impact-optimistic", 760, .92],
      [BUILT_IN_PUBLIC_SOUNDS.checkerExplosionRumble, "checkers-missile-impact-rumble-optimistic", 760, .62],
      [likelyWin ? BUILT_IN_PUBLIC_SOUNDS.success : "", "terminal-result-optimistic", 2200, .72],
    ]);
  }
  trace("optimistic-checkers-motion-started", null, {
    type: pendingClassicMotion.type,
    from: [...from],
    to: [...to],
    captured: Boolean(capturedPiece),
    likelyWin,
  });
}

function reconcileOptimisticBuiltInCheckersMotion(authoritativeMotion, version) {
  const optimistic = optimisticBuiltInCheckersMotion;
  if (!checkersMotionMatchesOptimistic(authoritativeMotion, optimistic)) return false;
  clearTimeout(classicAnimationTimer);
  classicAnimationTimer = 0;
  pendingClassicMotion = { ...authoritativeMotion, startedAt: optimistic.startedAt };
  reconciledBuiltInCheckersSound = {
    version: Number(version || 0),
    startedAt: optimistic.startedAt,
    captureSoundScheduled: optimistic.captureSoundScheduled,
    terminalSoundScheduled: optimistic.terminalSoundScheduled,
  };
  optimisticBuiltInCheckersMotion = null;
  trace("optimistic-checkers-motion-reconciled", null, {
    type: pendingClassicMotion.type,
    version: Number(version || 0),
  });
  return true;
}

function chessCastlingRook(before, move, after = null) {
  const [row, column] = move?.from || [];
  const [targetRow, targetColumn] = move?.to || [];
  const king = before?.board?.[row]?.[column];
  if (!['wK', 'bK'].includes(king) || column !== 4
      || row !== (king[0] === 'w' ? 7 : 0) || targetRow !== row
      || ![2, 6].includes(targetColumn) || move.capture || move.promotion) return null;
  const kingside = targetColumn === 6;
  const rook = king[0] + 'R';
  const from = [row, kingside ? 7 : 0];
  const to = [row, kingside ? 5 : 3];
  const corridor = kingside ? [5, 6] : [1, 2, 3];
  if (before.board[row][from[1]] !== rook
      || corridor.some(file => before.board[row][file] != null)) return null;
  if (!after) {
    // This is only a visual prediction for an already selected legal action.
    // The server remains responsible for attacked squares and move legality.
    if (!before.castling?.[king[0] + (kingside ? 'K' : 'Q')]) return null;
  } else {
    const expected = new Map([[`${row}:4`, null], [`${row}:${targetColumn}`, king],
      [`${row}:${from[1]}`, null], [`${row}:${to[1]}`, rook]]);
    for (let r = 0; r < 8; r++) for (let c = 0; c < 8; c++) {
      const key = `${r}:${c}`;
      if (!Array.isArray(after.board?.[r]) || after.board[r][c] !==
          (expected.has(key) ? expected.get(key) : before.board?.[r]?.[c])) return null;
    }
  }
  return { from, to, piece: rook };
}

function startOptimisticBuiltInChessMove(from, to) {
  if (context.extensionId !== "chess" || session?.presentation?.effectivePack !== "built-in") return;
  const before = JSON.parse(JSON.stringify(session?.state || {}));
  const board = Array.isArray(before.board) ? before.board : null;
  const movingPiece = board?.[Number(from?.[0])]?.[Number(from?.[1])];
  if (!movingPiece) return;
  const after = JSON.parse(JSON.stringify(before));
  const capturedPiece = after.board?.[Number(to?.[0])]?.[Number(to?.[1])] || null;
  after.board[Number(from[0])][Number(from[1])] = null;
  after.board[Number(to[0])][Number(to[1])] = movingPiece;
  const castle = chessCastlingRook(before, { from, to });
  if (castle) {
    after.board[castle.from[0]][castle.from[1]] = null;
    after.board[castle.to[0]][castle.to[1]] = castle.piece;
  }
  const likelyCheckmate = Boolean(chessCaptureAuditId() && capturedPiece);
  const startedAt = performance.now();
  const move = {
    actorUserId: currentUserId(),
    from: [...from],
    to: [...to],
    piece: movingPiece,
    capture: Boolean(capturedPiece),
    check: likelyCheckmate,
    promotion: null,
  };
  clearTimeout(classicAnimationTimer);
  classicAnimationTimer = 0;
  pendingClassicMotion = {
    gameId: "chess",
    version: `optimistic-${Number(session?.stateVersion || 0)}`,
    before,
    after,
    startedAt,
    type: likelyCheckmate ? "chess-checkmate" : capturedPiece ? "chess-capture" : "chess-move",
    move,
    winnerName: likelyCheckmate ? memberName(currentUserId()) : "",
    leadDurationMs: capturedPiece ? 2800 : 720,
  };
  optimisticBuiltInChessMotion = {
    from: [...from],
    to: [...to],
    startedAt,
    moveSoundScheduled: true,
    terminalSoundScheduled: likelyCheckmate,
  };
  playBuiltInGameSoundSequence([
    [capturedPiece ? BUILT_IN_PUBLIC_SOUNDS.chessPieceCapture : BUILT_IN_PUBLIC_SOUNDS.chessPieceSlide,
      capturedPiece ? "chess-capture-drop-optimistic" : "chess-move-optimistic", capturedPiece ? 520 : 0, .58],
    [capturedPiece ? BUILT_IN_PUBLIC_SOUNDS.chessPortalSink : "", "chess-capture-portal-descent-optimistic", 610, .98],
    [capturedPiece ? BUILT_IN_PUBLIC_SOUNDS.checkerExplosionRumble : "", "chess-capture-seal-rumble-optimistic", 1450, .56],
    [likelyCheckmate ? BUILT_IN_PUBLIC_SOUNDS.chessCheckmate : "", "chess-checkmate-result-optimistic", 0, .82, "voice"],
  ]);
  trace("optimistic-chess-motion-started", null, {
    type: pendingClassicMotion.type,
    from: [...from],
    to: [...to],
    captured: Boolean(capturedPiece),
    likelyCheckmate,
  });
  if (castle) render();
}

function reconcileOptimisticBuiltInChessMotion(authoritativeMotion, version) {
  const optimistic = optimisticBuiltInChessMotion;
  if (!checkersMotionMatchesOptimistic(authoritativeMotion, optimistic)) return false;
  clearTimeout(classicAnimationTimer);
  classicAnimationTimer = 0;
  pendingClassicMotion = { ...authoritativeMotion, startedAt: optimistic.startedAt };
  reconciledBuiltInChessSound = {
    version: Number(version || 0),
    startedAt: optimistic.startedAt,
    moveSoundScheduled: optimistic.moveSoundScheduled,
    terminalSoundScheduled: optimistic.terminalSoundScheduled,
  };
  optimisticBuiltInChessMotion = null;
  trace("optimistic-chess-motion-reconciled", null, {
    type: pendingClassicMotion.type,
    version: Number(version || 0),
  });
  return true;
}

function syncClassicAnimation() {
  if (!pendingClassicMotion) return;
  const presentationPack = session?.presentation?.effectivePack || "built-in";
  if (presentationPack === "classic" && pendingClassicMotion.gameId === "chess"
      && pendingClassicMotion.type === "chess-move"
      && chessCastlingRook(pendingClassicMotion.before, pendingClassicMotion.move, pendingClassicMotion.after)) {
    pendingClassicMotion = null;
    return;
  }
  if (presentationPack === "corechat") {
    pendingClassicMotion = null;
    return;
  }
  if (presentationPack !== "classic") {
    const duration = Number(BUILT_IN_MODERN_MOTION_MS?.[pendingClassicMotion.type] || 0);
    const enabled = duration > 0 && optionCategory("visualFxEnabled", true);
    if (!enabled) {
      pendingClassicMotion = null;
      return;
    }
    const remaining = Math.max(1, duration - (performance.now() - pendingClassicMotion.startedAt));
    classicAnimationTimer = setTimeout(() => {
      classicAnimationTimer = 0;
      const unresolvedOptimisticMotion = String(pendingClassicMotion?.version || "").startsWith("optimistic-")
        && ((pendingClassicMotion?.gameId === "checkers" && optimisticBuiltInCheckersMotion)
          || (pendingClassicMotion?.gameId === "chess" && optimisticBuiltInChessMotion));
      if (unresolvedOptimisticMotion) {
        // Network confirmation can take longer than the visual travel time.
        // Keep the actor parked on its destination until authoritative state
        // can replace it; clearing here exposes a blank/reverted frame.
        trace("optimistic-square-motion-held", null, {
          type: pendingClassicMotion.type,
          reason: "awaiting-authoritative-state",
        });
        return;
      }
      pendingClassicMotion = null;
      trace("classic-animation-stopped", null, { reason: "built-in-sequence-complete" });
      render();
    }, remaining);
    return;
  }
  const duration = motionLength(pendingClassicMotion.gameId, pendingClassicMotion.type);
  if (!duration || classicAnimationTimer) return;
  const reduced = !optionCategory("visualFxEnabled", true) && matchMedia("(prefers-reduced-motion: reduce)").matches;
  const enabled = optionCategory("visualFxEnabled", true) && !reduced;
  trace(enabled ? "classic-animation-started" : "classic-animation-suppressed", null, {
    type: pendingClassicMotion.type, durationMs: enabled ? duration : 0,
    reason: reduced ? "reduced-motion" : enabled ? "source-motion" : "visual-fx-off",
  });
  if (!enabled) {
    pendingClassicMotion = null;
    return;
  }
  const remaining = Math.max(1, duration - (performance.now() - pendingClassicMotion.startedAt));
  classicAnimationTimer = setTimeout(() => {
    classicAnimationTimer = 0;
    pendingClassicMotion = null;
    trace("classic-animation-stopped", null, { reason: "source-sequence-complete" });
    render();
  }, remaining);
}

function trace(event, slot = null, details = {}) {
  const record = { event, slot, at: new Date().toISOString(), ...details };
  mediaTrace.push(record);
  if (mediaTrace.length > MEDIA_TRACE_LIMIT) mediaTrace.splice(0, mediaTrace.length - MEDIA_TRACE_LIMIT);
  if (/^(?:state-version|stale-session|classic-)/.test(event)) {
    document.body.dataset.lastClassicTrace = JSON.stringify(record);
  }
  if (/^(?:audio-|effect-|music-|all-audio)/.test(event)) {
    document.body.dataset.lastMediaTrace = JSON.stringify(record);
  }
}

let originalAudioDetailsOpen = false;
let originalAudioOtherOpen = false;
let originalVoiceTimer = 0;
let originalVoiceTurn = "";
let originalVoiceStartedAt = 0;
let originalVoiceDone = new Set();
let originalVoiceActive = "";
let originalPreviewActive = "";
let originalAudioEpoch = 0;

function originalAudioSurfaceActive() {
  return session?.presentation?.effectivePack === "classic" && options?.effectsEnabled !== false
    && gameSurfaceVisible && !document.hidden && !terminalSessionError
    && !session?.state?._framework?.serviceInterruption?.active
    && !["paused","resuming"].includes(session?.state?._framework?.pause?.mode);
}
function stopOriginalVoice() {
  if (originalVoiceActive) { const audio=audioPlayers.get(originalVoiceActive);audio?.pause(); }
  originalVoiceActive="";
}
function stopOriginalPreview() {
  if (originalPreviewActive) audioPlayers.get(originalPreviewActive)?.pause();
  originalPreviewActive="";
}
function cancelOriginalAudio() {
  clearTimeout(originalVoiceTimer);originalVoiceTimer=0;originalAudioEpoch++;
  for(const item of originalReminderPlan(context.extensionId,options))originalVoiceDone.add(item.delay);
  stopOriginalVoice();stopOriginalPreview();
}
function previewOriginalAudio(slot) {
  if (!originalAudioSurfaceActive()) return;
  stopOriginalVoice();stopOriginalPreview();originalPreviewActive=slot;playSound(slot);
}
function renderOriginalAudioOptions() {
  const entries=originalAudioCatalog[context.extensionId];
  if (!entries || session?.presentation?.effectivePack!=="classic") return null;
  const panel=make("details","classic-audio-options");panel.open=originalAudioDetailsOpen;
  panel.append(make("summary","","Optional voices and sound previews"));
  panel.addEventListener("toggle",()=>{originalAudioDetailsOpen=panel.open;});
  panel.append(make("p","classic-audio-note","Extra voices are off by default. These choices apply only to you. Sound FX must be on."));
  const list=make("div","classic-audio-list");
  const add=(entry,host)=>{
    const row=make("div","classic-audio-row");
    if (entry.option) {
      const label=make("label");const check=make("input");check.type="checkbox";check.checked=originalVoiceEnabled(options,entry);
      check.id=`original-voice-${entry.slot}`;check.setAttribute("aria-label",entry.label);
      check.addEventListener("change",async()=>{
        const next=check.checked;check.disabled=true;
        if (!next && originalVoiceActive===entry.slot) stopOriginalVoice();
        try { if(await updateViewerOptions({...options,categories:{...(options?.categories||{}),[entry.option]:next}})) { render();scheduleOriginalVoices(); } }
        catch(error){check.checked=!next;el("status").textContent=error.message;} finally{check.disabled=false;}
      });label.append(check,document.createTextNode(` ${entry.label}`));row.append(label);
    } else row.append(make("span","",entry.label+(entry.usedByOriginal?"":" (preview only)")));
    const play=make("button","", "Play");play.type="button";play.setAttribute("aria-label",`Play ${entry.label}`);play.disabled=!originalAudioSurfaceActive();
    play.addEventListener("click",()=>previewOriginalAudio(entry.slot));row.append(play);host.append(row);
  };
  for (const entry of entries.filter(c=>c.option))add(entry,list);
  panel.append(list);
  const other=make("details","classic-audio-other");other.open=originalAudioOtherOpen;other.append(make("summary","","Other original sounds"));
  other.addEventListener("toggle",()=>{originalAudioOtherOpen=other.open;});
  for (const entry of entries.filter(c=>!c.option))add(entry,other);
  other.append(make("p","classic-audio-note","Preview-only clips have no located original playback trigger."));panel.append(other);return panel;
}
function scheduleOriginalVoices(previous = null, current = session) {
  clearTimeout(originalVoiceTimer);originalVoiceTimer=0;
  const key=current && Number(current.turnUserId)===currentUserId() && current.status==="active" && !current.state?.completed
    ? `${current.publicId}:${currentUserId()}:${current.state?.phase||""}` : "";
  if (key!==originalVoiceTurn || (previous && Number(previous.turnUserId)!==Number(current?.turnUserId))) {
    originalVoiceTurn=key;originalVoiceStartedAt=Date.now();originalVoiceDone.clear();stopOriginalVoice();
    // A loaded/reconnected view must not speak an old turn-start reminder.
    if (!previous) originalVoiceDone.add(0);
  }
  if (!key || !originalAudioSurfaceActive() || options?.voiceEnabled===false || !gameLifecycleAvailable()) {stopOriginalVoice();return;}
  const plan=originalReminderPlan(context.extensionId,options);
  const next=plan.find(item=>!originalVoiceDone.has(item.delay));
  if (!next) return;
  originalVoiceTimer=setTimeout(()=>{
    originalVoiceTimer=0;
    if (!originalAudioSurfaceActive() || !canAct() || options?.voiceEnabled===false) return;
    const playing=[...audioPlayers.values()].some(a=>!a.paused && !a.ended);
    if (playing || pendingClassicMotion) {originalVoiceTimer=setTimeout(()=>scheduleOriginalVoices(),250);return;}
    const live=originalReminderPlan(context.extensionId,options).find(p=>p.delay===next.delay);
    originalVoiceDone.add(next.delay);
    if (live?.slots.length) {
      const slot=live.slots[Math.floor(Math.random()*live.slots.length)];originalVoiceActive=slot;
      trace("original-optional-voice",slot,{reason:"source-turn-reminder",delayMs:next.delay});playSound(slot);
    }
    scheduleOriginalVoices();
  },Math.max(0,next.delay-(Date.now()-originalVoiceStartedAt)));
}
function scheduleOriginalRollVoice(slot, diceAudio, stateVersion) {
  if (!slot || !diceAudio) return;
  const epoch=originalAudioEpoch,publicId=session.publicId;
  const play=()=>{
    if (epoch!==originalAudioEpoch || publicId!==session?.publicId || Number(session.stateVersion)!==Number(stateVersion) || !originalAudioSurfaceActive()) return;
    const entry=(originalAudioCatalog[context.extensionId]||[]).find(c=>c.slot===slot);
    if(entry?.option && (!originalVoiceEnabled(options,entry)||options?.voiceEnabled===false))return;
    if(pendingClassicMotion && /dice-roll/.test(pendingClassicMotion.type)) {
      const timer=setTimeout(()=>{scheduledSoundTimers.delete(timer);play();},80);scheduledSoundTimers.add(timer);return;
    }
    if(entry?.option)originalVoiceActive=slot;
    trace("original-roll-announcement",slot,{stateVersion});playSound(slot);
  };
  if(diceAudio.ended)play();else diceAudio.addEventListener("ended",play,{once:true});
}

function playSound(slot) {
  if (!slot || session?.presentation?.effectivePack !== "classic" || options?.effectsEnabled === false) {
    trace("effect-suppressed", slot, { reason: !slot ? "unmapped" : session?.presentation?.effectivePack !== "classic" ? "built-in" : "sound-off" });
    return null;
  }
  let audio = audioPlayers.get(slot);
  if (!audio) {
    audio = new Audio(mediaUrl(slot));
    audio.preload = "none";
    audioPlayers.set(slot, audio);
    trace("audio-created", slot);
  }
  audio.currentTime = 0;
  audio.volume = Math.max(0, Math.min(1, Number(options?.masterVolume ?? 100) / 100));
  trace("effect-play-requested", slot);
  audio.play()
    .then(() => trace("effect-playing", slot))
    .catch(error => trace("effect-unavailable", slot, { errorName: String(error?.name || "Error") }));
  return audio;
}

function prepareBuiltInGameSound(asset) {
  if (!asset) return null;
  const key = `built-in-public:${asset}`;
  let audio = audioPlayers.get(key);
  if (!audio) {
    audio = new Audio(appUrl(`${BUILT_IN_PUBLIC_SOUND_ROOT}/${asset}`));
    audio.preload = "auto";
    audio.load();
    audioPlayers.set(key, audio);
    trace("audio-created", asset, { soundOwner: builtInGameSoundOwner(asset), preload: true });
  }
  return audio;
}

function builtInGameSoundOwner(asset) {
  return CORECHAT_GENERATED_BUILT_IN_SOUNDS.has(asset)
    ? "corechat-generated"
    : "built-in-public-cc0";
}

function playBuiltInGameSound(asset, reason, volumeScale = 1, channel = "effects") {
  const soundOwner = builtInGameSoundOwner(asset);
  const builtIn = session?.presentation?.effectivePack !== "classic";
  const channelEnabled = channel === "voice"
    ? options?.voiceEnabled !== false
    : options?.effectsEnabled !== false;
  if (!asset || !builtIn || !BUILT_IN_PUBLIC_SOUND_GAMES.has(context.extensionId)
    || !channelEnabled || !gameSurfaceVisible) {
    trace("effect-suppressed", asset || null, {
      reason: !asset ? "unmapped" : !builtIn ? "classic-owned" : !BUILT_IN_PUBLIC_SOUND_GAMES.has(context.extensionId)
        ? "different-game" : !channelEnabled ? `${channel}-off` : "surface-hidden",
      soundOwner,
      channel,
    });
    return null;
  }
  const audio = prepareBuiltInGameSound(asset);
  if (!audio) return null;
  audio.currentTime = 0;
  audio.volume = Math.max(0, Math.min(1, (Number(options?.masterVolume ?? 100) / 100) * Number(volumeScale || 1)));
  trace("effect-play-requested", asset, { reason, soundOwner, channel });
  audio.play()
    .then(() => trace("effect-playing", asset, { reason, soundOwner, channel }))
    .catch(error => trace("effect-unavailable", asset, { reason, soundOwner, channel, errorName: String(error?.name || "Error") }));
  return audio;
}

function playBuiltInGameSoundSequence(entries) {
  for (const [asset, reason, delayMs = 0, volumeScale = 1, channel = "effects"] of entries) {
    if (!asset) continue;
    if (Number(delayMs) <= 0) {
      playBuiltInGameSound(asset, reason, volumeScale, channel);
      continue;
    }
    const timer = setTimeout(() => {
      scheduledSoundTimers.delete(timer);
      playBuiltInGameSound(asset, reason, volumeScale, channel);
    }, Number(delayMs));
    scheduledSoundTimers.add(timer);
  }
}

function playBlackjackSound(asset, reason, volumeScale = 1) {
  if (!asset || context.extensionId !== "blackjack" || options?.effectsEnabled === false || !gameSurfaceVisible) {
    trace("effect-suppressed", asset || null, {
      reason: !asset ? "unmapped" : context.extensionId !== "blackjack" ? "different-game" : options?.effectsEnabled === false ? "sound-off" : "surface-hidden",
      soundOwner: "blackjack-public-cc0",
    });
    return null;
  }
  const key = `blackjack-public:${asset}`;
  let audio = audioPlayers.get(key);
  if (!audio) {
    audio = new Audio(appUrl(`${BLACKJACK_PUBLIC_SOUND_ROOT}/${asset}`));
    audio.preload = "auto";
    audioPlayers.set(key, audio);
    trace("audio-created", asset, { soundOwner: "blackjack-public-cc0" });
  }
  audio.currentTime = 0;
  audio.volume = Math.max(0, Math.min(1, (Number(options?.masterVolume ?? 100) / 100) * Number(volumeScale || 1)));
  trace("effect-play-requested", asset, { reason, soundOwner: "blackjack-public-cc0" });
  audio.play()
    .then(() => trace("effect-playing", asset, { reason, soundOwner: "blackjack-public-cc0" }))
    .catch(error => trace("effect-unavailable", asset, { reason, soundOwner: "blackjack-public-cc0", errorName: String(error?.name || "Error") }));
  return audio;
}

function playBlackjackSoundSequence(entries) {
  for (const [asset, reason, delayMs = 0, volumeScale = 1] of entries) {
    if (!asset) continue;
    if (Number(delayMs) <= 0) {
      playBlackjackSound(asset, reason, volumeScale);
      continue;
    }
    const timer = setTimeout(() => {
      scheduledSoundTimers.delete(timer);
      playBlackjackSound(asset, reason, volumeScale);
    }, Number(delayMs));
    scheduledSoundTimers.add(timer);
  }
}

function blackjackHands(state = {}) {
  return Object.values(state?.hands || {}).flatMap(hands => Array.isArray(hands) ? hands : []);
}

function blackjackCardCount(state = {}) {
  return blackjackHands(state).reduce((total, hand) => total + listLength(hand?.cards), 0)
    + listLength(state?.dealer?.cards);
}

function blackjackHandCount(state = {}) {
  return blackjackHands(state).length;
}

function blackjackBetTotal(state = {}) {
  return blackjackHands(state).reduce((total, hand) => total + Number(hand?.bet || 0), 0);
}

function blackjackInsuranceTotal(state = {}) {
  return Object.values(state?.insuranceBets || {}).reduce((total, value) => total + Number(value || 0), 0);
}

function blackjackStatusCount(state = {}, status = "") {
  return blackjackHands(state).filter(hand => String(hand?.status || "") === status).length;
}

function blackjackDealSound(stateVersion, index) {
  const sounds = BLACKJACK_PUBLIC_SOUNDS.deal;
  return sounds[(Math.max(0, Number(stateVersion || 0)) + Number(index || 0)) % sounds.length];
}

function blackjackSettlementSound(state = {}) {
  const viewerResults = (state?.lastSettlement || [])
    .filter(item => Number(item?.userId || 0) === currentUserId())
    .map(item => String(item?.result || ""));
  if (viewerResults.some(result => ["blackjack", "win"].includes(result))) return BLACKJACK_PUBLIC_SOUNDS.win;
  if (viewerResults.length > 0 && viewerResults.every(result => result === "push")) return BLACKJACK_PUBLIC_SOUNDS.push;
  if (viewerResults.some(result => ["dealer-blackjack", "loss", "bust"].includes(result))) return BLACKJACK_PUBLIC_SOUNDS.loss;
  if (viewerResults.some(result => result === "surrender")) return BLACKJACK_PUBLIC_SOUNDS.surrender;
  return "";
}

function playBlackjackTransitionSound(previous, current) {
  const before = previous?.state || {};
  const after = current?.state || {};
  const version = Number(current?.stateVersion || 0);
  const cardDelta = Math.max(0, blackjackCardCount(after) - blackjackCardCount(before));
  const handDelta = blackjackHandCount(after) - blackjackHandCount(before);
  const betDelta = blackjackBetTotal(after) - blackjackBetTotal(before);
  const insuranceDelta = blackjackInsuranceTotal(after) - blackjackInsuranceTotal(before);
  const settlementChanged = listLength(after.lastSettlement) > 0
    && JSON.stringify(after.lastSettlement) !== JSON.stringify(before.lastSettlement);
  const entries = [];

  if (settlementChanged) {
    const shoeReplaced = Number(after.shoeCount || 0) > Number(before.shoeCount || 0) + cardDelta;
    if (shoeReplaced) entries.push([BLACKJACK_PUBLIC_SOUNDS.shuffle, "six-deck-shoe-replaced", 0, .72]);
    const dealStart = shoeReplaced ? 360 : 0;
    for (let index = 0; index < Math.min(4, cardDelta); index += 1) {
      entries.push([blackjackDealSound(version, index), "authoritative-card-dealt", dealStart + index * 125, .76]);
    }
    const revealDelay = dealStart + Math.min(4, cardDelta) * 125;
    if (before.dealer?.revealed !== true && after.dealer?.revealed === true) {
      entries.push([BLACKJACK_PUBLIC_SOUNDS.reveal, "dealer-hole-card-revealed", revealDelay, .78]);
    }
    const viewerReturned = (after.lastSettlement || [])
      .filter(item => Number(item?.userId || 0) === currentUserId())
      .reduce((total, item) => total + Number(item?.returned || 0), 0);
    const resultDelay = revealDelay + 230;
    if (viewerReturned > 0) entries.push([BLACKJACK_PUBLIC_SOUNDS.payout, "viewer-chip-return", resultDelay, .82]);
    entries.push([blackjackSettlementSound(after), "viewer-round-result", resultDelay + (viewerReturned > 0 ? 190 : 0), .68]);
    playBlackjackSoundSequence(entries);
    return;
  }

  if (cardDelta > 0 && String(before.phase || "") === "deal") {
    const shoeReplaced = Number(after.shoeCount || 0) > Number(before.shoeCount || 0) + cardDelta;
    if (shoeReplaced) entries.push([BLACKJACK_PUBLIC_SOUNDS.shuffle, "six-deck-shoe-replaced", 0, .72]);
    const dealStart = shoeReplaced ? 360 : 0;
    for (let index = 0; index < Math.min(4, cardDelta); index += 1) {
      entries.push([blackjackDealSound(version, index), "initial-card-deal", dealStart + index * 125, .76]);
    }
    playBlackjackSoundSequence(entries);
    return;
  }

  if (handDelta > 0 && String(before.phase || "") === "betting") {
    const wager = BLACKJACK_PUBLIC_SOUNDS.wager[version % BLACKJACK_PUBLIC_SOUNDS.wager.length];
    playBlackjackSound(wager, "authoritative-wager-placed", .82);
    return;
  }
  if (handDelta > 0 && String(after.phase || "") === "player-turns") {
    playBlackjackSoundSequence([
      [BLACKJACK_PUBLIC_SOUNDS.adjust, "split-wager-adjusted", 0, .8],
      [blackjackDealSound(version, 0), "first-split-card-dealt", 130, .76],
      [blackjackDealSound(version, 1), "second-split-card-dealt", 255, .76],
    ]);
    return;
  }
  if (insuranceDelta > 0) {
    playBlackjackSound(BLACKJACK_PUBLIC_SOUNDS.adjust, "insurance-wager-placed", .8);
    return;
  }
  if (cardDelta > 0 && betDelta > 0) {
    playBlackjackSoundSequence([
      [BLACKJACK_PUBLIC_SOUNDS.adjust, "double-wager-adjusted", 0, .8],
      [blackjackDealSound(version, 0), "double-card-dealt", 145, .76],
    ]);
    return;
  }
  if (cardDelta > 0) {
    playBlackjackSound(blackjackDealSound(version, 0), "authoritative-card-dealt", .76);
    return;
  }
  if (blackjackStatusCount(after, "surrendered") > blackjackStatusCount(before, "surrendered")) {
    playBlackjackSound(BLACKJACK_PUBLIC_SOUNDS.surrender, "hand-surrendered", .72);
    return;
  }
  if (blackjackStatusCount(after, "stood") > blackjackStatusCount(before, "stood")) {
    playBlackjackSound(BLACKJACK_PUBLIC_SOUNDS.stand, "hand-stood", .68);
    return;
  }
  if (String(before.phase || "") === "round-complete" && String(after.phase || "") === "betting") {
    playBlackjackSound(BLACKJACK_PUBLIC_SOUNDS.button, "next-round-opened", .62);
    return;
  }
  trace("effect-suppressed", null, { reason: "blackjack-transition-unmapped", soundOwner: "blackjack-public-cc0", fromVersion: Number(previous?.stateVersion || 0), toVersion: version });
}

function playClassicSelectionCue(slot, phase) {
  if (!["checkers", "chess", "acey-deucy", "backgammon-first-party"].includes(context.extensionId)) return;
  trace("selection-sound-classified", slot, { gameId: context.extensionId, phase });
  if (session?.presentation?.effectivePack !== "classic") {
    playBuiltInGameSound(BUILT_IN_PUBLIC_SOUNDS.select, phase, .58);
    return;
  }
  playSound(slot);
}

function isExactDoubleSix(dice) {
  return Array.isArray(dice) && dice.length === 2 && dice.every(value => Number(value) === 6);
}

function builtInPointDiceSoundAsset() {
  return context.extensionId === "acey-deucy"
    ? BUILT_IN_PUBLIC_SOUNDS.aceyDeucyDice
    : BUILT_IN_PUBLIC_SOUNDS.backgammonDice;
}

function playPointDiceSound(dice, reason, stateVersion, details = {}) {
  if (session?.presentation?.effectivePack !== "classic") {
    const diceAsset = builtInPointDiceSoundAsset();
    trace("transition-classified", diceAsset, { reason, stateVersion, dice: [...dice], soundOwner: "built-in-public-cc0" });
    playBuiltInGameSound(diceAsset, reason, .82);
    return;
  }
  trace("transition-classified", "wav-dice", { reason, stateVersion, dice: [...dice] });
  const diceAudio = playSound("wav-dice");
  const slot=originalRollAnnouncement(context.extensionId,dice,Boolean(details.blocked),details.actorUserId,currentUserId(),options);
  scheduleOriginalRollVoice(slot,diceAudio,stateVersion);
}

function listLength(value) {
  return Array.isArray(value) ? value.length : 0;
}

function latest(value) {
  return Array.isArray(value) && value.length ? value[value.length - 1] : null;
}

function terminalSound(state) {
  const reason = String(state?.terminalReason || "").toLowerCase();
  if (context.extensionId === "checkers") {
    if (reason === "mutual-agreement" || reason.includes("draw") || reason.includes("repetition") || reason.includes("forty")) return ["wav-draw", "draw"];
    if (reason.includes("resign")) return ["wav-resign", "resignation"];
    return ["wav-victory", "win"];
  }
  if (context.extensionId === "chess") {
    if (reason.includes("checkmate")) return ["wav-mate", "checkmate"];
    if (reason.includes("clock") || reason.includes("time")) {
      const expiredClockUserId = Number(state?.expiredClockUserId || 0);
      if (expiredClockUserId < 1) return ["", "clock-expiration-owner-missing"];
      return expiredClockUserId === currentUserId()
        ? ["wav-yoot", "local-clock-expiration"]
        : ["wav-oot", "opponent-clock-expiration"];
    }
    if (reason.includes("resign")) return ["wav-resign", "resignation"];
    return ["wav-draw", "draw"];
  }
  if (context.extensionId === "acey-deucy") {
    const classification = String(state?.terminalClassification || "");
    if (classification === "backgammon") return ["wav-bkgamm", "backgammon-result"];
    if (classification === "gammon") return ["wav-gammon", "gammon-result"];
    return ["wav-victory", "win"];
  }
  if (context.extensionId === "backgammon-first-party") {
    if (String(state?.terminalCause || "") === "resignation") return ["", "resignation-unmapped"];
    return ["wav-victory", "played-out-win"];
  }
  if (context.extensionId === "battleship") {
    if (reason.includes("resign")) return ["", "resignation-unmapped"];
    return Number(state?.winnerUserId || 0) === currentUserId()
      ? ["wav-victory", "win"] : ["wav-looser", "loss"];
  }
  if (context.extensionId === "spades") return ["", "spades-completion-has-no-source-sound"];
  return ["", "unmapped"];
}

function transitionSound(previous, current) {
  const before = previous?.state || {};
  const after = current?.state || {};
  if (!previous || Number(previous.stateVersion) === Number(current?.stateVersion)) return ["", "unchanged"];
  if (!before.completed && after.completed) return terminalSound(after);

  if (context.extensionId === "checkers" && listLength(after.history) > listLength(before.history)) {
    const move = latest(after.history) || {};
    if (move.promoted) return ["wav-morph", "promotion"];
    if (move.captured) return ["wav-jump", "capture"];
    return ["wav-move", "move"];
  }
  if (context.extensionId === "chess" && listLength(after.history) > listLength(before.history)) {
    const move = latest(after.history) || {};
    if (move.check) return ["wav-check", "check"];
    if (move.capture) return ["wav-die", "capture"];
    return ["wav-move", move.promotion ? "promotion-move" : "move"];
  }
  if (context.extensionId === "acey-deucy") {
    if (listLength(after.dice) > 0 && JSON.stringify(after.dice) !== JSON.stringify(before.dice)) return ["wav-dice", "roll"];
    if (listLength(after.history) > listLength(before.history)) {
      const move = latest(after.history) || {};
      if (move.to === "borne-off") return ["wav-out", "bear-off"];
      return pointMoveHitOpponent(before, after, move) ? ["wav-eat", "capture"] : ["wav-move", "move"];
    }
  }
  if (context.extensionId === "backgammon-first-party") {
    if (listLength(after.dice) > 0 && JSON.stringify(after.dice) !== JSON.stringify(before.dice)) return ["wav-dice", "roll"];
    if (listLength(after.history) > listLength(before.history)) {
      const move = latest(after.history) || {};
      if (move.to === "borne-off") return ["wav-out", "bear-off"];
      return pointMoveHitOpponent(before, after, move) ? ["wav-eat", "hit"] : ["wav-move", "move"];
    }
  }
  if (context.extensionId === "battleship") {
    if (listLength(after.attackHistory) > listLength(before.attackHistory)) {
      const result = String(latest(after.attackHistory)?.result || "");
      if (result === "sunk") return ["wav-sink", "sink"];
      if (result === "hit") return ["wav-hit", "hit"];
      return ["wav-mis", "miss"];
    }
    if (before.phase === "placement" && after.phase === "battle") return ["wav-locate", "fleet-ready"];
  }
  if (context.extensionId === "spades") {
    if (before.phase === "deal" && after.phase === "bidding") return ["wav-shuffle", "deal"];
    if (String(after.phase || "") === "settling" && String(after.settlement?.kind || "") === "nil-set"
      && JSON.stringify(after.settlement) !== JSON.stringify(before.settlement)) return ["wav-set", "nil-bidder-first-trick"];
    if (Number(after.playSequence || 0) > Number(before.playSequence || 0)) return ["wav-playcard", "confirmed-card-play"];
    if (after.bidEligibility?.blindOffer === true && Number(current?.turnUserId || 0) === currentUserId()
      && (before.bidEligibility?.blindOffer !== true || Number(previous?.turnUserId || 0) !== currentUserId())) {
      return ["wav-blindnil", "pre-hand-blind-nil-offer"];
    }
  }
  return ["", "no-source-backed-cue"];
}

function builtInSoundAssetForClassicSlot(slot) {
  if (["acey-deucy", "backgammon-first-party"].includes(context.extensionId) && safe(slot) === "wav-dice") {
    return builtInPointDiceSoundAsset();
  }
  if (context.extensionId === "acey-deucy" && safe(slot) === "wav-move") {
    return BUILT_IN_PUBLIC_SOUNDS.aceyDeucyBootStomp;
  }
  if (context.extensionId === "acey-deucy" && safe(slot) === "wav-eat") {
    return BUILT_IN_PUBLIC_SOUNDS.aceyDeucyBooted;
  }
  return ({
    "wav-unlock": BUILT_IN_PUBLIC_SOUNDS.select,
    "wav-move": BUILT_IN_PUBLIC_SOUNDS.move,
    "wav-jump": BUILT_IN_PUBLIC_SOUNDS.capture,
    "wav-eat": BUILT_IN_PUBLIC_SOUNDS.pointHitToBar,
    "wav-die": BUILT_IN_PUBLIC_SOUNDS.capture,
    "wav-morph": BUILT_IN_PUBLIC_SOUNDS.success,
    "wav-out": BUILT_IN_PUBLIC_SOUNDS.success,
    "wav-check": BUILT_IN_PUBLIC_SOUNDS.chessCheck,
    "wav-mate": BUILT_IN_PUBLIC_SOUNDS.chessCheckmate,
    "wav-draw": BUILT_IN_PUBLIC_SOUNDS.success,
    "wav-resign": BUILT_IN_PUBLIC_SOUNDS.loss,
    "wav-victory": BUILT_IN_PUBLIC_SOUNDS.success,
    "wav-looser": BUILT_IN_PUBLIC_SOUNDS.loss,
    "wav-yoot": BUILT_IN_PUBLIC_SOUNDS.loss,
    "wav-oot": BUILT_IN_PUBLIC_SOUNDS.success,
    "wav-bkgamm": BUILT_IN_PUBLIC_SOUNDS.backgammon,
    "wav-gammon": BUILT_IN_PUBLIC_SOUNDS.gammon,
    "wav-locate": BUILT_IN_PUBLIC_SOUNDS.shipPlace,
    "wav-shoot": BUILT_IN_PUBLIC_SOUNDS.shipShot,
    "wav-mis": BUILT_IN_PUBLIC_SOUNDS.shipMiss,
    "wav-hit": BUILT_IN_PUBLIC_SOUNDS.shipHit,
    "wav-sink": BUILT_IN_PUBLIC_SOUNDS.shipSink,
    "wav-shuffle": BUILT_IN_PUBLIC_SOUNDS.cardShuffle,
    "wav-playcard": BUILT_IN_PUBLIC_SOUNDS.cardPlay,
    "wav-set": BUILT_IN_PUBLIC_SOUNDS.success,
    "wav-blindnil": BUILT_IN_PUBLIC_SOUNDS.select,
  })[safe(slot)] || "";
}

function playBuiltInTransitionSound(previous, current) {
  const before = previous?.state || {};
  const after = current?.state || {};
  const pointGame = ["acey-deucy", "backgammon-first-party"].includes(context.extensionId);
  if (pointGame && pendingClassicMotion?.pointNative && pendingClassicMotion.type === "point-hit") {
    playOrderedSoundChain([["wav-eat", "native-checker-capture", 0], ["wav-move", "native-checker-move", 1520]]);
    return;
  }
  const noLegalMove = after.lastNoLegalMove || after.lastBlockedRoll || null;
  const changedWholeTurnNoLegal = pointGame
    && noLegalMove
    && String(noLegalMove.kind || "whole-turn") === "whole-turn"
    && JSON.stringify(before.lastNoLegalMove || before.lastBlockedRoll || null) !== JSON.stringify(noLegalMove);
  const changedVisibleDice = pointGame
    && listLength(after.dice) === 2
    && JSON.stringify(after.dice) !== JSON.stringify(before.dice);
  if (changedWholeTurnNoLegal || changedVisibleDice) {
    const rolledDice = changedWholeTurnNoLegal ? [...(noLegalMove.dice || [])] : [...after.dice];
    const pointDiceAsset = builtInPointDiceSoundAsset();
    const rolledAceyDeucy = context.extensionId === "acey-deucy"
      && rolledDice.length === 2
      && [...rolledDice].map(Number).sort((left, right) => left - right).join(",") === "1,2";
    playBuiltInGameSoundSequence([
      [pointDiceAsset, changedWholeTurnNoLegal ? "whole-turn-no-legal-roll" : "roll", 0, .82],
      [changedWholeTurnNoLegal
        ? (context.extensionId === "acey-deucy" ? BUILT_IN_PUBLIC_SOUNDS.aceyDeucyBlockedWall : BUILT_IN_PUBLIC_SOUNDS.error)
        : "", "no-legal-move", 460, .64],
      [rolledAceyDeucy ? BUILT_IN_PUBLIC_SOUNDS.aceyDeucy : "", "acey-deucy-roll", 620, .95],
    ]);
    trace("transition-classified", pointDiceAsset, { dice: rolledDice, soundOwner: "built-in-public-cc0" });
    return;
  }
  if (context.extensionId === "battleship") {
    const viewer = String(currentUserId());
    const beforeViewerFleet = before.fleets?.[viewer] || {};
    const afterViewerFleet = after.fleets?.[viewer] || {};
    const enteredBattle = before.phase === "placement" && after.phase === "battle";
    const viewerAccepted = !beforeViewerFleet.accepted && afterViewerFleet.accepted;
    if (enteredBattle || viewerAccepted) {
      playBuiltInGameSound(BUILT_IN_PUBLIC_SOUNDS.battleshipFleetReady, enteredBattle
        ? "both-fleets-ready"
        : "viewer-fleet-ready", .82);
      return;
    }
    const placementChanged = after.phase === "placement"
      && JSON.stringify(beforeViewerFleet.ships || []) !== JSON.stringify(afterViewerFleet.ships || []);
    if (placementChanged) {
      const operation = String(after.lastPlacement?.operation || "");
      const asset = operation === "rotate"
        ? BUILT_IN_PUBLIC_SOUNDS.battleshipRotation
        : BUILT_IN_PUBLIC_SOUNDS.battleshipPlacement;
      playBuiltInGameSound(asset, operation === "rotate"
        ? "authoritative-ship-rotation"
        : "authoritative-ship-location-change", .78);
      return;
    }
    if (listLength(after.attackHistory) > listLength(before.attackHistory)) {
      const result = String(latest(after.attackHistory)?.result || "miss");
      const resultAsset = result === "miss"
        ? BUILT_IN_PUBLIC_SOUNDS.battleshipWaterMiss
        : BUILT_IN_PUBLIC_SOUNDS.battleshipFireHit;
      const sinkAsset = result === "sunk" ? BUILT_IN_PUBLIC_SOUNDS.battleshipSinking : "";
      const viewerWon = Number(after.winnerUserId || 0) === currentUserId();
      const terminalAsset = !before.completed && after.completed
        ? (viewerWon ? BUILT_IN_PUBLIC_SOUNDS.battleshipVictory : BUILT_IN_PUBLIC_SOUNDS.battleshipDefeat)
        : "";
      playBuiltInGameSoundSequence([
        [BUILT_IN_PUBLIC_SOUNDS.battleshipMissileLaunch, "authoritative-shot-launch", 0, .82],
        [resultAsset, `authoritative-${result}`, 1600, result === "miss" ? .8 : .94],
        [sinkAsset, "authoritative-ship-sinking", 6400, .94],
        [terminalAsset, "fleet-sunk-result", viewerWon ? 22500 : 9700, .9],
      ]);
      return;
    }
  }
  const checkersMoveChanged = context.extensionId === "checkers"
    && listLength(after.history) > listLength(before.history);
  const checkersMove = checkersMoveChanged ? (latest(after.history) || {}) : null;
  if (checkersMove?.captured) {
    const winnerUserId = Number(after.winnerUserId || 0);
    const terminalAsset = !before.completed && after.completed
      ? ((winnerUserId > 0 || gameTerminalOutcome(current).winnerIds.includes(winnerUserId)) && winnerUserId !== currentUserId()
        ? BUILT_IN_PUBLIC_SOUNDS.loss
        : BUILT_IN_PUBLIC_SOUNDS.success)
      : "";
    const optimisticSound = reconciledBuiltInCheckersSound?.version === Number(current?.stateVersion || 0)
      ? reconciledBuiltInCheckersSound
      : null;
    if (optimisticSound?.captureSoundScheduled) {
      if (terminalAsset && !optimisticSound.terminalSoundScheduled) {
        const elapsed = Math.max(0, performance.now() - Number(optimisticSound.startedAt || performance.now()));
        playBuiltInGameSoundSequence([
          [terminalAsset, "terminal-result-after-optimistic-capture", Math.max(0, 2200 - elapsed), .72],
        ]);
      }
      reconciledBuiltInCheckersSound = null;
      trace("checkers-authoritative-sound-reconciled", null, {
        version: Number(current?.stateVersion || 0),
        terminalSoundAlreadyScheduled: Boolean(optimisticSound.terminalSoundScheduled),
      });
      return;
    }
    playBuiltInGameSoundSequence([
      [BUILT_IN_PUBLIC_SOUNDS.checkerMissileLaunch, "checkers-missile-launch", 260, .84],
      [BUILT_IN_PUBLIC_SOUNDS.checkerExplosion, "checkers-missile-impact", 760, .92],
      [BUILT_IN_PUBLIC_SOUNDS.checkerExplosionRumble, "checkers-missile-impact-rumble", 760, .62],
      [terminalAsset, "terminal-result", 2200, .72],
    ]);
    return;
  }
  const chessMoveChanged = context.extensionId === "chess"
    && listLength(after.history) > listLength(before.history);
  const chessMove = chessMoveChanged ? (latest(after.history) || {}) : null;
  const chessCheckmate = context.extensionId === "chess"
    && String(after.terminalReason || "").toLowerCase().includes("checkmate");
  const optimisticChessSound = chessMove && reconciledBuiltInChessSound?.version === Number(current?.stateVersion || 0)
    ? reconciledBuiltInChessSound
    : null;
  if (optimisticChessSound?.moveSoundScheduled) {
    const terminalAsset = !before.completed && after.completed
      ? (chessCheckmate
        ? BUILT_IN_PUBLIC_SOUNDS.chessCheckmate
        : (Number(after.winnerUserId || 0) > 0 || gameTerminalOutcome(current).winnerIds.includes(after.winnerUserId)) && Number(after.winnerUserId || 0) !== currentUserId()
          ? BUILT_IN_PUBLIC_SOUNDS.loss
          : BUILT_IN_PUBLIC_SOUNDS.success)
      : "";
    const checkAsset = !after.completed && chessMove?.check
      ? BUILT_IN_PUBLIC_SOUNDS.chessCheck
      : "";
    if (checkAsset) {
      const elapsed = Math.max(0, performance.now() - Number(optimisticChessSound.startedAt || performance.now()));
      const checkDelayMs = chessMove?.captured || chessMove?.capture ? 2350 : 750;
      playBuiltInGameSoundSequence([
        [checkAsset, "chess-check-after-optimistic-move", Math.max(0, checkDelayMs - elapsed), .82, "voice"],
      ]);
    }
    if (terminalAsset && !optimisticChessSound.terminalSoundScheduled) {
      const elapsed = Math.max(0, performance.now() - Number(optimisticChessSound.startedAt || performance.now()));
      playBuiltInGameSoundSequence([
        [terminalAsset, chessCheckmate ? "chess-checkmate-after-optimistic-move" : "chess-terminal-result-after-optimistic-move", chessCheckmate ? 0 : Math.max(0, 3250 - elapsed), chessCheckmate ? .82 : .72, chessCheckmate ? "voice" : "effects"],
      ]);
    }
    reconciledBuiltInChessSound = null;
    trace("chess-authoritative-sound-reconciled", null, {
      version: Number(current?.stateVersion || 0),
      terminalSoundAlreadyScheduled: Boolean(optimisticChessSound.terminalSoundScheduled),
    });
    return;
  }
  if (chessMove) {
    const captured = Boolean(chessMove.captured || chessMove.capture);
    const terminalAsset = !before.completed && after.completed
      ? (chessCheckmate
        ? BUILT_IN_PUBLIC_SOUNDS.chessCheckmate
        : (Number(after.winnerUserId || 0) > 0 || gameTerminalOutcome(current).winnerIds.includes(after.winnerUserId)) && Number(after.winnerUserId || 0) !== currentUserId()
          ? BUILT_IN_PUBLIC_SOUNDS.loss
          : BUILT_IN_PUBLIC_SOUNDS.success)
      : "";
    const checkAsset = !after.completed && chessMove.check ? BUILT_IN_PUBLIC_SOUNDS.chessCheck : "";
    playBuiltInGameSoundSequence([
      [captured ? BUILT_IN_PUBLIC_SOUNDS.chessPieceCapture : BUILT_IN_PUBLIC_SOUNDS.chessPieceSlide,
        captured ? "chess-capture-approved-recording" : "chess-move-approved-recording", captured ? 520 : 0, .72],
      [captured ? BUILT_IN_PUBLIC_SOUNDS.chessPortalSink : "", "chess-capture-portal-descent", 610, .98],
      [captured ? BUILT_IN_PUBLIC_SOUNDS.checkerExplosionRumble : "", "chess-capture-seal-rumble", 1450, .56],
      [checkAsset, "chess-check", captured ? 2350 : 750, .82, "voice"],
      [terminalAsset, chessCheckmate ? "chess-checkmate" : "chess-terminal-result", chessCheckmate ? 0 : 3250,
        chessCheckmate ? .82 : .72, chessCheckmate ? "voice" : "effects"],
    ]);
    return;
  }
  if (context.extensionId === "spades"
    && String(after.phase || "") === "settling"
    && String(after.settlement?.kind || "") === "nil-set"
    && JSON.stringify(after.settlement) !== JSON.stringify(before.settlement)) {
    const turnOrder = Array.isArray(after.turnOrder) ? after.turnOrder.map(Number) : [];
    const nilBidderIndex = turnOrder.indexOf(Number(after.settlement?.winnerUserId || 0));
    const viewerIndex = turnOrder.indexOf(currentUserId());
    const nilTeam = nilBidderIndex >= 0 ? nilBidderIndex % 2 : -1;
    const viewerTeam = viewerIndex >= 0 ? viewerIndex % 2 : -1;
    const asset = viewerTeam < 0 || nilTeam < 0
      ? BUILT_IN_PUBLIC_SOUNDS.spadesNilFailed
      : viewerTeam === nilTeam
        ? BUILT_IN_PUBLIC_SOUNDS.spadesYourTeamNilFailed
        : BUILT_IN_PUBLIC_SOUNDS.spadesOtherTeamNilFailed;
    playBuiltInGameSound(asset, viewerTeam < 0 ? "nil-failed-neutral" : viewerTeam === nilTeam
      ? "viewer-team-nil-failed" : "other-team-nil-failed", .94, "voice");
    return;
  }
  if (!before.completed && after.completed) {
    const move = listLength(after.history) > listLength(before.history) ? (latest(after.history) || {}) : null;
    const moveAsset = move ? (move.captured || move.capture || pointMoveHitOpponent(before, after, move)
      ? BUILT_IN_PUBLIC_SOUNDS.capture
      : context.extensionId === "acey-deucy" ? BUILT_IN_PUBLIC_SOUNDS.aceyDeucyBootStomp : BUILT_IN_PUBLIC_SOUNDS.move) : "";
    const winnerUserId = Number(after.winnerUserId || 0);
    const resultAsset = chessCheckmate
      ? BUILT_IN_PUBLIC_SOUNDS.chessCheckmate
      : (winnerUserId > 0 || gameTerminalOutcome(current).winnerIds.includes(winnerUserId)) && winnerUserId !== currentUserId()
        ? BUILT_IN_PUBLIC_SOUNDS.loss : BUILT_IN_PUBLIC_SOUNDS.success;
    const playedOutPointResult = context.extensionId === "backgammon-first-party"
      ? String(after.terminalCause || "") === "bear-off"
      : context.extensionId === "acey-deucy" && move?.to === "borne-off";
    const pointResultAsset = playedOutPointResult && String(after.terminalClassification || "") === "backgammon"
      ? BUILT_IN_PUBLIC_SOUNDS.backgammon
      : playedOutPointResult && String(after.terminalClassification || "") === "gammon"
        ? BUILT_IN_PUBLIC_SOUNDS.gammon
        : "";
    const chessCapture = context.extensionId === "chess" && Boolean(move?.captured || move?.capture);
    playBuiltInGameSoundSequence(chessCapture ? [
      [BUILT_IN_PUBLIC_SOUNDS.capture, "chess-capture-drop", 610, .9],
      [BUILT_IN_PUBLIC_SOUNDS.shipSink, "chess-capture-portal", 690, .52],
      [BUILT_IN_PUBLIC_SOUNDS.checkerExplosionRumble, "chess-capture-seal-rumble", 1450, .38],
      [resultAsset, chessCheckmate ? "chess-checkmate" : "terminal-result", chessCheckmate ? 0 : 5800, chessCheckmate ? .82 : .72],
    ] : [
      [moveAsset, "terminal-move", 0, .72],
      [resultAsset, chessCheckmate ? "chess-checkmate" : "terminal-result", chessCheckmate ? 0 : (moveAsset ? 230 : 0), chessCheckmate ? .82 : .72],
      [pointResultAsset, "point-result-classification", moveAsset ? 700 : 470, .96],
    ]);
    return;
  }
  const [slot, reason] = transitionSound(previous, current);
  const asset = builtInSoundAssetForClassicSlot(slot);
  if (asset) {
    playBuiltInGameSound(asset, reason, .72);
    return;
  }
  trace("effect-suppressed", slot || null, { reason, soundOwner: "built-in-public-cc0" });
}

function playOrderedSoundChain(entries, startedAt = performance.now()) {
  const queue = entries.filter(entry => safe(entry?.[0]) !== "");
  const playIndex = index => {
    if (index >= queue.length || options?.effectsEnabled === false || session?.presentation?.effectivePack !== "classic") return;
    const [slot, reason, minimumOffsetMs = 0] = queue[index];
    const remaining = Math.max(0, Number(minimumOffsetMs) - (performance.now() - startedAt));
    if (remaining > 0) {
      const timer = setTimeout(() => {
        scheduledSoundTimers.delete(timer);
        playIndex(index);
      }, remaining);
      scheduledSoundTimers.add(timer);
      return;
    }
    trace("transition-classified", slot, { reason, orderedIndex:index, minimumOffsetMs });
    playSound(slot);
    const audio = audioPlayers.get(slot);
    if (index + 1 >= queue.length || !audio) return;
    if (audio.ended) {
      playIndex(index + 1);
      return;
    }
    audio.addEventListener("ended", () => playIndex(index + 1), { once:true });
  };
  playIndex(0);
}

function playTransitionSound(previous, current) {
  const before = previous?.state || {};
  const after = current?.state || {};
  if (context.extensionId === "hearts") {
    playHeartsTransitionSound(previous, current);
    return;
  }
  if (context.extensionId === "blackjack") {
    playBlackjackTransitionSound(previous, current);
    return;
  }
  if (context.extensionId === "uno") {
    playUnoTransitionSound(previous, current);
    return;
  }
  if (session?.presentation?.effectivePack !== "classic") {
    playBuiltInTransitionSound(previous, current);
    return;
  }
  if (["checkers","chess"].includes(context.extensionId) && Number(after.drawOfferBy)>0
      && Number(after.drawOfferBy)!==currentUserId() && Number(before.drawOfferBy||0)!==Number(after.drawOfferBy)
      && ["master","player"].includes(current.viewerRole)) {playSound("wav-draw");return;}
  if (context.extensionId==="chess" && listLength(after.history)>listLength(before.history)) {
    const move=latest(after.history)||{},chain=[[move.capture?"wav-die":"wav-move",move.capture?"capture":"move",0]];
    if(after.completed){const [slot,reason]=terminalSound(after);chain.push([slot,reason,Number(pendingClassicMotion?.leadDurationMs||0)]);}
    else if(move.check)chain.push(["wav-check","check-after-move",0]);
    playOrderedSoundChain(chain);return;
  }
  const sequence = [];
  let supplementalResultSlot = "";
  let supplementalResultDelayMs = 0;
  const noLegalMove = after.lastNoLegalMove || after.lastBlockedRoll || null;
  const pointGame = ["acey-deucy", "backgammon-first-party"].includes(context.extensionId);
  if (context.extensionId === "checkers") {
    const moveChanged = listLength(after.history) > listLength(before.history);
    const move = moveChanged ? (latest(after.history) || {}) : null;
    if (!before.completed && after.completed && isCheckersDecisiveTerminalReason(after.terminalReason)) {
      const chain = [];
      if (move) {
        chain.push(["wav-unlock", "successful-move-release", 0]);
        chain.push([move.captured ? "wav-jump" : "wav-move", move.captured ? "terminal-capture" : "terminal-move", 0]);
        if (move.promoted) chain.push(["wav-morph", "supplemental-terminal-promotion", 0]);
      } else if (["resign", "forfeit"].some(token => String(after.terminalReason || "").toLowerCase().includes(token))) {
        chain.push(["wav-resign", "resignation", 0]);
      }
      chain.push(["wav-victory", "native-decisive-terminal-sequence", Number(pendingClassicMotion?.leadDurationMs || 0)]);
      playOrderedSoundChain(chain);
      return;
    }
    if (move) {
      const chain = [
        ["wav-unlock", "successful-move-release", 0],
        [move.captured ? "wav-jump" : "wav-move", move.captured ? "capture" : "move", 0],
      ];
      if (move.promoted) chain.push(["wav-morph", "supplemental-promotion", 0]);
      playOrderedSoundChain(chain);
      return;
    }
  }
  const acceptedPointRoll=pointGame ? originalPointRoll(context.extensionId,before,after) : null;
  if (acceptedPointRoll) {
    playPointDiceSound(acceptedPointRoll.dice,acceptedPointRoll.blocked?"whole-turn-no-legal-roll":"roll",Number(current.stateVersion||0),
      {blocked:acceptedPointRoll.blocked,actorUserId:acceptedPointRoll.actorUserId||previous.turnUserId});
    return;
  }
  if (context.extensionId === "battleship") {
    const viewer = String(currentUserId());
    const placementChanged = after.phase === "placement"
      && JSON.stringify(before.fleets?.[viewer]?.ships || []) !== JSON.stringify(after.fleets?.[viewer]?.ships || []);
    if (placementChanged) {
      playOrderedSoundChain([[originalPlacementCue(before,after,currentUserId()), "authoritative-ship-location-change", 0]]);
      return;
    }
  }
  if (context.extensionId === "battleship" && before.phase==="placement" && after.phase==="battle") return;
  if (context.extensionId === "battleship" && listLength(after.attackHistory) > listLength(before.attackHistory)) {
    const attack = latest(after.attackHistory) || {};
    const result = String(attack.result || "miss");
    const source = classicSourceMap("battleship");
    const impactOffsetMs = Number(source.motion.audio.impactStartMs);
    const resultSettleMs = battleshipAttackTimeline({ attack, after }).settleMs;
    const chain = [["wav-shoot", "authoritative-shot-launch", 0]];
    if (result === "miss") {
      chain.push(["wav-mis", "authoritative-miss", impactOffsetMs]);
    } else {
      chain.push(["wav-hit", "authoritative-hit", impactOffsetMs]);
      if (result === "sunk") chain.push(["wav-sink", "authoritative-sink", Number(source.motion.audio.sinkStartMs)]);
    }
    if (!before.completed && after.completed && String(after.terminalReason || "") === "fleet-sunk") {
      const terminal = terminalSound(after);
      chain.push([
        terminal[0],
        terminal[1],
        resultSettleMs,
      ]);
    }
    playOrderedSoundChain(chain);
    return;
  }
  if (context.extensionId === "spades") {
    const enteredBidding = before.phase === "deal" && after.phase === "bidding";
    const blindOfferPresented = after.bidEligibility?.blindOffer === true
      && Number(current?.turnUserId || 0) === currentUserId()
      && (before.bidEligibility?.blindOffer !== true || Number(previous?.turnUserId || 0) !== currentUserId());
    if (enteredBidding) sequence.push(["wav-shuffle", "automatic-new-hand", 0]);
    if (blindOfferPresented) sequence.push(["wav-blindnil", "pre-hand-blind-nil-offer", 0]);
    if (String(after.phase || "") === "settling" && String(after.settlement?.kind || "") === "nil-set"
      && JSON.stringify(after.settlement) !== JSON.stringify(before.settlement)) {
      sequence.push(["wav-set", "nil-bidder-first-trick", 0]);
    }
    if (Number(after.playSequence || 0) > Number(before.playSequence || 0)) {
      sequence.unshift(["wav-playcard", "confirmed-card-play", 0]);
    }
  }
  if (!before.completed && after.completed && listLength(after.history) > listLength(before.history)) {
    const move = latest(after.history) || {};
    if (context.extensionId === "checkers" && isCheckersDecisiveTerminalReason(after.terminalReason)) {
      sequence.push([move.captured ? "wav-jump" : move.promoted ? "wav-morph" : "wav-move", "terminal-move", 0]);
      const terminal = terminalSound(after);
      sequence.push([terminal[0], terminal[1], Number(pendingClassicMotion?.leadDurationMs || 0)]);
    } else if (context.extensionId === "backgammon-first-party" && String(after.terminalCause || "") === "bear-off") {
      const leadDelayMs = Number(pendingClassicMotion?.leadDurationMs || 0);
      sequence.push(["wav-out", "terminal-bear-off", 0]);
      sequence.push(["wav-victory", "native-win-animation-begins", leadDelayMs]);
      const classification = String(after.terminalClassification || "");
      supplementalResultSlot = classification === "backgammon" ? "wav-bkgamm"
        : classification === "gammon" ? "wav-gammon" : "";
      supplementalResultDelayMs = leadDelayMs;
    } else if (context.extensionId === "acey-deucy" && move.to === "borne-off") {
      const leadDelayMs=Number(pendingClassicMotion?.leadDurationMs||0);
      sequence.push(["wav-out", "terminal-bear-off", 0]);
      sequence.push(["wav-victory","native-win-animation-begins",leadDelayMs]);
      const classification=String(after.terminalClassification||"");
      supplementalResultSlot=classification==="backgammon"?"wav-bkgamm":classification==="gammon"?"wav-gammon":"";
      supplementalResultDelayMs=leadDelayMs;
    }
  }
  if (sequence.length === 0) {
    const [slot, reason] = transitionSound(previous, current);
    sequence.push([slot, reason, 0]);
  }
  for (const [slot, reason, delayMs] of sequence) {
    trace("transition-classified", slot || null, {
      reason,
      delayMs,
      fromVersion: Number(previous?.stateVersion || 0),
      toVersion: Number(current?.stateVersion || 0)
    });
    if (delayMs <= 0) {
      playSound(slot);
      continue;
    }
    const timer = setTimeout(() => {
      scheduledSoundTimers.delete(timer);
      playSound(slot);
    }, delayMs);
    scheduledSoundTimers.add(timer);
  }
  if (supplementalResultSlot) {
    const timer = setTimeout(() => {
      scheduledSoundTimers.delete(timer);
      const victory = audioPlayers.get("wav-victory");
      if (!victory) {
        trace("effect-suppressed", supplementalResultSlot, { reason: "victory-owner-unavailable" });
        return;
      }
      if (victory.ended) {
        playSound(supplementalResultSlot);
        return;
      }
      victory.addEventListener("ended", () => playSound(supplementalResultSlot), { once: true });
    }, supplementalResultDelayMs + 1);
    scheduledSoundTimers.add(timer);
    trace("transition-classified", supplementalResultSlot, {
      reason: "supplemental-result-after-victory",
      delayMs: supplementalResultDelayMs,
      fromVersion: Number(previous?.stateVersion || 0),
      toVersion: Number(current?.stateVersion || 0),
    });
  }
}

function playHeartsSound(name, delayMs = 0) {
  const filename = HEARTS_PUBLIC_SOUNDS[name];
  if (!filename || options?.effectsEnabled === false || !gameSurfaceVisible) return;
  const run = () => {
    const key = `hearts-public:${name}`;
    let audio = audioPlayers.get(key);
    if (!audio) {
      audio = new Audio(new URL(`../../${HEARTS_PUBLIC_SOUND_ROOT}/${filename}`, window.location.href).href);
      audio.preload = "auto";
      audioPlayers.set(key, audio);
    }
    audio.pause();
    audio.currentTime = 0;
    audio.volume = Math.max(0, Math.min(1, Number(options?.masterVolume ?? 100) / 100));
    void audio.play().catch(() => {});
  };
  if (delayMs <= 0) { run(); return; }
  const timer = setTimeout(() => { scheduledSoundTimers.delete(timer); run(); }, delayMs);
  scheduledSoundTimers.add(timer);
}

function playHeartsTransitionSound(previous, current) {
  const before = previous?.state || {};
  const after = current?.state || {};
  if (!previous) return;
  if (!before.completed && after.completed) {
    playHeartsSound(Number(after.winnerUserId || 0) === currentUserId() ? "win" : "loss");
    return;
  }
  if (before.phase === "deal" && ["passing", "playing"].includes(after.phase)) {
    playHeartsSound("shuffle");
    playHeartsSound("deal1", 260);
    playHeartsSound("deal2", 520);
  }
  const beforePasses = Object.keys(before.pendingPasses || {}).length;
  const afterPasses = Object.keys(after.pendingPasses || {}).length;
  if (afterPasses > beforePasses || (before.phase === "passing" && after.phase === "playing")) playHeartsSound("pass");
  if (Number(after.playSequence || 0) > Number(before.playSequence || 0)) {
    playHeartsSound(Number(after.playSequence || 0) % 2 ? "play1" : "play2");
    const visiblePlays = Array.isArray(after.currentTrick) && after.currentTrick.length
      ? after.currentTrick
      : Array.isArray(after.lastCompletedTrick?.cards) ? after.lastCompletedTrick.cards : [];
    const playedCard = String(visiblePlays[visiblePlays.length - 1]?.card || "");
    if (playedCard === "S12") playHeartsSound("queen", 160);
  }
  if (!before.heartsBroken && after.heartsBroken) playHeartsSound("broken", 180);
  if (Number(after.trickNumber || 0) > Number(before.trickNumber || 0)) {
    const completedCards = Array.isArray(after.lastCompletedTrick?.cards) ? after.lastCompletedTrick.cards : [];
    const pointValue = completedCards.reduce((total, play) => {
      const card = String(play?.card || "");
      return total + (card.startsWith("H") ? 1 : card === "S12" ? 13 : 0);
    }, 0);
    playHeartsSound(pointValue > 0 ? "pointTrick" : "trick", 420);
  }
  const beforeResult = Number(before.lastHandResult?.handNumber || 0);
  const afterResult = Number(after.lastHandResult?.handNumber || 0);
  if (afterResult > beforeResult && Number(after.lastHandResult?.moonShooterUserId || 0) !== 0) playHeartsSound("moon", 180);
}

function playUnoSound(name, delayMs = 0) {
  const filename = UNO_PUBLIC_SOUNDS[name];
  const channel = name === "declare" ? "voice" : "effects";
  const enabled = channel === "voice" ? options?.voiceEnabled !== false : options?.effectsEnabled !== false;
  if (!filename || !enabled || !gameSurfaceVisible) return;
  const run = () => {
    const key = `uno-public:${name}`;
    let audio = audioPlayers.get(key);
    if (!audio) {
      audio = new Audio(new URL(`../../${UNO_PUBLIC_SOUND_ROOT}/${filename}`, window.location.href).href);
      audio.preload = "auto";
      audioPlayers.set(key, audio);
    }
    audio.pause();
    audio.currentTime = 0;
    audio.volume = Math.max(0, Math.min(1, Number(options?.masterVolume ?? 100) / 100));
    trace("effect-play-requested", filename, {
      reason:`uno-${name}`,
      soundOwner:name === "declare" ? "corechat-generated" : "card-game-public-cc0",
      channel,
    });
    void audio.play()
      .then(() => trace("effect-playing", filename, { reason:`uno-${name}`, channel }))
      .catch(error => trace("effect-unavailable", filename, { reason:`uno-${name}`, channel, errorName:String(error?.name || "Error") }));
  };
  if (delayMs <= 0) { run(); return; }
  const timer = setTimeout(() => { scheduledSoundTimers.delete(timer); run(); }, delayMs);
  scheduledSoundTimers.add(timer);
}

function playUnoTransitionSound(previous, current) {
  if (!previous) return;
  const before = previous?.state || {};
  const after = current?.state || {};
  if (!before.completed && after.completed) {
    playUnoSound(Number(after.winnerUserId || 0) === currentUserId() ? "win" : "loss");
    return;
  }
  if (before.phase === "deal" && after.phase !== "deal") {
    playUnoSound("shuffle");
    playUnoSound("deal1", 220);
    playUnoSound("deal2", 450);
  }
  const beforeSequence = Number(before.lastAction?.sequence || 0);
  const action = Number(after.lastAction?.sequence || 0) > beforeSequence ? after.lastAction : null;
  if (!action) return;
  if (action.type === "play") {
    playUnoSound(Number(action.sequence || 0) % 2 ? "play1" : "play2");
    const symbol = String(action.card || "").split(":")[1] || "";
    if (symbol === "R") playUnoSound("reverse", 130);
    if (symbol === "S") playUnoSound("skip", 130);
    if (["W", "D4"].includes(symbol)) playUnoSound("wild", 150);
  } else if (["draw", "accept-draw-four", "challenge-draw-four"].includes(String(action.type || ""))) {
    playUnoSound("draw");
  } else if (action.type === "call-uno") {
    playUnoSound("declare");
  } else if (action.type === "catch-uno") {
    playUnoSound("catch");
  }
}

function musicSlot() {
  return context.extensionId === "battleship" ? "mid-battle" : "";
}

function stopMusic(reason) {
  if (!musicPlayer) return;
  musicPlayer.pause();
  musicPlayer.currentTime = 0;
  trace("music-paused", musicSlot(), { reason });
}

async function syncMusic() {
  const slot = musicSlot();
  if (!slot || session?.presentation?.effectivePack !== "classic" || options?.musicEnabled !== true) {
    stopMusic(!slot ? "unmapped" : session?.presentation?.effectivePack !== "classic" ? "built-in" : "music-off");
    return;
  }
  if (!musicPlayer) {
    musicPlayer = new Audio(mediaUrl(slot));
    musicPlayer.loop = true;
    musicPlayer.preload = "none";
    trace("music-created", slot);
  }
  musicPlayer.volume = Math.max(0, Math.min(1, Number(options?.masterVolume ?? 100) / 100));
  trace("music-play-requested", slot);
  try { await musicPlayer.play(); trace("music-playing", slot); }
  catch { trace("music-unavailable", slot); }
}

function pauseAudio(reason) {
  cancelOriginalAudio();
  for (const audio of audioPlayers.values()) { audio.pause(); audio.currentTime = 0; }
  stopMusic(reason);
  trace("all-audio-paused", null, { reason });
  stopClassicAnimation(reason);
}

function renderRules() {
  const panel = el("rules-panel");
  const rules = session?.rules || {};
  const rulesLabel = safe(rules.label || session?.displayName || context.fallbackName).trim() || context.fallbackName;
  const rulesHeading = /\brules$/i.test(rulesLabel) ? rulesLabel : `${rulesLabel} Rules`;
  panel.replaceChildren();
  panel.append(make("h2", "", rulesHeading));
  panel.firstElementChild.id = "rules-panel-heading";
  panel.append(make("p", "", safe(rules.description || "The active game rules are unavailable.")));
  const list = make("dl");
  for (const section of rules.sections || []) {
    list.append(make("dt", "", safe(section.label)));
    if (context.extensionId === "uno" && Array.isArray(section.items) && section.items.length) {
      const guide = make("dd", "uno-rule-card-guide");
      if (section.text) guide.append(make("p", "uno-rule-card-intro", safe(section.text)));
      for (const item of section.items) {
        const row = make("div", "uno-rule-card-item");
        const copy = make("div", "uno-rule-card-copy");
        copy.append(make("strong", "", safe(item.label)), make("span", "", safe(item.text)));
        row.append(renderUnoCard(safe(item.card), "uno-rule-guide-card"), copy);
        guide.append(row);
      }
      list.append(guide);
    } else {
      list.append(make("dd", "", safe(section.text)));
    }
  }
  const opening = openingAnnouncement();
  if (opening) list.append(make("dt", "", context.extensionId === "spades" ? "Dealer and opening" : "Starter"), make("dd", "", opening));
  panel.append(list);
  el("rules-button").setAttribute("aria-label", `Show ${rulesHeading}`);
}

function accessibilityGuidance() {
  const gameSpecific = {
    checkers: [
      ["Play", "Focus an available checker or marked destination, then press Enter or Space to select or move."],
      ["Cancel selection", "Activate the selected checker again. Its selection highlight and focus state clear."],
      ["Crown and removal drawers", "Use Tab inside an open side drawer to reach its confirmation controls. Escape closes the drawer and returns focus."],
    ],
    chess: [
      ["Play", "Focus an available piece or marked destination, then press Enter or Space to select or move."],
      ["Cancel selection", "Activate the selected piece again. Its selection highlight and focus state clear."],
      ["Promotion", "Choose a promotion piece with Tab and Enter or Space. Escape cancels the promotion choice and restores the selected move."],
    ],
    "acey-deucy": [
      ["Play", "Focus a movable checker point or marked destination, then press Enter or Space to select or move."],
      ["Cancel selection", "Activate the selected checker point again."],
      ["Dice and actions", "Use Tab to reach Roll and the available below-board actions, then press Enter or Space."],
    ],
    "backgammon-first-party": [
      ["Play", "Focus a movable checker point, bar entry, or marked destination, then press Enter or Space to select or move."],
      ["Cancel selection", "Activate the selected checker point or bar entry again."],
      ["Dice and actions", "Use Tab to reach Roll and the available below-board actions, then press Enter or Space."],
    ],
    battleship: [
      ["Place ships", "Use Tab to reach ship and grid controls. Press Enter or Space on the focused control to select, rotate, or place as its accessible name describes."],
      ["Fire", "Focus an available target cell and press Enter or Space."],
    ],
    spades: [
      ["Bid and play", "Use Tab to reach bids, cards, and action buttons. Press Enter or Space to activate the focused choice."],
      ["Cancel card selection", "Press Escape while the hand is focused to clear a selected card or passing-card selection."],
    ],
    blackjack: [
      ["Bet and play", "Use Tab to reach the wager controls and the available Hit, Stand, Double, Split, or Surrender buttons."],
      ["Read hands", "Each hand identifies its cards, value, wager, and result. The dealer hole card remains announced as hidden until it is revealed."],
    ],
    uno: [
      ["Play a card", "Use Tab to reach a playable card, then press Enter or Space. Wild cards open an accessible color chooser."],
      ["Draw or keep", "Use the Draw card button. If the drawn card is playable, play it or use Keep card to end the turn."],
      ["Call or catch UNO", "Use the visible UNO button before playing from two cards to one. A visible Catch UNO button announces an available missed call."],
    ],
  };
  return gameSpecific[context.extensionId] || [];
}

function renderAccessibility() {
  const panel = el("accessibility-panel");
  panel.replaceChildren();
  const heading = make("h2", "", "Accessibility");
  heading.id = "accessibility-panel-heading";
  panel.append(
    heading,
    make("p", "", "Keyboard commands act on the control that currently has focus; there are no hidden letter-key shortcuts. Visual FX follows your device motion preference until you choose On or Off for this game."),
  );
  const list = make("dl");
  const guidance = [
    ["Move focus", "Press Tab to move forward and Shift+Tab to move backward through available controls."],
    ["Activate", "Press Enter or Space to use the focused button, board cell, point, card, die, or scoring choice."],
    ...((supportsViewerBoardScale() || supportsBuiltInBoardScale()) ? [["Board size", "Open Game Options and use the Board size minus and plus buttons. The setting is personal to this browser and never changes another player's view."]] : []),
    ["Status", "Turn, timer, game-state, and error changes are announced through live status regions."],
    ...accessibilityGuidance(),
  ];
  for (const [label, detail] of guidance) list.append(make("dt", "", label), make("dd", "", detail));
  panel.append(list);
}

function gameRecordsScope(value = session) {
  const publicId = String(value?.publicId || "");
  const gameKey = String(value?.gameKey || "");
  if (!publicId || publicId !== String(context.gameSessionId || "")
    || !gameKey || gameKey !== String(context.gameKey || "")) return "";
  return JSON.stringify([String(context.sessionId || ""), Number(context.participantId || 0), publicId, gameKey]);
}

function gameRecordsCompletionKey(value = session) {
  const scope = gameRecordsScope(value);
  return scope && value?.mode === "recorded" && gameSessionIsTerminal(value)
    ? JSON.stringify([scope, Number(value.stateVersion), String(value.status), String(value.endedAt || "")])
    : "";
}

function resetGameRecords() {
  recordsRequestSerial += 1;
  records = null;
  recordsScope = "";
  recordsRequest = null;
  recordsTerminalKey = "";
  recordsError = "";
}

function refreshGameRecords({ force = false } = {}) {
  if (terminalSessionError) return Promise.resolve(null);
  const scope = gameRecordsScope();
  if (!scope) {
    resetGameRecords();
    renderRecords();
    return Promise.resolve(null);
  }
  if (recordsScope !== scope) {
    resetGameRecords();
    recordsScope = scope;
  }
  const version = Number(session.stateVersion);
  if (!Number.isInteger(version) || version < 0) return Promise.resolve(null);
  const completionKey = gameRecordsCompletionKey();
  const key = JSON.stringify([scope, version, completionKey]);
  if (recordsRequest?.key === key) return recordsRequest.promise;
  if (!force && records && (!completionKey || recordsTerminalKey === completionKey)) return Promise.resolve(records);
  const request = { scope, key, serial: ++recordsRequestSerial, promise: null };
  recordsRequest = request;
  recordsError = "";
  renderRecords();
  const ownsResponse = () => !terminalSessionError
    && recordsScope === scope && recordsRequestSerial === request.serial
    && gameRecordsScope() === scope && Number(session?.stateVersion) === version
    && gameRecordsCompletionKey() === completionKey;
  request.promise = (async () => {
    try {
      const next = await apiGet("records", { game_key: context.gameKey });
      if (!ownsResponse()) return null;
      if (!next || Array.isArray(next) || next.gameKey !== context.gameKey
        || !next.lifetime || Array.isArray(next.lifetime)
        || !["win", "loss", "draw", "recorded"].every(name => Number.isInteger(next.lifetime[name]) && next.lifetime[name] >= 0)) {
        const error = new Error("The game server returned invalid recorded results.");
        error.code = "GAME_RECORDS_INVALID";
        throw error;
      }
      records = next;
      recordsTerminalKey = completionKey;
      return next;
    } catch (error) {
      if (!ownsResponse()) return null;
      recordsError = "Recorded results could not be refreshed. Last loaded totals may be out of date; reopen this panel to retry.";
      trace("records-refresh-unavailable", null, { errorName: String(error?.name || "Error") });
      return null;
    } finally {
      if (recordsRequest === request) {
        recordsRequest = null;
        renderRecords();
      }
    }
  })();
  return request.promise;
}

function renderRecords() {
  const host = el("records");
  if (!host) return;
  host.replaceChildren();
  const scope = gameRecordsScope();
  const scopedRecords = scope && recordsScope === scope ? records : null;
  const loading = Boolean(scope && recordsRequest?.scope === scope);
  host.setAttribute("aria-busy", String(loading));
  if (loading) host.append(make("p", "minor", "Updating recorded results..."));
  if (scope && recordsScope === scope && recordsError) host.append(make("p", "minor", recordsError));
  if (!scopedRecords) {
    if (!loading && !recordsError) host.append(make("p", "minor", "Recorded results have not loaded."));
    return;
  }
  const lifetime = scopedRecords.lifetime;
  for (const [label, value] of [["Wins", lifetime.win], ["Losses", lifetime.loss], ["Draws", lifetime.draw], ["Recorded", lifetime.recorded]]) {
    const card = make("div", "record-card");
    card.append(make("span", "minor", label), make("strong", "", String(Number(value || 0))));
    host.append(card);
  }
  if (context.extensionId === "checkers" && scopedRecords?.recordClasses) {
    for (const [recordClass, label] of [["standard", "Standard Rules results"], ["custom", "Custom Rules results"]]) {
      const totals = scopedRecords.recordClasses[recordClass] || {};
      const card = make("div", "record-card rule-record-card");
      card.append(
        make("span", "minor", label),
        make("strong", "", String(Number(totals.recorded || 0))),
        make("span", "minor", `${Number(totals.win || 0)} wins · ${Number(totals.loss || 0)} losses · ${Number(totals.draw || 0)} draws`),
      );
      host.append(card);
    }
  }
}

function renderDrawProgress() {
  const host = el("draw-progress");
  const body = el("draw-progress-body");
  const status = el("draw-progress-status");
  host.hidden = !["chess", "checkers"].includes(context.extensionId);
  body.replaceChildren();
  if (host.hidden) {
    lastDrawProgressAnnouncementKey = "";
    status.textContent = "";
    return;
  }
  const progress = session.state?.drawProgress || {};
  if (gameSessionIsTerminal() && progress.completed !== true) {
    const text = gameTerminalStatus();
    body.append(make("p", "draw-progress-terminal", text));
    status.textContent = text;
    lastDrawProgressAnnouncementKey = `session-terminal:${session.status}:${session.stateVersion}`;
    return;
  }
  if (context.extensionId === "checkers") {
    const automatic = progress.automaticProtectionEnabled === true && progress.drawHandling !== "proposal-only";
    if (!automatic) {
      body.append(make("p", "draw-progress-terminal", "Automatic repetition and 40-move protection are disabled for this game. Mutual draw proposals remain available."));
      lastDrawProgressAnnouncementKey = "";
      status.textContent = "";
      return;
    }
    if (progress.completed) {
      const terminalReason = safe(progress.terminalReason || "completed game");
      const completedText = terminalReason === "third-repetition"
        ? "Automatic draw completed: the current position appeared for the third time."
        : terminalReason === "forty-move-rule"
          ? "Automatic draw completed: both players reached 40 qualifying quiet king moves."
          : `Game completed: ${terminalReason.replaceAll("-", " ")}.`;
      body.append(make("p", "draw-progress-terminal", completedText));
      const key = `checkers-terminal:${terminalReason}`;
      if (["third-repetition", "forty-move-rule"].includes(terminalReason)
          && lastDrawProgressAnnouncementKey !== key) status.textContent = completedText;
      else if (!["third-repetition", "forty-move-rule"].includes(terminalReason)) status.textContent = "";
      lastDrawProgressAnnouncementKey = key;
      return;
    }
    const appearances = Math.max(0, Number(progress.positionAppearances || 0));
    const repetition = make("div", "draw-progress-row");
    repetition.append(
      make("p", "", `Current position has appeared ${appearances} ${appearances === 1 ? "time" : "times"}.`),
      make("p", "minor", "The game draws automatically on the third occurrence."),
    );
    const players = (session.members || []).filter(member => ["master", "player"].includes(member.role));
    const quietParts = players.map(member => {
      const moves = Math.max(0, Number(progress.quietKingMovesByUser?.[String(member.userId)] || 0));
      return `${memberName(member.userId)}: ${moves} of 40`;
    });
    const quiet = make("div", "draw-progress-row");
    quiet.append(
      make("p", "", `${quietParts.join(" · ")} quiet king moves.`),
      make("p", "minor", "A capture or any uncrowned-man move, including a move that promotes, resets both players to 0. The game draws automatically only when both players reach 40."),
    );
    body.append(repetition, quiet);

    const notices = [];
    if (appearances === 2) notices.push("The automatic third-position repetition draw is imminent.");
    const quietCounts = players.map(member => Math.max(0, Number(progress.quietKingMovesByUser?.[String(member.userId)] || 0)));
    if (quietCounts.length === 2 && Math.min(...quietCounts) >= 39) notices.push("The automatic 40-move draw is imminent.");
    const key = notices.join("|");
    if (key && key !== lastDrawProgressAnnouncementKey) status.textContent = notices.join(" ");
    if (!key && lastDrawProgressAnnouncementKey) status.textContent = "";
    lastDrawProgressAnnouncementKey = key;
    return;
  }
  if (progress.completed) {
    const reason = safe(progress.terminalReason || "draw").replaceAll("-", " ");
    body.append(make("p", "draw-progress-terminal", `Game completed: ${reason}.`));
    const key = `terminal:${reason}`;
    if (lastDrawProgressAnnouncementKey !== key) status.textContent = `The Chess game ended: ${reason}.`;
    lastDrawProgressAnnouncementKey = key;
    return;
  }
  const appearances = Math.max(0, Number(progress.positionAppearances || 0));
  const repetition = make("div", "draw-progress-row");
  repetition.append(
    make("p", "", `Current position has appeared ${appearances} ${appearances === 1 ? "time" : "times"}.`),
    make("p", "minor", "A draw can be claimed after 3 appearances. It becomes automatic after 5."),
  );
  const players = (session.members || []).filter(member => ["master", "player"].includes(member.role));
  const moveParts = players.map(member => {
    const moves = Math.max(0, Number(progress.movesByUser?.[String(member.userId)] || 0));
    return `${memberName(member.userId)}: ${moves} ${moves === 1 ? "move" : "moves"}`;
  });
  const quiet = make("div", "draw-progress-row");
  quiet.append(
    make("p", "", `${moveParts.join(" · ")} since the last pawn move or capture.`),
    make("p", "minor", "A draw can be claimed when both players reach 50 moves. It becomes automatic when both reach 75."),
  );
  body.append(repetition, quiet);

  const notices = [];
  if (session.state?.drawClaims?.threefold === true) notices.push("A threefold repetition draw claim is now available.");
  if (session.state?.drawClaims?.fiftyMove === true) notices.push("A 50-move draw claim is now available.");
  if (appearances === 4) notices.push("The automatic fivefold repetition draw is imminent.");
  const moveCounts = players.map(member => Math.max(0, Number(progress.movesByUser?.[String(member.userId)] || 0)));
  if (moveCounts.length === 2 && Math.min(...moveCounts) >= 74) notices.push("The automatic 75-move draw is imminent.");
  const key = notices.join("|");
  if (key && key !== lastDrawProgressAnnouncementKey) status.textContent = notices.join(" ");
  if (!key && lastDrawProgressAnnouncementKey) status.textContent = "";
  lastDrawProgressAnnouncementKey = key;
}

function chessClockRemainingSeconds(clock) {
  if (!clock || clock.remainingSeconds === null) return null;
  let remaining = Math.max(0, Number(clock.remainingSeconds || 0));
  const startedAt = Date.parse(String(clock.turnStartedAt || ""));
  if (String(session?.state?._framework?.pause?.mode || "running") === "running"
      && session?.state?._framework?.serviceInterruption?.active !== true
      && Number.isFinite(startedAt)) {
    const serverNow = Number(session?.nowUnixMs);
    if (Number.isFinite(serverNow) && serverNow > 0) {
      const projectedNow = serverNow + Math.max(0, performance.now() - sessionProjectedAtMs);
      remaining -= Math.max(0, projectedNow - startedAt) / 1000;
    }
  }
  return Math.max(0, remaining);
}

function formatChessClock(seconds) {
  if (seconds === null) return "No clock";
  const whole = Math.max(0, Math.ceil(Number(seconds)));
  const minutes = Math.floor(whole / 60);
  return `${minutes}:${String(whole % 60).padStart(2, "0")}`;
}

function competitiveGameClockActive() {
  return String(session?.state?.clock?.kind || "none") !== "none";
}

function sharedInactivityRemainingSeconds() {
  const inactivity = session?.state?._framework?.inactivity || {};
  if (!inactivity.enabled) return null;
  return lifecycleProjectedRemaining(inactivity.remainingProjectedSeconds);
}

function playerStatusUsesSharedPlacementTimer(member) {
  if (context.extensionId !== "battleship" || String(session?.state?.phase || "") !== "placement") return false;
  const fleet = session?.state?.fleets?.[String(Number(member.userId || 0))] || {};
  return fleet.accepted !== true;
}

function playerStatusHasRunningTimer(member) {
  if (gameSessionIsTerminal()) return false;
  const userId = Number(member.userId || 0);
  if (competitiveGameClockActive()) return Number(session?.turnUserId || 0) === userId;
  const inactivity = session?.state?._framework?.inactivity || {};
  if (!inactivity.enabled) return false;
  if (playerStatusUsesSharedPlacementTimer(member)) return true;
  const ownerUserId = Number(inactivity.ownerUserId || 0);
  return ownerUserId > 0 && ownerUserId === userId;
}

function playerStatusIsCurrent(member) {
  if (gameSessionIsTerminal()) return false;
  const userId = Number(member.userId || 0);
  if (context.extensionId === "battleship" && String(session?.state?.phase || "") === "placement") {
    const fleet = session?.state?.fleets?.[String(userId)] || {};
    return fleet.accepted !== true;
  }
  return Number(session?.turnUserId || 0) === userId;
}

function playerStatusTimerRemainingSeconds(member) {
  const userId = String(Number(member.userId || 0));
  const framework = session?.state?._framework || {};
  if (framework.players?.[userId]?.disconnected) return lifecycleProjectedRemaining(framework.players[userId].disconnectRemainingSeconds);
  if (framework.pause?.mode === "resuming") return lifecycleProjectedRemaining(framework.pause.resumeRemainingSeconds);
  if (competitiveGameClockActive()) return chessClockRemainingSeconds(session?.state?.clocks?.[userId]);
  return playerStatusHasRunningTimer(member) ? sharedInactivityRemainingSeconds() : null;
}

function syncChessClockCells() {
  clearTimeout(chessClockRenderTimer);
  chessClockRenderTimer = 0;
  if (!session) return;
  for (const cell of document.querySelectorAll("[data-game-clock-user-id]")) {
    const userId = String(cell.dataset.gameClockUserId);
    const member = playerMembers().find(item => Number(item.userId) === Number(userId));
    const remaining = member ? playerStatusTimerRemainingSeconds(member) : null;
    const prefix = String(cell.dataset.timerPrefix || "");
    const status = member ? playerStatusTimerText(member) : "";
    cell.textContent = status ? `${prefix}${status}` : "";
    cell.hidden = status === "";
    cell.dataset.remainingSeconds = remaining === null ? "none" : String(Math.max(0, Math.ceil(remaining)));
  }
  for (const cell of document.querySelectorAll("[data-player-status-user-id]")) {
    const userId = String(cell.dataset.playerStatusUserId);
    const member = playerMembers().find(item => Number(item.userId) === Number(userId));
    const status = member ? playerStatusTimerText(member) : "";
    const statePrefix = String(cell.dataset.playerStatePrefix || "");
    const timerPrefix = status && playerStatusTimerConfigured() ? "Timer " : "";
    cell.textContent = [statePrefix, status ? `${timerPrefix}${status}` : ""].filter(Boolean).join(" · ");
  }
  const framework = session.state?._framework || {};
  const timeCanAdvance = String(framework.pause?.mode || "running") === "running"
    && framework.serviceInterruption?.active !== true;
  const lifecycleCanAdvance = framework.serviceInterruption?.active !== true
    && (Object.values(framework.players || {}).some(player => player.disconnected
      && lifecycleProjectedRemaining(player.disconnectRemainingSeconds) > 0)
      || (framework.pause?.mode === "resuming" && lifecycleProjectedRemaining(framework.pause.resumeRemainingSeconds) > 0));
  if (!session.state?.completed && (lifecycleCanAdvance || (timeCanAdvance
      && (competitiveGameClockActive() || framework.inactivity?.enabled === true)))) {
    chessClockRenderTimer = setTimeout(syncChessClockCells, 250);
  }
}

function playerStatusTimerConfigured() {
  const framework = session?.state?._framework || {};
  return competitiveGameClockActive() || framework.inactivity?.enabled === true;
}

async function settleChessClockAtDeadline(expectedVersion, expiredUserId) {
  if (chessClockSettlementInFlight || !["chess", "checkers"].includes(context.extensionId)) return;
  if (Number(session?.stateVersion || 0) !== Number(expectedVersion) || session?.state?.completed) return;
  chessClockSettlementInFlight = true;
  trace("chess-clock-deadline-reached", null, { expectedVersion, expiredUserId });
  try {
    await apiPost("extension-action", {
      request_id: requestId("chess-clock-deadline"),
      expected_version: Number(expectedVersion),
      action_type: "settle-clock",
      payload: { expired_user_id: Number(expiredUserId) },
    });
  } catch (error) {
    trace("chess-clock-settlement-refresh", null, { code: safe(error?.code || "conflict") });
  } finally {
    chessClockSettlementInFlight = false;
    await refreshSession(true).catch(() => {});
  }
}

function scheduleChessClockDeadline() {
  clearTimeout(chessClockDeadlineTimer);
  chessClockDeadlineTimer = 0;
  if (!["chess", "checkers"].includes(context.extensionId) || session?.state?.completed || session?.state?.clock?.kind === "none") return;
  if (String(session?.state?._framework?.pause?.mode || "running") !== "running") return;
  if (session?.state?._framework?.serviceInterruption?.active === true) return;
  if (!playerMembers().some(member => Number(member.userId) === currentUserId())) return;
  const activeUserId = Number(session.turnUserId || 0);
  const activeClock = session.state?.clocks?.[String(activeUserId)];
  if (activeUserId < 1 || !activeClock?.turnStartedAt) return;
  const remaining = chessClockRemainingSeconds(activeClock);
  if (remaining === null) return;
  const expectedVersion = Number(session.stateVersion || 0);
  chessClockDeadlineTimer = setTimeout(
    () => settleChessClockAtDeadline(expectedVersion, activeUserId),
    Math.max(0, Math.ceil(remaining * 1000) + 75),
  );
}

function playerStatusTimerText(member) {
  if (gameSessionIsTerminal() && session?.state?.completed !== true) return "Final";
  const userId = String(Number(member.userId || 0));
  const framework = session?.state?._framework || {};
  const pause = framework.pause || {};
  const inactivity = framework.inactivity || {};
  const competitive = competitiveGameClockActive();
  let baseLabel = "";
  if (competitive) {
    const base = formatChessClock(chessClockRemainingSeconds(session?.state?.clocks?.[userId]));
    baseLabel = base === "No clock" ? "" : base;
  } else if (inactivity.enabled) {
    if (context.extensionId === "battleship" && String(session?.state?.phase || "") === "placement"
        && session?.state?.fleets?.[userId]?.accepted === true) {
      baseLabel = "Ready";
    } else if (playerStatusHasRunningTimer(member)) {
      baseLabel = formatChessClock(sharedInactivityRemainingSeconds());
    } else {
      baseLabel = "Waiting";
    }
  }
  const withBase = label => baseLabel ? `${label} · ${baseLabel}` : label;
  if (session?.state?.completed) return baseLabel ? withBase("Final") : "";
  if (framework.serviceInterruption?.active) return withBase("Service interruption");
  if (framework.players?.[userId]?.disconnected) {
    return withBase(`Reconnect ${formatChessClock(lifecycleProjectedRemaining(framework.players[userId].disconnectRemainingSeconds))}`);
  }
  if (pause.mode === "resuming") return withBase(`Resumes in ${formatChessClock(lifecycleProjectedRemaining(pause.resumeRemainingSeconds))}`);
  if (pause.mode === "paused") return withBase("Paused");
  return baseLabel;
}

function spadesPlayerBidStatus(userId) {
  if (context.extensionId !== "spades") return null;
  const id = String(Number(userId || 0));
  const phase = String(session.state?.phase || "");
  const bid = session.state?.bids?.[id];
  const seat = (session.state?.turnOrder || []).findIndex(candidate => Number(candidate) === Number(userId));
  const team = seat >= 0 ? (seat % 2) + 1 : 0;
  if (bid?.private === true) return { label: "Bid submitted", team, state: "private" };
  if (!bid) return phase === "bidding" ? { label: "Waiting to bid", team, state: "waiting" } : null;
  const kind = String(bid.kind || "standard");
  if (kind === "blind-nil") return { label: "Blind Nil", team, state: "blind-nil" };
  if (kind === "nil") return { label: "Nil", team, state: "nil" };
  return { label: `Bid ${Math.max(0, Number(bid.amount || 0))}`, team, state: "standard" };
}

const BUILT_IN_BOARD_AVATAR_GAME_IDS = new Set([
  "acey-deucy",
  "backgammon-first-party",
  "battleship",
  "checkers",
  "chess",
]);

function renderPlayerStatusStrip() {
  const host = el("player-status-strip");
  host.replaceChildren();
  if (context.extensionId === "uno") {
    host.hidden = true;
    host.dataset.playerCount = "0";
    return;
  }
  const players = playerMembers();
  const blackjackHasUsefulStatus = context.extensionId !== "blackjack"
    || players.some(member => playerStatusTimerText(member) !== ""
      || (playerStatusIsCurrent(member) && !gameSessionIsTerminal()));
  const showBuiltInBoardAvatars = session?.presentation?.effectivePack === "built-in"
    && BUILT_IN_BOARD_AVATAR_GAME_IDS.has(context.extensionId);
  host.classList.toggle("has-built-in-board-avatars", showBuiltInBoardAvatars);
  host.hidden = players.length === 0 || !blackjackHasUsefulStatus;
  host.style.setProperty("--player-status-columns", String(Math.max(1, Math.min(4, players.length))));
  host.dataset.playerCount = String(players.length);
  if (host.hidden) return;
  const viewerIsPlayer = ["master", "player"].includes(String(session?.viewerRole || ""));
  const showMoves = ["chess", "checkers"].includes(context.extensionId);
  for (const member of players) {
    const userId = String(Number(member.userId || 0));
    const current = playerStatusIsCurrent(member);
    const bidStatus = spadesPlayerBidStatus(member.userId);
    const block = make("section", `player-status-block${current ? " is-current" : ""}${bidStatus ? " has-spades-bid" : ""}`);
    block.dataset.playerUserId = userId;
    const timerStatus = playerStatusTimerText(member);
    block.setAttribute("aria-label", `${safe(member.displayName)}${bidStatus ? `, ${bidStatus.label}` : ""}${showMoves ? ", move status" : ""}${timerStatus ? ", timer status" : ""}`);
    const identity = make("strong", "player-status-name", viewerIsPlayer && Number(member.userId) === currentUserId() ? "You" : safe(member.displayName));
    const facts = make("span", "player-status-facts");
    const timer = make("span", "player-status-timer", timerStatus);
    timer.dataset.gameClockUserId = userId;
    timer.hidden = timerStatus === "";
    if (!showMoves && playerStatusTimerConfigured()) timer.dataset.timerPrefix = "Timer ";
    if (showMoves) facts.append(make("span", "player-status-moves", `Moves ${Math.max(0, Number(session.state?.movesByUser?.[userId] || 0))}`));
    facts.append(timer);
    const simultaneousPlacement = context.extensionId === "battleship"
      && String(session?.state?.phase || "") === "placement";
    const turnLabel = simultaneousPlacement ? "Placement" : "Current turn";
    if (showBuiltInBoardAvatars) {
      block.classList.add("has-built-in-board-avatar");
      const avatar = memberAvatar(member, "built-in-board-player-avatar");
      avatar.dataset.playerUserId = userId;
      block.append(avatar);
    }
    block.append(identity, facts);
    if (bidStatus) {
      block.append(make("span", `player-status-bid is-team-${bidStatus.team} is-${bidStatus.state}`, bidStatus.label));
    }
    if (current && !gameSessionIsTerminal()) block.append(make("span", "player-status-turn", turnLabel));
    host.append(block);
  }
  requestAnimationFrame(syncChessClockCells);
}

function lifecycleProjectedRemaining(value) {
  const initial = Number(value);
  if (!Number.isFinite(initial)) return null;
  const elapsed = session?.state?._framework?.serviceInterruption?.active === true
    ? 0
    : Math.max(0, performance.now() - sessionProjectedAtMs) / 1000;
  return Math.max(0, initial - elapsed);
}

function syncSharedLifecycleCountdowns() {
  clearTimeout(sharedLifecycleRenderTimer);
  sharedLifecycleRenderTimer = 0;
  if (!session || gameSessionIsTerminal() || !["active", "paused"].includes(session.status)) return;
  const framework = session.state?._framework || {};
  const pause = framework.pause || {};
  let active = false;
  for (const node of document.querySelectorAll("[data-lifecycle-countdown]")) {
    const kind = String(node.dataset.lifecycleCountdown || "");
    if (kind === "resume") {
      const remaining = lifecycleProjectedRemaining(pause.resumeRemainingSeconds);
      node.textContent = `Resuming in ${formatChessClock(remaining)}.`;
      active = active || (remaining !== null && remaining > 0);
    } else if (kind === "inactivity") {
      const remaining = lifecycleProjectedRemaining(framework.inactivity?.remainingProjectedSeconds);
      node.textContent = `Inactivity time remaining: ${formatChessClock(remaining)}.`;
      active = active || (remaining !== null && remaining > 0);
    } else if (kind.startsWith("disconnect:")) {
      const userId = kind.slice("disconnect:".length);
      const player = framework.players?.[userId] || {};
      const remaining = lifecycleProjectedRemaining(player.disconnectRemainingSeconds);
      node.textContent = `${memberName(Number(userId))} disconnected · ${formatChessClock(remaining)} cumulative reconnect time remaining.`;
      active = active || (remaining !== null && remaining > 0);
    }
  }
  if (active) sharedLifecycleRenderTimer = setTimeout(syncSharedLifecycleCountdowns, 250);
}

function scheduleSharedLifecycleDeadline() {
  clearTimeout(sharedLifecycleDeadlineTimer);
  sharedLifecycleDeadlineTimer = 0;
  syncSharedLifecycleCountdowns();
  if (!session || terminalSessionError || busy || gameSessionIsTerminal() || !["active", "paused"].includes(session.status) || !["master", "player"].includes(String(session.viewerRole || ""))) return;
  const framework = session.state?._framework || {};
  if (framework.serviceInterruption?.active) return;
  const pause = framework.pause || {};
  let action = "";
  let remaining = null;
  if (pause.mode === "resuming" && typeof pause.resumeRemainingSeconds === "number" && Number.isFinite(pause.resumeRemainingSeconds)) {
    action = "settle-resume";
    remaining = Number(pause.resumeRemainingSeconds);
  } else if (pause.mode === "running" && framework.inactivity?.enabled && typeof framework.inactivity.remainingProjectedSeconds === "number" && Number.isFinite(framework.inactivity.remainingProjectedSeconds)) {
    action = "settle-inactivity";
    remaining = Number(framework.inactivity.remainingProjectedSeconds);
  }
  if (!action || remaining === null) return;
  const expectedVersion = Number(session.stateVersion || 0);
  const expectedPublicId = String(session.publicId || "");
  if (!expectedPublicId) return;
  sharedLifecycleDeadlineTimer = setTimeout(async () => {
    if (terminalSessionError || gameSessionIsTerminal() || !["active", "paused"].includes(session?.status)
      || String(session?.publicId || "") !== expectedPublicId
      || sharedLifecycleSettlementInFlight || Number(session?.stateVersion || 0) !== expectedVersion) return;
    sharedLifecycleSettlementInFlight = true;
    try {
      await apiPost("extension-action", {
        request_id: requestId(`shared-${action}`), expected_version: expectedVersion,
        action_type: action, payload: {},
      });
    } catch (_) {
      // Another current player may settle the same deadline first. Refresh is authoritative.
    } finally {
      sharedLifecycleSettlementInFlight = false;
      await refreshSession(true).catch(() => {});
    }
  }, Math.max(0, Math.ceil(remaining * 1000) + 75));
}

function nestedFourLiveState(state, userId, terminalLabel = null) {
  const groups = state?.reserves?.[String(userId)];
  const available = Array.isArray(groups) && groups.every(group => Number.isInteger(group?.remaining) && group.remaining >= 0);
  const remaining = available ? groups.reduce((total, group) => total + group.remaining, 0) : null;
  const reserve = remaining === null ? 'Reserve count unavailable' : remaining + (remaining === 1 ? ' piece in reserve' : ' pieces in reserve');
  const winner = Number(state?.winnerUserId);
  const outcome = terminalLabel || (state?.completed
    ? state.winnerUserId === null ? 'Draw'
      : Number.isSafeInteger(winner) && winner > 0 ? (winner === Number(userId) ? 'Winner' : 'Lost') : 'Completed'
    : state?.phase === 'lobby' ? 'Waiting to start' : 'Playing');
  return outcome + ' \u00b7 ' + reserve;
}

function publicGameLiveSummary(extensionId, state, userId) {
  const key = String(userId);
  const score = value => typeof value === "number" && Number.isFinite(value);
  const count = value => Number.isSafeInteger(value) && value >= 0;
  const label = (name, value, valid = score) => valid(value)
    ? `${name}: ${value.toLocaleString()}` : `${name} unavailable`;
  if (extensionId === "hearts") {
    return [
      label("Total points", state?.scores?.[key]),
      label("Tricks", state?.tricksWon?.[key], count),
      label("Captured points", state?.captured?.[key]?.points, count),
    ].join(" · ");
  }
  if (extensionId === "uno") {
    return [label("Points", state?.scores?.[key]), label("Cards", state?.cardCounts?.[key], count)].join(" · ");
  }
  if (extensionId === "chinese-checkers") {
    const home = state?.homeProgress?.[key], total = state?.piecesPerPlayer;
    return count(home) && count(total) && total > 0 && home <= total
      ? `Home: ${home.toLocaleString()} / ${total.toLocaleString()} pieces`
      : "Home progress unavailable";
  }
  if (extensionId === "puppy-panic") {
    const eliminated = state?.eliminated?.[key];
    return [
      label("Cards", state?.cardCounts?.[key], count),
      eliminated === true ? "Eliminated" : eliminated === false ? "Not eliminated" : "Player status unavailable",
    ].join(" · ");
  }
  return "";
}

function renderLiveScore() {
  const host = el("live-score");
  host.replaceChildren();
  const state = session?.state || {};
  const table = make("table", "score-table");
  const head = make("thead");
  const headRow = make("tr");
  for (const label of ["Player", "Seat", "Turn", "Live score/state"]) headRow.append(make("th", "", label));
  head.append(headRow);
  const body = make("tbody");
  for (const member of (session?.members || []).filter(item => ["master", "player"].includes(item.role))) {
    const row = make("tr");
    let gameState = context.extensionId === "blackjack" ? blackjackLiveState(member.userId)
      : context.extensionId === "nested-four" ? nestedFourLiveState(state, member.userId, gameSessionIsTerminal() ? gameMemberTerminalLabel(member.userId) : null) : (context.extensionId === "battleship"
      ? (state.fleets?.[String(member.userId)]?.placed ? "Fleet ready" : gameSessionIsTerminal() ? "Fleet incomplete" : "Placing fleet")
      : context.extensionId === "spades"
        ? `Team ${((Number(member.seat || 1) - 1) % 2) + 1} · ${Number(state.tricksWon?.[String(member.userId)] || 0)} tricks`
        : ["acey-deucy", "backgammon-first-party"].includes(context.extensionId)
          ? `${Number(state.borneOff?.[String(member.userId)] || 0)} borne off · ${Number(state.bar?.[String(member.userId)] || 0)} on bar`
          : ["checkers", "chess"].includes(context.extensionId)
            ? `Moves ${Math.max(0, Number(state.movesByUser?.[String(member.userId)] || 0))}`
            : publicGameLiveSummary(context.extensionId, state, member.userId));
    if (gameSessionIsTerminal() && !["blackjack", "nested-four"].includes(context.extensionId)) {
      gameState = [gameMemberTerminalLabel(member.userId), gameState].filter(Boolean).join(" · ");
    }
    const timerStatus = playerStatusTimerText(member);
    const live = [gameState, timerStatus ? `${playerStatusTimerConfigured() ? "Timer " : ""}${timerStatus}` : ""].filter(Boolean).join(" · ");
    const values = [member.displayName, playerPositionLabel(member), gameSessionIsTerminal() ? "Complete" : (Number(session.turnUserId) === Number(member.userId) ? "Current" : "Waiting"), live];
    values.forEach((value, index) => {
      const cell = make("td", "", String(value));
      if (index === 3) {
        cell.dataset.playerStatusUserId = String(Number(member.userId));
        cell.dataset.playerStatePrefix = gameState;
        cell.setAttribute("aria-label", `${safe(member.displayName)} live game state${timerStatus ? " and timer" : ""}`);
      }
      row.append(cell);
    });
    body.append(row);
  }
  table.append(head, body);
  host.append(table);
  requestAnimationFrame(syncChessClockCells);
}

function boardButton(row, column, label) {
  const button = make("button", "board-cell");
  button.type = "button";
  button.dataset.row = String(row);
  button.dataset.column = String(column);
  button.setAttribute("aria-label", label);
  return button;
}

function appendCheckersClassicEdgeActions(board) {
  if (!["master", "player"].includes(session.viewerRole)) return;
  const source = classicSourceMap("checkers");
  const host = make("div", "checkers-classic-edge-actions");
  const makeTab = (kind, label, geometry, actionLabel, action, disabled = false) => {
    const drawer = make("section", `checkers-edge-tab is-${kind}${openCheckersDrawer === kind ? " is-open" : ""}`);
    drawer.dataset.drawer = kind;
    drawer.dataset.drawerState = openCheckersDrawer === kind ? "open" : "retracted";
    const tabGeometry = source.controls[`${kind}Tab`];
    // Both authenticated OCX edge controls are fixed to the immutable right
    // board edge and reveal inward.  Geometry is source-owned above; never
    // derive or permit an outward/right fallback from implementation boxes.
    drawer.dataset.openDirection = "left";
    setSourceBox(drawer, openCheckersDrawer === kind ? geometry : tabGeometry, source.canvas.width, source.canvas.height);
    const handle = make("button", "checkers-edge-handle", label);
    handle.type = "button";
    handle.disabled = disabled || busy;
    const artPrefix = kind === "draw" ? "gif-draw" : "gif-resign";
    const initialArtState = handle.disabled ? "d" : openCheckersDrawer === kind ? "p" : "r";
    const handleArt = mediaImage(`${artPrefix}-${initialArtState}`, "checkers-edge-handle-art", "");
    handleArt.dataset.slot = `${artPrefix}-${initialArtState}`;
    const setHandleArt = state => {
      const slot = `${artPrefix}-${handle.disabled ? "d" : state}`;
      if (handleArt.dataset.slot === slot) return;
      handleArt.dataset.slot = slot;
      handleArt.src = mediaUrl(slot);
    };
    handle.replaceChildren(make("span", "sr-only", label));
    handle.setAttribute("aria-label", `${openCheckersDrawer === kind ? "Close" : "Open"} ${label.toLowerCase()} actions`);
    handle.setAttribute("aria-expanded", openCheckersDrawer === kind ? "true" : "false");
    handle.setAttribute("aria-controls", `checkers-${kind}-drawer-panel`);
    const panel = make("div", "checkers-edge-confirmation-rail");
    panel.id = `checkers-${kind}-drawer-panel`;
    panel.hidden = false;
    panel.inert = openCheckersDrawer !== kind;
    panel.setAttribute("aria-hidden", openCheckersDrawer === kind ? "false" : "true");
    const confirm = make("button", "checkers-edge-confirm", kind === "resign" ? "Confirm" : actionLabel);
    confirm.type = "button";
    confirm.setAttribute("aria-label", actionLabel);
    confirm.disabled = handle.disabled;
    confirm.addEventListener("click", async () => {
      openCheckersDrawer = null;
      await action();
    });
    const cancel = make("button", "checkers-edge-cancel", "Cancel");
    cancel.type = "button";
    cancel.addEventListener("click", () => {
      openCheckersDrawer = null;
      drawer.classList.remove("is-open");
      drawer.dataset.drawerState = "retracted";
      setSourceBox(drawer, source.controls[`${kind}Tab`], source.canvas.width, source.canvas.height);
      setHandleArt("r");
      panel.inert = true;
      panel.setAttribute("aria-hidden", "true");
      handle.setAttribute("aria-expanded", "false");
      handle.setAttribute("aria-label", `Open ${label.toLowerCase()} actions`);
      handle.focus();
    });
    const setOpen = open => {
      for (const peer of host.querySelectorAll(".checkers-edge-tab")) {
        const peerOpen = open && peer === drawer;
        const peerKind = peer.dataset.drawer;
        peer.classList.toggle("is-open", peerOpen);
        peer.dataset.drawerState = peerOpen ? "open" : "retracted";
        setSourceBox(
          peer,
          source.controls[`${peerKind}${peerOpen ? "Drawer" : "Tab"}`],
          source.canvas.width,
          source.canvas.height,
        );
        const peerPanel = peer.querySelector(".checkers-edge-confirmation-rail");
        const peerHandle = peer.querySelector(".checkers-edge-handle");
        const peerArt = peer.querySelector(".checkers-edge-handle-art");
        if (peerPanel) {
          peerPanel.inert = !peerOpen;
          peerPanel.setAttribute("aria-hidden", peerOpen ? "false" : "true");
        }
        if (peerHandle) {
          peerHandle.setAttribute("aria-expanded", peerOpen ? "true" : "false");
          peerHandle.setAttribute("aria-label", `${peerOpen ? "Close" : "Open"} ${peer.dataset.drawer} actions`);
        }
        if (peerArt) {
          const peerPrefix = peerKind === "draw" ? "gif-draw" : "gif-resign";
          const peerSlot = `${peerPrefix}-${peerHandle?.disabled ? "d" : peerOpen ? "p" : "r"}`;
          peerArt.dataset.slot = peerSlot;
          peerArt.src = mediaUrl(peerSlot);
        }
      }
      openCheckersDrawer = open ? kind : null;
      if (open) confirm.focus(); else handle.focus();
    };
    handle.addEventListener("pointerenter", () => { if (openCheckersDrawer !== kind) setHandleArt("h"); });
    handle.addEventListener("pointerleave", () => setHandleArt(openCheckersDrawer === kind ? "p" : "r"));
    handle.addEventListener("focus", () => { if (openCheckersDrawer !== kind) setHandleArt("h"); });
    handle.addEventListener("blur", () => setHandleArt(openCheckersDrawer === kind ? "p" : "r"));
    handle.addEventListener("pointerdown", () => setHandleArt("p"));
    handle.addEventListener("pointercancel", () => setHandleArt(openCheckersDrawer === kind ? "p" : "r"));
    handle.addEventListener("click", () => {
      if (!handle.disabled) setOpen(openCheckersDrawer !== kind);
    });
    drawer.addEventListener("keydown", event => {
      if (event.key === "Escape" && openCheckersDrawer === kind) {
        event.preventDefault();
        setOpen(false);
        return;
      }
      if (event.key !== "Tab" || openCheckersDrawer !== kind) return;
      const focusable = [handle, confirm, cancel].filter(node => !node.disabled && !node.hidden);
      const index = focusable.indexOf(document.activeElement);
      const next = event.shiftKey ? (index <= 0 ? focusable.length - 1 : index - 1) : (index >= focusable.length - 1 ? 0 : index + 1);
      event.preventDefault();
      focusable[next].focus();
    });
    panel.append(confirm, cancel);
    drawer.append(handleArt, handle, panel);
    host.append(drawer);
  };
  const drawOfferBy = Number(session.state?.drawOfferBy || 0);
  makeTab(
    "draw", "DRAW", source.controls.drawDrawer,
    drawOfferBy > 0 ? "Draw pending" : "Offer draw",
    () => performAction("offer-draw"),
    drawOfferBy > 0 || !gameLifecycleAvailable(),
  );
  makeTab(
    "resign", "RESIGN", source.controls.resignDrawer, "Confirm resignation",
    () => performAction("resign"), !gameLifecycleAvailable() || drawOfferBy > 0,
  );
  board.append(host);
}

function animateSourceBox(node, from, to, canvas, durationMs, easing = "linear", delayMs = 0) {
  const frames = [from, to].map(box => ({
    left: `${(Number(box.x) / canvas.width) * 100}%`,
    top: `${(Number(box.y) / canvas.height) * 100}%`,
    width: `${(Number(box.width) / canvas.width) * 100}%`,
    height: `${(Number(box.height) / canvas.height) * 100}%`,
  }));
  const animation = node.animate(frames, { duration: durationMs, delay: delayMs, easing, fill: "both" });
  const elapsed = Math.max(0, performance.now() - Number(pendingClassicMotion?.startedAt || performance.now()));
  animation.currentTime = Math.min(durationMs + delayMs, elapsed);
  node.dataset.motionDurationMs = String(durationMs);
  node.dataset.motionDelayMs = String(delayMs);
  return animation;
}

function appendMovingAsset(stage, slot, from, to, durationMs, easing = "linear", className = "", delayMs = 0) {
  const source = classicSourceMap(context.extensionId);
  const image = mediaImage(slot, `classic-source-motion-asset ${className}`, "");
  setSourceBox(image, from, source.canvas.width, source.canvas.height);
  image.dataset.motionSlot = slot;
  image.dataset.motionFrom = `${from.x},${from.y},${from.width},${from.height}`;
  image.dataset.motionTo = `${to.x},${to.y},${to.width},${to.height}`;
  stage.append(image);
  requestAnimationFrame(() => animateSourceBox(image, from, to, source.canvas, durationMs, easing, delayMs));
  return image;
}

function appendSegmentedPointHitAsset(stage, slot, from, to, durationMs, easing, sections = 5, delayMs = 0) {
  const source = classicSourceMap(context.extensionId);
  const count = Math.max(2, Number(sections || 5));
  const sectionDurationMs = Math.max(1, Number(durationMs || 0) / count);
  let firstSection = null;
  for (let index = 0; index < count; index += 1) {
    const image = mediaImage(slot, "classic-source-motion-asset is-hit-to-bar is-segmented-capture is-hit-capture-section", "");
    const left = (index / count) * 100;
    const right = 100 - ((index + 1) / count) * 100;
    const eraseOrder = count - 1 - index;
    const sectionDelayMs = Number(delayMs || 0) + (eraseOrder * sectionDurationMs);
    setSourceBox(image, from, source.canvas.width, source.canvas.height);
    image.style.clipPath = `inset(0 ${right}% 0 ${left}%)`;
    image.dataset.motionSlot = slot;
    image.dataset.motionFrom = `${from.x},${from.y},${from.width},${from.height}`;
    image.dataset.motionTo = `${to.x},${to.y},${to.width},${to.height}`;
    image.dataset.motionDurationMs = String(sectionDurationMs);
    image.dataset.motionDelayMs = String(sectionDelayMs);
    image.dataset.motionCapturedEraseSections = String(count);
    image.dataset.motionCapturedEraseSection = String(index + 1);
    image.dataset.motionCapturedEraseDirection = "right-to-left";
    stage.append(image);
    requestAnimationFrame(() => {
      const animation = image.animate([
        { opacity:1, offset:0 },
        { opacity:1, offset:.999 },
        { opacity:0, offset:1 },
      ], {
        duration:sectionDurationMs,
        delay:sectionDelayMs,
        easing:"linear",
        fill:"both",
      });
      const elapsed = Math.max(0, performance.now() - Number(pendingClassicMotion?.startedAt || performance.now()));
      animation.currentTime = Math.min(sectionDelayMs + sectionDurationMs, elapsed);
    });
    firstSection ||= image;
  }
  return firstSection;
}

function revealAfterMotion(node, durationMs) {
  if (!node || durationMs <= 0) return;
  const animation = node.animate([
    { opacity: 0, offset: 0 },
    { opacity: 0, offset: .999 },
    { opacity: 1, offset: 1 },
  ], { duration: durationMs, easing: "linear", fill: "both" });
  const elapsed = Math.max(0, performance.now() - Number(pendingClassicMotion?.startedAt || performance.now()));
  animation.currentTime = Math.min(durationMs, elapsed);
}

function hideAfterMotion(node, durationMs) {
  if (!node || durationMs <= 0) return;
  const animation = node.animate([
    { opacity: 1, offset: 0 },
    { opacity: 1, offset: .999 },
    { opacity: 0, offset: 1 },
  ], { duration: durationMs, easing: "linear", fill: "both" });
  const elapsed = Math.max(0, performance.now() - Number(pendingClassicMotion?.startedAt || performance.now()));
  animation.currentTime = Math.min(durationMs, elapsed);
}

function sourceAssetBox(cell, dimensions) {
  return centeredSourceBox(cell, {
    width: Number(dimensions?.width || cell.width),
    height: Number(dimensions?.height || cell.height),
  });
}

function bottomCenteredSourceAssetBox(cell, dimensions) {
  const width = Number(dimensions?.width || cell.width);
  const height = Number(dimensions?.height || cell.height);
  return {
    x:Number(cell.x) + (Number(cell.width) - width) / 2,
    y:Number(cell.y) + Number(cell.height) - height,
    width,
    height,
  };
}

function chessSelectedPieceNaturalSize(source, slot, level) {
  return source.selectedPieceNaturalSizes.bySlot[slot]
    || source.selectedPieceNaturalSizes.byLevel[level];
}

function appendChessMovingAsset(stage, slot, destinationSlot, from, to, fromDimensions, toDimensions, durationMs, easing, delayMs) {
  const source = classicSourceMap("chess");
  const image = mediaImage(slot, "classic-source-motion-asset is-chess-slide", "");
  const movingFrom = bottomCenteredSourceAssetBox(from, fromDimensions);
  const movingTo = bottomCenteredSourceAssetBox(to, toDimensions);
  setSourceBox(image, movingFrom, source.canvas.width, source.canvas.height);
  image.dataset.motionSlot = slot;
  image.dataset.motionDestinationSlot = destinationSlot;
  image.dataset.motionFrom = `${movingFrom.x},${movingFrom.y},${movingFrom.width},${movingFrom.height}`;
  image.dataset.motionTo = `${movingTo.x},${movingTo.y},${movingTo.width},${movingTo.height}`;
  image.dataset.motionSourceAssetSize = `${fromDimensions.width},${fromDimensions.height}`;
  image.dataset.motionDestinationAssetSize = `${toDimensions.width},${toDimensions.height}`;
  stage.append(image);
  requestAnimationFrame(() => animateSourceBox(
    image, movingFrom, movingTo, source.canvas, durationMs, easing, delayMs,
  ));
  return image;
}

function checkersCaptureVictim(motion) {
  if (!motion?.move?.captured) return null;
  const from = motion.move.from || [];
  const mover = String(motion.before.board?.[from[0]]?.[from[1]] || "");
  for (let row = 0; row < 8; row++) for (let column = 0; column < 8; column++) {
    if (row === Number(from[0]) && column === Number(from[1])) continue;
    const beforePiece = String(motion.before.board?.[row]?.[column] || "");
    const afterPiece = String(motion.after.board?.[row]?.[column] || "");
    if (beforePiece && !afterPiece && beforePiece.toLowerCase() !== mover.toLowerCase()) {
      return { piece: beforePiece, row, column };
    }
  }
  return null;
}

function chessCaptureVictim(motion) {
  if (!motion?.move?.capture) return null;
  const from = motion.move.from || [];
  const to = motion.move.to || [];
  const direct = String(motion.before.board?.[to[0]]?.[to[1]] || "");
  if (direct) return { piece: direct, row: Number(to[0]), column: Number(to[1]) };
  // En passant leaves the destination empty in the predecessor. Bind the
  // source fall to the one opponent piece removed outside the mover origin.
  const mover = String(motion.before.board?.[from[0]]?.[from[1]] || "");
  for (let row = 0; row < 8; row++) for (let column = 0; column < 8; column++) {
    if (row === Number(from[0]) && column === Number(from[1])) continue;
    const beforePiece = String(motion.before.board?.[row]?.[column] || "");
    const afterPiece = String(motion.after.board?.[row]?.[column] || "");
    if (beforePiece && !afterPiece && beforePiece[0] !== mover[0]) return { piece: beforePiece, row, column };
  }
  return null;
}

function centeredSourceBox(cell, size) {
  return {
    x: Number(cell.x) + (Number(cell.width) - Number(size.width)) / 2,
    y: Number(cell.y) + (Number(cell.height) - Number(size.height)) / 2,
    width: Number(size.width),
    height: Number(size.height),
  };
}

function appendChessCaptureFall(stage, motion, delayMs = 0) {
  const victim = chessCaptureVictim(motion);
  if (!victim) return 0;
  const source = classicSourceMap("chess");
  const native = chessNativeCapture(motion);
  if (native) {
    const elapsed = Math.max(0, performance.now() - Number(pendingClassicMotion?.startedAt || performance.now()));
    const host = sourceStripNode("chess", native.slot, native.box, native, delayMs, "classic-chess-native-capture", elapsed);
    host.dataset.captureSquare = `${victim.row}:${victim.column}`;
    host.dataset.capturedPiece = victim.piece;
    stage.append(host);
    return native.durationMs;
  }
  const timeline = source.motion.captureFall;
  const cell = checkerCellBox("chess", victim.row, victim.column);
  const elapsed = Math.max(0, performance.now() - Number(pendingClassicMotion?.startedAt || performance.now()));
  const pieceNames = { K: "k", Q: "q", R: "r", B: "b", N: "kn", P: "p" };
  const pieceType = String(victim.piece[1] || "P");
  const direction = victim.piece[0] === "w" ? "u" : "d";
  const predecessor = mediaImage(
    `gif-${pieceNames[pieceType] || "p"}-${victim.piece[0]}-${source.pieceSizeByRow[victim.row]}`,
    "classic-chess-captured-predecessor",
    "",
  );
  setSourceBox(predecessor, cell, source.canvas.width, source.canvas.height);
  predecessor.dataset.capturedPiece = victim.piece;
  predecessor.dataset.captureSquare = `${victim.row}:${victim.column}`;
  predecessor.style.setProperty("--capture-delay", `${delayMs - elapsed}ms`);
  predecessor.style.setProperty("--capture-frame-duration", `${timeline.frameDurationMs}ms`);
  stage.append(predecessor);

  const hole = mediaImage("gif-dead-nul", "classic-chess-capture-hole", "");
  setSourceBox(hole, centeredSourceBox(cell, timeline.holeSize), source.canvas.width, source.canvas.height);
  hole.dataset.motionKind = "chess-capture-hole";
  hole.style.setProperty("--capture-delay", `${delayMs - elapsed}ms`);
  hole.style.setProperty("--capture-duration", `${timeline.durationMs}ms`);
  stage.append(hole);

  const suffixes = timeline.frameSlotsByPiece[pieceType] || timeline.frameSlotsByPiece.P;
  suffixes.forEach((suffix, index) => {
    const slotPiece = pieceType === "N" ? "k" : pieceType.toLowerCase();
    const slot = `gif-dead-${slotPiece}${direction}${suffix}`;
    const frame = mediaImage(slot, "classic-chess-capture-fragment", "");
    setSourceBox(frame, centeredSourceBox(cell, timeline.fragmentSize), source.canvas.width, source.canvas.height);
    frame.dataset.motionSlot = slot;
    frame.dataset.frameIndex = String(index);
    frame.dataset.capturedPiece = victim.piece;
    frame.style.setProperty("--source-frame-delay", `${delayMs + index * timeline.frameDurationMs - elapsed}ms`);
    frame.style.setProperty("--source-frame-duration", `${timeline.frameDurationMs}ms`);
    stage.append(frame);
  });
  return timeline.durationMs;
}

function checkersMovingSurfaceSlot(piece, level) {
  const source = classicSourceMap("checkers").motion.movingSurfaces;
  const side = String(piece || "").toLowerCase();
  const family = source[side] || source.b;
  const base = piece === String(piece || "").toUpperCase() ? family.kingBase : family.manBase;
  return `bitmap-${Number(base) + Math.max(1, Math.min(4, Number(level))) - 1}`;
}

function sourceStripNode(gameId, slot, targetBox, strip, delayMs, className, elapsed = 0) {
  const source = classicSourceMap(gameId);
  const host = make("div", `classic-source-strip-host ${className}`);
  host.dataset.motionSlot = slot;
  host.dataset.frameCount = String(strip.frameCount);
  host.dataset.frameDurationMs = String(strip.frameDurationMs);
  host.dataset.motionDelayMs = String(delayMs);
  setSourceBox(host, targetBox, source.canvas.width, source.canvas.height);
  for (let index = 0; index < Number(strip.frameCount); index++) {
    const frame = make("div", "classic-source-strip-frame");
    frame.dataset.frameIndex = String(index);
    frame.style.setProperty("--source-frame-delay", `${delayMs + index * Number(strip.frameDurationMs) - elapsed}ms`);
    frame.style.setProperty("--source-frame-duration", `${Number(strip.frameDurationMs)}ms`);
    const image = mediaImage(slot, "classic-source-strip-image", "");
    image.style.height = `${Number(strip.frameCount) * 100}%`;
    image.style.top = `${-index * 100}%`;
    frame.append(image);
    host.append(frame);
  }
  return host;
}

function appendVerticalSourceStrip(stage, slot, targetBox, strip, delayMs, className) {
  const elapsed = Math.max(0, performance.now() - Number(pendingClassicMotion?.startedAt || performance.now()));
  const host = sourceStripNode("checkers", slot, targetBox, strip, delayMs, className, elapsed);
  stage.append(host);
  return Number(strip.frameCount) * Number(strip.frameDurationMs);
}

function sourceStripFinalFrame(slot, strip, className) {
  const host = make("div", `classic-source-strip-host classic-source-static-strip-frame ${className}`);
  host.dataset.motionSlot = slot;
  host.dataset.frameCount = String(strip.frameCount);
  host.dataset.frameIndex = String(Number(strip.frameCount) - 1);
  const image = mediaImage(slot, "classic-source-strip-image", "");
  image.style.height = `${Number(strip.frameCount) * 100}%`;
  image.style.top = `${-(Number(strip.frameCount) - 1) * 100}%`;
  host.append(image);
  return host;
}

function persistentSourceStripNode(slot, strip, className, animate = true) {
  const frameCount = Math.max(1, Number(strip.frameCount));
  const frameDurationMs = Math.max(1, Number(strip.frameDurationMs));
  const host = make("div", `classic-source-strip-host classic-source-persistent-strip ${className}`);
  host.dataset.motionSlot = slot;
  host.dataset.frameCount = String(frameCount);
  host.dataset.frameDurationMs = String(frameDurationMs);
  host.dataset.persistentSourceState = "true";
  const image = mediaImage(slot, "classic-source-strip-image", "");
  image.style.height = `${frameCount * 100}%`;
  if (!animate || frameCount === 1) {
    host.dataset.looping = "false";
    host.dataset.frameIndex = String(frameCount - 1);
    image.style.top = `${-(frameCount - 1) * 100}%`;
  } else {
    host.dataset.looping = "true";
    image.style.top = "0";
    const travel = 100;
    image.animate([
      { transform:"translateY(0)" },
      { transform:`translateY(-${travel}%)` },
    ], {
      duration:frameCount * frameDurationMs,
      iterations:Infinity,
      easing:`steps(${frameCount}, end)`,
    });
  }
  host.append(image);
  return host;
}

function loopingSourceStripNode(gameId, slot, targetBox, strip, cycleDurationMs, startMs, className) {
  const source = classicSourceMap(gameId);
  const host = make("div", `classic-source-strip-host ${className}`);
  host.dataset.motionSlot = slot;
  host.dataset.frameCount = String(strip.frameCount);
  host.dataset.frameDurationMs = String(strip.frameDurationMs);
  host.dataset.loopCycleMs = String(cycleDurationMs);
  host.dataset.loopStartMs = String(startMs);
  setSourceBox(host, targetBox, source.canvas.width, source.canvas.height);
  const image = mediaImage(slot, "classic-source-strip-image", "");
  image.style.height = `${Number(strip.frameCount) * 100}%`;
  image.style.top = "0";
  host.append(image);
  const activeDuration = Number(strip.frameCount) * Number(strip.frameDurationMs);
  const startOffset = Math.max(0, Math.min(.999, startMs / cycleDurationMs));
  const endOffset = Math.max(startOffset + .0001, Math.min(.999, (startMs + activeDuration) / cycleDurationMs));
  const travel = ((Number(strip.frameCount) - 1) / Number(strip.frameCount)) * 100;
  image.animate([
    { transform:"translateY(0)", offset:0 },
    { transform:"translateY(0)", offset:startOffset },
    { transform:`translateY(-${travel}%)`, offset:endOffset },
    { transform:`translateY(-${travel}%)`, offset:1 },
  ], { duration:cycleDurationMs, iterations:Infinity, easing:`steps(${Math.max(1, Number(strip.frameCount) - 1)}, end)` });
  host.animate([
    { opacity:0, offset:0 },
    { opacity:0, offset:startOffset },
    { opacity:1, offset:Math.min(.999, startOffset + .0001) },
    { opacity:1, offset:endOffset },
    { opacity:0, offset:Math.min(1, endOffset + .0001) },
    { opacity:0, offset:1 },
  ], { duration:cycleDurationMs, iterations:Infinity, easing:"linear" });
  return host;
}

function animateLoopingSourceTravel(node, from, to, cycleDurationMs, startMs, travelDurationMs, canvas) {
  const sourceFrame = box => ({
    left:`${(Number(box.x) / canvas.width) * 100}%`,
    top:`${(Number(box.y) / canvas.height) * 100}%`,
    width:`${(Number(box.width) / canvas.width) * 100}%`,
    height:`${(Number(box.height) / canvas.height) * 100}%`,
  });
  const startOffset = Math.max(0, Math.min(.999, startMs / cycleDurationMs));
  const endOffset = Math.max(startOffset + .0001, Math.min(.999, (startMs + travelDurationMs) / cycleDurationMs));
  node.animate([
    { ...sourceFrame(from), opacity:0, offset:0 },
    { ...sourceFrame(from), opacity:0, offset:startOffset },
    { ...sourceFrame(from), opacity:1, offset:Math.min(.999, startOffset + .0001) },
    { ...sourceFrame(to), opacity:1, offset:endOffset },
    { ...sourceFrame(to), opacity:0, offset:Math.min(1, endOffset + .0001) },
    { ...sourceFrame(to), opacity:0, offset:1 },
  ], { duration:cycleDurationMs, iterations:Infinity, easing:"linear" });
}

function appendCheckersCaptureEffect(stage, motion, movingFrom, capturedBox, delayMs = 0) {
  const source = classicSourceMap("checkers");
  const timeline = source.motion.capture;
  const piece = String(motion.before.board?.[motion.move.from?.[0]]?.[motion.move.from?.[1]] || "");
  const side = piece.toLowerCase();
  const projectileSlot = timeline.projectile.slots[side] || timeline.projectile.slots.b;
  const projectileFrom = centeredSourceBox(movingFrom, timeline.projectile.naturalSize);
  const projectileTo = centeredSourceBox(capturedBox, timeline.projectile.naturalSize);
  const projectileDelay = delayMs + Number(timeline.projectile.delayMs);
  const projectile = appendMovingAsset(
    stage, projectileSlot, projectileFrom, projectileTo,
    Number(timeline.projectile.durationMs), timeline.projectile.easing,
    "classic-checkers-capture-projectile", projectileDelay,
  );
  projectile.dataset.motionKind = "checkers-side-projectile";
  projectile.dataset.moverSide = side;
  const explosionDelay = projectileDelay + Number(timeline.projectile.durationMs);
  const explosionDuration = appendVerticalSourceStrip(stage, timeline.explosion.slot, capturedBox, {
    frameCount: timeline.explosion.frameCount,
    frameDurationMs: timeline.explosion.frameDurationMs,
  }, explosionDelay, "classic-checkers-capture-explosion");
  return explosionDelay + explosionDuration - delayMs;
}

function appendCheckersPromotion(stage, motion, targetBox, delayMs) {
  const piece = String(motion.before.board?.[motion.move.from?.[0]]?.[motion.move.from?.[1]] || "");
  const stripEntry = checkersPromotionStrip(piece, motion.move.to);
  if (!stripEntry) return 0;
  const [slot, definition] = stripEntry;
  return appendVerticalSourceStrip(stage, slot, targetBox, {
    frameCount: definition.frameCount,
    frameDurationMs: classicSourceMap("checkers").motion.promotion.frameDurationMs,
  }, delayMs, "classic-checkers-promotion-morph");
}

function appendSquareMoveMotion(stage, gameId, motion, motionType, delayMs = 0) {
  if (!motion.move) return;
  // The original OCX casts by updating both squares directly; no king-only tween.
  if (gameId === "chess" && chessCastlingRook(motion.before, motion.move, motion.after)) return;
  const source = classicSourceMap(gameId);
  const from = checkerCellBox(gameId, Number(motion.move.from?.[0]), Number(motion.move.from?.[1]));
  const to = checkerCellBox(gameId, Number(motion.move.to?.[0]), Number(motion.move.to?.[1]));
  const piece = String(motion.before.board?.[motion.move.from?.[0]]?.[motion.move.from?.[1]] || "");
  if (!piece) return;
  const selector = `[data-row="${motion.move.to?.[0]}"][data-column="${motion.move.to?.[1]}"] .classic-piece, [data-row="${motion.move.to?.[0]}"][data-column="${motion.move.to?.[1]}"] .classic-chess-piece`;
  const destination = stage.querySelector(selector);
  const slideDuration = gameId === "checkers" ? source.motion.checkerSlide.durationMs : source.motion.pieceSlide.durationMs;
  if (gameId === "checkers") {
    const fromLevel = source.pieceSizeByRow[checkersSourceCoordinates(Number(motion.move.from?.[0]), Number(motion.move.from?.[1])).row];
    const toLevel = source.pieceSizeByRow[checkersSourceCoordinates(Number(motion.move.to?.[0]), Number(motion.move.to?.[1])).row];
    const movingFrom = sourceAssetBox(from, source.pieceAssetDimensions[fromLevel]);
    const movingTo = sourceAssetBox(to, source.pieceAssetDimensions[toLevel]);
    appendMovingAsset(stage, checkersMovingSurfaceSlot(piece, fromLevel), movingFrom, movingTo,
      slideDuration, source.motion.checkerSlide.easing, "is-checker-slide is-native-bitmap-surface", delayMs);
    let effectEnd = Number(slideDuration);
    if (motionType === "checkers-capture" || motion.move.captured) {
      const victim = checkersCaptureVictim(motion);
      if (victim) {
        const capturedCell = checkerCellBox("checkers", victim.row, victim.column);
        const capturedLevel = source.pieceSizeByRow[checkersSourceCoordinates(victim.row, victim.column).row];
        const capturedColor = victim.piece.toLowerCase() === "a" ? "b" : "w";
        const capturedBox = sourceAssetBox(capturedCell, source.pieceAssetDimensions[capturedLevel]);
        const predecessor = mediaImage(
          `gif-${victim.piece === victim.piece.toUpperCase() ? `k-${capturedColor}` : capturedColor}-${capturedLevel}`,
          "classic-checkers-captured-predecessor",
          "",
        );
        predecessor.dataset.capturedPiece = victim.piece;
        predecessor.dataset.captureSquare = `${victim.row}:${victim.column}`;
        setSourceBox(predecessor, capturedBox, source.canvas.width, source.canvas.height);
        stage.append(predecessor);
        const captureEnd = appendCheckersCaptureEffect(stage, motion, movingFrom, capturedBox, delayMs);
        effectEnd = Math.max(effectEnd, captureEnd);
        hideAfterMotion(predecessor, delayMs + captureEnd);
      }
    }
    if (motion.move.promoted) {
      const promotionDuration = appendCheckersPromotion(stage, motion, movingTo, delayMs + effectEnd);
      effectEnd += promotionDuration;
    }
    revealAfterMotion(destination, delayMs + effectEnd);
  } else {
    const classicPiece = { K:"k", Q:"q", R:"r", B:"b", N:"kn", P:"p" };
    const fromSize = source.pieceSizeByRow[squareVisualCoordinates("chess",Number(motion.move.from?.[0]),Number(motion.move.from?.[1])).row];
    const toSize = source.pieceSizeByRow[squareVisualCoordinates("chess",Number(motion.move.to?.[0]),Number(motion.move.to?.[1])).row];
    const movingSlot = `gif-${classicPiece[piece[1]]}-${piece[0]}-h-${fromSize}`;
    const destinationSlot = `gif-${classicPiece[piece[1]]}-${piece[0]}-h-${toSize}`;
    const captureDelay = motionType === "chess-capture" ? appendChessCaptureFall(stage, motion, delayMs) : 0;
    revealAfterMotion(destination, delayMs + captureDelay + slideDuration);
    appendChessMovingAsset(
      stage,
      movingSlot,
      destinationSlot,
      from,
      to,
      chessSelectedPieceNaturalSize(source, movingSlot, fromSize),
      chessSelectedPieceNaturalSize(source, destinationSlot, toSize),
      slideDuration,
      source.motion.pieceSlide.easing,
      delayMs + captureDelay,
    );
  }
}

function pointFinalChecker(stage, location, actorUserId) {
  const owner = String(actorUserId);
  if (location === "bar") return stage.querySelector(`.classic-bar-zone .classic-checker-pile[data-owner-user-id="${owner}"] .classic-checker:last-child`);
  if (location === "borne-off") return stage.querySelector(`.classic-borne-zone .classic-checker-pile[data-owner-user-id="${owner}"] .classic-checker:last-child`);
  const point = typeof location === "string" && location.startsWith("point:") ? Number(location.slice(6)) : Number(location);
  return stage.querySelector(`[data-point-key="point:${point}"] .classic-checker-pile[data-owner-user-id="${owner}"] .classic-checker:last-child`);
}

function appendNativePointStrip(stage, gameId, id, width, height, count, from, to, delayMs) {
  const source = classicSourceMap(gameId);
  const duration = count * 80;
  const layer = make("div", "classic-source-motion-asset point-native-strip");
  layer.style.overflow = "hidden";
  layer.dataset.nativeSlot = `bitmap-${id}`;
  layer.dataset.nativeFrames = String(count);
  layer.dataset.motionDelayMs = String(delayMs);
  layer.dataset.motionDurationMs = String(duration);
  const artwork = mediaImage(`bitmap-${id}`, "point-native-strip-art", "");
  Object.assign(artwork.style, { position:"absolute", left:"0", width:"100%", maxWidth:"none",
    height:`${pointNativeDimensions[`bitmap-${id}`][1] / height * 100}%` });
  layer.append(artwork);
  const start = {x:Math.round(from.x), y:Math.round(from.y)};
  const end = {x:Math.round(to.x), y:Math.round(to.y)};
  const dx = Math.trunc((end.x-start.x)/(count-1));
  const dy = Math.trunc((end.y-start.y)/(count-1));
  const frames = Array.from({length:count+1},(_,i) => {
    const tick = Math.min(i,count-1);
    return {left:`${(start.x+tick*dx)/source.canvas.width*100}%`,
      top:`${(start.y+tick*dy)/source.canvas.height*100}%`,offset:i/count,easing:"steps(1, end)"};
  });
  setSourceBox(layer,{...start,width,height},source.canvas.width,source.canvas.height);
  stage.append(layer);
  // Each callback phase has one visible owner, even after a state-preserving redraw.
  layer.style.opacity = "0";
  requestAnimationFrame(() => {
    const timing={duration,delay:delayMs,easing:"linear",fill:"both"};
    const elapsed=Math.max(0,performance.now()-Number(pendingClassicMotion?.startedAt || performance.now()));
    const moving=layer.animate(frames,timing);
    const changing=artwork.animate(frames.map((f,i)=>({top:`${-Math.min(i,count-1)*100}%`,offset:f.offset,easing:"steps(1, end)"})),timing);
    moving.currentTime=elapsed; changing.currentTime=elapsed;
    const visibility=layer.animate([{opacity:1,offset:0},{opacity:1,offset:.99999},{opacity:0,offset:1}],
      {...timing,fill:"forwards"});
    visibility.currentTime=elapsed;
  });
  return duration;
}

function appendNativePointMove(stage, gameId, motion, type, delayMs) {
  const actor=Number(motion.move.actorUserId);
  const index=motion.after.turnOrder.map(Number).indexOf(actor);
  const source=classicSourceMap(gameId);
  const from=sourceStackCheckerBox(gameId,motion.move.from,actor,motion.before);
  const to=sourceStackCheckerBox(gameId,motion.move.to,actor,motion.after);
  const shifted=(box,x,y)=>({x:box.x-x,y:box.y-y});
  let elapsed=delayMs;
  if(type==="point-hit") {
    const victimIndex=1-index;
    const victim=Number(motion.after.turnOrder[victimIndex]);
    const victimFrom=sourceStackCheckerBox(gameId,motion.move.to,victim,motion.before);
    const victimTo=sourceStackCheckerBox(gameId,"bar",victim,motion.after);
    elapsed+=appendNativePointStrip(stage,gameId,501+victimIndex,25,25,7,victimFrom,victimFrom,elapsed);
    const arrival=shifted(victimTo,3,6);
    elapsed+=appendNativePointStrip(stage,gameId,505+victimIndex,30,37,12,arrival,arrival,elapsed);
    revealAfterMotion(pointFinalChecker(stage,"bar",victim),elapsed);
  }
  // The moving player's checker remains at its origin during the capture phases.
  if(elapsed>delayMs) {
    const waiting=mediaImage(index===0?"gif-w":"gif-b","classic-source-motion-asset","");
    setSourceBox(waiting,from,source.canvas.width,source.canvas.height);stage.append(waiting);
    hideAfterMotion(waiting,elapsed);
  }
  if(type==="point-bear-off") {
    const profile=source.borneOffCheckers.nativeSizes[index];
    const rack={x:Math.round(to.x-(profile[0]-to.width)/2),y:Math.round(to.y-(profile[1]-to.height)/2)};
    elapsed+=appendNativePointStrip(stage,gameId,507+index,32,32,8,shifted(from,4,16),shifted(rack,4,16),elapsed);
    const landing=shifted(rack,4,23);
    elapsed+=appendNativePointStrip(stage,gameId,511+index,32,32,6,landing,landing,elapsed);
  } else {
    const origin=shifted(from,3,3),destination=shifted(to,3,3);
    elapsed+=appendNativePointStrip(stage,gameId,509+index,31,31,8,origin,destination,elapsed);
    elapsed+=appendNativePointStrip(stage,gameId,513+index,31,31,4,destination,destination,elapsed);
  }
  revealAfterMotion(pointFinalChecker(stage,motion.move.to,actor),elapsed);
}

function appendPointBearOffMotion(stage, gameId, slot, from, to, playerIndex, durationMs, delayMs) {
  const source = classicSourceMap(gameId);
  const profile = source.borneOffCheckers.nativeSizes[playerIndex];
  const turnSlot = playerIndex === 0 ? "bitmap-507" : "bitmap-508";
  const turnImage = pointBearOffMedia.get(mediaUrl(turnSlot));
  const sourceTurn = turnImage?.complete && turnImage.naturalWidth === 32
    && turnImage.naturalHeight === (playerIndex === 0 ? 256 : 285);
  const image = make("div", "classic-source-motion-asset is-point-slide point-bear-off is-rack-entry");
  let frames, artwork;
  if (sourceTurn) {
    // Both OCXs pass a 32px cell at point - (4,16), moving directly to
    // rack - (4,16). Each 80ms tick advances position AND turning artwork.
    // Native integer division leaves any remainder for the final board redraw.
    const count = Math.floor(turnImage.naturalHeight / 32);
    const start = { x: Math.round(from.x) - 4, y: Math.round(from.y) - 16 };
    const end = {
      x: Math.round(to.x - (profile[0] - to.width) / 2) - 4,
      y: Math.round(to.y - (profile[1] - to.height) / 2) - 16,
    };
    const stepX = Math.trunc((end.x - start.x) / (count - 1));
    const stepY = Math.trunc((end.y - start.y) / (count - 1));
    frames = Array.from({ length: count + 1 }, (_, tick) => {
      const frame = Math.min(tick, count - 1);
      return { x: start.x + frame * stepX, y: start.y + frame * stepY,
        width: 32, height: 32, offset: tick / count, easing: "steps(1, end)" };
    });
    const turn = make("div", "point-bearoff-turn");
    turn.dataset.frameCount = String(count);
    artwork = mediaImage(turnSlot, "point-bearoff-turn-image", "");
    artwork.style.height = `${turnImage.naturalHeight / 32 * 100}%`;
    turn.append(artwork); image.append(turn);
  } else {
    // Older complete media packs keep a direct slide and edge-on fallback.
    frames = [{ ...from, offset: 0 }, {
      x: to.x + (to.width - profile[0]) / 2, y: to.y + (to.height - profile[1]) / 2,
      width: profile[0], height: profile[1], offset: 1,
    }];
    image.append(mediaImage(slot, "point-bearoff-checker", ""));
  }
  image.dataset.sourceTurn = sourceTurn ? turnSlot : "fallback";
  image.dataset.motionSlot = slot;
  image.dataset.motionFrom = `${from.x},${from.y},${from.width},${from.height}`;
  image.dataset.motionTo = `${to.x},${to.y},${to.width},${to.height}`;
  image.dataset.motionDurationMs = String(durationMs);
  image.dataset.motionDelayMs = String(delayMs);
  setSourceBox(image, frames[0], source.canvas.width, source.canvas.height);
  stage.append(image);
  // The terminal sequence can outlive this move; hand the rack back to its
  // resting checker at the same instant that revealAfterMotion reveals it.
  hideAfterMotion(image, delayMs + durationMs);
  requestAnimationFrame(() => {
    const timing = { duration: durationMs, delay: delayMs, easing: "linear", fill: "both" };
    const animation = image.animate(frames.map(box => ({ offset: box.offset, easing: box.easing || "linear",
      left: `${box.x / source.canvas.width * 100}%`, top: `${box.y / source.canvas.height * 100}%`,
      width: `${box.width / source.canvas.width * 100}%`, height: `${box.height / source.canvas.height * 100}%`,
    })), timing);
    const elapsed = Math.min(durationMs + delayMs,
      Math.max(0, performance.now() - Number(pendingClassicMotion?.startedAt || performance.now())));
    animation.currentTime = elapsed;
    if (artwork) {
      const changing = artwork.animate(frames.map((box, tick) => ({
        top: `${-Math.min(tick, frames.length - 2) * 100}%`, offset: box.offset, easing: "steps(1, end)",
      })), timing);
      changing.currentTime = elapsed;
    }
  });
}

function appendPointMoveMotion(stage, gameId, motion, motionType, delayMs = 0) {
  if (!motion.move) return;
  if (motion.pointNative) { appendNativePointMove(stage, gameId, motion, motionType, delayMs); return; }
  const source = classicSourceMap(gameId);
  const actor = Number(motion.move.actorUserId || 0);
  const playerIndex = Math.max(0, (motion.after.turnOrder || []).map(Number).indexOf(actor));
  const slot = playerIndex === 0 ? "gif-w" : "gif-b";
  const from = sourceStackCheckerBox(gameId, motion.move.from, actor, motion.before);
  const to = sourceStackCheckerBox(gameId, motion.move.to, actor, motion.after);
  if (motionType === "point-hit" && source.motion.hitToBar.sequence === "captured-then-mover") {
    const hit = source.motion.hitToBar;
    const victimIndex = playerIndex === 0 ? 1 : 0;
    const victimUserId = Number(motion.after.turnOrder?.[victimIndex] || 0);
    const victimSlot = victimIndex === 0 ? "gif-w" : "gif-b";
    const victimFrom = sourceStackCheckerBox(gameId, motion.move.to, victimUserId, motion.before);
    const victimTo = sourceStackCheckerBox(gameId, "bar", victimUserId, motion.after);
    const capturedEnd = delayMs + Number(hit.capturedDurationMs || 0);
    const moverDelay = delayMs + Number(hit.moverDelayMs || 0);
    const moverEnd = moverDelay + Number(hit.moverDurationMs || 0);
    revealAfterMotion(pointFinalChecker(stage, "bar", victimUserId), capturedEnd);
    appendSegmentedPointHitAsset(
      stage,
      victimSlot,
      victimFrom,
      victimTo,
      Number(hit.capturedDurationMs || 0),
      source.motion.checkerSlide.easing,
      Number(hit.capturedEraseSections || 5),
      delayMs,
    );
    revealAfterMotion(pointFinalChecker(stage, motion.move.to, actor), moverEnd);
    appendMovingAsset(
      stage,
      slot,
      from,
      to,
      Number(hit.moverDurationMs || 0),
      source.motion.checkerSlide.easing,
      `is-point-slide ${motionType}`,
      moverDelay,
    );
    return;
  }
  const duration = motionType === "point-bear-off"
    ? source.motion.bearOff.durationMs
    : motionType === "point-hit"
      ? source.motion.hitToBar.moverDurationMs
      : source.motion.checkerSlide.durationMs;
  revealAfterMotion(pointFinalChecker(stage, motion.move.to, actor), delayMs + duration);
  if (motionType === "point-bear-off") {
    appendPointBearOffMotion(stage, gameId, slot, from, to, playerIndex, duration, delayMs);
  } else {
    appendMovingAsset(stage, slot, from, to, duration, source.motion.checkerSlide.easing, `is-point-slide ${motionType}`, delayMs);
  }
  if (motionType !== "point-hit") return;
  const victimIndex = playerIndex === 0 ? 1 : 0;
  const victimUserId = Number(motion.after.turnOrder?.[victimIndex] || 0);
  const victimSlot = victimIndex === 0 ? "gif-w" : "gif-b";
  const victimFrom = sourceStackCheckerBox(gameId, motion.move.to, victimUserId, motion.before);
  const victimTo = sourceStackCheckerBox(gameId, "bar", victimUserId, motion.after);
  const victimEnd = delayMs + source.motion.hitToBar.capturedDelayMs + source.motion.hitToBar.capturedDurationMs;
  revealAfterMotion(pointFinalChecker(stage, "bar", victimUserId), victimEnd);
  appendMovingAsset(stage, victimSlot, victimFrom, victimTo,
    source.motion.hitToBar.capturedDurationMs, source.motion.checkerSlide.easing, "is-hit-to-bar", delayMs + source.motion.hitToBar.capturedDelayMs);
}

// Original OCX callback order; these offsets also own sound and terminal handoff.
function battleshipAttackTimeline(motion) {
  const source = classicSourceMap("battleship").motion;
  const attack = motion?.attack || {};
  const result = String(attack.result || "miss");
  const cell = String(attack.cell || `${attack.row}:${attack.column}`);
  const fleet = motion?.after?.fleets?.[String(attack.targetUserId)] || {};
  const ship = (fleet.ships || []).find(item => (item.cells || []).includes(cell));
  const wreck = battleshipWreckDefinition(ship);
  const impactMs = source.shot.projectile.frameCount * source.shot.frameDurationMs;
  const aftermathMs = impactMs + (result === "miss" ? source.impacts.miss : source.impacts.explosion).frameCount * source.shot.frameDurationMs;
  const settleMs = result === "miss"
    ? aftermathMs + source.impacts.marker.initialDelayMs + source.impacts.marker.frameCount * source.impacts.marker.frameDurationMs
    : result === "sunk" ? aftermathMs + (wreck?.frameCount || 35) * source.shot.frameDurationMs
    : aftermathMs;
  return { result, ship, wreck, impactMs, aftermathMs, settleMs };
}

function battleshipNativeAnchor(gridName, row, column) {
  // Original integer coordinate functions; keep click hitboxes separate.
  return { x:(gridName === "own" ? 12 : 227) + column * 18,
    y:(gridName === "own" ? 16 : 123) + row * 18 };
}

function appendBattleshipAttackMotion(stage, motion, motionType, elapsed = 0) {
  if (!motion.attack) return;
  const source = classicSourceMap("battleship");
  const row = Number(motion.attack.row ?? String(motion.attack.cell || "0:0").split(":")[0]);
  const column = Number(motion.attack.column ?? String(motion.attack.cell || "0:0").split(":")[1]);
  const actorIsViewer = Number(motion.attack.actorUserId || 0) === currentUserId();
  const gridName = actorIsViewer ? "target" : "own";
  const anchor = battleshipNativeAnchor(gridName, row, column);
  const timeline = battleshipAttackTimeline(motion);
  const { result, impactMs, aftermathMs, settleMs } = timeline;
  const shot = source.motion.shot;
  const frameDurationMs = Number(shot.frameDurationMs);
  const appendNative = (definition, targetBox, delayMs, className) => {
    const host = sourceStripNode("battleship", definition.slot, targetBox, {
      frameCount:definition.frameCount, frameDurationMs:definition.frameDurationMs || frameDurationMs,
    }, delayMs, className, elapsed);
    host.dataset.sourceCell = `${gridName}:${row}:${column}`;
    stage.append(host);
    return host;
  };
  const launch = shot.launchByPerspective[actorIsViewer ? "viewer" : "opponent"];
  const launchHost = appendNative(launch, launch.region, 0, "classic-battleship-native-launch");
  launchHost.dataset.motionKind = "battleship-native-launch";
  const projectile = appendNative(shot.projectile, launch.muzzle, 0, "classic-battleship-projectile");
  projectile.dataset.motionKind = "battleship-native-projectile";
  // Native movement advances once per frame using truncated integer increments.
  const count = shot.projectile.frameCount;
  const dx = Math.trunc((anchor.x - launch.muzzle.x) / (count - 1));
  const dy = Math.trunc((anchor.y - launch.muzzle.y) / (count - 1));
  const frames = Array.from({length:count}, (_, index) => ({
    left:`${(launch.muzzle.x + index * dx) / source.canvas.width * 100}%`,
    top:`${(launch.muzzle.y + index * dy) / source.canvas.height * 100}%`,
    offset:index / count, easing:"steps(1, end)",
  }));
  frames.push({...frames[count - 1], offset:1});
  projectile.animate(frames, {duration:impactMs, delay:-elapsed, fill:"both"});

  const impact = result === "miss" ? source.motion.impacts.miss : source.motion.impacts.explosion;
  appendNative(impact, {...anchor, ...impact.nativeSize}, impactMs, `classic-battleship-native-impact is-${result}`);
  const persisted = stage.querySelector(`.classic-battle-grid.is-${gridName} .classic-battleship-persisted-result[data-source-cell="${gridName}:${row}:${column}"]`);
  if (result === "miss") {
    const marker = source.motion.impacts.marker;
    appendNative(marker, {x:anchor.x + 4, y:anchor.y + 4, ...marker.nativeSize},
      aftermathMs + marker.initialDelayMs, "classic-battleship-native-marker");
    revealAfterMotion(persisted, settleMs);
  } else if (result === "hit") {
    revealAfterMotion(persisted, aftermathMs);
  }
  if (result !== "sunk" || !timeline.ship || !timeline.wreck) return;
  const wreckBox = battleshipNativeWreckBox(timeline.ship, gridName);
  appendNative(timeline.wreck, wreckBox, aftermathMs, "classic-battleship-native-wreck-sequence");
  const persistedWreck = stage.querySelector(`.classic-native-wreck[data-ship-cells~="${CSS.escape(`${row}:${column}`)}"]`);
  revealAfterMotion(persistedWreck, settleMs);
}

function battleshipNativeWreckBox(ship, gridName) {
  const cells = (ship?.cells || []).map(cell => String(cell).split(":").map(Number));
  const definition = battleshipWreckDefinition(ship);
  if (!cells.length || !definition) return null;
  const anchor = battleshipNativeAnchor(gridName, Math.min(...cells.map(c => c[0])), Math.min(...cells.map(c => c[1])));
  const horizontal = cells.length > 1 && new Set(cells.map(c => c[0])).size === 1;
  return {...anchor, y:anchor.y - (horizontal ? definition.nativeSize.height - 18 : 0), ...definition.nativeSize};
}

function battleshipWreckDefinition(ship) {
  const cells = (ship?.cells || []).map(cell => String(cell).split(":").map(Number))
    .filter(cell => cell.length === 2 && cell.every(Number.isInteger));
  if (!cells.length) return null;
  const length = cells.length;
  if (length === 1) return classicSourceMap("battleship").motion.wrecks["1"];
  const horizontal = new Set(cells.map(cell => cell[0])).size === 1;
  return classicSourceMap("battleship").motion.wrecks[`${horizontal ? "horizontal" : "vertical"}-${length}`] || null;
}

function battleshipShipSourceBox(ship, gridName = "own") {
  const source = classicSourceMap("battleship");
  const edges = source.gridEdges[gridName];
  const cells = (ship?.cells || []).map(cell => String(cell).split(":").map(Number))
    .filter(cell => cell.length === 2 && cell.every(Number.isInteger));
  if (!cells.length) return null;
  const rows = cells.map(cell => cell[0]);
  const columns = cells.map(cell => cell[1]);
  const minRow = Math.min(...rows), maxRow = Math.max(...rows);
  const minColumn = Math.min(...columns), maxColumn = Math.max(...columns);
  return {
    x:edges.x[minColumn], y:edges.y[minRow],
    width:edges.x[maxColumn + 1] - edges.x[minColumn],
    height:edges.y[maxRow + 1] - edges.y[minRow],
  };
}

function appendBattleshipAutoPlacementMotion(stage, motion, elapsed = 0) {
  const source = classicSourceMap("battleship");
  const viewer = String(currentUserId());
  const beforeShips = battleshipVisibleFleet(motion.before, Number(viewer));
  const afterShips = battleshipVisibleFleet(motion.after, Number(viewer));
  stage.dataset.motionKind = "battleship-auto-placement";
  stage.dataset.motionMode = "source-near-instant-complete-fleet-jump";
  stage.dataset.motionDurationMs = "0";
  stage.dataset.sourceMotionWindowMs = String(source.motion.autoPlacement.durationMs);
  stage.dataset.sourceElapsedMs = String(Math.max(0, elapsed));
  stage.dataset.completeFleetBefore = String(beforeShips.length === 5);
  stage.dataset.completeFleetAfter = String(afterShips.length === 5);
  stage.dataset.sourceCellsBefore = beforeShips.map(ship => (ship.cells || []).join(",")).join("|");
  stage.dataset.sourceCellsAfter = afterShips.map(ship => (ship.cells || []).join(",")).join("|");
}

function appendCheckersDecisiveTerminal(stage, motion, delayMs = 0) {
  const source = classicSourceMap("checkers");
  const timeline = source.motion.decisiveTerminal;
  const host = make("div", "classic-checkers-terminal-tiles");
  host.dataset.motionKind = "decisive-terminal-colored-tiles";
  host.dataset.motionDurationMs = String(timeline.durationMs);
  host.dataset.sourceReferenceSha256 = "DFC56DF0327FCF7106B919524BE09703066D396F2562D186905A27A3D75355A5";
  host.dataset.sourceFrameRange = `${timeline.sourceStartFrame}-${timeline.sourceLastFrame}`;
  host.dataset.sourceSettledFrame = String(timeline.sourceSettledFrame);
  host.dataset.winnerSide = String(motion.winnerSide || "");
  const elapsed = Math.max(0, performance.now() - Number(motion.startedAt || performance.now()));
  let elapsedFrames = 0;
  timeline.phases.forEach((phase, index) => {
    const projected = checkersSourceCoordinates(Number(phase.row), Number(phase.column));
    const suffix = `${projected.row}${projected.column}`;
    const cell = checkerCellBox("checkers", Number(phase.row), Number(phase.column));
    const dimensions = timeline.assetDimensions[suffix];
    const tile = mediaImage(`gif-disco${suffix}`, "classic-checkers-terminal-tile", "");
    const durationMs = Number(phase.frames) * 1000 / Number(timeline.sourceFps);
    const phaseDelayMs = delayMs + elapsedFrames * 1000 / Number(timeline.sourceFps) - elapsed;
    setSourceBox(tile, {
      x: cell.x + (cell.width - Number(dimensions.width)) / 2,
      y: cell.y,
      width: Number(dimensions.width),
      height: Number(dimensions.height),
    }, source.canvas.width, source.canvas.height);
    tile.style.setProperty("--source-frame-delay", `${phaseDelayMs}ms`);
    tile.style.setProperty("--source-frame-duration", `${durationMs}ms`);
    tile.dataset.motionSlot = `gif-disco${suffix}`;
    tile.dataset.phaseIndex = String(index);
    tile.dataset.sourceFrameStart = String(timeline.sourceStartFrame + elapsedFrames);
    tile.dataset.sourceFrameCount = String(phase.frames);
    tile.dataset.logicalCell = `${phase.row}:${phase.column}`;
    tile.dataset.sourceCell = `${projected.row}:${projected.column}`;
    host.append(tile);
    elapsedFrames += Number(phase.frames);
  });
  host.dataset.sourceFrameCount = String(elapsedFrames);
  stage.prepend(host);
}

function spadesHandCardBox(cardIndex, cardCount) {
  const source = classicSourceMap("spades");
  const width = Number(source.handCard.width);
  const height = Number(source.handCard.height);
  const compactStep = Number(source.handCard.stepX);
  return {
    x: Number(source.hand.x) + Math.max(0, Number(cardIndex || 0)) * compactStep,
    y: Number(source.handCard.restY),
    width,
    height,
  };
}

function appendSpadesCardPlayMotion(stage, motion) {
  const source = classicSourceMap("spades");
  const duration = source.motion.cardPlay.durationMs;
  const destination = stage.querySelector(`.classic-card-wrap[data-card="${CSS.escape(String(motion.card || ""))}"][data-user-id="${Number(motion.actorUserId || 0)}"]`);
  // The authoritative state already contains the played card at its trick
  // destination while the source-authentic transition is running. Hide that
  // settled node synchronously so the moving card is the only visible copy;
  // the normal settlement render recreates the destination after 520 ms.
  if (destination) {
    destination.style.visibility = "hidden";
    destination.setAttribute("aria-hidden", "true");
    destination.dataset.revealAfterCardPlaySettle = "true";
  }
  const moving = appendMovingAsset(
    stage,
    classicCardSlot(motion.card),
    spadesHandCardBox(motion.fromIndex, motion.fromCount),
    source.trickCards[motion.destinationPosition || "bottom"],
    duration,
    source.motion.cardPlay.easing,
    "is-spades-card-play",
  );
  moving.dataset.playedCard = String(motion.card || "");
  moving.dataset.actorUserId = String(Number(motion.actorUserId || 0));
  moving.dataset.compactionAfterSettle = "true";
}

function appendPointWinMotion(stage, gameId, motion, elapsed) {
  const source = classicSourceMap(gameId);
  const definition = source.motion.win;
  if (motion.move) appendPointMoveMotion(stage, gameId, motion, "point-bear-off");

  const aceyNative = gameId === "acey-deucy" ? aceyNativeWin(motion) : null;
  if (aceyNative) {
    const host = make("div", "classic-point-win is-acey-deucy is-native-strips");
    host.dataset.motionKind = "acey-native-terminal-strips";
    host.dataset.sourceFrameCount = "70";
    host.dataset.sourceDurationMs = String(aceyNative.durationMs);
    host.dataset.sourceStripSlots = aceyNative.slots.join(",");
    setSourceBox(host,{x:0,y:0,width:source.canvas.width,height:source.canvas.height},source.canvas.width,source.canvas.height);
    let offset = 0;
    aceyNative.frames.forEach((frame,phase) => {
      const [x,y] = aceyNative.positions[phase];
      const strip = sourceStripNode(gameId,aceyNative.slots[phase],{x,y,width:frame.width,height:frame.height},
        {frameCount:frame.count,frameDurationMs:aceyNative.frameDurationMs},Number(motion.leadDurationMs||0)+offset,
        "classic-acey-native-victory-phase",elapsed);
      strip.dataset.phaseIndex = String(phase);
      host.append(strip);
      offset += frame.count * aceyNative.frameDurationMs;
    });
    stage.append(host);
    return;
  }

  if (gameId === "backgammon-first-party") {
    const winnerIndex = Math.max(0, (motion.after?.turnOrder || []).map(Number)
      .indexOf(Number(motion.after?.winnerUserId || 0)));
    const color = winnerIndex === 0 ? "white" : "black";
    const strips = definition.strips[color];
    const host = make("div", "classic-point-win is-backgammon-first-party is-native-strips");
    host.dataset.motionKind = "backgammon-native-terminal-strips";
    host.dataset.sourceRole = source.sourceRole;
    host.dataset.sourceDirection = definition.direction;
    host.dataset.sourceFrameDurationMs = String(definition.frameDurationMs);
    host.dataset.sourceStripSlots = strips.join(",");
    host.dataset.syntheticCheckerTrail = "false";
    setSourceBox(host, { x: 0, y: 0, width: source.canvas.width, height: source.canvas.height }, source.canvas.width, source.canvas.height);
    let accumulatedFrames = 0;
    definition.phases.forEach((phase, phaseIndex) => {
      const slot = strips[phaseIndex];
      for (let frameIndex = 0; frameIndex < Number(phase.frameCount); frameIndex++) {
        const frame = make("span", "point-win-native-frame");
        setSourceBox(frame, phase.box, source.canvas.width, source.canvas.height);
        frame.style.backgroundImage = `url("${mediaUrl(slot)}")`;
        frame.style.backgroundSize = `100% ${Number(phase.frameCount) * 100}%`;
        frame.style.backgroundPosition = `0 ${Number(phase.frameCount) <= 1 ? 0 : frameIndex / (Number(phase.frameCount) - 1) * 100}%`;
        frame.style.setProperty("--source-frame-delay", `${Number(motion.leadDurationMs || 0) + (accumulatedFrames + frameIndex) * Number(definition.frameDurationMs) - elapsed}ms`);
        frame.style.setProperty("--source-frame-duration", `${Number(definition.frameDurationMs)}ms`);
        frame.dataset.stripSlot = slot;
        frame.dataset.phaseIndex = String(phaseIndex);
        frame.dataset.frameIndex = String(frameIndex);
        frame.dataset.sourceFrameBox = `${phase.frameWidth}x${phase.frameHeight}`;
        host.append(frame);
      }
      accumulatedFrames += Number(phase.frameCount);
    });
    host.dataset.sourceFrameCount = String(accumulatedFrames);
    host.dataset.sourceDurationMs = String(accumulatedFrames * Number(definition.frameDurationMs));
    stage.append(host);
    return;
  }

  const winnerIndex = Math.max(0, (motion.after?.turnOrder || []).map(Number)
    .indexOf(Number(motion.after?.winnerUserId || 0)));
  const checkerSlot = winnerIndex === 0 ? "gif-w" : "gif-b";
  const trailSlot = winnerIndex === 0 ? "gif-w-s" : "gif-b-s";
  const host = make("div", `classic-point-win is-${gameId}`);
  host.dataset.motionKind = `${gameId}-win`;
  host.dataset.motionPhases = definition.phases.join(",");
  host.dataset.sourceCheckerPath = definition.checkerPath
    .map(point => `${point.phase}:${point.x},${point.y}`).join(";");
  host.dataset.sourceTrailAsset = trailSlot;
  host.dataset.sourceCheckerAsset = checkerSlot;
  setSourceBox(host, { x: 0, y: 0, width: source.canvas.width, height: source.canvas.height }, source.canvas.width, source.canvas.height);

  const checker = mediaImage(checkerSlot, "point-win-source-checker", "");
  const trail = mediaImage(trailSlot, "point-win-source-trail", "");
  const first = definition.checkerPath[0];
  setSourceBox(checker, {
    x: first.x, y: first.y,
    width: definition.checkerSize, height: definition.checkerSize,
  }, source.canvas.width, source.canvas.height);
  setSourceBox(trail, {
    x: first.x + definition.trailOffset.x,
    y: first.y + definition.trailOffset.y,
    width: definition.trailSize.width,
    height: definition.trailSize.height,
  }, source.canvas.width, source.canvas.height);

  const checkerFrames = definition.checkerPath.map(point => ({
    offset: point.phase,
    left: `${point.x / source.canvas.width * 100}%`,
    top: `${point.y / source.canvas.height * 100}%`,
    opacity: point.phase === 0 ? 0 : 1,
    transform: "scale(1)",
  }));
  const finalPoint = definition.checkerPath.at(-1);
  checkerFrames.push(
    { offset: .88, left: `${finalPoint.x / source.canvas.width * 100}%`, top: `${finalPoint.y / source.canvas.height * 100}%`, opacity: 1, transform: "scale(1.08)" },
    { offset: .94, left: `${finalPoint.x / source.canvas.width * 100}%`, top: `${finalPoint.y / source.canvas.height * 100}%`, opacity: .55, transform: "scale(.55)" },
    { offset: 1, left: `${finalPoint.x / source.canvas.width * 100}%`, top: `${finalPoint.y / source.canvas.height * 100}%`, opacity: 0, transform: "scale(.15)" },
  );
  const trailFrames = definition.checkerPath.map(point => ({
    offset: point.phase,
    left: `${(point.x + definition.trailOffset.x) / source.canvas.width * 100}%`,
    top: `${(point.y + definition.trailOffset.y) / source.canvas.height * 100}%`,
    opacity: point.phase <= .08 ? 0 : .9,
    transform: `scaleX(${point.phase < .55 ? 1 : 1.35})`,
  }));
  trailFrames.push(
    { offset: .88, left: `${(finalPoint.x + definition.trailOffset.x) / source.canvas.width * 100}%`, top: `${(finalPoint.y + definition.trailOffset.y) / source.canvas.height * 100}%`, opacity: .85, transform: "scaleX(1.5)" },
    { offset: .94, left: `${(finalPoint.x + definition.trailOffset.x) / source.canvas.width * 100}%`, top: `${(finalPoint.y + definition.trailOffset.y) / source.canvas.height * 100}%`, opacity: .4, transform: "scaleX(.75)" },
    { offset: 1, left: `${(finalPoint.x + definition.trailOffset.x) / source.canvas.width * 100}%`, top: `${(finalPoint.y + definition.trailOffset.y) / source.canvas.height * 100}%`, opacity: 0, transform: "scaleX(.2)" },
  );
  const timing = {
    duration: definition.durationMs,
    delay: Number(motion.leadDurationMs || 0) - elapsed,
    easing: definition.easing,
    fill: "both",
  };
  checker.animate(checkerFrames, timing);
  trail.animate(trailFrames, timing);
  host.append(trail, checker);
  stage.append(host);
}

function appendPointNoLegalMotion(stage, gameId, event, elapsed, delayMs = 0) {
  const source = classicSourceMap(gameId);
  const kind = String(event?.kind || "whole-turn");
  const timer = kind === "partial-turn"
    ? source.motion.blockedReentry.partialTurn
    : source.motion.blockedReentry.wholeTurn;
  const host = make("div", "classic-point-no-legal-motion");
  host.dataset.motionKind = `${kind}-no-legal-move`;
  host.dataset.sourceTickMs = String(Number(timer.tickMs));
  host.dataset.sourceTickCount = String(Number(timer.ticks));
  host.dataset.motionDurationMs = String(Number(timer.durationMs));
  host.dataset.motionDelayMs = String(Number(delayMs));
  host.dataset.boardStateUnchanged = String(event?.boardStateUnchanged !== false);
  host.setAttribute("role", "status");
  host.append(make("span", "sr-only", kind === "partial-turn"
    ? "No remaining legal move. The turn passes after the source delay."
    : "No legal move. The turn passes after the source delay."));
  // The roll layer or settled tray already owns the displayed pair. The
  // blocked-turn status must not add old, smaller GIF dice over those dice.
  // If the next-turn state cleared the tray, retain the verified roll here
  // using the same face renderer until the blocked-turn display finishes.
  if (kind === "whole-turn" && !stage.querySelector(".classic-die")) {
    (event?.dice || []).slice(0, 2).forEach((value, index) => {
      const die = pointGameClassicDie(Number(value));
      die.classList.add("classic-blocked-roll-die");
      die.dataset.motionKind = "no-legal-roll-die";
      die.dataset.dieValue = String(Number(value));
      setSourceBox(die, source.diceSlots[index], source.canvas.width, source.canvas.height);
      host.append(die);
    });
  }
  host.style.setProperty("--source-no-legal-delay", `${Number(delayMs) - Number(elapsed)}ms`);
  stage.append(host);
}

let nativeChessKingEpoch = null;
function appendNativeChessKing(stage, motion = null) {
  const state = motion?.after || session?.state;
  if (!state?.completed || !String(state.terminalReason || "").includes("checkmate")
      || !optionCategory("visualFxEnabled",true)) return false;
  const winnerColor = state.colorAssignments?.[String(state.winnerUserId)];
  if (!["w","b"].includes(winnerColor)) return false;
  const piece = `${winnerColor === "w" ? "b" : "w"}K`;
  let square = null;
  for (let row=0;row<8;row++) for(let column=0;column<8;column++) if(state.board?.[row]?.[column]===piece) square={row,column};
  if (!square) return false;
  const source = classicSourceMap("chess");
  const row = squareVisualCoordinates("chess",square.row,square.column).row;
  const level = source.pieceSizeByRow[row];
  const index = (piece[0] === "w" ? 4 : 0) + level - 1;
  const slot = `bitmap-${600+index}`;
  if (!optionalClassicStrip(slot)) return false;
  const frameHeight = source.motion.nativeCapture.frameSizes[level-1].height;
  const bitmapHeight = source.motion.nativeKing.bitmapHeights[index];
  // The original loader floors bitmap-height/frame-height; do not stretch
  // trailing partial rows into its displayed frames.
  const count = Math.floor(bitmapHeight/frameHeight);
  const start = source.motion.nativeKing.loopStarts[index];
  const tick = source.motion.nativeKing.frameDurationMs;
  const box = bottomCenteredSourceAssetBox(checkerCellBox("chess",square.row,square.column),{width:54,height:frameHeight});
  box.y -= source.motion.nativeCapture.rowYOffset[row];
  const key = `${context.gameSessionId}:${state.winnerUserId}:${square.row}:${square.column}`;
  if (nativeChessKingEpoch?.key !== key) nativeChessKingEpoch = { key,
    at:motion ? motion.startedAt + Number(motion.leadDurationMs||0) : performance.now()-count*tick };
  const elapsed = performance.now()-nativeChessKingEpoch.at;
  const ordinary = stage.querySelector(`[data-row="${square.row}"][data-column="${square.column}"] .classic-chess-piece`);
  if (ordinary) {
    if (elapsed >= 0) ordinary.style.opacity = "0";
    else ordinary.animate([{opacity:0},{opacity:0}],{duration:count*tick,delay:-elapsed,fill:"forwards"});
  }
  const intro = sourceStripNode("chess",slot,box,{frameCount:count,frameDurationMs:tick},0,"classic-chess-native-king-intro",elapsed);
  for (const image of intro.querySelectorAll("img")) image.style.height = `${bitmapHeight/frameHeight*100}%`;
  const loop = make("div","classic-source-strip-host classic-chess-native-king-loop");
  setSourceBox(loop,box,source.canvas.width,source.canvas.height);
  loop.dataset.motionSlot = slot;
  loop.dataset.loopStart = String(start);
  loop.dataset.frameCount = String(count);
  loop.dataset.kingSquare = `${square.row}:${square.column}`;
  loop.dataset.epoch = String(nativeChessKingEpoch.at);
  const image = mediaImage(slot,"classic-source-strip-image","");
  image.style.height = `${bitmapHeight/frameHeight*100}%`;
  const length = count-start;
  const frames = Array.from({length:length+1},(_,i)=>({top:`${-(start+i%length)*100}%`,offset:i/length,easing:"steps(1,end)"}));
  // Step each frame segment; a whole-animation steps(1) freezes the flag.
  image.animate(frames,{duration:length*tick,delay:count*tick-elapsed,iterations:Infinity,easing:"linear"});
  loop.style.opacity = "0";
  loop.animate([{opacity:1},{opacity:1}],{duration:length*tick,delay:count*tick-elapsed,fill:"forwards"});
  loop.append(image);stage.append(intro,loop);
  return true;
}

function classicTerminalSequence(gameId, settled = false, animationDelayMs = 0, terminalMotion = pendingClassicMotion, elapsed = 0) {
  const source = classicSourceMap(gameId);
  if (gameId === "chess") {
    const definition = source.motion.checkmateFlag;
    const host = make("div", "classic-source-strip-host classic-chess-checkmate-source-sequence");
    host.dataset.motionKind = "checkmate-flag-full-sequence";
    host.dataset.motionSlot = "independently-authored-source-traced-checkmate-strip";
    host.dataset.frameCount = String(definition.frameCount);
    host.dataset.frameDurationMs = String(definition.frameDurationMs);
    host.dataset.startDelayMs = String(definition.startDelayMs);
    host.dataset.sourceFrameWindow = `${definition.sourceFrames.start}-${definition.sourceFrames.end}@${definition.sourceFrames.fps}fps`;
    host.dataset.terminalReason = "checkmate";
    host.dataset.persistedFinal = "false";
    setSourceBox(host, definition.region, source.canvas.width, source.canvas.height);
    for (let index = 0; index < Number(definition.frameCount); index++) {
      const frame = make("div", "classic-source-strip-frame");
      frame.dataset.frameIndex = String(index);
      frame.style.setProperty("--source-frame-delay", `${Number(animationDelayMs) + Number(definition.startDelayMs) + index * Number(definition.frameDurationMs)}ms`);
      frame.style.setProperty("--source-frame-duration", `${Number(definition.frameDurationMs)}ms`);
      const image = document.createElement("img");
      image.className = "classic-source-strip-image";
      image.src = definition.stripAsset;
      image.alt = "";
      image.draggable = false;
      image.style.height = `${Number(definition.frameCount) * 100}%`;
      image.style.top = `${-index * 100}%`;
      frame.append(image);
      host.append(frame);
    }
    return host;
  }
  if (gameId === "battleship") {
    if (!optionCategory("visualFxEnabled", true)) return null;
    const attack = terminalMotion?.attack || latest(session.state?.attackHistory);
    if (!attack) return null;
    const row = Number(attack.row ?? String(attack.cell || "0:0").split(":")[0]);
    const column = Number(attack.column ?? String(attack.cell || "0:0").split(":")[1]);
    const gridName = Number(attack.actorUserId || 0) === currentUserId() ? "target" : "own";
    const definition = source.motion.victoryFlag;
    const flag = make("div", "classic-source-strip-host classic-battleship-native-terminal-flag");
    setSourceBox(flag, {...battleshipNativeAnchor(gridName, row, column), ...definition.nativeSize}, source.canvas.width, source.canvas.height);
    Object.assign(flag.dataset, {motionKind:"fleet-sunk-native-dib9-sequence", motionSlot:definition.slot,
      sourceCell:`${gridName}:${row}:${column}`, terminalReason:"fleet-sunk", persistedFinal:"true",
      frameCount:String(definition.frameCount), frameDurationMs:String(definition.frameDurationMs),
      loopStartFrame:String(definition.loopStartFrame)});
    const image = mediaImage(definition.slot, "classic-source-strip-image", "");
    image.style.height = `${definition.frameCount * 100}%`;
    image.style.top = "0";
    flag.append(image);
    const animate = optionCategory("visualFxEnabled", true);
    const keyframes = start => {
      const count = definition.frameCount - start;
      const frames = Array.from({length:count}, (_, index) => ({
        transform:`translateY(-${(start + index) / definition.frameCount * 100}%)`,
        offset:index / count, easing:"steps(1, end)",
      }));
      return [...frames, {...frames[count - 1], offset:1}];
    };
    if (!animate) image.style.top = `${-(definition.frameCount - 1) * 100}%`;
    else {
      const offset = settled ? definition.durationMs : Math.max(0, elapsed - animationDelayMs);
      const wait = settled ? 0 : Math.max(0, animationDelayMs - elapsed);
      if (!settled && offset < definition.durationMs) {
        const intro = image.animate(keyframes(0), {duration:definition.durationMs, delay:wait, fill:"both"});
        intro.currentTime = offset;
      }
      const loop = image.animate(keyframes(definition.loopStartFrame), {
        duration:(definition.frameCount - definition.loopStartFrame) * definition.frameDurationMs,
        delay:Math.max(0, definition.durationMs - offset) + wait, iterations:Infinity, fill:"forwards",
      });
      if (offset > definition.durationMs) loop.currentTime = offset - definition.durationMs;
      // Keep the ending invisible until the final wreck has settled.
      flag.animate([{visibility:"hidden"},{visibility:"visible"}], {duration:1, delay:wait, fill:"both"});
    }
    return flag;
  }

  return null;
}

function appendPersistedClassicTerminal(stage, gameId) {
  if (pendingClassicMotion?.gameId === gameId || !session.state?.completed) return;
  if (gameId === "chess" && appendNativeChessKing(stage)) return;
  const reason = String(session.state.terminalReason || "").toLowerCase();
  const source = classicSourceMap(gameId);
  const ownsPersistentFinal = gameId === "chess"
    ? reason.includes("checkmate") && source.motion.checkmateFlag.persistFinal === true
    : gameId === "battleship"
      ? reason === "fleet-sunk" && source.motion.victoryFlag.persistFinal === true
      : false;
  if (!ownsPersistentFinal) return;
  const flag = classicTerminalSequence(gameId, true);
  if (flag) stage.append(flag);
}

function appendClassicMotion(stage, gameId) {
  const motion = pendingClassicMotion;
  if (!motion || motion.gameId !== gameId || !optionCategory("visualFxEnabled", true)) return;
  const source = classicSourceMap(gameId);
  const elapsed = performance.now() - motion.startedAt;
  if (elapsed >= motionLength(gameId, motion.type)) return;
  stage.dataset.motionType = motion.type;
  stage.dataset.motionVersion = String(motion.version);

  if (["checkers-move", "checkers-capture", "chess-move", "chess-capture"].includes(motion.type)) {
    appendSquareMoveMotion(stage, gameId, motion, motion.type);
    return;
  }

  if (motion.type === "checkers-win") {
    if (motion.move) appendSquareMoveMotion(stage, "checkers", motion, motion.move.captured ? "checkers-capture" : "checkers-move");
    appendCheckersDecisiveTerminal(stage, motion, Number(motion.leadDurationMs || 0));
    return;
  }

  if (motion.type === "chess-checkmate") {
    if (motion.move) appendSquareMoveMotion(stage, "chess", motion, motion.move.capture ? "chess-capture" : "chess-move");
    if (!appendNativeChessKing(stage,motion)) stage.append(classicTerminalSequence("chess", false, Number(motion.leadDurationMs || 0) - elapsed));
    return;
  }

  if (["point-move", "point-hit", "point-bear-off"].includes(motion.type)) {
    appendPointMoveMotion(stage, gameId, motion, motion.type);
    if (motion.noLegalMove) {
      const delayMs = pointMoveDuration(gameId, motion.type, motion);
      appendPointNoLegalMotion(stage, gameId, motion.noLegalMove, elapsed, delayMs);
    }
    return;
  }

  if (motion.type === "backgammon-dice-roll") {
    const duration = 1467;
    if (elapsed < duration) {
      // Transfer the settled dice into the roll layer rather than painting a
      // second pair over hidden copies. Keep one owner for each die throughout
      // the roll/settle transition, including redraws during the animation.
      const tray = stage.querySelector(".classic-dice");
      const existingDice = tray ? Array.from(tray.children) : [];
      tray?.replaceChildren();
      const layer = make("div", "classic-point-dice-roll");
      layer.setAttribute("aria-hidden", "true");
      // Measured original OCX sequence: enter from the right, travel left,
      // rebound, then settle (owner recording 19-17-56, frames 348-392).
      const path = [[0, 399, 155, -180], [.25, 197, 173, -540], [.48, 246, 148, -850], [.7, 286, 157, -1040], [.87, 314, 150, -1120], [1, 303, 157, -1080]];
      for (const [index, value] of motion.dice.entries()) {
        const existing = existingDice[index];
        const die = Number(existing?.dataset.dieFace) === Number(value) ? existing : pointGameClassicDie(value);
        const destination = source.diceSlots[index];
        setSourceBox(die, destination, source.canvas.width, source.canvas.height);
        layer.append(die);
        const dx = destination.x - 303, dy = destination.y - 157;
        const frames = path.map(([offset, x, y, angle]) => ({ offset,
          left: `${(x + dx) / source.canvas.width * 100}%`,
          top: `${(y + dy) / source.canvas.height * 100}%`,
          transform: `rotate(${angle}deg)` }));
        const animation = die.animate(frames, { duration, easing: "linear", fill: "both" });
        animation.currentTime = elapsed;
      }
      stage.append(layer);
    }
    if (motion.noLegalMove) appendPointNoLegalMotion(stage, gameId, motion.noLegalMove, elapsed, duration);
    return;
  }
  if (motion.type === "point-no-legal-move") {
    appendPointNoLegalMotion(stage, gameId, motion.noLegalMove, elapsed);
    return;
  }

  if (["backgammon-win", "acey-deucy-win"].includes(motion.type)) {
    appendPointWinMotion(stage, gameId, motion, elapsed);
    return;
  }

  if (motion.type.startsWith("battleship-")) {
    if (motion.type === "battleship-auto-placement") {
      appendBattleshipAutoPlacementMotion(stage, motion, elapsed);
      return;
    }
    if (motion.type === "battleship-win") {
      if (motion.attack) appendBattleshipAttackMotion(stage, motion, motion.resultType || "battleship-sunk", elapsed);
      const terminal = classicTerminalSequence("battleship", false, Number(motion.leadDurationMs || 0), motion, elapsed);
      if (terminal) stage.append(terminal);
      return;
    }
    appendBattleshipAttackMotion(stage, motion, motion.type, elapsed);
    return;
  }

  if (motion.type === "spades-card-play") appendSpadesCardPlayMotion(stage, motion);
}

const BUILT_IN_MODERN_MOTION_MS = Object.freeze({
  "checkers-move": 760,
  "checkers-capture": 2100,
  "checkers-win": 3100,
  "chess-move": 720,
  "chess-capture": 2300,
  "chess-checkmate": 6450,
  "battleship-miss": 3600,
  "battleship-hit": 6400,
  "battleship-sunk": 10100,
  "battleship-win": 25200,
});

const BUILT_IN_MODERN_CHESS_GLYPHS = Object.freeze({
  K: "♔", Q: "♕", R: "♖", B: "♗", N: "♘", P: "♙",
  k: "♚", q: "♛", r: "♜", b: "♝", n: "♞", p: "♟",
});
const BUILT_IN_SCULPTED_CHESS_TYPES = Object.freeze({
  K: "king", Q: "queen", R: "rook", B: "bishop", N: "knight", P: "pawn",
});

let builtInModernEnhancementScheduled = false;
let builtInCheckersSquareNumbersEnabled = false;
let coreChatChessCoordinatesEnabled = false;
try {
  builtInCheckersSquareNumbersEnabled = gameViewStorage.getItem("corechat-checkers-square-numbers") === "true";
  coreChatChessCoordinatesEnabled = gameViewStorage.getItem("corechat-chess-board-coordinates") === "true";
} catch (_error) {
  builtInCheckersSquareNumbersEnabled = false;
  coreChatChessCoordinatesEnabled = false;
}
function builtInModernSquare(value, fallbackRow = null, fallbackColumn = null) {
  if (Array.isArray(value) && value.length >= 2) {
    return { row: Number(value[0]), column: Number(value[1]) };
  }
  if (value && typeof value === "object") {
    const row = Number(value.row ?? value.r ?? fallbackRow);
    const column = Number(value.column ?? value.col ?? value.c ?? fallbackColumn);
    if (Number.isFinite(row) && Number.isFinite(column)) {
      return { row, column };
    }
  }
  const row = Number(fallbackRow);
  const column = Number(fallbackColumn);
  return Number.isFinite(row) && Number.isFinite(column) ? { row, column } : null;
}

function builtInModernBoardState(snapshot) {
  return snapshot?.board || snapshot?.state?.board || [];
}

function builtInModernVisualSquare(gameId, logicalSquare) {
  if (!logicalSquare) {
    return null;
  }
  for (let visualRow = 0; visualRow < 8; visualRow += 1) {
    for (let visualColumn = 0; visualColumn < 8; visualColumn += 1) {
      const logical = squareLogicalCoordinates(gameId, visualRow, visualColumn);
      if (logical.row === logicalSquare.row && logical.column === logicalSquare.column) {
        return { row: visualRow, column: visualColumn };
      }
    }
  }
  return logicalSquare;
}

function builtInModernCell(board, square) {
  if (!square) {
    return null;
  }
  return board.querySelector(
    '.board-cell[data-logical-row="' + square.row + '"][data-logical-column="' + square.column + '"]',
  );
}

function builtInChessPieceIdentity(piece) {
  const raw = String(piece || "");
  if (/^[wb][KQRBNP]$/.test(raw)) {
    return { side: raw[0], type: raw[1] };
  }
  const type = raw.slice(-1).toUpperCase();
  return {
    side: raw === raw.toLowerCase() ? "b" : "w",
    type,
  };
}

function builtInChessPieceNode(piece, extraClass = "", styleMode = chessPieceStyleMode()) {
  const identity = builtInChessPieceIdentity(piece);
  const node = make("span", (`chess-piece chess-piece-side-${identity.side} ${extraClass}`).trim());
  node.dataset.pieceType = identity.type.toLowerCase();
  node.dataset.pieceSide = identity.side;
  if (styleMode === "sculpted" && BUILT_IN_SCULPTED_CHESS_TYPES[identity.type]) {
    node.classList.add("is-sculpted");
    const color = identity.side === "b" ? "navy" : "ivory";
    const image = make("img", "chess-piece-art");
    image.src = appUrl(`assets/images/chess-sculpted/${color}-${BUILT_IN_SCULPTED_CHESS_TYPES[identity.type]}.png`);
    image.alt = "";
    image.draggable = false;
    image.setAttribute("aria-hidden", "true");
    image.addEventListener("error", () => {
      node.classList.remove("is-sculpted");
      image.remove();
      node.textContent = BUILT_IN_MODERN_CHESS_GLYPHS[identity.side === "b"
        ? identity.type.toLowerCase()
        : identity.type] || String(piece);
    }, { once: true });
    node.append(image);
  } else {
    node.textContent = BUILT_IN_MODERN_CHESS_GLYPHS[identity.side === "b"
      ? identity.type.toLowerCase()
      : identity.type] || String(piece);
  }
  node.setAttribute("aria-hidden", "true");
  return node;
}

function builtInModernPiece(gameId, piece, extraClass = "") {
  if (!piece) {
    return null;
  }
  if (gameId === "checkers") {
    const kingClass = piece === String(piece).toUpperCase() ? " king" : "";
    const node = make("span", ("piece side-" + String(piece).toLowerCase() + kingClass + " " + extraClass).trim());
    node.setAttribute("aria-hidden", "true");
    return node;
  }
  return builtInChessPieceNode(piece, extraClass);
}

function builtInModernWinnerName(state) {
  const winnerId = state?.winnerUserId;
  if (!Number.isSafeInteger(winnerId) || (winnerId <= 0
    && !gameTerminalOutcome(session).winnerIds.includes(winnerId))) return "A player";
  const candidates = [
    ...(Array.isArray(state?.players) ? state.players : []),
    ...(Array.isArray(session?.members) ? session.members : []),
    ...(Array.isArray(session?.players) ? session.players : []),
    ...(Array.isArray(session?.participants) ? session.participants : []),
  ];
  const winner = candidates.find((candidate) => candidate?.userId != null
    && String(candidate.userId) === String(winnerId));
  return winner?.displayName || winner?.username || winner?.name || "A player";
}

function builtInModernAppendResult(board, gameId, motion) {
  const state = session?.state || {};
  const outcome = gameTerminalOutcome(session, gameId);
  if (!outcome.terminal) return;
  const won = outcome.known && !outcome.draw && outcome.winnerIds.length === 1;
  const checkmate = won && gameId === "chess" && outcome.reason === "checkmate";
  const terminalMotion = won && (checkmate && motion?.type === "chess-checkmate"
    || gameId === "checkers" && isCheckersDecisiveTerminalReason(outcome.reason)
      && motion?.type === "checkers-win");
  const result = make("section", "built-in-modern-result " + gameId + "-result");
  if (terminalMotion && motion?.startedAt) {
    const captureLead = gameId === "chess" && motion?.type === "chess-checkmate" && motion?.move?.capture
      ? Number(motion.leadDurationMs || 2550)
      : 0;
    const revealAt = gameId === "chess" ? captureLead + 3250 : 2200;
    const elapsed = Math.max(0, performance.now() - Number(motion.startedAt));
    result.classList.add("is-terminal-motion-result");
    result.style.setProperty("--result-delay", Math.max(0, revealAt - elapsed) + "ms");
  }
  result.setAttribute("role", "status");
  result.setAttribute("aria-live", "polite");
  result.append(make("span", "built-in-modern-result-kicker", outcome.known && outcome.draw
    ? "Draw" : won ? checkmate ? "Checkmate" : "Victory" : "Game over"));
  result.append(make("strong", "built-in-modern-result-title", outcome.known && outcome.draw
    ? "Game drawn" : won ? builtInModernWinnerName(state) + " wins" : gameMemberTerminalLabel(0, session, gameId)));
  result.append(make(
    "span",
    "built-in-modern-result-detail",
    checkmate ? "The opposing king is checkmated."
      : won && gameId === "checkers" && outcome.reason === "all-pieces-captured"
        ? "The final opposing checker has been cleared."
        : outcome.reason ? "Reason: " + outcome.reason.replaceAll("-", " ") + "."
          : won ? "The game is complete." : "No confirmed winner is available.",
  ));
  board.append(result);
}

function builtInModernAppendMotion(board, gameId) {
  const motion = pendingClassicMotion;
  const duration = BUILT_IN_MODERN_MOTION_MS[motion?.type];
  const elapsed = motion?.startedAt ? Math.max(0, performance.now() - Number(motion.startedAt)) : 0;
  if (!motion || !duration || elapsed >= duration || !optionCategory("visualFxEnabled", true)) {
    trace("classic-built-in-motion-skipped", null, {
      type: motion?.type || null,
      reason: !motion
        ? "missing-motion"
        : !duration
          ? "missing-duration"
          : elapsed >= duration
            ? "motion-expired"
            : "visual-fx-off",
      elapsedMs: Math.round(elapsed),
      durationMs: Number(duration || 0),
    });
    builtInModernAppendResult(board, gameId, motion);
    return;
  }

  const move = motion.move || {};
  board.style.setProperty("--motion-elapsed", elapsed + "ms");
  const from = builtInModernSquare(move.from, move.fromRow, move.fromColumn);
  const to = builtInModernSquare(move.to, move.toRow, move.toColumn);
  const sourceCell = builtInModernCell(board, from);
  const targetCell = builtInModernCell(board, to);
  const beforeBoard = builtInModernBoardState(motion.before);
  const afterBoard = builtInModernBoardState(motion.after);
  const movingPiece = beforeBoard?.[from?.row]?.[from?.column];
  if (!from || !to || !sourceCell || !targetCell || !movingPiece) {
    trace("classic-built-in-motion-skipped", null, {
      type: motion.type,
      reason: !from
        ? "missing-source-square"
        : !to
          ? "missing-target-square"
          : !sourceCell
            ? "missing-source-cell"
            : !targetCell
              ? "missing-target-cell"
              : "missing-moving-piece",
      from,
      to,
      hasBeforeBoard: Array.isArray(beforeBoard),
      hasAfterBoard: Array.isArray(afterBoard),
    });
    builtInModernAppendResult(board, gameId, motion);
    return;
  }

  const fromVisual = builtInModernVisualSquare(gameId, from);
  const toVisual = builtInModernVisualSquare(gameId, to);
  const castle = gameId === "chess" ? chessCastlingRook(motion.before, move, motion.after) : null;
  const travelDuration = castle ? BUILT_IN_MODERN_MOTION_MS['chess-move'] : duration;
  const actor = make("span", "built-in-modern-motion-actor " + gameId + "-motion-actor " + (castle ? "chess-move" : motion.type));
  actor.style.setProperty("--motion-dx", String(toVisual.column - fromVisual.column));
  actor.style.setProperty("--motion-dy", String(toVisual.row - fromVisual.row));
  actor.style.setProperty("--motion-duration", travelDuration + "ms");
  actor.style.setProperty("--motion-elapsed", elapsed + "ms");
  actor.append(builtInModernPiece(gameId, movingPiece, "built-in-modern-actor-piece"));
  const promotedPiece = afterBoard?.[to.row]?.[to.column];
  if (gameId === "checkers" && promotedPiece === String(promotedPiece).toUpperCase()
      && movingPiece !== String(movingPiece).toUpperCase()) {
    actor.classList.add("promotes-on-landing");
  }
  sourceCell.append(actor);
  const targetPieceElement = targetCell.querySelector(".piece, .chess-piece");
  targetPieceElement?.classList.add("is-built-in-motion-hidden");
  if (castle) {
    const rookFrom = builtInModernSquare(castle.from);
    const rookTo = builtInModernSquare(castle.to);
    const rookSource = builtInModernCell(board, rookFrom);
    const rookTarget = builtInModernCell(board, rookTo);
    const start = builtInModernVisualSquare(gameId, rookFrom);
    const end = builtInModernVisualSquare(gameId, rookTo);
    const rookActor = make("span", "built-in-modern-motion-actor chess-motion-actor chess-move");
    actor.dataset.castlingPiece = "king";
    rookActor.dataset.castlingPiece = "rook";
    rookActor.style.setProperty("--motion-dx", String(end.column - start.column));
    rookActor.style.setProperty("--motion-dy", String(end.row - start.row));
    rookActor.style.setProperty("--motion-duration", travelDuration + "ms");
    rookActor.style.setProperty("--motion-elapsed", elapsed + "ms");
    rookActor.append(builtInModernPiece(gameId, castle.piece, "built-in-modern-actor-piece"));
    rookSource?.append(rookActor);
    rookTarget?.querySelector(".chess-piece")?.classList.add("is-built-in-motion-hidden");
  }

  const isCapture = !castle && (motion.type.includes("capture")
    || motion.type === "checkers-win"
    || motion.type === "chess-checkmate");
  if (gameId === "chess" && isCapture && targetPieceElement) {
    const renderedBoard = builtInModernBoardState(session?.state);
    const renderedTargetPiece = renderedBoard?.[to.row]?.[to.column];
    const authoritativeDestinationReady = Boolean(renderedTargetPiece)
      && renderedTargetPiece === promotedPiece;
    if (authoritativeDestinationReady) {
      const revealDestination = () => {
        targetPieceElement.classList.remove("is-built-in-motion-hidden");
      };
      const completeHandoff = () => {
        actor.remove();
        revealDestination();
      };
      // Overlap the rendered destination with the travelling actor so browser
      // paint timing cannot expose a blank frame during their handoff.
      const revealDelay = Math.max(0, 2520 - elapsed);
      const completionDelay = Math.max(0, 2800 - elapsed);
      if (revealDelay === 0) revealDestination();
      else setTimeout(revealDestination, revealDelay);
      if (completionDelay === 0) completeHandoff();
      else setTimeout(completeHandoff, completionDelay);
    }
  }
  if (isCapture) {
    const captured = gameId === "checkers"
      ? { row: Math.round((from.row + to.row) / 2), column: Math.round((from.column + to.column) / 2) }
      : to;
    const victimCell = builtInModernCell(board, captured);
    const victimPiece = beforeBoard?.[captured.row]?.[captured.column];
    if (victimCell && victimPiece) {
      const victim = make("span", "built-in-modern-capture-victim " + gameId + "-capture-victim");
      victim.style.setProperty("--motion-duration", duration + "ms");
      victim.style.setProperty("--motion-elapsed", elapsed + "ms");
      victim.append(builtInModernPiece(gameId, victimPiece, "built-in-modern-victim-piece"));
      victimCell.append(victim);
      if (gameId === "checkers") {
        const capturedVisual = builtInModernVisualSquare(gameId, captured);
        const projectile = make("span", "built-in-checkers-projectile");
        projectile.style.setProperty("--projectile-dx", String(capturedVisual.column - fromVisual.column));
        projectile.style.setProperty("--projectile-dy", String(capturedVisual.row - fromVisual.row));
        projectile.style.setProperty(
          "--projectile-angle",
          (Math.atan2(capturedVisual.row - fromVisual.row, capturedVisual.column - fromVisual.column) * 180 / Math.PI) + "deg",
        );
        projectile.style.setProperty("--motion-duration", duration + "ms");
        projectile.style.setProperty("--motion-elapsed", elapsed + "ms");
        projectile.append(make("span", "projectile-body"));
        sourceCell.append(projectile);
        const debris = make("span", "built-in-checkers-debris");
        for (let index = 0; index < 6; index += 1) {
          const fragment = make("span", "checker-fragment");
          fragment.style.setProperty("--fragment-index", String(index));
          fragment.append(builtInModernPiece(gameId, victimPiece, "built-in-modern-fragment-piece"));
          debris.append(fragment);
        }
        victimCell.append(debris);
      } else {
        const vault = make("span", "built-in-chess-capture-vault");
        const sparks = make("span", "built-in-chess-capture-sparks");
        for (let index = 0; index < 8; index += 1) sparks.append(make("i"));
        const seal = make("span", "built-in-chess-capture-seal");
        victimCell.append(vault, sparks, seal);
      }
    }
  }

  if (motion.type === "checkers-win") {
    board.classList.add("is-built-in-checkers-win");
    Array.from(board.querySelectorAll(".board-cell > .piece"))
      .filter((piece) => !piece.classList.contains("is-built-in-motion-hidden"))
      .forEach((piece, index) => {
        piece.classList.add("built-in-checkers-winning-piece");
        piece.style.setProperty("--winner-stagger", (index * 85) + "ms");
      });
    board.append(make("span", "built-in-checkers-victory-wave"));
  }
  if (motion.type === "chess-checkmate") {
    board.classList.add("is-built-in-chess-checkmate");
    const terminalLead = motion.move?.capture
      ? Math.max(2800, Number(motion.leadDurationMs || 2800))
      : 0;
    board.style.setProperty("--terminal-lead", `${terminalLead}ms`);
    const movingSide = String(movingPiece).charAt(0).toLowerCase();
    const kingCell = Array.from(board.querySelectorAll(".board-cell[data-piece]")).find((cell) => {
      return cell.dataset.pieceType === "k" && cell.dataset.pieceSide !== movingSide;
    });
    kingCell?.querySelector(".chess-piece")?.classList.add("is-built-in-defeated-king");
    if (kingCell) {
      const brackets = make("span", "built-in-chess-checkmate-brackets");
      brackets.setAttribute("aria-hidden", "true");
      for (let index = 0; index < 4; index += 1) brackets.append(document.createElement("i"));
      const wave = make("span", "built-in-chess-checkmate-wave");
      wave.setAttribute("aria-hidden", "true");
      kingCell.append(brackets, wave);
      const defeatedSide = kingCell.dataset.pieceSide;
      Array.from(board.querySelectorAll(".board-cell[data-piece]")).forEach((cell) => {
        if (cell.dataset.pieceSide !== defeatedSide) {
          cell.querySelector(".chess-piece")?.classList.add("built-in-chess-winning-army");
        }
      });
    }
    const frameSweep = make("span", "built-in-chess-frame-sweep");
    frameSweep.setAttribute("aria-hidden", "true");
    board.append(frameSweep);
  }
  builtInModernAppendResult(board, gameId, motion);
  trace("classic-built-in-motion-attached", null, {
    type: motion.type,
    from,
    to,
    elapsedMs: Math.round(elapsed),
    projectileAttached: Boolean(board.querySelector(".built-in-checkers-projectile")),
  });
}

function builtInModernEnhanceBoard(board, gameId) {
  if (!board || board.dataset.builtInModernEnhanced === "true") {
    return;
  }
  board.dataset.builtInModernEnhanced = "true";
  board.dataset.visualFx = optionCategory("visualFxEnabled", true) ? "on" : "off";
  board.classList.add("built-in-modern-" + gameId + "-board");
  const boardState = session?.state?.board || [];
  const showNumbers = gameId === "checkers" && builtInCheckersSquareNumbersEnabled;
  Array.from(board.querySelectorAll(":scope > .board-cell")).forEach((cell, index) => {
    const visualRow = Math.floor(index / 8);
    const visualColumn = index % 8;
    const logical = squareLogicalCoordinates(gameId, visualRow, visualColumn);
    const piece = boardState?.[logical.row]?.[logical.column] || "";
    const dark = (visualRow + visualColumn) % 2 === 1;
    cell.dataset.logicalRow = String(logical.row);
    cell.dataset.logicalColumn = String(logical.column);
    cell.dataset.squareTone = dark ? "dark" : "light";
    if (piece) {
      cell.dataset.piece = String(piece);
    }
    if (showNumbers && dark) {
      const number = make("span", "built-in-checkers-square-number");
      number.textContent = String((visualRow * 4) + Math.floor(visualColumn / 2) + 1);
      number.setAttribute("aria-hidden", "true");
      cell.append(number);
    }
    if (gameId === "chess" && visualRow === 7) {
      cell.dataset.fileLabel = String.fromCharCode(97 + logical.column);
    }
    if (gameId === "chess" && visualColumn === 0) {
      cell.dataset.rankLabel = String(8 - logical.row);
    }
  });
  builtInModernAppendMotion(board, gameId);
}

function builtInModernEnhancePromotion(board) {
  board?.querySelectorAll(".chess-promotion-choices [data-promotion-choice]").forEach((button) => {
    if (button.dataset.builtInModernChoice === "true") {
      return;
    }
    button.dataset.builtInModernChoice = "true";
    const piece = button.dataset.promotionChoice;
    const label = button.textContent.trim();
    button.textContent = "";
    const from = pendingChessPromotion?.from || [];
    const movingPawn = session?.state?.board?.[Number(from[0])]?.[Number(from[1])] || "wP";
    const side = String(movingPawn).startsWith("b") ? "b" : "w";
    const promotionPiece = builtInChessPieceNode(`${side}${piece}`, "promotion-piece-glyph");
    button.append(promotionPiece, make("span", "promotion-piece-label", label));
  });
}

function builtInModernEnhanceCheckersOptions(root) {
  if (root.querySelector(".built-in-modern-square-number-option")) {
    return;
  }
  const anchor = Array.from(root.querySelectorAll(".game-setting")).find((setting) =>
    /show legal moves/i.test(setting.textContent || ""));
  if (!anchor) {
    return;
  }
  const setting = make("div", "game-setting built-in-modern-square-number-option");
  const label = make("span", "game-setting-label setting-label-with-info", "Show square numbers");
  const toggle = make("button", "compact-state-button");
  const syncToggle = () => {
    toggle.textContent = builtInCheckersSquareNumbersEnabled ? "On" : "Off";
    toggle.setAttribute("aria-pressed", builtInCheckersSquareNumbersEnabled ? "true" : "false");
    toggle.setAttribute(
      "aria-label",
      "Show square numbers " + (builtInCheckersSquareNumbersEnabled ? "On" : "Off"),
    );
  };
  toggle.type = "button";
  syncToggle();
  toggle.addEventListener("click", () => {
    builtInCheckersSquareNumbersEnabled = !builtInCheckersSquareNumbersEnabled;
    try {
      gameViewStorage.setItem(
        "corechat-checkers-square-numbers",
        builtInCheckersSquareNumbersEnabled ? "true" : "false",
      );
    } catch (_error) {
      // The current viewer still receives the setting when storage is unavailable.
    }
    syncToggle();
    const board = root.querySelector(".built-in-modern-checkers-board");
    board?.querySelectorAll(".built-in-checkers-square-number").forEach((number) => number.remove());
    if (builtInCheckersSquareNumbersEnabled) {
      Array.from(board?.querySelectorAll(":scope > .board-cell") || []).forEach((cell, index) => {
        if (cell.dataset.squareTone !== "dark") {
          return;
        }
        const visualRow = Math.floor(index / 8);
        const visualColumn = index % 8;
        const number = make("span", "built-in-checkers-square-number");
        number.textContent = String((visualRow * 4) + Math.floor(visualColumn / 2) + 1);
        number.setAttribute("aria-hidden", "true");
        cell.append(number);
      });
    }
    builtInModernScheduleEnhancement();
  });
  const help = make(
    "span",
    "minor setting-info-popover",
    "Numbers the 32 playable dark squares from 1 through 32 for standard Checkers notation.",
  );
  setting.append(label, toggle, help);
  anchor.insertAdjacentElement("afterend", setting);
}

function enhanceClassicCoordinateOverlay(board, gameId) {
  if (!board) {
    return;
  }
  if (gameId === "checkers") {
    board.querySelectorAll(".classic-checkers-square-number").forEach((number) => number.remove());
    if (!builtInCheckersSquareNumbersEnabled) {
      return;
    }
    Array.from(board.querySelectorAll(":scope > .checkers-classic-cell")).forEach((cell) => {
      const visualRow = Number(cell.dataset.visualRow);
      const visualColumn = Number(cell.dataset.visualColumn);
      if (!Number.isInteger(visualRow)
          || !Number.isInteger(visualColumn)
          || (visualRow + visualColumn) % 2 === 0) {
        return;
      }
      const number = make("span", "classic-checkers-square-number");
      number.textContent = String((visualRow * 4) + Math.floor(visualColumn / 2) + 1);
      number.setAttribute("aria-hidden", "true");
      cell.append(number);
    });
    return;
  }
}

function builtInModernEnhance() {
  builtInModernEnhancementScheduled = false;
  if (!["checkers", "chess"].includes(context.extensionId)) {
    return;
  }
  const root = document.getElementById("game-root");
  const presentationPack = session?.presentation?.effectivePack || "built-in";
  const classic = presentationPack === "classic";
  const builtIn = presentationPack === "built-in";
  const board = classic
    ? root?.querySelector(`.${context.extensionId}-classic`)
    : context.extensionId === "checkers"
      ? root?.querySelector(".built-in-checkers-board")
      : root?.querySelector(".board:not(.classic-stage):not(.classic-board)");
  if (classic) {
    if (context.extensionId === "checkers") {
      enhanceClassicCoordinateOverlay(board, context.extensionId);
    }
  } else if (builtIn) {
    builtInModernEnhanceBoard(board, context.extensionId);
    if (context.extensionId === "chess") {
      builtInModernEnhancePromotion(board);
    }
  }
  if (root && context.extensionId === "checkers") {
    builtInModernEnhanceCheckersOptions(root);
  }
}

function builtInModernScheduleEnhancement() {
  if (builtInModernEnhancementScheduled) {
    return;
  }
  builtInModernEnhancementScheduled = true;
  window.requestAnimationFrame(builtInModernEnhance);
}

if (["checkers", "chess"].includes(context.extensionId)) {
  const builtInModernObserver = new MutationObserver(builtInModernScheduleEnhancement);
  builtInModernObserver.observe(document.getElementById("game-root") || document.body, {
    childList: true,
    subtree: true,
  });
  queueMicrotask(builtInModernScheduleEnhancement);
}

function renderCheckers() {
  const pendingCheckersMove = pendingSquareMove?.game === "checkers" ? pendingSquareMove : null;
  const classic = session.presentation?.effectivePack === "classic";
  if (!classic && options?.effectsEnabled !== false) {
    [
      BUILT_IN_PUBLIC_SOUNDS.checkerMissileLaunch,
      BUILT_IN_PUBLIC_SOUNDS.checkerExplosion,
      BUILT_IN_PUBLIC_SOUNDS.checkerExplosionRumble,
      BUILT_IN_PUBLIC_SOUNDS.success,
      BUILT_IN_PUBLIC_SOUNDS.loss,
    ].forEach(prepareBuiltInGameSound);
  }
  const board = classic ? classicStage("classic-board", "checkers-classic") : make("div", "board built-in-checkers-board");
  if (classic) applyClassicBoardScale(board, classicSourceMap("checkers"));
  const checkersProjection = squareBoardPerspective("checkers").projection;
  board.dataset.boardOrientation = `${classic ? classicSourceMap("checkers").orientation : "viewer-relative-built-in"}-${checkersProjection}`;
  board.dataset.sourceRoleProjection = checkersProjection;
  board.setAttribute("role", "grid");
  board.setAttribute("aria-label", squareBoardAriaLabel("Checkers"));
  const state = session.state || {};
  const interaction = projectedBoardInteraction(state);
  const showAllLegalMoves = optionCategory("showLegalMoves", false);
  for (let visualRow = 0; visualRow < 8; visualRow++) for (let visualColumn = 0; visualColumn < 8; visualColumn++) {
    const { row, column } = squareLogicalCoordinates("checkers", visualRow, visualColumn);
    const piece = state.board?.[row]?.[column] || null;
    const key = boardSquareKey(row, column);
    const selected = interaction.selectedKey === key;
    const selectableOrigin = interaction.origins.has(key);
    const legalDestination = interaction.destinations.has(key);
    const availability = selected ? ", selected movable piece; activate again to cancel" : selectableOrigin ? ", movable piece" : legalDestination ? ", legal destination" : "";
    const button = boardButton(row, column, `${String.fromCharCode(65 + column)}${8 - row}${piece ? `, ${piece.toLowerCase() === "a" ? "starting side" : "opposing side"}${piece === piece.toUpperCase() ? " king" : " piece"}` : ", empty"}${availability}`);
    button.dataset.visualRow = String(visualRow);
    button.dataset.visualColumn = String(visualColumn);
    let cellGeometry = null;
    if (classic) {
      button.className = "classic-cell checkers-classic-cell";
      const source = classicSourceMap("checkers");
      cellGeometry = checkerCellBox("checkers", row, column);
      setSourceBox(button, cellGeometry, source.canvas.width, source.canvas.height);
    }
    const pendingOrigin = pendingCheckersMove
      && Number(pendingCheckersMove.from?.[0]) === row
      && Number(pendingCheckersMove.from?.[1]) === column;
    const pendingDestination = pendingCheckersMove
      && Number(pendingCheckersMove.to?.[0]) === row
      && Number(pendingCheckersMove.to?.[1]) === column;    button.classList.toggle("is-selected", selected);
    button.classList.toggle("is-legal-destination", legalDestination && showAllLegalMoves);
    button.classList.toggle("is-preview-destination", legalDestination && previewDestination === key);
    button.classList.toggle("is-move-submitting-origin", Boolean(pendingOrigin));
    button.classList.toggle("is-move-submitting-destination", Boolean(pendingDestination));
    button.setAttribute("aria-pressed", selected ? "true" : "false");
    button.dataset.actionable = selectableOrigin ? "origin" : legalDestination ? "destination" : "none";
    button.dataset.legalDestination = legalDestination ? "true" : "false";
    if (pendingDestination && !classic) {
      const cue = make("span", "move-submitted-cue", "");
      cue.setAttribute("aria-hidden", "true");
      button.append(cue);
      button.setAttribute("aria-busy", "true");
    }
    if (piece && classic) {
      const source = classicSourceMap("checkers");
      const color = piece.toLowerCase() === "a" ? "b" : "w";
      const size = source.pieceSizeByRow[checkersSourceCoordinates(row, column).row];
      const slot = `gif-${piece === piece.toUpperCase() ? `k-${color}` : color}-${size}${selected ? "-h" : ""}`;
      const restSlot = `gif-${piece === piece.toUpperCase() ? `k-${color}` : color}-${size}`;
      const image = squareSelectionImage(slot, restSlot, "classic-piece");
      image.dataset.pieceSlot = slot;
      fitClassicAssetToSourcePixels(image, cellGeometry, "center", true);
      button.append(image);
    } else if (piece) button.append(make("span", `piece side-${piece.toLowerCase()}${piece === piece.toUpperCase() ? " king" : ""}`));
    if (classic && legalDestination) {
      const source = classicSourceMap("checkers");
      const cue = classicDestinationCue(source.destinationCueAsset, "is-checkers-crosshair", cellGeometry, "checkers-optical");
      cue.dataset.opticalCenter = `${source.destinationCueOpticalCenter.x},${source.destinationCueOpticalCenter.y}`;
      button.append(cue);
    }
    button.disabled = !canAct() || (!selected && !selectableOrigin && !legalDestination);
    bindDestinationPreview(button, key, legalDestination);
    button.addEventListener("click", () => {
      if (selected) {
        selectedSquare = null;
        previewDestination = null;
        touchPreviewDestination = null;
        playClassicSelectionCue("wav-lock", "selected-piece-cancelled");
        button.blur();
        render();
        return;
      }
      if (selectableOrigin) {
        selectedSquare = [row, column];
        playClassicSelectionCue("wav-lock", "valid-piece-selected");
        render();
        return;
      }
      if (!selectedSquare || !legalDestination || consumeTouchPreviewOnly(button)) return;
      const from = selectedSquare;
      const to = [row, column];
      selectedSquare = null;
      pendingSquareMove = { game: "checkers", from: [...from], to: [...to] };
      startOptimisticBuiltInCheckersMove(from, to);
      performAction("move", { from, to }).finally(() => {
        pendingSquareMove = null;
        render();
      });
    });
    board.append(button);
  }
  if (classic) {
    appendClassicMotion(board, "checkers");
    appendClassicPlayerAvatars(board, "checkers", classicMembersBySourceIdentity("checkers"));
    appendCheckersClassicEdgeActions(board);
  }
  return board;
}

function renderChess() {
  const classic = session.presentation?.effectivePack === "classic";
  if (!classic && options?.effectsEnabled !== false) {
    [
      BUILT_IN_PUBLIC_SOUNDS.move,
      BUILT_IN_PUBLIC_SOUNDS.capture,
      BUILT_IN_PUBLIC_SOUNDS.chessPortalSink,
      BUILT_IN_PUBLIC_SOUNDS.checkerExplosionRumble,
      BUILT_IN_PUBLIC_SOUNDS.chessCheck,
      BUILT_IN_PUBLIC_SOUNDS.chessCheckmate,
      BUILT_IN_PUBLIC_SOUNDS.success,
      BUILT_IN_PUBLIC_SOUNDS.loss,
    ].forEach(prepareBuiltInGameSound);
  }
  const chessPerspective = squareBoardPerspective("chess");
  const chessSource = classicSourceMap("chess");
  const classicBoardSlot = chessSource.boardSlots?.[chessPerspective.projection] || "classic-board-alternate";
  const board = classic ? classicStage(classicBoardSlot, "chess-classic") : make("div", "board");
  const coreChatArtwork = !classic && session?.presentation?.effectivePack === "corechat";
  if (classic) applyClassicBoardScale(board, chessSource);
  const pieceSizeMode = chessPieceSizeMode();
  const pieceStyleMode = !classic && !coreChatArtwork ? chessPieceStyleMode() : "unicode";
  board.classList.toggle("is-chess-piece-smaller", pieceSizeMode === "smaller");
  board.dataset.chessPieceSize = pieceSizeMode;
  board.dataset.chessPieceStyle = pieceStyleMode;
  board.dataset.boardOrientation = `${classic ? chessSource.orientation : "viewer-relative-built-in"}-${chessPerspective.projection}`;
  board.dataset.sourceRoleProjection = chessPerspective.projection;
  board.dataset.appearancePack = coreChatArtwork ? "corechat" : (classic ? "classic" : "built-in");
  board.classList.toggle("show-chess-coordinates", coreChatArtwork && coreChatChessCoordinatesEnabled);
  board.setAttribute("role", "grid");
  board.setAttribute("aria-label", squareBoardAriaLabel("Chess"));
  const glyph = { wK:"♔",wQ:"♕",wR:"♖",wB:"♗",wN:"♘",wP:"♙",bK:"♚",bQ:"♛",bR:"♜",bB:"♝",bN:"♞",bP:"♟" };
  const classicPiece = { K:"k", Q:"q", R:"r", B:"b", N:"kn", P:"p" };
  const state = session.state || {};
  const interaction = projectedBoardInteraction(state);
  const showAllLegalMoves = optionCategory("showLegalMoves", false);
  for (let visualRow = 0; visualRow < 8; visualRow++) for (let visualColumn = 0; visualColumn < 8; visualColumn++) {
    const { row, column } = squareLogicalCoordinates("chess", visualRow, visualColumn);
    const piece = state.board?.[row]?.[column] || null;
    const key = boardSquareKey(row, column);
    const selected = interaction.selectedKey === key;
    const selectableOrigin = interaction.origins.has(key);
    const legalDestination = interaction.destinations.has(key);
    const availability = selected ? ", selected movable piece; activate again to cancel" : selectableOrigin ? ", movable piece" : legalDestination ? ", legal destination" : "";
    const button = boardButton(row, column, `${String.fromCharCode(65 + column)}${8 - row}${piece ? `, ${piece[0] === "w" ? "white" : "black"} ${piece[1]}` : ", empty"}${availability}`);
    button.dataset.visualRow = String(visualRow);
    button.dataset.visualColumn = String(visualColumn);
    if (piece) {
      button.dataset.pieceType = piece[1].toLowerCase();
      button.dataset.pieceSide = piece[0];
    }
    let cellGeometry = null;
    if (classic) {
      button.className = "classic-cell chess-classic-cell";
      cellGeometry = checkerCellBox("chess", row, column);
      setSourceBox(button, cellGeometry, chessSource.canvas.width, chessSource.canvas.height);
    }
    button.classList.toggle("is-selected", selected);
    button.classList.toggle("is-legal-destination", legalDestination && showAllLegalMoves);
    button.classList.toggle("is-preview-destination", legalDestination && previewDestination === key);
    button.setAttribute("aria-pressed", selected ? "true" : "false");
    button.dataset.actionable = selectableOrigin ? "origin" : legalDestination ? "destination" : "none";
    button.dataset.legalDestination = legalDestination ? "true" : "false";
    if (piece && classic) {
      const size = chessSource.pieceSizeByRow[visualRow];
      const restSlot = `gif-${classicPiece[piece[1]]}-${piece[0]}-${size}`;
      const hiliteSlot = `gif-${classicPiece[piece[1]]}-${piece[0]}-h-${size}`;
      const slot = selected ? hiliteSlot : restSlot;
      const image = squareSelectionImage(slot, restSlot, "classic-chess-piece");
      image.dataset.pieceSlot = slot;
      image.dataset.restSlot = restSlot;
      image.dataset.hiliteSlot = hiliteSlot;
      fitClassicAssetToSourcePixels(image, cellGeometry, "center-bottom", true);
      button.append(image);
      if (selectableOrigin) {
        const showFocus = focused => {
          const focusSlot = focused || selected ? hiliteSlot : restSlot;
          if (image.dataset.pieceSlot === focusSlot) return;
          image.dataset.pieceSlot = focusSlot;
          setSquarePieceArtwork(image, focusSlot);
        };
        button.addEventListener("focus", () => showFocus(true));
        button.addEventListener("blur", () => showFocus(false));
      }
    }
    else if (piece && !coreChatArtwork) {
      button.append(builtInChessPieceNode(piece, "", pieceStyleMode));
    }
    else if (piece) {
      const displayPiece = coreChatArtwork && piece[0] === "b" ? `w${piece[1]}` : piece;
      const pieceNode = make("span", `chess-piece chess-piece-side-${piece[0]}`, glyph[displayPiece] || piece);
      if (coreChatArtwork && piece[0] === "b") {
        pieceNode.style.setProperty("color", "#7a2635", "important");
      }
      button.append(pieceNode);
    }
    if (coreChatArtwork && coreChatChessCoordinatesEnabled) {
      if (visualRow === 7) {
        const file = make("span", "chess-coordinate chess-coordinate-file", String.fromCharCode(97 + column));
        file.setAttribute("aria-hidden", "true");
        button.append(file);
      }
      if (visualColumn === 0) {
        const rank = make("span", "chess-coordinate chess-coordinate-rank", String(8 - row));
        rank.setAttribute("aria-hidden", "true");
        button.append(rank);
      }
    }
    if (classic && legalDestination) {
      const source = classicSourceMap("chess");
      button.append(classicDestinationCue(source.destinationCueAsset, "is-chess-horseshoe", cellGeometry, "center-bottom"));
    }
    button.disabled = Boolean(pendingChessPromotion) || !canAct() || (!selected && !selectableOrigin && !legalDestination);
    bindDestinationPreview(button, key, legalDestination);
    button.addEventListener("click", () => {
      if (selected) {
        selectedSquare = null;
        previewDestination = null;
        touchPreviewDestination = null;
        playClassicSelectionCue("wav-lock", "selected-piece-cancelled");
        button.blur();
        render();
        return;
      }
      if (selectableOrigin) {
        selectedSquare = [row, column];
        playClassicSelectionCue("wav-lock", "valid-piece-selected");
        render();
        return;
      }
      if (!selectedSquare || !legalDestination || consumeTouchPreviewOnly(button)) return;
      const from = selectedSquare;
      const movingPiece = state.board?.[from[0]]?.[from[1]] || "";
      if (movingPiece[1] === "P" && (row === 0 || row === 7)) {
        pendingChessPromotion = { from, to: [row, column] };
        render();
        return;
      }
      const to = [row, column];
      selectedSquare = null;
      pendingSquareMove = { game: "chess", from: [...from], to: [...to] };
      startOptimisticBuiltInChessMove(from, to);
      performAction("move", { from, to }).finally(() => {
        pendingSquareMove = null;
        render();
      });
    });
    board.append(button);
  }
  if (classic) {
    appendClassicMotion(board, "chess");
    appendPersistedClassicTerminal(board, "chess");
    appendClassicPlayerAvatars(board, "chess", classicMembersBySourceIdentity("chess"));
  }
  appendChessPromotionDialog(board);
  return board;
}

function appendChessPromotionDialog(board) {
  if (!pendingChessPromotion || context.extensionId !== "chess") return;
  board.dataset.promotionDialogOpen = "true";
  const overlay = make("div", "chess-promotion-overlay");
  const dialog = make("section", "chess-promotion-dialog");
  dialog.setAttribute("role", "dialog");
  dialog.setAttribute("aria-modal", "true");
  dialog.setAttribute("aria-labelledby", "chess-promotion-heading");
  dialog.append(
    Object.assign(make("h3", "", "Choose promotion"), { id: "chess-promotion-heading" }),
    make("p", "minor", "Choose the piece for this pawn promotion."),
  );
  const choices = make("div", "chess-promotion-choices");
  for (const [value, label] of [["Q", "Queen"], ["R", "Rook"], ["N", "Knight"], ["B", "Bishop"]]) {
    const button = make("button", "", label);
    button.type = "button";
    button.dataset.promotionChoice = value;
    button.addEventListener("click", () => {
      const move = pendingChessPromotion;
      pendingChessPromotion = null;
      if (move) performAction("move", { from: move.from, to: move.to, promotion: value });
    });
    choices.append(button);
  }
  const cancel = make("button", "secondary", "Cancel");
  cancel.type = "button";
  cancel.addEventListener("click", () => {
    const from = pendingChessPromotion?.from || null;
    pendingChessPromotion = null;
    selectedSquare = from;
    render();
  });
  dialog.append(choices, cancel);
  dialog.addEventListener("keydown", event => {
    if (event.key === "Escape") {
      event.preventDefault();
      cancel.click();
      return;
    }
    if (event.key !== "Tab") return;
    const focusable = [...dialog.querySelectorAll("button:not([disabled])")];
    const index = focusable.indexOf(document.activeElement);
    const next = event.shiftKey ? (index <= 0 ? focusable.length - 1 : index - 1) : (index >= focusable.length - 1 ? 0 : index + 1);
    event.preventDefault();
    focusable[next]?.focus();
  });
  overlay.append(dialog);
  board.append(overlay);
  requestAnimationFrame(() => choices.querySelector("button")?.focus());
}

function renderAceyDeucyLegacy() {
  const classic = session.presentation?.effectivePack === "classic";
  const board = classic ? classicStage(session.state?.completed ? "classic-board-result" : "classic-board-alternate", "acey-classic") : make("div", "acey-board");
  if (classic) applyClassicBoardScale(board, classicSourceMap("acey-deucy"));
  const players = (session.state?.turnOrder || []).map((userId, index) => ({
    userId: Number(userId),
    index,
    slot: index === 0 ? "gif-w" : "gif-b",
  }));
  const pile = (player, count, className, label, direction = "down", selected = false) => {
    const host = make("span", `classic-checker-pile ${className} is-${direction}`);
    host.setAttribute("role", "img");
    host.setAttribute("aria-label", `${label}: ${Math.max(0, Number(count || 0))}`);
    for (let index = 0; index < Math.max(0, Number(count || 0)); index++) {
      const checker = mediaImage(player.slot, "classic-checker", "");
      checker.style.setProperty("--stack-index", String(index));
      host.append(checker);
    }
    return host;
  };
  for (let point = 0; point < 24; point++) {
    const button = make("button", "acey-point");
    button.type = "button";
    button.disabled = true;
    if (classic) {
      button.className = "classic-point";
      const source = classicSourceMap("acey-deucy");
      const pointRow = source.pointRows[point < 12 ? 0 : 1];
      setSourceBox(button, {
        x: source.pointX[source.pointOrder[point]],
        y: pointRow.y,
        width: pointRow.width,
        height: pointRow.height,
      }, source.canvas.width, source.canvas.height);
    }
    button.append(make("strong", "", String(point + 1)));
    const values = Object.entries(session.state?.points || {});
    const counts = values.map(([userId, points]) => `${memberName(userId)}: ${Number(points?.[point] || 0)}`).join(" · ");
    if (classic) for (const player of players) {
      const count = Number(session.state?.points?.[String(player.userId)]?.[point] || 0);
      if (!count) continue;
      button.append(pile(player, count, "is-point", `${memberName(player.userId)} pieces on point ${point + 1}`, point < 12 ? "down" : "up"));
    }
    button.append(make("span", classic ? "sr-only" : "minor", counts));
    board.append(button);
  }
  if (classic) {
    for (const player of players) {
      const reserve = pile(player, session.state?.off?.[String(player.userId)], "classic-reserve", `${memberName(player.userId)} off-board reserve`, player.index === 0 ? "down" : "up");
      setSourceBox(reserve, { x: 469, y: player.index === 0 ? 12 : 176, width: 30, height: 132 }, 500, 320);
      const bar = pile(player, session.state?.bar?.[String(player.userId)], "classic-bar", `${memberName(player.userId)} pieces on the bar`, player.index === 0 ? "down" : "up");
      setSourceBox(bar, { x: 209, y: player.index === 0 ? 12 : 176, width: 25, height: 132 }, 500, 320);
      const borne = pile(player, session.state?.borneOff?.[String(player.userId)], "classic-borne-off", `${memberName(player.userId)} pieces borne off`, pointBorneDirection("acey-deucy", player.index));
      setSourceBox(borne, { x: 4, y: player.index === 0 ? 8 : 167, width: 27, height: 44 }, 500, 320);
      board.append(reserve, bar, borne);
    }
    const dice = make("div", "classic-dice");
    for (const value of session.state?.dice || []) dice.append(mediaImage(`gif-dice${value}`, "classic-die", `Die ${value}`));
    board.append(dice);
    appendClassicPlayerAvatars(board, "acey-deucy");
  }
  return board;
}

function pointGameMovePayload(origin, move) {
  const from = origin.startsWith("point:") ? Number(origin.slice(6)) : origin;
  return { from, die: Number(move.die) };
}

function bindPointGameAction(button, key, interaction) {
  const selected = interaction.selectedOrigin === key;
  const selectable = interaction.origins.has(key);
  const legalDestination = interaction.destinations.has(key);
  button.classList.toggle("is-selected", selected);
  button.classList.toggle("is-legal-destination", legalDestination && optionCategory("showLegalMoves", false));
  button.classList.toggle("is-preview-destination", legalDestination && previewDestination === key);
  button.dataset.legalDestination = legalDestination ? "true" : "false";
  button.dataset.actionable = selectable ? "origin" : legalDestination ? "destination" : "none";
  button.setAttribute("aria-pressed", selected ? "true" : "false");
  button.disabled = !canAct() || (!selected && !selectable && !legalDestination);
  bindDestinationPreview(button, key, legalDestination);
  button.addEventListener("click", () => {
    if (selected) {
      selectedPointOrigin = null;
      previewDestination = null;
      touchPreviewDestination = null;
      playClassicSelectionCue("wav-lock", "selected-checker-cancelled");
      button.blur();
      render();
      return;
    }
    if (interaction.selectedOrigin && legalDestination) {
      if (consumeTouchPreviewOnly(button)) return;
      const move = interaction.moves.find(candidate => String(candidate.destination) === key);
      if (!move) return;
      const origin = interaction.selectedOrigin;
      selectedPointOrigin = null;
      previewDestination = null;
      touchPreviewDestination = null;
      performAction("move", pointGameMovePayload(origin, move));
      return;
    }
    if (selectable) {
      selectedPointOrigin = key;
      previewDestination = null;
      touchPreviewDestination = null;
      playClassicSelectionCue("wav-lock", "valid-checker-selected");
      render();
    }
  });
}

function appendAceyDoubletDialog(board, state) {
  if (state?.aceyStage !== "choose-double" || !canAct()) return;
  board.dataset.doubletDialogOpen = "true";
  const overlay = make("div", "acey-doublet-overlay");
  const dialog = make("form", "acey-doublet-dialog");
  dialog.setAttribute("role", "dialog");
  dialog.setAttribute("aria-modal", "true");
  dialog.setAttribute("aria-labelledby", "acey-doublet-heading");
  const titleBar = make("div", "acey-doublet-titlebar");
  titleBar.append(
    Object.assign(make("strong", "", "Choose Doublet"), { id: "acey-doublet-heading" }),
    Object.assign(make("span", "acey-doublet-close", "×"), { ariaHidden: "true" }),
  );
  const choices = document.createElement("fieldset");
  choices.className = "acey-doublet-choices";
  choices.append(make("legend", "", "Choose any doublet roll:"));
  const labels = ["Ones", "Twos", "Threes", "Fours", "Fives", "Sixes"];
  const ok = make("button", "acey-doublet-ok", "OK");
  ok.type = "submit";
  ok.disabled = true;
  labels.forEach((label, index) => {
    const value = index + 1;
    const option = make("label", "acey-doublet-option");
    const input = document.createElement("input");
    input.type = "radio";
    input.name = "acey-doublet-choice";
    input.value = String(value);
    input.addEventListener("change", () => { ok.disabled = false; });
    option.append(input, make("span", "", label));
    choices.append(option);
  });
  dialog.addEventListener("submit", event => {
    event.preventDefault();
    const selected = dialog.querySelector('input[name="acey-doublet-choice"]:checked');
    if (!selected) return;
    performAction("choose-double", { value: Number(selected.value) });
  });
  dialog.append(titleBar, choices, ok);
  overlay.append(dialog);
  board.append(overlay);
}

function renderPointGame(gameId) {
  const state = session.state || {};
  const classic = session.presentation?.effectivePack === "classic";
  if (!classic && options?.effectsEnabled !== false) {
    [
      BUILT_IN_PUBLIC_SOUNDS.select,
      BUILT_IN_PUBLIC_SOUNDS.move,
      BUILT_IN_PUBLIC_SOUNDS.pointHitToBar,
      builtInPointDiceSoundAsset(),
      BUILT_IN_PUBLIC_SOUNDS.success,
      BUILT_IN_PUBLIC_SOUNDS.loss,
      BUILT_IN_PUBLIC_SOUNDS.gammon,
      BUILT_IN_PUBLIC_SOUNDS.backgammon,
    ].forEach(prepareBuiltInGameSound);
  }
  const isAcey = gameId === "acey-deucy";
  const turnOrder = Array.isArray(state.turnOrder) ? state.turnOrder.map(Number) : [];
  const viewerPlayerIndex = turnOrder.indexOf(currentUserId());
  const viewerSide = viewerPlayerIndex === 1 ? 1 : 0;
  const builtInPointOrder = Array.from({ length: 24 }, (_, point) => {
    if (viewerSide === 1) return point < 12 ? 11 - point : point - 12;
    return point < 12 ? point : 23 - point;
  });
  const builtInHomeColumn = viewerSide === 1 ? "15" : "1";
  const builtInReserveColumn = viewerSide === 1 ? "1" : "15";
  const source = classicSourceMap(gameId);
  const boardSlot = isAcey
    ? (state.completed ? "classic-board-result" : "classic-board-alternate")
    : source.boardSlot;
  const board = classic
    ? classicStage(boardSlot, `${isAcey ? "acey" : "backgammon"}-classic point-game-classic`)
    : make("div", `acey-board point-game-board built-in-point-board ${isAcey ? "is-acey" : "is-backgammon"}`);
  if (classic) applyClassicBoardScale(board, source);
  if (!classic) board.dataset.checkerStyle = pointCheckerStyleMode();
  board.setAttribute("role", "grid");
  board.dataset.boardOrientation = classic
    ? String(source.sourceRole || "source-role-1")
    : `viewer-relative-role-${viewerSide + 1}`;
  if (classic && !isAcey) board.dataset.sourceRole = source.sourceRole;
  board.setAttribute("aria-label", `${isAcey ? "Acey Deucy" : "Backgammon"} board. Choose a movable checker, then a legal destination. Activate the selected checker again to cancel.`);
  const interaction = projectedPointInteraction(state);
  const players = turnOrder.map((userId, index) => ({ userId, index, slot: index === 0 ? "gif-w" : "gif-b" }));
  const configurePointDestinationCueHost = (button, geometry) => {
    const natural = source.destinationCueNaturalSize;
    const hotspot = source.destinationCueHotspot;
    button.style.setProperty("--point-destination-cue", `url("${new URL(source.destinationCueAsset, window.location.href).href}")`);
    button.style.setProperty("--point-destination-cue-width", `${(Number(natural.width) / Number(geometry.width)) * 100}%`);
    button.style.setProperty("--point-destination-cue-hotspot-x", `${(Number(hotspot.x) / Number(natural.width)) * -100}%`);
    button.style.setProperty("--point-destination-cue-hotspot-y", `${(Number(hotspot.y) / Number(natural.height)) * -100}%`);
    button.dataset.destinationCueAsset = source.destinationCueAsset;
    button.dataset.destinationCueResourceSha256 = source.destinationCueResourceSha256;
  };
  const stackStepPercent = (count, sourceHeight, checkerSize = 25, minimumStep = 4) => {
    const amount = Math.max(0, Number(count || 0));
    if (amount <= 1) return 0;
    return (Math.max(minimumStep, Math.min(checkerSize, (Number(sourceHeight) - checkerSize) / (amount - 1))) / Number(sourceHeight)) * 100;
  };
  const builtInPointStackOffset = index => {
    const openIndex = Math.min(Math.max(0, Number(index || 0)), 6);
    const overflowIndex = Math.max(0, Number(index || 0) - 6);
    return `calc(${openIndex} * clamp(21px, 3.1cqi, 24px) + ${overflowIndex} * clamp(2px, .45cqi, 4px))`;
  };
  const pile = (player, count, className, label, direction = "down", selected = false, sourceHeight = source.pointRows[0].height, sourceWidth = 0) => {
    const borneProfile = classic && className.includes("classic-borne-zone") ? source.borneOffCheckers : null;
    const profileSize = borneProfile?.nativeSizes?.[player.index] || null;
    const checkerHeight = Number(profileSize?.[1] || 25);
    const minimumStep = Number(borneProfile?.stackStep || 4);
    const host = make("span", `classic-checker-pile ${className} is-${direction}${borneProfile ? " is-side-profile" : ""}${classic ? "" : " is-built-in-pile"}`);
    host.setAttribute("role", "img");
    host.setAttribute("aria-label", `${label}: ${Math.max(0, Number(count || 0))}`);
    const stackStep = stackStepPercent(count, sourceHeight, checkerHeight, minimumStep);
    host.style.setProperty("--stack-step", `${stackStep}%`);
    host.style.setProperty("--stack-inset", `${(Number(borneProfile?.edgeInsets?.[player.index] ?? borneProfile?.edgeInset ?? 0) / Number(sourceHeight)) * 100}%`);
    host.dataset.stackStep = String(stackStep);
    host.dataset.ownerUserId = String(player.userId);
    host.dataset.stackCount = String(Math.max(0, Number(count || 0)));
    host.dataset.stackDensity = Number(count || 0) >= 8 ? "dense" : Number(count || 0) >= 6 ? "compact" : "open";
    const cappedPointLane = !classic && className.split(/\s+/).includes("is-point");
    if (cappedPointLane) {
      const finalIndex = Math.max(0, Number(count || 0) - 1);
      host.dataset.stackNormalCapacity = "7";
      host.style.setProperty("--built-in-stack-badge-offset", builtInPointStackOffset(finalIndex));
    }
    for (let index = 0; index < Math.max(0, Number(count || 0)); index++) {
      const selectedChecker = selected && index === Math.max(0, Number(count || 0)) - 1;
      const checkerSlot = borneProfile
        ? (borneProfile.slots?.[player.index] || `${player.slot}-s`)
        : `${player.slot}${selectedChecker ? "-h" : ""}`;
      const checker = classic ? pointSelectionImage(checkerSlot, borneProfile ? checkerSlot : player.slot, "classic-checker") : make("span", `built-in-checker is-player-${player.index + 1}`);
      checker.style.setProperty("--stack-index", String(index));
      if (cappedPointLane) checker.style.setProperty("--built-in-stack-offset", builtInPointStackOffset(index));
      if (classic) {
        checker.dataset.checkerSlot = checkerSlot;
        const notchInsets = borneProfile?.notchLeftInsets?.[player.index] || [];
        const notchCandidate = notchInsets.length ? Number(notchInsets[index % notchInsets.length]) : NaN;
        if (Number(sourceWidth) > 0 && Number.isFinite(notchCandidate) && profileSize) {
          const notchCenter = notchCandidate + Number(profileSize[0]) / 2;
          checker.style.left = `${(notchCenter / Number(sourceWidth)) * 100}%`;
          checker.dataset.sourceNotchLeftInset = String(notchCandidate);
        }
      }
      host.append(checker);
    }
    if (!classic && Number(count || 0) >= 8) {
      const badge = make("span", "built-in-stack-count-badge", String(Number(count || 0)));
      badge.setAttribute("aria-hidden", "true");
      host.append(badge);
    }
    return host;
  };
  for (let point = 0; point < 24; point++) {
    const key = `point:${point}`;
    const selected = interaction.selectedOrigin === key;
    const legalDestination = interaction.destinations.has(key);
    const button = make("button", classic ? "classic-point point-game-point" : "acey-point point-game-point");
    button.type = "button";
    button.dataset.pointKey = key;
    if (classic) {
      const pointRow = source.pointRows[point < 12 ? 0 : 1];
      const pointColumn = Number(source.pointOrder?.[point] ?? point % 12);
      setSourceBox(button, { x: source.pointX[pointColumn], y: pointRow.y, width: pointRow.width, height: pointRow.height }, source.canvas.width, source.canvas.height);
    } else {
      const pointColumn = builtInPointOrder[point];
      button.style.gridColumn = String(pointColumn < 6 ? pointColumn + 2 : pointColumn + 3);
      button.style.gridRow = point < 12 ? "1" : "3";
      button.dataset.pointSide = point < 12 ? "top" : "bottom";
      button.dataset.pointTone = point % 2 === 0 ? "light" : "dark";
    }
    const counts = players.map(player => ({ player, count: Number(state.points?.[String(player.userId)]?.[point] || 0) }));
    const wording = counts.filter(item => item.count > 0).map(item => `${memberName(item.player.userId)}: ${item.count}`).join("; ") || "empty";
    if (!classic) {
      const destinationCount = Number(counts.find(item => Number(item.player.userId) === currentUserId())?.count || 0);
      button.style.setProperty("--built-in-stack-count", String(Math.max(0, destinationCount)));
      const destinationFinalIndex = Math.max(0, destinationCount - 1);
      button.style.setProperty(
        "--built-in-stack-marker-offset",
        `calc(${builtInPointStackOffset(destinationFinalIndex)} + clamp(28px, 4cqi, 34px))`,
      );
    }
    button.setAttribute("aria-label", `Point ${point + 1}, ${wording}${selected ? ", selected checker" : legalDestination ? ", legal destination" : ""}`);
    button.append(make("strong", classic ? "sr-only" : "", String(point + 1)));
    for (const item of counts) {
      if (!item.count) continue;
      button.append(pile(
        item.player,
        item.count,
        "is-point",
        `${memberName(item.player.userId)} checkers on point ${point + 1}`,
        point < 12 ? "down" : "up",
        selected && Number(item.player.userId) === currentUserId(),
        source.pointRows[point < 12 ? 0 : 1].height,
      ));
    }
    if (classic && legalDestination) {
      const pointRow = source.pointRows[point < 12 ? 0 : 1];
      const destinationCount = Number(counts.find(item => Number(item.player.userId) === currentUserId())?.count || 0);
      const cue = classicDestinationCue(source.destinationCueAsset, "is-point-source-cue", { width: pointRow.width, height: pointRow.height });
      const occupiedStep = stackStepPercent(destinationCount, pointRow.height);
      const checkerHeight = (25 / Number(pointRow.height)) * 100;
      const cueOffset = destinationCount > 0
        ? (occupiedStep * (destinationCount - 1)) + checkerHeight
        : 0;
      const visibleBounds = source.destinationCueVisibleBounds;
      const hiddenEdge = point < 12
        ? Number(visibleBounds.y)
        : Number(source.destinationCueNaturalSize.height) - Number(visibleBounds.y + visibleBounds.height);
      const visibleEdgeCompensation = (hiddenEdge / Number(pointRow.height)) * 100;
      cue.style.setProperty("--cue-stack-offset", `${Math.max(0, cueOffset - visibleEdgeCompensation)}%`);
      cue.classList.add(point < 12 ? "is-down" : "is-up");
      cue.dataset.cuePlacement = "next-open-stack-slot";
      cue.dataset.cueArtwork = "exact-ocx-rt-cursor-1";
      cue.dataset.cueResourceSha256 = source.destinationCueResourceSha256;
      cue.dataset.cueHotspot = `${source.destinationCueHotspot.x},${source.destinationCueHotspot.y}`;
      configurePointDestinationCueHost(button, pointRow);
      button.append(cue);
    }
    if (!classic) button.append(make("span", "minor", wording));
    bindPointGameAction(button, key, interaction);
    board.append(button);
  }
  const zone = (key, className, label, counts, geometry, ownerUserId = null) => {
    const button = make("button", `point-game-zone ${className}`);
    button.type = "button";
    button.setAttribute("aria-label", label);
    if (classic) setSourceBox(button, geometry, source.canvas.width, source.canvas.height);
    else {
      const playerIndex = className.includes("is-player-2") ? 2 : 1;
      button.classList.add("built-in-point-zone");
      button.dataset.zoneKey = key;
      if (key === "bar") {
        button.style.gridColumn = "8";
        button.style.gridRow = "1 / span 3";
      } else {
        button.style.gridColumn = className.includes("classic-reserve-zone") ? builtInReserveColumn : builtInHomeColumn;
        button.style.gridRow = className.includes("classic-reserve-zone")
          ? (playerIndex === 1 ? "1" : "3")
          : (playerIndex === 1 ? "3" : "1");
      }
      button.append(make("span", "built-in-zone-label", key === "bar" ? "BAR" : className.includes("classic-reserve-zone") ? "RESERVE" : "HOME"));
    }
    counts.forEach(({ player, count, direction }) => {
      if (count > 0) button.append(pile(player, count, className, `${memberName(player.userId)} ${label.toLowerCase()}`, direction, interaction.selectedOrigin === key && Number(player.userId) === currentUserId(), geometry.height, geometry.width));
    });
    if (ownerUserId === null || Number(ownerUserId) === currentUserId()) {
      bindPointGameAction(button, key, interaction);
    } else {
      button.disabled = true;
      button.dataset.actionable = "none";
      button.dataset.legalDestination = "false";
      button.setAttribute("aria-pressed", "false");
    }
    if (classic && interaction.destinations.has(key)
      && (ownerUserId === null || Number(ownerUserId) === currentUserId())) {
      const cue = classicDestinationCue(source.destinationCueAsset, "is-point-source-cue", geometry);
      cue.classList.add("is-zone-cue");
      cue.dataset.cueArtwork = "exact-ocx-rt-cursor-1";
      cue.dataset.cueResourceSha256 = source.destinationCueResourceSha256;
      cue.dataset.cueHotspot = `${source.destinationCueHotspot.x},${source.destinationCueHotspot.y}`;
      configurePointDestinationCueHost(button, geometry);
      button.append(cue);
    }
    board.append(button);
  };
  const barCounts = players.map(player => ({ player, count: Number(state.bar?.[String(player.userId)] || 0), direction: player.index === 0 ? "down" : "up" }));
  zone("bar", "classic-bar-zone", "Bar", barCounts, source.bar);
  const borneCounts = players.map(player => ({ player, count: Number(state.borneOff?.[String(player.userId)] || 0), direction: pointBorneDirection(gameId, player.index) }));
  source.borneOff.forEach((geometry, index) => zone(
    "borne-off",
    `classic-borne-zone is-player-${index + 1}`,
    `${memberName(players[index]?.userId)} bear-off area`,
    players[index] ? [{ player: players[index], count: borneCounts[index]?.count || 0, direction: borneCounts[index]?.direction || pointBorneDirection(gameId, index) }] : [],
    geometry,
    players[index]?.userId,
  ));
  if (isAcey) {
    const offCounts = players.map(player => ({ player, count: Number(state.off?.[String(player.userId)] || 0), direction: player.index === 0 ? "down" : "up" }));
    source.reserves.forEach((geometry, index) => zone(
      "off",
      `classic-reserve-zone is-player-${index + 1}`,
      `${memberName(players[index]?.userId)} off-board reserve`,
      offCounts[index] ? [offCounts[index]] : [],
      geometry,
      players[index]?.userId,
    ));
  }
  if (!classic || !isAcey) {
    const directionRole = classic ? (source.sourceRole === "role-2" ? 1 : 0) : viewerSide;
    const direction = make("div", `point-game-play-direction ${classic ? "is-classic" : "is-built-in"} is-role-${directionRole}`);
    direction.setAttribute("role", "img");
    direction.setAttribute("aria-label", directionRole === 1
      ? "Direction of play: across the top row from right to left, then across the bottom row from left to right."
      : "Direction of play: across the top row from left to right, then across the bottom row from right to left.");
    direction.append(make("span", "sr-only", "Direction of play"));
    board.append(direction);
  }
  const dice = make("div", classic ? "classic-dice" : "built-in-dice");
  if (!classic) dice.setAttribute("role", "group");
  dice.setAttribute("aria-label", `Current dice: ${(state.dice || []).join(", ") || (isAcey && currentAceyRollAgain() ? "none active" : "not rolled")}. Remaining dice: ${(state.remainingDice || []).join(", ") || "none"}.`);
  for (const [index, value] of (state.dice || []).entries()) {
    const die = classic ? pointGameClassicDie(value) : make("span", "built-in-die");
    if (classic) {
      const slot = source.diceSlots?.[index];
      if (slot) setSourceBox(die, slot, source.canvas.width, source.canvas.height);
      die.dataset.dieIndex = String(index + 1);
      die.dataset.dieAspect = "1:1";
    } else {
      const face = Math.max(1, Math.min(6, Math.trunc(Number(value) || 1)));
      const pipPositions = {
        1:[5], 2:[1,9], 3:[1,5,9], 4:[1,3,7,9], 5:[1,3,5,7,9], 6:[1,3,4,6,7,9],
      };
      die.dataset.face = String(face);
      die.setAttribute("role", "img");
      die.setAttribute("aria-label", `Die ${face}`);
      for (let pip = 1; pip <= 9; pip++) {
        const dot = make("i", pipPositions[face].includes(pip) ? "is-visible" : "");
        dot.setAttribute("aria-hidden", "true");
        die.append(dot);
      }
    }
    dice.append(die);
  }
  board.append(dice);
  if (classic) {
    appendClassicMotion(board, gameId);
    appendClassicPlayerAvatars(board, gameId, classicMembersBySourceIdentity(gameId));
  }
  if (isAcey) appendAceyDoubletDialog(board, state);
  return board;
}

function renderAceyDeucy() { return renderPointGame("acey-deucy"); }

function renderBackgammon() { return renderPointGame("backgammon-first-party"); }

function mountBuiltInPointRollAction(button, guidance) {
  const board = el("board-host")?.querySelector(".built-in-point-board");
  const tray = board?.querySelector(".built-in-dice");
  if (!tray) return false;
  button.classList.add("built-in-roll-action");
  if (guidance) {
    guidance.id = `${context.extensionId}-built-in-move-guidance`;
    guidance.className = "sr-only";
    button.setAttribute("aria-describedby", guidance.id);
    tray.append(button, guidance);
  } else tray.append(button);
  return true;
}

function classifyBuiltInPointWin(previous, current) {
  if (!previous || previous.state?.completed || !current?.state?.completed) return null;
  if (safe(current.presentation?.effectivePack || "built-in") === "classic") return null;
  const gameId = context.extensionId;
  if (!["acey-deucy", "backgammon-first-party"].includes(gameId)) return null;
  const before = previous.state || {};
  const after = current.state || {};
  const winnerUserId = Number(after.winnerUserId || 0);
  if (winnerUserId <= 0 && !(gameId === "backgammon-first-party" && after.bots?.[String(winnerUserId)]?.userId === winnerUserId)) return null;
  const latestMove = listLength(after.history) > listLength(before.history) ? latest(after.history) : null;
  const borneOffWin = gameId === "backgammon-first-party"
    ? safe(after.terminalCause).toLowerCase() === "bear-off"
    : latestMove?.to === "borne-off"
      || safe(after.terminalCause).toLowerCase() === "bear-off"
      || safe(after.terminalReason).toLowerCase().includes("borne");
  if (!borneOffWin) return null;
  const turnOrder = Array.isArray(after.turnOrder) ? after.turnOrder.map(Number) : [];
  const winnerIndex = Math.max(0, turnOrder.indexOf(winnerUserId));
  const classification = safe(after.terminalClassification || "").replaceAll("-", " ");
  return {
    gameId,
    version:Number(current.stateVersion || 0),
    winnerUserId,
    winnerIndex,
    winnerName:memberName(winnerUserId),
    detail:classification
      ? `${classification.charAt(0).toUpperCase()}${classification.slice(1)} victory`
      : "All checkers borne off",
  };
}

function stopBuiltInPointWinMotion() {
  clearTimeout(builtInPointWinTimer);
  builtInPointWinTimer = 0;
  pendingBuiltInPointWin = null;
}

function startBuiltInPointWinMotion(motion) {
  stopBuiltInPointWinMotion();
  pendingBuiltInPointWin = motion;
}

function appendBuiltInPointWinMotion(board) {
  const motion = pendingBuiltInPointWin;
  if (!motion || !board?.classList?.contains("built-in-point-board")) return;
  const reduced = !optionCategory("visualFxEnabled", true);
  const overlay = make("div", `built-in-point-win is-player-${motion.winnerIndex + 1}${reduced ? " is-reduced" : ""}`);
  overlay.setAttribute("role", "status");
  overlay.setAttribute("aria-live", "polite");
  overlay.setAttribute("aria-label", `${motion.winnerName} wins. ${motion.detail}.`);
  const finalChecker = make("span", `built-in-point-win-final-checker is-player-${motion.winnerIndex + 1}`);
  finalChecker.setAttribute("aria-hidden", "true");
  const particles = make("div", "built-in-point-win-particles");
  const vectors = [[-220,-78],[-184,-132],[-142,-84],[-96,-156],[-48,-104],[0,-170],[52,-116],[98,-158],[146,-94],[190,-136],[226,-72],[-202,12],[-132,44],[-62,26],[64,32],[136,48],[204,8]];
  vectors.forEach(([x, y], index) => {
    const particle = make("i", `is-particle-${(index % 3) + 1}`);
    particle.style.setProperty("--particle-x", `${x}px`);
    particle.style.setProperty("--particle-y", `${y}px`);
    particle.style.setProperty("--particle-delay", `${(index % 6) * 45}ms`);
    particle.style.setProperty("--particle-spin", `${index % 2 ? 210 : -210}deg`);
    particles.append(particle);
  });
  particles.setAttribute("aria-hidden", "true");
  const champions = make("div", "built-in-point-win-champions");
  for (let index = 0; index < 3; index++) {
    const checker = make("span", `built-in-point-win-checker is-player-${motion.winnerIndex + 1}`);
    checker.style.setProperty("--winner-checker-index", String(index));
    champions.append(checker);
  }
  champions.setAttribute("aria-hidden", "true");
  const plaque = make("div", "built-in-point-win-plaque");
  const dismiss = make("button", "built-in-point-win-dismiss", "Close result");
  dismiss.type = "button";
  dismiss.setAttribute("aria-label", "Close game result");
  dismiss.addEventListener("click", () => {
    stopBuiltInPointWinMotion();
    render();
  });
  plaque.append(
    make("span", "built-in-point-win-kicker", motion.gameId === "acey-deucy" ? "Acey Deucy" : "Backgammon"),
    make("strong", "", `${motion.winnerName} wins`),
    make("span", "", motion.detail),
    dismiss,
  );
  overlay.append(finalChecker, particles, champions, plaque);
  board.append(overlay);
}

function battleshipSafePreviewFleet() {
  return [1,2,3,4,5].map((length, index) => ({
    id:`ship-${length}`,
    length,
    orientation:"horizontal",
    cells:Array.from({length}, (_, offset) => `${index * 2}:${offset}`),
    hits:[],
    preview:true,
  }));
}

function battleshipSelectShip(shipId) {
  selectedBattleshipShipId = selectedBattleshipShipId === shipId ? "" : shipId;
  render();
}

async function battleshipManualPlacement(operation, shipId = "", row = null, column = null) {
  clearTimeout(battleshipSelectionTimer);
  battleshipSelectionTimer = 0;
  const payload = { operation, ...(shipId ? { shipId } : {}) };
  if (Number.isInteger(row) && Number.isInteger(column)) Object.assign(payload, { row, column });
  const succeeded = await performAction("manual-place", payload);
  if (succeeded) selectedBattleshipShipId = "";
  return succeeded;
}

function battleshipVisibleFleet(state, ownerUserId) {
  const fleet = state?.fleets?.[String(ownerUserId)] || {};
  if (Array.isArray(fleet.ships) && fleet.ships.length) return fleet.ships;
  return state?.phase === "placement" && Number(ownerUserId) === currentUserId()
    ? battleshipSafePreviewFleet()
    : [];
}

function renderBattleGrid(ownerUserId, target) {
  const grid = make("div", "battle-grid");
  grid.classList.add(target ? "is-target-grid" : "is-own-grid");
  const classic = session.presentation?.effectivePack === "classic";
  const source = classic ? classicSourceMap("battleship") : null;
  const gridName = target ? "target" : "own";
  const gridGeometry = source?.grids?.[gridName];
  const gridEdges = source?.gridEdges?.[gridName];
  const state = session.state || {};
  const fleet = state.fleets?.[String(ownerUserId)] || {};
  const own = Number(ownerUserId) === currentUserId();
  const attacks = new Map(Object.entries(fleet.attacksReceived || {}));
  const visibleFleet = battleshipVisibleFleet(state, ownerUserId);
  const ships = new Set(visibleFleet.flatMap(ship => ship.cells || []));
  const sunkCells = new Set(visibleFleet
    .filter(ship => (ship.cells || []).length > 0
      && (ship.cells || []).every(cell => ["hit", "sunk"].includes(String(attacks.get(String(cell)) || ""))))
    .flatMap(ship => ship.cells || [])
    .map(String));
  const placementEditable = own && state.phase === "placement" && !fleet.accepted
    && gameLifecycleAvailable() && !busy;
  if (own && state.phase === "placement" && !fleet.placed) grid.dataset.placementPreview = "source-safe-visible-fleet";
  for (let row = 0; row < 10; row++) for (let column = 0; column < 10; column++) {
    const key = `${row}:${column}`;
    const result = attacks.get(key) || "";
    const shipAtCell = visibleFleet.find(ship => (ship.cells || []).includes(key));
    const button = make("button", `battle-cell${own && ships.has(key) ? " ship" : ""}${result ? ` ${result}` : ""}`, classic ? "" : result === "hit" || result === "sunk" ? "×" : result === "miss" ? "•" : "");
    button.type = "button";
    button.dataset.row = String(row);
    button.dataset.column = String(column);
    button.dataset.coordinate = `${String.fromCharCode(65 + column)}${row + 1}`;
    button.setAttribute("aria-label", `${target ? "Opponent" : "Own"} grid ${String.fromCharCode(65 + column)}${row + 1}${result ? `, ${result}` : ""}`);
    button.disabled = !target || !canAct() || session.state?.phase !== "battle" || Boolean(result);
    if (placementEditable) {
      const coordinateLabel = `${String.fromCharCode(65 + column)}${row + 1}`;
      button.disabled = !selectedBattleshipShipId && !shipAtCell;
      if (selectedBattleshipShipId) {
        button.setAttribute("aria-label", `Move ${selectedBattleshipShipId.replace("ship-", "length ")} ship to ${coordinateLabel}`);
      } else if (shipAtCell) {
        button.setAttribute("aria-label", `Select length ${shipAtCell.length} ship at ${coordinateLabel}`);
      }
    }
    let cellBox = null;
    if (classic && gridEdges && gridGeometry) {
      cellBox = {
        x: gridEdges.x[column], y: gridEdges.y[row],
        width: gridEdges.x[column + 1] - gridEdges.x[column],
        height: gridEdges.y[row + 1] - gridEdges.y[row],
      };
      setNestedSourceBox(button, cellBox, gridGeometry);
      button.dataset.sourceCell = `${gridName}:${row}:${column}`;
      button.dataset.sourceCellCenter = `${cellBox.x + cellBox.width / 2},${cellBox.y + cellBox.height / 2}`;
    }
    let persistedResult = null;
    if (classic && result && cellBox && gridGeometry) {
      const shouldAnimate = optionCategory("visualFxEnabled", true);
      if (result === "miss") {
        const markerDefinition = source.motion.impacts.marker;
        persistedResult = sourceStripFinalFrame(
          markerDefinition.slot,
          markerDefinition,
          "classic-battleship-persisted-result is-miss",
        );
        const anchor = battleshipNativeAnchor(gridName, row, column);
        setNestedSourceBox(persistedResult, {x:anchor.x + 4, y:anchor.y + 4, ...markerDefinition.nativeSize}, gridGeometry);
      } else if (result === "hit" && !sunkCells.has(key)) {
        const hitDefinition = { ...source.motion.impacts.hit, frameDurationMs:source.motion.shot.frameDurationMs };
        persistedResult = persistentSourceStripNode(
          hitDefinition.slot,
          hitDefinition,
          "classic-battleship-persisted-result is-hit",
          shouldAnimate,
        );
        setNestedSourceBox(persistedResult, {...battleshipNativeAnchor(gridName, row, column), ...hitDefinition.nativeSize}, gridGeometry);
      }
      if (persistedResult) {
        persistedResult.dataset.attackResult = result;
        persistedResult.dataset.sourceCell = `${gridName}:${row}:${column}`;
      }
    }
    if (target) button.addEventListener("click", () => performAction("attack", { row, column }));
    else if (placementEditable) {
      button.addEventListener("click", () => {
        if (selectedBattleshipShipId) battleshipManualPlacement("relocate", selectedBattleshipShipId, row, column);
        else if (shipAtCell) battleshipSelectShip(String(shipAtCell.id || `ship-${shipAtCell.length}`));
      });
    }
    grid.append(button);
    if (persistedResult) grid.append(persistedResult);
  }
  if (classic) {
    for (const ship of visibleFleet) {
      const cells = (ship.cells || [])
        .map(cell => String(cell).split(":").map(Number))
        .filter(cell => cell.length === 2 && cell.every(Number.isInteger));
      if (!cells.length) continue;
      const rows = cells.map(cell => cell[0]);
      const columns = cells.map(cell => cell[1]);
      const horizontal = new Set(rows).size === 1;
      const length = cells.length;
      const sunk = cells.every(cell => ["hit", "sunk"].includes(String(attacks.get(`${cell[0]}:${cell[1]}`) || "")));
      if (!own && !sunk) continue;
      const shipBox = battleshipShipSourceBox(ship, gridName);
      if (!shipBox) continue;
      let shipImage;
      if (sunk) {
        const wreck = battleshipWreckDefinition(ship);
        if (!wreck) continue;
        shipImage = sourceStripFinalFrame(wreck.slot, wreck, `classic-native-wreck ${horizontal ? "is-horizontal" : "is-vertical"}`);
        shipImage.dataset.shipSlot = wreck.slot;
        setNestedSourceBox(shipImage, battleshipNativeWreckBox(ship, gridName), gridGeometry);
      } else {
        const slot = length === 1 ? "gif-1" : `gif-${length}-${horizontal ? "h" : "v"}`;
        shipImage = mediaImage(slot, `classic-battle-ship ${horizontal ? "is-horizontal" : "is-vertical"}`, "");
        shipImage.decoding = "sync";
        shipImage.dataset.shipSlot = slot;
      }
      shipImage.dataset.shipLength = String(length);
      shipImage.dataset.shipCells = cells.map(cell => `${cell[0]}:${cell[1]}`).join(" ");
      if (gridEdges && gridGeometry) {
        shipImage.dataset.sourceShipBox = `${shipBox.x},${shipBox.y},${shipBox.width},${shipBox.height}`;
      }
      if (placementEditable && !sunk) {
        const shipId = String(ship.id || `ship-${length}`);
        const control = make("button", `classic-battle-ship-control${selectedBattleshipShipId === shipId ? " is-selected" : ""}`);
        control.type = "button";
        control.dataset.shipId = shipId;
        control.dataset.shipLength = String(length);
        control.dataset.shipOrientation = horizontal ? "horizontal" : "vertical";
        control.setAttribute("aria-pressed", selectedBattleshipShipId === shipId ? "true" : "false");
        control.setAttribute("aria-label", `Length ${length} ship, ${horizontal ? "horizontal" : "vertical"}. Click to select. Double-click, double-tap, or press Enter or Space to rotate.`);
        setNestedSourceBox(control, shipBox, gridGeometry);
        control.addEventListener("click", event => {
          if (performance.now() < battleshipSuppressClickUntil || event.detail > 1) return;
          clearTimeout(battleshipSelectionTimer);
          battleshipSelectionTimer = setTimeout(() => {
            battleshipSelectionTimer = 0;
            battleshipSelectShip(shipId);
          }, 360);
        });
        control.addEventListener("dblclick", event => {
          event.preventDefault();
          clearTimeout(battleshipSelectionTimer);
          battleshipSelectionTimer = 0;
          battleshipManualPlacement("rotate", shipId);
        });
        control.addEventListener("pointerup", event => {
          if (event.pointerType !== "touch") return;
          event.preventDefault();
          const now = performance.now();
          battleshipSuppressClickUntil = now + 700;
          if (battleshipLastTouchShipId === shipId && now - battleshipLastTouchAt <= 650) {
            battleshipLastTouchShipId = "";
            battleshipLastTouchAt = 0;
            battleshipManualPlacement("rotate", shipId);
          } else {
            battleshipLastTouchShipId = shipId;
            battleshipLastTouchAt = now;
            selectedBattleshipShipId = shipId;
            render();
          }
        });
        control.addEventListener("keydown", event => {
          if (!["Enter", " "].includes(event.key)) return;
          event.preventDefault();
          battleshipManualPlacement("rotate", shipId);
        });
        control.append(shipImage);
        grid.append(control);
      } else {
        if (!sunk) setNestedSourceBox(shipImage, shipBox, gridGeometry);
        grid.append(shipImage);
      }
    }
  }
  return grid;
}

const BUILT_IN_BATTLESHIP_ART_ROOT = "assets/images/battleship-modern";
const BUILT_IN_BATTLESHIP_SHIP_ART = Object.freeze({
  1: { name: "Attack craft", intact: "naval-attack-craft-1.png", destroyed: "naval-attack-craft-1-destroyed.png" },
  2: { name: "Corvette", intact: "naval-corvette-2.png", destroyed: "naval-corvette-2-destroyed.png" },
  3: { name: "Frigate", intact: "naval-frigate-3.png", destroyed: "naval-frigate-3-destroyed.png" },
  4: { name: "Cruiser", intact: "naval-cruiser-4.png", destroyed: "naval-cruiser-4-destroyed.png" },
  5: { name: "Command ship", intact: "naval-command-ship.png", destroyed: "naval-command-ship-destroyed.png" },
});

function builtInBattleshipArtUrl(filename) {
  return appUrl(`${BUILT_IN_BATTLESHIP_ART_ROOT}/${filename}`);
}

function builtInBattleshipShipCells(ship) {
  return (ship?.cells || [])
    .map(cell => String(cell).split(":").map(Number))
    .filter(cell => cell.length === 2 && cell.every(Number.isInteger));
}

function builtInBattleshipShipGeometry(ship) {
  const cells = builtInBattleshipShipCells(ship);
  if (!cells.length) return null;
  const rows = cells.map(cell => cell[0]);
  const columns = cells.map(cell => cell[1]);
  const horizontal = new Set(rows).size === 1;
  return {
    cells,
    horizontal,
    row: Math.min(...rows),
    column: Math.min(...columns),
    width: horizontal ? cells.length : 1,
    height: horizontal ? 1 : cells.length,
  };
}

function builtInBattleshipShipSunk(ship, attacks) {
  const cells = builtInBattleshipShipCells(ship);
  return cells.length > 0 && cells.every(([row, column]) =>
    ["hit", "sunk"].includes(String(attacks.get(`${row}:${column}`) || ""))
  );
}

function builtInBattleshipFireNode(cellIndex = 0, dramatic = false) {
  const fire = make("span", `modern-battle-hit-fire${dramatic ? " is-impact" : ""}`);
  fire.setAttribute("aria-hidden", "true");
  fire.style.setProperty("--fire-phase", `${-((cellIndex * .173) % 1.19).toFixed(2)}s`);
  const plume = make("img", "modern-battle-fire-sprite");
  plume.src = builtInBattleshipArtUrl("naval-fire-plume-v2.png");
  plume.alt = "";
  plume.decoding = "async";
  const smoke = make("span", "modern-battle-fire-smoke");
  for (let index = 0; index < 3; index += 1) smoke.append(make("i", `is-smoke-${index + 1}`));
  const embers = make("span", "modern-battle-fire-embers");
  for (let index = 0; index < 5; index += 1) embers.append(make("i", `is-ember-${index + 1}`));
  fire.append(plume, smoke, embers);
  return fire;
}

function builtInBattleshipAftermathNode() {
  const aftermath = make("span", "modern-battle-wreck-aftermath");
  const smoke = make("span", "modern-battle-wreck-smoke");
  const bubbles = make("span", "modern-battle-wreck-bubbles");
  for (let index = 0; index < 8; index += 1) smoke.append(make("i"));
  for (let index = 0; index < 6; index += 1) bubbles.append(make("i"));
  aftermath.append(
    make("span", "modern-battle-wreck-water"),
    make("span", "modern-battle-wreck-oil"),
    make("span", "modern-battle-breach-glow is-first"),
    make("span", "modern-battle-breach-glow is-second"),
    smoke,
    bubbles,
  );
  aftermath.setAttribute("aria-hidden", "true");
  return aftermath;
}

function builtInBattleshipLocallyLegal(ship, row, column, fleet) {
  const geometry = builtInBattleshipShipGeometry(ship);
  if (!geometry) return false;
  const length = Number(ship.length || geometry.cells.length || 0);
  const candidate = Array.from({ length }, (_, offset) => [
    row + (geometry.horizontal ? 0 : offset),
    column + (geometry.horizontal ? offset : 0),
  ]);
  if (candidate.some(([candidateRow, candidateColumn]) =>
    candidateRow < 0 || candidateRow > 9 || candidateColumn < 0 || candidateColumn > 9
  )) return false;
  const occupied = new Set((fleet || [])
    .filter(other => Number(other.length) !== Number(ship.length))
    .flatMap(other => builtInBattleshipShipCells(other).map(([otherRow, otherColumn]) => `${otherRow}:${otherColumn}`)));
  if (candidate.some(([candidateRow, candidateColumn]) => occupied.has(`${candidateRow}:${candidateColumn}`))) return false;
  if (session?.state?.settings?.shipsMayTouch === true) return true;
  for (const [candidateRow, candidateColumn] of candidate) {
    for (let rowOffset = -1; rowOffset <= 1; rowOffset += 1) {
      for (let columnOffset = -1; columnOffset <= 1; columnOffset += 1) {
        if (occupied.has(`${candidateRow + rowOffset}:${candidateColumn + columnOffset}`)) return false;
      }
    }
  }
  return true;
}

function beginBuiltInBattleshipDrag(event, control, ship, fleet, grid) {
  if (event.button !== 0 || busy) return;
  const geometry = builtInBattleshipShipGeometry(ship);
  if (!geometry) return;
  const rect = grid.getBoundingClientRect();
  if (rect.width <= 0 || rect.height <= 0) return;
  const pointerColumn = Math.max(0, Math.min(9, Math.floor((event.clientX - rect.left) / rect.width * 10)));
  const pointerRow = Math.max(0, Math.min(9, Math.floor((event.clientY - rect.top) / rect.height * 10)));
  const state = {
    pointerId: event.pointerId,
    ship,
    control,
    grid,
    startX: event.clientX,
    startY: event.clientY,
    offsetRow: Math.max(0, pointerRow - geometry.row),
    offsetColumn: Math.max(0, pointerColumn - geometry.column),
    moved: false,
    legal: true,
    row: geometry.row,
    column: geometry.column,
  };
  builtInBattleshipDragState = state;
  const move = moveEvent => {
    if (builtInBattleshipDragState !== state || moveEvent.pointerId !== state.pointerId) return;
    const distance = Math.hypot(moveEvent.clientX - state.startX, moveEvent.clientY - state.startY);
    if (!state.moved && distance < 4) return;
    state.moved = true;
    moveEvent.preventDefault();
    const currentRect = grid.getBoundingClientRect();
    const cellColumn = Math.floor((moveEvent.clientX - currentRect.left) / currentRect.width * 10);
    const cellRow = Math.floor((moveEvent.clientY - currentRect.top) / currentRect.height * 10);
    const maxRow = geometry.horizontal ? 9 : 10 - Number(ship.length || geometry.height);
    const maxColumn = geometry.horizontal ? 10 - Number(ship.length || geometry.width) : 9;
    state.row = Math.max(0, Math.min(maxRow, cellRow - state.offsetRow));
    state.column = Math.max(0, Math.min(maxColumn, cellColumn - state.offsetColumn));
    state.legal = builtInBattleshipLocallyLegal(ship, state.row, state.column, fleet);
    control.style.setProperty("--ship-row", String(state.row));
    control.style.setProperty("--ship-column", String(state.column));
    control.classList.add("is-dragging");
    control.classList.toggle("is-invalid", !state.legal);
  };
  const finish = finishEvent => {
    if (builtInBattleshipDragState !== state || finishEvent.pointerId !== state.pointerId) return;
    cleanup();
    if (!state.moved) {
      if (finishEvent.pointerType === "touch") {
        const now = performance.now();
        const shipId = String(ship.id || `ship-${ship.length}`);
        if (battleshipLastTouchShipId === shipId && now - battleshipLastTouchAt <= 650) {
          battleshipSuppressClickUntil = now + 700;
          battleshipLastTouchShipId = "";
          battleshipLastTouchAt = 0;
          void battleshipManualPlacement("rotate", shipId);
        } else {
          battleshipLastTouchShipId = shipId;
          battleshipLastTouchAt = now;
        }
      }
      return;
    }
    battleshipSuppressClickUntil = performance.now() + 700;
    if (!state.legal) {
      playBuiltInGameSound(BUILT_IN_PUBLIC_SOUNDS.battleshipInvalidPlacement, "client-placement-preview-invalid", .8);
      render();
      return;
    }
    void battleshipManualPlacement("relocate", String(ship.id || `ship-${ship.length}`), state.row, state.column);
  };
  const cancel = cancelEvent => {
    if (builtInBattleshipDragState !== state || cancelEvent.pointerId !== state.pointerId) return;
    cleanup();
    render();
  };
  const cleanup = () => {
    if (builtInBattleshipDragState === state) builtInBattleshipDragState = null;
    window.removeEventListener("pointermove", move);
    window.removeEventListener("pointerup", finish);
    window.removeEventListener("pointercancel", cancel);
    control.classList.remove("is-dragging", "is-invalid");
  };
  state.cleanup = cleanup;
  window.addEventListener("pointermove", move, { passive: false });
  window.addEventListener("pointerup", finish);
  window.addEventListener("pointercancel", cancel);
}

function builtInBattleshipPlayerPlate(userId, sideLabel) {
  const member = (session.members || []).find(candidate => Number(candidate.userId) === Number(userId)) || {};
  const record = session.state?.headToHeadRecord || {};
  const wins = Math.max(0, Number(record.winsByUserId?.[String(userId)] || 0));
  const losses = Math.max(0, Number(record.lossesByUserId?.[String(userId)] || 0));
  const plate = make("div", `modern-battle-player-plate${Number(session.turnUserId || 0) === Number(userId) ? " is-active" : ""}`);
  plate.append(memberAvatar(member, "modern-battle-avatar"));
  const copy = make("span", "modern-battle-player-copy");
  copy.append(make("small", "", sideLabel), make("strong", "", member.displayName || `Player ${userId}`));
  const series = make("span", "modern-battle-series");
  series.setAttribute("aria-label", `${wins} wins and ${losses} losses`);
  series.append(make("b", "", String(wins).padStart(2, "0")), make("i", ""), make("b", "", String(losses).padStart(2, "0")));
  plate.append(copy, series);
  return plate;
}

function builtInBattleshipMotionForGrid(ownerUserId) {
  const motion = pendingClassicMotion;
  if (!motion || motion.gameId !== "battleship" || !motion.attack) return null;
  if (Number(motion.attack.targetUserId || 0) !== Number(ownerUserId)) return null;
  const duration = Number(BUILT_IN_MODERN_MOTION_MS[motion.type] || 0);
  const elapsed = Math.max(0, performance.now() - Number(motion.startedAt || performance.now()));
  if (!duration || elapsed >= duration || !optionCategory("visualFxEnabled", true)) return null;
  return { ...motion, elapsed, duration };
}

function appendBuiltInBattleshipAttackMotion(grid, motion) {
  const [row, column] = String(motion.attack?.cell || "").split(":").map(Number);
  if (![row, column].every(Number.isInteger)) return;
  const targetX = column * 10 + 5;
  const targetY = row * 10 + 5;
  const launchX = targetX <= 50 ? 0 : 100;
  const angle = Math.atan2(targetY, targetX - launchX) * 180 / Math.PI - 90;
  const reticle = make("span", "modern-battle-reticle");
  reticle.style.left = `${targetX}%`;
  reticle.style.top = `${targetY}%`;
  const missile = make("span", "modern-battle-missile");
  missile.style.setProperty("--launch-x", `${launchX}%`);
  missile.style.setProperty("--launch-y", "0%");
  missile.style.setProperty("--target-x", `${targetX}%`);
  missile.style.setProperty("--target-y", `${targetY}%`);
  missile.style.setProperty("--missile-angle", `${angle.toFixed(2)}deg`);
  missile.style.setProperty("--motion-offset", `${-Math.min(1600, motion.elapsed)}ms`);
  missile.append(make("i", ""), make("b", ""));
  reticle.setAttribute("aria-hidden", "true");
  missile.setAttribute("aria-hidden", "true");
  grid.append(reticle, missile);
}

function builtInBattleshipVictoryShipYAt(launchSeconds, shipDelay) {
  const progress = Math.max(0, Math.min(1, (launchSeconds - shipDelay) / 14.35));
  return -40 + progress * 150;
}

function appendBuiltInBattleshipVictorySalvo(grid, targetAttacks, motion) {
  if (motion?.type !== "battleship-win" || Number(session.state?.winnerUserId || 0) !== currentUserId()) return;
  const ownFleet = session.state?.fleets?.[String(currentUserId())] || {};
  const ownAttacks = new Map(Object.entries(ownFleet.attacksReceived || {}));
  const survivors = (ownFleet.ships || []).filter(ship => !builtInBattleshipShipSunk(ship, ownAttacks));
  if (!survivors.length) return;
  const elapsedSeconds = Number(motion.elapsed || 0) / 1000;
  const salvo = make("span", "modern-battle-victory-salvo");
  const fleet = make("span", "modern-battle-victory-fleet");
  const positions = survivors.map((ship, index) => 18 + (64 * (index + 1) / (survivors.length + 1)));
  survivors.forEach((ship, index) => {
    const length = Number(ship.length || 1);
    const art = BUILT_IN_BATTLESHIP_SHIP_ART[length] || BUILT_IN_BATTLESHIP_SHIP_ART[1];
    const shipNode = make("span", "modern-battle-victory-ship");
    shipNode.style.setProperty("--fleet-x", `${positions[index]}%`);
    shipNode.style.setProperty("--fleet-delay", `${Math.max(-14.35, index * .2 - elapsedSeconds)}s`);
    shipNode.style.setProperty("--fleet-width", `${7 + length * 1.25}%`);
    shipNode.style.setProperty("--fleet-height", `${15 + length * 6.2}%`);
    const image = make("img", "");
    image.src = builtInBattleshipArtUrl(art.intact);
    image.alt = "";
    shipNode.append(image, make("i", ""));
    fleet.append(shipNode);
  });
  salvo.append(make("span", "modern-battle-victory-sweep"), fleet);
  const unmarked = [];
  for (let row = 0; row < 10; row += 1) for (let column = 0; column < 10; column += 1) {
    if (!targetAttacks.has(`${row}:${column}`)) unmarked.push({ row, column });
  }
  unmarked.forEach((cell, index) => {
    const launchSeconds = 3.75 + (unmarked.length <= 1 ? 0 : index / (unmarked.length - 1) * 9.35);
    const shipIndex = index % survivors.length;
    const shipDelay = shipIndex * .2;
    const sourceX = positions[shipIndex];
    const sourceY = builtInBattleshipVictoryShipYAt(launchSeconds, shipDelay);
    const targetX = cell.column * 10 + 5;
    const targetY = cell.row * 10 + 5;
    const angle = Math.atan2(targetY - sourceY, targetX - sourceX) * 180 / Math.PI - 90;
    const shot = make("span", "modern-battle-victory-shot");
    shot.style.setProperty("--salvo-source-x", `${sourceX}%`);
    shot.style.setProperty("--salvo-source-y", `${sourceY}%`);
    shot.style.setProperty("--salvo-target-x", `${targetX}%`);
    shot.style.setProperty("--salvo-target-y", `${targetY}%`);
    shot.style.setProperty("--salvo-angle", `${angle.toFixed(2)}deg`);
    shot.style.setProperty("--salvo-delay", `${Math.max(-.74, launchSeconds - elapsedSeconds)}s`);
    shot.append(make("i", ""), make("b", ""));
    salvo.append(shot);
  });
  salvo.setAttribute("aria-hidden", "true");
  grid.append(salvo);
}

function appendBuiltInBattleshipResult(grid, opponentUserId, motion) {
  const state = session.state || {};
  if (!state.completed) return;
  const resultKey = `${context.gameSessionId}:${Number(session.stateVersion || 0)}:${Number(state.winnerUserId || 0)}`;
  if (dismissedBuiltInBattleshipResultKey === resultKey) return;
  const viewerWon = Number(state.winnerUserId || 0) === currentUserId();
  const overlay = make("button", `modern-battle-result is-${viewerWon ? "victory" : "defeat"}`);
  overlay.type = "button";
  overlay.setAttribute("aria-live", "assertive");
  overlay.setAttribute("aria-label", `${viewerWon ? "Victory" : "Defeat"}. ${viewerWon ? "Enemy" : "Your"} fleet destroyed. Dismiss result.`);
  const visualFx = optionCategory("visualFxEnabled", true);
  const waitMs = visualFx && motion
    ? Math.max(0, (viewerWon ? 23800 : 9700) - Number(motion.elapsed || 0))
    : 0;
  overlay.style.setProperty("--result-delay", `${waitMs}ms`);
  overlay.append(
    make("small", "", viewerWon ? "Enemy fleet destroyed" : "Your fleet was destroyed"),
    make("strong", "", viewerWon ? "Victory" : "Defeat"),
    make("span", "", viewerWon ? `${memberName(currentUserId())} controls the sea.` : `${memberName(opponentUserId)} controls the sea.`),
    make("em", "", "Click to dismiss"),
  );
  overlay.addEventListener("click", () => {
    dismissedBuiltInBattleshipResultKey = resultKey;
    render();
  });
  grid.append(overlay);
}

function renderBuiltInBattleshipGrid(ownerUserId, target) {
  const state = session.state || {};
  const fleet = state.fleets?.[String(ownerUserId)] || {};
  const own = Number(ownerUserId) === currentUserId();
  const attacks = new Map(Object.entries(fleet.attacksReceived || {}));
  const visibleFleet = battleshipVisibleFleet(state, ownerUserId);
  const placementEditable = own && state.phase === "placement" && !fleet.accepted
    && gameLifecycleAvailable() && !busy;
  const motion = builtInBattleshipMotionForGrid(ownerUserId);
  const motionCell = String(motion?.attack?.cell || "");
  const frame = make("div", `modern-battle-frame${target ? " is-target" : " is-own"}`);
  const columnAxis = make("span", "modern-battle-axis is-columns");
  const rowAxis = make("span", "modern-battle-axis is-rows");
  for (let column = 0; column < 10; column += 1) columnAxis.append(make("i", "", String.fromCharCode(65 + column)));
  for (let row = 0; row < 10; row += 1) rowAxis.append(make("i", "", String(row + 1)));
  const grid = make("div", "modern-battle-ocean-grid");
  grid.setAttribute("role", "grid");
  grid.setAttribute("aria-label", target ? "Opponent target grid" : "Your fleet grid");
  for (let row = 0; row < 10; row += 1) for (let column = 0; column < 10; column += 1) {
    const key = `${row}:${column}`;
    const result = String(attacks.get(key) || "");
    const shipAtCell = visibleFleet.find(ship => (ship.cells || []).includes(key));
    const shipSunk = shipAtCell ? builtInBattleshipShipSunk(shipAtCell, attacks) : false;
    const cell = make("button", `modern-battle-cell${result ? ` is-${result}` : ""}${motionCell === key ? " is-current-target" : ""}`);
    cell.type = "button";
    cell.dataset.row = String(row);
    cell.dataset.column = String(column);
    cell.dataset.coordinate = `${String.fromCharCode(65 + column)}${row + 1}`;
    cell.setAttribute("role", "gridcell");
    cell.setAttribute("aria-label", `${target ? "Opponent" : "Own"} grid ${cell.dataset.coordinate}${result ? `, ${result}` : ""}`);
    cell.disabled = target
      ? !canAct() || state.phase !== "battle" || Boolean(result)
      : !placementEditable || Boolean(shipAtCell) || !selectedBattleshipShipId;
    if (target) {
      cell.addEventListener("click", () => performAction("attack", { row, column }));
    } else if (placementEditable) {
      cell.addEventListener("click", () => {
        if (selectedBattleshipShipId) void battleshipManualPlacement("relocate", selectedBattleshipShipId, row, column);
        else if (shipAtCell) battleshipSelectShip(String(shipAtCell.id || `ship-${shipAtCell.length}`));
      });
    }
    if (result === "miss") {
      const marker = make("img", "modern-battle-miss-marker");
      marker.src = builtInBattleshipArtUrl("broken-missile-miss.png");
      marker.alt = "";
      marker.setAttribute("aria-hidden", "true");
      cell.append(marker);
    } else if (["hit", "sunk"].includes(result)
      && (!shipSunk || (motionCell === key && Number(motion?.elapsed || 0) < 6400))) {
      cell.append(builtInBattleshipFireNode(row * 10 + column, motionCell === key));
    }
    grid.append(cell);
  }
  for (const ship of visibleFleet) {
    const geometry = builtInBattleshipShipGeometry(ship);
    if (!geometry) continue;
    const sunk = builtInBattleshipShipSunk(ship, attacks);
    if (!own && !sunk) continue;
    const length = Number(ship.length || geometry.cells.length || 1);
    const art = BUILT_IN_BATTLESHIP_SHIP_ART[length] || BUILT_IN_BATTLESHIP_SHIP_ART[1];
    const shipId = String(ship.id || `ship-${length}`);
    const shipNode = make(placementEditable && !sunk ? "button" : "span", `modern-battle-ship is-${geometry.horizontal ? "horizontal" : "vertical"}${length === 1 ? " is-single" : ""}${sunk ? " is-sunk" : ""}${selectedBattleshipShipId === shipId ? " is-selected" : ""}`);
    if (shipNode instanceof HTMLButtonElement) shipNode.type = "button";
    shipNode.dataset.shipId = shipId;
    shipNode.style.setProperty("--ship-row", String(geometry.row));
    shipNode.style.setProperty("--ship-column", String(geometry.column));
    shipNode.style.setProperty("--ship-width", String(geometry.width));
    shipNode.style.setProperty("--ship-height", String(geometry.height));
    shipNode.style.setProperty("--ship-cells", String(length));
    const sinking = sunk && motion?.attack && geometry.cells.some(([row, column]) => `${row}:${column}` === motionCell);
    if (sinking) {
      shipNode.classList.add("is-sinking");
      shipNode.style.setProperty("--sink-delay", `${(6400 - motion.elapsed) / 1000}s`);
    }
    if (sinking) {
      const intactImage = make("img", "modern-battle-ship-art is-intact-transition");
      intactImage.src = builtInBattleshipArtUrl(art.intact);
      intactImage.alt = "";
      intactImage.draggable = false;
      shipNode.append(intactImage);
    }
    const image = make("img", `modern-battle-ship-art${sinking ? " is-destroyed-transition" : ""}`);
    image.src = builtInBattleshipArtUrl(sunk ? art.destroyed : art.intact);
    image.alt = "";
    image.draggable = false;
    shipNode.append(image);
    if (sunk) shipNode.append(builtInBattleshipAftermathNode());
    if (placementEditable && !sunk) {
      shipNode.setAttribute("aria-pressed", selectedBattleshipShipId === shipId ? "true" : "false");
      shipNode.setAttribute("aria-label", `${art.name}, ${length} ${length === 1 ? "cell" : "cells"}, ${geometry.horizontal ? "horizontal" : "vertical"}. Drag to move, click to select or unselect, and double click or press Enter or Space to rotate.`);
      shipNode.addEventListener("click", event => {
        if (performance.now() < battleshipSuppressClickUntil || event.detail > 1) return;
        battleshipSelectShip(shipId);
      });
      shipNode.addEventListener("dblclick", event => {
        event.preventDefault();
        battleshipSuppressClickUntil = performance.now() + 700;
        void battleshipManualPlacement("rotate", shipId);
      });
      shipNode.addEventListener("pointerdown", event => beginBuiltInBattleshipDrag(event, shipNode, ship, visibleFleet, grid));
      shipNode.addEventListener("keydown", event => {
        if (!["Enter", " "].includes(event.key)) return;
        event.preventDefault();
        void battleshipManualPlacement("rotate", shipId);
      });
    } else {
      shipNode.setAttribute("aria-label", `${art.name}${sunk ? ", sunk" : ""}`);
    }
    grid.append(shipNode);
  }
  if (motion) appendBuiltInBattleshipAttackMotion(grid, motion);
  appendBuiltInBattleshipVictorySalvo(grid, attacks, motion);
  if (target) appendBuiltInBattleshipResult(grid, ownerUserId, motion);
  frame.append(columnAxis, rowAxis, grid);
  return frame;
}

function renderBuiltInBattleshipPlacementControls(ownFleet) {
  if (String(session.state?.phase || "") !== "placement") return null;
  const accepted = ownFleet?.accepted === true;
  const toolbar = make("section", "modern-battle-placement-controls");
  toolbar.setAttribute("aria-label", "Fleet placement controls");
  const label = make("strong", "modern-battle-placement-label", accepted ? "Fleet ready" : "Arrange your fleet");
  const actions = make("div", "modern-battle-placement-actions");
  const rotate = make("button", "modern-battle-placement-action is-rotate", "Rotate ship");
  rotate.type = "button";
  rotate.disabled = !gameLifecycleAvailable() || accepted || !selectedBattleshipShipId;
  rotate.addEventListener("click", () => battleshipManualPlacement("rotate", selectedBattleshipShipId));
  const random = make("button", "modern-battle-placement-action is-random", "Random placement");
  random.type = "button";
  random.disabled = !gameLifecycleAvailable() || accepted;
  random.addEventListener("click", () => performAction("auto-place", {}, "battleship-auto-placement"));
  const start = make("button", "modern-battle-placement-action is-start", accepted ? "Waiting for opponent" : "Start Game");
  start.type = "button";
  start.disabled = !gameLifecycleAvailable() || !ownFleet?.placed || accepted;
  start.addEventListener("click", () => performAction("start"));
  actions.append(rotate, random, start);
  toolbar.append(label, actions);
  return toolbar;
}

function renderBattleship() {
  if (session.presentation?.effectivePack === "classic") {
    const source = classicSourceMap("battleship");
    const stage = classicStage("classic-board", "battleship-classic");
    applyClassicBoardScale(stage, source);
    const opponent = (session.state?.turnOrder || []).find(userId => Number(userId) !== currentUserId());
    const own = renderBattleGrid(currentUserId(), false);
    own.classList.add("classic-battle-grid", "is-own");
    setSourceBox(own, source.grids.own, source.canvas.width, source.canvas.height);
    const target = renderBattleGrid(opponent, true);
    target.classList.add("classic-battle-grid", "is-target");
    setSourceBox(target, source.grids.target, source.canvas.width, source.canvas.height);
    const activeTerminalMotion = pendingClassicMotion?.gameId === "battleship"
      && pendingClassicMotion?.type === "battleship-win"
      && optionCategory("visualFxEnabled", true);
    const terminalElapsed = activeTerminalMotion
      ? performance.now() - Number(pendingClassicMotion.startedAt || performance.now())
      : Number.POSITIVE_INFINITY;
    const terminalSettled = !activeTerminalMotion
      || terminalElapsed >= motionLength("battleship", "battleship-win");
    const ownFleet = session.state?.fleets?.[String(currentUserId())] || {};
    const statusSlot = session.state?.completed
      ? terminalSettled ? "gif-end" : "gif-m-wait"
      : session.state?.phase === "placement"
        ? ownFleet.accepted ? "gif-m-wait" : "gif-m-place"
        : Number(session.turnUserId || 0) === currentUserId() ? "gif-m-select" : "gif-m-oppon";
    const status = mediaImage(statusSlot, "classic-battle-status", "");
    status.alt = session.state?.phase === "placement" && ownFleet.accepted
      ? "Wait for opponent"
      : session.state?.phase === "placement" ? "Place ships and start game" : "";
    status.dataset.statusOwner = session.state?.phase === "placement" ? "automatic-readiness-feedback" : "game-state";
    setSourceBox(status, source.status, source.canvas.width, source.canvas.height);
    const totals = make("div", "classic-battle-records");
    const headToHeadRecord = session.state?.headToHeadRecord || {};
    const viewerRecordKey = String(currentUserId());
    const recordWins = Math.max(0, Number(headToHeadRecord.winsByUserId?.[viewerRecordKey] || 0));
    const recordLosses = Math.max(0, Number(headToHeadRecord.lossesByUserId?.[viewerRecordKey] || 0));
    totals.dataset.recordsOwner = "server-explicit-rematch-series";
    totals.dataset.roundsCompleted = String(Math.max(0, Number(headToHeadRecord.roundsCompleted || 0)));
    totals.dataset.resetReason = safe(headToHeadRecord.resetReason || "");
    totals.setAttribute("aria-label", `${recordWins} wins and ${recordLosses} losses`);
    const wins = make("span", "is-wins", String(recordWins).padStart(2, "0"));
    const losses = make("span", "is-losses", String(recordLosses).padStart(2, "0"));
    setSourceBox(wins, source.scoreCells.wins, source.canvas.width, source.canvas.height);
    setSourceBox(losses, source.scoreCells.losses, source.canvas.width, source.canvas.height);
    totals.append(wins, losses);
    stage.append(own, target, status, totals);
    appendClassicMotion(stage, "battleship");
    appendPersistedClassicTerminal(stage, "battleship");
    appendClassicPlayerAvatars(stage, "battleship", classicMembersBySourceIdentity("battleship"));
    return stage;
  }
  const host = make("div", "battle-grids built-in-battle-station built-in-battleship-modern");
  const opponent = (session.state?.turnOrder || []).find(userId => Number(userId) !== currentUserId());
  const ownFleet = session.state?.fleets?.[String(currentUserId())] || {};
  const targetWrap = make("section", "battle-panel modern-battle-panel is-target-panel");
  const targetHeader = make("header", "modern-battle-panel-header");
  targetHeader.append(
    builtInBattleshipPlayerPlate(opponent, "Opponent"),
    make("span", "modern-battle-lock", session.state?.phase === "battle"
      ? Number(session.turnUserId || 0) === currentUserId() ? "Target lock ready" : "Opponent firing"
      : "Target grid secured"),
  );
  targetWrap.append(targetHeader, renderBuiltInBattleshipGrid(opponent, true));
  const ownWrap = make("section", "battle-panel modern-battle-panel is-own-panel");
  const ownHeader = make("header", "modern-battle-panel-header");
  ownHeader.append(
    builtInBattleshipPlayerPlate(currentUserId(), "Your fleet"),
    make("span", "modern-battle-lock", session.state?.phase === "placement"
      ? ownFleet.accepted ? "Wait for opponent" : ownFleet.placed ? "Fleet placed — review or start" : "Place your fleet"
      : session.state?.completed ? "Battle complete" : "Fleet deployed"),
  );
  const placementControls = renderBuiltInBattleshipPlacementControls(ownFleet);
  ownWrap.append(ownHeader, renderBuiltInBattleshipGrid(currentUserId(), false));
  if (placementControls) host.append(placementControls);
  host.append(targetWrap, ownWrap);
  return host;
}

function cardLabel(card) {
  if (String(card || "").toUpperCase() === "JB") return "Big Joker";
  if (String(card || "").toUpperCase() === "JL") return "Little Joker";
  const match = /^([CDHS])(\d+)$/.exec(card || "");
  if (!match) return safe(card);
  const suits = { C: ["♣", "Clubs"], D: ["♦", "Diamonds"], H: ["♥", "Hearts"], S: ["♠", "Spades"] };
  const ranks = { 11:"J", 12:"Q", 13:"K", 14:"A" };
  return `${ranks[match[2]] || match[2]}${suits[match[1]][0]}`;
}

function classicCardSlot(card) {
  if (String(card || "").toUpperCase() === "JB") return "svg-joker-big";
  if (String(card || "").toUpperCase() === "JL") return "svg-joker-little";
  const match = /^([CDHS])(\d+)$/.exec(String(card || ""));
  if (!match) return "";
  const ranks = { 10: "t", 11: "j", 12: "q", 13: "k", 14: "a" };
  const rank = ranks[Number(match[2])] || match[2];
  return `gif-card-${rank}${match[1].toLowerCase()}`;
}

function renderBlackjackCard(card, className = "") {
  const hidden = card && typeof card === "object" && card.hidden === true;
  const node = make("span", `blackjack-card ${className}${hidden ? " is-hidden" : ""}`.trim());
  if (hidden) {
    node.setAttribute("aria-label", "Hidden dealer card");
    node.append(make("span", "blackjack-card-back-pattern", ""));
    return node;
  }
  const value = String(card || "");
  const match = /^([CDHS])(\d+)$/.exec(value);
  if (!match) {
    node.textContent = "?";
    return node;
  }
  const red = ["D", "H"].includes(match[1]);
  node.classList.toggle("is-red", red);
  node.setAttribute("aria-label", cardLabel(value));
  const ranks = { 11: "J", 12: "Q", 13: "K", 14: "A" };
  const suits = { C: "♣", D: "♦", H: "♥", S: "♠" };
  node.append(
    make("span", "blackjack-card-corner", `${ranks[Number(match[2])] || match[2]}${suits[match[1]]}`),
    make("span", "blackjack-card-suit", suits[match[1]]),
  );
  return node;
}

function renderBlackjackHand(hand, handIndex, isViewer) {
  const wrapper = make("section", `blackjack-hand${isViewer && Number(session.state?.viewerHandIndex || 0) === handIndex && canAct() ? " is-active" : ""}`);
  const cards = make("div", "blackjack-cards");
  const suitOrder = { C:0, D:1, H:2, S:3 };
  const orderedCards = [...(hand?.cards || [])].sort((left, right) => {
    const leftMatch = /^([CDHS])(\d+)/.exec(String(left || ""));
    const rightMatch = /^([CDHS])(\d+)/.exec(String(right || ""));
    const leftRank = Number(leftMatch?.[2] || 99);
    const rightRank = Number(rightMatch?.[2] || 99);
    const leftValue = leftRank === 14 ? 1 : Math.min(leftRank, 10);
    const rightValue = rightRank === 14 ? 1 : Math.min(rightRank, 10);
    return leftValue - rightValue
      || leftRank - rightRank
      || Number(suitOrder[leftMatch?.[1]] ?? 9) - Number(suitOrder[rightMatch?.[1]] ?? 9);
  });
  if (orderedCards.length > 4) {
    cards.classList.add("is-paged");
    const pageCount = Math.ceil(orderedCards.length / 4);
    for (let index = 0; index < orderedCards.length; index += 4) {
      const page = make("span", "blackjack-card-page");
      page.setAttribute("aria-label", `Cards ${index + 1} through ${Math.min(index + 4, orderedCards.length)} of ${orderedCards.length}, page ${Math.floor(index / 4) + 1} of ${pageCount}`);
      for (const card of orderedCards.slice(index, index + 4)) page.append(renderBlackjackCard(card));
      cards.append(page);
    }
  } else {
    for (const card of orderedCards) cards.append(renderBlackjackCard(card));
  }
  const total = Number(hand?.value?.total || 0);
  const result = String(hand?.result || "");
  const summary = [
    total ? `${hand?.value?.soft ? "Soft " : ""}${total}` : "Waiting",
    Number(hand?.bet || 0) ? `Bet ${Number(hand.bet).toLocaleString()}` : "",
    result ? `${gameSessionIsTerminal() ? "Last hand: " : ""}${result.replaceAll("-", " ")}` : "",
  ].filter(Boolean).join(" · ");
  wrapper.append(cards, make("p", "blackjack-hand-summary", summary));
  return wrapper;
}

function renderBlackjackStation(member, position) {
  const userId = Number(member?.userId || 0);
  const isViewer = userId === currentUserId();
  const station = make("article", `blackjack-player-station is-${position}${isViewer ? " is-viewer" : ""}`);
  station.style.setProperty("--blackjack-seat-column", String({ "far-left":1, "near-left":2, center:3, "near-right":4, "far-right":5 }[position] || 3));
  const identity = make("header", "blackjack-player-identity");
  identity.append(memberAvatar(member, "blackjack-player-avatar"), make("strong", "", isViewer ? "You" : memberName(userId)));
  identity.append(make("span", "blackjack-bankroll", blackjackBankrollLabel(userId)));
  const hands = make("div", "blackjack-hands");
  const playerHands = Array.isArray(session.state?.hands?.[String(userId)]) ? session.state.hands[String(userId)] : [];
  if (playerHands.length) playerHands.forEach((hand, index) => hands.append(renderBlackjackHand(hand, index, isViewer)));
  else hands.append(make("p", "blackjack-waiting", gameSessionIsTerminal() ? "Match complete"
    : session.status === "lobby" ? "Waiting to start"
      : typeof session.state?.bankrolls?.[String(userId)] !== "number" ? "Waiting for game state"
        : session.state.bankrolls[String(userId)] < 10 ? "Sitting out" : "Waiting for bet"));
  station.append(identity, hands);
  return station;
}

function renderBlackjack() {
  const table = make("section", "blackjack-table");
  const facts = blackjackDisplayFacts();
  const roundText = facts.lobby
    ? `Waiting to start${facts.rounds === null ? "" : ` · ${facts.rounds} rounds`}`
    : facts.round === null || facts.rounds === null ? "Round information pending" : `Round ${facts.round} / ${facts.rounds}`;
  table.setAttribute("aria-label", `Blackjack table, ${roundText}`);
  const dealer = make("section", "blackjack-dealer");
  const shoe = make("div", "blackjack-shoe");
  shoe.append(renderBlackjackCard({ hidden:true }, "blackjack-shoe-card"), make("span", "", facts.lobby ? "Waiting to deal" : `${Number(session.state?.shoeCount || 0)} cards`));
  const dealerCards = make("div", "blackjack-dealer-cards");
  for (const card of (session.state?.dealer?.cards || [])) dealerCards.append(renderBlackjackCard(card, "is-dealer-card"));
  const dealerValue = Number(session.state?.dealer?.value?.total || 0);
  dealer.append(make("h3", "", "Dealer"), dealerCards, make("p", "blackjack-dealer-value", facts.lobby ? "Waiting to deal" : dealerValue ? `${session.state?.dealer?.value?.soft ? "Soft " : ""}${dealerValue}` : "Hole card hidden"));
  const messageBand = make("div", "blackjack-message-band");
  messageBand.append(
    make("strong", "", "BLACKJACK PAYS 3 TO 2"),
    make("span", "", "Dealer draws to 16 and stands on every 17"),
  );
  const members = playerMembers();
  const viewerIndex = Math.max(0, members.findIndex(member => Number(member.userId) === currentUserId()));
  const viewer = members[viewerIndex] || members[0];
  const others = members.filter(member => member !== viewer);
  const relative = members.length ? [...members.slice(viewerIndex + 1), ...members.slice(0, viewerIndex)].filter(member => member !== viewer) : [];
  const positionOrder = others.length <= 1 ? ["near-left"]
    : others.length === 2 ? ["near-left", "near-right"]
    : others.length === 3 ? ["far-left", "near-left", "near-right"]
    : ["far-left", "near-left", "near-right", "far-right"];
  const stations = make("div", "blackjack-player-arc");
  relative.forEach((member, index) => stations.append(renderBlackjackStation(member, positionOrder[index])));
  if (viewer) stations.append(renderBlackjackStation(viewer, "center"));
  const round = make("div", "blackjack-round-badge", roundText);
  table.append(shoe, dealer, messageBand, stations, round);
  return table;
}

let selectedHeartsCard = null;
let selectedHeartsPassCards = [];
let heartsHandLayout = "spread";

function renderInBoardHandLayout(gameId, layout) {
  const panel = make("div", `in-board-hand-layout is-${gameId}`);
  panel.append(make("span", "in-board-hand-layout-label", "Hand layout"));
  const button = make("button", "in-board-hand-layout-toggle", layout === "spread" ? "Consolidate hand" : "Spread hand");
  button.type = "button";
  button.dataset.handLayoutToggle = gameId;
  button.setAttribute("aria-label", `Hand layout is ${layout}. ${layout === "spread" ? "Consolidate" : "Spread"} hand.`);
  button.addEventListener("click", () => {
    heartsHandLayout = heartsHandLayout === "spread" ? "consolidated" : "spread";
    render();
    requestAnimationFrame(() => document.querySelector(`[data-hand-layout-toggle="${gameId}"]`)?.focus({ preventScroll:true }));
  });
  panel.append(button);
  return panel;
}

function heartsSeatPosition(index, viewerIndex, count) {
  const relative = (index - Math.max(0, viewerIndex) + count) % count;
  if (count === 2) return relative === 0 ? "bottom" : "top";
  return ["bottom", "left", "top", "right"][relative] || "top";
}

function heartsPointsSummary(userId) {
  return Number(session.state?.captured?.[String(userId)]?.points || 0);
}

function renderHearts() {
  const state = session.state || {};
  const viewerId = currentUserId();
  const terminal = gameSessionIsTerminal();
  const lobby = !terminal && String(session.status || "") === "lobby";
  const terminalLabel = ({ forfeited: "Game forfeited", abandoned: "Game abandoned", ended: "Game ended", cancelled: "Game cancelled", expired: "Game expired" })[String(session.status || "")] || "Game complete";
  const order = Array.isArray(state.turnOrder) ? state.turnOrder.map(Number) : [];
  const viewerIndex = order.indexOf(viewerId);
  const members = new Map((session.members || []).map(member => [Number(member.userId), member]));
  const table = make("section", lobby ? "hearts-table is-lobby" : `hearts-table is-${Number(state.playerCount || order.length)}-player${state.phase === "passing" ? " is-passing" : ""}`);
  table.setAttribute("aria-label", lobby ? "Hearts lobby" : `${Number(state.playerCount || order.length)}-player Hearts table`);
  const banner = make("header", "hearts-status-band");
  const ruleset = state.ruleset === "two-player-28-card" ? "Two-player 28-card Hearts" : "Standard four-player Hearts";
  if (lobby) banner.append(make("strong", "hearts-ruleset", "Hearts"), make("span", "", "Waiting for players and game options"));
  else banner.append(
    make("strong", "hearts-ruleset", ruleset),
    make("span", "", `Hand ${Math.max(1, Number(state.handNumber || 1))}`),
    make("span", "", `Pass: ${safe(state.passDirection || "pending")}`),
    make("span", `hearts-broken-status${state.heartsBroken ? " is-broken" : ""}`, state.heartsBroken ? "Hearts broken" : "Hearts unbroken"),
  );
  const seats = make("div", "hearts-seats");
  order.forEach((userId, index) => {
    const member = members.get(userId) || { userId, displayName: memberName(userId) };
    const position = heartsSeatPosition(index, viewerIndex, order.length);
    const seat = make("article", `hearts-seat is-${position}${userId === viewerId ? " is-viewer" : ""}${Number(session.turnUserId || 0) === userId ? " is-current" : ""}`);
    const identity = make("header", "hearts-seat-identity");
    identity.append(memberAvatar(member, "hearts-avatar"), make("strong", "", userId === viewerId ? "You" : memberName(userId)));
    identity.append(make("span", "hearts-score", `${Number(state.scores?.[String(userId)] || 0)} / ${Number(state.settings?.targetScore || (order.length === 2 ? 50 : 100))}`));
    const details = make("p", "hearts-seat-details", `${Number(state.tricksWon?.[String(userId)] || 0)} tricks · ${heartsPointsSummary(userId)} hand points`);
    const hand = state.hands?.[String(userId)];
    const count = Array.isArray(hand) ? hand.length : Number(hand?.count || 0);
    const backs = make("div", "hearts-opponent-hand");
    if (userId !== viewerId) for (let cardIndex = 0; cardIndex < Math.min(count, 13); cardIndex++) backs.append(renderBlackjackCard({ hidden:true }, "hearts-card-back"));
    seat.append(identity, details, backs);
    seats.append(seat);
  });
  const center = make("div", "hearts-center");
  const trick = make("div", "hearts-trick");
  const plays = Array.isArray(state.currentTrick) ? state.currentTrick : [];
  if (plays.length === 0) trick.append(make("p", "hearts-empty-trick", terminal ? "No further cards to play" : lobby ? "Cards will be dealt when the game begins" : state.phase === "passing" ? "Complete the pass" : Number(state.trickNumber || 0) === 0 ? "Waiting for the opening card" : "Waiting for the next lead"));
  for (const play of plays) {
    const wrap = make("figure", "hearts-trick-card");
    wrap.append(renderBlackjackCard(play.card, "hearts-played-card"), make("figcaption", "", memberName(play.userId)));
    trick.append(wrap);
  }
  const centerMessage = terminal ? terminalLabel : lobby ? "Waiting for the game to start" : state.phase === "settling" && state.lastCompletedTrick
    ? `${memberName(state.lastCompletedTrick.winnerUserId)} takes trick ${Number(state.lastCompletedTrick.trick || 0)}`
    : state.phase === "passing"
      ? `Select ${Number(state.passCount || 0)} card${Number(state.passCount || 0) === 1 ? "" : "s"} to pass ${safe(state.passDirection || "")}`
      : state.phase === "deal" ? "Dealing the next hand" : `${memberName(session.turnUserId)} to play`;
  center.append(make("p", "hearts-center-message", centerMessage), trick);
  if (Number(state.widowCount || state.widow?.count || 0) > 0) center.append(make("span", "hearts-widow", `${Number(state.widowCount || state.widow?.count || 0)}-card widow`));
  const viewerHand = make("div", `hearts-viewer-hand is-${heartsHandLayout}`);
  const handCards = Array.isArray(state.hands?.[String(viewerId)]) ? state.hands[String(viewerId)] : [];
  const legal = new Set(Array.isArray(state.legalCards) ? state.legalCards : []);
  selectedHeartsPassCards = selectedHeartsPassCards.filter(card => handCards.includes(card));
  if (!handCards.includes(selectedHeartsCard)) selectedHeartsCard = null;
  for (const card of handCards) {
    const selected = selectedHeartsCard === card || selectedHeartsPassCards.includes(card);
    const button = make("button", `hearts-card-button${selected ? " is-selected" : ""}`);
    button.type = "button"; button.dataset.card = card;
    const passing = state.phase === "passing"; const playable = legal.has(card);
    button.disabled = passing ? !canAct() : (!canAct() || state.phase !== "playing" || !playable);
    button.classList.toggle("is-unplayable", canAct() && state.phase === "playing" && !playable);
    button.setAttribute("aria-pressed", selected ? "true" : "false");
    button.setAttribute("aria-label", passing ? `${selectedHeartsPassCards.includes(card) ? "Deselect" : "Select"} ${cardLabel(card)} to pass` : `${playable ? selectedHeartsCard === card ? "Play" : "Select" : "Cannot play"} ${cardLabel(card)}`);
    button.append(renderBlackjackCard(card, "hearts-hand-card"));
    button.addEventListener("click", () => {
      if (passing) {
        if (selectedHeartsPassCards.includes(card)) selectedHeartsPassCards = selectedHeartsPassCards.filter(value => value !== card);
        else if (selectedHeartsPassCards.length < Number(state.passCount || 0)) selectedHeartsPassCards = [...selectedHeartsPassCards, card];
        render(); return;
      }
      if (selectedHeartsCard !== card) { selectedHeartsCard = card; render(); return; }
      selectedHeartsCard = null; performAction("play", { card });
    });
    viewerHand.append(button);
  }
  const actions = make("div", "hearts-actions");
  if (state.phase === "passing") {
    const pass = make("button", "hearts-primary-action", `Pass ${Number(state.passCount || 0)} selected`);
    pass.disabled = !canAct() || selectedHeartsPassCards.length !== Number(state.passCount || 0);
    pass.addEventListener("click", () => { const cards = [...selectedHeartsPassCards]; selectedHeartsPassCards = []; performAction("pass", { cards }); });
    actions.append(pass, make("span", "", `${selectedHeartsPassCards.length} of ${Number(state.passCount || 0)} selected`));
  }
  if (state.lastHandResult) {
    const shooter = Number(state.lastHandResult.moonShooterUserId || 0);
    actions.append(make("p", "hearts-last-hand", shooter !== 0 ? `${memberName(shooter)} shot the moon.` : `Hand ${Number(state.lastHandResult.handNumber || 0)} scored.`));
  }
  actions.append(renderInBoardHandLayout("hearts", heartsHandLayout));
  table.append(banner, seats, center, viewerHand, actions);
  return table;
}

function unoCardParts(card) {
  const match = /^([RYGBW]):(0|1|2|3|4|5|6|7|8|9|S|R|D2|W|D4):([1-4])$/.exec(String(card || ""));
  return match ? { color:match[1], symbol:match[2], copy:Number(match[3]) } : null;
}

function unoColorName(color) {
  return ({ R:"Red", Y:"Yellow", G:"Green", B:"Blue", W:"Wild" })[String(color || "")] || "No color";
}

function unoCardLabel(card) {
  const parts = unoCardParts(card);
  if (!parts) return "Unknown card";
  const symbols = { S:"Skip", R:"Reverse", D2:"Draw Two", W:"Wild", D4:"Wild Draw Four" };
  return parts.color === "W" ? symbols[parts.symbol] : `${unoColorName(parts.color)} ${symbols[parts.symbol] || parts.symbol}`;
}

function unoCardAsset(card) {
  const parts = unoCardParts(card);
  if (!parts) return "back.png";
  if (parts.symbol === "W") return "wild.png";
  if (parts.symbol === "D4") return "wild-draw-four.png";
  const color = ({ R:"red", Y:"yellow", G:"green", B:"blue" })[parts.color];
  const symbol = ({ S:"skip", R:"reverse", D2:"draw-two" })[parts.symbol] || parts.symbol;
  return `${color}-${symbol}.png`;
}

function renderUnoCard(card, className = "", hidden = false) {
  const node = make("span", `uno-card ${className}${hidden ? " is-hidden" : ""}`.trim());
  const image = document.createElement("img");
  image.className = "uno-card-image";
  const cardUrl = new URL(`../../assets/images/uno/cards/${hidden ? "back.png" : unoCardAsset(card)}`, window.location.href);
  cardUrl.searchParams.set("v", "20260826-uno-r3-cards");
  image.src = cardUrl.href;
  image.alt = hidden ? "Face-down card" : unoCardLabel(card);
  image.decoding = "sync";
  image.draggable = false;
  node.append(image);
  return node;
}

function unoOrderedMembers() {
  const members = new Map(playerMembers().map(member => [Number(member.userId), member]));
  return (session.state?.turnOrder || []).map(userId => members.get(Number(userId))).filter(Boolean);
}

function renderUnoOpponent(member, index, viewerIndex, count) {
  const userId = Number(member?.userId || 0);
  const relative = (index - viewerIndex + count) % count;
  const opponentIndex = relative - 1;
  const opponentCount = Math.max(1, count - 1);
  const angle = opponentCount === 1 ? 270 : 205 + (130 * opponentIndex / Math.max(1, opponentCount - 1));
  const radians = angle * Math.PI / 180;
  const station = make("article", `uno-seat${Number(session.turnUserId || 0) === userId ? " is-current" : ""}`);
  station.style.setProperty("--uno-seat-x", String(50 + 38 * Math.cos(radians)));
  station.style.setProperty("--uno-seat-y", String(55 + 36 * Math.sin(radians)));
  const identity = make("header", "uno-seat-identity");
  identity.append(memberAvatar(member, "uno-avatar"));
  const text = make("span", "uno-seat-copy");
  text.append(make("strong", "", memberName(userId)), make("small", "", `${Number(session.state?.scores?.[String(userId)] || 0)} points`));
  identity.append(text);
  const countValue = Number(session.state?.cardCounts?.[String(userId)] || 0);
  const cards = make("div", "uno-opponent-cards");
  const visibleBackCount = count >= 8 ? 3 : 5;
  for (let cardIndex = 0; cardIndex < Math.min(visibleBackCount, countValue); cardIndex++) cards.append(renderUnoCard(null, "uno-opponent-card", true));
  cards.append(make("span", "uno-card-count", String(countValue)));
  const called = session.state?.unoDeclared?.[String(userId)] === true;
  station.append(identity, cards, make("span", `uno-seat-status${called ? " is-called" : ""}`, called ? "UNO called" : (Number(session.turnUserId || 0) === userId ? "Current turn" : "Waiting")));
  return station;
}

function renderUno() {
  const state = session.state || {};
  const members = unoOrderedMembers();
  const viewerId = currentUserId();
  const viewerIndex = Math.max(0, members.findIndex(member => Number(member.userId) === viewerId));
  const viewer = members[viewerIndex] || playerMembers().find(member => Number(member.userId) === viewerId);
  const table = make("section", "uno-table");
  table.dataset.playerCount = String(members.length);
  table.style.setProperty("--uno-current-color", ({ R:"#df4b3f", Y:"#f3c847", G:"#3eaa71", B:"#3989d4" })[String(state.currentColor || "")] || "#efe5c8");
  table.setAttribute("aria-label", `UNO table. ${unoColorName(state.currentColor)} is the current color. ${Number(state.direction || 1) > 0 ? "Clockwise" : "Counterclockwise"} play.`);
  const status = make("header", "uno-status-band");
  status.append(
    make("strong", "", `Hand ${Number(state.handNumber || 1)}`),
    make("span", "", `${unoColorName(state.currentColor)} active`),
    make("span", "", Number(state.direction || 1) > 0 ? "Clockwise" : "Counterclockwise"),
  );
  const seats = make("div", "uno-seats");
  members.forEach((member, index) => { if (index !== viewerIndex) seats.append(renderUnoOpponent(member, index, viewerIndex, members.length)); });
  const center = make("section", "uno-center");
  const direction = make("div", `uno-direction${Number(state.direction || 1) < 0 ? " is-reversed" : ""}`);
  direction.setAttribute("aria-hidden", "true");
  const piles = make("div", "uno-piles");
  const draw = make("button", "uno-pile uno-draw-pile");
  draw.type = "button";
  draw.disabled = busy || !(state.legalActions || []).includes("draw");
  draw.setAttribute("aria-label", `Draw a card. ${Number(state.drawPile?.count || 0)} cards remain.`);
  draw.append(renderUnoCard(null, "", true), make("span", "uno-pile-label", `Draw · ${Number(state.drawPile?.count || 0)}`));
  draw.addEventListener("click", () => performAction("draw"));
  const discard = make("div", "uno-pile uno-discard-pile");
  discard.append(renderUnoCard(state.topDiscard), make("span", "uno-pile-label", "Discard"));
  piles.append(draw, discard);
  const color = make("div", "uno-current-color");
  color.append(make("span", "uno-color-swatch", ""), make("strong", "", `${unoColorName(state.currentColor)} to play`));
  center.append(direction, piles, color);
  const viewerStation = make("section", `uno-viewer-station${Number(session.turnUserId || 0) === viewerId ? " is-current" : ""}`);
  const viewerIdentity = make("header", "uno-viewer-identity");
  if (viewer) viewerIdentity.append(memberAvatar(viewer, "uno-avatar"));
  viewerIdentity.append(make("strong", "", "You"), make("span", "", `${Number(state.scores?.[String(viewerId)] || 0)} / ${Number(state.targetScore || 500)} points`));
  const hand = make("div", "uno-viewer-hand");
  const legalCards = new Set(Array.isArray(state.legalCards) ? state.legalCards : []);
  const viewerHand = Array.isArray(state.hands?.[String(viewerId)]) ? state.hands[String(viewerId)] : [];
  const mustDraw = canAct()
    && legalCards.size === 0
    && (state.legalActions || []).includes("draw");
  if (mustDraw) {
    draw.classList.add("is-required");
    const drawPrompt = make("p", "uno-draw-required", "No legal card — draw a card.");
    drawPrompt.id = "uno-draw-required";
    drawPrompt.setAttribute("role", "status");
    drawPrompt.setAttribute("aria-live", "assertive");
    draw.setAttribute("aria-describedby", drawPrompt.id);
    viewerStation.append(drawPrompt);
  }
  hand.style.setProperty("--uno-hand-count", String(Math.max(1, viewerHand.length)));
  for (const card of viewerHand) {
    const wild = ["W", "D4"].includes(unoCardParts(card)?.symbol || "");
    const selected = selectedUnoWildCard === card;
    const button = make("button", `uno-hand-card${selected ? " is-selected" : ""}`);
    button.type = "button";
    button.disabled = busy || !canAct() || !legalCards.has(card);
    button.classList.toggle("is-illegal", canAct() && String(state.phase || "") === "playing" && !legalCards.has(card));
    button.setAttribute("aria-pressed", selected ? "true" : "false");
    button.setAttribute("aria-label", `${wild ? "Choose color for" : "Play"} ${unoCardLabel(card)}`);
    button.append(renderUnoCard(card));
    button.addEventListener("click", () => {
      if (wild) { selectedUnoWildCard = selected ? "" : card; render(); return; }
      performAction("play", { card });
    });
    hand.append(button);
  }
  const boardActions = make("div", "uno-board-actions");
  boardActions.setAttribute("role", "group");
  boardActions.setAttribute("aria-label", "UNO table actions");
  viewerStation.append(boardActions, viewerIdentity, hand);
  if (state.lastRoundResult) {
    viewerStation.append(make("p", "uno-round-result", `${memberName(state.lastRoundResult.winnerUserId)} won hand ${Number(state.lastRoundResult.handNumber || 0)} for ${Number(state.lastRoundResult.points || 0)} points.`));
  }
  table.append(status, seats, center, viewerStation);
  return table;
}

function scheduleHeartsAutomaticAction() {
  const cancelPending = () => {
    if (scheduleHeartsAutomaticAction.timer) {
      clearTimeout(Number(scheduleHeartsAutomaticAction.timer));
      scheduleHeartsAutomaticAction.key = "";
    }
    scheduleHeartsAutomaticAction.timer = 0;
  };
  if (context.extensionId !== "hearts" || busy || !canAct() || session.state?.completed) { cancelPending(); return; }
  let action = ""; let delay = 0;
  if (session.state?.phase === "deal") { action = "deal"; delay = 120; }
  if (session.state?.phase === "settling") { action = "settle-trick"; delay = Math.max(30, Number(session.state?.settlement?.settleAfterUnixMs || 0) - Number(session.nowUnixMs || Date.now())); }
  if (!action) { cancelPending(); return; }
  const key = `${Number(session.stateVersion || 0)}:${action}`;
  if (scheduleHeartsAutomaticAction.key === key) return;
  cancelPending();
  scheduleHeartsAutomaticAction.key = key;
  scheduleHeartsAutomaticAction.timer = setTimeout(async () => {
    scheduleHeartsAutomaticAction.timer = 0;
    if (busy || !canAct()) {
      if (scheduleHeartsAutomaticAction.key === key) scheduleHeartsAutomaticAction.key = "";
      return;
    }
    const succeeded = await performAction(action, {}, action === "deal" ? "hearts-deal" : "");
    if (!succeeded && scheduleHeartsAutomaticAction.key === key) scheduleHeartsAutomaticAction.key = "";
  }, delay);
}

function renderSpades() {
  const completed = Boolean(session.state?.completed || session.status === "completed");
  const currentTrick = Array.isArray(session.state?.currentTrick) ? session.state.currentTrick : [];
  const completedTrick = currentTrick.length === 0 && session.state?.phase === "settling" && Array.isArray(session.state?.lastCompletedTrick?.cards)
    ? session.state.lastCompletedTrick.cards : [];
  const visibleTrick = currentTrick.length ? currentTrick : completedTrick;
  const completedWinner = Number(session.state?.lastCompletedTrick?.winnerUserId || 0);
  const legalCards = new Set(Array.isArray(session.state?.legalCards) ? session.state.legalCards : []);
  const scoredHands = [...(session.state?.history || [])].filter(entry => Number.isInteger(Number(entry?.team)) && Object.hasOwn(entry || {}, "scoreChange"));
  const lastScore = team => Number([...scoredHands].reverse().find(entry => Number(entry.team) === team)?.scoreChange || 0);
  const teamUsers = team => (session.state?.turnOrder || []).filter((_userId, index) => index % 2 === team);
  const currentTricks = team => teamUsers(team).reduce((total, userId) => total + Number(session.state?.tricksWon?.[String(userId)] || 0), 0);
  const teamBid = team => Object.hasOwn(session.state?.teamBids || {}, String(team))
    ? Number(session.state.teamBids[String(team)]?.amount || 0) : "—";
  const scoreRows = [
    ["Team bid", teamBid(0), teamBid(1)],
    ["Current", currentTricks(0), currentTricks(1)],
    ["Last", lastScore(0), lastScore(1)],
    ["Total", Number(session.state?.teamScores?.["0"] || 0), Number(session.state?.teamScores?.["1"] || 0)],
  ];
  const passes = Object.values(session.state?.partnerPasses || {});
  const localPassOffer = passes.find(pass => Number(pass.fromUserId) === currentUserId() && pass.status === "awaiting-offer") || null;
  const localPassResponse = passes.find(pass => Number(pass.toUserId) === currentUserId() && pass.status === "awaiting-response") || null;
  const passSelectionActive = session.state?.phase === "partner-pass" && canAct() && Boolean(localPassOffer || localPassResponse);
  const passCardCount = Math.max(1, Math.min(2, Number(localPassOffer?.cardCount || localPassResponse?.cardCount || 2)));
  const localHand = session.state?.hands?.[String(currentUserId())];
  if (!passSelectionActive || !Array.isArray(localHand)) selectedSpadesPassCards = [];
  else selectedSpadesPassCards = selectedSpadesPassCards.filter(card => localHand.includes(card)).slice(0, passCardCount);
  if (selectedSpadesCard && (!legalCards.has(selectedSpadesCard) || session.state?.phase !== "playing" || !canAct())) selectedSpadesCard = null;
  const activationMode = spadesCardActivationMode();
  const activateCard = card => {
    if (!legalCards.has(card) || !canAct() || session.state?.phase !== "playing") return;
    if (activationMode === "legacy-ocx-one-click") {
      selectedSpadesCard = null;
      performAction("play", { card });
      return;
    }
    if (selectedSpadesCard !== card) {
      selectedSpadesCard = card;
      document.querySelectorAll('.classic-card-button[data-card], .playing-card[data-card]').forEach(candidate => {
        const candidateCard = String(candidate.dataset.card || '');
        const selected = candidateCard === card;
        const playable = legalCards.has(candidateCard);
        candidate.classList.toggle('is-selected', selected);
        candidate.closest('.playing-card-slot')?.classList.toggle('is-selected', selected);
        candidate.setAttribute('aria-pressed', selected ? 'true' : 'false');
        candidate.setAttribute('aria-label', `${playable ? selected ? 'Selected' : 'Select' : 'Cannot play'} ${cardLabel(candidateCard)}${selected ? '; activate again to play' : ''}`);
      });
      document.querySelector(`.classic-card-button[data-card="${CSS.escape(card)}"], .playing-card[data-card="${CSS.escape(card)}"]`)?.focus({ preventScroll: true });
      return;
    }
    selectedSpadesCard = null;
    performAction("play", { card });
  };
  const togglePassCard = card => {
    if (!passSelectionActive || !Array.isArray(localHand) || !localHand.includes(card)) return;
    selectedSpadesPassCards = selectedSpadesPassCards.includes(card)
      ? selectedSpadesPassCards.filter(item => item !== card)
      : passCardCount === 1
        ? [card]
        : selectedSpadesPassCards.length < passCardCount
          ? [...selectedSpadesPassCards, card]
          : [...selectedSpadesPassCards.slice(1), card].slice(-passCardCount);
    render();
    requestAnimationFrame(() => document.querySelector(`[data-card="${CSS.escape(card)}"]`)?.focus());
  };
  if (session.presentation?.effectivePack === "classic") {
    const source = classicSourceMap("spades");
    const stage = classicStage("classic-board", "spades-classic");
    applyClassicBoardScale(stage, source);
    const trick = make("div", "classic-trick");
    const members = playerMembers();
    const viewerSeat = Number(members.find(member => Number(member.userId) === currentUserId())?.seat || members[0]?.seat || 1);
    const relativePositions = ["bottom", "left", "top", "right"];
    const positionForSeat = seat => relativePositions[((Number(seat) - viewerSeat) % 4 + 4) % 4];
    const trickCompleted = (session.state?.phase === "settling" && visibleTrick.length === 4) || completedTrick.length > 0;
    if (trickCompleted) {
      trick.classList.add("is-completed");
      trick.setAttribute("role", "group");
      trick.setAttribute("aria-label", `Completed trick. ${memberName(completedWinner)} won.`);
    }
    for (const play of visibleTrick) {
      const member = members.find(item => Number(item.userId) === Number(play.userId));
      const position = positionForSeat(member?.seat || 1);
      const card = make("span", `classic-card-wrap is-${position}`);
      card.dataset.card = String(play.card || "");
      card.dataset.userId = String(Number(play.userId || 0));
      setSourceBox(card, source.trickCards[position], source.canvas.width, source.canvas.height);
      const slot = classicCardSlot(play.card);
      card.append(mediaImage(slot, "classic-card", cardLabel(play.card)));
      trick.append(card);
    }
    const hand = make("div", "classic-hand");
    setSourceBox(hand, source.hand, source.canvas.width, source.canvas.height);
    const cards = session.state?.hands?.[String(currentUserId())];
    if (Array.isArray(cards)) {
      const deferredMotion = pendingClassicMotion?.gameId === "spades"
        && pendingClassicMotion.type === "spades-card-play"
        && optionCategory("visualFxEnabled", true);
      const layoutCount = deferredMotion ? Number(pendingClassicMotion.fromCount || cards.length + 1) : cards.length;
      hand.dataset.compactionState = deferredMotion ? "deferred-until-card-settles" : "settled";
      for (const [cardIndex, card] of cards.entries()) {
        const button = make("button", "classic-card-button");
        const playable = legalCards.has(card);
        button.type = "button";
        button.disabled = passSelectionActive ? false : (!canAct() || session.state?.phase !== "playing" || !playable);
        button.classList.toggle("is-unplayable", canAct() && session.state?.phase === "playing" && !playable);
        const selected = selectedSpadesCard === card;
        const passSelected = selectedSpadesPassCards.includes(card);
        button.classList.toggle("is-selected", selected);
        button.classList.toggle("is-pass-selected", passSelected);
        button.classList.toggle("is-legacy-activation", activationMode === "legacy-ocx-one-click");
        button.dataset.activationMode = activationMode;
        button.dataset.card = card;
        button.setAttribute("aria-pressed", selected || passSelected ? "true" : "false");
        button.setAttribute("aria-label", passSelectionActive
          ? `${passSelected ? "Selected" : "Select"} ${cardLabel(card)} for the ${passCardCount === 1 ? "one-card" : "two-card"} exchange`
          : activationMode === "legacy-ocx-one-click"
            ? `${playable ? "Play" : "Cannot play"} ${cardLabel(card)}`
            : `${playable ? selected ? "Selected" : "Select" : "Cannot play"} ${cardLabel(card)}${selected ? "; activate again to play" : ""}`);
        const visualIndex = deferredMotion && cardIndex >= Number(pendingClassicMotion.fromIndex || 0) ? cardIndex + 1 : cardIndex;
        const box = spadesHandCardBox(visualIndex, layoutCount);
        const nextCardIndex = cardIndex + 1;
        const nextVisualIndex = deferredMotion && nextCardIndex >= Number(pendingClassicMotion.fromIndex || 0) ? nextCardIndex + 1 : nextCardIndex;
        const nextBox = nextCardIndex < cards.length ? spadesHandCardBox(nextVisualIndex, layoutCount) : null;
        const hitWidth = nextBox
          ? Math.max(1, Math.min(box.width, nextBox.x - box.x))
          : box.width;
        button.style.setProperty("--card-index", String(visualIndex));
        button.style.setProperty("--classic-card-visual-width", `${(box.width / hitWidth) * 100}%`);
        button.style.left = `${((box.x - source.hand.x) / source.hand.width) * 100}%`;
        button.style.top = `${((box.y - source.hand.y) / source.hand.height) * 100}%`;
        button.style.width = `${(hitWidth / source.hand.width) * 100}%`;
        button.style.height = `${(box.height / source.hand.height) * 100}%`;
        const slot = classicCardSlot(card);
        button.append(mediaImage(slot, "classic-card", ""));
        button.addEventListener("click", () => passSelectionActive ? togglePassCard(card) : activateCard(card));
        hand.append(button);
      }
    } else if (cards?.notViewed === true && Number(cards.count || 0) > 0) {
      const cardBackCount = Math.min(13, Math.max(0, Number(cards.count || 0)));
      hand.dataset.cardBackPreview = "true";
      hand.setAttribute("aria-label", `${cardBackCount} hidden cards. Blind Nil decision required before revealing the hand.`);
      for (let cardIndex = 0; cardIndex < cardBackCount; cardIndex++) {
        const back = make("span", "classic-card-button is-card-back");
        const box = spadesHandCardBox(cardIndex, cardBackCount);
        back.dataset.cardBack = "true";
        back.dataset.cardIndex = String(cardIndex);
        back.style.setProperty("--card-index", String(cardIndex));
        back.style.left = `${((box.x - source.hand.x) / source.hand.width) * 100}%`;
        back.style.top = `${((box.y - source.hand.y) / source.hand.height) * 100}%`;
        back.style.width = `${(box.width / source.hand.width) * 100}%`;
        back.style.height = `${(box.height / source.hand.height) * 100}%`;
        back.append(mediaImage(source.cardBack.slot, "classic-card", ""));
        hand.append(back);
      }
    }
    hand.addEventListener("keydown", event => {
      if (event.key !== "Escape" || (!selectedSpadesCard && selectedSpadesPassCards.length === 0)) return;
      event.preventDefault();
      const cancelled = selectedSpadesCard;
      selectedSpadesCard = null;
      selectedSpadesPassCards = [];
      render();
      if (cancelled) requestAnimationFrame(() => document.querySelector(`.classic-card-button[data-card="${CSS.escape(cancelled)}"]`)?.focus({ preventScroll: true }));
    });
    const passPreview = make("div", "classic-spades-pass-preview");
    const previewCards = selectedSpadesPassCards.length > 0
      ? selectedSpadesPassCards
      : localPassResponse && Array.isArray(localPassResponse.cards) ? localPassResponse.cards.slice(0, 2) : [];
    for (const [index, card] of previewCards.entries()) {
      const preview = mediaImage(classicCardSlot(card), "classic-spades-pass-card", cardLabel(card));
      preview.dataset.card = String(card);
      preview.dataset.passIndex = String(index);
      setSourceBox(preview, source.passCards[index], source.canvas.width, source.canvas.height);
      passPreview.append(preview);
    }
    const seats = make("div", "classic-spades-seats");
    const authoritativeTeamFor = member => {
      const teamIndex = (session.state?.turnOrder || []).map(Number).indexOf(Number(member.userId));
      return teamIndex >= 0 ? (teamIndex % 2) + 1 : ((Number(member.seat || 1) - 1) % 2) + 1;
    };
    for (const member of members) {
      const position = positionForSeat(member.seat);
      const team = authoritativeTeamFor(member);
      const seat = make("div", `classic-spades-seat is-${position}${Number(session.turnUserId) === Number(member.userId) ? " is-active" : ""}`);
      const frame = source.avatarFrames?.[position] || source.seats[position];
      const aperture = source.avatarWells?.[position] || source.avatarInsets[position];
      setSourceBox(seat, frame, source.canvas.width, source.canvas.height);
      seat.setAttribute("role", "img");
      seat.setAttribute("aria-label", `${member.displayName}, ${position} seat, Team ${team}${Number(session.turnUserId) === Number(member.userId) ? ", current turn" : ""}`);
      seat.dataset.userId = String(Number(member.userId || 0));
      seat.dataset.seat = String(Number(member.seat || 0));
      seat.dataset.avatarFrameBox = JSON.stringify(frame);
      seat.dataset.avatarApertureBox = JSON.stringify(aperture);
      seat.dataset.identityBinding = `${Number(member.userId || 0)}:${Number(member.seat || 0)}:${position}`;
      seat.dataset.team = String(team);
      const borderDefinition = source.avatarBorders[`team${team}`];
      const currentTurn = Number(session.turnUserId) === Number(member.userId);
      const borderSlot = borderDefinition.rest;
      const border = mediaImage(borderSlot, "classic-spades-seat-border", "");
      border.dataset.team = String(team);
      border.dataset.highlighted = "false";
      border.dataset.nativeSize = borderDefinition.nativeSize.join("x");
      seat.dataset.borderSlot = borderSlot;
      seat.dataset.currentTurnPulse = currentTurn ? "true" : "false";
      seat.dataset.pulseDurationMs = String(source.avatarBorders.currentTurnPulse.durationMs);
      seat.dataset.pulseHighlightDurationMs = String(source.avatarBorders.currentTurnPulse.highlightedDurationMs);
      const avatar = memberAvatar(member, "classic-spades-avatar");
      setNestedSourceBox(avatar, aperture, frame);
      seat.append(border);
      if (currentTurn) {
        const highlighted = mediaImage(borderDefinition.highlighted, "classic-spades-seat-border is-current-turn-highlight", "");
        highlighted.dataset.team = String(team);
        highlighted.dataset.highlighted = "true";
        highlighted.dataset.nativeSize = borderDefinition.nativeSize.join("x");
        highlighted.dataset.sourceTicks = source.avatarBorders.currentTurnPulse.highlightedTicks.join(",");
        // Session polling rebuilds the source board. Anchor each replacement
        // layer to the same wall-clock phase so the authentic late-cycle pulse
        // is not restarted before source ticks 22-23 become visible.
        const pulseDurationMs = Number(source.avatarBorders.currentTurnPulse.durationMs);
        const pulsePhaseMs = pulseDurationMs > 0 ? Date.now() % pulseDurationMs : 0;
        highlighted.style.animationDelay = `-${pulsePhaseMs}ms`;
        highlighted.dataset.pulsePhaseMs = String(pulsePhaseMs);
        seat.dataset.highlightBorderSlot = borderDefinition.highlighted;
        seat.append(highlighted);
      }
      seat.append(avatar);
      seats.append(seat);
    }
    const arrows = make("div", "classic-spades-turn-arrows");
    const currentMember = members.find(member => Number(member.userId) === Number(session.turnUserId));
    const currentPosition = currentMember ? positionForSeat(currentMember.seat) : "";
    for (const position of relativePositions) {
      const definition = source.turnArrows[position];
      const active = position === currentPosition;
      const arrow = mediaImage(active ? definition.active : definition.off, `classic-spades-turn-arrow is-${position}${active ? " is-active" : " is-off"}`, "");
      setSourceBox(arrow, definition.box, source.canvas.width, source.canvas.height);
      arrow.dataset.position = position;
      arrow.dataset.active = active ? "true" : "false";
      arrow.dataset.slot = active ? definition.active : definition.off;
      arrows.append(arrow);
    }
    const score = make("div", "classic-spades-score");
    score.setAttribute("aria-label", scoreRows.map(row => `${row[0]}. Team 1 ${row[1]}. Team 2 ${row[2]}.`).join(" "));
    const scoreGrid = make("div", "classic-spades-score-grid");
    scoreGrid.setAttribute("aria-hidden", "true");
    setSourceBox(scoreGrid, { ...source.scoreTable, height: 78 }, source.canvas.width, source.canvas.height);
    scoreGrid.append(
      make("span", "classic-spades-score-title", "SCORES"),
      make("span", "classic-spades-score-cell is-corner", ""),
      make("span", "classic-spades-score-cell is-team-1", "Team 1"),
      make("span", "classic-spades-score-cell is-team-2", "Team 2"),
    );
    for (const [label, team1, team2] of scoreRows) {
      scoreGrid.append(
        make("span", "classic-spades-score-cell is-row-label", label),
        make("span", "classic-spades-score-cell", String(team1)),
        make("span", "classic-spades-score-cell", String(team2)),
      );
    }
    score.append(scoreGrid);
    const roster = make("div", "classic-spades-roster");
    const rosterMap = source.teamRoster;
    if (rosterMap?.region) {
      setSourceBox(roster, rosterMap.region, source.canvas.width, source.canvas.height);
      const rosterNames = [];
      for (const teamNumber of [1, 2]) {
        const teamMap = rosterMap[`team${teamNumber}`];
        const teamMembers = members
          .filter(member => authoritativeTeamFor(member) === teamNumber)
          .sort((left, right) => Number(left.seat || 0) - Number(right.seat || 0));
        const teamLabel = make("span", `is-team-label is-team-${teamNumber}`, `Team ${teamNumber}`);
        teamLabel.dataset.team = String(teamNumber);
        setNestedSourceBox(teamLabel, teamMap.label, rosterMap.region);
        roster.append(teamLabel);
        teamMembers.slice(0, 2).forEach((member, index) => {
          const name = make("span", `is-team-name is-team-${teamNumber}`, member.displayName);
          name.dataset.team = String(teamNumber);
          name.dataset.userId = String(Number(member.userId || 0));
          name.dataset.seat = String(Number(member.seat || 0));
          name.dataset.identityBinding = `${Number(member.userId || 0)}:${Number(member.seat || 0)}:${teamNumber}`;
          setNestedSourceBox(name, teamMap.names[index], rosterMap.region);
          roster.append(name);
          rosterNames.push(`Team ${teamNumber}: ${member.displayName}`);
        });
      }
      roster.setAttribute("role", "group");
      roster.setAttribute("aria-label", rosterNames.join("; "));
      roster.dataset.sourceRegion = `${rosterMap.region.x},${rosterMap.region.y},${rosterMap.region.width},${rosterMap.region.height}`;
    }
    stage.append(trick, hand, passPreview, arrows, seats, score, roster);
    appendClassicMotion(stage, "spades");
    return stage;
  }
  const host = make("div");
  const scoreSummary = make("section", "spades-score-summary");
  scoreSummary.setAttribute("aria-label", scoreRows.map(row => `${row[0]}. Team 1 ${row[1]}. Team 2 ${row[2]}.`).join(" "));
  const scoreSummaryGrid = make("div", "spades-score-summary-grid");
  scoreSummaryGrid.setAttribute("aria-hidden", "true");
  scoreSummaryGrid.append(
    make("strong", "spades-score-summary-title", "Scores"),
    make("span", "spades-score-summary-cell is-corner", ""),
    make("span", "spades-score-summary-cell is-team-heading", "Team 1"),
    make("span", "spades-score-summary-cell is-team-heading", "Team 2"),
  );
  for (const [label, team1, team2] of scoreRows) {
    scoreSummaryGrid.append(
      make("span", "spades-score-summary-cell is-row-heading", label),
      make("span", "spades-score-summary-cell", String(team1)),
      make("span", "spades-score-summary-cell", String(team2)),
    );
  }
  scoreSummary.append(scoreSummaryGrid);
  const table = make("div", "card-table");
  const trick = make("div", "trick");
  for (const play of visibleTrick) trick.append(make("span", "playing-card", `${memberName(play.userId)} · ${cardLabel(play.card)}`));
  if (completedTrick.length) {
    trick.setAttribute("role", "group");
    trick.setAttribute("aria-label", `Completed trick. ${memberName(completedWinner)} won.`);
    table.append(make("p", "minor", `Completed trick · ${memberName(completedWinner)} won.`));
  }
  table.append(trick.childNodes.length ? trick : make("p", "minor", completed ? "Game completed." : "Waiting for the next card."));
  const hand = make("div", "hand");
  const cards = session.state?.hands?.[String(currentUserId())];
  if (Array.isArray(cards)) for (const card of cards) {
    const cardSlot = make("span", "playing-card-slot");
    const button = make("button", `playing-card${String(card).startsWith("D") || String(card).startsWith("H") ? " is-red" : ""}${String(card).slice(1) === "10" ? " is-ten" : ""}`, cardLabel(card));
    const playable = legalCards.has(card);
    button.type = "button";
    button.disabled = passSelectionActive ? false : (!canAct() || session.state?.phase !== "playing" || !playable);
    button.classList.toggle("is-unplayable", canAct() && session.state?.phase === "playing" && !playable);
    cardSlot.classList.toggle("is-playable", !button.disabled);
    const selected = selectedSpadesCard === card;
    const passSelected = selectedSpadesPassCards.includes(card);
    button.classList.toggle("is-selected", selected);
    button.classList.toggle("is-pass-selected", passSelected);
    cardSlot.classList.toggle("is-selected", selected);
    cardSlot.classList.toggle("is-pass-selected", passSelected);
    button.classList.toggle("is-legacy-activation", activationMode === "legacy-ocx-one-click");
    button.dataset.activationMode = activationMode;
    button.dataset.card = card;
    button.setAttribute("aria-pressed", selected || passSelected ? "true" : "false");
    button.setAttribute("aria-label", passSelectionActive
      ? `${passSelected ? "Selected" : "Select"} ${cardLabel(card)} for the ${passCardCount === 1 ? "one-card" : "two-card"} exchange`
      : activationMode === "legacy-ocx-one-click"
        ? `${playable ? "Play" : "Cannot play"} ${cardLabel(card)}`
        : `${playable ? selected ? "Selected" : "Select" : "Cannot play"} ${cardLabel(card)}${selected ? "; activate again to play" : ""}`);
    button.addEventListener("click", () => passSelectionActive ? togglePassCard(card) : activateCard(card));
    cardSlot.append(button);
    hand.append(cardSlot);
  }
  hand.addEventListener("keydown", event => {
    if (event.key === "Escape" && (selectedSpadesCard || selectedSpadesPassCards.length > 0)) {
      event.preventDefault(); selectedSpadesCard = null; selectedSpadesPassCards = []; render();
    }
  });
  host.append(scoreSummary, table);
  if (completed) {
    const team1Score = Number(session.state?.teamScores?.["0"] || 0);
    const team2Score = Number(session.state?.teamScores?.["1"] || 0);
    const finalScore = make("div", "score-card");
    finalScore.setAttribute("role", "status");
    finalScore.setAttribute("aria-label", `Final score. Team 1 score ${team1Score}. Team 2 score ${team2Score}.`);
    finalScore.append(make("strong", "", "Final score"), make("p", "minor", `Team 1 ${team1Score} · Team 2 ${team2Score}`));
    host.append(finalScore);
  } else if (["master", "player"].includes(String(session.viewerRole || ""))) {
    host.append(make("h3", "", "Your hand"), hand);
  }
  return host;
}

function addControl(label, control) {
  const wrapper = make("label");
  wrapper.append(make("span", "", label), control);
  return wrapper;
}

function renderLifecycleControls() {
  const host = el("external-pause");
  host.replaceChildren();
  if (gameSessionIsTerminal()) return;
  const viewerIsPlayer = ["master", "player"].includes(String(session?.viewerRole || ""));
  const framework = session?.state?._framework || {};
  const pause = framework.pause || { mode: "running" };
  const actions = framework.actions || {};
  const group = make("div", "lifecycle-controls");
  if (viewerIsPlayer && !session?.state?.completed) {
    const pauseButton = make("button", "", "Pause game");
    pauseButton.type = "button";
    pauseButton.dataset.externalGameControl = "pause";
    pauseButton.disabled = !actions.canPause || busy;
    pauseButton.addEventListener("click", () => performAction("pause-game"));
    group.append(pauseButton);
  }

  if (framework.serviceInterruption?.active) {
    group.append(make("span", "lifecycle-status", "Game time is frozen for a confirmed CoreChat service interruption."));
  }
  for (const member of playerMembers()) {
    const userId = String(Number(member.userId || 0));
    if (framework.players?.[userId]?.disconnected !== true) continue;
    const status = make("span", "lifecycle-status lifecycle-countdown");
    status.dataset.lifecycleCountdown = `disconnect:${userId}`;
    group.append(status);
  }
  if (pause.mode === "running" && framework.inactivity?.enabled && !framework.serviceInterruption?.active) {
    const inactivity = make("span", "lifecycle-status lifecycle-countdown");
    inactivity.dataset.lifecycleCountdown = "inactivity";
    group.append(inactivity);
  }

  if (pause.mode === "proposed") {
    group.append(make("span", "lifecycle-status", `Pause proposed by ${memberName(pause.proposedByUserId)}.`));
    if (actions.canAcceptPause) {
      const accept = make("button", "", "Accept pause");
      accept.dataset.externalGameRow = "pause";
      accept.disabled = busy;
      accept.addEventListener("click", () => performAction("accept-pause"));
      group.append(accept);
    }
    if (actions.canDeclinePause) {
      const decline = make("button", "", "Decline pause");
      decline.dataset.externalGameRow = "pause";
      decline.disabled = busy;
      decline.addEventListener("click", () => performAction("decline-pause"));
      group.append(decline);
    }
  }
  if (actions.canPauseForReconnect) {
    const reconnectPause = make("button", "", "Pause for reconnect");
    reconnectPause.dataset.externalGameRow = "pause";
    reconnectPause.disabled = busy;
    reconnectPause.addEventListener("click", () => performAction("pause-for-reconnect"));
    group.append(reconnectPause);
  }
  if (pause.mode === "paused") {
    group.append(make("span", "lifecycle-status", pause.reason === "reconnect" ? "Paused while waiting for reconnect." : "Game paused by agreement."));
    if (actions.canStartResume) {
      const resume = make("button", "", "Start 1-minute resume");
      resume.dataset.externalGameRow = "pause";
      resume.disabled = busy;
      resume.addEventListener("click", () => performAction("start-resume"));
      group.append(resume);
    }
  }
  if (pause.mode === "resuming") {
    const countdown = make("span", "lifecycle-status lifecycle-countdown");
    countdown.dataset.lifecycleCountdown = "resume";
    group.append(countdown);
    if (actions.canResumeNow) {
      const resumeNow = make("button", "", "Resume now");
      resumeNow.dataset.externalGameRow = "pause";
      resumeNow.disabled = busy;
      resumeNow.addEventListener("click", () => performAction("resume-now"));
      group.append(resumeNow);
    }
  }
  const appendClaim = (selectKey, confirmKey, selectLabel, confirmLabel, selectAction, confirmAction) => {
    if (actions[selectKey]) {
      const select = make("button", "", selectLabel);
      select.dataset.externalGameRow = "pause";
      select.disabled = busy;
      select.addEventListener("click", () => performAction(selectAction));
      group.append(select);
    }
    if (actions[confirmKey]) {
      const confirm = make("button", "lifecycle-confirm", confirmLabel);
      confirm.dataset.externalGameRow = "pause";
      confirm.disabled = busy;
      confirm.addEventListener("click", () => performAction(confirmAction));
      group.append(confirm);
    }
  };
  appendClaim("canSelectDisconnectWin", "canConfirmDisconnectWin", "End game and win", "Confirm end game and win", "select-disconnect-win", "confirm-disconnect-win");
  appendClaim("canSelectDisconnectDraw", "canConfirmDisconnectDraw", "End game as draw", "Confirm end game as draw", "select-disconnect-draw", "confirm-disconnect-draw");
  if (group.childElementCount) host.append(group);
  requestAnimationFrame(syncSharedLifecycleCountdowns);
}

function renderControls() {
  const host = el("controls");
  host.replaceChildren();
  const resignHost = el("external-resign");
  resignHost.replaceChildren();
  renderLifecycleControls();
  const group = make("div", "control-group");
  if (context.extensionId === "checkers") {
    if (session.presentation?.effectivePack !== "classic") {
      const draw = make("button", "", Number(session.state?.drawOfferBy || 0) > 0 ? "Draw pending" : "Offer draw");
      draw.disabled = !gameLifecycleAvailable() || Number(session.state?.drawOfferBy || 0) > 0;
      draw.addEventListener("click", () => performAction("offer-draw"));
      group.append(draw);
    }
  } else if (context.extensionId === "chess") {
    const pendingDraw = Number(session.state?.drawOfferBy || 0) > 0;
    const draw = make("button", "", pendingDraw ? "Draw decision pending" : "Offer draw");
    draw.disabled = !gameLifecycleAvailable() || pendingDraw;
    draw.addEventListener("click", () => performAction("offer-draw"));
    const claimThreefold = make("button", "", "Claim threefold draw");
    claimThreefold.disabled = !gameLifecycleAvailable() || pendingDraw || session.state?.drawClaims?.threefold !== true;
    claimThreefold.setAttribute("aria-describedby", "rules-panel");
    claimThreefold.addEventListener("click", () => performAction("claim-draw", { claim: "threefold" }));
    const claimFifty = make("button", "", "Claim 50-move draw");
    claimFifty.disabled = !gameLifecycleAvailable() || pendingDraw || session.state?.drawClaims?.fiftyMove !== true;
    claimFifty.setAttribute("aria-describedby", "rules-panel");
    claimFifty.addEventListener("click", () => performAction("claim-draw", { claim: "fifty-move" }));
    if (session.presentation?.effectivePack !== "classic") group.append(draw);
    group.append(claimThreefold, claimFifty);
  } else if (context.extensionId === "acey-deucy") {
    const classic = session.presentation?.effectivePack === "classic";
    if (classic) group.classList.add("is-classic-point-controls");
    const opening = session.state?.aceyStage === "opening-roll";
    const rollLabel = opening ? "Roll to choose starter" : (currentAceyRollAgain() ? "Roll again" : "Roll dice");
    const roll = classic
      ? applyClassicActionArt(make("button", "", rollLabel), "gif-roll")
      : make("button", "built-in-roll-action", rollLabel);
    roll.disabled = opening ? !canRequestOpeningRoll() : (!canAct() || !["roll", "roll-again"].includes(session.state?.aceyStage));
    roll.addEventListener("click", () => performAction("roll", {}, "acey-deucy-roll"));
    if (!classic) {
      const guidance = make("p", "minor", selectedPointOrigin
        ? "Choose a highlighted destination, or activate the selected checker again to cancel."
        : "Choose a movable checker, then a highlighted legal destination.");
      if (!mountBuiltInPointRollAction(roll, guidance)) group.append(roll);
    }
  } else if (context.extensionId === "backgammon-first-party") {
    const classic = session.presentation?.effectivePack === "classic";
    if (classic) group.classList.add("is-classic-point-controls");
    const opening = session.state?.backgammonStage === "opening-roll";
    const rollLabel = opening ? "Roll to choose starter" : "Roll dice";
    const roll = classic
      ? applyClassicActionArt(make("button", "", rollLabel), "gif-roll")
      : make("button", "built-in-roll-action", rollLabel);
    roll.disabled = opening ? !canRequestOpeningRoll() : (!canAct() || session.state?.backgammonStage !== "roll");
    roll.addEventListener("click", () => performAction("roll", {}, "backgammon-roll"));
    const guidance = make("p", "minor", selectedPointOrigin
      ? `Selected ${selectedPointOrigin === "bar" ? "checker on the bar" : `point ${Number(selectedPointOrigin.slice(6)) + 1}`}. Choose a marked legal destination, or activate it again to cancel.`
      : "Choose a movable checker on the board. Legal destinations are derived from the current server-confirmed dice and state.");
    if (!classic && !mountBuiltInPointRollAction(roll, guidance)) group.append(roll, guidance);
  } else if (context.extensionId === "battleship") {
    // Built-in placement controls are rendered inside the fleet panel so they
    // remain part of the board. Classic/OCX placement keeps its source UI.
  } else if (context.extensionId === "blackjack") {
    const legal = new Set(Array.isArray(session.state?.legalActions) ? session.state.legalActions : []);
    if (legal.has("bet")) {
      const bankroll = Number(session.state?.bankrolls?.[String(currentUserId())] || 0);
      const maximum = Math.max(10, Math.min(500, bankroll));
      selectedBlackjackBet = Math.max(10, Math.min(maximum, Math.round(Number(selectedBlackjackBet || 50) / 10) * 10));
      const wager = make("section", "blackjack-action-panel");
      wager.append(make("h3", "", "Place your bet"));
      const picker = make("div", "blackjack-bet-picker");
      const minus = make("button", "", "− 10");
      minus.type = "button";
      minus.disabled = selectedBlackjackBet <= 10;
      minus.addEventListener("click", () => { selectedBlackjackBet = Math.max(10, selectedBlackjackBet - 10); render(); });
      const amount = make("output", "blackjack-bet-amount", `${selectedBlackjackBet.toLocaleString()} chips`);
      const plus = make("button", "", "+ 10");
      plus.type = "button";
      plus.disabled = selectedBlackjackBet >= maximum;
      plus.addEventListener("click", () => { selectedBlackjackBet = Math.min(maximum, selectedBlackjackBet + 10); render(); });
      const submit = make("button", "blackjack-primary-action", "Place bet");
      submit.addEventListener("click", () => performAction("bet", { amount:selectedBlackjackBet }));
      picker.append(minus, amount, plus, submit);
      wager.append(picker);
      group.append(wager);
      group.classList.add("blackjack-bet-actions");
      requestAnimationFrame(() => {
        const table = document.querySelector(".blackjack-table");
        if (table && group.isConnected) table.append(group);
      });
    } else if (legal.has("insurance")) {
      const panel = make("section", "blackjack-action-panel");
      panel.append(make("h3", "", "Dealer shows an Ace"), make("p", "minor", "Protect half of your wager against dealer Blackjack?"));
      const decline = make("button", "", "No, continue");
      decline.addEventListener("click", () => performAction("insurance", { take:false }));
      const accept = make("button", "blackjack-primary-action", "Protect wager");
      accept.addEventListener("click", () => performAction("insurance", { take:true }));
      panel.append(decline, accept);
      group.append(panel);
    } else if (session.state?.phase === "player-turns" && canAct()) {
      const labels = { hit:"Hit", stand:"Stand", double:"Double", split:"Split", surrender:"Surrender" };
      group.classList.add("blackjack-turn-actions");
      for (const action of ["hit", "stand", "double", "split", "surrender"]) {
        if (!legal.has(action)) continue;
        const button = make("button", action === "hit" ? "blackjack-primary-action" : "", labels[action]);
        button.classList.add("blackjack-turn-action", "is-" + action);
        button.addEventListener("click", () => performAction(action));
        group.append(button);
      }
      requestAnimationFrame(() => {
        const viewerStation = document.querySelector(".blackjack-player-station.is-viewer");
        if (viewerStation && group.isConnected) viewerStation.append(group);
      });
    } else if (legal.has("next-round")) {
      const next = make("button", "blackjack-primary-action", "Start next round");
      next.addEventListener("click", () => performAction("next-round"));
      group.append(next);
      group.classList.add("blackjack-next-round-actions");
      requestAnimationFrame(() => {
        const viewerStation = document.querySelector(".blackjack-player-station.is-viewer");
        if (viewerStation && group.isConnected) viewerStation.append(group);
      });
    } else if (["deal", "dealer"].includes(String(session.state?.phase || ""))) {
      group.append(make("p", "minor blackjack-automatic-status", session.state.phase === "deal" ? "Dealer is dealing the next hand…" : "Dealer is completing the hand…"));
    }
  } else if (context.extensionId === "uno") {
    const legal = new Set(Array.isArray(session.state?.legalActions) ? session.state.legalActions : []);
    const declared = session.state?.unoDeclared?.[String(currentUserId())] === true;
    if (legal.has("call-uno")) {
      const call = make("button", "uno-primary-action uno-call-action", declared ? "UNO called" : "Call UNO");
      call.type = "button";
      call.disabled = declared || busy || gameSessionIsTerminal();
      call.title = declared ? "UNO has been declared." : "Declare UNO when the rules allow it before your last card.";
      if (!declared) call.addEventListener("click", () => performAction("call-uno"));
      group.append(call);
    }
    const colorChoices = (action, card = "") => {
      const chooser = make("section", "uno-color-chooser");
      chooser.append(make("h3", "", "Choose the continuing color"));
      for (const [color, label] of [["R","Red"],["Y","Yellow"],["G","Green"],["B","Blue"]]) {
        const button = make("button", `uno-color-choice is-${label.toLowerCase()}`, label);
        button.addEventListener("click", () => { selectedUnoWildCard = ""; performAction(action, { ...(card ? { card } : {}), color }); });
        chooser.append(button);
      }
      if (card) {
        const cancel = make("button", "uno-cancel-color", "Cancel color choice");
        cancel.addEventListener("click", () => { selectedUnoWildCard = ""; render(); });
        chooser.append(cancel);
      }
      group.append(chooser);
    };
    if (legal.has("choose-color")) {
      colorChoices("choose-color");
    } else if (selectedUnoWildCard) {
      colorChoices("play", selectedUnoWildCard);
    } else {

      if (legal.has("catch-uno")) {
        const catchButton = make("button", "uno-catch-action", `Catch ${memberName(session.state?.unoCatchUserId)} without UNO`);
        catchButton.addEventListener("click", () => performAction("catch-uno"));
        group.append(catchButton);
      }
      if (legal.has("pass")) {
        const keep = make("button", "", "Keep drawn card");
        keep.addEventListener("click", () => performAction("pass"));
        group.append(keep);
      }
      if (legal.has("accept-draw-four")) {
        const accept = make("button", "", "Accept four cards");
        accept.addEventListener("click", () => performAction("accept-draw-four"));
        const challenge = make("button", "uno-primary-action", "Challenge Wild Draw Four");
        challenge.addEventListener("click", () => performAction("challenge-draw-four"));
        group.append(make("p", "minor", `${memberName(session.state?.pendingChallenge?.offenderUserId)} played Wild Draw Four.`), accept, challenge);
      }
      if (legal.has("next-hand")) {
        const next = make("button", "uno-primary-action", "Start next hand");
        next.addEventListener("click", () => performAction("next-hand"));
        group.append(next);
        group.classList.add("uno-next-hand-actions");

      }
      if (legal.has("draw")) group.append(make("p", "minor", "Play a highlighted card or use the center Draw pile."));
    }
  } else if (context.extensionId === "spades") {
    if (session.state?.phase === "deal") {
      group.dataset.automaticDeal = "true";
    } else if (session.state?.phase === "bidding") {
      const hand = session.state?.hands?.[String(currentUserId())];
      const handNotViewed = !Array.isArray(hand) && hand?.notViewed === true;
      const eligibility = session.state?.bidEligibility || {};
      const role = String(eligibility.role || "hint");
      const minimumTeamBid = Math.max(0, Number(eligibility.minimumTeamBid || 0));
      const minimumStandardBid = Math.max(1, Number(eligibility.minimumStandardBid || 1));
      const partnerBid = eligibility.partnerBid || (eligibility.partnerHint ? { ...eligibility.partnerHint, kind: "standard" } : null);
      if (selectedSpadesBid && selectedSpadesBid.role !== role) selectedSpadesBid = null;
      if (selectedSpadesBid?.kind === "standard" && Number(selectedSpadesBid.amount || 0) < minimumStandardBid) selectedSpadesBid = null;
      if (canAct()) {
        const dialog = make("section", "spades-action-dialog");
        dialog.setAttribute("role", "dialog");
        dialog.setAttribute("aria-modal", "false");
        const renderAndFocus = selector => {
          const scrollLeft = window.scrollX;
          const scrollTop = window.scrollY;
          render();
          requestAnimationFrame(() => {
            window.scrollTo({ left: scrollLeft, top: scrollTop, behavior: "instant" });
            document.querySelector(selector)?.focus({ preventScroll: true });
          });
        };
        const appendConfirmation = choice => {
          let confirmation = `${choice.label} is your individual bid.`;
          if (partnerBid) {
            const partnerKind = String(partnerBid.kind || "standard");
            const partnerAmount = Number(partnerBid.amount || 0);
            const combined = partnerAmount + (choice.kind === "standard" ? Number(choice.amount || 0) : 0);
            confirmation = choice.kind === "standard"
              ? `Your bid ${Number(choice.amount)} + partner bid ${partnerAmount} = team bid ${combined}.`
              : `You are bidding ${choice.label}. Your partner's ${partnerAmount}-trick bid becomes the team bid.`;
          } else if (choice.kind === "standard") {
            confirmation = `Your bid is ${Number(choice.amount)}. Your partner's individual bid will be added to create the team bid.`;
          }
          dialog.append(make("h3", "", "Confirm Your Bid"), make("p", "", confirmation));
          const actions = make("div", "spades-dialog-actions");
          const confirm = make("button", "spades-confirm-bid", "Confirm Your Bid");
          confirm.addEventListener("click", () => performAction("bid", { kind: choice.kind, amount: choice.amount }));
          const back = make("button", "", "Go Back");
          back.addEventListener("click", () => {
            const priorChoice = selectedSpadesBid;
            selectedSpadesBid = null;
            const selector = priorChoice?.kind === "standard"
              ? `.spades-bid-choice[data-bid-amount="${priorChoice.amount}"]`
              : `.spades-action-dialog [data-bid-kind="${priorChoice?.kind || ""}"]`;
            renderAndFocus(selector);
          });
          actions.append(confirm, back);
          dialog.append(actions);
        };
        if (selectedSpadesBid?.confirming) {
          appendConfirmation(selectedSpadesBid);
        } else if (handNotViewed) {
          dialog.append(make("h3", "", eligibility.blindOffer === true ? "Do you want to bid Blind Nil?" : "View your cards to bid"));
          const actions = make("div", "spades-dialog-actions");
          const show = make("button", "", "Show Cards");
          show.addEventListener("click", () => performAction("view-hand"));
          actions.append(show);
          if (eligibility.blindOffer === true) {
            const blind = make("button", "", "Bid Blind Nil");
            blind.dataset.bidKind = "blind-nil";
            blind.addEventListener("click", () => {
              selectedSpadesBid = { kind: "blind-nil", amount: 0, label: "Blind Nil", role, confirming: true };
              renderAndFocus(".spades-confirm-bid");
            });
            actions.append(blind);
          }
          dialog.append(actions);
        } else if (!handNotViewed) {
          dialog.append(make("h3", "", "How many tricks can you take?"));
          if (partnerBid) {
            const partnerKind = String(partnerBid.kind || "standard");
            const partnerText = partnerKind === "standard" ? `${Number(partnerBid.amount)} tricks` : partnerKind === "blind-nil" ? "Blind Nil" : "Nil";
            dialog.append(make("p", "spades-private-hint", `${memberName(partnerBid.fromUserId)} bid ${partnerText}. Your bid will be combined with your partner's bid.`));
          }
          if (minimumTeamBid > 0) {
            const boardMessage = role === "team"
              ? `Board ${minimumTeamBid} is active. Your standard bid must be at least ${minimumStandardBid} so the combined team contract reaches ${minimumTeamBid}. Matching Double Nil or Double Blind Nil is exempt.`
              : `Board ${minimumTeamBid} is active. Your partner's bid will complete the minimum team contract. Matching Double Nil or Double Blind Nil is exempt.`;
            dialog.append(make("p", "spades-private-hint spades-board-minimum", boardMessage));
          }
          const picker = make("div", "spades-bid-choices");
          picker.setAttribute("role", "group");
          picker.setAttribute("aria-label", "Choose your individual bid");
          for (let amount = 1; amount <= 13; amount++) {
            const selected = selectedSpadesBid?.kind === "standard" && selectedSpadesBid?.amount === amount;
            const button = make("button", `spades-bid-choice${selected ? " is-selected" : ""}`, String(amount));
            button.type = "button";
            button.dataset.bidKind = "standard";
            button.dataset.bidAmount = String(amount);
            button.setAttribute("aria-pressed", selected ? "true" : "false");
            button.disabled = amount < minimumStandardBid;
            if (button.disabled) button.title = `Board ${minimumTeamBid} requires at least ${minimumStandardBid} from you.`;
            button.addEventListener("click", () => {
              selectedSpadesBid = { kind: "standard", amount, label: `${amount} tricks`, role, confirming: false };
              renderAndFocus(`.spades-bid-choice[data-bid-amount="${amount}"]`);
            });
            picker.append(button);
          }
          const actions = make("div", "spades-dialog-actions");
          const submitNumeric = make("button", "spades-place-bid", "Place Your Bid");
          submitNumeric.disabled = selectedSpadesBid?.kind !== "standard";
          submitNumeric.addEventListener("click", () => {
            if (selectedSpadesBid?.kind !== "standard") return;
            selectedSpadesBid = { ...selectedSpadesBid, confirming: true };
            renderAndFocus(".spades-confirm-bid");
          });
          const nil = make("button", "", "Bid Nil");
          nil.dataset.bidKind = "nil";
          nil.disabled = eligibility.nil === false;
          if (nil.disabled) nil.title = `This Nil would leave the team contract below Board ${minimumTeamBid}.`;
          nil.addEventListener("click", () => {
            selectedSpadesBid = { kind: "nil", amount: 0, label: "Nil", role, confirming: true };
            renderAndFocus(".spades-confirm-bid");
          });
          actions.append(submitNumeric, nil);
          dialog.append(picker, actions);
        }
        if (dialog.childElementCount > 0) {
          const heading = dialog.querySelector("h3");
          if (heading) {
            heading.id = "spades-action-dialog-title";
            dialog.setAttribute("aria-labelledby", heading.id);
          }
          group.append(dialog);
        }
      }
    } else if (session.state?.phase === "partner-pass") {
      const passes = Object.values(session.state?.partnerPasses || {});
      const offer = passes.find(pass => Number(pass.fromUserId) === currentUserId() && pass.status === "awaiting-offer");
      const response = passes.find(pass => Number(pass.toUserId) === currentUserId() && pass.status === "awaiting-response");
      if (offer) {
        const requiredCount = Math.max(1, Math.min(2, Number(offer.cardCount || 2)));
        const submit = make("button", "", `Confirm ${requiredCount === 1 ? "one card" : "two cards"} to partner`);
        submit.disabled = !canAct() || selectedSpadesPassCards.length !== requiredCount;
        submit.addEventListener("click", async () => {
          const cards = [...selectedSpadesPassCards];
          if (await performAction("offer-partner-pass", { cards })) { selectedSpadesPassCards = []; render(); }
        });
        group.append(make("p", "minor", `Select exactly ${requiredCount === 1 ? "one card" : "two cards"} in your hand. ${selectedSpadesPassCards.length} selected.`), submit);
      } else if (response) {
        const exchangeMode = String(response.exchangeMode || session.state?.settings?.exchangeMode || "modern");
        const requiredCount = Math.max(1, Math.min(2, Number(response.cardCount || 2)));
        const regularNil = response.trigger === "regular-nil";
        const allowDecline = Object.hasOwn(response, "allowDecline") ? response.allowDecline === true : exchangeMode === "modern";
        const accept = make("button", "", allowDecline ? "Accept and exchange" : `Confirm ${requiredCount === 1 ? "one" : "two"} return ${requiredCount === 1 ? "card" : "cards"}`);
        accept.disabled = !canAct() || selectedSpadesPassCards.length !== requiredCount;
        accept.addEventListener("click", async () => {
          const returnCards = [...selectedSpadesPassCards];
          if (await performAction("respond-partner-pass", { accept: true, returnCards })) { selectedSpadesPassCards = []; render(); }
        });
        const decline = make("button", "", "Decline partner hint"); decline.disabled = !canAct();
        decline.addEventListener("click", () => performAction("respond-partner-pass", { accept: false }));
        group.append(make("p", "minor", regularNil
          ? `Regular Nil exchange requires exactly one return card. ${selectedSpadesPassCards.length} selected.`
          : allowDecline
            ? `Select two return cards to accept, or decline without changing either hand. ${selectedSpadesPassCards.length} selected.`
            : `Blind Nil Legacy exchange requires exactly two return cards. ${selectedSpadesPassCards.length} selected.`), accept);
        if (allowDecline) group.append(decline);
      }
    } else if (session.state?.phase === "playing" && selectedSpadesCard) {
      const cancelCard = make("button", "", `Cancel selected ${cardLabel(selectedSpadesCard)}`);
      cancelCard.type = "button";
      cancelCard.addEventListener("click", () => {
        selectedSpadesCard = null;
        render();
      });
      group.append(make("p", "minor", `${cardLabel(selectedSpadesCard)} is selected. Activate it again to play, choose another legal card, or cancel.`), cancelCard);
    }
  }
  const captureAuditId = checkersCaptureAuditId() || chessCaptureAuditId();
  if (captureAuditId) {
    const undoTestMove = make("button", "capture-audit-undo", "Undo test move");
    undoTestMove.type = "button";
    undoTestMove.disabled = busy;
    undoTestMove.title = `Local capture audit ${captureAuditId}: restore the repeatable final move`;
    undoTestMove.addEventListener("click", context.extensionId === "chess"
      ? resetChessCaptureAuditFixture
      : resetCheckersCaptureAuditFixture);
    group.append(undoTestMove);
  }
  const unoAuditId = unoCallAuditId();
  if (unoAuditId) {
    const resetUnoCall = make("button", "capture-audit-undo", "Reset UNO call test");
    resetUnoCall.type = "button";
    resetUnoCall.disabled = busy;
    resetUnoCall.title = `Local UNO call audit ${unoAuditId}: restore the repeatable declaration`;
    resetUnoCall.addEventListener("click", resetUnoCallAuditFixture);
    group.append(resetUnoCall);
  }
  const spadesAuditId = spadesNilAuditId();
  if (spadesAuditId) {
    const triggerNilFailure = make("button", "capture-audit-undo", "Trigger Nil failure test");
    triggerNilFailure.type = "button";
    triggerNilFailure.disabled = busy;
    triggerNilFailure.title = `Local Spades Nil audit ${spadesAuditId}: trigger the viewer-relative cue`;
    triggerNilFailure.addEventListener("click", triggerSpadesNilAuditFixture);
    group.append(triggerNilFailure);
  }
  if (!gameSessionIsTerminal() && !(context.extensionId === "checkers" && session.presentation?.effectivePack === "classic")) {
    const resignBase = context.extensionId === "chess" ? "gif-rsgn" : "";
    const resign = applyClassicActionArt(make("button", "", "Resign game"), resignBase);
    resign.dataset.externalResign = "true";
    resign.disabled = !gameLifecycleAvailable()
      || (["checkers", "chess"].includes(context.extensionId) && Number(session.state?.drawOfferBy || 0) > 0)
      || ["paused", "resuming"].includes(String(session.state?._framework?.pause?.mode || "running"));
    resign.addEventListener("click", () => performAction("resign"));
    resignHost.append(resign);
  }
  if (group.childElementCount > 0) {
    const unoActions = context.extensionId === "uno" ? document.querySelector(".uno-board-actions") : null;
    (unoActions || host).append(group);
  }
  syncExternalResignVisibility();
}

async function toggleSound() {
  const next = { ...options, effectsEnabled: options?.effectsEnabled === false };
  if (await updateViewerOptions(next)) render();
}

async function toggleMusic() {
  const next = { ...options, musicEnabled: options?.musicEnabled !== true };
  if (await updateViewerOptions(next)) render();
  await syncMusic();
}

async function changePresentation(packId) {
  clearTimeout(battleshipSelectionTimer);
  battleshipSelectionTimer = 0;
  selectedSquare = null;
  selectedPointOrigin = null;
  selectedBattleshipShipId = "";
  selectedSpadesCard = null;
  selectedSpadesBid = null;
  selectedSpadesPassCards = [];
  previewDestination = null;
  touchPreviewDestination = null;
  openCheckersDrawer = null;
  pendingChessPromotion = null;
  clearSpadesAutomaticAction();
  await apiPost("presentation-pack", { game_key: context.gameKey, pack_id: packId });
  await refreshSession(true);
}

function renderSurfaceOptions() {
  const soundHost = el("surface-options"); soundHost.replaceChildren();
  const visualHost = el("surface-options-visual"); visualHost.replaceChildren();
  const musicHost = el("surface-options-music"); musicHost.replaceChildren();
  const soundLabel = "Sound FX";
  const sound = make("button", "", `${soundLabel} ${options?.effectsEnabled === false ? "Off" : "On"}`);
  sound.dataset.separateControl = "soundFx";
  sound.hidden = true;
  sound.type="button"; sound.setAttribute("aria-pressed", options?.effectsEnabled === false ? "false" : "true");
  sound.addEventListener("click", () => toggleSound().catch(error => { el("status").textContent=error.message; })); soundHost.append(sound);
  if (musicSlot()) {
    const music = make("button", "", options?.musicEnabled === true ? "Music On" : "Music Off");
    music.dataset.separateControl = "music";
    music.hidden = true;
    music.type = "button"; music.setAttribute("aria-pressed", options?.musicEnabled === true ? "true" : "false");
    music.addEventListener("click", () => toggleMusic().catch(error => { el("status").textContent=error.message; })); musicHost.append(music);
  }
  if (["checkers", "chess", "acey-deucy", "battleship", "backgammon-first-party", "spades"].includes(context.extensionId)) {
    const visual = make("button", "", `Visual FX ${optionCategory("visualFxEnabled", true) ? "On" : "Off"}`);
    visual.dataset.separateControl = "visualFx";
    visual.hidden = true;
    visual.type = "button";
    visual.setAttribute("aria-pressed", optionCategory("visualFxEnabled", true) ? "true" : "false");
    visual.addEventListener("click", () => toggleOptionCategory("visualFxEnabled", true).catch(error => { el("status").textContent = error.message; }));
    visualHost.append(visual);
  }
  renderSeparateControlsPreference();
  requestAnimationFrame(syncSeparateControlVisibility);
}

function syncExternalResignVisibility() {
  const external = document.querySelector("[data-external-resign]");
  if (!external) return;
  const hotspot = document.querySelector('[data-classic-action="resign"]');
  const classic = session?.presentation?.effectivePack === "classic";
  const sourceArtAvailable = ["pending", "true"].includes(hotspot?.dataset.hotspotReady || "");
  const box = hotspot?.getBoundingClientRect();
  const minimumHotspotSize = window.matchMedia?.("(pointer: coarse)")?.matches ? 44 : 24;
  const safeHotspot = Boolean(classic && sourceArtAvailable && box
    && box.width >= minimumHotspotSize && box.height >= minimumHotspotSize);
  external.hidden = safeHotspot;
  external.dataset.classicHotspotSafe = safeHotspot ? "true" : "false";
}

function renderSeparateControlsPreference() {
  const enabled = optionCategory("separateControlButtons", false);
  const toggle = el("separate-controls-toggle");
  if (toggle) {
    toggle.textContent = enabled ? "On" : "Off";
    toggle.setAttribute("aria-pressed", enabled ? "true" : "false");
  }
  document.body.dataset.separateControlButtons = enabled ? "on" : "off";
}

let gameOptionHelpSequence = 0;

function closeCompactGameOptionHelp(except = null) {
  for (const row of document.querySelectorAll(".game-setting.is-help-open")) {
    if (row === except) continue;
    row.classList.remove("is-help-open");
    row.querySelector(".setting-info-button")?.setAttribute("aria-expanded", "false");
  }
}

function compactGameOptionHelp(root = document) {
  for (const help of root.querySelectorAll(".game-setting > .minor:not([data-option-help])")) {
    const row = help.parentElement;
    const label = row?.firstElementChild;
    const helpText = help.textContent.trim();
    const labelText = label?.textContent.trim() || "this option";
    if (!row || !label || !helpText) continue;
    help.dataset.optionHelp = "true";
    help.classList.add("setting-info-popover");
    help.id ||= `game-option-help-${++gameOptionHelpSequence}`;
    help.setAttribute("role", "tooltip");
    const button = make("button", "setting-info-button", "i");
    button.type = "button";
    button.title = helpText;
    button.setAttribute("aria-label", `About ${labelText}`);
    button.setAttribute("aria-describedby", help.id);
    button.setAttribute("aria-expanded", "false");
    button.addEventListener("click", event => {
      event.preventDefault();
      event.stopPropagation();
      const opening = !row.classList.contains("is-help-open");
      closeCompactGameOptionHelp(row);
      row.classList.toggle("is-help-open", opening);
      button.setAttribute("aria-expanded", opening ? "true" : "false");
    });
    button.addEventListener("keydown", event => {
      if (event.key !== "Escape") return;
      row.classList.remove("is-help-open");
      button.setAttribute("aria-expanded", "false");
      button.focus();
    });
    label.classList.add("setting-label-with-info");
    label.append(button);
  }
}

const gameOptionHelpRoot = document.getElementById("game-root") || document.body;
new MutationObserver(() => compactGameOptionHelp(gameOptionHelpRoot)).observe(gameOptionHelpRoot, { childList: true, subtree: true });
document.addEventListener("click", event => {
  if (!event.target.closest?.(".setting-info-button")) closeCompactGameOptionHelp();
}, true);
compactGameOptionHelp(gameOptionHelpRoot);

function syncSeparateControlVisibility() {
  if (!options) return;
  const preferenceOn = optionCategory("separateControlButtons", false);
  const classic = session?.presentation?.effectivePack === "classic";
  for (const modern of document.querySelectorAll("[data-separate-control]")) {
    const controlId = modern.dataset.separateControl;
    const hotspot = document.querySelector(`[data-classic-control="${controlId}"]`);
    const sourceArtAvailable = ["pending", "true"].includes(hotspot?.dataset.hotspotReady || "");
    const box = hotspot?.getBoundingClientRect();
    const minimumHotspotSize = window.matchMedia?.("(pointer: coarse)")?.matches ? 44 : 24;
    const safeHotspot = Boolean(classic && sourceArtAvailable && box
      && box.width >= minimumHotspotSize && box.height >= minimumHotspotSize);
    modern.hidden = controlId === "music" ? safeHotspot : (!preferenceOn && safeHotspot);
    modern.dataset.classicHotspotSafe = safeHotspot ? "true" : "false";
  }
}

function settingValue(control, value) {
  if (control.type === "checkbox") return Boolean(value);
  if (control.type === "stepper") return Number(value);
  const numeric = (control.options || []).every(option => Number.isFinite(Number(option.value)));
  return numeric ? Number(value) : safe(value);
}

function renderViewerGameOptions() {
  const section = make("section", "viewer-game-options");
  section.append(make("h3", "", "Your options"));
  const grid = make("div", "game-settings-grid");
  const originalAudioOptions = renderOriginalAudioOptions();
  if (originalAudioOptions) section.append(originalAudioOptions);
  if (session?.presentation?.selectionOwner === "viewer") {
    const wrapper = make("div", "game-setting appearance-preference");
    const label = make("span", "game-setting-label", "Appearance");
    label.id = "appearance-preference-label";
    const choices = make("div", "setting-choice-group appearance-choice-group");
    choices.setAttribute("role", "group");
    choices.setAttribute("aria-labelledby", label.id);
    for (const pack of session.presentation.packs || []) {
      const classicUnavailable = pack.id === "classic" && session.presentation.classicAvailable !== true;
      const button = make("button", "setting-choice-button", `${safe(pack.label)}${classicUnavailable ? " (Unavailable)" : ""}`);
      button.type = "button";
      button.dataset.presentationPack = safe(pack.id);
      button.dataset.requested = pack.id === session.presentation.requestedPack ? "true" : "false";
      const selected = pack.id === session.presentation.effectivePack;
      button.classList.toggle("is-selected", selected);
      button.setAttribute("aria-pressed", selected ? "true" : "false");
      button.disabled = classicUnavailable;
      if (classicUnavailable) button.title = "The installation-private Original OCX media pack is unavailable or incomplete.";
      button.addEventListener("click", () => changePresentation(pack.id).catch(error => { el("status").textContent = error.message; }));
      choices.append(button);
    }
    wrapper.append(label, choices);
    if (session.presentation.fallbackApplied === true) {
      wrapper.append(make("span", "minor appearance-fallback-notice", "Original OCX was requested, but its installation-private media pack is unavailable or incomplete. Built-in is active."));
    }
    grid.append(wrapper);
  }
  if (["checkers", "chess", "acey-deucy", "backgammon-first-party", "chinese-checkers", "nested-four"].includes(context.extensionId)) {
    const chineseCheckersLegalInfo = ["chinese-checkers", "nested-four"].includes(context.extensionId);
    const wrapper = make("div", `game-setting${chineseCheckersLegalInfo ? " viewer-effect-option" : ""}`);
    const legalDefault = ["chinese-checkers", "nested-four"].includes(context.extensionId);
    const legal = make("button", "compact-state-button", optionCategory("showLegalMoves", legalDefault) ? "On" : "Off");
    legal.type = "button";
    legal.setAttribute("aria-pressed", optionCategory("showLegalMoves", legalDefault) ? "true" : "false");
    legal.setAttribute("aria-label", `Show legal moves ${legal.textContent}`);
    legal.addEventListener("click", () => toggleOptionCategory("showLegalMoves", legalDefault).catch(error => { el("status").textContent = error.message; }));
    const legalLabel = make("span", "game-setting-label", "Show legal moves");
    if (chineseCheckersLegalInfo) {
      const info = make("button", "inline-info", "i");
      info.type = "button";
      info.setAttribute("aria-label", context.extensionId === "nested-four" ? "About Nested Four legal moves" : "About Chinese Checkers legal moves");
      info.setAttribute("aria-expanded", "false");
      const help = make("div", "compact-info-popover", context.extensionId === "nested-four"
        ? "On shows every legal destination for the committed piece. Off reveals a destination on hover, focus, or touch preview. Selecting a piece remains a committed action."
        : "On shows every legal destination after selecting a marble. Off reveals a legal destination on hover, focus, or touch preview. Selection itself always remains visible.");
      help.hidden = true;
      info.addEventListener("click", () => {
        help.hidden = !help.hidden;
        info.setAttribute("aria-expanded", help.hidden ? "false" : "true");
      });
      const labelWithInfo = make("span", "setting-label-with-info");
      labelWithInfo.append(legalLabel, info);
      wrapper.append(labelWithInfo, legal, help);
    } else {
      wrapper.append(legalLabel, legal);
      wrapper.append(make("span", "minor", "Off shows the authentic cue only for the legal destination you hover, focus, or preview by touch. On shows the same cue at every legal destination."));
    }
    grid.append(wrapper);
  }
  if (supportsBuiltInHeightFit()) {
    const wrapper = make("div", "game-setting viewer-effect-option");
    const label = make("span", "game-setting-label", "Fit available height");
    label.id = "game-height-fit-label";
    const enabled = builtInHeightFitEnabled();
    const toggle = make("button", "compact-state-button", enabled ? "On" : "Off");
    toggle.id = "game-height-fit-toggle";
    toggle.type = "button";
    toggle.setAttribute("aria-pressed", String(enabled));
    toggle.setAttribute("aria-labelledby", "game-height-fit-label game-height-fit-toggle");
    toggle.addEventListener("click", () => {
      setViewerHeightFit(context.extensionId, !builtInHeightFitEnabled());
      render();
    });
    wrapper.append(label, toggle, make("span", "minor", "Personal to this game and browser. On shrinks the whole board toward the available height, stopping at a readable minimum before scrolling. Off keeps normal height sizing. Board size, where available, remains separate."));
    grid.append(wrapper);
  }
  if (supportsBuiltInBoardScale()) {
    const wrapper = make("div", "game-setting viewer-effect-option");
    const index = builtInBoardScaleIndex();
    const displayName = safe(session.displayName || context.fallbackName);
    const smaller = make("button", "", "-");
    const larger = make("button", "", "+");
    smaller.type = "button"; larger.type = "button";
    smaller.disabled = index === 0;
    larger.disabled = index === builtInBoardScaleSteps.length - 1;
    smaller.setAttribute("aria-label", "Make " + displayName + " board smaller");
    larger.setAttribute("aria-label", "Make " + displayName + " board larger");
    smaller.addEventListener("click", () => changeBuiltInBoardScale(-1));
    larger.addEventListener("click", () => changeBuiltInBoardScale(1));
    const output = make("output", "", Math.round(builtInBoardScaleSteps[index] * 100) + "%");
    output.setAttribute("aria-label", displayName + " board size");
    const controls = make("span", "classic-board-scale-controls");
    controls.setAttribute("role", "group");
    controls.setAttribute("aria-label", displayName + " board size controls");
    controls.append(smaller, output, larger);
    wrapper.append(make("span", "game-setting-label", "Board size"), controls,
      make("span", "minor", "Viewer-local, 50%-150%. Fit available height is a separate option. Larger settings use page scrolling; width stays inside the room. Smaller settings also reduce text and controls."));
    grid.append(wrapper);
  }
  if (supportsViewerBoardScale()) {
    const wrapper = make("div", "game-setting viewer-effect-option");
    const source = classicSourceMap(context.extensionId);
    const scaleIndex = classicBoardScaleIndex();
    const smaller = make("button", "", "−");
    const larger = make("button", "", "+");
    const displayName = safe(session.displayName || context.fallbackName);
    smaller.type = "button";
    larger.type = "button";
    smaller.disabled = scaleIndex === 0;
    larger.disabled = scaleIndex === classicBoardScaleSteps.length - 1;
    smaller.setAttribute("aria-label", `Make ${displayName} board smaller`);
    larger.setAttribute("aria-label", `Make ${displayName} board larger`);
    smaller.addEventListener("click", () => changeClassicBoardScale(-1));
    larger.addEventListener("click", () => changeClassicBoardScale(1));
    const output = make("output", "", `${Math.round(classicBoardScale() * 100)}%`);
    output.setAttribute("aria-label", `${displayName} board size`);
    const controls = make("span", "classic-board-scale-controls");
    controls.setAttribute("role", "group");
    controls.setAttribute("aria-label", `${displayName} board size controls`);
    controls.append(smaller, output, larger);
    wrapper.append(
      make("span", "game-setting-label", "Board size"),
      controls,
      make("span", "minor", `Viewer-local. 100% is the original ${Number(source?.canvas?.width || 0)} × ${Number(source?.canvas?.height || 0)} OCX canvas; responsive shrinking prevents overflow.`),
    );
    grid.append(wrapper);
  }
  if (context.extensionId === "chinese-checkers") {
    window.CoreChatChineseCheckers?.appendBoardSizeOption({ grid, make, rerender:render });
  }
  if (context.extensionId === "nested-four") {
    window.CoreChatNestedFour?.appendBoardSizeOption({ grid, make, rerender:render });
  }
  if (["tetris-versus", "space-invasion"].includes(context.extensionId)) {
    window.CoreChatArcade?.appendOptions({ grid, make });
  }
  {
    const wrapper = make("div", "game-setting viewer-effect-option");
    const label = make("span", "game-setting-label", "Sound FX");
    label.id = "game-options-sound-fx-label";
    const toggle = make("button", "compact-state-button", options?.effectsEnabled === false ? "Off" : "On");
    toggle.id = "game-options-sound-fx-toggle";
    toggle.type = "button";
    toggle.setAttribute("aria-pressed", options?.effectsEnabled === false ? "false" : "true");
    toggle.setAttribute("aria-labelledby", `${label.id} ${toggle.id}`);
    toggle.addEventListener("click", () => toggleSound().catch(error => { el("status").textContent = error.message; }));
    wrapper.append(label, toggle);
    grid.append(wrapper);
  }
  if (["checkers", "chess", "acey-deucy", "battleship", "backgammon-first-party", "spades", "chinese-checkers", "nested-four"].includes(context.extensionId)) {
    const wrapper = make("div", "game-setting viewer-effect-option");
    const label = make("span", "game-setting-label", "Visual FX");
    label.id = "game-options-visual-fx-label";
    const toggle = make("button", "compact-state-button", optionCategory("visualFxEnabled", true) ? "On" : "Off");
    toggle.id = "game-options-visual-fx-toggle";
    toggle.type = "button";
    toggle.setAttribute("aria-pressed", optionCategory("visualFxEnabled", true) ? "true" : "false");
    toggle.setAttribute("aria-labelledby", `${label.id} ${toggle.id}`);
    toggle.addEventListener("click", () => toggleOptionCategory("visualFxEnabled", true).catch(error => { el("status").textContent = error.message; }));
    wrapper.append(label, toggle);
    grid.append(wrapper);
  }
  {
    const wrapper = make("div", "game-setting viewer-audio-volume-option");
    const label = make("label", "game-setting-label", "Master volume");
    label.htmlFor = "game-options-master-volume";
    const controls = make("span", "setting-choice-group viewer-audio-volume-control");
    const volume = document.createElement("input");
    volume.id = "game-options-master-volume";
    volume.type = "range";
    volume.min = "0";
    volume.max = "100";
    volume.step = "1";
    volume.value = String(Math.max(0, Math.min(100, Number(options?.masterVolume ?? 100))));
    const output = make("output", "", `${volume.value}%`);
    output.htmlFor = volume.id;
    const decrease = make("button", "viewer-audio-volume-step", "−");
    const increase = make("button", "viewer-audio-volume-step", "+");
    decrease.type = "button";
    increase.type = "button";
    decrease.setAttribute("aria-label", "Decrease master volume by 5");
    increase.setAttribute("aria-label", "Increase master volume by 5");
    let saveTimer = 0;
    const syncVolumeControls = () => {
      const current = Math.max(0, Math.min(100, Number(volume.value || 0)));
      output.textContent = `${current}%`;
      decrease.disabled = current <= 0;
      increase.disabled = current >= 100;
    };
    const saveVolume = () => {
      clearTimeout(saveTimer);
      saveTimer = window.setTimeout(() => {
        saveTimer = 0;
        const masterVolume = Math.max(0, Math.min(100, Number(volume.value || 0)));
        updateViewerOptions({ ...options, masterVolume })
          .then(async updated => { if (updated) { render(); await syncMusic(); } })
          .catch(error => { el("status").textContent = error.message; });
      }, 300);
    };
    const stepVolume = delta => {
      volume.value = String(Math.max(0, Math.min(100, Number(volume.value || 0) + delta)));
      syncVolumeControls();
      saveVolume();
    };
    volume.addEventListener("input", syncVolumeControls);
    volume.addEventListener("change", saveVolume);
    decrease.addEventListener("click", () => stepVolume(-5));
    increase.addEventListener("click", () => stepVolume(5));
    syncVolumeControls();
    controls.append(decrease, volume, output, increase);
    wrapper.append(label, controls);
    grid.append(wrapper);
  }
  for (const [key, text, syncAfterSave] of [
    ["musicEnabled", "Music", true],
    ["voiceEnabled", "Voice", false],
  ]) {
    const wrapper = make("div", "game-setting viewer-effect-option");
    const label = make("span", "game-setting-label", text);
    const enabled = options?.[key] !== false;
    const toggle = make("button", "compact-state-button", enabled ? "On" : "Off");
    const idPart = key === "musicEnabled" ? "music" : "voice";
    label.id = `game-options-${idPart}-label`;
    toggle.id = `game-options-${idPart}-toggle`;
    toggle.type = "button";
    toggle.setAttribute("aria-pressed", enabled ? "true" : "false");
    toggle.setAttribute("aria-labelledby", `${label.id} ${toggle.id}`);
    toggle.addEventListener("click", () => {
      updateViewerOptions({ ...options, [key]: !enabled })
        .then(async updated => { if (updated) { render(); if (syncAfterSave) await syncMusic(); } })
        .catch(error => { el("status").textContent = error.message; });
    });
    wrapper.append(label, toggle);
    grid.append(wrapper);
  }
  {
    const wrapper = make("div", "game-setting viewer-effect-option viewer-audio-reset-option");
    const label = make("span", "game-setting-label", "Audio defaults");
    const reset = make("button", "compact-state-button", "Reset");
    reset.type = "button";
    reset.addEventListener("click", () => {
      updateViewerOptions({
        ...options,
        masterVolume: 100,
        musicEnabled: true,
        voiceEnabled: true,
        effectsEnabled: true,
      }).then(async updated => { if (updated) { render(); await syncMusic(); } })
        .catch(error => { el("status").textContent = error.message; });
    });
    wrapper.append(label, reset);
    grid.append(wrapper);
  }
  if (context.extensionId === "chess" && session?.presentation?.effectivePack === "built-in") {
    const wrapper = make("div", "game-setting chess-piece-style-preference");
    const label = make("span", "game-setting-label", "Piece style");
    label.id = "chess-piece-style-label";
    const choices = make("div", "setting-choice-group");
    choices.setAttribute("role", "group");
    choices.setAttribute("aria-labelledby", label.id);
    const current = chessPieceStyleMode();
    for (const [value, text] of [["sculpted", "Sculpted pieces"], ["unicode", "Unicode pieces"]]) {
      const button = make("button", "setting-choice-button", text);
      button.type = "button";
      button.dataset.chessPieceStyle = value;
      button.classList.toggle("is-selected", value === current);
      button.setAttribute("aria-pressed", value === current ? "true" : "false");
      button.addEventListener("click", () => setChessPieceStyleMode(value).catch(error => { el("status").textContent = error.message; }));
      choices.append(button);
    }
    wrapper.append(label, choices, make("span", "minor", "Sculpted pieces are the default. This preference changes only this viewer's Built-in Chess board."));
    grid.append(wrapper);
  }
  if (["acey-deucy", "backgammon-first-party"].includes(context.extensionId)
    && session?.presentation?.effectivePack === "built-in") {
    const wrapper = make("div", "game-setting point-checker-style-preference");
    const label = make("span", "game-setting-label", "Checker style");
    label.id = "point-checker-style-label";
    const choices = make("div", "setting-choice-group");
    choices.setAttribute("role", "group");
    choices.setAttribute("aria-labelledby", label.id);
    const current = pointCheckerStyleMode();
    for (const [value, text] of [["high-quality", "Sculpted checkers"], ["css", "CSS-rendered checkers"]]) {
      const button = make("button", "setting-choice-button", text);
      button.type = "button";
      button.dataset.pointCheckerStyle = value;
      button.classList.toggle("is-selected", value === current);
      button.setAttribute("aria-pressed", value === current ? "true" : "false");
      button.addEventListener("click", () => setPointCheckerStyleMode(value).catch(error => { el("status").textContent = error.message; }));
      choices.append(button);
    }
    wrapper.append(label, choices, make("span", "minor", "Sculpted checkers are the default. This viewer-local preference keeps the CSS-rendered checkers available as a fallback."));
    grid.append(wrapper);
  }
  if (context.extensionId === "chess") {
    const wrapper = make("div", "game-setting chess-piece-size-preference");
    const label = make("span", "game-setting-label", "Piece size");
    label.id = "chess-piece-size-label";
    const choices = make("div", "setting-choice-group");
    choices.setAttribute("role", "group");
    choices.setAttribute("aria-labelledby", label.id);
    const current = chessPieceSizeMode();
    for (const [value, text] of [["normal", "Normal"], ["smaller", "Smaller"]]) {
      const button = make("button", "setting-choice-button", text);
      button.type = "button";
      button.dataset.chessPieceSize = value;
      button.classList.toggle("is-selected", value === current);
      button.setAttribute("aria-pressed", value === current ? "true" : "false");
      button.addEventListener("click", () => setChessPieceSizeMode(value).catch(error => { el("status").textContent = error.message; }));
      choices.append(button);
    }
    wrapper.append(label, choices, make("span", "minor", "Normal is the default. Smaller changes only this viewer's complete piece presentation."));
    grid.append(wrapper);
  }
  if (context.extensionId === "chess" && session?.presentation?.effectivePack === "corechat") {
    const wrapper = make("div", "game-setting corechat-chess-coordinate-option");
    const label = make("span", "game-setting-label", "Show board coordinates");
    const toggle = make("button", "compact-state-button", coreChatChessCoordinatesEnabled ? "On" : "Off");
    toggle.type = "button";
    toggle.setAttribute("aria-pressed", coreChatChessCoordinatesEnabled ? "true" : "false");
    toggle.setAttribute("aria-label", `Show board coordinates ${coreChatChessCoordinatesEnabled ? "On" : "Off"}`);
    toggle.addEventListener("click", () => {
      coreChatChessCoordinatesEnabled = !coreChatChessCoordinatesEnabled;
      try {
        gameViewStorage.setItem(
          "corechat-chess-board-coordinates",
          coreChatChessCoordinatesEnabled ? "true" : "false",
        );
      } catch (_error) {
        // The current viewer still receives the setting when storage is unavailable.
      }
      render();
    });
    wrapper.append(label, toggle, make("span", "minor", "Shows a-h and 1-8 as a viewer-local overlay on CoreChat artwork."));
    grid.append(wrapper);
  }
  if (context.extensionId === "spades") {
    const wrapper = make("div", "game-setting spades-card-activation-preference");
    const label = make("span", "game-setting-label", "Card activation");
    label.id = "spades-card-activation-label";
    const choices = make("div", "setting-choice-group");
    choices.setAttribute("role", "group");
    choices.setAttribute("aria-labelledby", label.id);
    const current = spadesCardActivationMode();
    for (const [value, text] of [["two-step", "Two-step card confirmation"], ["legacy-ocx-one-click", "Legacy OCX one-click play"]]) {
      const button = make("button", "setting-choice-button", text);
      button.type = "button";
      button.dataset.cardActivationMode = value;
      button.classList.toggle("is-selected", value === current);
      button.setAttribute("aria-pressed", value === current ? "true" : "false");
      button.addEventListener("click", () => setSpadesCardActivationMode(value).catch(error => { el("status").textContent = error.message; }));
      choices.append(button);
    }
    wrapper.append(label, choices, make("span", "minor", "This account-wide viewer preference changes only how you confirm a legal card. It never changes shared rules or the retained card slide."));
    grid.append(wrapper);
  }
  {
    const wrapper = make("div", "game-setting separate-controls-preference");
    const label = make("span", "game-setting-label setting-label-with-info", "Show separate sound and visual controls");
    label.id = "separate-controls-label";
    const toggle = make("button", "compact-state-button", optionCategory("separateControlButtons", false) ? "On" : "Off");
    toggle.id = "separate-controls-toggle";
    toggle.type = "button";
    toggle.setAttribute("aria-pressed", optionCategory("separateControlButtons", false) ? "true" : "false");
    toggle.setAttribute("aria-labelledby", "separate-controls-label separate-controls-toggle");
    toggle.addEventListener("click", () => toggleOptionCategory("separateControlButtons", false).catch(error => { el("status").textContent = error.message; }));
    const info = make("button", "inline-info", "i");
    info.id = "separate-controls-info";
    info.type = "button";
    info.setAttribute("aria-expanded", "false");
    info.setAttribute("aria-controls", "separate-controls-help");
    info.setAttribute("aria-label", "About separate sound and visual controls");
    const help = make("div", "compact-info-popover", "When Off in Classic appearance, safe artwork controls replace matching separate buttons. Buttons remain available whenever an artwork control is unavailable or too small to use safely.");
    help.id = "separate-controls-help";
    help.setAttribute("role", "note");
    help.hidden = true;
    info.addEventListener("click", () => {
      help.hidden = !help.hidden;
      info.setAttribute("aria-expanded", help.hidden ? "false" : "true");
    });
    label.append(info);
    wrapper.append(label, toggle, help);
    grid.append(wrapper);
  }
  section.append(grid);
  return { section, hasOptions: grid.childElementCount > 0 };
}

let recordedReadyPromptPosition = null;

function roomOwnsRecordedReadiness() {
  try {
    const frame = window.frameElement;
    if (window.parent === window || !frame || !context.gameSessionId
        || frame.getAttribute("data-recorded-readiness-owner") !== "room"
        || frame.getAttribute("data-recorded-readiness-session") !== context.gameSessionId) return false;
    const source = frame.getAttribute("src");
    if (!source) return false;
    const url = new URL(source, location.href);
    return url.origin === location.origin && url.pathname === location.pathname && url.search === location.search;
  } catch (_error) {
    return false;
  }
}

function makeRecordedReadyPrompt() {
  const prompt = make("section", "recorded-ready-prompt");
  prompt.setAttribute("role", "dialog");
  prompt.setAttribute("aria-labelledby", "recorded-ready-prompt-title");
  const handle = make("h3", "recorded-ready-prompt-handle", "Ready to begin?");
  handle.id = "recorded-ready-prompt-title";
  handle.tabIndex = 0;
  const message = make("p", "", "Every player accepted the rules. Confirm that both sides are ready before the ranked game begins.");
  const start = make("button", "recorded-ready-prompt-start", "Both sides are ready");
  start.type = "button";
  start.disabled = busy;
  start.addEventListener("click", async () => {
    if (busy) return;
    busy = true;
    render();
    try {
      await apiPost("start");
      await refreshSession(false);
    } catch (error) {
      el("status").textContent = error.message;
    } finally {
      busy = false;
      render();
    }
  });
  prompt.append(handle, message, start);

  if (recordedReadyPromptPosition) {
    prompt.style.left = recordedReadyPromptPosition.left + "px";
    prompt.style.top = recordedReadyPromptPosition.top + "px";
    prompt.style.transform = "none";
  }
  let drag = null;
  handle.addEventListener("pointerdown", event => {
    const rect = prompt.getBoundingClientRect();
    drag = { pointerId: event.pointerId, offsetX: event.clientX - rect.left, offsetY: event.clientY - rect.top };
    prompt.style.left = rect.left + "px";
    prompt.style.top = rect.top + "px";
    prompt.style.transform = "none";
    handle.setPointerCapture(event.pointerId);
    event.preventDefault();
  });
  handle.addEventListener("pointermove", event => {
    if (!drag || drag.pointerId !== event.pointerId) return;
    const left = Math.max(8, Math.min(window.innerWidth - prompt.offsetWidth - 8, event.clientX - drag.offsetX));
    const top = Math.max(8, Math.min(window.innerHeight - prompt.offsetHeight - 8, event.clientY - drag.offsetY));
    recordedReadyPromptPosition = { left, top };
    prompt.style.left = left + "px";
    prompt.style.top = top + "px";
  });
  const endDrag = event => {
    if (!drag || drag.pointerId !== event.pointerId) return;
    if (handle.hasPointerCapture(event.pointerId)) handle.releasePointerCapture(event.pointerId);
    drag = null;
  };
  handle.addEventListener("pointerup", endDrag);
  handle.addEventListener("pointercancel", endDrag);
  return prompt;
}

// Locked games never reinterpret a legacy accepted boolean as historical consent.
function gameAcceptanceLabel(member, locked) {
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

function renderGameSettings() {
  document.querySelector(".recorded-ready-prompt")?.remove();
  const host = el("game-settings");
  const body = el("game-settings-body");
  const projection = session?.settingsControls || {};
  const controls = (Array.isArray(projection.controls) ? projection.controls : [])
    .filter(control => session.status !== "lobby" || !session.botSeats?.options?.length || !/^botSeat\d+Difficulty$/.test(control.key));
  const viewerOptions = renderViewerGameOptions();
  const available = true;
  const sharedReviewRequired = session?.status === "lobby"
    && (session?.members || []).filter(member => ["master", "player"].includes(member.role)).length > 1;
  if (!gameOptionsInitialStateResolved && sharedReviewRequired) {
    // Open once when multiple players actually have shared setup decisions to
    // review. Viewer-only preferences and one-player lobbies stay closed.
    gameOptionsVisible = true;
    gameOptionsInitialStateResolved = true;
  } else if (!gameOptionsInitialStateResolved && session?.status !== "lobby") {
    gameOptionsVisible = false;
    gameOptionsInitialStateResolved = true;
  }
  const toggle = el("game-options-toggle");
  toggle.hidden = !available;
  toggle.textContent = gameOptionsVisible ? "Hide Game Options" : "Show Game Options";
  toggle.setAttribute("aria-expanded", gameOptionsVisible ? "true" : "false");
  host.hidden = !available || !gameOptionsVisible;
  body.replaceChildren();
  if (!available) return;

  el("game-settings-heading").textContent = safe(projection.label || "Game Options");
  const locked = session.status !== "lobby";
  const editable = !locked && session.viewerRole === "master";
  el("game-settings-status").textContent = locked
    ? "Accepted Game Options · Locked after play began."
    : editable
      ? "Choose the complete rules package. Any change clears prior acceptance, so every player must accept again."
      : "Review the current Game Options. Only the Master may change them before play.";
  const content = make("div", "game-settings-content");
  const seating = renderGameSeatControls(document, session.seating, currentUserId(), async (action, payload) => {
    await apiPost(action, payload);
    await refreshSession(false);
  });
  if (seating) content.append(seating);
  const bots = renderGameBotControls(document, session.botSeats, async (action, payload) => {
    await apiPost(action, payload);
    await refreshSession(false);
  });
  if (bots) content.append(bots);

  const modeSetting = make("div", "game-setting game-mode-setting");
  const modeLabel = make("span", "game-setting-label", "Play mode");
  if (locked) {
    const summary = make("span", "game-mode-summary", session.mode === "recorded" ? "Ranked or Recorded Play" : "Practice Mode");
    summary.dataset.mode = safe(session.mode);
    modeSetting.append(modeLabel, summary);
  } else {
    const modeGroup = make("div", "setting-choice-group game-mode-choice-group");
    modeGroup.setAttribute("role", "group");
    modeGroup.setAttribute("aria-labelledby", "game-mode-label");
    modeLabel.id = "game-mode-label";
    let selectedMode = settingsDraftSha256 === session.settingsSha256 && settingsDraftMode
      ? settingsDraftMode
      : (session.mode === "recorded" ? "recorded" : "practice");
    const modeButtons = [];
    const selectMode = value => {
      selectedMode = value;
      settingsDraftMode = value;
      settingsDraftSha256 = session.settingsSha256;
      modeSetting.dataset.selectedMode = value;
      for (const entry of modeButtons) {
        const selected = entry.value === value;
        entry.button.classList.toggle("is-selected", selected);
        entry.button.setAttribute("aria-pressed", selected ? "true" : "false");
      }
    };
    for (const [value, label] of [["practice", "Practice Mode"], ["recorded", "Ranked or Recorded Play"]]) {
      const button = make("button", "setting-choice-button", label);
      button.type = "button";
      button.dataset.modeChoice = value;
      button.disabled = !editable || busy;
      button.addEventListener("click", () => selectMode(value));
      modeButtons.push({ button, value });
      modeGroup.append(button);
    }
    selectMode(selectedMode);
    modeSetting.append(modeLabel, modeGroup, make("span", "minor", "Every player accepts this shared choice before play. It locks when play begins."));
  }
  content.append(modeSetting);

  if (viewerOptions.hasOptions) content.append(viewerOptions.section);

  const form = make("div", "game-settings-grid");
  const fields = new Map();
  const updateDraftChoice = (key, value) => {
    if (settingsDraftSha256 !== session.settingsSha256 || !settingsDraft) {
      settingsDraft = Object.fromEntries(controls.map(item => [safe(item.key), item.value]));
      settingsDraftSha256 = session.settingsSha256;
    }
    settingsDraft[key] = value;
  };
  for (const control of controls) {
    const key = safe(control.key);
    const draftCurrent = settingsDraftSha256 === session.settingsSha256 && settingsDraft
      && Object.hasOwn(settingsDraft, key);
    const displayedValue = draftCurrent ? settingsDraft[key] : control.value;
    const wrapper = make("div", "game-setting");
    const title = make("span", "game-setting-label", safe(control.label));
    title.id = `setting-label-${key}`;
    const choiceGroup = make("div", "setting-choice-group");
    choiceGroup.setAttribute("role", "group");
    choiceGroup.setAttribute("aria-labelledby", title.id);
    choiceGroup.setAttribute("aria-describedby", `setting-help-${key}`);
    choiceGroup.dataset.settingKey = key;
    if (control.type === "choice-grid") {
      wrapper.classList.add("is-choice-grid-setting");
      choiceGroup.classList.add("is-choice-grid");
    }
    let currentValue;
    const choices = [];
    if (control.type === "stepper") {
      currentValue = Math.max(Number(control.minimum), Math.min(Number(control.maximum), Number(displayedValue)));
    } else if (control.type === "checkbox") {
      currentValue = Boolean(displayedValue);
      choices.push({ value:false, label:"Disable" }, { value:true, label:"Enable" });
    } else {
      for (const item of control.options || []) {
        choices.push({ value:settingValue(control, item.value), label:safe(item.label) });
      }
      currentValue = settingValue(control, displayedValue);
    }
    const choiceButtons = [];
    const selectChoice = value => {
      currentValue = value;
      for (const entry of choiceButtons) {
        const selected = safe(entry.value) === safe(currentValue);
        entry.button.classList.toggle("is-selected", selected);
        entry.button.setAttribute("aria-pressed", selected ? "true" : "false");
      }
      updateDraftChoice(key, currentValue);
    };
    if (control.type === "stepper") {
      choiceGroup.classList.add("is-stepper");
      const minimum = Number(control.minimum);
      const maximum = Number(control.maximum);
      const step = Number(control.step);
      const value = make("output", "setting-stepper-value", String(currentValue));
      value.setAttribute("aria-live", "polite");
      value.setAttribute("aria-label", `${safe(control.label)} ${currentValue}`);
      const setStepperValue = next => {
        currentValue = Math.max(minimum, Math.min(maximum, Math.round((Number(next) - minimum) / step) * step + minimum));
        value.value = String(currentValue);
        value.textContent = String(currentValue);
        value.setAttribute("aria-label", `${safe(control.label)} ${currentValue}`);
        minus.disabled = !editable || busy || currentValue <= minimum;
        plus.disabled = !editable || busy || currentValue >= maximum;
        updateDraftChoice(key, currentValue);
      };
      const minus = make("button", "setting-stepper-button", `−${step}`);
      minus.type = "button";
      minus.dataset.settingKey = key;
      minus.dataset.settingDelta = String(-step);
      minus.addEventListener("click", () => setStepperValue(currentValue - step));
      const plus = make("button", "setting-stepper-button", `+${step}`);
      plus.type = "button";
      plus.dataset.settingKey = key;
      plus.dataset.settingDelta = String(step);
      plus.addEventListener("click", () => setStepperValue(currentValue + step));
      choiceGroup.append(minus, value, plus);
      for (const shortcut of control.shortcuts || []) {
        const button = make("button", "setting-choice-button setting-stepper-shortcut", safe(shortcut.label));
        button.type = "button";
        button.disabled = !editable || busy;
        button.dataset.settingKey = key;
        button.dataset.settingChoice = safe(shortcut.value);
        button.addEventListener("click", () => setStepperValue(Number(shortcut.value)));
        choiceGroup.append(button);
      }
      setStepperValue(currentValue);
    } else {
      for (const choice of choices) {
        const button = make("button", "setting-choice-button", choice.label);
        button.type = "button";
        button.disabled = !editable || busy;
        button.dataset.settingKey = key;
        button.dataset.settingChoice = safe(choice.value);
        const selected = safe(choice.value) === safe(currentValue);
        button.classList.toggle("is-selected", selected);
        button.setAttribute("aria-pressed", selected ? "true" : "false");
        button.addEventListener("click", () => selectChoice(choice.value));
        choiceButtons.push({ button, value:choice.value });
        choiceGroup.append(button);
      }
    }
    const help = make("span", "minor", safe(control.description));
    help.id = `setting-help-${key}`;
    wrapper.append(title, choiceGroup, help);
    form.append(wrapper);
    fields.set(key, { readValue:() => currentValue, control });
  }
  if (controls.length) content.append(form);

  const players = (session.members || []).filter(member => ["master", "player"].includes(member.role));
  const acceptance = make("ul", "settings-acceptance");
  for (const member of players) {
    const acceptanceClass = locked
      ? (member.acceptanceStatus === "accepted-at-start" ? "is-accepted" : "")
      : (member.accepted ? "is-accepted" : "is-pending");
    acceptance.append(make("li", acceptanceClass,
      `${safe(member.displayName)}: ${gameAcceptanceLabel(member, locked)}`));
  }
  content.append(make("h3", "", locked
    ? "Accepted Game Options"
    : (session.mode === "practice" ? "Host acceptance" : "Player acceptance")), acceptance);

  if (editable) {
    const update = make("button", "", "Save Game Options");
    update.type = "button";
    update.addEventListener("click", async () => {
      if (busy) return;
      const next = { ...session.settings };
      for (const [key, { readValue }] of fields) {
        next[key] = readValue();
      }
      const nextMode = modeSetting.dataset.selectedMode || session.mode;
      if (nextMode === "recorded") for (const key of Object.keys(next)) if (/^botSeat\d+Difficulty$/.test(key)) delete next[key];
      if (nextMode === "recorded" && next.inactivityProfile === "unlimited") next.inactivityProfile = "default";
      settingsDraft = { ...next };
      settingsDraftSha256 = session.settingsSha256;
      busy = true; render();
      try {
        await apiPost("update-settings", { settings: next, mode: nextMode });
        await refreshSession(false);
      } catch (error) {
        el("status").textContent = error.message;
      } finally { busy = false; render(); }
    });
    content.append(update);
  }

  const viewer = (session.members || []).find(member => Number(member.userId) === currentUserId());
  if (!locked && ["master", "player"].includes(session.viewerRole)
      && (session.mode !== "practice" || session.viewerRole === "master")) {
    const accept = make("button", "", viewer?.accepted ? "Current Game Options accepted" : "Accept current Game Options");
    accept.type = "button";
    accept.disabled = Boolean(viewer?.accepted) || busy;
    accept.addEventListener("click", async () => {
      if (busy) return;
      busy = true; render();
      try {
        await apiPost("accept", { settings_sha256: session.settingsSha256, mode: session.mode });
        await refreshSession(false);
      } catch (error) {
        el("status").textContent = error.message;
      } finally { busy = false; render(); }
    });
    content.append(accept);
  }
  const minimumPlayers = session.minimumPlayers;
  const unanimouslyAccepted = Number.isInteger(minimumPlayers) && minimumPlayers > 0
    && players.length >= minimumPlayers && players.every(member => member.accepted);
  if (!locked && session.viewerRole === "master" && unanimouslyAccepted && session.mode !== "recorded") {
    const start = make("button", "", "Start game");
    start.type = "button";
    start.disabled = busy;
    start.addEventListener("click", async () => {
      if (busy) return;
      busy = true; render();
      try {
        await apiPost("start");
        await refreshSession(false);
      } catch (error) {
        el("status").textContent = error.message;
      } finally { busy = false; render(); }
    });
    content.append(start);
  }
  body.append(content);
  if (!locked && session.mode === "recorded" && session.viewerRole === "master" && unanimouslyAccepted
      && !roomOwnsRecordedReadiness()) {
    document.body.append(makeRecordedReadyPrompt());
  }
}

function boardImageReady(image) {
  if (image.complete) return Promise.resolve(image.naturalWidth > 0 && image.naturalHeight > 0);
  image.loading = "eager";
  return new Promise(resolve => {
    let settled = false;
    const finish = ready => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      image.removeEventListener("load", onLoad);
      image.removeEventListener("error", onError);
      resolve(ready);
    };
    const onLoad = () => finish(image.naturalWidth > 0 && image.naturalHeight > 0);
    const onError = () => finish(false);
    const timer = setTimeout(() => finish(false), 4000);
    image.addEventListener("load", onLoad, { once: true });
    image.addEventListener("error", onError, { once: true });
    // Detached board trees are intentionally kept off-screen until every
    // replacement asset is ready.  Explicit decoding starts the network and
    // decode work even when a browser defers images that are not connected to
    // the document yet, so an atomic swap does not become a four-second UI
    // delay on the first selected or moving piece variant.
    if (typeof image.decode === "function") {
      image.decode()
        .then(() => finish(image.naturalWidth > 0 && image.naturalHeight > 0))
        .catch(() => { if (image.complete) finish(false); });
    }
  });
}

function renderBoard() {
  const host = el("board-host");
  const renderer = {
    "tetris-versus": () => window.CoreChatArcade?.render({ session, busy, currentUserId, memberName, performAction }) || make("p", "", "Tetris Versus is loading."),
    "space-invasion": () => window.CoreChatArcade?.render({ session, busy, currentUserId, memberName, performAction }) || make("p", "", "Space Invasion is loading."),
    checkers:renderCheckers,
    chess:renderChess,
    "acey-deucy":renderAceyDeucy,
    "backgammon-first-party":renderBackgammon,
    battleship:renderBattleship,
    spades:()=>window.CoreChatSpadesModern?.render({session,options,busy,currentUserId,memberAvatar,memberName,performAction,optionCategory,rerender:render,spadesPassSelection:spadesPassSelectionForModernBoard(),setStatus:(message)=>{actionStatusError=String(message||"");const statusNode=el("status");if(statusNode)statusNode.textContent=actionStatusError;}})||renderSpades(),
    blackjack:renderBlackjack,
    hearts:renderHearts,
    uno:renderUno,
    "puppy-panic":()=>window.CoreChatPuppyPanic?.render({session,options,busy,currentUserId,memberAvatar,memberName,performAction,optionCategory,rerender:render,setStatus:(message)=>{actionStatusError=String(message||"");const statusNode=el("status");if(statusNode)statusNode.textContent=actionStatusError;}})||make("p","","Puppy Panic is loading."),
    "chinese-checkers":() => window.CoreChatChineseCheckers?.render({
      session,
      options,
      busy,
      currentUserId,
      memberAvatar,
      memberName,
      performAction,
      optionCategory,
      rerender:render,
      setStatus:message => {
        actionStatusError = String(message || "");
        actionStatusErrorUntil = Date.now() + 5000;
        el("status").textContent = actionStatusError;
      },
    }) || make("p", "", "Chinese Checkers is loading."),
    "nested-four":() => window.CoreChatNestedFour?.render({
      session,
      options,
      busy,
      currentUserId,
      memberAvatar,
      memberName,
      performAction,
      optionCategory,
      rerender:render,
      setStatus:message => {
        actionStatusError = String(message || "");
        actionStatusErrorUntil = Date.now() + 5000;
        el("status").textContent = actionStatusError;
      },
    }) || make("p", "", "Nested Four is loading."),
  }[context.extensionId];
  const board = renderer ? renderer() : make("p", "", "This game surface is unavailable.");
  if (["tetris-versus", "space-invasion"].includes(context.extensionId)) {
    // Retain the canvas and focused controls across high-frequency snapshots.
    if (host.firstElementChild !== board) host.replaceChildren(board);
    return;
  }
  if (pendingClassicMotion
      && ["checkers", "chess"].includes(context.extensionId)
      && session?.presentation?.effectivePack === "built-in") {
    builtInModernEnhanceBoard(board, context.extensionId);
  }
  appendBuiltInPointWinMotion(board);
  if (context.extensionId !== "chess") {
    boardRenderRevision++;
    host.replaceChildren(board);
    installBuiltInBoardSize(board, host);
    return;
  }
  const revision = ++boardRenderRevision;
  const previous = host.firstElementChild;
  const focusedCell = previous?.contains(document.activeElement)
    ? document.activeElement.closest?.("[data-row][data-column]")
    : null;
  const focusCoordinates = focusedCell
    ? { row: focusedCell.dataset.row, column: focusedCell.dataset.column }
    : null;
  if (previous) {
    previous.inert = true;
    previous.setAttribute("aria-busy", "true");
  }
  host.dataset.boardSwapPending = "true";
  const images = Array.from(board.querySelectorAll("img"));
  // The first classic Chess board must be connected immediately.  Detached
  // image decoding can take longer than the session refresh interval, which
  // otherwise lets each refresh supersede the pending mount and leaves the
  // board host blank indefinitely.  Later replacements still use the atomic
  // ready-asset swap below so selected/moving pieces never flash at a
  // temporary size.
  if (!previous) {
    board.dataset.assetsReady = "pending";
    host.dataset.boardAssetsReady = "pending";
    host.replaceChildren(board);
    installBuiltInBoardSize(board, host);
  }
  const mount = ready => {
    if (revision !== boardRenderRevision) return;
    board.dataset.assetsReady = ready ? "true" : "false";
    host.dataset.boardAssetsReady = ready ? "true" : "false";
    host.dataset.boardSwapPending = "false";
    host.replaceChildren(board);
    connectSquarePieceArtwork(board);
    installBuiltInBoardSize(board, host);
    if (!ready) trace("board-assets-incomplete", null, {
      imageCount: images.length,
      failedCount: images.filter(image => !image.complete || image.naturalWidth < 1 || image.naturalHeight < 1).length,
    });
    if (focusCoordinates) {
      requestAnimationFrame(() => board.querySelector(
        `[data-row="${CSS.escape(focusCoordinates.row)}"][data-column="${CSS.escape(focusCoordinates.column)}"]`,
      )?.focus({ preventScroll:true }));
    }
  };
  if (!images.length || images.every(image => image.complete && image.naturalWidth > 0 && image.naturalHeight > 0)) {
    mount(true);
    return;
  }
  Promise.all(images.map(boardImageReady)).then(results => mount(results.every(Boolean)));
}

function completedGameEventDialog(previous, current) {
  if (previous?.state?.completed || !current?.state?.completed) return null;
  const reason = safe(current.state?.terminalReason || current.state?.terminalCause || current.state?.terminalClassification).toLowerCase();
  const version = Number(current.stateVersion || 0);
  const winnerUserId = Number(current.state?.winnerUserId || 0);
  const resignedUserId = Number(current.state?.resignedUserId || 0);
  const name = safe(current.displayName || context.fallbackName || "Game");
  const key = `${context.gameKey}:${version}:${reason}:${winnerUserId}:${resignedUserId}`;
  if (reason === "mutual-agreement") {
    return {
      key, kind:"draw-accepted", version,
      title:"Draw accepted",
      message:`The ${name} draw proposal was accepted.`,
    };
  }
  if (!reason.includes("resign")) return null;
  const players = (current?.members || []).filter(member => ["master", "player"].includes(String(member?.role || "")));
  const viewerUserId = currentUserId();
  const viewerIsPlayer = players.some(member => Number(member.userId) === viewerUserId);
  if (resignedUserId > 0 && resignedUserId === viewerUserId) {
    return {
      key, kind:"resignation-recorded", version,
      title:"Resignation recorded",
      message:`Your ${name} resignation was recorded.`,
    };
  }
  if (resignedUserId > 0) {
    const resignedName = safe(players.find(member => Number(member.userId) === resignedUserId)?.displayName || "A player");
    const opponent = viewerIsPlayer && players.length === 2;
    return {
      key, kind:opponent ? "opponent-resigned" : "player-resigned", version,
      title:opponent ? "Opponent resigned" : "Player resigned",
      message:opponent ? `Your opponent resigned from ${name}.` : `${resignedName} resigned from ${name}.`,
    };
  }
  if (winnerUserId > 0 && winnerUserId === currentUserId()) {
    return {
      key, kind:"opponent-resigned", version,
      title:"Opponent resigned",
      message:`Your opponent resigned from ${name}.`,
    };
  }
  return {
    key, kind:"player-resigned", version,
    title:"Player resigned",
    message:`A player resigned from ${name}.`,
  };
}

function nonterminalGameEventDialog(previous, current) {
  if (!previous || current?.state?.completed) return null;
  const response = current?.state?.lastDrawResponse;
  const sequence = Number(response?.sequence || 0);
  if (response?.type !== "declined" || Number(response?.forUserId || 0) !== currentUserId() || sequence < 1) return null;
  if (sequence === Number(previous?.state?.lastDrawResponse?.sequence || 0)) return null;
  const version = Number(current.stateVersion || 0);
  return {
    key:`${context.gameKey}:${version}:draw-declined:${sequence}`,
    kind:"draw-declined", version,
    title:"Draw declined",
    message:`Your opponent declined the ${safe(current.displayName || context.fallbackName || "game")} draw proposal. The game continues.`,
  };
}

function setGameEventBackgroundInert(inert) {
  for (const child of root.children) {
    if (child.classList.contains("game-event-dialog-overlay")) continue;
    child.inert = inert;
  }
}

function shouldDeferGameEventDialogForClassicTerminal(event) {
  if (!event || context.extensionId !== "checkers" || pendingClassicMotion?.type !== "checkers-win") return false;
  if (!["opponent-resigned", "resignation-recorded", "player-resigned"].includes(event.kind)) return false;
  const duration = motionLength(pendingClassicMotion.gameId, pendingClassicMotion.type);
  return duration > 0 && performance.now() - pendingClassicMotion.startedAt < duration;
}

function renderGameEventDialog() {
  const existing = root.querySelector(".game-event-dialog-overlay");
  const event = pendingGameEventDialog;
  if (shouldDeferGameEventDialogForClassicTerminal(event)) {
    existing?.remove();
    setGameEventBackgroundInert(false);
    return;
  }
  if (existing?.dataset.eventKey === pendingGameEventDialog?.key
    && dismissedGameEventDialogKey !== pendingGameEventDialog?.key) return;
  existing?.remove();
  setGameEventBackgroundInert(false);
  if (!event || event.key === dismissedGameEventDialogKey) return;

  const overlay = make("div", "game-event-dialog-overlay");
  overlay.dataset.eventKey = event.key;
  overlay.dataset.eventKind = event.kind;
  overlay.dataset.stateVersion = String(event.version);
  const dialog = make("section", "game-event-dialog");
  dialog.setAttribute("role", "dialog");
  dialog.setAttribute("aria-modal", "true");
  dialog.setAttribute("aria-labelledby", "game-event-dialog-title");
  dialog.setAttribute("aria-describedby", "game-event-dialog-message");
  const title = make("h2", "", event.title);
  title.id = "game-event-dialog-title";
  const message = make("p", "", event.message);
  message.id = "game-event-dialog-message";
  const ok = make("button", "game-event-dialog-ok", "OK");
  ok.type = "button";
  const dismiss = () => {
    dismissedGameEventDialogKey = event.key;
    pendingGameEventDialog = null;
    overlay.remove();
    setGameEventBackgroundInert(false);
    const returnTarget = gameEventDialogReturnFocus?.isConnected
      ? gameEventDialogReturnFocus
      : el("game-options-toggle");
    returnTarget?.focus({ preventScroll:true });
    gameEventDialogReturnFocus = null;
  };
  ok.addEventListener("click", dismiss);
  dialog.addEventListener("keydown", event => {
    if (event.key === "Escape") {
      event.preventDefault();
      ok.click();
      return;
    }
    if (event.key === "Tab") {
      event.preventDefault();
      ok.focus();
    }
  });
  dialog.append(title, message, ok);
  overlay.append(dialog);
  root.append(overlay);
  setGameEventBackgroundInert(true);
  overlay.inert = false;
  requestAnimationFrame(() => ok.focus({ preventScroll:true }));
}

function renderReceivedDrawProposalDialog() {
  const existing = root.querySelector(".draw-proposal-overlay");
  const proposer = Number(session?.state?.drawOfferBy || 0);
  const recipient = Object.hasOwn(session?.state || {}, "drawOfferBy") && !session?.state?.completed
    && proposer > 0 && proposer !== currentUserId()
    && playerMembers().some(member => Number(member.userId) === currentUserId());
  const key = recipient ? `${Number(session.stateVersion || 0)}:${proposer}:${currentUserId()}` : "";
  if (existing?.dataset.proposalKey === key) {
    setGameEventBackgroundInert(true);
    existing.inert = false;
    return;
  }
  const proposalWasOpen = Boolean(existing);
  existing?.remove();
  if (!recipient) {
    const resultDialogOpen = Boolean(root.querySelector(".game-event-dialog-overlay"));
    setGameEventBackgroundInert(resultDialogOpen);
    if (proposalWasOpen && !resultDialogOpen) {
      const returnTarget = drawProposalReturnFocus?.isConnected ? drawProposalReturnFocus : el("game-options-toggle");
      returnTarget?.focus({ preventScroll:true });
    }
    drawProposalReturnFocus = null;
    return;
  }

  if (!drawProposalReturnFocus || !drawProposalReturnFocus.isConnected) {
    drawProposalReturnFocus = document.activeElement && document.activeElement !== document.body
      ? document.activeElement
      : el("game-options-toggle");
  }

  const overlay = make("div", "draw-proposal-overlay");
  overlay.dataset.proposalKey = key;
  overlay.dataset.proposerUserId = String(proposer);
  const dialog = make("section", "game-event-dialog draw-proposal-dialog");
  dialog.setAttribute("role", "dialog");
  dialog.setAttribute("aria-modal", "true");
  dialog.setAttribute("aria-labelledby", "draw-proposal-title");
  dialog.setAttribute("aria-describedby", "draw-proposal-message");
  const title = make("h2", "", "Draw proposed");
  title.id = "draw-proposal-title";
  const message = make("p", "", `${memberName(proposer)} offered a draw. Accept or decline before play continues.`);
  message.id = "draw-proposal-message";
  const actions = make("div", "draw-proposal-actions");
  const accept = make("button", "", "Accept");
  const decline = make("button", "", "Decline");
  for (const button of [accept, decline]) {
    button.type = "button";
    button.disabled = busy;
  }
  accept.addEventListener("click", () => performAction("accept-draw"));
  decline.addEventListener("click", () => performAction("decline-draw"));
  actions.append(accept, decline);
  dialog.append(title, message, actions);
  dialog.addEventListener("keydown", event => {
    if (event.key !== "Tab") return;
    const focusable = [accept, decline].filter(button => !button.disabled);
    if (!focusable.length) return;
    const index = focusable.indexOf(document.activeElement);
    const next = event.shiftKey ? (index <= 0 ? focusable.length - 1 : index - 1) : (index >= focusable.length - 1 ? 0 : index + 1);
    event.preventDefault();
    focusable[next].focus();
  });
  overlay.append(dialog);
  root.append(overlay);
  setGameEventBackgroundInert(true);
  overlay.inert = false;
  requestAnimationFrame(() => accept.focus({ preventScroll:true }));
}

function showBotStatus(gameId, message, retry) {
  if (context.extensionId !== gameId) return;
  const statusId = gameId === "backgammon-first-party" ? "backgammon" : gameId;
  let panel = document.getElementById(`${statusId}-bot-status`);
  if (!panel) {
    panel = make("div", `${statusId}-bot-status game-bot-status minor`);
    panel.id = `${statusId}-bot-status`;
    // Feedback belongs after every board control, never before the board.
    el("surface")?.append(panel);
  }
  // Reserve the same slot through human turns; no idle message is necessary.
  panel.hidden = !Object.keys(session?.state?.bots || {}).length;
  const key = JSON.stringify([message || "", Boolean(retry)]);
  if (panel.dataset.statusKey === key && panel.childNodes.length) {
    const button = panel.querySelector("button");
    if (button) button.onclick = retry;
    return;
  }
  panel.dataset.statusKey = key;
  panel.replaceChildren();
  const status = make("span", "", message || "");
  status.setAttribute("role", "status");
  panel.append(status);
  if (retry) {
    const button = make("button", "btn", "Retry bot");
    button.type = "button";
    button.onclick = retry;
    panel.append(button);
  }
}

function createServerCardBot(gameId, gameName) { return createCardBotController({
  gameName,
  snapshot: () => ({
    enabled: context.extensionId === gameId && gameSurfaceVisible && gameLifecycleAvailable(),
    key: `${session?.publicId}:${session?.stateVersion}:${session?.state?.botTask?.positionKey}`,
    task: session?.state?.botTask,
  }),
  submit: payload => performAction(payload.action, {engine: payload.engine, positionKey: payload.positionKey}, payload.action === "bot-deal" ? `${gameId}-deal` : ""),
  showStatus: (message, retry) => showBotStatus(gameId, message, retry),
}); }
const unoBotController = createServerCardBot("uno", "UNO");
const heartsBotController = createServerCardBot("hearts", "Hearts");
window.addEventListener("pagehide", () => { unoBotController.stop(); heartsBotController.stop(); });
const cardBotTimer = setInterval(() => { if (context.extensionId === "uno") unoBotController.sync(); if (context.extensionId === "hearts") heartsBotController.sync(); }, 250);
window.addEventListener("pagehide", () => clearInterval(cardBotTimer));

const chessBotController = createChessBotController({
  snapshot: () => ({
    enabled: context.extensionId === "chess" && gameSurfaceVisible && gameLifecycleAvailable(),
    key: `${session?.publicId}:${session?.stateVersion}:${session?.state?.botTask?.positionKey}`,
    task: session?.state?.botTask,
  }),
  submit: payload => performAction("bot-step", payload),
  showStatus: (message, retry) => showBotStatus("chess", message, retry),
});
window.addEventListener("pagehide", () => chessBotController.stop());

const checkersBotController = createCheckersBotController({
  snapshot: () => ({
    enabled: context.extensionId === "checkers" && gameSurfaceVisible && gameLifecycleAvailable(),
    key: `${session?.publicId}:${session?.stateVersion}:${session?.state?.botTask?.positionKey}`,
    task: session?.state?.botTask,
  }),
  submit: payload => performAction("bot-step", payload),
  showStatus: (message, retry) => showBotStatus("checkers", message, retry),
});
window.addEventListener("pagehide", () => checkersBotController.stop());

const backgammonBotController = createBackgammonBotController({
  snapshot: () => ({
    enabled: context.extensionId === "backgammon-first-party" && gameSurfaceVisible && gameLifecycleAvailable() && !(pendingClassicMotion && performance.now() - pendingClassicMotion.startedAt < motionLength(pendingClassicMotion.gameId, pendingClassicMotion.type)),
    key: `${session?.publicId}:${session?.stateVersion}:${session?.state?.botTask?.positionKey}`,
    task: session?.state?.botTask,
  }),
  submit: payload => { const rolling = payload.action === "roll"; return performAction(rolling ? "bot-roll" : "bot-step", payload, rolling ? "backgammon-roll" : ""); },
  showStatus: (message, retry) => showBotStatus("backgammon-first-party", message, retry),
});
window.addEventListener("pagehide", () => backgammonBotController.stop());

const backgammonBotTimer = setInterval(() => { if (context.extensionId === "backgammon-first-party") backgammonBotController.sync(); }, 250);
window.addEventListener("pagehide", () => clearInterval(backgammonBotTimer));

function render() {
  document.body.dataset.visualFx = optionCategory("visualFxEnabled", true) ? "on" : "off";
  if (terminalSessionError) return;
  if (!session) return;
  const focusedDrawerKind = document.activeElement?.closest?.(".checkers-edge-tab")?.dataset?.drawer || "";
  const focusedDrawerControl = document.activeElement?.classList?.contains("checkers-edge-confirm")
    ? "checkers-edge-confirm"
    : document.activeElement?.classList?.contains("checkers-edge-cancel")
      ? "checkers-edge-cancel"
      : document.activeElement?.classList?.contains("checkers-edge-handle")
        ? "checkers-edge-handle"
        : "";
  if ((!canAct() && !busy) || gameSessionIsTerminal()) {
    selectedSquare = null;
    selectedPointOrigin = null;
    selectedSpadesCard = null;
    selectedSpadesBid = null;
    selectedSpadesPassCards = [];
    selectedHeartsCard = null;
    selectedHeartsPassCards = [];
    previewDestination = null;
    touchPreviewDestination = null;
    pendingChessPromotion = null;
  }
  const name = session.displayName || context.fallbackName;
  document.title = name;
  el("game-title").textContent = name;
  const pack = safe(session.presentation?.effectivePack || "built-in");
  document.body.dataset.appearance = pack;
  ensurePointBearOffMediaReady();
  ensurePointPresentationMediaReady();
  ensureNativeClassicMediaReady();
  if (!session.state?.completed) nativeChessKingEpoch = null;
  if (pack === "classic" && ["checkers", "battleship"].includes(context.extensionId)) {
    void ensureClassicMotionMediaReady().catch(() => {});
  }
  if (actionStatusErrorUntil > 0 && Date.now() >= actionStatusErrorUntil) {
    actionStatusError = "";
    actionStatusErrorUntil = 0;
  }
  el("status").textContent = gameSessionIsTerminal() ? gameTerminalStatus() : actionStatusError || (busy
    ? (context.extensionId === "uno" && pendingActionType === "play" ? "Playing card…" : "Applying your action…")
    : session.state?.completed
      ? `Game completed${session.state.terminalReason ? ` · ${safe(session.state.terminalReason).replaceAll("-"," ")}` : ""}.`
      : session.status === "lobby"
        ? (() => {
            const players = (session.members || []).filter(member => ["master", "player"].includes(member.role));
            return players.length > 0 && players.every(member => member.accepted)
              ? "Game Options accepted by all current players."
              : "Waiting for players to accept the game settings.";
          })()
        : currentAceyRollAgain() ? "Another roll is due." : "Game in progress.");
  const visibleActionError = el("game-action-error");
  visibleActionError.textContent = actionStatusError;
  visibleActionError.hidden = actionStatusError === "";
  const turnStatus = el("turn");
  turnStatus.textContent = gameSessionIsTerminal()
    ? ""
    : session.turnUserId
      ? `${memberName(session.turnUserId)} to act.`
      : context.extensionId === "tetris-versus" ? "Both players play simultaneously."
        : context.extensionId === "space-invasion" ? "Defend your ship."
        : "Waiting for the next turn.";
  turnStatus.dataset.roundNumber = String(Math.max(1, Number(session.state?.roundNumber || 1)));
  turnStatus.dataset.turnUserId = String(Number(session.turnUserId || 0));
  turnStatus.dataset.starterUserId = String(Number(session.state?.starterUserId || 0));
  turnStatus.dataset.starterReason = safe(session.state?.starterReason || "");
  if (pack === "classic") {
    const surface = el("surface");
    surface.style.setProperty("--classic-board", `url("${mediaUrl("classic-board")}")`);
  } else el("surface").style.removeProperty("--classic-board");
  renderGameSettings(); renderSurfaceOptions(); renderRules(); renderAccessibility(); renderPlayerStatusStrip(); renderBoard(); renderControls(); renderLiveScore(); renderDrawProgress(); renderRecords(); renderGameEventDialog(); renderReceivedDrawProposalDialog();
  // The parent room observes these controls too. Set their final visibility
  // in this render turn, before either window can paint temporary defaults.
  syncSeparateControlVisibility();
  syncClassicAnimation();
  scheduleSpadesAutomaticAction();
  scheduleBlackjackAutomaticAction();
  scheduleHeartsAutomaticAction();
  scheduleUnoAutomaticAction();
  unoBotController.sync();
  heartsBotController.sync();
  chessBotController.sync();
  checkersBotController.sync();
  backgammonBotController.sync();
  scheduleSurfaceReachability(() => {
    syncSurfaceReachability();
    if (openCheckersDrawer) {
      const openDrawer = document.querySelector(`.checkers-edge-tab[data-drawer="${CSS.escape(openCheckersDrawer)}"]`);
      if (openDrawer && !openDrawer.contains(document.activeElement)) {
        openDrawer.querySelector(`.${focusedDrawerControl || "checkers-edge-confirm"}`)?.focus();
      }
    } else if (focusedDrawerKind) {
      document.querySelector(`.checkers-edge-tab[data-drawer="${CSS.escape(focusedDrawerKind)}"] .checkers-edge-handle`)?.focus();
    }
  });
}

function scheduleSurfaceReachability(callback = syncSurfaceReachability) {
  if (terminalSessionError) return 0;
  const frame = requestAnimationFrame(() => {
    surfaceReachabilityFrames.delete(frame);
    if (terminalSessionError) return;
    callback();
  });
  surfaceReachabilityFrames.add(frame);
  return frame;
}

function stopSurfaceReachability() {
  surfaceReachabilityObserver?.disconnect();
  surfaceReachabilityObserver = null;
  for (const frame of surfaceReachabilityFrames) cancelAnimationFrame(frame);
  surfaceReachabilityFrames.clear();
}

function observeSurfaceReachability() {
  const surface = el("surface");
  if (terminalSessionError || !surface || surface.isConnected === false) return;
  surfaceReachabilityObserver?.disconnect();
  surfaceReachabilityObserver = new ResizeObserver(syncSurfaceReachability);
  surfaceReachabilityObserver.observe(surface);
}

function syncSurfaceReachability() {
  const surface = el("surface");
  if (terminalSessionError || !surface || surface.isConnected === false) return;
  const clippedHorizontally = surface.scrollWidth > surface.clientWidth + 1;
  const clippedVertically = surface.scrollHeight > surface.clientHeight + 1;
  const surfaceRect = surface.getBoundingClientRect();
  const documentContinuesBelow = document.documentElement.scrollHeight > innerHeight + 1
    && surfaceRect.bottom > innerHeight + 1;
  const scrollable = clippedHorizontally || clippedVertically || documentContinuesBelow;
  surface.dataset.scrollable = scrollable ? "true" : "false";
  surface.dataset.scrollableHorizontally = clippedHorizontally ? "true" : "false";
  surface.dataset.scrollableVertically = clippedVertically ? "true" : "false";
  surface.dataset.documentScrollContinues = documentContinuesBelow ? "true" : "false";
}

function stableSessionRenderIdentity(value) {
  const stable = structuredClone(value || {});
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
  return JSON.stringify(stable);
}

function notifyRematchSuccessor() {
  const predecessor = session?.publicId;
  const successor = session?.rematchSuccessorPublicId;
  if (typeof predecessor !== "string" || predecessor !== context.gameSessionId
    || typeof successor !== "string" || !successor || successor === predecessor
    || !["completed", "forfeited", "abandoned"].includes(session?.status)
    || !["master", "player"].includes(session?.viewerRole)) return;
  const identity = predecessor + ":" + successor;
  if (lastRematchSuccessorNotice === identity) return;
  lastRematchSuccessorNotice = identity;
  window.parent.postMessage({
    type: "corechat-game-session-reconcile",
    lobbyCode: predecessor,
    successorLobbyCode: successor
  }, location.origin);
}

async function refreshSession(refreshRecords = false, providedSession = null, { deferRender = false } = {}) {
  if (terminalSessionError) return;
  const optionsRevisionAtStart = optionsMutationRevision;
  const shouldLoadOptions = options === null;
  const tasks = [providedSession ? Promise.resolve(providedSession) : apiGet("session")];
  tasks.push(shouldLoadOptions ? apiGet("options", { game_key: context.gameKey }) : Promise.resolve(null));
  const [nextSession, nextOptions] = await Promise.all(tasks);
  if (terminalSessionError) return;
  assertGameSessionEnvelope(nextSession);
  // Action and background-poll requests may overlap.  Own a transition from
  // the session that is current when this response is applied, not from the
  // value that happened to be current before awaiting the network.  A slower
  // stale response must never roll the game backward or clear a just-mounted
  // source-authentic animation.
  const previous = session;
  const stableSessionChanged = !previous
    || stableSessionRenderIdentity(previous) !== stableSessionRenderIdentity(nextSession);
  const previousVersion = Number(previous?.stateVersion || 0);
  const nextVersion = Number(nextSession?.stateVersion || 0);
  if (previous && nextVersion < previousVersion) {
    trace("stale-session-refresh-rejected", null, { previousVersion, nextVersion });
    return;
  }
  if (previous && previous.settingsSha256 !== nextSession.settingsSha256) {
    settingsDraft = null;
    settingsDraftSha256 = "";
    settingsDraftMode = "";
  }
  const closeOptionsAfterAcceptedStart = previous?.status === "lobby" && nextSession?.status !== "lobby";
  const restoreFocusAfterOptionsClose = closeOptionsAfterAcceptedStart
    && el("game-settings")?.contains(document.activeElement);
  if (closeOptionsAfterAcceptedStart) gameOptionsVisible = false;
  session = nextSession;
  sessionProjectedAtMs = performance.now();
  if (shouldLoadOptions && options === null
    && optionsRevisionAtStart === optionsMutationRevision) options = nextOptions;
  notifyRematchSuccessor();
  const currentVersion = nextVersion;
  if (!previous) lastSoundedStateVersion = currentVersion;
  const eventDialog = completedGameEventDialog(previous, session) || nonterminalGameEventDialog(previous, session);
  if (eventDialog) {
    gameEventDialogReturnFocus = document.activeElement && document.activeElement !== document.body
      ? document.activeElement
      : el("game-options-toggle");
    pendingGameEventDialog = eventDialog;
  }
  if (previous && Number(previous.stateVersion) !== currentVersion) {
    clearTimeout(battleshipSelectionTimer);
    battleshipSelectionTimer = 0;
    selectedSquare = null;
    selectedPointOrigin = null;
    selectedBattleshipShipId = "";
    selectedSpadesCard = null;
    selectedSpadesBid = null;
    selectedSpadesPassCards = [];
    previewDestination = null;
    touchPreviewDestination = null;
    openCheckersDrawer = null;
    pendingChessPromotion = null;
    const authoritativeMotion = classifyClassicMotion(previous, session);
    const reusedOptimisticMotion = reconcileOptimisticBuiltInCheckersMotion(authoritativeMotion, currentVersion)
      || reconcileOptimisticBuiltInChessMotion(authoritativeMotion, currentVersion);
    if (!reusedOptimisticMotion) {
      if (classicAnimationTimer || pendingClassicMotion || scheduledSoundTimers.size > 0) stopClassicAnimation("state-version-changed");
      pendingClassicMotion = authoritativeMotion;
      optimisticBuiltInCheckersMotion = null;
      reconciledBuiltInCheckersSound = null;
      optimisticBuiltInChessMotion = null;
      reconciledBuiltInChessSound = null;
    }
    trace("state-version-observed", null, {
      fromVersion: Number(previous.stateVersion || 0),
      toVersion: currentVersion,
      previousHistoryCount: listLength(previous.state?.history),
      currentHistoryCount: listLength(session.state?.history),
      previousAttackCount: listLength(previous.state?.attackHistory),
      currentAttackCount: listLength(session.state?.attackHistory),
    });
    const builtInPointWin = classifyBuiltInPointWin(previous, session);
    if (builtInPointWin) startBuiltInPointWinMotion(builtInPointWin);
    else if (pendingBuiltInPointWin) stopBuiltInPointWinMotion();
    if (pendingClassicMotion) trace("classic-transition-classified", null, {
      type: pendingClassicMotion.type,
      fromVersion: Number(previous.stateVersion || 0),
      toVersion: currentVersion,
    });
    else trace("classic-transition-unclassified", null, {
      fromVersion: Number(previous.stateVersion || 0),
      toVersion: currentVersion,
    });
    // An action refresh and the background poll may resolve together. Bind
    // audible feedback to the authoritative version so the same transition is
    // neither lost nor played twice.
    if (currentVersion > lastSoundedStateVersion) {
      lastSoundedStateVersion = currentVersion;
      playTransitionSound(previous, session);
    }
  }
  const statusExpiryRequiresRender = actionStatusErrorUntil > 0 && Date.now() >= actionStatusErrorUntil;
  // A stable polling response must not rebuild any game's interactive surface.
  // Replacing unchanged point/cell controls drops physical keyboard focus and
  // makes a user restart the Tab sequence every poll.  Projected clocks and
  // lifecycle countdowns have their own lightweight synchronizers, so a full
  // render is required only when authoritative or explicitly refreshed data
  // changed.
  const renderRequired = stableSessionChanged || shouldLoadOptions || statusExpiryRequiresRender;
  if (renderRequired && !deferRender) render();
  else if (renderRequired) document.body.dataset.lastDeferredBoardStateVersion = String(currentVersion);
  else document.body.dataset.lastSuppressedBoardStateVersion = String(currentVersion);
  if (restoreFocusAfterOptionsClose && !deferRender) el("game-options-toggle")?.focus({ preventScroll: true });
  scheduleChessClockDeadline();
  scheduleSharedLifecycleDeadline();
  scheduleOriginalVoices(previous,session);
  if (musicPlayer && options?.musicEnabled !== true) stopMusic("music-off");
  await reconnectVisibleSession();
  // Auxiliary records own their errors and panel updates. Never let them hold
  // an accepted action's render, animation window or the next session poll.
  void refreshGameRecords({ force: refreshRecords });
  return { renderRequired, restoreFocusAfterOptionsClose };
}

function isTerminalSessionFailure(error) {
  return [401, 403, 404, 410].includes(Number(error?.httpStatus || error?.details?.status || 0))
    || ["MULTIPLAYER_GAME_ACCESS_DENIED", "MULTIPLAYER_GAME_NOT_FOUND"].includes(String(error?.code || ""));
}

function endUnavailableSession(error) {
  if (!isTerminalSessionFailure(error)) return false;
  if (terminalSessionError) return true;
  terminalSessionError = error;
  stopSurfaceReachability();
  gameSurfaceVisible = false;
  clearTimeout(pollTimer);
  pollTimer = 0;
  clearTimeout(battleshipSelectionTimer);
  clearTimeout(chessClockRenderTimer);
  clearTimeout(chessClockDeadlineTimer);
  clearTimeout(sharedLifecycleDeadlineTimer);
  clearTimeout(sharedLifecycleRenderTimer);
  builtInBattleshipDragState?.cleanup?.();
  builtInBattleshipDragState = null;
  clearSpadesAutomaticAction();
  clearBlackjackAutomaticAction();
  clearUnoAutomaticAction();
  pauseAudio("session-unavailable");
  session = null;
  resetGameRecords();
  options = null;
  const unavailableMessage = {
    type: "corechat-game-session-unavailable",
    lobbyCode: context.gameSessionId,
    httpStatus: Number(error.httpStatus || error.details?.status || 0),
    code: String(error.code || "")
  };
  if (root && context.extensionId) {
    root.classList.add("has-terminal-session-error");
    const notice = document.createElement("section");
    notice.className = "surface game-session-unavailable";
    notice.setAttribute("role", "alert");
    notice.setAttribute("aria-labelledby", "game-session-unavailable-title");
    notice.tabIndex = -1;
    const title = document.createElement("h1");
    title.id = "game-session-unavailable-title";
    title.textContent = `${context.fallbackName} is unavailable`;
    const detail = document.createElement("p");
    detail.textContent = "This game session could not be opened. It may have ended or expired.";
    const recovery = document.createElement("p");
    recovery.textContent = "Return to the room to choose a current game.";
    const returnButton = document.createElement("button");
    returnButton.type = "button";
    returnButton.textContent = "Return to room";
    returnButton.addEventListener("click", () => {
      window.parent.postMessage(unavailableMessage, location.origin);
      if (window.parent !== window) return;
      if (history.length > 1) history.back();
      else location.assign("../../lobby.php");
    });
    notice.append(title, detail, recovery, returnButton);
    root.replaceChildren(notice);
    requestAnimationFrame(() => notice.focus({ preventScroll: true }));
  } else {
    const notice = el("status");
    if (notice) {
      notice.textContent = "This game is no longer available. Return to the room to choose a game.";
      root?.replaceChildren(notice);
    } else if (root) {
      root.textContent = "This game is no longer available. Return to the room to choose a game.";
    }
  }
  window.parent.postMessage(unavailableMessage, location.origin);
  return true;
}

async function poll(providedSession = null) {
  if (terminalSessionError) return;
  try {
    if (gameSurfaceVisible && !document.hidden) {
      await refreshSession(false, providedSession);
    }
  }
  catch (error) {
    if (!endUnavailableSession(error)) {
      const status = el("status");
      if (status) status.textContent = error.message;
    }
  }
  finally {
    clearTimeout(pollTimer);
    const localFixture = /^(?:127(?:\.\d{1,3}){3}|localhost)$/.test(location.hostname);
    pollTimer = terminalSessionError ? 0 : setTimeout(poll, localFixture ? 2500 : 1500);
  }
}

el("rules-button").addEventListener("click", () => {
  const panel = el("rules-panel"); panel.hidden = !panel.hidden;
  el("rules-button").setAttribute("aria-expanded", panel.hidden ? "false" : "true");
  if (!panel.hidden) panel.focus({ preventScroll:true });
});
el("accessibility-button").addEventListener("click", () => {
  const panel = el("accessibility-panel"); panel.hidden = !panel.hidden;
  el("accessibility-button").setAttribute("aria-expanded", panel.hidden ? "false" : "true");
  if (!panel.hidden) panel.focus({ preventScroll:true });
});
document.addEventListener("keydown", event => {
  const panel = el("accessibility-panel");
  if (event.key !== "Escape" || !panel || panel.hidden) return;
  event.preventDefault();
  panel.hidden = true;
  el("accessibility-button").setAttribute("aria-expanded", "false");
  el("accessibility-button").focus({ preventScroll:true });
});
document.addEventListener("keydown", event => {
  const help = el("separate-controls-help");
  const info = el("separate-controls-info");
  if (event.key !== "Escape" || !help || !info || help.hidden) return;
  event.preventDefault();
  help.hidden = true;
  info.setAttribute("aria-expanded", "false");
  info.focus();
});
el("score-records-toggle").addEventListener("click", () => {
  const panel = el("score-records"); panel.hidden = !panel.hidden;
  document.querySelector(".layout")?.setAttribute("data-score-records-visible", panel.hidden ? "false" : "true");
  el("score-records-toggle").textContent = panel.hidden ? "Show Score & Records" : "Hide Score & Records";
  el("score-records-toggle").setAttribute("aria-expanded", panel.hidden ? "false" : "true");
  if (!panel.hidden) {
    panel.focus({ preventScroll:true });
    void refreshGameRecords({ force: true });
  }
  scheduleSurfaceReachability(syncSurfaceReachability);
});
function bindReliableControlActivation(control, activate) {
  let pointerActivationAt = Number.NEGATIVE_INFINITY;
  const now = () => Number(globalThis.performance?.now?.() ?? Date.now());
  control.addEventListener("pointerdown", event => {
    if (event.button !== 0 || event.isPrimary === false) return;
    event.preventDefault();
    pointerActivationAt = now();
    activate(event);
  });
  control.addEventListener("click", event => {
    if (event.detail === 0 || now() - pointerActivationAt > 700) activate(event);
  });
}
function setGameOptionsPanelVisible(visible, { moveFocus = true } = {}) {
  gameOptionsVisible = Boolean(visible);
  const panel = el("game-settings");
  panel.hidden = !gameOptionsVisible;
  const toggle = el("game-options-toggle");
  toggle.textContent = gameOptionsVisible ? "Hide Game Options" : "Show Game Options";
  toggle.setAttribute("aria-expanded", gameOptionsVisible ? "true" : "false");
  if (moveFocus) {
    if (gameOptionsVisible) panel.focus({ preventScroll: true });
    else toggle.focus({ preventScroll: true });
  }
  scheduleSurfaceReachability(syncSurfaceReachability);
}
bindReliableControlActivation(el("game-options-toggle"), () => {
  setGameOptionsPanelVisible(!gameOptionsVisible);
});
observeSurfaceReachability();
addEventListener("message", event => {
  if (terminalSessionError || event.source !== window.parent
    || event.origin !== location.origin || event.data?.type !== "corechat-game-surface-visibility") return;
  gameSurfaceVisible = event.data.visible !== false;
  if (!gameSurfaceVisible) { builtInBattleshipDragState?.cleanup?.(); builtInBattleshipDragState = null; clearTimeout(battleshipSelectionTimer); battleshipSelectionTimer = 0; selectedSquare = null; selectedPointOrigin = null; selectedBattleshipShipId = ""; selectedSpadesCard = null; selectedSpadesBid = null; selectedSpadesPassCards = []; previewDestination = null; touchPreviewDestination = null; openCheckersDrawer = null; pendingChessPromotion = null; clearSpadesAutomaticAction(); clearBlackjackAutomaticAction(); render(); pauseAudio("game-surface-hidden"); }
  else refreshSession(false).catch(error => { endUnavailableSession(error); });
});
document.addEventListener("visibilitychange", () => {
  if (terminalSessionError || document.hidden || !gameSurfaceVisible) return;
  clearTimeout(pollTimer);
  void poll();
});
addEventListener("pagehide", () => { builtInBoardSizeCleanup?.(); builtInBoardSizeCleanup = null; gameSurfaceVisible = false; builtInBattleshipDragState?.cleanup?.(); builtInBattleshipDragState = null; selectedSquare = null; selectedPointOrigin = null; selectedBattleshipShipId = ""; selectedSpadesCard = null; selectedSpadesBid = null; selectedSpadesPassCards = []; previewDestination = null; touchPreviewDestination = null; openCheckersDrawer = null; pendingChessPromotion = null; clearTimeout(battleshipSelectionTimer); battleshipSelectionTimer = 0; clearTimeout(pollTimer); clearTimeout(chessClockRenderTimer); clearTimeout(chessClockDeadlineTimer); clearTimeout(sharedLifecycleDeadlineTimer); clearTimeout(sharedLifecycleRenderTimer); clearSpadesAutomaticAction(); clearBlackjackAutomaticAction(); pauseAudio("pagehide"); }, { once:true });
addEventListener("pagehide", () => {
  if (!terminalSessionError) void apiPost("disconnect", {}, { keepalive: true }).catch(() => {});
}, { once: true });
function visibleReconnectMembership() {
  if (!session || session.status !== "active" || session.state?.completed !== false
    || !["master", "player"].includes(String(session.viewerRole || ""))) return null;
  const publicId = String(context.gameSessionId || "");
  const participantId = Number(context.participantId);
  if (!publicId || session.publicId !== publicId || !Number.isInteger(participantId) || participantId <= 0) return null;
  const member = session.members?.find(item => Number(item.participantId) === participantId);
  const userId = Number(member?.userId);
  if (!Number.isInteger(userId) || userId <= 0 || member?.membershipStatus !== "active"
    || member?.role !== session.viewerRole) return null;
  return { publicId, participantId, userId, key: JSON.stringify([publicId, participantId, userId, connectionEpoch]) };
}

async function reconnectVisibleSession() {
  const binding = visibleReconnectMembership();
  if (!binding) return;
  const player = session.state?._framework?.players?.[String(binding.userId)];
  if (player?.disconnected === false) {
    visibleReconnectAttemptKey = "";
    return;
  }
  if (player?.disconnected !== true || terminalSessionError || !gameSurfaceVisible
    || document.hidden || busy || session.state?._framework?.serviceInterruption?.active === true) return;
  const attemptKey = JSON.stringify([binding.key, String(player.disconnectedAt || "")]);
  if (pendingVisibleReconnect || visibleReconnectAttemptKey === attemptKey) return;
  const pending = {};
  pendingVisibleReconnect = pending;
  // One automatic request per observed disconnect episode. A transport failure
  // is not proof that a reconnect was rejected and must not trigger blind retries.
  visibleReconnectAttemptKey = attemptKey;
  try {
    const reconnectedSession = await apiPost("reconnect", { client_epoch: connectionEpoch });
    const currentBinding = visibleReconnectMembership();
    if (pendingVisibleReconnect !== pending || terminalSessionError || !gameSurfaceVisible
      || document.hidden || busy || !currentBinding || currentBinding.key !== binding.key
      || reconnectedSession?.publicId !== binding.publicId) return;
    // The server alone clears disconnect state and accounts for reconnect time.
    // Existing envelope/version validation owns acceptance of the returned DTO.
    await refreshSession(false, reconnectedSession);
  } finally {
    if (pendingVisibleReconnect === pending) pendingVisibleReconnect = null;
  }
}

function startSessionConnection() {
  return apiPost("reconnect", { client_epoch: connectionEpoch }).then(
    reconnectedSession => poll(reconnectedSession),
    error => { if (!endUnavailableSession(error)) return poll(); },
  );
}
void startSessionConnection();
(function installBuiltInBoardMaterialControl() {
  'use strict';

  var body = document.body;
  var gameKey = body && body.dataset ? body.dataset.extension : '';
  if (gameKey !== 'checkers' && gameKey !== 'chess') {
    return;
  }

  var allowedMaterials = ['wood', 'glass', 'enamel'];
  var storageKey = 'corechat-' + gameKey + '-board-material';
  var material = 'wood';

  try {
    var storedMaterial = gameViewStorage.getItem(storageKey);
    if (allowedMaterials.indexOf(storedMaterial) !== -1) {
      material = storedMaterial;
    }
  } catch (error) {
    material = 'wood';
  }

  function refreshButtons(row) {
    if (!row) {
      return;
    }
    Array.prototype.forEach.call(row.querySelectorAll('[data-board-material]'), function (button) {
      var selected = button.dataset.boardMaterial === material;
      button.classList.toggle('is-selected', selected);
      button.setAttribute('aria-pressed', selected ? 'true' : 'false');
    });
  }

  function applyMaterial(nextMaterial, persist) {
    if (allowedMaterials.indexOf(nextMaterial) === -1) {
      nextMaterial = 'wood';
    }
    material = nextMaterial;
    body.dataset.boardMaterial = material;
    refreshButtons(document.querySelector('.built-in-board-material-option'));
    if (persist) {
      try {
        gameViewStorage.setItem(storageKey, material);
      } catch (error) {
        // The selected finish still applies for this view when storage is unavailable.
      }
    }
  }

  function ensureControl() {
    var settingsGrid = document.querySelector('.viewer-game-options .game-settings-grid');
    if (!settingsGrid) {
      return;
    }

    var row = settingsGrid.querySelector('.built-in-board-material-option');
    if (!row) {
      row = document.createElement('div');
      row.className = 'game-setting built-in-board-material-option';

      var label = document.createElement('span');
      label.className = 'game-setting-label';
      label.id = 'built-in-' + gameKey + '-board-material-label';
      label.textContent = 'Board material';

      var choices = document.createElement('div');
      choices.className = 'setting-choice-group board-material-choice-group';
      choices.setAttribute('role', 'group');
      choices.setAttribute('aria-labelledby', label.id);

      allowedMaterials.forEach(function (value) {
        var button = document.createElement('button');
        button.className = 'setting-choice-button';
        button.type = 'button';
        button.dataset.boardMaterial = value;
        button.textContent = value.charAt(0).toUpperCase() + value.slice(1);
        button.addEventListener('click', function () {
          applyMaterial(value, true);
        });
        choices.appendChild(button);
      });

      row.appendChild(label);
      row.appendChild(choices);

      var appearanceRow = settingsGrid.querySelector('.appearance-preference');
      if (appearanceRow && appearanceRow.nextSibling) {
        settingsGrid.insertBefore(row, appearanceRow.nextSibling);
      } else if (appearanceRow) {
        settingsGrid.appendChild(row);
      } else {
        settingsGrid.insertBefore(row, settingsGrid.firstChild);
      }
    }

    row.hidden = !!body.dataset.appearance && body.dataset.appearance !== 'built-in';
    refreshButtons(row);
  }

  applyMaterial(material, false);
  ensureControl();

  var observer = new MutationObserver(function () {
    ensureControl();
  });
  observer.observe(document.documentElement, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ['data-appearance']
  });

  window.addEventListener('storage', function (event) {
    if (event.key === storageKey && allowedMaterials.indexOf(event.newValue) !== -1) {
      applyMaterial(event.newValue, false);
    }
  });
}());

if (LOOPBACK_HOST.test(location.hostname) && /^\d+$/.test(params.get("capture_audit") || "")) {
  Object.defineProperty(globalThis, "__corechatAuditGameAction", {
    value: Object.freeze({
      driver: "performAction",
      ready: () => Boolean(session && session.status === "active" && !busy),
      run: (actionType, payload = {}) => performAction(
        String(actionType || ""),
        payload && typeof payload === "object" ? payload : {},
      ),
    }),
    configurable: false,
    enumerable: false,
    writable: false,
  });
}
