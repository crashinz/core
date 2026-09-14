'use strict';

const APP_BASE = document.body?.dataset.appBase || '';
const CSRF_TOKEN = document.body?.dataset.csrf || '';

function appUrl(path) {
  if (!path) return APP_BASE || '/';
  if (/^(?:https?:)?\/\//.test(path) || path.startsWith('data:') || path.startsWith('blob:')) return path;
  if (!path.startsWith('/')) return path;
  if (APP_BASE && path.startsWith(`${APP_BASE}/`)) return path;
  return `${APP_BASE}${path}`;
}

function mediaUrl(path) {
  return appUrl(path);
}

function cacheBust(url) {
  if (!url || /^(?:data|blob):/.test(url)) return url;
  return `${url}${url.includes('?') ? '&' : '?'}v=${Date.now()}`;
}

function innerTranquillityPlayerCapability() {
  const body = document.body;
  return Object.freeze({
    relevant: body?.dataset.innerTranquillityPlayerRelevant === 'true',
    available: body?.dataset.innerTranquillityPlayerAvailable === 'true',
    reason: body?.dataset.innerTranquillityPlayerReason || 'capability-unavailable',
  });
}

function runtimeDiagnosticsCapability() {
  const body = document.body;
  return Object.freeze({
    enabled: body?.dataset.runtimeDiagnosticsEnabled === 'true',
    mode: body?.dataset.runtimeDiagnosticsMode || 'disabled',
    verificationControls: body?.dataset.runtimeVerificationControls === 'true',
  });
}

let cfg = null;
const vpMusicYoutube = document.getElementById('vp-music-youtube');
let participants = new Map();
let presenceRefreshCycle = 0;
let chatRuntimeCore = null;
let chatRuntime = null;
let roomRuntime = null;
let voiceRuntime = null;
let gameRuntime = null;
let roomEffectsRuntime = null;
let importedRoomRuntime = null;
let liveWebsiteRoomRuntime = null;
let avatarRuntime = null;
let pollingRuntime = null;
let runtimeDiagnosticsInstallation = null;
let runtimeDiagnostics = null;
let runtimeVerificationControls = null;
let runtimeRequestClient = null;
let runtimeIssueCaptureService = null;
let gesturePresentation = null;
let gestureCatalogController = null;
let p2pTransferService = null;
let gestureCatalogBroadcastChannel = null;
let GestureCatalogControllerClass = null;
const messageProtectionPending = new Set();
const messageProtectionContextCache = new Map();
function isMissingRoomResponse(error) {
  return error?.details?.status === 404
    && error?.responsePayload?.code === 'ROOM_SESSION_NOT_FOUND';
}

function handleMissingRoomResponse(error) {
  if (!isMissingRoomResponse(error)) return false;
  if (roomExitInProgress) return true;
  const notice = document.createElement('div');
  notice.id = 'room-session-ended-notice';
  notice.setAttribute('role', 'alert');
  notice.style.cssText = 'position:fixed;top:12px;left:12px;right:12px;z-index:2147483647;padding:16px;background:#151b28;color:#fff;border:2px solid #f3c969;border-radius:8px;';
  const message = document.createElement('span');
  message.textContent = 'This room is no longer available. Live updates have stopped. ';
  const link = document.createElement('a');
  link.href = appUrl('/lobby.php');
  link.textContent = 'Return to lobby';
  link.style.cssText = 'color:#f3c969;text-decoration:underline;font-weight:bold;';
  notice.append(message, link);
  document.body.append(notice);
  recordRuntimeDiagnostic('requests', 'room-session-ended', { code: 'ROOM_SESSION_NOT_FOUND' });
  stopRoomForDocumentExit('room-session-not-found');
  return true;
}

const runtimeRequestAbortController = new AbortController();
let gameChatRequestAbortController = new AbortController();
function stopRoomForDocumentExit(reason) {
  roomExitInProgress = true;
  avatarRuntime?.p2pAvatar?.clearAll(reason);
  p2pTransferService?.destroy(reason);
  chatRuntime?.poll?.stop();
  if (chatRuntimeCore?.state === 'started') chatRuntimeCore.stop();
  voiceRuntime?.destroy?.();
  gameChatRequestAbortController.abort(reason);
  runtimeRequestAbortController.abort(reason);
  runtimeIssueCaptureService?.destroy();
  liveWebsiteRoomRuntime?.destroy(reason);
  gestureCatalogBroadcastChannel?.close();
  gestureCatalogBroadcastChannel = null;
  avatarRuntime?.coordinator?.cancelPendingLinkChoice(reason);
}
window.addEventListener('pagehide', () => stopRoomForDocumentExit('page-hide'), { once: true });
window.addEventListener('beforeunload', () => stopRoomForDocumentExit('before-unload'), { once: true });
let frameQueued = false;
let pendingLayout = false;
let layoutLocked = false;
let avatarViewportReflowFrame = null;
const roomLayout = document.querySelector('.room-layout');
const mainEl = document.querySelector('.main');
const roomStage = document.getElementById('room-stage');
const roomStageViewport = document.getElementById('room-stage-viewport');
const avatarViewportLayer = document.getElementById('avatar-viewport-layer');
const relationshipCanvas = document.getElementById('relationship-canvas');
let relationshipCanvasResolvedWidth = 0;
let relationshipCanvasResolvedHeight = 0;
let relationshipCanvasMeasurementGeneration = 0;
let relationshipCanvasMeasurementState = Object.freeze({
  generation: 0,
  reset: false,
  viewport: null,
  requested: null,
  applied: null,
  changed: false,
});
const vpRoomLayout = document.getElementById('vp-room-layout');
const vpMusicPlayer = document.getElementById('vp-music-player');
const vpMusicSelect = document.getElementById('vp-music-select');
const vpMusicAudio = document.getElementById('vp-music-audio');
const vpMusicYoutubeControls = document.getElementById('vp-music-youtube-controls');
const vpMusicLaunch = document.getElementById('vp-music-launch');
const vpMusicEmbed = document.getElementById('vp-music-embed');
const vpMusicModal = document.getElementById('vp-music-modal');
const vpMusicModalTitle = document.getElementById('vp-music-modal-title');
const vpMusicModalClose = document.getElementById('vp-music-modal-close');
const vpMusicModalMinimize = document.getElementById('vp-music-modal-minimize');
const vpMusicDragHandle = document.getElementById('vp-music-drag-handle');
const vpMusicModalBox = vpMusicModal?.querySelector('.vp-music-modal-box');
const vpMusicFrameWrap = document.getElementById('vp-music-frame-wrap');
const messagesEl = document.getElementById('messages');
const userListEl = document.getElementById('user-list');
const friendListEl = document.getElementById('friend-results');
const memberProfileModal = document.getElementById('member-profile-modal');
const memberProfileContent = document.getElementById('member-profile-content');
const memberProfileStatus = document.getElementById('member-profile-status');
const memberProfileActions = document.getElementById('member-profile-actions');
const ctxProfile = document.getElementById('ctx-profile');
const gameListEl = document.getElementById('active-games');
const gameStartMenu = document.getElementById('game-start-menu');
const roomGamesMenu = document.getElementById('room-games-menu');
const roomGamesListEl = document.getElementById('room-games-list');
const roomGamesCount = document.getElementById('room-games-count');
const roomGamesEmpty = document.getElementById('room-games-empty');
const roomGamesSummary = document.getElementById('room-games-summary');
const gamePickerCatalog = document.getElementById('game-picker-catalog');
const gamePickerSearch = document.getElementById('game-picker-search');
const gamePickerSelection = document.getElementById('game-picker-selection');
const gamePickerContinue = document.getElementById('game-picker-continue');
const gamePickerEmpty = document.getElementById('game-picker-empty');
const gamePickerCount = document.getElementById('game-picker-count');
const gameModeModal = document.getElementById('game-mode-modal');
const gameModeForm = document.getElementById('game-mode-form');
const gameModeTitle = document.getElementById('game-mode-title');
const gameModeRecordedChoice = document.getElementById('game-mode-recorded-choice');
const gameModeStatus = document.getElementById('game-mode-status');
const gameRecordsModal = document.getElementById('game-records-modal');
let gameModeDefinition = null;
let gameModeReturnFocus = null;
let gameRecordsReturnFocus = null;
let gameCatalogDefinitions = [];
let gameCatalogLoaded = false;
let gameCatalogFilter = 'all';
let selectedGameCatalogKey = '';
let gamePickerDragState = null;
let roomGamesDragState = null;
const voiceSideSection = document.getElementById('voice-side-section');
const voiceTitleEl = document.getElementById('voice-title');
const voiceListEl = document.getElementById('voice-list');
const voiceCountLabel = document.getElementById('voice-count-label');
const voiceTransmissionHold = document.getElementById('voice-transmission-hold');
const voiceTransmissionStatus = document.getElementById('voice-transmission-status');
const privateVoiceOpen = document.getElementById('private-voice-open');
const privateVoiceModal = document.getElementById('private-voice-modal');
const privateVoiceContent = document.getElementById('private-voice-content');
const privateVoiceStatus = document.getElementById('private-voice-status');
const privateVoicePolicyNote = document.getElementById('private-voice-policy-note');
const webcamAudienceModal = document.getElementById('webcam-audience-modal');
const webcamAudienceForm = document.getElementById('webcam-audience-form');
const webcamAudiencePeople = document.getElementById('webcam-audience-people');
const webcamAudiencePersonList = document.getElementById('webcam-audience-person-list');
const webcamAudienceStatus = document.getElementById('webcam-audience-status');
const ctxMenu = document.getElementById('ctx-menu');
const ctxInteract = document.getElementById('ctx-interact');
const ctxLapDance = document.getElementById('ctx-lap-dance');
const ctxLapBounce = document.getElementById('ctx-lap-bounce');
const textCtxMenu = document.getElementById('text-ctx-menu');
const msgActionMenu = document.getElementById('msg-action-menu');
const tabCtxMenu = document.getElementById('tab-ctx-menu');
const roomMenu = document.getElementById('room-menu');
const roomActionMenu = document.getElementById('room-action-menu');
const gameStage = document.getElementById('game-stage');
const gameFrame = document.getElementById('game-frame');
const gamePresentationActions = document.getElementById('game-presentation-actions');
const gamePauseFollowupActions = document.getElementById('game-pause-followup-actions');
const gameTerminalActions = document.getElementById('game-terminal-actions');
const embeddedGamePresentationButtons = {
  soundFx: document.getElementById('game-sfx-toggle'),
  visualFx: document.getElementById('game-gfx-toggle'),
  music: document.getElementById('game-music-toggle'),
};
const embeddedGamePrimaryButtons = {
  pause: document.getElementById('game-pause'),
};
const embeddedGameActionRows = {
  pause: gamePauseFollowupActions,
  terminal: gameTerminalActions,
};
let embeddedGameControlsObserver = null;
let embeddedGameControlsSyncFrame = 0;
const embeddedGameActionProxyRows = new WeakMap();

function embeddedGameControlNode(controlId) {
  try {
    return gameFrame?.contentDocument?.querySelector?.(`[data-separate-control="${controlId}"]`) || null;
  } catch (_) {
    return null;
  }
}

function embeddedGamePrimaryControlNode(controlId) {
  try {
    return gameFrame?.contentDocument?.querySelector?.(`[data-external-game-control="${controlId}"]`) || null;
  } catch (_) {
    return null;
  }
}

function embeddedGameControlAvailable(node) {
  if (!node || node.hidden) return false;
  for (let current = node.parentElement; current; current = current.parentElement) {
    if (current.hidden) return false;
  }
  return true;
}

function embeddedGameActionContext() {
  try {
    const frameDocument = gameFrame?.contentDocument;
    const lobbyCode = String(gameRuntime?.lifecycle?.getActiveGame?.()?.lobby_code || '');
    const source = gameFrame?.getAttribute('src');
    if (!gameFrame?.isConnected || !frameDocument || !lobbyCode || !source) return null;
    const expected = new URL(source, window.location.href);
    const actual = new URL(frameDocument.location.href);
    const sourceLobby = expected.searchParams.get('game_session_id') || expected.searchParams.get('lobby') || '';
    if (expected.origin !== window.location.origin || actual.origin !== expected.origin
        || actual.pathname !== expected.pathname || actual.search !== expected.search
        || sourceLobby !== lobbyCode) return null;
    expected.hash = '';
    return { frameDocument, lobbyCode, source: expected.href };
  } catch (_) {
    return null;
  }
}

function syncEmbeddedGameActionRows() {
  const context = embeddedGameActionContext();
  for (const [rowId, host] of Object.entries(embeddedGameActionRows)) {
    if (!host) continue;
    let binding = embeddedGameActionProxyRows.get(host);
    if (!context) {
      if (host.childElementCount) host.replaceChildren();
      host.hidden = true;
      embeddedGameActionProxyRows.delete(host);
      continue;
    }
    if (!binding || binding.context.frameDocument !== context.frameDocument
        || binding.context.lobbyCode !== context.lobbyCode || binding.context.source !== context.source) {
      if (host.childElementCount) host.replaceChildren();
      binding = { context, proxies: new Map() };
      embeddedGameActionProxyRows.set(host, binding);
    }
    const selector = '[data-external-game-row="' + rowId + '"]';
    const sources = Array.from(context.frameDocument.querySelectorAll(selector));
    const sourceSet = new Set(sources);
    for (const [source, proxy] of binding.proxies) {
      if (!sourceSet.has(source)) {
        proxy.remove();
        binding.proxies.delete(source);
      }
    }
    let visibleCount = 0;
    for (const [index, source] of sources.entries()) {
      let proxy = binding.proxies.get(source);
      if (!proxy) {
        proxy = document.createElement('button');
        proxy.type = 'button';
        proxy.addEventListener('click', () => {
          const current = embeddedGameActionContext();
          if (!current || embeddedGameActionProxyRows.get(host) !== binding
              || current.frameDocument !== context.frameDocument || current.lobbyCode !== context.lobbyCode
              || current.source !== context.source || !host.isConnected || !proxy.isConnected
              || proxy.parentElement !== host
              || binding.proxies.get(source) !== proxy || source.ownerDocument !== current.frameDocument
              || !embeddedGameControlAvailable(gameFrame) || !embeddedGameControlAvailable(proxy)
              || !embeddedGameControlAvailable(source) || proxy.disabled || source.disabled
              || source.matches(':disabled')
              || !Array.from(current.frameDocument.querySelectorAll(selector)).includes(source)) return;
          source.click();
          scheduleEmbeddedGamePresentationSync();
        });
        binding.proxies.set(source, proxy);
      }
      const visible = embeddedGameControlAvailable(source);
      const label = String(source.textContent || '').trim() || 'Game action';
      const className = 'btn' + (source.classList.contains('lifecycle-confirm') ? ' btn-danger' : '');
      const title = String(source.title || '');
      if (proxy.textContent !== label) proxy.textContent = label;
      if (proxy.className !== className) proxy.className = className;
      if (proxy.title !== title) proxy.title = title;
      proxy.hidden = !visible;
      proxy.disabled = !visible || source.disabled || source.matches(':disabled');
      if (visible) visibleCount += 1;
      if (host.children[index] !== proxy) host.insertBefore(proxy, host.children[index] || null);
    }
    host.hidden = visibleCount === 0;
  }
}

function syncEmbeddedGamePresentationControls() {
  embeddedGameControlsSyncFrame = 0;
  for (const [controlId, button] of Object.entries(embeddedGamePrimaryButtons)) {
    if (!button) continue;
    const source = embeddedGamePrimaryControlNode(controlId);
    if (!source) continue;
    const visible = embeddedGameControlAvailable(source);
    button.hidden = !visible;
    button.disabled = !visible || source.disabled;
    button.textContent = String(source.textContent || '').trim() || 'Pause game';
  }
  let visibleCount = 0;
  for (const [controlId, button] of Object.entries(embeddedGamePresentationButtons)) {
    if (!button) continue;
    const source = embeddedGameControlNode(controlId);
    const visible = embeddedGameControlAvailable(source);
    button.hidden = !visible;
    button.disabled = !visible || source.disabled;
    if (!visible) {
      button.removeAttribute('aria-pressed');
      continue;
    }
    visibleCount += 1;
    const pressed = source.getAttribute('aria-pressed') === 'true';
    const label = controlId === 'soundFx' ? 'SFX' : controlId === 'visualFx' ? 'GFX' : 'Music';
    button.textContent = `${label} ${pressed ? 'On' : 'Off'}`;
    button.setAttribute('aria-pressed', pressed ? 'true' : 'false');
  }
  if (gamePresentationActions) gamePresentationActions.hidden = visibleCount === 0;

  syncEmbeddedGameActionRows();
}

function scheduleEmbeddedGamePresentationSync() {
  if (embeddedGameControlsSyncFrame) return;
  embeddedGameControlsSyncFrame = requestAnimationFrame(syncEmbeddedGamePresentationControls);
}

function observeEmbeddedGamePresentationControls() {
  embeddedGameControlsObserver?.disconnect();
  embeddedGameControlsObserver = null;
  if (embeddedGameControlsSyncFrame) cancelAnimationFrame(embeddedGameControlsSyncFrame);
  embeddedGameControlsSyncFrame = 0;
  let body = null;
  try {
    body = gameFrame?.contentDocument?.body || null;
  } catch (_) {
    body = null;
  }
  if (!body) {
    syncEmbeddedGamePresentationControls();
    return;
  }
  body.dataset.externalGameControls = 'true';
  embeddedGameControlsObserver = new MutationObserver(scheduleEmbeddedGamePresentationSync);
  embeddedGameControlsObserver.observe(body, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ['hidden', 'disabled', 'aria-pressed'],
  });
  syncEmbeddedGamePresentationControls();
}

for (const [controlId, button] of Object.entries(embeddedGamePresentationButtons)) {
  button?.addEventListener('click', () => {
    const source = embeddedGameControlNode(controlId);
    if (!embeddedGameControlAvailable(source) || source.disabled) return;
    source.click();
    scheduleEmbeddedGamePresentationSync();
  });
}
for (const [controlId, button] of Object.entries(embeddedGamePrimaryButtons)) {
  button?.addEventListener('click', (event) => {
    const source = embeddedGamePrimaryControlNode(controlId);
    if (!embeddedGameControlAvailable(source) || source.disabled) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    source.click();
    scheduleEmbeddedGamePresentationSync();
  }, true);
}
gameFrame?.addEventListener('load', () => {
  if (gamePresentationActions) gamePresentationActions.hidden = true;
  if (gamePauseFollowupActions) {
    gamePauseFollowupActions.replaceChildren();
    gamePauseFollowupActions.hidden = true;
  }
  if (gameTerminalActions) {
    gameTerminalActions.replaceChildren();
    gameTerminalActions.hidden = true;
  }
  for (const button of Object.values(embeddedGamePresentationButtons)) {
    if (button) button.hidden = true;
  }
  window.setTimeout(observeEmbeddedGamePresentationControls, 0);
});
const mediaPicker = document.getElementById('media-picker');
const mediaSearchInput = document.getElementById('media-search-input');
const gifResults = document.getElementById('gif-results');
const gestureGrid = document.getElementById('gesture-grid');
const gestureFileInput = document.getElementById('gesture-file-input');
const gestureTray = document.getElementById('gesture-tray');
const gesturePageLabel = document.getElementById('gesture-page-label');
const gesturePrev = document.getElementById('gesture-prev');
const gestureNext = document.getElementById('gesture-next');
const gestureDeleteModal = document.getElementById('gesture-delete-modal');
const gestureDeleteMessage = document.getElementById('gesture-delete-message');
const gestureDeleteConfirm = document.getElementById('gesture-delete-confirm');
const emojiGrid = document.getElementById('emoji-grid');
const attachMenu = document.getElementById('attach-menu');
const chatFileInput = document.getElementById('chat-file-input');
const sharedAttachmentsModal = document.getElementById('shared-attachments-modal');
const sharedAttachmentsList = document.getElementById('shared-attachments-list');
const p2pTransferComposeModal = document.getElementById('p2p-transfer-compose-modal');
const p2pTransferComposeForm = document.getElementById('p2p-transfer-compose-form');
const p2pTransferOfferModal = document.getElementById('p2p-transfer-offer-modal');
const p2pTransferStatusDrawer = document.getElementById('p2p-transfer-status-drawer');
const transfersButton = document.getElementById('transfers-button');
const transfersTray = document.getElementById('transfers-tray');
const transfersTrayClose = document.getElementById('transfers-tray-close');
const transfersCount = document.getElementById('transfers-count');
let p2pTransferTargetParticipantId = null;
let p2pTransferIncomingOffer = null;
let p2pTransferPreviewUrl = null;
let p2pTransferSelectedFiles = [];
let p2pTransferPreparedAvatar = null;
let p2pTransferOfferStorageReady = false;
let sharedAttachmentsView = 'room';
let transferModalReturnFocus = null;
let transferOfferReturnFocus = null;
const transferGestureCatalog = new Map();
const replyDraftEl = document.getElementById('reply-draft');
const replyDraftAuthorEl = document.getElementById('reply-draft-author');
const replyDraftPreviewEl = document.getElementById('reply-draft-preview');
const voiceDeviceModal = document.getElementById('voice-device-modal');
const voiceDeviceForm = document.getElementById('voice-device-form');
const voiceInputDevice = document.getElementById('voice-input-device');
const voiceOutputDevice = document.getElementById('voice-output-device');
const voiceDeviceStatus = document.getElementById('voice-device-status');
const voiceDeviceRefresh = document.getElementById('voice-device-refresh');
const voiceNoteModal = document.getElementById('voice-note-modal');
const appVersionEl = document.getElementById('app-version');
const latencyMonitorEl = document.getElementById('latency-monitor');
const versionBanner = document.getElementById('version-banner');
const versionBannerText = document.getElementById('version-banner-text');
const versionRefreshBtn = document.getElementById('version-refresh');
const linkIconModal = document.getElementById('link-icon-modal');
const linkIconGrid = document.getElementById('link-icon-grid');
const linkChoiceModal = document.getElementById('link-choice-modal');
const auraModal = document.getElementById('aura-modal');
const auraOptionsEl = document.getElementById('aura-options');
const auraPreviewAvatar = document.getElementById('aura-preview-avatar');
const auraPreviewLayer = document.querySelector('#aura-modal .aura-preview-layer');
const avatarFileInput = document.getElementById('avatar-file-input');
const nameplateFileInput = document.getElementById('nameplate-file-input');
const ctxChangeNameplate = document.getElementById('ctx-change-nameplate');
const ctxRemoveNameplate = document.getElementById('ctx-remove-nameplate');
const ctxToggleWebcam = document.getElementById('ctx-toggle-webcam');
const sidebarWebcamToggle = document.getElementById('webcam-toggle');
const ctxWebcamVisibility = document.getElementById('ctx-webcam-visibility');
const ctxWebcamReceive = document.getElementById('ctx-webcam-receive');
const ctxAvatarVisibility = document.getElementById('ctx-avatar-visibility');
const ctxAvatarUserVisibility = document.getElementById('ctx-avatar-user-visibility');
const ctxNameplateVisibility = document.getElementById('ctx-nameplate-visibility');
const ctxNameplateUserVisibility = document.getElementById('ctx-nameplate-user-visibility');
const ctxGestureSenderVisibility = document.getElementById('ctx-gesture-sender-visibility');
const ctxSendFileGesture = document.getElementById('ctx-send-file-gesture');
const ctxAuras = document.getElementById('ctx-auras');
const ctxOrientationWrap = document.getElementById('ctx-orientation-wrap');
const ctxOrientation = document.getElementById('ctx-orientation');
const ctxOrientationSubmenu = document.getElementById('ctx-orientation-submenu');
const avatarSizeModal = document.getElementById('avatar-size-modal');
const avatarSizeForm = document.getElementById('avatar-size-form');
const avatarSizeTitle = document.getElementById('avatar-size-title');
const avatarSizeCap = document.getElementById('avatar-size-cap');
const avatarSizeCurrent = document.getElementById('avatar-size-current');
const avatarSizeAvatarFields = document.getElementById('avatar-size-avatar-fields');
const avatarSizeWebcamFields = document.getElementById('avatar-size-webcam-fields');
const avatarSizeEdge = document.getElementById('avatar-size-edge');
const avatarSizeMode = document.getElementById('avatar-size-mode');
const avatarExactWidth = document.getElementById('avatar-exact-width');
const avatarExactHeight = document.getElementById('avatar-exact-height');
const avatarExactMatchParticipant = document.getElementById('avatar-exact-match-participant');
const avatarSizeWebcamPreset = document.getElementById('avatar-size-webcam-preset');
const avatarSizeWebcamWidth = document.getElementById('avatar-size-webcam-width');
const avatarSizeWebcamHeight = document.getElementById('avatar-size-webcam-height');
const avatarSizeAspectLock = document.getElementById('avatar-size-aspect-lock');
const avatarSizeMatchWrap = document.getElementById('avatar-size-match-wrap');
const avatarSizeMatchParticipant = document.getElementById('avatar-size-match-participant');
const avatarSizeStatus = document.getElementById('avatar-size-status');
const AVATAR_ORIENTATION_LABELS = Object.freeze({
  original: 'Original',
  'flip-horizontal': 'Flip Horizontally',
  'flip-vertical': 'Flip Vertically',
  'flip-both': 'Flip Horizontally and Vertically',
});
const sessionLockEl = document.getElementById('session-lock');
const sessionLockForm = document.getElementById('session-lock-form');
const sessionLockPassword = document.getElementById('session-lock-password');
const sessionLockError = document.getElementById('session-lock-error');
const reportProblemModal = document.getElementById('report-problem-modal');
const reportProblemForm = document.getElementById('report-problem-form');
const reportProblemSummary = document.getElementById('report-problem-summary');
const reportProblemScreenshot = document.getElementById('report-problem-screenshot');
const reportProblemStatus = document.getElementById('report-problem-status');
const chatOptionsModal = document.getElementById('chat-options-modal');
const webcamOptionsReset = document.getElementById('webcam-options-reset');
const gestureShowAnimations = document.getElementById('gesture-show-animations');
const gestureShowText = document.getElementById('gesture-show-text');
const gesturePlaySounds = document.getElementById('gesture-play-sounds');
const gestureOptionsReset = document.getElementById('gesture-options-reset');
const gestureOptionsStatus = document.getElementById('gesture-options-status');
const showRoleMessageColors = document.getElementById('show-role-message-colors');
const showRoleMessageTitles = document.getElementById('show-role-message-titles');
const showIdentityNameplates = document.getElementById('show-identity-nameplates');
const importantMessageControl = document.getElementById('important-message-control');
const importantMessageToggle = document.getElementById('important-message-toggle');
const CHAT_ROLE_MESSAGE_COLORS_KEY = 'corechat.chat.role-message-colors.v1';
const CHAT_ROLE_MESSAGE_TITLES_KEY = 'corechat.chat.role-message-titles.v1';
const CHAT_IDENTITY_NAMEPLATES_KEY = 'corechat.chat.identity-nameplates.v1';
const storedChatBoolean = (key, fallback = true) => {
  try {
    const value = window.localStorage.getItem(key);
    return value === null ? fallback : value !== 'false';
  } catch (_) {
    return fallback;
  }
};
let chatAppearancePreferences = {
  showRoleMessageColors: storedChatBoolean(CHAT_ROLE_MESSAGE_COLORS_KEY, false),
  showRoleMessageTitles: storedChatBoolean(CHAT_ROLE_MESSAGE_TITLES_KEY, false),
  showIdentityNameplates: storedChatBoolean(CHAT_IDENTITY_NAMEPLATES_KEY),
};
if (showRoleMessageTitles) showRoleMessageTitles.checked = chatAppearancePreferences.showRoleMessageTitles;
let webcamPreferencesPending = false;
let gesturePreferencesPending = false;
let bootstrapped = false;
let textMenuMode = 'copy';
let lastLatencyMs = null;
const latencySamples = [];
const isLoopbackHost = /^(?:127(?:\.\d{1,3}){3}|localhost)$/.test(location.hostname);
let ctxMenuParticipantId = null;
let memberProfileUserId = null;
const memberProfileCache = new Map();
const memberProfileRequests = new Map();
let memberProfileReturnFocus = null;
let memberProfileSnapshot = null;
let memberProfileRequestVersion = 0;
const MEMBER_PROFILE_IDENTITY_WAIT_TIMEOUT_MS = 2000;
const MEMBER_PROFILE_IDENTITY_WAIT_INTERVAL_MS = 40;
let avatarOrientationPending = false;
let avatarOrientationIntentGeneration = 0;
let avatarOrientationQueuedIntent = null;
let avatarSizePending = false;
let avatarSizeModalMode = 'avatar';
let avatarSizeResetRequested = false;
let avatarSizeStartWebcam = false;
let avatarSizeStartConfirmed = false;
let avatarSizeAspectRatio = 1;
let avatarSizeInputSync = false;
let hostModalTargetParticipantId = null;
let msgActionTargetId = null;
let msgActionTargetChat = null;
let tabCtxTargetChat = null;
let pendingDeleteMessageId = null;
let pendingDeleteChatKey = null;
let webcamStream = null;
let webcamIntent = false;
let webcamAcquisitionState = 'idle';
let webcamOperationGeneration = 0;
let confirmedWebcamAudience = null;
let webcamAudienceDecision = null;
let webcamAudienceReturnFocus = null;
let privateVoiceReturnFocus = null;
const pendingRemoteVideoStreams = new Map();

function syncLocalWebcamToggleButton() {
  if (!sidebarWebcamToggle) return;
  const active = Boolean(webcamIntent || webcamStream);
  const blocked = !webcamUseAllowed() && !active;
  sidebarWebcamToggle.textContent = active ? 'Stop Camera' : 'Start Camera';
  sidebarWebcamToggle.disabled = blocked;
  sidebarWebcamToggle.title = blocked ? 'Webcam use is disabled for this installation.' : '';
  sidebarWebcamToggle.setAttribute('aria-pressed', active ? 'true' : 'false');
}
const AVATAR_STAGE_SIZE = 150;
const blockedUserIds = new Set();
const mutedUserPolicies = new Map();
const revealedPersonalMuteMessages = new Set();
const PERSONAL_MUTE_SCOPES = ['text-bubbles', 'gestures-audio', 'notices-unread', 'voice', 'avatar-webcam-placeholder'];
const MUTED_MESSAGE_PLACEHOLDER = 'Muted message — reveal this one message from its message actions.';
let muteExpiryTimer = null;
let voiceNoteRecorder = null;
let voiceNoteChunks = [];
let voiceNoteStream = null;
let voiceNoteCancelled = false;
let lastVoiceParticipants = [];
let latestAppVersion = '';
const APP_VERSION_CACHE_KEY = 'chatspace_seen_version';
const SESSION_LOCK_PREFIX = 'chatspace_session_locked_';
let memorySeenVersion = '';
let pendingLinkIconTargetId = null;
const animatedDmMessageIds = new Set();
let roomExitInProgress = false;
let roomDeleteInProgress = false;
const seenRoomHistoryClears = new Set();

let gifSearchTimer = null;
let gifSearchState = null;
const gifDurationCache = new Map();
let activeMediaTab = 'gifs';
let gesturePage = 1;
let gestureHasMore = false;
let gesturePaletteLoaded = false;
let gestureOwnedCount = 0;
let gestureOwnedLimit = 50;
let gestureSearchTimer = null;
let pendingGestureDelete = null;
let activeGestureAudio = null;
const mediaSearchValues = { gifs: '', gestures: '' };
const EMOJI_OPTIONS = [
  '😀','😃','😄','😁','😂','🤣','😊','😌','😉','😏','😈','😍','🥰','😘','😇','🙂','🙃','😋','😜','🤭',
  '😭','🥺','😤','😡','😱','😳','🤔','🙄','😴','🤯','😎','🥳','🖤','🤍','❤️','🧡','💛','💚','💙','💜',
  '💕','💞','💋','✨','🔥','🌙','⭐','🌸','🌹','🍒','🍓','☕','🍷','🎉','🎵','🎧','🎮','♟️','✅','👀',
  '👍','👎','👏','🙏','💅','👑','💬','🌐','✉️','➕','🔒','🔓','⚠️','💀','🫶','🫦','😌','😏','😉','😈'
];

let roomServerClock = null;

async function initializeAvatarRuntime() {
  if (avatarRuntime) return avatarRuntime;
  initializeEmojiComposer();

  const [{ Core }, { ChatRuntime }, { RoomRuntime }, { VoiceRuntime }, { GameRuntime }, { RoomEffectsRuntime }, { ImportedRoomRuntime }, { AvatarRuntime }, { PollingRuntime }, { installRuntimeDiagnostics }, { RuntimeRequestClient }, { RuntimeIssueCaptureService }, { GesturePresentationService }, { GestureCatalogController }, { P2PTransferService }, ServerClock] = await Promise.all([
    import(appUrl('/assets/js/core/core.js')),
    import(appUrl('/assets/js/runtime/chat/chat-runtime.js?v=20260914-custom-emojis')),
    import(appUrl('/assets/js/runtime/room/room-runtime.js')),
    import(appUrl('/assets/js/runtime/voice/voice-runtime.js')),
    import(appUrl('/assets/js/runtime/game/game-runtime.js?v=9951ddcc1828')),
    import(appUrl('/assets/js/runtime/room-effects/room-effects-runtime.js')),
    import(appUrl('/assets/js/runtime/imported-room/imported-room-runtime.js?v=20260913-regression')),
    import(`${appUrl('/assets/js/runtime/avatar/avatar-runtime.js?v=20260914-bubble-emojis')}?v=20260913-away-layout`),
    import(`${appUrl('/assets/js/runtime/polling/polling-runtime.js')}?v=20260901-latency-phase-r1`),
    import(appUrl('/assets/js/core/runtime-diagnostics.js')),
    import(appUrl('/assets/js/core/runtime-request-client.js?v=20260913-server-clock-r2')),
  import(appUrl('/assets/js/core/runtime-issue-capture-service.js?v=20260913-local-failure-recovery')),
    import(appUrl('/assets/js/runtime/gesture/gesture-presentation-service.js')),
    import(appUrl('/assets/js/runtime/gesture/gesture-catalog-controller.js?v=20260914-menu-position')),
    import(appUrl('/assets/js/runtime/chat/services/p2p-transfer-service.js?v=20260913-server-clock')),
    import(appUrl('/assets/js/core/animation-server-clock.js?v=20260913-r2')),
  ]);
  roomServerClock = ServerClock;

  if (!runtimeDiagnosticsInstallation) {
    const diagnosticsCapability = runtimeDiagnosticsCapability();
    runtimeDiagnosticsInstallation = installRuntimeDiagnostics({
      globalObject: window,
      enabled: diagnosticsCapability.enabled,
      mode: diagnosticsCapability.mode,
      verificationControls: diagnosticsCapability.verificationControls,
    });
    runtimeDiagnostics = runtimeDiagnosticsInstallation.diagnostics;
    runtimeVerificationControls = runtimeDiagnosticsInstallation.controls;
    runtimeVerificationControls.register('replace-local-webcam-capture', async () => {
      const acquisition = await acquireLocalWebcamCapture({
        video: { width: { ideal: 640 }, height: { ideal: 640 }, frameRate: { ideal: 30, max: 30 } },
        audio: false,
      }, 'replace');
      if (!acquisition.stream) return acquisition;
      return replaceLocalWebcamCapture(
        acquisition.stream,
        'replace',
        acquisition.token,
      );
    });
  }

  if (!runtimeIssueCaptureService) {
    runtimeIssueCaptureService = new RuntimeIssueCaptureService({
      endpoint: appUrl('/api/runtime_issues.php'),
      csrfToken: CSRF_TOKEN,
      diagnostics: runtimeDiagnostics,
      buildId: '000063-admin-game-diagnostics-r2',
    }).start();
    runtimeIssueCaptureService.observeGameFrame(gameFrame, {
      getContext: () => {
        const activeGame = gameRuntime?.lifecycle?.getActiveGame?.() || null;
          return {
            gameType: String(activeGame?.game_type || ''),
            sessionStatus: String(activeGame?.framework?.status || ''),
            gameKey: String(activeGame?.game_key || activeGame?.id || ''),
          };
      },
    });
    runtimeVerificationControls.register('capture-game-diagnostic', details =>
      runtimeIssueCaptureService.captureGameDiagnostic(details || {})
    );
  }

  runtimeRequestClient = new RuntimeRequestClient({
    resolveUrl: appUrl,
    csrfToken: CSRF_TOKEN,
    lifecycleSignal: runtimeRequestAbortController.signal,
    onFailure(error) {
      if (error?.code === 'REQUEST_ABORTED') return;
      if (handleMissingRoomResponse(error)) return;
      if (
        error?.details?.endpointCategory === 'game-chat'
        && error?.details?.requestContext?.ownerCurrentAtOutcome === false
      ) return;
      recordRuntimeDiagnostic('requests', 'runtime-request-failure', {
        code: error.code,
        message: error.message,
        ...error.details,
      });
      if (error?.details?.endpointCategory === 'games') {
        runtimeIssueCaptureService?.captureGameRequestFailure(error, {
          gameType: String(error?.details?.requestContext?.gameType || 'room-game-runtime'),
        })?.catch(() => {});
      } else {
        runtimeIssueCaptureService?.captureRequestFailure(error)?.catch(() => {});
      }
    },
  });

  gesturePresentation = new GesturePresentationService({
    onChange(change) {
      if (!cfg) return;
      applyGesturePresentationChange(change);
    },
  });
  GestureCatalogControllerClass = GestureCatalogController;
  p2pTransferService = new P2PTransferService();

  chatRuntimeCore = new Core();
  chatRuntimeCore.registerService('runtime-diagnostics', runtimeDiagnostics);
  chatRuntimeCore.registerService('runtime-request-client', runtimeRequestClient);
  chatRuntime = new ChatRuntime();
  roomRuntime = new RoomRuntime();
  voiceRuntime = new VoiceRuntime();
  gameRuntime = new GameRuntime();
  roomEffectsRuntime = new RoomEffectsRuntime();
  importedRoomRuntime = new ImportedRoomRuntime();
  pollingRuntime = new PollingRuntime();
  avatarRuntime = new AvatarRuntime();

  chatRuntimeCore.registerModule(chatRuntime);
  chatRuntimeCore.registerModule(roomRuntime);
  chatRuntimeCore.registerModule(voiceRuntime);
  chatRuntimeCore.registerModule(gameRuntime);
  chatRuntimeCore.registerModule(roomEffectsRuntime);
  chatRuntimeCore.registerModule(importedRoomRuntime);
  chatRuntimeCore.registerModule(pollingRuntime);
  chatRuntimeCore.registerModule(avatarRuntime);
  chatRuntimeCore.initialize();
  chatRuntimeCore.start();

  participants = avatarRuntime.state;
  configureAvatarCoordinator();
  configureAvatarRelationshipManagement();
  configureAvatarDragController();
  configureAvatarAura();
  configureAvatarVisibility();
  configureP2PAvatarRuntime();
  configureP2PTransferRuntime();
  configureParticipantActionCatalog();
  configureChatMessageRenderer();
  configureChatPrivateChats();
  configureChatEventRouter();
  configureChatMessageActions();
  configureChatUnread();
  configureChatNavigation();
  configureChatReply();
  configureChatTyping();
  configureChatComposer();
  configureChatMediaSend();
  configureChatGameChat();
  configureRoomEventRouter();
  configureVoiceRuntime();
  configureGameRuntime();
  configureRoomEffectsRuntime();
  configureImportedRoomRuntime();
  configureChatPoll();
  runtimeVerificationControls?.register(
    'avatar-relationship-layout-snapshot',
    relationshipLayoutVerificationSnapshot,
  );

  return avatarRuntime;
}

function configureAvatarCoordinator() {
  avatarRuntime?.coordinator?.configure({
    getConfig: () => cfg,
    stageSize() {
      return relationshipCanvasSize();
    },
    relationshipViewportSize() {
      return relationshipViewportSize();
    },
    beginRelationshipCanvasMeasurement() {
      return beginRelationshipCanvasMeasurement();
    },
    setRelationshipCanvasSize(size = {}) {
      return setRelationshipCanvasSize(size);
    },
    baseAvatarSize() {
      return AVATAR_STAGE_SIZE;
    },
    isLayoutLocked() {
      return layoutLocked;
    },
    requestRelationshipRefreshFrame(callback) {
      return requestAnimationFrame(callback);
    },
    cancelAnimationFrame(handle) {
      cancelAnimationFrame(handle);
    },
    matchMedia(query) {
      return window.matchMedia(query);
    },
    window,
    mutateRelationship(payload = {}) {
      return runtimeRequestClient.postJson('/api/avatar_relationships.php', {
        ...payload,
        session_id: cfg.sessionId,
        join_token: cfg.myJoinToken,
      }, {
        operation: `lap-animation-${String(payload.action || 'mutation')}`,
        endpointCategory: 'avatar-relationship-animation',
      });
    },
    onLapAnimationStateChanged() {
      const participant = participants.get(Number(ctxMenuParticipantId || 0));
      if (participant && ctxMenu?.classList.contains('visible')) {
        syncParticipantActionMenu(participant, Number(participant.id) === Number(cfg.myParticipantId));
      }
      if (avatarRuntime?.relationshipManagement?.isOpen?.()) {
        avatarRuntime.relationshipManagement.refresh().catch(warnRuntimeRequest);
      }
    },
    positionAvatar,
    renderParticipant,
    renderPeople,
    renderLinkTabs,
    refreshLinkClasses,
    updateStageLinkIcons,
    closeLinkChoiceModal,
    openLinkChoiceModal() {
      resetLinkChoiceModal();
      linkChoiceModal?.classList.add('open');
    },
    openLapSeatChoice() {
      const actions = document.getElementById('link-choice-actions');
      const seats = document.getElementById('link-choice-seat');
      const prompt = document.getElementById('link-choice-prompt');
      if (actions) actions.hidden = true;
      if (seats) seats.hidden = false;
      if (prompt) prompt.textContent = 'Choose which side you would like to sit on.';
      document.getElementById('link-choice-bottom-right')?.focus();
    },
    isRelationshipBlocked(first, second) {
      const firstIsCurrent = Number(first?.id) === Number(cfg?.myParticipantId);
      const secondIsCurrent = Number(second?.id) === Number(cfg?.myParticipantId);
      return (firstIsCurrent && isUserBlocked(second?.user_id))
        || (secondIsCurrent && isUserBlocked(first?.user_id));
    },
    recordRelationshipDiagnostic(entry = {}) {
      recordRuntimeDiagnostic('relationships', entry.event || 'relationship-eligibility', entry);
    },
    animateLinkedPair(pair) {
      pair.forEach(participant => {
        if (participant?.avatarEl) {
          participant.avatarEl.style.transition = 'left .35s ease, top .35s ease';
        }
      });
      setTimeout(() => {
        pair.forEach(participant => {
          if (participant?.avatarEl) participant.avatarEl.style.transition = '';
        });
      }, 380);
    },
    onLinkUnavailable(participantId) {
      if (activeChatKey() === `link:${participantId}`) switchChat('room');
    },
    onCurrentParticipantUnlinked() {
      if (activeChatKey().startsWith('link:')) switchChat('room');
    },
    persistLink({ target, linkMode, initiator, lapSide = null }) {
      return apiPost('/api/users.php', {
        action: 'link',
        session_id: cfg.sessionId,
        join_token: cfg.myJoinToken,
        target_participant_id: target.id,
        link_mode: linkMode,
        lap_side: lapSide,
        initiator_x: initiator.position_x,
        initiator_y: initiator.position_y,
        target_x: target.position_x,
        target_y: target.position_y,
      });
    },
    persistUnlink() {
      return apiPost('/api/users.php', {
        action: 'unlink',
        session_id: cfg.sessionId,
        join_token: cfg.myJoinToken,
      });
    },
    persistPosition(participant) {
      return apiPost('/api/users.php', {
        action: 'position',
        session_id: cfg.sessionId,
        join_token: cfg.myJoinToken,
        x: participant.position_x,
        y: participant.position_y,
      }).catch(warnRuntimeRequest);
    },
    persistPositions(list) {
      return apiPost('/api/users.php', {
        action: 'position_pair',
        session_id: cfg.sessionId,
        join_token: cfg.myJoinToken,
        positions: list.map(p => ({ participant_id: p.id, x: p.position_x, y: p.position_y })),
      }).catch(warnRuntimeRequest);
    },
    persistRelationshipPositions(operation) {
      return apiPost('/api/users.php', {
        action: 'relationship_position',
        session_id: cfg.sessionId,
        join_token: cfg.myJoinToken,
        relationship_id: operation.relationshipId,
        expected_version: operation.relationshipVersion,
        operation_id: operation.operationId,
        positions: operation.positions,
      });
    },
    warnError: warnRuntimeRequest,
    persistLinkIcon({ targetId, iconName }) {
      return apiPost('/api/users.php', {
        action: 'link_icon',
        session_id: cfg.sessionId,
        join_token: cfg.myJoinToken,
        target_participant_id: targetId,
        icon_name: iconName,
      });
    },
    showWarning,
    alertError(error) {
      alert(error.message || error);
    },
  });
}

function configureAvatarDragController() {
  avatarRuntime?.drag?.configure({
    getConfig: () => cfg,
    stageElement() {
      return participantStage(participants.get(Number(cfg?.myParticipantId)));
    },
    baseAvatarSize() {
      return AVATAR_STAGE_SIZE;
    },
    isUserBlocked,
    requestAnimationFrame(callback) {
      return requestAnimationFrame(callback);
    },
  });
}

function configureAvatarRelationshipManagement() {
  avatarRuntime?.relationshipManagement?.configure({
    document,
    getConfig: () => cfg,
    fetchManagementState({ relationshipId = "" } = {}) {
      const query = new URLSearchParams({
        session_id: cfg.sessionId,
        join_token: cfg.myJoinToken,
      });
      if (relationshipId) query.set('relationship_id', relationshipId);
      return runtimeRequestClient.getJson(`/api/avatar_relationships.php?${query}`, {
        operation: 'load-relationship-management',
        endpointCategory: 'avatar-relationship-management',
      });
    },
    mutateRelationship(payload = {}) {
      return runtimeRequestClient.postJson('/api/avatar_relationships.php', {
        ...payload,
        session_id: cfg.sessionId,
        join_token: cfg.myJoinToken,
      }, {
        operation: `relationship-management-${String(payload.action || 'mutation')}`,
        endpointCategory: 'avatar-relationship-management',
      });
    },
    showError(error) {
      showWarning(error?.message || 'Relationship management could not be refreshed.');
    },
    recordDiagnostic(entry = {}) {
      recordRuntimeDiagnostic('relationships', entry.event || 'relationship-management', entry);
    },
  });
}

function configureAvatarAura() {
  avatarRuntime?.aura?.configure({
    document,
    window,
    appUrl,
    cacheBust,
    apiPost,
    getConfig: () => cfg,
    getParticipants: () => participants,
    fetchJson(path) {
      return runtimeRequestClient.getJson(path, {
        operation: 'load-avatar-aura-catalog',
        endpointCategory: 'avatar-aura',
      });
    },
    onError(error) {
      warnRuntimeRequest(error);
    },
  });
}

function configureAvatarVisibility() {
  avatarRuntime?.visibility?.configure({
    mutate(body) {
      return mutateVisibilityWithRetry({
        url: '/api/avatar_visibility_preferences.php',
        staleCode: 'AVATAR_VISIBILITY_STALE',
        body: {
        ...body,
        session_id: cfg?.sessionId,
        join_token: cfg?.myJoinToken,
        },
        applyStaleProjection(projection) {
          avatarRuntime?.visibility?.applyServerProjection(projection, 'avatar-visibility:stale-refresh');
        },
      });
    },
    onMutationResult(result) {
      applyRevealedAvatarSources(result?.revealedAvatars || []);
      applyNameplateVisibilityProjection(result?.nameplateVisibilityPreferences || {});
    },
    onChange(change) {
      reconcileAvatarVisibility(change);
    },
  });
}

function applyRevealedAvatarSources(revealed = []) {
  const byUser = new Map(revealed.map(item => [Number(item.user_id), item]));
  if (!byUser.size) return;
  participants.forEach(person => {
    const source = byUser.get(Number(person.user_id));
    if (source) participants.update(person.id, source);
  });
  chatMessageState()?.forEachChannelMessage?.(message => {
    const source = byUser.get(Number(message.user_id));
    if (source) Object.assign(message, source);
    (message.reactions || []).forEach(reaction => {
      const reactionSource = byUser.get(Number(reaction.user_id));
      if (reactionSource) Object.assign(reaction, reactionSource);
    });
  });
  (cfg?.dmUsers || []).forEach(user => {
    const source = byUser.get(Number(user.id));
    if (source) Object.assign(user, source);
  });
}

function configureParticipantActionCatalog() {
  roomRuntime?.participantActions?.configure({
    getViewer: () => participants.get(Number(cfg?.myParticipantId)) || null,
    getAvatarVisibility: participant => avatarVisibilityFor(participant),
    getNameplateVisibility: participant => nameplateVisibilityFor(participant),
    getGestureMediaVisibility: participant => ({
      hidden: gesturePresentation?.isSenderHidden?.(participant?.user_id) === true,
    }),
    getWebcamPolicy: participant => webcamViewerPolicyFor(participant),
    isBlocked: isUserBlocked,
    webcamAllowed: webcamUseAllowed,
    getTransferPolicy: () => ({
      effectiveEnabled: Boolean(
        cfg?.p2pTransferPolicy?.effectiveEnabled
        || (cfg?.serverMediaPolicy?.serverAttachmentsEnabled && ['server-only', 'both'].includes(cfg?.serverMediaPolicy?.fileMode))
        || ['server-only', 'both'].includes(cfg?.serverMediaPolicy?.sendGestureMode)
      ),
      filesEnabled: Boolean(cfg?.p2pTransferPolicy?.filesEnabled || (cfg?.serverMediaPolicy?.serverAttachmentsEnabled && ['server-only', 'both'].includes(cfg?.serverMediaPolicy?.fileMode))),
      sendGestureEnabled: Boolean(cfg?.p2pTransferPolicy?.sendGestureEnabled || ['server-only', 'both'].includes(cfg?.serverMediaPolicy?.sendGestureMode)),
    }),
    getAvatarInteractionActions(participant) {
      return avatarRuntime?.dances?.participantActions(participant) || [];
    },
  });
}

function configureChatMessageRenderer() {
  chatRuntime?.renderer?.configure({
    document,
    window,
    CSS,
    messagesElement: messagesEl,
    getConfig: () => cfg,
    getParticipants: () => participants,
    getActiveChat: () => activeChatKey(),
    esc,
    mediaUrl,
    isHttpUrl,
    formatBytes,
    fullTimestamp,
    parseServerDate,
    messageAvatarUrl,
    avatarPresentationHtml,
    participantRoleClass,
    participantRoleLabel,
    participantRoleBadgeHtml,
    getChatAppearancePreferences: () => ({
      ...chatAppearancePreferences,
      showRoleMessageTitles: showRoleMessageTitles?.checked === true,
    }),
    identityNameplateUrl,
    displayNameFor,
    messageVisible,
    gestureFromMessage,
    gesturePresentation,
    showGestureAgain: showGestureFromMessage,
    openMessageActionMenu,
    openParticipantActionMenu: openAvatarContextMenu,
    openMemberProfile,
    applyReaction,
  });
}

async function mutateVisibilityWithRetry({ url, staleCode, body, applyStaleProjection }) {
  try {
    return await apiPost(url, body);
  } catch (error) {
    const payload = error?.responsePayload;
    const projection = payload?.preferences;
    if (String(payload?.code || '') !== staleCode || !projection) throw error;
    applyStaleProjection?.(projection);
    return apiPost(url, {
      ...body,
      expected_version: Math.max(1, Number(projection.version || 1)),
    });
  }
}

function configureP2PTransferRuntime() {
  p2pTransferService?.configure({
    window,
    setInterval: window.setInterval.bind(window),
    clearInterval: window.clearInterval.bind(window),
    getConfig: () => cfg,
    getPolicy: () => cfg?.p2pTransferPolicy || {},
    getClientEpoch: () => voiceRuntime?.media?.getClientEpoch?.() || '',
    apiPost,
    apiGet(url) {
      return runtimeRequestClient.getJson(url, {
        operation: 'p2p-transfer-poll',
        endpointCategory: 'p2p-transfer',
        cache: 'no-store',
      });
    },
    async confirmDirectToDisk(offer) {
      if (!document.getElementById('p2p-transfer-direct-disk')?.checked) return false;
      await p2pTransferService.prepareDirectToDisk(offer);
      return true;
    },
    onReselectRequired(offer) {
      renderP2PTransferStatus({offer, state: 'resume-source-required', detail: 'Choose the exact original file or folder again.'});
      openTransfersTray();
    },
    onIncomingOffer: openIncomingTransferOffer,
    onPreview: renderIncomingTransferPreview,
    onStatus: renderP2PTransferStatus,
    onReceived: receiveP2PTransfer,
  });
}

function configureP2PAvatarRuntime() {
  avatarRuntime?.p2pAvatar?.configure({
    window,
    fetch(url, options = {}) {
      return window.fetch(appUrl(url), options);
    },
    getConfig: () => cfg,
    sendSignal(media, participantId, type, data) {
      return voiceRuntime?.media?.sendExternalSignal(media, participantId, type, data);
    },
    authorizeSource(authorization, assetKind = 'avatar') {
      return apiPost('/api/p2p_avatar.php', {
        action: 'authorize_source',
        session_id: cfg?.sessionId,
        participant_id: cfg?.myParticipantId,
        join_token: cfg?.myJoinToken,
        authorization,
        asset_kind: assetKind,
      });
    },
    refreshAuthorization(targetParticipantId) {
      return apiPost('/api/p2p_avatar.php', {
        action: 'authorize_viewer',
        session_id: cfg?.sessionId,
        participant_id: cfg?.myParticipantId,
        join_token: cfg?.myJoinToken,
        target_participant_id: Number(targetParticipantId),
      });
    },
    onAuthorization(participantId, projection, assetKind = 'avatar') {
      const person = participants.get(Number(participantId));
      if (!person) return;
      participants.update(Number(participantId), assetKind === 'nameplate' ? {
        p2p_nameplate: projection,
        nameplate_delivery: 'p2p-prefetch',
      } : {
        p2p_avatar: projection,
        avatar_delivery: 'p2p-prefetch',
      });
    },
    onAssetReady(participantId, assetKind, objectUrl, identity) {
      const person = participants.get(Number(participantId));
      const projection = assetKind === 'nameplate' ? person?.p2p_nameplate : person?.p2p_avatar;
      if (!person || String(projection?.identity || '') !== String(identity)) return false;
      participants.update(Number(participantId), assetKind === 'nameplate' ? {
        nameplate_path: null,
        nameplate_url: objectUrl,
        p2p_nameplate_object_identity: identity,
      } : {
        avatar_path: null,
        avatar_url: objectUrl,
        p2p_avatar_object_identity: identity,
        avatar_version: Date.now(),
      });
      renderParticipant(person, { animateJoin: false });
      renderActiveChat();
      return true;
    },
    onAssetCleared(participantId, assetKind, identity, reason) {
      const person = participants.get(Number(participantId));
      const current = assetKind === 'nameplate'
        ? person?.p2p_nameplate_object_identity
        : person?.p2p_avatar_object_identity;
      if (!person || String(current || '') !== String(identity)) return;
      participants.update(Number(participantId), assetKind === 'nameplate' ? {
        nameplate_path: null,
        nameplate_url: null,
        p2p_nameplate_object_identity: null,
      } : {
        avatar_path: null,
        avatar_url: null,
        p2p_avatar_object_identity: null,
        avatar_version: Date.now(),
      });
      if (!roomExitInProgress && reason !== 'participant-removed' && reason !== 'service-destroyed') {
        renderParticipant(person, { animateJoin: false });
        renderActiveChat();
      }
    },
    onAvatarReady(participantId, objectUrl, identity) {
      const person = participants.get(Number(participantId));
      if (!person || String(person?.p2p_avatar?.identity || '') !== String(identity)) return false;
      participants.update(Number(participantId), {
        avatar_path: null,
        avatar_url: objectUrl,
        p2p_avatar_object_identity: identity,
        avatar_version: Date.now(),
      });
      renderParticipant(person, { animateJoin: false });
      renderActiveChat();
      return true;
    },
    onAvatarCleared(participantId, identity, reason) {
      const person = participants.get(Number(participantId));
      if (!person || String(person.p2p_avatar_object_identity || '') !== String(identity)) return;
      participants.update(Number(participantId), {
        avatar_path: null,
        avatar_url: null,
        p2p_avatar_object_identity: null,
        avatar_version: Date.now(),
      });
      if (!roomExitInProgress && reason !== 'participant-removed' && reason !== 'service-destroyed') {
        renderParticipant(person, { animateJoin: false });
        renderActiveChat();
      }
    },
    isAssetHidden: (participant, assetKind = 'avatar') => assetKind === 'nameplate'
      ? nameplateVisibilityFor(participant).hidden
      : avatarVisibilityFor(participant).hidden,
    isHidden: participant => avatarVisibilityFor(participant).hidden,
    isBlocked: participant => isUserBlocked(participant?.user_id),
    recordLifecycle(entry = {}) {
      recordRuntimeDiagnostic('avatarP2P', entry.event || 'p2p-avatar-lifecycle', entry);
    },
    warn: warnRuntimeRequest,
  });
}

function configureChatPrivateChats() {
  chatRuntime?.privateChats?.configure({
    apiPost,
    getConfig: () => cfg,
    getActiveChat: () => activeChatKey(),
    channelForApi,
    clearUnread,
    renderActiveChat,
    renderLinkTabs,
    switchChat,
    showWarning,
    isUserBlocked,
    participantName(participantId) {
      return participants.get(Number(participantId))?.display_name || null;
    },
    focusComposer() {
      document.getElementById('chat-input')?.focus();
    },
  });
}

function configureChatNotifications() {
  if (!cfg?.myUserId) return;
  chatRuntime?.notifications?.configure({
    document, window,
    getConfig: () => cfg,
    getActiveChat: () => activeChatKey(),
    storageKey: `corechat.message-chime.v1:${APP_BASE}:${cfg.myUserId}`,
    soundUrl: appUrl('/assets/audio/message-chime.wav'),
    toggle: document.getElementById('message-chime-toggle'),
    focusedToggle: document.getElementById('message-chime-focused-toggle'),
    roomToggle: document.getElementById('room-message-chime-toggle'),
    roomIntervalDown: document.getElementById('room-message-chime-interval-down'),
    roomIntervalUp: document.getElementById('room-message-chime-interval-up'),
    roomIntervalValue: document.getElementById('room-message-chime-interval-value'),
    roomIntervalPresets: document.getElementById('room-message-chime-interval-presets'),
    preview: document.getElementById('message-chime-preview'),
    volumeDown: document.getElementById('message-chime-volume-down'),
    volumeUp: document.getElementById('message-chime-volume-up'),
    volumeValue: document.getElementById('message-chime-volume-value'),
    intervalDown: document.getElementById('message-chime-interval-down'),
    intervalUp: document.getElementById('message-chime-interval-up'),
    intervalValue: document.getElementById('message-chime-interval-value'),
    intervalPresets: document.getElementById('message-chime-interval-presets'),
    status: document.getElementById('message-chime-status'),
  });
}

function configureChatEventRouter() {
  chatRuntime?.events?.configure({
    getConfig: () => cfg,
    getActiveChat: () => activeChatKey(),
    relationshipChatKeyFromPayload,
    dmPartnerIdFromPayload,
    renderMessage,
    addMessageToChannel,
    updateMessageInChannels,
    removeMessageFromChannels,
    handleRoomHistoryClear,
    updateMessageInChannel,
    removeMessageFromChannel,
    rememberDirectMessageUser,
  });
}

function configureChatMessageActions() {
  chatRuntime?.actions?.configure({
    apiPost,
    getConfig: () => cfg,
    channelForApi,
    updateMessageInChannel,
    updateMessageInChannels,
    removeMessageFromChannel,
    showWarning,
  });
}

function configureChatUnread() {
  chatRuntime?.unread?.configure({
    getConfig: () => cfg,
    refreshUnreadBadges: updateTabBadges,
  });
}

function configureChatNavigation() {
  chatRuntime?.navigation?.configure({
    readComposerDraft() {
      const input = document.getElementById('chat-input');
      return { text: input?.value || '', start: input?.selectionStart || 0, end: input?.selectionEnd || 0,
        important: Boolean(document.getElementById('important-message-toggle')?.checked) };
    },
    restoreComposerDraft(draft) {
      const input = document.getElementById('chat-input');
      if (!input) return;
      input.value = draft?.text || '';
      input.setSelectionRange(draft?.start || 0, draft?.end || 0);
      const important = document.getElementById('important-message-toggle');
      if (important) important.checked = Boolean(draft?.important);
      const counter = document.getElementById('char-counter');
      if (counter) counter.textContent = `${input.value.length}/${input.maxLength > 0 ? input.maxLength : 1000}`;
    },
    clearUnread,
    stopTypingNow,
    stopGameTypingNow,
    clearReplyDraft,
    setGameLayerVisibility,
    renderMessagesForChat(chatKey) {
      chatMessageRenderer().renderActiveChat(chatKey);
    },
    updateComposerPlaceholder,
    renderReplyDraft,
    syncActiveTabs(chatKey) {
      chatRuntime?.notifications?.syncAttention();
      document.querySelectorAll('.chat-tab').forEach(tab => {
        tab.classList.toggle('active', tab.dataset.chatTab === chatKey);
      });
    },
    isLinkChatAvailable(chatKey) {
      return Boolean(chatPrivateChats().relationshipRequest(chatKey));
    },
    isGameChatAvailable(chatKey) {
      const activeGame = gameRuntime?.lifecycle?.getActiveGame();
      return Boolean(activeGame && chatKey === gameChatKey(activeGame.lobby_code));
    },
  });
}

function configureChatReply() {
  chatRuntime?.reply?.configure({
    channelForApi,
    messagePreviewText,
    participantDisplayName(participantId) {
      return participants.get(Number(participantId))?.display_name || null;
    },
    focusComposer() {
      document.getElementById('chat-input')?.focus();
    },
    onReplyDraftChange() {
      renderReplyDraft();
    },
  });
}

function configureChatTyping() {
  chatRuntime?.typing?.configure({
    apiPost,
    getConfig: () => cfg,
    getActiveChat: () => activeChatKey(),
    getParticipants: () => participants,
    activeRelationshipRequest,
    isUserBlocked,
    positionAvatar,
    syncTyping(participant, active) {
      return avatarRuntime?.renderer?.syncTyping(participant, active, {
        stage: participantStage(participant),
        document,
      });
    },
  });
}

function configureChatComposer() {
  chatRuntime?.composer?.configure({
    apiPost,
    getConfig: () => cfg,
    activeRelationshipRequest,
    activeDmUserId,
    requestKey: gestureRequestKey,
    addMessageToChannel,
    renderMessage,
    showDmFlight,
    stopTypingNow,
    alertError(error) {
      alert(error.message || error);
    },
  });
}

function messageProtectionBase64Url(bytes) {
  return btoa(String.fromCharCode(...new Uint8Array(bytes)))
    .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

function messageProtectionBase64UrlDecode(value) {
  const padded = String(value || '').replace(/-/g, '+').replace(/_/g, '/')
    + '='.repeat((4 - (String(value || '').length % 4)) % 4);
  const decoded = atob(padded);
  return Uint8Array.from(decoded, character => character.charCodeAt(0));
}

function messageProtectionCanonical(value) {
  if (Array.isArray(value)) return value.map(messageProtectionCanonical);
  if (!value || typeof value !== 'object') return value;
  return Object.fromEntries(Object.keys(value).sort().map(key => [key, messageProtectionCanonical(value[key])]));
}

function messageProtectionCanonicalJson(value) {
  return JSON.stringify(messageProtectionCanonical(value));
}

function messageProtectionConversation(chatKey = activeChatKey()) {
  if (chatKey.startsWith('dm:')) {
    const peer = Number(chatKey.slice(3));
    const ids = [Number(cfg?.myUserId || 0), peer].sort((left, right) => left - right);
    return { kind: 'dm', key: `dm:${ids[0]}:${ids[1]}` };
  }
  if (chatKey.startsWith('link:')) {
    const relationship = chatPrivateChats().relationshipRequest(chatKey);
    const key = String(relationship?.conversation_id || relationship?.conversation_public_id || '');
    return key ? { kind: 'link', key } : null;
  }
  if (chatKey === 'community') {
    return {
      kind: 'community',
      key: String(cfg?.messageProtection?.community?.conversationKey || 'community'),
    };
  }
  if (chatKey === 'room') {
    const key = String(cfg?.messageProtection?.room?.conversationKey || '');
    return key ? { kind: 'room', key } : null;
  }
  if (chatKey.startsWith('game:')) {
    const key = String(chatKey.slice(5));
    return key ? { kind: 'game', key } : null;
  }
  return null;
}

function messageProtectionPolicyFor(chatKey = activeChatKey()) {
  const conversation = messageProtectionConversation(chatKey);
  if (!conversation) return null;
  const projection = cfg?.messageProtection || {};
  if (conversation.kind === 'dm') {
    return (projection.dm || []).find(policy => policy.conversationKey === conversation.key) || {
      conversationKind: 'dm',
      conversationKey: conversation.key,
      mode: 'standard',
      protocolVersion: 1,
      keyEpoch: 1,
      revision: 0,
    };
  }
  if (conversation.kind === 'game') {
    return projection.game?.[conversation.key] || {
      conversationKind: 'game',
      conversationKey: conversation.key,
      mode: 'standard',
      protocolVersion: 1,
      keyEpoch: 1,
      revision: 0,
    };
  }
  const policy = projection[conversation.kind];
  return policy?.conversationKey === conversation.key ? policy : null;
}

function messageProtectionUpdatePolicy(policy) {
  if (!policy || !cfg?.messageProtection) return;
  if (policy.conversationKind === 'dm') {
    const policies = cfg.messageProtection.dm || [];
    const index = policies.findIndex(item => item.conversationKey === policy.conversationKey);
    if (index >= 0) policies[index] = policy;
    else policies.push(policy);
    cfg.messageProtection.dm = policies;
  } else if (policy.conversationKind === 'game') {
    cfg.messageProtection.game = cfg.messageProtection.game || {};
    cfg.messageProtection.game[policy.conversationKey] = policy;
  } else {
    cfg.messageProtection[policy.conversationKind] = policy;
  }
  updateComposerPlaceholder();
}

function messageProtectionDb() {
  return new Promise((resolve, reject) => {
    const open = indexedDB.open('corechat-message-protection-v1', 1);
    open.onupgradeneeded = () => {
      if (!open.result.objectStoreNames.contains('devices')) {
        open.result.createObjectStore('devices', { keyPath: 'deviceId' });
      }
    };
    open.onerror = () => reject(open.error);
    open.onsuccess = () => resolve(open.result);
  });
}

async function messageProtectionLocalDevices() {
  if (!globalThis.indexedDB || !globalThis.crypto?.subtle) return [];
  const database = await messageProtectionDb();
  const devices = await new Promise((resolve, reject) => {
    const transaction = database.transaction('devices', 'readonly');
    const request = transaction.objectStore('devices').getAll();
    request.onsuccess = () => resolve(request.result || []);
    request.onerror = () => reject(request.error);
  });
  database.close();
  return devices;
}

async function messageProtectionStoreDevice(device) {
  const database = await messageProtectionDb();
  await new Promise((resolve, reject) => {
    const transaction = database.transaction('devices', 'readwrite');
    transaction.objectStore('devices').put(device);
    transaction.oncomplete = resolve;
    transaction.onerror = () => reject(transaction.error);
  });
  database.close();
}

async function messageProtectionFetchContext(conversation, deviceId = '', fresh = false) {
  const cacheKey = `${conversation.kind}:${conversation.key}:${deviceId}`;
  if (!fresh && messageProtectionContextCache.has(cacheKey)) {
    return messageProtectionContextCache.get(cacheKey);
  }
  const query = new URLSearchParams({
    conversation_kind: conversation.kind,
    conversation_key: conversation.key,
  });
  if (deviceId) query.set('device_id', deviceId);
  const promise = runtimeRequestClient.getJson(`/api/message_protection.php?${query}`, {
    operation: 'message-protection-context',
    endpointCategory: 'message-protection',
    cache: 'no-store',
    shouldReportFailure: error => !messageProtectionRequiresAuthentication(error),
  });
  messageProtectionContextCache.set(cacheKey, promise);
  try {
    return await promise;
  } catch (error) {
    messageProtectionContextCache.delete(cacheKey);
    throw error;
  }
}

async function messageProtectionTrustedLocalDevice(accountProjection = null) {
  const account = accountProjection || (await messageProtectionFetchContext(
    messageProtectionConversation(activeChatKey()),
    '',
    true
  )).account;
  const trusted = new Set((account?.devices || [])
    .filter(device => device.status === 'trusted')
    .map(device => device.deviceId));
  return (await messageProtectionLocalDevices()).find(device => trusted.has(device.deviceId)) || null;
}

async function messageProtectionSha256(value) {
  return Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(value))))
    .map(byte => byte.toString(16).padStart(2, '0')).join('').toUpperCase();
}

async function messageProtectionVerifySignature(publicJwk, material, signature) {
  const key = await crypto.subtle.importKey(
    'jwk',
    publicJwk,
    { name: 'ECDSA', namedCurve: 'P-256' },
    false,
    ['verify']
  );
  return crypto.subtle.verify(
    { name: 'ECDSA', hash: 'SHA-256' },
    key,
    messageProtectionBase64UrlDecode(signature),
    new TextEncoder().encode(material)
  );
}

async function messageProtectionWrapKey(rawKey, conversation, epoch, sender, recipient) {
  const ephemeral = await crypto.subtle.generateKey(
    { name: 'ECDH', namedCurve: 'P-256' },
    true,
    ['deriveBits']
  );
  const recipientKey = await crypto.subtle.importKey(
    'jwk',
    recipient.encryptionPublicJwk,
    { name: 'ECDH', namedCurve: 'P-256' },
    false,
    []
  );
  const shared = await crypto.subtle.deriveBits(
    { name: 'ECDH', public: recipientKey },
    ephemeral.privateKey,
    256
  );
  const salt = crypto.getRandomValues(new Uint8Array(16));
  const nonce = crypto.getRandomValues(new Uint8Array(12));
  const baseKey = await crypto.subtle.importKey('raw', shared, 'HKDF', false, ['deriveKey']);
  const wrapKey = await crypto.subtle.deriveKey(
    {
      name: 'HKDF',
      hash: 'SHA-256',
      salt,
      info: new TextEncoder().encode(messageProtectionCanonicalJson({
        protocol: 'corechat-message-protection-v1',
        conversationKind: conversation.kind,
        conversationKey: conversation.key,
        keyEpoch: epoch,
        recipientDeviceId: recipient.deviceId,
      })),
    },
    baseKey,
    { name: 'AES-GCM', length: 256 },
    false,
    ['encrypt']
  );
  const sealed = new Uint8Array(await crypto.subtle.encrypt(
    { name: 'AES-GCM', iv: nonce, tagLength: 128 },
    wrapKey,
    rawKey
  ));
  const material = {
    conversationKind: conversation.kind,
    conversationKey: conversation.key,
    keyEpoch: epoch,
    recipientDeviceId: recipient.deviceId,
    senderDeviceId: sender.deviceId,
    ephemeralPublicJwk: await crypto.subtle.exportKey('jwk', ephemeral.publicKey),
    salt: messageProtectionBase64Url(salt),
    nonce: messageProtectionBase64Url(nonce),
    ciphertext: messageProtectionBase64Url(sealed.slice(0, -16)),
    tag: messageProtectionBase64Url(sealed.slice(-16)),
  };
  const signature = await crypto.subtle.sign(
    { name: 'ECDSA', hash: 'SHA-256' },
    sender.signingPrivateKey,
    new TextEncoder().encode(messageProtectionCanonicalJson(material))
  );
  return {
    ...material,
    signature: messageProtectionBase64Url(signature),
  };
}

async function messageProtectionUnwrapKey(item, context, conversation, localDevice) {
  const sender = (context.conversationDevices || [])
    .find(device => device.deviceId === item.senderDeviceId);
  if (!sender) throw new Error('The private-chat key sender is unavailable.');
  const envelope = item.envelope || {};
  const material = {
    conversationKind: conversation.kind,
    conversationKey: conversation.key,
    keyEpoch: Number(item.keyEpoch),
    recipientDeviceId: localDevice.deviceId,
    senderDeviceId: item.senderDeviceId,
    ephemeralPublicJwk: envelope.ephemeralPublicJwk,
    salt: envelope.salt,
    nonce: envelope.nonce,
    ciphertext: envelope.ciphertext,
    tag: envelope.tag,
  };
  if (!await messageProtectionVerifySignature(
    sender.signingPublicJwk,
    messageProtectionCanonicalJson(material),
    envelope.signature
  )) {
    throw new Error('The private-chat key envelope signature is invalid.');
  }
  const ephemeralKey = await crypto.subtle.importKey(
    'jwk',
    envelope.ephemeralPublicJwk,
    { name: 'ECDH', namedCurve: 'P-256' },
    false,
    []
  );
  const shared = await crypto.subtle.deriveBits(
    { name: 'ECDH', public: ephemeralKey },
    localDevice.encryptionPrivateKey,
    256
  );
  const baseKey = await crypto.subtle.importKey('raw', shared, 'HKDF', false, ['deriveKey']);
  const wrapKey = await crypto.subtle.deriveKey(
    {
      name: 'HKDF',
      hash: 'SHA-256',
      salt: messageProtectionBase64UrlDecode(envelope.salt),
      info: new TextEncoder().encode(messageProtectionCanonicalJson({
        protocol: 'corechat-message-protection-v1',
        conversationKind: conversation.kind,
        conversationKey: conversation.key,
        keyEpoch: Number(item.keyEpoch),
        recipientDeviceId: localDevice.deviceId,
      })),
    },
    baseKey,
    { name: 'AES-GCM', length: 256 },
    false,
    ['decrypt']
  );
  const sealed = new Uint8Array([
    ...messageProtectionBase64UrlDecode(envelope.ciphertext),
    ...messageProtectionBase64UrlDecode(envelope.tag),
  ]);
  const rawKey = await crypto.subtle.decrypt(
    {
      name: 'AES-GCM',
      iv: messageProtectionBase64UrlDecode(envelope.nonce),
      tagLength: 128,
    },
    wrapKey,
    sealed
  );
  return crypto.subtle.importKey('raw', rawKey, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt']);
}

async function messageProtectionContentKey(conversation, epoch) {
  const initial = await messageProtectionFetchContext(conversation, '', true);
  const localDevice = await messageProtectionTrustedLocalDevice(initial.account);
  if (!localDevice) {
    throw new Error('Register or recover a trusted Private Chat Protection device before using E2EE.');
  }
  const storageKey = `${conversation.kind}:${conversation.key}:${epoch}`;
  if (localDevice.conversationKeys?.[storageKey]) {
    return { key: localDevice.conversationKeys[storageKey], device: localDevice, context: initial };
  }
  let context = await messageProtectionFetchContext(conversation, localDevice.deviceId, true);
  const storedEnvelope = (context.keyEnvelopes || []).find(item => Number(item.keyEpoch) === Number(epoch));
  let contentKey;
  if (storedEnvelope) {
    contentKey = await messageProtectionUnwrapKey(storedEnvelope, context, conversation, localDevice);
  } else {
    const recipients = context.conversationDevices || [];
    const participantIds = new Set(recipients.map(device => Number(device.userId)));
    if (conversation.kind === 'dm' && participantIds.size < 2) {
      throw new Error('Every participant needs a trusted device before E2EE can start.');
    }
    if (!recipients.length) throw new Error('No trusted private-chat devices are available.');
    const rawKey = crypto.getRandomValues(new Uint8Array(32));
    for (const recipient of recipients) {
      const envelope = await messageProtectionWrapKey(rawKey, conversation, epoch, localDevice, recipient);
      await apiPost('/api/message_protection.php', {
        action: 'store_key_envelope',
        conversationKind: conversation.kind,
        conversationKey: conversation.key,
        keyEpoch: epoch,
        senderDeviceId: localDevice.deviceId,
        recipientDeviceId: recipient.deviceId,
        envelope: {
          ephemeralPublicJwk: envelope.ephemeralPublicJwk,
          salt: envelope.salt,
          nonce: envelope.nonce,
          ciphertext: envelope.ciphertext,
          tag: envelope.tag,
          signature: envelope.signature,
        },
      });
    }
    contentKey = await crypto.subtle.importKey(
      'raw',
      rawKey,
      { name: 'AES-GCM' },
      false,
      ['encrypt', 'decrypt']
    );
    messageProtectionContextCache.clear();
    context = await messageProtectionFetchContext(conversation, localDevice.deviceId, true);
  }
  localDevice.conversationKeys = { ...(localDevice.conversationKeys || {}), [storageKey]: contentKey };
  await messageProtectionStoreDevice(localDevice);
  return { key: contentKey, device: localDevice, context };
}

async function messageProtectionDecryptMessage(message, chatKey) {
  const conversation = messageProtectionConversation(chatKey);
  const envelope = message?.protection_envelope;
  if (!conversation || !envelope) throw new Error('The protected message envelope is unavailable.');
  const { key, context } = await messageProtectionContentKey(conversation, Number(envelope.keyEpoch));
  const sender = (context.conversationDevices || [])
    .find(device => device.deviceId === envelope.senderDeviceId);
  if (!sender) throw new Error('The message sender device is no longer trusted.');
  const aad = {
    protocol: 'corechat-message-protection-v1',
    mode: 'e2ee-private',
    conversation: conversation.key,
    clientMessageId: envelope.clientMessageId,
    senderUserId: Number(envelope.senderUserId),
    senderDeviceId: envelope.senderDeviceId,
    keyEpoch: Number(envelope.keyEpoch),
    sequence: Number(envelope.sequence),
    messageType: String(message.message_type || 'text'),
  };
  const aadJson = messageProtectionCanonicalJson(aad);
  if (await messageProtectionSha256(aadJson) !== String(envelope.aadSha256 || '').toUpperCase()) {
    throw new Error('The encrypted message metadata failed integrity validation.');
  }
  const signatureMaterial = `${aadJson}\n${envelope.nonce}.${envelope.ciphertext}.${envelope.tag}`;
  if (!await messageProtectionVerifySignature(sender.signingPublicJwk, signatureMaterial, envelope.signature)) {
    throw new Error('The encrypted message signature is invalid.');
  }
  const sealed = new Uint8Array([
    ...messageProtectionBase64UrlDecode(envelope.ciphertext),
    ...messageProtectionBase64UrlDecode(envelope.tag),
  ]);
  const plaintext = await crypto.subtle.decrypt(
    {
      name: 'AES-GCM',
      iv: messageProtectionBase64UrlDecode(envelope.nonce),
      additionalData: new TextEncoder().encode(aadJson),
      tagLength: 128,
    },
    key,
    sealed
  );
  const packageData = JSON.parse(new TextDecoder().decode(plaintext));
  return {
    ...message,
    content: String(packageData.content || ''),
    original_content: packageData.originalContent || null,
    url_preview: packageData.urlPreview || null,
    reply_to: packageData.replyTo || null,
    _messageProtectionResolved: true,
  };
}

async function sendProtectedTextMessage(content, chatKey) {
  const conversation = messageProtectionConversation(chatKey);
  if (!conversation || !['dm', 'link'].includes(conversation.kind)) {
    throw new Error('E2EE is available only in direct and active relationship chats.');
  }
  const latest = await messageProtectionFetchContext(conversation, '', true);
  const policy = latest.conversation?.policy;
  if (policy?.mode !== 'e2ee-private') {
    messageProtectionUpdatePolicy(policy);
    return chatComposer().sendTextMessage(content, chatKey);
  }
  const { key, device } = await messageProtectionContentKey(conversation, Number(policy.keyEpoch));
  const clientMessageId = crypto.randomUUID();
  const sequence = (Date.now() * 1000) + crypto.getRandomValues(new Uint16Array(1))[0];
  const reply = chatReply().draftForChat(chatKey);
  const packageData = {
    content,
    originalContent: null,
    urlPreview: null,
    replyTo: reply || null,
  };
  const aad = {
    protocol: 'corechat-message-protection-v1',
    mode: 'e2ee-private',
    conversation: conversation.key,
    clientMessageId,
    senderUserId: Number(cfg.myUserId),
    senderDeviceId: device.deviceId,
    keyEpoch: Number(policy.keyEpoch),
    sequence,
    messageType: 'text',
  };
  const aadJson = messageProtectionCanonicalJson(aad);
  const nonce = crypto.getRandomValues(new Uint8Array(12));
  const sealed = new Uint8Array(await crypto.subtle.encrypt(
    {
      name: 'AES-GCM',
      iv: nonce,
      additionalData: new TextEncoder().encode(aadJson),
      tagLength: 128,
    },
    key,
    new TextEncoder().encode(messageProtectionCanonicalJson(packageData))
  ));
  const envelope = {
    ...aad,
    nonce: messageProtectionBase64Url(nonce),
    ciphertext: messageProtectionBase64Url(sealed.slice(0, -16)),
    tag: messageProtectionBase64Url(sealed.slice(-16)),
    aadSha256: await messageProtectionSha256(aadJson),
  };
  envelope.signature = messageProtectionBase64Url(await crypto.subtle.sign(
    { name: 'ECDSA', hash: 'SHA-256' },
    device.signingPrivateKey,
    new TextEncoder().encode(`${aadJson}\n${envelope.nonce}.${envelope.ciphertext}.${envelope.tag}`)
  ));
  let payload = chatReply().appendReplyPayload({
    session_id: cfg.sessionId,
    join_token: cfg.myJoinToken,
    channel: chatKey,
    content: '',
    client_message_id: clientMessageId,
    protection_envelope: envelope,
  }, chatKey);
  const relationship = activeRelationshipRequest();
  const dmUserId = activeDmUserId();
  if (relationship) {
    payload = {
      ...payload,
      channel: 'link',
      relationship_id: relationship.relationship_id,
      conversation_id: relationship.conversation_id,
    };
  } else if (dmUserId) {
    payload = { ...payload, channel: 'dm', target_user_id: dmUserId };
  }
  stopTypingNow();
  let message;
  if (conversation.kind === 'game') {
    message = await apiPost('/api/game_chat.php', {
      ...payload,
      action: 'message',
      channel: 'game',
      lobby_code: conversation.key,
    });
    chatGameChat().addMessage(message, false);
  } else {
    message = await apiPost('/api/messages.php', payload);
  }
  chatReply().clearDraft();
  if (message.channel === 'link') {
    addMessageToChannel(message, relationship?.chatKey || chatKey, false);
  } else if (message.channel === 'dm') {
    addMessageToChannel(message, `dm:${dmUserId}`, false);
    showDmFlight(message);
  }
  return message;
}

const MESSAGE_PROTECTION_MODE_LABELS = Object.freeze({
  standard: 'Standard',
  'server-encrypted': 'Encrypted on this server',
  'e2ee-private': 'End-to-end encrypted',
});
const messageProtectionDialogState = {
  busy: false,
  chatKey: null,
  conversation: null,
  policy: null,
  returnFocus: null,
};

function messageProtectionModeLabel(mode) {
  return MESSAGE_PROTECTION_MODE_LABELS[String(mode || '')] || 'Unknown';
}

function messageProtectionDialogElements() {
  const modal = document.getElementById('message-protection-dialog');
  return {
    modal,
    form: document.getElementById('message-protection-form'),
    choices: document.getElementById('message-protection-choices'),
    availability: document.getElementById('message-protection-availability'),
    note: document.getElementById('message-protection-note'),
    impact: document.getElementById('message-protection-impact'),
    confirmation: document.getElementById('message-protection-e2ee-confirmation'),
    confirmationInput: document.getElementById('message-protection-e2ee-confirm'),
    status: document.getElementById('message-protection-status'),
    cancel: document.getElementById('message-protection-cancel'),
    submit: document.getElementById('message-protection-submit'),
  };
}

function messageProtectionSetDialogStatus(message = '', state = '') {
  const status = messageProtectionDialogElements().status;
  if (!status) return;
  status.textContent = message;
  status.className = `admin-form-status${state ? ` ${state}` : ''}`;
}

function messageProtectionSelectedMode() {
  return document.querySelector('input[name="message_protection_mode"]:checked')?.value || '';
}

function syncMessageProtectionDialog() {
  const elements = messageProtectionDialogElements();
  if (!elements.form || !messageProtectionDialogState.policy) return;
  const requested = messageProtectionSelectedMode();
  const e2eeSelected = requested === 'e2ee-private';
  elements.confirmation.hidden = !e2eeSelected;
  elements.confirmationInput.required = e2eeSelected;
  if (!e2eeSelected) elements.confirmationInput.checked = false;

  const current = String(messageProtectionDialogState.policy.mode || 'standard');
  if (requested === 'e2ee-private') {
    elements.impact.textContent = 'End-to-end encryption applies to new messages. Earlier messages keep their existing protection.';
  } else if (current === 'e2ee-private') {
    elements.impact.textContent = 'New messages will use the selected server-readable protection. Earlier end-to-end encrypted messages remain unchanged.';
  } else if (current === 'server-encrypted' && requested === 'standard') {
    elements.impact.textContent = 'Eligible earlier messages are changed only through the existing verified conversion. Their protection remains truthful throughout.';
  } else {
    elements.impact.textContent = 'Earlier messages keep their existing protection unless the existing verified conversion applies.';
  }

  const valid = requested !== ''
    && requested !== current
    && elements.note.value.trim() !== ''
    && (!e2eeSelected || elements.confirmationInput.checked);
  elements.submit.disabled = messageProtectionDialogState.busy || !valid;
  elements.cancel.disabled = messageProtectionDialogState.busy;
  elements.choices.disabled = messageProtectionDialogState.busy;
  elements.note.disabled = messageProtectionDialogState.busy;
}

function closeMessageProtectionDialog({ restoreFocus = true } = {}) {
  if (messageProtectionDialogState.busy) return;
  const elements = messageProtectionDialogElements();
  elements.modal?.classList.remove('open');
  elements.modal?.setAttribute('aria-hidden', 'true');
  elements.form?.reset();
  messageProtectionSetDialogStatus();
  const returnFocus = messageProtectionDialogState.returnFocus;
  messageProtectionDialogState.chatKey = null;
  messageProtectionDialogState.conversation = null;
  messageProtectionDialogState.policy = null;
  messageProtectionDialogState.returnFocus = null;
  if (restoreFocus && returnFocus?.isConnected) returnFocus.focus({ preventScroll: true });
}

let messageProtectionAuthenticationReturnFocus = null;

function messageProtectionRequiresAuthentication(error) {
  return error?.code === 'AUTH_REDIRECT'
    || [401, 403].includes(Number(error?.details?.status || 0))
    || Boolean(error?.reauthenticationRequired);
}

function openMessageProtectionAuthenticationDialog(returnFocus = document.activeElement) {
  const modal = document.getElementById('message-protection-auth-dialog');
  if (!modal) return;
  messageProtectionAuthenticationReturnFocus = returnFocus?.isConnected ? returnFocus : null;
  modal.classList.add('open');
  modal.setAttribute('aria-hidden', 'false');
  document.getElementById('message-protection-auth-open')?.focus({ preventScroll: true });
}

function closeMessageProtectionAuthenticationDialog() {
  const modal = document.getElementById('message-protection-auth-dialog');
  modal?.classList.remove('open');
  modal?.setAttribute('aria-hidden', 'true');
  const returnFocus = messageProtectionAuthenticationReturnFocus;
  messageProtectionAuthenticationReturnFocus = null;
  if (returnFocus?.isConnected) returnFocus.focus({ preventScroll: true });
}

async function changeMessageProtectionMode() {
  const chatKey = activeChatKey();
  const conversation = messageProtectionConversation(chatKey);
  if (!conversation) return;
  const current = await messageProtectionFetchContext(conversation, '', true);
  const policy = current.conversation?.policy;
  if (!policy) throw new Error('Message protection is unavailable for this conversation.');

  const elements = messageProtectionDialogElements();
  messageProtectionDialogState.busy = false;
  messageProtectionDialogState.chatKey = chatKey;
  messageProtectionDialogState.conversation = conversation;
  messageProtectionDialogState.policy = policy;
  messageProtectionDialogState.returnFocus = document.activeElement;
  elements.form.reset();
  elements.note.value = '';
  elements.availability.hidden = true;
  elements.availability.textContent = '';
  elements.form.querySelectorAll('input[name="message_protection_mode"]').forEach(input => {
    input.disabled = false;
    input.checked = input.value === policy.mode;
  });
  const e2eeChoice = elements.form.querySelector('input[name="message_protection_mode"][value="e2ee-private"]');
  if (!['dm', 'link', 'game'].includes(conversation.kind)) {
    e2eeChoice.disabled = true;
    elements.availability.hidden = false;
    elements.availability.textContent = 'End-to-end encryption is available only for direct and private relationship conversations.';
  }
  messageProtectionSetDialogStatus();
  elements.modal.classList.add('open');
  elements.modal.setAttribute('aria-hidden', 'false');
  syncMessageProtectionDialog();
  const selected = elements.form.querySelector('input[name="message_protection_mode"]:checked');
  (selected || elements.form.querySelector('input[name="message_protection_mode"]:not(:disabled)'))?.focus({ preventScroll: true });
}

async function submitMessageProtectionChange(event) {
  event.preventDefault();
  if (messageProtectionDialogState.busy) return;
  const elements = messageProtectionDialogElements();
  const conversation = messageProtectionDialogState.conversation;
  const policy = messageProtectionDialogState.policy;
  const requested = messageProtectionSelectedMode();
  const explanation = elements.note.value.trim();
  if (!conversation || !policy || requested === '' || requested === policy.mode || explanation === '') {
    messageProtectionSetDialogStatus('Choose a different protection option and enter a private note.', 'error');
    elements.status.focus({ preventScroll: true });
    return;
  }
  if (requested === 'e2ee-private' && !elements.confirmationInput.checked) {
    messageProtectionSetDialogStatus('Confirm the End-to-End Encryption statement to continue.', 'error');
    elements.confirmationInput.focus({ preventScroll: true });
    return;
  }

  messageProtectionDialogState.busy = true;
  messageProtectionSetDialogStatus('Changing message protection…', 'working');
  syncMessageProtectionDialog();
  try {
    let result = await apiPost('/api/message_protection.php', {
      action: 'request_transition',
      requestId: crypto.randomUUID(),
      conversationKind: conversation.kind,
      conversationKey: conversation.key,
      toMode: requested,
      explanation,
      confirmed: true,
      expectedRevision: Number(policy.revision),
    });
    let transition = result.transition;
    while (['preparing', 'migrating', 'validating', 'interrupted'].includes(transition?.status)) {
      result = await apiPost('/api/message_protection.php', {
        action: 'continue_transition',
        requestId: transition.requestId,
        batchSize: 100,
      });
      transition = result.conversation?.transition;
    }
    const refreshed = await messageProtectionFetchContext(conversation, '', true);
    messageProtectionUpdatePolicy(refreshed.conversation?.policy);
    messageProtectionDialogState.busy = false;
    closeMessageProtectionDialog({ restoreFocus: true });
  } catch (error) {
    messageProtectionDialogState.busy = false;
    if (messageProtectionRequiresAuthentication(error)) {
      const returnFocus = messageProtectionDialogState.returnFocus;
      closeMessageProtectionDialog({ restoreFocus: false });
      openMessageProtectionAuthenticationDialog(returnFocus);
      return;
    }
    messageProtectionSetDialogStatus(error?.message || 'Message protection could not change.', 'error');
    syncMessageProtectionDialog();
    elements.status.focus({ preventScroll: true });
  }
}

function messageProtectionChatKeyForConversation(kind, key) {
  if (kind === 'room' || kind === 'community') return kind;
  if (kind === 'dm') {
    const users = String(key || '').split(':').slice(1).map(Number);
    const peer = users.find(userId => userId > 0 && userId !== Number(cfg?.myUserId || 0));
    return peer ? `dm:${peer}` : null;
  }
  if (kind === 'link') {
    const tabs = [...document.querySelectorAll('[data-chat-tab^="link:"]')];
    const tab = tabs.find(candidate => messageProtectionConversation(candidate.dataset.chatTab)?.key === key);
    return tab?.dataset.chatTab || null;
  }
  if (kind === 'game') return `game:${key}`;
  return null;
}

function handleMessageProtectionChangeEvent(event) {
  if (event?.type !== 'message_protection_change') return false;
  const payload = event.payload || {};
  const kind = String(payload.conversationKind || '');
  const key = String(payload.conversationKey || '');
  const mode = String(payload.mode || '');
  if (!kind || !key || !MESSAGE_PROTECTION_MODE_LABELS[mode]) return true;
  const prefix = `${kind}:${key}:`;
  [...messageProtectionContextCache.keys()].forEach(cacheKey => {
    if (cacheKey.startsWith(prefix)) messageProtectionContextCache.delete(cacheKey);
  });
  const chatKey = messageProtectionChatKeyForConversation(kind, key);
  if (chatKey) {
    addMessageToChannel({
      id: `message-protection-change-${kind}-${event.id || Date.now()}`,
      system: true,
      message_type: 'system',
      content: `Message protection changed to ${messageProtectionModeLabel(mode)}.`,
      sent_at: payload.changedAt || new Date().toISOString(),
    }, chatKey, true);
  }
  messageProtectionFetchContext({ kind, key }, '', true)
    .then(context => messageProtectionUpdatePolicy(context.conversation?.policy))
    .catch(warnRuntimeRequest);
  return true;
}

function syncMessageProtectionControl() {
  const composer = document.getElementById('composer');
  if (!composer) return;
  let button = document.getElementById('message-protection-mode-button');
  if (!button) {
    button = document.createElement('button');
    button.id = 'message-protection-mode-button';
    button.className = 'btn';
    button.type = 'button';
    button.addEventListener('click', () => {
      changeMessageProtectionMode().catch(error => {
        if (messageProtectionRequiresAuthentication(error)) {
          openMessageProtectionAuthenticationDialog(button);
          return;
        }
        showWarning(error.message || 'Message protection could not change.');
      });
    });
    const submit = composer.querySelector('[type="submit"]');
    const actions = submit?.parentElement;
    if (submit && actions && composer.contains(actions)) {
      actions.insertBefore(button, submit);
    } else {
      composer.appendChild(button);
    }
  }
  const policy = messageProtectionPolicyFor(activeChatKey());
  button.hidden = !policy;
  button.textContent = policy ? `Protection: ${messageProtectionModeLabel(policy.mode)}` : 'Protection';
  button.setAttribute('aria-label', policy
    ? `Change message protection. Current mode ${messageProtectionModeLabel(policy.mode)}.`
    : 'Message protection unavailable.');
}

const messageProtectionDialog = messageProtectionDialogElements();
messageProtectionDialog.form?.addEventListener('submit', submitMessageProtectionChange);
messageProtectionDialog.cancel?.addEventListener('click', () => closeMessageProtectionDialog());
document.getElementById('message-protection-auth-close')?.addEventListener('click', closeMessageProtectionAuthenticationDialog);
document.getElementById('message-protection-auth-dialog')?.addEventListener('keydown', event => {
  if (event.key !== 'Escape') return;
  event.preventDefault();
  closeMessageProtectionAuthenticationDialog();
});
messageProtectionDialog.form?.addEventListener('change', syncMessageProtectionDialog);
messageProtectionDialog.note?.addEventListener('input', () => {
  if (!messageProtectionDialogState.busy) messageProtectionSetDialogStatus();
  syncMessageProtectionDialog();
});
messageProtectionDialog.modal?.addEventListener('keydown', event => {
  if (event.key === 'Escape') {
    event.preventDefault();
    if (!messageProtectionDialogState.busy) closeMessageProtectionDialog();
    return;
  }
  if (event.key !== 'Tab') return;
  const focusable = [...messageProtectionDialog.modal.querySelectorAll(
    'button:not([disabled]), input:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
  )].filter(element => !element.hidden && !element.closest('[hidden]') && element.getClientRects().length > 0);
  if (!focusable.length) return;
  const first = focusable[0];
  const last = focusable[focusable.length - 1];
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
});

function configureChatMediaSend() {
  chatRuntime?.mediaSend?.configure({
    apiPost,
    apiUpload,
    getConfig: () => cfg,
    getActiveChat: () => activeChatKey(),
    channelForApi,
    activeRelationshipRequest,
    activeDmUserId,
    addMessageToChannel,
    renderMessage,
    showDmFlight,
    gameChatKey,
    switchChat,
    createFormData() {
      return new FormData();
    },
    alertError(error) {
      alert(error.message || error);
    },
  });
}

function configureChatGameChat() {
  chatRuntime?.gameChat?.configure({
    apiPost,
    getConfig: () => cfg,
    getActiveGame: () => gameRuntime?.lifecycle?.getActiveGame(),
    addMessageToChannel,
    renderGameStagePlayers: updateGameStagePlayers,
    fetchGameChat(query, options = {}) {
      const lobbyCode = String(query?.get?.('lobby_code') || '');
      const isTerminalNotFound = error => (
        error?.code === 'HTTP_ERROR'
        && Number(error?.details?.status || 0) === 404
        && error?.responsePayload?.error === 'Game not found'
      );
      return runtimeRequestClient.getJson('/api/game_chat.php?' + query, {
        operation: 'poll-game-chat',
        endpointCategory: 'game-chat',
        signal: gameChatRequestAbortController.signal,
        requestContext: () => {
          const currentLobbyCode = String(gameRuntime?.lifecycle?.getActiveGame?.()?.lobby_code || '');
          const ownerCurrentAtOutcome = Boolean(lobbyCode && currentLobbyCode === lobbyCode);
          return {
            gameType: 'game-chat',
            requestPurpose: 'game-session-read',
            ownerCurrentAtOutcome,
            sameGameAtOutcome: ownerCurrentAtOutcome,
          };
        },
        shouldReportFailure(error) {
          if (isTerminalNotFound(error)) return false;
          return options.shouldReportFailure?.(error) !== false;
        },
      });
    },
    onGameChatClosed(lobbyCode, isCurrent) {
      if (!isCurrent() || gameRuntime?.lifecycle?.getActiveGame()?.lobby_code !== lobbyCode) return;
      return gameRuntime?.lifecycle?.refreshFromRoomEvent();
    },
    reportGameChatFailure(error) {
      return runtimeIssueCaptureService?.captureRequestFailure(error);
    },
    warnError(error) {
      warnRuntimeRequest(error);
    },
  });
}

function configureRoomEventRouter() {
  roomRuntime?.events?.configure({
    onParticipantNameplate(payload) {
      const person = participants.get(Number(payload.participant_id));
      if (!person) return;
      const p2pProjection = Number(payload.participant_id) !== Number(cfg.myParticipantId)
        && String(payload.nameplate_delivery || '') === 'p2p-prefetch';
      participants.update(Number(payload.participant_id), {
        nameplate_path: p2pProjection ? null : (payload.nameplate_path ?? null),
        nameplate_url: p2pProjection ? null : (payload.nameplate_url ?? null),
        p2p_nameplate: p2pProjection ? (payload.p2p_nameplate || null) : null,
        nameplate_delivery: payload.nameplate_delivery || 'server-stored',
        p2p_nameplate_object_identity: null,
      });
      avatarRuntime?.p2pAvatar?.reconcileParticipant(person);
      renderParticipant(person, { animateJoin: false });
      renderActiveChat();
    },
    onParticipantJoin(payload) {
      const alreadyKnown = participants.has(payload.id);
      const hadStageAvatar = Boolean(participants.get(payload.id)?.avatarEl);
      renderParticipantWhenReady(Object.assign({ online: true }, payload), { animateJoin: !hadStageAvatar }).catch(() => {
        renderParticipant(Object.assign({ online: true }, payload), { animateJoin: !hadStageAvatar });
      });
      if (!alreadyKnown && payload.id !== cfg.myParticipantId) addSystemMessage(`${payload.display_name} joined the room.`);
    },
    onParticipantIdentity(payload) {
      const participant = participants.get(Number(payload.participant_id || payload.id));
      if (!participant || Number(participant.user_id) !== Number(payload.user_id)) return;
      participant.display_name = String(payload.display_name || participant.display_name || 'Member');
      renderParticipant(participant, { animateJoin: false });
      renderPeople();
      renderLinkTabs();
      renderActiveChat();
      if (ctxMenu?.classList.contains('visible')
          && Number(ctxMenuParticipantId) === Number(participant.id)) {
        syncParticipantIdentityHeader(participant);
        syncParticipantActionMenu(
          participant,
          Number(participant.id) === Number(cfg.myParticipantId)
        );
      }
    },
    onParticipantLeave(payload) {
      const leavingId = payload.participant_id || payload.id;
      const person = participants.get(Number(leavingId));
      if (person && person.id !== cfg.myParticipantId) addSystemMessage(`${person.display_name} left the room.`);
      removeParticipant(leavingId);
      gameRuntime?.lifecycle?.refreshFromRoomEvent().catch(warnRuntimeRequest);
    },
    onParticipantPosition(payload) {
      const person = participants.get(payload.participant_id);
      if (!person) return;

      avatarRuntime?.layout?.applyParticipantPosition(person, {
        x: payload.position_x,
        y: payload.position_y,
      });

      if (avatarRuntime?.coordinator?.refreshRelationshipsForParticipant(person, {
        animate: false,
        persist: false,
        reason: 'remote-position',
      })) return;

      positionAvatar(person);
    },
    onRelationshipPosition(payload, event) {
      avatarRuntime?.coordinator?.reconcileRemoteRelationshipPosition(payload, event);
    },
    onParticipantWebcam(payload) {
      applyWebcamState(payload.participant_id, Boolean(payload.webcam_enabled || payload.webcam_path), payload.webcam_path || null, 'room-event-webcam');
    },
    onWebcamCapability(payload) {
      cfg.webcamCapability = payload;
      voiceRuntime?.viewerPolicy?.applyCapability(payload, 'installation-webcam-capability');
      syncChatOptions();
      if (!payload.allowWebcamUse && (webcamIntent || webcamStream)) {
        disableLocalWebcam('installation-capability-disabled').catch(warnRuntimeRequest);
      } else if (!payload.allowWebcamUse) {
        voiceRuntime?.media?.reconcileWebcamCapability(
          false,
          'installation-webcam-capability-disabled',
        ).catch(warnRuntimeRequest);
      }
    },
    onGestureCapability(payload) {
      applyGestureCapabilityProjection(payload, 'room-event');
    },
    onParticipantAvatar(payload, event = {}) {
      const person = participants.get(payload.participant_id);
      if (!person) return;
      const eventId = Number(event.id || event.event_id || 0);
      if (eventId > 0 && eventId <= Number(person.avatar_event_id || 0)) {
        recordRuntimeDiagnostic('avatarOrientation', 'avatar-event-stale', {
          participantId: Number(person.id),
          eventId,
          lastEventId: Number(person.avatar_event_id || 0),
        });
        return;
      }
      const localCaptureActive = Number(payload.participant_id) === Number(cfg.myParticipantId)
        && Boolean(webcamStream?.getVideoTracks?.().some(track => track.readyState === 'live'));
      const nextWebcamEnabled = Boolean(payload.webcam_enabled || payload.webcam_path || localCaptureActive);
      recordVoiceLifecycleDiagnostic({
        event: 'webcam-state-change',
        source: 'room-event-avatar',
        participantId: Number(payload.participant_id),
        previous: {
          webcam_enabled: Boolean(person.webcam_enabled),
          webcam_path: person.webcam_path || null,
        },
        next: {
          webcam_enabled: nextWebcamEnabled,
          webcam_path: payload.webcam_path || null,
        },
        willDetach: !nextWebcamEnabled,
        localCaptureAuthoritative: localCaptureActive,
      });

      const p2pProjection = Number(payload.participant_id) !== Number(cfg.myParticipantId)
        && ['p2p-prefetch', 'built-in-generated-fallback'].includes(String(payload.avatar_delivery || ''));
      const nextAvatarPath = p2pProjection ? null : (payload.avatar_path ?? person.avatar_path);
      const nextAvatarUrl = p2pProjection ? null : (payload.avatar_url ?? person.avatar_url);
      const avatarSourceChanged = nextAvatarPath !== person.avatar_path
        || nextAvatarUrl !== person.avatar_url
        || (p2pProjection
          && String(payload?.p2p_avatar?.identity || '') !== String(person?.p2p_avatar?.identity || ''));
      const previousDimensions = avatarRenderedDimensions(person);
      const currentSizeVersion = Number(person.avatar_size_version || 1);
      const incomingSizeVersion = Number(payload.avatar_size_version || currentSizeVersion);
      const staleSizeProjection = incomingSizeVersion < currentSizeVersion;
      const nextSizeProjection = staleSizeProjection ? {} : {
        avatar_display_width_px: payload.avatar_display_width_px === undefined ? person.avatar_display_width_px : payload.avatar_display_width_px,
        avatar_display_height_px: payload.avatar_display_height_px === undefined ? person.avatar_display_height_px : payload.avatar_display_height_px,
        avatar_source_width_px: payload.avatar_source_width_px === undefined
          ? person.avatar_source_width_px
          : payload.avatar_source_width_px,
        avatar_source_height_px: payload.avatar_source_height_px === undefined
          ? person.avatar_source_height_px
          : payload.avatar_source_height_px,
        avatar_display_size_px: payload.avatar_display_size_px === undefined
          ? person.avatar_display_size_px
          : payload.avatar_display_size_px,
        webcam_display_width_px: payload.webcam_display_width_px === undefined
          ? person.webcam_display_width_px
          : payload.webcam_display_width_px,
        webcam_display_height_px: payload.webcam_display_height_px === undefined
          ? person.webcam_display_height_px
          : payload.webcam_display_height_px,
        avatar_size_version: incomingSizeVersion,
      };
      const sizeProjectionChanged = !staleSizeProjection && (
        Number(nextSizeProjection.avatar_size_version || 1) !== currentSizeVersion
        || nextSizeProjection.avatar_display_size_px !== person.avatar_display_size_px
        || nextSizeProjection.avatar_display_width_px !== person.avatar_display_width_px
        || nextSizeProjection.avatar_display_height_px !== person.avatar_display_height_px
        || nextSizeProjection.webcam_display_width_px !== person.webcam_display_width_px
        || nextSizeProjection.webcam_display_height_px !== person.webcam_display_height_px
      );
      const currentOrientationVersion = Math.max(1, Number(person.avatar_orientation_version || 1));
      const incomingOrientationVersion = Math.max(1, Number(
        payload.avatar_orientation_version || currentOrientationVersion
      ));
      const staleOrientationProjection = incomingOrientationVersion < currentOrientationVersion;

      participants.update(payload.participant_id, {
        avatar_path: nextAvatarPath,
        avatar_url: nextAvatarUrl,
        ...(p2pProjection ? {
          p2p_avatar: payload.p2p_avatar || null,
          avatar_delivery: payload.avatar_delivery,
          p2p_avatar_object_identity: null,
        } : {}),
        avatar_orientation: staleOrientationProjection || payload.avatar_orientation === undefined
          ? normalizeAvatarOrientation(person.avatar_orientation)
          : normalizeAvatarOrientation(payload.avatar_orientation),
        avatar_orientation_version: staleOrientationProjection
          ? currentOrientationVersion
          : incomingOrientationVersion,
        avatar_event_id: eventId || Number(person.avatar_event_id || 0),
        avatar_version: avatarSourceChanged ? Date.now() : person.avatar_version,
        webcam_path: payload.webcam_path || null,
        webcam_enabled: nextWebcamEnabled,
        ...nextSizeProjection,
      });
      if (!nextWebcamEnabled) detachParticipantVideo(person.id, true, 'participant-avatar-event');
      renderParticipant(person);
      if (sizeProjectionChanged) {
        const nextDimensions = avatarRenderedDimensions(person);
        avatarRuntime?.coordinator?.scheduleRelationshipRefresh({
          participant: person,
          reason: 'avatar-display-size-change',
        });
        recordRuntimeDiagnostic('avatarDisplayPolicy', 'participant-display-size-reconciled', {
          participantId: Number(person.id),
          displayPreferenceVersion: Number(person.avatar_size_version || 1),
          previousDimensions,
          nextDimensions,
        });
      }
      recordRuntimeDiagnostic('avatarOrientation', 'avatar-event-reconciled', {
        participantId: Number(person.id),
        eventId: eventId || null,
        orientation: normalizeAvatarOrientation(person.avatar_orientation),
        orientationVersion: Number(person.avatar_orientation_version || 1),
        staleProjection: staleOrientationProjection,
      });
    },
    onAvatarSizePolicy(payload, event = {}) {
      const changed = avatarRuntime?.displayPolicy?.configure(payload) || false;
      if (!changed) return;
      cfg.avatarSizePolicy = avatarRuntime.displayPolicy.policy();
      window.ChatSpaceAvatar?.configure?.(cfg.avatarSizePolicy);
      participants.forEach(renderParticipant);
      avatarRuntime?.coordinator?.scheduleRelationshipRefresh({
        all: true,
        reason: 'installation-avatar-size-policy-change',
      });
      recordRuntimeDiagnostic('avatarDisplayPolicy', 'installation-policy-reconciled', {
        eventId: Number(event.id || 0) || null,
        revision: Number(cfg.avatarSizePolicy.revision || 1),
      });
    },
    onDanceCapability(payload, event = {}) {
      const changed = avatarRuntime?.dances?.configureCapabilityPolicy?.(payload, {
        reason: 'installation-dance-capability-change',
      }) || false;
      if (!changed) return;
      cfg.danceCapability = avatarRuntime.dances.capabilityPolicy;
      avatarRuntime?.relationshipManagement?.refresh?.({ render: true }).catch(warnRuntimeRequest);
      recordRuntimeDiagnostic('avatarDance', 'installation-capability-reconciled', {
        eventId: Number(event.id || 0) || null,
        revision: Number(cfg.danceCapability.revision || 1),
        enabledCount: Number(cfg.danceCapability.enabledCount || 0),
        totalCount: Number(cfg.danceCapability.totalCount || 0),
      });
    },
    onParticipantAura(payload) {
      participants.forEach(person => {
        if (Number(person.user_id) !== Number(payload.user_id) && Number(person.id) !== Number(payload.participant_id)) return;
        person.aura_effect = payload.aura_effect || null;
        applyParticipantAura(person);
      });
    },
    onUserRoleUpdate(payload) {
      applyUserRoleUpdate(payload);
    },
    onRoleColorsUpdate(payload) {
      document.body.dataset.roleColorsMode = payload?.mode || 'enabled';
      for (const [role, colors] of Object.entries(payload?.palette || {})) {
      if (!['admin', 'developer', 'guide', 'moderator', 'owner', 'user'].includes(role)) continue;
        if (/^#[0-9a-f]{6}$/i.test(colors?.background || '')) document.body.style.setProperty(`--role-${role}-bg`, colors.background);
        if (/^#[0-9a-f]{6}$/i.test(colors?.text || '')) document.body.style.setProperty(`--role-${role}-text`, colors.text);
      }
    },
    onTyping(payload) {
      showTyping(payload.participant_id, payload.active);
    },
    onPresenceLeave(payload) {
      const person = participants.get(payload.participant_id);
      if (!person) return;
      recordVoiceLifecycleDiagnostic({
        event: 'webcam-state-change',
        source: 'presence-leave',
        participantId: Number(payload.participant_id),
        previous: {
          webcam_enabled: Boolean(person.webcam_enabled),
          webcam_path: person.webcam_path || null,
        },
        next: {
          webcam_enabled: Boolean(person.webcam_enabled),
          webcam_path: null,
        },
      });

      participants.update(payload.participant_id, {
        online: false,
        webcam_path: null,
      });
      // A presence timeout means Away, not that the participant left the
      // room. Keep the avatar and relationship placement visible so an idle
      // member is not visually ejected from the room stage.
      renderParticipant(person, { animateJoin: false });
      gameRuntime?.lifecycle?.refreshFromRoomEvent().catch(warnRuntimeRequest);
    },
    onRemoteLink(payload) {
      avatarRuntime?.coordinator?.reconcileRemoteLink(payload);
    },
    onRemoteRelationship(payload) {
      avatarRuntime?.coordinator?.reconcileRemoteRelationship(payload);
      avatarRuntime?.relationshipManagement?.handleRemoteRelationship(payload);
    },
    onRemoteLinkIcon(payload) {
      avatarRuntime?.coordinator?.reconcileRemoteLinkIcon(payload);
    },
    onBlock(payload) {
      if (Number(payload.blocker_user_id) !== cfg.myUserId) return;

      blockedUserIds.add(Number(payload.blocked_user_id));
      avatarRuntime?.coordinator?.invalidatePendingLinkChoice(
        'block-state-change',
        [cfg.myParticipantId, ...[...participants.values()]
          .filter(person => Number(person.user_id) === Number(payload.blocked_user_id))
          .map(person => person.id)]
      );
      participants.forEach(person => {
        if (Number(person.user_id) === Number(payload.blocked_user_id) || person.linked_to && Number(participants.get(person.linked_to)?.user_id) === Number(payload.blocked_user_id)) {
          avatarRuntime?.coordinator?.clearBlockedRelationship(person);
          avatarRuntime?.p2pAvatar?.clearParticipant(person.id, 'blocked');
          renderParticipant(person, { animateJoin: false });
        }
      });
      renderActiveChat();
    },
    onUnblock(payload) {
      if (Number(payload.blocker_user_id) !== cfg.myUserId) return;

      blockedUserIds.delete(Number(payload.blocked_user_id));
      participants.forEach(renderParticipant);
      renderActiveChat();
    },
    onGameEvent(payload, event) {
      gameRuntime?.lifecycle?.refreshFromRoomEvent(payload, event);
    },
    onRoomUpdate(payload) {
      applyRoomUpdate(payload);
    },
    onRoomDeleted(payload) {
      handleRoomDeleted(payload);
    },
    onRoomEffect(payload) {
      roomEffectsRuntime?.effects?.handleRoomEffect(payload);
    },
    onHostWarning(payload) {
      if (Number(payload.target_user_id) === cfg.myUserId) {
        showHostNotice('Warning', payload.message || 'You have received a warning.');
      }
    },
    onHostEjection(payload) {
      if (Number(payload.target_user_id) === cfg.myUserId) {
        const msg = payload.permanent
          ? 'You have been permanently ejected from the room.'
          : `You have been ejected from the room for ${payload.duration_minutes} minutes.`;
        showHostNotice('Room Ejection', msg, true);
      }
      removeParticipant(payload.target_participant_id);
    },
    onCommunityEjection(payload) {
      if (Number(payload.target_user_id) === cfg.myUserId) {
        const msg = payload.permanent
          ? 'You have been permanently ejected from the community.'
          : `You have been ejected from the community until ${new Date(String(payload.expires_at).replace(' ', 'T') + 'Z').toLocaleString()}.`;
        showHostNotice('Community Ejection', msg, true);
        document.getElementById('host-notice-understand').dataset.redirectUrl = appUrl('/community_ejected.php');
      }
      removeParticipant(payload.target_participant_id);
    },
    onLinkTyping(payload) {
      const chatKey = chatPrivateChats().relationshipChatKeyFromPayload(payload);
      if (chatKey && (activeChatKey() === chatKey || Number(payload.participant_id) === Number(cfg.myParticipantId))) {
        showTyping(payload.participant_id, payload.active);
      }
    },
    onGameTyping(payload) {
      if (gameRuntime?.lifecycle?.getActiveGame()?.lobby_code !== payload.lobby_code) return;
      if (Number(payload.participant_id) !== Number(cfg.myParticipantId)) {
        setGameTyping(payload.participant_id, Boolean(payload.active));
      }
    },
  });
}

function configureVoiceRuntime() {
  voiceRuntime?.viewerPolicy?.configure({
    storage: window.localStorage,
    onChange: reconcileWebcamViewerPolicy,
  });
  voiceRuntime?.privateVoice?.configure({
    getPolicy: () => cfg?.voiceWebcamPolicy || {},
    getConfig: () => cfg,
    getJson(path) {
      return runtimeRequestClient.getJson(path, {
        operation: 'private-voice-snapshot',
        endpointCategory: 'voice-signaling',
      });
    },
    apiPost,
    setTimeout: window.setTimeout.bind(window),
    clearTimeout: window.clearTimeout.bind(window),
    onSnapshot(snapshot) {
      renderPrivateVoiceSnapshot(snapshot);
      const activeId = String(snapshot?.activeChat?.id || '');
      const current = voiceRuntime?.media?.getState?.()?.voiceContext || {};
      if (!activeId && current.type === 'private-voice') {
        selectVoiceContext({ type: 'room', publicId: null }).catch(warnRuntimeRequest);
      }
      if (confirmedWebcamAudience?.mode === 'private-voice') {
        const nextHash = (snapshot?.activeChat?.members || []).map(member => Number(member.userId))
          .filter(userId => userId !== Number(cfg?.myUserId)).sort((a, b) => a - b).join(':');
        if (nextHash !== confirmedWebcamAudience.contextHash && (webcamIntent || webcamStream)) {
          disableLocalWebcam('private-voice-audience-changed').catch(warnRuntimeRequest);
        }
      }
    },
    onError: warnRuntimeRequest,
  });
  voiceRuntime?.transmissionModes?.configure({
    window,
    document,
    storage: window.localStorage,
    holdControl: voiceTransmissionHold,
    statusControl: voiceTransmissionStatus,
    getMedia: () => voiceRuntime?.media,
    getPolicy: () => cfg?.voiceWebcamPolicy || {},
    getPreferences: () => cfg?.voiceWebcamPreferences || {},
  });
  voiceRuntime?.media?.configure({
    window,
    navigator,
    HTMLMediaElement,
    setTimeout: window.setTimeout.bind(window),
    clearTimeout: window.clearTimeout.bind(window),
    setInterval: window.setInterval.bind(window),
    clearInterval: window.clearInterval.bind(window),
    apiPost,
    getConfig: () => cfg,
    getParticipants: () => participants,
    getWebcamStream: () => webcamStream,
    shouldSendWebcamTo: webcamAudienceAllowsParticipant,
    initialMuted: () => voiceRuntime?.transmissionModes?.initialMuted?.() || false,
    shouldReceiveRemoteWebcam(participantId) {
      const person = participants.get(Number(participantId));
      return voiceRuntime?.viewerPolicy?.effectiveFor(person)?.receive !== false;
    },
    getWebcamLifecycleState: () => ({
      intent: webcamIntent,
      acquisitionState: webcamAcquisitionState,
      operationGeneration: webcamOperationGeneration,
      trackId: webcamStream?.getVideoTracks?.()[0]?.id || null,
    }),
    updateToggleButton: updateVoiceToggleButton,
    setDeviceStatus: setVoiceDeviceStatus,
    renderVoiceList,
    attachParticipantVideo,
    detachParticipantVideo,
    canPopulateDevices() {
      return Boolean(voiceInputDevice && voiceOutputDevice);
    },
    onDeviceSnapshot: renderVoiceDeviceSnapshot,
    getVoiceSourceHint() {
      if (!runtimeVerificationControls?.isEnabled()) return '';
      const params = new URLSearchParams(window.location.search);
      return params.get('runtime_diagnostics_audio_source') || '';
    },
    closeDeviceModal: closeVoiceDeviceModal,
    getAudioElements() {
      return Array.from(document.querySelectorAll('audio[id^="voice-audio-"]'));
    },
    getAudioElement(participantId) {
      return document.getElementById(`voice-audio-${participantId}`);
    },
    getOrCreateAudioElement(participantId) {
      let audio = document.getElementById(`voice-audio-${participantId}`);
      if (!audio) {
        audio = document.createElement('audio');
        audio.id = `voice-audio-${participantId}`;
        audio.autoplay = true;
        document.body.appendChild(audio);
      }
      return audio;
    },
    removeAudioElement(participantId) {
      document.getElementById(`voice-audio-${participantId}`)?.remove();
    },
    removeAllAudioElements() {
      document.querySelectorAll('audio[id^="voice-audio-"]').forEach(audio => audio.remove());
    },
    fetchMediaSignals(query) {
      return runtimeRequestClient.getJson('/api/media_signal.php?' + query, {
        operation: 'poll-media-signals',
        endpointCategory: 'voice-signaling',
      });
    },
    handleAvatarSignal(signal) {
      return avatarRuntime?.p2pAvatar?.handleSignal(signal) || false;
    },
    recordVoiceSignalDiagnostic(entry) {
      recordRuntimeDiagnostic(
        'signaling',
        entry?.event || entry?.name || 'runtimeSignalDiagnostic',
        entry,
      );
    },
    recordVoiceLifecycleDiagnostic: recordVoiceLifecycleDiagnostic,
    isRuntimeDiagnosticsEnabled() {
      return runtimeDiagnostics?.isEnabled() || false;
    },
    warn(error) {
      warnRuntimeRequest(error);
    },
  });
}

function recordVoiceLifecycleDiagnostic(entry = {}) {
  recordRuntimeDiagnostic('videoLifecycle', entry.event || 'voice-lifecycle', {
    localParticipantId: cfg?.myParticipantId || null,
    ...entry,
  });
}

function recordRuntimeDiagnostic(category, event, details = {}) {
  return runtimeDiagnostics?.record(category, event, details) || false;
}

function warnRuntimeRequest(error) {
  if (error?.code !== 'REQUEST_ABORTED') console.warn(error);
}

function configureGameRuntime() {
  gameRuntime?.lifecycle?.configure({
    document,
    window,
    apiPost,
    appUrl,
    mediaUrl,
    esc,
    avatarPresentationHtml,
    avatarVisibilityFor,
    getConfig: () => cfg,
    getCsrfToken: () => CSRF_TOKEN,
    activeChatKey,
    gameChatKey,
    switchChat,
    startGameChatPolling,
    stopGameChatPolling,
    stopGameTypingNow,
    renderPeople,
    showWarning,
    renderLinkTabs,
    isGameTyping(participantId) {
      return chatGameChat().isTyping(participantId);
    },
    fetchGames(query) {
      return runtimeRequestClient.getJson('/api/games.php?' + query, {
        operation: 'load-game-catalog',
        endpointCategory: 'games',
      });
    },
    fetchFramework(query, { requestContext = null } = {}) {
      return runtimeRequestClient.getJson('/api/game_framework.php?' + query, {
        operation: 'load-game-framework-state',
        endpointCategory: 'games',
        requestContext,
      });
    },
    openGameRecords,
    renderGameCatalog(catalog, { error = '' } = {}) {
      gameCatalogLoaded = true;
      gameCatalogDefinitions = Array.isArray(catalog) ? catalog.slice() : [];
      if (!gameCatalogDefinitions.some(game => String(game.key || '') === selectedGameCatalogKey)) {
        selectedGameCatalogKey = '';
      }
      renderGamePickerCatalog();
      document.getElementById('game-start-btn').hidden = false;
      if (error) {
        if (gamePickerCount) gamePickerCount.textContent = 'Game library unavailable';
        if (gamePickerSelection) gamePickerSelection.textContent = error;
      }
    },
    getGameListElement() {
      return gameListEl;
    },
    getRoomGameListElement() {
      return roomGamesListEl;
    },
    getRoomGameCountElement() {
      return roomGamesCount;
    },
    getRoomGameEmptyElement() {
      return roomGamesEmpty;
    },
    getRoomGameSummaryElement() {
      return roomGamesSummary;
    },
    onGameOpened() {
      closeRoomGamesMenu({ restoreFocus: false });
      gameRuntime?.webcams?.reconcile?.({ reason: 'game-opened' });
      window.requestAnimationFrame(() => {
        gameRuntime?.webcams?.reconcile?.({ reason: 'game-opened-layout-settled' });
      });
    },
    getGameStageElement() {
      return gameStage;
    },
    getMainElement() {
      return mainEl;
    },
    getGameStartButtonElement() {
      return document.getElementById('game-start-btn');
    },
    onAdaptiveGameLayout(measurement) {
      recordRuntimeDiagnostic('gameLayout', 'adaptive-game-layout', measurement);
      participants.forEach(positionAvatar);
      avatarRuntime?.coordinator?.scheduleRelationshipRefresh({
        all: true,
        reason: measurement?.visible ? 'game-layout-fit' : 'game-layout-restore',
      });
    },
    getGameFrameElement() {
      return gameFrame;
    },
    getStageTitleElement() {
      return document.getElementById('game-stage-title');
    },
    getStageIconElement() {
      return document.getElementById('game-stage-icon');
    },
    getStageStatusElement() {
      return document.getElementById('game-stage-status');
    },
    getGameAcceptElement() {
      return document.getElementById('game-accept');
    },
    getPlayerOneElement() {
      return document.getElementById('game-player-one');
    },
    getPlayerTwoElement() {
      return document.getElementById('game-player-two');
    },
    getGamePlayerElements() {
      return Array.from(document.querySelectorAll('[data-game-seat]'));
    },
    origin() {
      return window.location.origin;
    },
    warnError(error) {
      warnRuntimeRequest(error);
    },
  });
}

function configureRoomEffectsRuntime() {
  roomEffectsRuntime?.effects?.configure({
    document,
    window,
    CSS,
    appUrl,
    mediaUrl,
    cacheBust,
    getConfig: () => cfg,
    getParticipants: () => participants,
    getRoomStage() {
      return roomStageViewport || roomStage;
    },
    setRoomEffectsState(effects, current) {
      cfg.roomEffects = effects || [];
      cfg.activeRoomEffect = current || null;
    },
    setActiveRoomEffect(effect) {
      cfg.activeRoomEffect = effect?.active ? effect : null;
    },
    renderRoomEffectsModal,
    addSystemMessage,
    fetchEffectsState(query) {
      return runtimeRequestClient.getJson('/api/room_admin.php?' + query, {
        operation: 'load-room-effects',
        endpointCategory: 'room-effects',
      });
    },
  });
}

function configureImportedRoomRuntime() {
  const context = {
    document,
    window,
    esc,
    mediaUrl,
    isHttpUrl,
    getConfig: () => cfg,
    reportPlaybackError(error, detail) {
      console.error('Imported website player playback failed.', detail, error);
    },
    getLayoutElement() {
      return vpRoomLayout;
    },
    getStageElement() {
      return roomStageViewport || roomStage;
    },
    getMusicPlayerElement() {
      return vpMusicPlayer;
    },
    getMusicSelectElement() {
      return vpMusicSelect;
    },
    getMusicAudioElement() {
      return vpMusicAudio;
    },
    getMusicYoutubeControlsElement() {
      return vpMusicYoutubeControls;
    },
    getMusicLaunchElement() {
      return vpMusicLaunch;
    },
    getMusicEmbedElement() {
      return vpMusicEmbed;
    },
    getMusicYoutubeElement() {
      return vpMusicYoutube;
    },
    getMusicModalElement() {
      return vpMusicModal;
    },
    getMusicModalTitleElement() {
      return vpMusicModalTitle;
    },
    getMusicModalCloseElement() {
      return vpMusicModalClose;
    },
    getMusicModalMinimizeElement() {
      return vpMusicModalMinimize;
    },
    getMusicDragHandleElement() {
      return vpMusicDragHandle;
    },
    getMusicModalBoxElement() {
      return vpMusicModalBox;
    },
    getMusicFrameWrapElement() {
      return vpMusicFrameWrap;
    },
  };

  importedRoomRuntime?.layout?.configure(context);
  importedRoomRuntime?.music?.configure(context);
}

function configureChatPoll() {
  chatRuntime?.poll?.configure({
    getConfig: () => cfg,
    shouldStop: () => roomExitInProgress,
    pollInterval: isLoopbackHost ? 2500 : 250,
    failureBackoffBase: 1000,
    failureBackoffMax: 30000,
    getTransportPolicy: () => cfg.transport || {},
    resolveUrl: appUrl,
    createEventSource(url) {
      return new EventSource(url, { withCredentials: true });
    },
    createWebSocket(url, protocols) {
      return new WebSocket(url, protocols);
    },
    fetchPoll(query) {
      return runtimeRequestClient.getJson('/api/poll.php?' + query, {
        operation: 'poll-room-events',
        endpointCategory: 'room-poll',
      });
    },
    handleRoomEvent(event) {
      if (handleMessageProtectionChangeEvent(event)) return;
      roomRuntime?.events?.routeRoomEvent(event);
    },
    handleCommunityEvent(event) {
      if (handleMessageProtectionChangeEvent(event)) return;
      roomRuntime?.events?.routeCommunityEvent(event);
    },
    handleProjection(data) {
      const projection = data?.avatar_visibility_preferences;
      if (projection) avatarRuntime?.visibility?.applyServerProjection(projection, 'room-poll');
      if (data?.gesture_preferences) {
        gesturePresentation?.applyServerProjection(data.gesture_preferences, 'room-poll');
      }
      if (data?.gesture_capabilities) {
        applyGestureCapabilityProjection(data.gesture_capabilities, 'room-poll');
      }
    },
    warnError(error, retry = {}) {
      recordRuntimeDiagnostic('requests', 'room-poll-retry-scheduled', {
        code: error?.code || null,
        status: error?.details?.status || null,
        failureCount: Number(retry.failureCount || 0),
        retryDelay: Number(retry.retryDelay || 0),
      });
      warnRuntimeRequest(error);
    },
    onAdapterChange(state = {}) {
      recordRuntimeDiagnostic('requests', 'room-event-transport-changed', {
        activeAdapter: String(state.activeAdapter || 'polling'),
        fallbackAdapter: String(state.fallbackAdapter || 'polling'),
        reason: String(state.reason || '').slice(0, 240),
      });
    },
    onExpectedRenewal(state = {}) {
      recordRuntimeDiagnostic('requests', 'room-event-transport-renewed', {
        activeAdapter: 'sse',
        expected: true,
        retryDelay: Number(state.retryDelay || 0),
      });
    },
  });
  gameRuntime?.webcams?.configure({
    document,
    window,
    storage: window.localStorage,
    getActiveGame: () => gameRuntime?.lifecycle?.getActiveGame?.() || null,
    getParticipants: () => participants,
    getMedia: () => voiceRuntime?.media,
    getViewerPolicy: () => voiceRuntime?.viewerPolicy,
    getCurrentUserId: () => cfg?.myUserId,
    getFrame: () => gameFrame,
    getFrameWrap: () => gameFrame?.closest?.('.game-frame-wrap'),
    getLayer: () => document.getElementById('game-webcam-layer'),
    getPanel: () => document.getElementById('game-webcams-panel'),
    getToggle: () => document.getElementById('game-webcams-toggle'),
    recordDiagnostic: recordRuntimeDiagnostic,
  });
}

function apiPost(url, body) {
  return runtimeRequestClient.postJson(url, body, {
    operation: 'mutate-room-state',
    endpointCategory: 'room-mutation',
  });
}

function apiUpload(url, formData) {
  return runtimeRequestClient.postForm(url, formData, {
    operation: 'upload-room-media',
    endpointCategory: 'room-upload',
  });
}

function setUploadProgress(progressEl, pct, message) {
  if (!progressEl) return;
  const bounded = Math.max(0, Math.min(100, Math.round(pct)));
  progressEl.classList.add('open');
  const bar = progressEl.querySelector('.upload-progress-bar');
  const pctEl = progressEl.querySelector('.upload-progress-pct');
  const msgEl = progressEl.querySelector('.upload-progress-msg');
  if (bar) bar.style.width = `${bounded}%`;
  if (pctEl) pctEl.textContent = `${bounded}%`;
  if (msgEl) msgEl.textContent = message || (bounded >= 100 ? 'Processing...' : 'Uploading...');
}

function resetUploadProgress(progressEl) {
  if (!progressEl) return;
  progressEl.classList.remove('open');
  setUploadProgress(progressEl, 0, 'Waiting...');
  progressEl.classList.remove('open');
}

function videoThumbnailBlob(file) {
  return new Promise((resolve) => {
    if (!file || !String(file.type || '').startsWith('video/')) {
      resolve(null);
      return;
    }
    const video = document.createElement('video');
    const url = URL.createObjectURL(file);
    const cleanup = () => URL.revokeObjectURL(url);
    video.muted = true;
    video.preload = 'metadata';
    video.playsInline = true;
    video.addEventListener('loadeddata', () => {
      try {
        video.currentTime = Math.min(1, Math.max(0, (video.duration || 1) / 4));
      } catch (err) {
        cleanup();
        resolve(null);
      }
    }, { once: true });
    video.addEventListener('seeked', () => {
      try {
        const canvas = document.createElement('canvas');
        const width = 720;
        const ratio = video.videoWidth ? video.videoHeight / video.videoWidth : 9 / 16;
        canvas.width = width;
        canvas.height = Math.max(1, Math.round(width * ratio));
        canvas.getContext('2d')?.drawImage(video, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(blob => {
          cleanup();
          resolve(blob);
        }, 'image/jpeg', 0.82);
      } catch (err) {
        cleanup();
        resolve(null);
      }
    }, { once: true });
    video.addEventListener('error', () => {
      cleanup();
      resolve(null);
    }, { once: true });
    video.src = url;
  });
}

function apiUploadWithProgress(url, formData, progressEl, submitBtn = null) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    if (formData && !formData.has('_csrf')) formData.append('_csrf', CSRF_TOKEN);
    const previousDisabled = submitBtn ? submitBtn.disabled : false;
    if (submitBtn) submitBtn.disabled = true;
    setUploadProgress(progressEl, 0, 'Uploading...');

    xhr.upload.addEventListener('progress', event => {
      if (!event.lengthComputable) {
        setUploadProgress(progressEl, 5, 'Uploading...');
        return;
      }
      const pct = (event.loaded / event.total) * 100;
      setUploadProgress(progressEl, pct, pct >= 100 ? 'Processing...' : 'Uploading...');
    });

    xhr.addEventListener('load', () => {
      setUploadProgress(progressEl, 100, 'Processing...');
      if (submitBtn) submitBtn.disabled = previousDisabled;
      let data = {};
      try {
        data = JSON.parse(xhr.responseText || '{}');
      } catch (err) {
        reject(new Error('Upload response was not readable.'));
        return;
      }
      if (xhr.status >= 200 && xhr.status < 400 && !data.error) {
        resolve(data);
        return;
      }
      reject(new Error(data.error || 'Upload failed'));
    });

    xhr.addEventListener('error', () => {
      if (submitBtn) submitBtn.disabled = previousDisabled;
      reject(new Error('Upload failed. The file may be too large or the connection may have dropped.'));
    });

    xhr.addEventListener('abort', () => {
      if (submitBtn) submitBtn.disabled = previousDisabled;
      reject(new Error('Upload canceled.'));
    });

    xhr.open('POST', appUrl(url));
    xhr.setRequestHeader('X-CSRF-Token', CSRF_TOKEN);
    xhr.send(formData);
  });
}

function sessionLockKey() {
  return `${SESSION_LOCK_PREFIX}${document.body.dataset.roomId || 'room'}`;
}

function setSessionLocked(locked) {
  if (!sessionLockEl) return;
  if (locked) localStorage.setItem(sessionLockKey(), '1');
  else localStorage.removeItem(sessionLockKey());
  sessionLockEl.classList.toggle('open', locked);
  sessionLockEl.setAttribute('aria-hidden', locked ? 'false' : 'true');
  if (sessionLockError) sessionLockError.textContent = '';
  if (sessionLockPassword) sessionLockPassword.value = '';
  if (locked) requestAnimationFrame(() => sessionLockPassword?.focus());
}

function lockSession() {
  closeFloatingShells(['roomAction', 'game']);
  setSessionLocked(true);
}

async function unlockSession() {
  const password = sessionLockPassword?.value || '';
  if (!password) {
    if (sessionLockError) sessionLockError.textContent = 'Enter your account password.';
    sessionLockPassword?.focus();
    return;
  }
  try {
    await apiPost('/api/session_lock.php', { password });
    setSessionLocked(false);
  } catch (err) {
    if (sessionLockError) sessionLockError.textContent = err.message || 'Could not unlock session.';
    sessionLockPassword?.select();
  }
}

function restoreSessionLock() {
  if (localStorage.getItem(sessionLockKey()) === '1') setSessionLocked(true);
}

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
}

function isHttpUrl(value) {
  return /^https?:\/\//i.test(String(value || ''));
}

function linkifiedTextHtml(text) {
  return chatMessageRenderer().linkifiedTextHtml(text);
}

function urlPreviewHtml(preview) {
  return chatMessageRenderer().urlPreviewHtml(preview);
}

function avatarUrl(p) {
  if (!p) return cfg.avatarPresets.Default;
  if (avatarVisibilityFor(p).hidden) return '';
  if (isUserBlocked(p.user_id)) return appUrl('/assets/images/baghead.png');
  if (p.avatar_url?.startsWith('blob:')) return p.avatar_url;
  if (p.avatar_url && !p.avatar_url.startsWith('data:')) {
    const url = mediaUrl(p.avatar_url);
    return `${url}${url.includes('?') ? '&' : '?'}v=${p.avatar_version || 0}`;
  }
  if (p.avatar_url) return p.avatar_url;
  if (p.avatar_path?.startsWith('preset:')) return cfg.avatarPresets[p.avatar_path.slice(7)] || cfg.avatarPresets.Default;
  return mediaUrl(p.avatar_path || cfg.avatarPresets.Default);
}

// Same-origin first-party game frames reuse only this viewer's authorized
// room avatar. Blob URLs remain local; no P2P assets are uploaded to hosting.
Object.defineProperty(window, 'coreChatGameAvatar', { value(userId) {
  const person = Array.from(participants.values()).find(item => Number(item.user_id) === Number(userId));
  if (!person || !cfg || roomExitInProgress) return null;
  if (avatarVisibilityFor(person).hidden) return { hidden: true, url: '' };
  return { hidden: false, url: avatarUrl(person) };
} });

function avatarVisibilityFor(subject, own = false) {
  return avatarRuntime?.visibility?.effectiveFor(subject, {
    own: own || Number(subject?.user_id || 0) === Number(cfg?.myUserId || 0),
  }) || Object.freeze({ hidden: Boolean(subject?.avatar_hidden), exact: false, user: false, scope: null, notice: null });
}

let nameplateVisibilityProjection = { version: 1, entries: [] };

function applyNameplateVisibilityProjection(projection = {}) {
  const version = Math.max(1, Number(projection?.version || 1));
  if (version < Number(nameplateVisibilityProjection.version || 1)) return false;
  nameplateVisibilityProjection = {
    version,
    entries: Array.isArray(projection?.entries) ? projection.entries.slice() : [],
  };
  return true;
}

function nameplateVisibilityFor(subject, own = false) {
  const userId = Number(subject?.user_id || 0);
  const available = Boolean(subject?.nameplate_url || subject?.nameplate_path || subject?.p2p_nameplate);
  if (own || userId <= 0 || userId === Number(cfg?.myUserId || 0)) {
    return { hidden: false, exact: false, user: false, available };
  }
  const identity = String(subject?.p2p_nameplate?.identity || subject?.nameplate_identity || '');
  const entries = nameplateVisibilityProjection.entries.filter(entry => Number(entry.targetUserId) === userId);
  const user = entries.some(entry => entry.scope === 'nameplate_user');
  const exact = entries.some(entry => entry.scope === 'nameplate'
    && (!identity || String(entry.nameplateIdentity || '') === identity));
  return { hidden: user || exact || Boolean(subject?.nameplate_hidden), exact, user, available: available || exact };
}

async function setNameplateVisibility(participant, scope, hidden) {
  const presentedSource = identityNameplateUrl(participant);
  const lastVisibleSource = participant?.nameplate_last_visible_url
    || participant?.nameplate_url
    || participant?.nameplate_path
    || presentedSource
    || null;
  const priorSource = {
    nameplate_path: participant?.nameplate_path ?? null,
    nameplate_url: participant?.nameplate_url ?? lastVisibleSource,
    nameplate_identity: participant?.nameplate_identity ?? null,
    p2p_nameplate: participant?.p2p_nameplate ?? null,
    nameplate_delivery: participant?.nameplate_delivery ?? null,
    nameplate_last_visible_url: lastVisibleSource,
  };
  const action = scope === 'user'
    ? (hidden ? 'hide_nameplate_user' : 'show_nameplate_user')
    : (hidden ? 'hide_nameplate' : 'show_nameplate');
  const result = await mutateVisibilityWithRetry({
    url: '/api/nameplate_visibility_preferences.php',
    staleCode: 'NAMEPLATE_VISIBILITY_STALE',
    body: {
      action,
      target_user_id: Number(participant.user_id),
      expected_version: Number(nameplateVisibilityProjection.version || 1),
      session_id: cfg.sessionId,
      join_token: cfg.myJoinToken,
    },
    applyStaleProjection(projection) {
      applyNameplateVisibilityProjection(projection);
    },
  });
  applyNameplateVisibilityProjection(result?.preferences || {});
  if (!hidden) {
    const clearedScope = scope === 'user' ? 'nameplate_user' : 'nameplate';
    nameplateVisibilityProjection = {
      ...nameplateVisibilityProjection,
      entries: nameplateVisibilityProjection.entries.filter(entry => !(
        Number(entry.targetUserId) === Number(participant.user_id)
        && entry.scope === clearedScope
      )),
    };
  }
  avatarRuntime?.visibility?.applyServerProjection(
    result?.avatarVisibilityPreferences || {},
    `nameplate-visibility:${action}`
  );
  let revealedApplied = false;
  (result?.revealedNameplates || []).forEach(revealed => {
    participants.forEach(person => {
      if (Number(person.user_id) === Number(revealed.user_id)) {
        participants.update(person.id, { ...revealed, nameplate_hidden: false });
        revealedApplied = true;
      }
    });
  });
  participants.update(Number(participant.id), hidden ? {
    nameplate_hidden: true,
    nameplate_last_visible_url: lastVisibleSource,
  } : {
    ...(!revealedApplied ? priorSource : {}),
    nameplate_hidden: false,
    nameplate_hidden_scope: null,
    nameplate_hidden_notice: null,
  });
  avatarRuntime?.p2pAvatar?.reconcileParticipant(participant);
  renderPeople();
  renderVoiceList(lastVoiceParticipants);
  renderActiveChat();
}

function avatarPresentationHtml(subject, options = {}) {
  const policy = avatarVisibilityFor(subject, options.own);
  const className = esc(options.className || '');
  const displayName = esc(options.displayName || subject?.display_name || 'User');
  if (policy.hidden) {
    return `<span class="avatar-hidden-placeholder ${className}" role="img" aria-label="Avatar hidden by you" title="${esc(policy.notice || 'Avatar hidden by you')}"><span>Avatar hidden by you</span></span>`;
  }
  const source = options.source || avatarUrl(subject);
  return `<img${className ? ` class="${className}"` : ''} src="${esc(source || cfg?.avatarPresets?.Default || '')}" alt="${displayName}"${options.title === false ? '' : ` title="${displayName}"`}>`;
}

function isWebcamAssetUrl(url) {
  return String(url || '').includes('/assets/uploads/webcam/');
}

function addAvatarContextListeners(el) {
  if (!el) return;
  el.tabIndex = 0;
  el.setAttribute('role', 'button');
  el.setAttribute('aria-label', 'Avatar actions');
  let longPressTimer = null;
  let longPressStart = null;
  const cancelLongPress = () => {
    if (longPressTimer) clearTimeout(longPressTimer);
    longPressTimer = null;
    longPressStart = null;
  };
  const openForElement = (x, y, focusMenu = false) => {
    const current = participants.get(Number(el.dataset.participantId));
    if (!current) return;
    openAvatarContextMenu(x, y, current, { returnFocus: el, focusMenu });
  };
  el.addEventListener('contextmenu', e => {
    e.preventDefault();
    e.stopPropagation();
    openForElement(e.clientX, e.clientY);
  });
  el.addEventListener('keydown', e => {
    if (e.key !== 'ContextMenu' && !(e.shiftKey && e.key === 'F10')) return;
    e.preventDefault();
    const rect = el.getBoundingClientRect();
    openForElement(rect.left + rect.width / 2, rect.top + rect.height / 2, true);
  });
  el.addEventListener('pointerdown', e => {
    if (e.pointerType !== 'touch') return;
    cancelLongPress();
    longPressStart = { x: e.clientX, y: e.clientY };
    longPressTimer = setTimeout(() => {
      openForElement(e.clientX, e.clientY, true);
      cancelLongPress();
    }, 550);
  });
  el.addEventListener('pointermove', e => {
    if (!longPressStart) return;
    if (Math.hypot(e.clientX - longPressStart.x, e.clientY - longPressStart.y) > 8) {
      cancelLongPress();
    }
  });
  el.addEventListener('pointerup', cancelLongPress);
  el.addEventListener('pointercancel', cancelLongPress);
}

function setAvatarImageSource(img, nextSrc, flip = false) {
  avatarRuntime?.renderer?.setAvatarImageSource(img, nextSrc, {
    flip,
    window,
  });
}

function attachParticipantVideo(participantId, stream, own = false, presentationIdentity = {}) {
  const person = participants.get(Number(participantId));
  const viewerPolicy = webcamViewerPolicyFor(person, own);
  const previousPreviewTrackId = person?.webcamVideoEl?.srcObject?.getVideoTracks?.()[0]?.id || null;
  recordVoiceLifecycleDiagnostic({
    event: 'attachParticipantVideo-called',
    participantId: Number(participantId),
    own: Boolean(own),
    hasPerson: Boolean(person),
    hasStream: Boolean(stream),
    streamTrackCount: stream?.getTracks?.().length || 0,
    source: presentationIdentity.source || (own ? 'local-capture' : 'unknown'),
    peerInstanceId: presentationIdentity.peerInstanceId || null,
    generation: presentationIdentity.generation || null,
    receiverIdentity: presentationIdentity.receiverIdentity || null,
    requestedStreamIdentity: presentationIdentity.streamIdentity || null,
    videoTrackState: stream?.getVideoTracks?.().map(track => ({
      id: track.id,
      readyState: track.readyState,
      enabled: track.enabled,
      muted: track.muted,
    })) || [],
  });
  if (!person || !stream) {
    if (stream) pendingRemoteVideoStreams.set(Number(participantId), {
      stream,
      own,
      presentationIdentity,
    });
    recordVoiceLifecycleDiagnostic({
      event: 'attachParticipantVideo-pending',
      participantId: Number(participantId),
      reason: !person ? 'missing-participant' : 'missing-stream',
    });
    return;
  }
  if (!own && viewerPolicy.receive === false) {
    pendingRemoteVideoStreams.delete(Number(participantId));
    recordVoiceLifecycleDiagnostic({
      event: 'attachParticipantVideo-skipped-by-viewer-policy',
      participantId: Number(participantId),
      reason: viewerPolicy.reason,
      streamTrackCount: stream?.getTracks?.().length || 0,
    });
    return;
  }
  pendingRemoteVideoStreams.delete(Number(participantId));
  avatarRuntime?.renderer?.attachWebcam(person, stream, {
    stage: participantStage(person),
    document,
    own,
    source: presentationIdentity.source || (own ? 'local-capture' : 'unknown'),
    presentationIdentity,
    presentationVisible: own || viewerPolicy.show,
    presentationReason: viewerPolicy.reason,
    onWebcamPresentationDiagnostic: recordVoiceLifecycleDiagnostic,
    onWebcamPresentationError(error, detail) {
      if (error?.name === 'NotAllowedError') return;
      console.error('Webcam playback failed.', detail, error);
    },
    addContextListeners: addAvatarContextListeners,
    makeDraggable,
  });
  participants.update(participantId, {
    webcam_enabled: true,
  });
  recordVoiceLifecycleDiagnostic({
    event: 'webcam-state-change',
    source: 'attachParticipantVideo',
    participantId: Number(participantId),
    previous: {
      webcam_enabled: Boolean(person.webcam_enabled),
      webcam_path: person.webcam_path || null,
    },
    next: {
      webcam_enabled: true,
      webcam_path: person.webcam_path || null,
    },
  });
  recordVoiceLifecycleDiagnostic({
    event: 'attachParticipantVideo-complete',
    participantId: Number(participantId),
    hasVideoElement: Boolean(person.webcamVideoEl),
    videoSrcObjectTrackCount: person.webcamVideoEl?.srcObject?.getTracks?.().length || 0,
  });
  gameRuntime?.webcams?.reconcile?.({ reason: 'webcam-attached' });
  if (own) {
    const localTrackId = stream.getVideoTracks?.()[0]?.id || null;
    recordVoiceLifecycleDiagnostic({
      event: previousPreviewTrackId && previousPreviewTrackId !== localTrackId
        ? 'local-preview-replaced'
        : 'local-preview-attached',
      participantId: Number(participantId),
      previousTrackId: previousPreviewTrackId,
      localPreviewTrackId: localTrackId,
      muted: Boolean(person.webcamVideoEl?.muted),
      autoplay: Boolean(person.webcamVideoEl?.autoplay),
      playsInline: Boolean(person.webcamVideoEl?.playsInline),
    });
  }
  positionAvatar(person);
  avatarRuntime?.coordinator?.scheduleRelationshipRefresh({
    participant: person,
    reason: 'webcam-frame-change',
  });
}

function detachParticipantVideo(
  participantId,
  flip = true,
  reason = 'explicit-detach',
  options = {},
) {
  const person = participants.get(Number(participantId));
  const preserveAvailability = Boolean(options?.preserveAvailability);
  const previousTrackId = person?.webcamVideoEl?.srcObject?.getVideoTracks?.()[0]?.id || null;
  recordVoiceLifecycleDiagnostic({
    event: 'detachParticipantVideo-called',
    participantId: Number(participantId),
    flip: Boolean(flip),
    hasPerson: Boolean(person),
    hadVideoElement: Boolean(person?.webcamVideoEl),
    videoSrcObjectTrackCount: person?.webcamVideoEl?.srcObject?.getTracks?.().length || 0,
  });
  pendingRemoteVideoStreams.delete(Number(participantId));
  if (!person) return;
  const previous = {
    webcam_enabled: Boolean(person.webcam_enabled),
    webcam_path: person.webcam_path || null,
  };
  if (preserveAvailability) {
    recordVoiceLifecycleDiagnostic({
      event: 'webcam-presentation-detached-state-preserved',
      source: 'detachParticipantVideo',
      participantId: Number(participantId),
      reason,
      webcam_enabled: previous.webcam_enabled,
      webcam_path: previous.webcam_path,
    });
  } else {
    participants.update(participantId, {
      webcam_enabled: false,
      webcam_path: null,
    });
    recordVoiceLifecycleDiagnostic({
      event: 'webcam-state-change',
      source: 'detachParticipantVideo',
      participantId: Number(participantId),
      previous,
      next: {
        webcam_enabled: false,
        webcam_path: null,
      },
    });
  }
  avatarRuntime?.renderer?.detachWebcam(person, {
    flip,
    window,
    reason,
    onWebcamPresentationDiagnostic: recordVoiceLifecycleDiagnostic,
  });
  recordVoiceLifecycleDiagnostic({
    event: 'detachParticipantVideo-complete',
    participantId: Number(participantId),
    hasVideoElement: Boolean(person.webcamVideoEl),
    reason,
  });
  if (Number(participantId) === Number(cfg?.myParticipantId)) {
    recordVoiceLifecycleDiagnostic({
      event: 'local-preview-removed',
      participantId: Number(participantId),
      previousTrackId,
      reason,
    });
  }
  avatarRuntime?.coordinator?.scheduleRelationshipRefresh({
    participant: person,
    reason: 'webcam-frame-change',
  });
  gameRuntime?.webcams?.reconcile?.({ reason: 'webcam-detached' });
}

function applyWebcamState(participantId, enabled, webcamPath = null, source = 'unknown') {
  const person = participants.get(Number(participantId));
  if (!person) return;
  const localCaptureTrack = Number(participantId) === Number(cfg?.myParticipantId)
    ? webcamStream?.getVideoTracks?.().find(track => track.readyState === 'live') || null
    : null;
  const previous = {
    webcam_enabled: Boolean(person.webcam_enabled),
    webcam_path: person.webcam_path || null,
  };
  const next = Object.assign({}, person, {
    webcam_path: webcamPath || null,
    webcam_enabled: Boolean(enabled || webcamPath || localCaptureTrack),
  });
  recordVoiceLifecycleDiagnostic({
    event: 'webcam-state-change',
    source,
    participantId: Number(participantId),
    previous,
    next: {
      webcam_enabled: Boolean(next.webcam_enabled),
      webcam_path: next.webcam_path || null,
    },
    willDetach: !next.webcam_enabled,
    localCaptureAuthoritative: Boolean(localCaptureTrack),
  });
  if (!next.webcam_path && isWebcamAssetUrl(next.avatar_url)) next.avatar_url = null;
  renderParticipant(next);
  if (localCaptureTrack) {
    syncLocalWebcamPreview(`participant-state:${source}`);
  } else if (!next.webcam_enabled) {
    detachParticipantVideo(participantId, true, `participant-state:${source}`);
  }
  if (Number(participantId) !== Number(cfg?.myParticipantId)) {
    voiceRuntime?.media?.reconcileRemoteWebcamPresentation(
      participantId,
      Boolean(next.webcam_enabled),
      `participant-state:${source}`
    );
    voiceRuntime?.media?.reconcileRemoteWebcamReadiness(
      participantId,
      Boolean(next.webcam_enabled),
      `participant-state:${source}`
    );
  }
}

function syncLocalWebcamPreview(reason = 'local-capture-sync') {
  const participantId = Number(cfg?.myParticipantId);
  const person = participants.get(participantId);
  const track = webcamStream?.getVideoTracks?.().find(item => item.readyState === 'live') || null;
  if (!person || !track) return false;

  participants.update(participantId, {
    webcam_enabled: true,
    webcam_path: null,
  });
  attachParticipantVideo(participantId, webcamStream, true);
  recordVoiceLifecycleDiagnostic({
    event: 'local-preview-reconciled',
    participantId,
    reason,
    localPreviewTrackId: person.webcamVideoEl?.srcObject?.getVideoTracks?.()[0]?.id || null,
    currentLocalTrackId: track.id,
    previewUsesCurrentTrack: person.webcamVideoEl?.srcObject?.getVideoTracks?.()[0] === track,
  });
  return true;
}

function messageAvatarUrl(msg, participant = null) {
  if (participant) return avatarUrl(participant);
  if (avatarVisibilityFor(msg).hidden) return '';
  if (isUserBlocked(msg?.user_id)) return appUrl('/assets/images/baghead.png');
  if (msg?.avatar_url) return mediaUrl(msg.avatar_url);
  if (msg?.avatar_path?.startsWith('preset:')) return cfg.avatarPresets[msg.avatar_path.slice(7)] || cfg.avatarPresets.Default;
  if (msg?.avatar_path) return mediaUrl(msg.avatar_path);
  return cfg.avatarPresets.Default;
}

function isUserBlocked(userId) {
  return blockedUserIds.has(Number(userId));
}

function muteExpiryMs(policy) {
  const value = policy?.expires_at || policy?.expiresAt || '';
  if (!value) return Number.POSITIVE_INFINITY;
  const parsed = parseServerDate(value);
  return parsed && !Number.isNaN(parsed.getTime()) ? parsed.getTime() : 0;
}

function activeMutedPolicyFor(userId) {
  const key = Number(userId || 0);
  const policy = mutedUserPolicies.get(key) || null;
  if (policy && roomServerClock?.serverDeadlineExpired(muteExpiryMs(policy))) {
    mutedUserPolicies.delete(key);
    clearPersonalMuteRevealsForUser(key);
    return null;
  }
  return policy;
}

function authoritativeGameOrSystemMessage(message, chatKey = '') {
  return Boolean(message?.system)
    || String(chatKey || message?.channel || '').startsWith('game:')
    || ['game_action', 'game_score', 'system', 'required_state'].includes(String(message?.message_type || ''));
}

function messageMuteScope(message) {
  return ['gesture', 'voice_note'].includes(String(message?.message_type || ''))
    ? 'gestures-audio'
    : 'text-bubbles';
}

function personalMuteMessageKey(message, chatKey = '') {
  const userId = Number(message?.user_id || participants.get(Number(message?.participant_id))?.user_id || 0);
  const messageId = String(message?.id || message?.client_message_id || '');
  return userId > 0 && messageId ? `${userId}:${String(chatKey || message?.channel || 'room')}:${messageId}` : '';
}

function clearPersonalMuteRevealsForUser(userId) {
  const prefix = `${Number(userId || 0)}:`;
  [...revealedPersonalMuteMessages].forEach(key => {
    if (key.startsWith(prefix)) revealedPersonalMuteMessages.delete(key);
  });
}

function captureMutedMessageOriginal(message) {
  if (message._personalMuteOriginal) return;
  message._personalMuteOriginal = {
    content: message.content,
    message_type: message.message_type,
    url_preview: message.url_preview,
    reply_to: message.reply_to,
    original_name: message.original_name,
    mime_type: message.mime_type,
    file_size: message.file_size,
  };
  message.muted_original_content = message.content;
}

function restoreMutedMessageOriginal(message, { revealed = false } = {}) {
  const original = message?._personalMuteOriginal;
  if (original) Object.assign(message, original);
  message.muted_collapsed = false;
  message.one_message_reveal_allowed = Boolean(revealed);
  message.muted_revealed = Boolean(revealed);
  if (!revealed) {
    delete message._personalMuteOriginal;
    delete message.muted_original_content;
    delete message.one_message_reveal_allowed;
    delete message.muted_revealed;
  }
  return message;
}

function applyPersonalMutePresentation(message, chatKey = '') {
  const userId = Number(message?.user_id || participants.get(Number(message?.participant_id))?.user_id || 0);
  const policy = activeMutedPolicyFor(userId);
  const scopes = Array.isArray(policy?.scopes) ? policy.scopes : [];
  const shouldCollapse = Boolean(policy)
    && !authoritativeGameOrSystemMessage(message, chatKey)
    && scopes.includes(messageMuteScope(message));
  if (!shouldCollapse) {
    if (message?._personalMuteOriginal) restoreMutedMessageOriginal(message);
    return message;
  }
  const revealKey = personalMuteMessageKey(message, chatKey);
  if (message.muted_revealed || (revealKey && revealedPersonalMuteMessages.has(revealKey))) {
    if (message?._personalMuteOriginal) restoreMutedMessageOriginal(message, { revealed: true });
    else {
      message.muted_collapsed = false;
      message.one_message_reveal_allowed = true;
      message.muted_revealed = true;
    }
    return message;
  }
  captureMutedMessageOriginal(message);
  Object.assign(message, {
    content: MUTED_MESSAGE_PLACEHOLDER,
    message_type: 'text',
    url_preview: null,
    reply_to: null,
    original_name: null,
    mime_type: null,
    file_size: null,
    muted_collapsed: true,
    one_message_reveal_allowed: true,
  });
  return message;
}

function reconcilePersonalMuteMessages() {
  chatMessageState()?.forEachChannelMessage?.(message => {
    applyPersonalMutePresentation(message, String(message?.channel || ''));
  });
  renderActiveChat();
}

function schedulePersonalMuteExpiry() {
  if (muteExpiryTimer) clearTimeout(muteExpiryTimer);
  muteExpiryTimer = null;
  const expiries = [...mutedUserPolicies.values()]
    .map(muteExpiryMs)
    .filter(value => Number.isFinite(value));
  if (!expiries.length) return;
  const serverNow = roomServerClock?.serverEpochNow({conservative: true});
  const delay = serverNow == null ? 1000
    : Math.max(0, Math.min(Math.min(...expiries) - serverNow, 2147483647));
  muteExpiryTimer = setTimeout(() => {
    [...mutedUserPolicies.keys()].forEach(activeMutedPolicyFor);
    reconcilePersonalMuteMessages();
    schedulePersonalMuteExpiry();
    if (memberProfileSnapshot && memberProfileUserId) renderMemberProfileActions(memberProfileSnapshot);
  }, delay);
}

function replacePersonalMuteProjection(policies = []) {
  const previousMutedUserIds = new Set(mutedUserPolicies.keys());
  mutedUserPolicies.clear();
  (Array.isArray(policies) ? policies : []).forEach(policy => {
    const userId = Number(policy?.muted_user_id || policy?.mutedUserId || 0);
    if (userId > 0 && !roomServerClock?.serverDeadlineExpired(muteExpiryMs(policy))) mutedUserPolicies.set(userId, policy);
  });
  previousMutedUserIds.forEach(userId => {
    if (!mutedUserPolicies.has(userId)) clearPersonalMuteRevealsForUser(userId);
  });
  reconcilePersonalMuteMessages();
  schedulePersonalMuteExpiry();
}

async function setPersonalMuteState(userId, muted, duration = 'until-unmute') {
  const targetUserId = Number(userId || 0);
  if (!targetUserId || targetUserId === Number(cfg.myUserId)) return;
  const response = await apiPost('/api/moderation.php', {
    action: muted ? 'mute' : 'unmute',
    target_user_id: targetUserId,
    ...(muted ? { duration, scopes: PERSONAL_MUTE_SCOPES } : {}),
  });
  if (Array.isArray(response?.mutes)) {
    replacePersonalMuteProjection(response.mutes);
  } else {
    if (muted) {
      mutedUserPolicies.set(targetUserId, {
        muted_user_id: targetUserId,
        scopes: PERSONAL_MUTE_SCOPES,
        expires_at: response?.expiresAt || null,
      });
    } else {
      mutedUserPolicies.delete(targetUserId);
      clearPersonalMuteRevealsForUser(targetUserId);
    }
    reconcilePersonalMuteMessages();
    schedulePersonalMuteExpiry();
  }
  return response;
}

function displayNameFor(p) {
  return isUserBlocked(p?.user_id) ? 'Blocked' : (p?.display_name || 'Someone');
}

function participantRoleKey(p) {
  const role = String(p?.role || 'user').replace(/[^a-z]/g, '') || 'user';
  if (p?.is_installation_owner) return 'owner';
  if (['admin', 'developer', 'guide', 'moderator'].includes(role)) return role;
  if (p?.is_owner) return 'roomowner';
  return role;
}

function participantRoleLabel(p) {
  const key = participantRoleKey(p);
  if (key === 'owner') return 'CoreChat Owner';
  if (key === 'roomowner') return 'Room Owner';
  if (key === 'developer') return 'Developer';
  if (key === 'admin') return 'Admin';
  if (key === 'guide') return 'Guide';
  if (key === 'moderator') return 'Moderator';
  return 'User';
}

function participantRoleClass(p) {
  return `role-${participantRoleKey(p)}`;
}

function participantRoleLabels(p) {
  const labels = [];
  const role = String(p?.role || 'user').replace(/[^a-z]/g, '') || 'user';
  if (p?.is_installation_owner) labels.push('CoreChat Owner');
  const primaryLabels = {
    admin: 'Administrator',
    developer: 'Developer',
    moderator: 'Moderator',
    guide: 'Guide',
  };
  if (primaryLabels[role]) labels.push(primaryLabels[role]);
  if (p?.is_owner) labels.push('Room Owner');
  return labels.length ? [...new Set(labels)] : ['User'];
}

function participantRoleBadgeHtml(p) {
  const key = participantRoleKey(p);
  const badges = {
    owner: ['♛', 'OWNER'],
    roomowner: ['⌂', 'ROOM OWNER'],
    admin: ['◆', 'ADMIN'],
    moderator: ['⬟', 'MOD'],
    guide: ['✦', 'GUIDE'],
    developer: ['⌘', 'DEV'],
  };
  const badge = badges[key];
  if (!badge) return '';
  const label = participantRoleLabel(p);
  return `<span class="role-badge role-${key}" title="${esc(label)}" aria-label="${esc(label)}"><span class="role-badge-icon" aria-hidden="true">${badge[0]}</span><span>${badge[1]}</span></span>`;
}

function identityNameplateUrl(subject, participant = null) {
  const person = participant || subject;
  if (!person || nameplateVisibilityFor(person).hidden || isUserBlocked(person.user_id)) return '';
  return String(person.nameplate_url || person.nameplate_path || '').trim();
}

function applyIdentityNameplate(element, person) {
  if (!element) return;
  const source = chatAppearancePreferences.showIdentityNameplates ? identityNameplateUrl(person) : '';
  element.classList.toggle('identity-nameplate', Boolean(source));
  element.style.removeProperty('--identity-nameplate-image');
  let artwork = [...element.children].find(child => child.classList.contains('identity-nameplate-artwork'));
  let content = [...element.children].find(child => child.classList.contains('identity-nameplate-content'));
  if (!source) {
    artwork?.remove();
    if (content) content.replaceWith(...content.childNodes);
    return;
  }
  if (!content) {
    content = document.createElement(element.tagName === 'STRONG' ? 'span' : 'div');
    content.className = 'identity-nameplate-content';
    [...element.childNodes].filter(child => child !== artwork).forEach(child => content.appendChild(child));
    element.appendChild(content);
  }
  if (!artwork) {
    artwork = document.createElement('img');
    artwork.className = 'identity-nameplate-artwork';
    artwork.alt = '';
    artwork.setAttribute('aria-hidden', 'true');
    artwork.draggable = false;
    element.prepend(artwork);
  }
  // A real image supplies its intrinsic ratio and preserves GIF/WebP/APNG playback.
  // Do not reset an unchanged source during presence refreshes: that restarts GIFs.
  if (artwork.getAttribute('src') !== source) artwork.src = source;
}

function persistChatAppearancePreference(key, value) {
  try { window.localStorage.setItem(key, value ? 'true' : 'false'); } catch (_) {}
}

function rerenderChatAppearance() {
  document.body.classList.toggle('hide-identity-nameplates', !chatAppearancePreferences.showIdentityNameplates);
  document.body.classList.toggle('hide-chat-role-titles', !chatAppearancePreferences.showRoleMessageTitles);
  if (!cfg) return;
  const previousTop = messagesEl?.scrollTop || 0;
  const pinned = messagesEl ? messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight < 24 : true;
  renderPeople();
  renderVoiceList(lastVoiceParticipants);
  renderActiveChat();
  if (messagesEl && !pinned) messagesEl.scrollTop = previousTop;
}

document.body.classList.toggle('hide-identity-nameplates', !chatAppearancePreferences.showIdentityNameplates);
document.body.classList.toggle('hide-chat-role-titles', !chatAppearancePreferences.showRoleMessageTitles);

function setPermissionUI() {
  const actionBtn = document.getElementById('room-action-btn');
  if (actionBtn) actionBtn.hidden = !(cfg?.canEditRoom || cfg?.canUseHostTools);
  const editAction = document.getElementById('room-action-edit');
  if (editAction) editAction.hidden = !cfg?.canEditRoom;
  const refreshImportText = document.getElementById('room-action-refresh-import-text');
  if (refreshImportText) refreshImportText.hidden = !(cfg?.canEditRoom && cfg?.importUrl);
  const effectsAction = document.getElementById('room-action-effects');
  if (effectsAction) effectsAction.hidden = !cfg?.canUseHostTools;
  const clearHistoryAction = document.getElementById('room-action-clear-history');
  if (clearHistoryAction) clearHistoryAction.hidden = !cfg?.canUseHostTools;
}

function allChannelMaps() {
  return {
    forEach(callback) {
      chatMessageState().forEachChannelMessage(callback);
    },
  };
}

function applyUserRoleUpdate(update) {
  const userId = Number(update.user_id);
  const participantId = Number(update.participant_id);
  if (!userId && !participantId) return;
  const nextRole = update.role || 'user';
  let changedParticipants = false;
  participants.forEach(person => {
    const matches = Number(person.user_id) === userId || Number(person.id) === participantId;
    if (!matches) return;
    person.role = nextRole;
    if ('is_owner' in update) person.is_owner = Boolean(update.is_owner);
    renderParticipant(person);
    changedParticipants = true;
  });
  allChannelMaps().forEach(msg => {
    const msgUserId = Number(msg.user_id || participants.get(msg.participant_id)?.user_id || 0);
    if (msgUserId !== userId && Number(msg.participant_id) !== participantId) return;
    msg.role = nextRole;
    if ('is_owner' in update) msg.is_owner = Boolean(update.is_owner);
  });
  if (userId === Number(cfg.myUserId) || participantId === Number(cfg.myParticipantId)) {
    cfg.myRole = nextRole;
    if ('can_edit_room' in update) cfg.canEditRoom = Boolean(update.can_edit_room);
    if ('can_use_host_tools' in update) cfg.canUseHostTools = Boolean(update.can_use_host_tools);
    if ('can_moderate_messages' in update) cfg.canModerateMessages = Boolean(update.can_moderate_messages);
    if ('can_community_eject' in update) cfg.canCommunityEject = Boolean(update.can_community_eject);
    setPermissionUI();
    closeContextMenu();
  }
  if (changedParticipants) renderPeople();
  renderActiveChat();
}

function participantByUserId(userId) {
  const id = Number(userId);
  if (!id) return null;
  for (const person of participants.values()) {
    if (Number(person.user_id) === id) return person;
  }
  return null;
}

function messageUserId(msg) {
  return Number(msg.user_id || participants.get(msg.participant_id)?.user_id || 0);
}

function messageVisible(msg) {
  if (msg?.is_deleted && !cfg?.canModerateMessages) return false;
  const uid = messageUserId(msg);
  return !uid || uid === cfg.myUserId || !isUserBlocked(uid);
}

function showWarning(message) {
  document.getElementById('warning-message').textContent = message;
  document.getElementById('warning-modal').classList.add('open');
}

function showHostNotice(title, message, redirectToLobby = false) {
  document.getElementById('host-notice-title').textContent = title;
  document.getElementById('host-notice-message').textContent = message;
  const btn = document.getElementById('host-notice-understand');
  btn.dataset.redirect = redirectToLobby ? '1' : '';
  btn.dataset.redirectUrl = '';
  document.getElementById('host-notice-modal').classList.add('open');
}

function activeLinkPartnerId() {
  return chatPrivateChats().activeLinkPartnerId(activeChatKey());
}

function activeRelationshipRequest() {
  return chatPrivateChats().relationshipRequest(activeChatKey());
}

function activeDmUserId() {
  return chatPrivateChats().activeDmUserId(activeChatKey());
}

function linkKeyFor(a, b) {
  return avatarRuntime?.relationships?.linkKeyFor(a, b) || [Number(a), Number(b)].sort((x, y) => x - y).join(':');
}

function normalizeLinkMode(mode) {
  return avatarRuntime?.relationships?.normalizeLinkMode(mode) || (mode === 'lap' ? 'lap' : 'normal');
}

function isLapLinkInitiator(person) {
  return avatarRuntime?.relationships?.isLapLinkInitiator(person) || false;
}

function isLapLinkTarget(person) {
  return avatarRuntime?.relationships?.isLapLinkTarget(person) || false;
}

function linkModeForPair(a, b) {
  return avatarRuntime?.relationships?.linkModeForPair(a, b) || 'normal';
}

function avatarStageSize(person) {
  return avatarRuntime?.layout?.avatarStageSize(person, {
    baseSize: AVATAR_STAGE_SIZE,
    dimensions: avatarRenderedDimensions(person),
  }) || AVATAR_STAGE_SIZE;
}

function avatarRenderedDimensions(person, options = {}) {
  return avatarRuntime?.renderer?.renderedAvatarDimensions(person, {
    fallbackSize: AVATAR_STAGE_SIZE,
    lapInitiator: isLapLinkInitiator(person),
    ...options,
  }) || {
    width: AVATAR_STAGE_SIZE,
    height: AVATAR_STAGE_SIZE,
  };
}

function chatMessageState() {
  return chatRuntime?.messages;
}

function chatPrivateChats() {
  return chatRuntime?.privateChats;
}

function chatMessageRenderer() {
  return chatRuntime?.renderer;
}

function chatMessageActions() {
  return chatRuntime?.actions;
}

function chatUnread() {
  return chatRuntime?.unread;
}

function chatReply() {
  return chatRuntime?.reply;
}

function chatTyping() {
  return chatRuntime?.typing;
}

function chatComposer() {
  return chatRuntime?.composer;
}

function chatMediaSend() {
  return chatRuntime?.mediaSend;
}

function chatGameChat() {
  return chatRuntime?.gameChat;
}

function chatNavigation() {
  return chatRuntime?.navigation;
}

function chatPoll() {
  return chatRuntime?.poll;
}

function activeChatKey() {
  return chatNavigation()?.activeChat() || 'room';
}

function channelForApi(chatKey = activeChatKey()) {
  return chatMessageState().channelForApi(chatKey);
}

function linkPartnerIdFromKey(key) {
  return chatPrivateChats().linkPartnerIdFromKey(key);
}

function relationshipChatKeyFromPayload(payload) {
  return chatPrivateChats().relationshipChatKeyFromPayload(payload);
}

function dmPartnerIdFromPayload(payload) {
  return chatPrivateChats().dmPartnerIdFromPayload(payload);
}

function chatLabel(chatKey = activeChatKey()) {
  if (chatKey === 'room') return 'Chat Room';
  if (chatKey === 'community') return 'Community Chat';
  if (chatKey.startsWith('dm:')) {
    return chatPrivateChats().dmLabel(chatKey);
  }
  if (chatKey.startsWith('game:')) {
    const activeGame = gameRuntime?.lifecycle?.getActiveGame();
    return activeGame ? `${gameName(activeGame.game_type)} Game` : 'Game';
  }
  return `Link> ${chatPrivateChats().relationshipLabel()}`;
}

function rememberDmUser(user) {
  return chatPrivateChats().rememberDmUser(user);
}

function openDmWithUser(user) {
  chatPrivateChats().openDmWithUser(user);
}

function rememberDirectMessageUser(partnerUserId, payload) {
  chatPrivateChats().rememberIncomingDmUser(partnerUserId, payload);
}

function updateComposerPlaceholder() {
  const input = document.getElementById('chat-input');
  if (!input) return;
  const activeChat = activeChatKey();
  if (activeChat === 'room') input.placeholder = `Message ${cfg.roomName || 'room'}`;
  else input.placeholder = `Message ${chatLabel(activeChat)}`;
  syncMessageProtectionControl();
}

function preloadImage(src) {
  return new Promise(resolve => {
    if (!src) {
      resolve(false);
      return;
    }
    const img = new Image();
    let settled = false;
    let timeoutId = null;
    const finish = loaded => {
      if (settled) return;
      settled = true;
      if (timeoutId !== null) window.clearTimeout(timeoutId);
      img.onload = null;
      img.onerror = null;
      resolve(loaded);
    };
    img.onload = () => finish(true);
    img.onerror = () => finish(false);
    timeoutId = window.setTimeout(() => finish(false), 5000);
    img.src = src;
    if (img.complete) finish(true);
  });
}

function runAvatarPixelEffect(person, mode = 'in') {
  return avatarRuntime?.effects?.runPixelEffect(person, {
    mode,
    stage: participantStage(person),
    document,
    window,
  }) || Promise.resolve();
}

async function renderParticipantWhenReady(p, options = {}) {
  const prepared = Object.assign({}, p);
  if (!avatarVisibilityFor(prepared).hidden) await preloadImage(avatarUrl(prepared));
  renderParticipant(prepared, options);
}

function renderParticipant(p, options = {}) {
  if (roomExitInProgress && Number(p.id) === Number(cfg?.myParticipantId)) return;
  const existing = participants.get(p.id) || {};
  const hadImage = Boolean(existing.avatarEl);
  const wasWebcam = Boolean(existing.webcam_path || existing.webcam_enabled);
  const merged = participants.merge(p);
  if (!merged.webcam_path && isWebcamAssetUrl(merged.avatar_url)) merged.avatar_url = null;

  const nowWebcam = Boolean(merged.webcam_path || merged.webcam_enabled);
  if (wasWebcam !== nowWebcam) {
    recordVoiceLifecycleDiagnostic({
      event: 'renderParticipant-webcam-mode-change',
      participantId: Number(merged.id),
      previousWebcam: wasWebcam,
      nextWebcam: nowWebcam,
      webcam_enabled: Boolean(merged.webcam_enabled),
      webcam_path: merged.webcam_path || null,
      hadVideoElement: Boolean(existing.webcamVideoEl),
      reason: nowWebcam ? 'render-to-webcam' : 'render-to-avatar',
    });
  }
  avatarRuntime?.renderer?.syncParticipant(merged, {
    stage: participantStage(merged),
    document,
    window,
    own: Number(p.id) === Number(cfg.myParticipantId),
    makeDraggable,
    addContextListeners: addAvatarContextListeners,
    avatarSource: avatarUrl(merged),
    avatarHidden: avatarVisibilityFor(merged).hidden,
    avatarHiddenNotice: avatarVisibilityFor(merged).notice,
    orientation: normalizeAvatarOrientation(merged.avatar_orientation),
    displayName: displayNameFor(merged),
    webcam: nowWebcam,
    webcamEnabled: merged.webcam_enabled,
    lapInitiator: isLapLinkInitiator(merged),
    lapTarget: isLapLinkTarget(merged),
    lapSide: avatarRuntime?.relationships?.lapSideForParticipant(merged),
    flipImage: hadImage && wasWebcam !== nowWebcam,
    fallbackSize: AVATAR_STAGE_SIZE,
    onRenderedSizeChange(participant, detail = {}) {
      positionAvatar(participant);
      avatarRuntime?.coordinator?.scheduleRelationshipRefresh({
        participant,
        reason: detail.reason || 'rendered-size-change',
      });
    },
  });
  applyWebcamPresentationPolicy(merged, 'participant-render');
  refreshLinkClasses();
  positionAvatar(merged);
  applyParticipantAura(merged);
  avatarRuntime?.order?.syncFrontParticipant(merged);
  const pendingVideo = pendingRemoteVideoStreams.get(Number(merged.id));
  if (pendingVideo) {
    const pendingStream = pendingVideo.stream || pendingVideo;
    attachParticipantVideo(
      merged.id,
      pendingStream,
      Boolean(pendingVideo.own ?? (Number(merged.id) === Number(cfg.myParticipantId))),
      pendingVideo.presentationIdentity || { source: 'pending-participant-render' }
    );
  }
  if (Number(merged.id) === Number(cfg.myParticipantId)) {
    syncLocalWebcamPreview('participant-render');
  }
  gameRuntime?.webcams?.reconcile?.({ reason: 'participant-rendered' });
  if (wasWebcam && !nowWebcam) {
    recordVoiceLifecycleDiagnostic({
      event: 'avatar-fallback-detected',
      participantId: Number(merged.id),
      reason: 'renderParticipant-webcam-state-false',
      hasVideoElement: Boolean(merged.webcamVideoEl),
      videoSrcObjectTrackCount: merged.webcamVideoEl?.srcObject?.getTracks?.().length || 0,
    });
  }
  if (options.animateJoin) runAvatarPixelEffect(merged, 'in');
  avatarRuntime?.p2pAvatar?.reconcileParticipant(merged);
  renderPeople();
  renderLinkTabs();
}

function removeStagePresence(person) {
  avatarRuntime?.renderer?.removeStagePresence(person, {
    document,
  });
}

function removeParticipant(participantId, options = {}) {
  const id = Number(participantId);
  const person = participants.get(id);
  if (!person) return Promise.resolve();
  avatarRuntime?.p2pAvatar?.clearParticipant(id, 'participant-removed');
  avatarRuntime?.coordinator?.invalidatePendingLinkChoice('participant-removed', [id]);
  participants.clearParticipantTimers(id);
  pendingRemoteVideoStreams.delete(id);
  voiceRuntime?.media?.closePeer(id);
  const finish = () => {
    removeStagePresence(person);
    if (options.keepRecord) {
      participants.update(id, {
        online: false,
        webcam_path: null,
      });
      avatarRuntime?.coordinator?.clearParticipantRelationship(id);
    } else {
      avatarRuntime?.coordinator?.clearParticipantRelationship(id);
      participants.delete(id);
    }
    avatarRuntime?.coordinator?.unlinkFollowersOf(id);
    refreshLinkClasses();
    renderPeople();
    renderLinkTabs();
  };
  if (person.avatarEl && options.animate !== false) {
    return runAvatarPixelEffect(person, 'out').then(finish);
  } else {
    finish();
    return Promise.resolve();
  }
}

async function handleRoomDeleted(payload = {}) {
  if (roomDeleteInProgress) return;
  roomDeleteInProgress = true;
  roomExitInProgress = true;
  try {
    [ctxMenu, textCtxMenu, msgActionMenu, tabCtxMenu, roomMenu, roomActionMenu].forEach(menu => menu?.classList.remove('visible'));
    document.querySelectorAll('.modal.open').forEach(modal => modal.classList.remove('open'));
    addSystemMessage('Aw snap, this room was deleted.');
    const others = [...participants.values()]
      .filter(person => Number(person.id) !== Number(cfg.myParticipantId) && person.avatarEl)
      .sort((a, b) => String(a.display_name || '').localeCompare(String(b.display_name || '')));
    for (const person of others) {
      await removeParticipant(person.id, { keepRecord: true });
    }
    const me = participants.get(Number(cfg.myParticipantId));
    if (me) await removeParticipant(me.id, { keepRecord: true });
  } finally {
    window.location.href = appUrl('/lobby.php?room_deleted=1');
  }
}

function bindModalCloseButtons(buttonIds, closeHandler) {
  buttonIds.forEach(id => document.getElementById(id)?.addEventListener('click', closeHandler));
}

function positionFloatingMenu(menu, x, y) {
  const requestedX = Number.isFinite(Number(x)) ? Number(x) : 8;
  const requestedY = Number.isFinite(Number(y)) ? Number(y) : 8;
  menu.style.position = 'fixed';
  menu.style.left = '8px';
  menu.style.top = '8px';
  const rect = menu.getBoundingClientRect();
  const left = Math.max(8, Math.min(requestedX, window.innerWidth - rect.width - 8));
  const top = Math.max(8, Math.min(requestedY, window.innerHeight - rect.height - 8));
  menu.style.left = `${left}px`;
  menu.style.top = `${top}px`;
  const positioned = menu.getBoundingClientRect();
  const horizontalCorrection = positioned.left < 8
    ? 8 - positioned.left
    : Math.min(0, window.innerWidth - 8 - positioned.right);
  const verticalCorrection = positioned.top < 8
    ? 8 - positioned.top
    : Math.min(0, window.innerHeight - 8 - positioned.bottom);
  if (horizontalCorrection) menu.style.left = `${left + horizontalCorrection}px`;
  if (verticalCorrection) menu.style.top = `${top + verticalCorrection}px`;
}

function isInsideAvatarContextMenu(target) {
  return Boolean(ctxMenu?.contains(target)
    || (ctxOrientationWrap?.classList.contains('open') && ctxOrientationSubmenu?.contains(target))
    || (document.getElementById('ctx-tools-wrap')?.classList.contains('open')
      && document.getElementById('ctx-tools-submenu')?.contains(target)));
}

function closeContextSubmenu(wrap, trigger, submenu) {
  wrap?.classList.remove('open');
  trigger?.setAttribute('aria-expanded', 'false');
  if (submenu && wrap && submenu.parentElement !== wrap) wrap.appendChild(submenu);
  ['display', 'position', 'left', 'top', 'max-height', 'overflow-y', 'visibility']
    .forEach(property => submenu?.style.removeProperty(property));
  const originLeft = ctxMenu?.dataset.submenuOriginLeft;
  if (ctxMenu && originLeft !== undefined) {
    ctxMenu.style.left = originLeft;
    delete ctxMenu.dataset.submenuOriginLeft;
  }
}

function openContextSubmenu(wrap, trigger, submenu) {
  if (!ctxMenu || !wrap || !trigger || !submenu) return;
  wrap.classList.add('open');
  trigger.setAttribute('aria-expanded', 'true');
  if (ctxMenu.dataset.submenuOriginLeft === undefined) {
    ctxMenu.dataset.submenuOriginLeft = ctxMenu.style.left || '8px';
  }
  document.body.appendChild(submenu);
  Object.assign(submenu.style, {
    display: 'block',
    position: 'fixed',
    left: '8px',
    top: '8px',
    visibility: 'hidden',
    maxHeight: 'calc(100vh - 16px)',
    overflowY: 'auto',
  });
  const gap = 8;
  const submenuRect = submenu.getBoundingClientRect();
  let menuRect = ctxMenu.getBoundingClientRect();
  const overflow = menuRect.right + gap + submenuRect.width + gap - window.innerWidth;
  if (overflow > 0) {
    ctxMenu.style.left = `${Math.max(gap, menuRect.left - overflow)}px`;
    menuRect = ctxMenu.getBoundingClientRect();
  }
  const triggerRect = trigger.getBoundingClientRect();
  let left = menuRect.right + gap;
  if (left + submenuRect.width > window.innerWidth - gap) {
    left = Math.max(gap, window.innerWidth - submenuRect.width - gap);
  }
  const top = Math.max(
    gap,
    Math.min(triggerRect.top, window.innerHeight - submenuRect.height - gap)
  );
  submenu.style.left = `${left}px`;
  submenu.style.top = `${top}px`;
  submenu.style.visibility = 'visible';
}

function relationshipCanvasSize() {
  return {
    width: Math.max(1, relationshipCanvas?.clientWidth || roomStage?.clientWidth || 0),
    height: Math.max(1, relationshipCanvas?.clientHeight || roomStage?.clientHeight || 0),
  };
}

function relationshipViewportSize() {
  return {
    width: Math.max(1, roomStage?.clientWidth || 0),
    height: Math.max(1, roomStage?.clientHeight || 0),
  };
}

function roomStageUsesGameScrollOwnership() {
  return Boolean(
    mainEl?.classList.contains('game-surface-visible')
    && roomStage?.querySelector('.game-frame-wrap[data-scroll-owner="room-stage"]')
  );
}

function beginRelationshipCanvasMeasurement() {
  relationshipCanvas?.style.removeProperty('--relationship-canvas-width');
  relationshipCanvas?.style.removeProperty('--relationship-canvas-height');
  relationshipCanvasResolvedWidth = 0;
  relationshipCanvasResolvedHeight = 0;
  if (roomStage && !roomStageUsesGameScrollOwnership()) {
    roomStage.scrollLeft = 0;
    roomStage.scrollTop = 0;
  }
  const viewport = relationshipViewportSize();
  relationshipCanvasMeasurementGeneration += 1;
  relationshipCanvasMeasurementState = Object.freeze({
    generation: relationshipCanvasMeasurementGeneration,
    reset: true,
    viewport: Object.freeze({ ...viewport }),
    requested: null,
    applied: null,
    changed: false,
  });
  return viewport;
}

function setRelationshipCanvasSize(size = {}) {
  const viewport = relationshipViewportSize();
  const width = Math.max(viewport.width, Math.ceil(Number(size.width || 0)));
  const height = Math.max(viewport.height, Math.ceil(Number(size.height || 0)));
  const changed = width !== relationshipCanvasResolvedWidth
    || height !== relationshipCanvasResolvedHeight;
  relationshipCanvasResolvedWidth = width;
  relationshipCanvasResolvedHeight = height;
  relationshipCanvas?.style.setProperty('--relationship-canvas-width', `${width}px`);
  relationshipCanvas?.style.setProperty('--relationship-canvas-height', `${height}px`);
  if (roomStage && !roomStageUsesGameScrollOwnership()) {
    if (width <= roomStage.clientWidth) roomStage.scrollLeft = 0;
    if (height <= roomStage.clientHeight) roomStage.scrollTop = 0;
  }
  relationshipCanvasMeasurementState = Object.freeze({
    generation: relationshipCanvasMeasurementGeneration,
    reset: Boolean(relationshipCanvasMeasurementState.reset),
    viewport: Object.freeze({ ...viewport }),
    requested: Object.freeze({
      width: Number(size.width || 0),
      height: Number(size.height || 0),
    }),
    applied: Object.freeze({ width, height }),
    changed,
  });
  return changed;
}

roomStage?.addEventListener('keydown', event => {
  if (event.target !== roomStage) return;
  const stepByKey = {
    ArrowLeft: { x: -48, y: 0 },
    ArrowRight: { x: 48, y: 0 },
    ArrowUp: { x: 0, y: -48 },
    ArrowDown: { x: 0, y: 48 },
  };
  const step = stepByKey[event.key];
  if (!step) return;
  const maxLeft = Math.max(0, roomStage.scrollWidth - roomStage.clientWidth);
  const maxTop = Math.max(0, roomStage.scrollHeight - roomStage.clientHeight);
  const left = Math.min(maxLeft, Math.max(0, roomStage.scrollLeft + step.x));
  const top = Math.min(maxTop, Math.max(0, roomStage.scrollTop + step.y));
  if (left === roomStage.scrollLeft && top === roomStage.scrollTop) return;
  event.preventDefault();
  roomStage.scrollTo({ left, top, behavior: 'auto' });
});

function relationshipLayoutVerificationSnapshot() {
  const relationshipPresentations = avatarRuntime?.relationships
    ?.relationshipPresentations?.() || [];
  return {
    canvasMeasurement: relationshipCanvasMeasurementState,
    runtimeDiagnostics: avatarRuntime?.getDiagnostics?.() || null,
    relationships: relationshipPresentations.map(presentation => {
      const relationship = avatarRuntime?.relationships
        ?.relationshipById?.(presentation.relationshipId) || null;
      const members = presentation.visibleMemberIds.map(participantId => {
        const participant = participants.get(Number(participantId));
        const dimensions = participant
          ? avatarRuntime?.renderer?.renderedAvatarDimensions(participant, {
            document,
            window,
          })
          : null;
        return {
          participantId: Number(participantId),
          position: participant ? {
            x: Number(participant.position_x || 0),
            y: Number(participant.position_y || 0),
          } : null,
          dimensions,
          webcamEnabled: Boolean(participant?.webcam_enabled),
          webcamPresented: Boolean(participant?.webcamVideoEl),
          avatarHiddenPlaceholder: Boolean(
            participant?.avatarSourceRedacted
            || participant?.avatar_source_redacted
            || participant?.avatar_hidden_placeholder
          ),
        };
      });
      return {
        relationshipId: presentation.relationshipId,
        relationshipVersion: Number(relationship?.version || 0),
        selectedFormation: relationship?.options?.formation || 'horizontal-row',
        effectiveFormation: avatarRuntime?.layout?.getDiagnostics?.()
          ?.lastRelationshipStrategy?.effectiveFormation || null,
        rowSpacing: Number(relationship?.options?.rowSpacing || 0),
        normalMemberOrder: presentation.visibleNormalMembers
          .map(member => Number(member.participantId)),
        lapMembers: presentation.visibleLapMembers.map(member => ({
          participantId: Number(member.participantId),
          hostParticipantId: Number(member.lapHostParticipantId),
          lapSide: member.lapSide,
        })),
        members,
      };
    }),
  };
}

function participantStage(participant) {
  const relationship = participant
    ? avatarRuntime?.relationships?.relationshipPresentationForParticipant(participant.id)
    : null;
  return relationship ? relationshipCanvas : avatarViewportLayer || roomStage;
}

function scheduleAvatarViewportReflow() {
  if (avatarViewportReflowFrame !== null) return;
  avatarViewportReflowFrame = window.requestAnimationFrame(() => {
    avatarViewportReflowFrame = null;
    if ([...document.querySelectorAll('img.avatar')]
      .some(avatar => avatar.style.cursor === 'grabbing')) return;
    const groups = new Map();
    participants.forEach(person => {
      if (!person?.avatarEl || !person?.labelEl || avatarRuntime?.relationships?.isLinked(person)) return;
      const stage = participantStage(person);
      if (!stage || person.avatarEl.isConnected === false) return;
      const list = groups.get(stage) || [];
      list.push(person);
      groups.set(stage, list);
    });

    groups.forEach((people, stage) => {
      const stageWidth = Number(stage.clientWidth || 0);
      const stageHeight = Number(stage.clientHeight || 0);
      if (people.length < 5 || stageWidth > 1100 || stageWidth <= 0 || stageHeight <= 0) return;

      const desired = people.map(person => {
        const dimensions = avatarRenderedDimensions(person);
        return {
          person,
          dimensions,
          x: Math.max(0, Math.min(stageWidth - dimensions.width, Number(person.position_x || 0) * stageWidth)),
          y: Math.max(0, Math.min(stageHeight - dimensions.height, Number(person.position_y || 0) * stageHeight)),
        };
      });
      const overlaps = desired.some((first, index) => desired.slice(index + 1).some(second => (
        first.x < second.x + second.dimensions.width
        && first.x + first.dimensions.width > second.x
        && first.y < second.y + second.dimensions.height + 22
        && first.y + first.dimensions.height + 22 > second.y
      )));
      if (!overlaps) return;

      const gap = 8;
      const columns = Math.max(2, Math.min(
        people.length,
        Math.ceil(Math.sqrt(people.length * stageWidth / Math.max(stageHeight, 1)))
      ));
      const rows = Math.ceil(people.length / columns);
      const cellWidth = stageWidth / columns;
      const cellHeight = stageHeight / rows;
      const maximumEdge = Math.max(44, Math.min(92, cellWidth - gap * 2, cellHeight - 30));
      const availableCells = Array.from({ length: people.length }, (_, index) => ({
        index,
        column: index % columns,
        row: Math.floor(index / columns),
      }));

      desired.sort((a, b) => Number(a.person.id || 0) - Number(b.person.id || 0)).forEach(entry => {
        const desiredCenterX = entry.x + entry.dimensions.width / 2;
        const desiredCenterY = entry.y + entry.dimensions.height / 2;
        availableCells.sort((a, b) => {
          const distance = cell => Math.abs((cell.column + .5) * cellWidth - desiredCenterX)
            + Math.abs((cell.row + .5) * cellHeight - desiredCenterY);
          return distance(a) - distance(b) || a.index - b.index;
        });
        const cell = availableCells.shift();
        const sourceEdge = Math.max(entry.dimensions.width, entry.dimensions.height, 1);
        const scale = Math.min(1, maximumEdge / sourceEdge);
        const width = Math.max(1, Math.round(entry.dimensions.width * scale));
        const height = Math.max(1, Math.round(entry.dimensions.height * scale));
        const frame = {
          width,
          height,
          x: Math.max(0, Math.min(stageWidth - width, (cell.column + .5) * cellWidth - width / 2)),
          y: Math.max(0, Math.min(stageHeight - height - 22, (cell.row + .5) * cellHeight - (height + 22) / 2)),
        };
        avatarRuntime?.renderer?.applyParticipantFrame(entry.person, frame, { stage });
        entry.person.labelEl.style.maxWidth = `${Math.max(40, Math.floor(cellWidth - gap * 2))}px`;
        entry.person.labelEl.style.overflow = 'hidden';
        entry.person.labelEl.style.textOverflow = 'ellipsis';
      });
      stage.dataset.avatarDensityLayout = 'compact';
    });
  });
}

function positionAvatar(p) {
  const img = p.avatarEl;
  const label = p.labelEl;
  if (!img || !label) return;
  
  const stage = participantStage(p);
  avatarRuntime?.renderer?.syncParticipantStage(p, stage);
  const w = stage?.clientWidth || roomStage.clientWidth;
  const h = stage?.clientHeight || roomStage.clientHeight;
  const dimensions = avatarRenderedDimensions(p);
  const frame = avatarRuntime?.layout?.avatarFrame(p, {
    stageWidth: w,
    stageHeight: h,
    baseSize: AVATAR_STAGE_SIZE,
    dimensions,
  }) || {
    width: dimensions.width,
    height: dimensions.height,
    x: Math.max(0, Math.min(w - dimensions.width, p.position_x * w)),
    y: Math.max(0, Math.min(h - dimensions.height, p.position_y * h)),
  };
  avatarRuntime?.renderer?.applyParticipantFrame(p, frame, {
    stage,
  });
  scheduleAvatarViewportReflow();
  updateStageLinkIcons();
}

function refreshLinkClasses() {
  participants.forEach(p => {
    avatarRuntime?.renderer?.syncLinkedClass(
      p,
      avatarRuntime?.relationships?.isLinked(p) || false
    );
  });
  updateStageLinkIcons();
}

function pulseParticipantAvatar(participantId) {
  const person = participants.get(Number(participantId));
  if (Number(participantId) === Number(cfg.myParticipantId)) {
    avatarRuntime?.order?.bringParticipantToFront(person);
  }
  avatarRuntime?.effects?.pulseParticipant(person, {
    window,
  });
}

function linkedPairs() {
  return avatarRuntime?.relationships?.linkedPairs() || [];
}

function updateStageLinkIcons() {
  if (!cfg) return;
  avatarRuntime?.renderer?.syncStageLinkIcons(linkedPairs(), {
    stage: relationshipCanvas,
    document,
    window,
    appUrl,
    linkIconCatalog: cfg.linkIconCatalog,
    linkModeForPair,
    linkIconNameForStage(key) {
      return avatarRuntime?.coordinator?.linkIconNameForStage(key) || '';
    },
  });
}

function stagePointFromElement(el) {
  if (!el || !roomStage || !el.isConnected) return null;
  const stageRect = roomStage.getBoundingClientRect();
  const rect = el.getBoundingClientRect();
  return {
    x: rect.left - stageRect.left + rect.width / 2,
    y: rect.top - stageRect.top + rect.height / 2,
  };
}

function dmFlightPointForUser(userId) {
  const person = participantByUserId(userId);
  const elementPoint = stagePointFromElement(person?.avatarEl)
    || stagePointFromElement([...roomStage.querySelectorAll('.avatar')].find(el => Number(el.dataset.participantId) === Number(person?.id)));
  if (elementPoint) return elementPoint;
  if (person && Number.isFinite(Number(person.position_x)) && Number.isFinite(Number(person.position_y))) {
    const dimensions = avatarRenderedDimensions(person);
    return {
      x: Math.max(0, Math.min(roomStage.clientWidth, Number(person.position_x) * roomStage.clientWidth + dimensions.width / 2)),
      y: Math.max(0, Math.min(roomStage.clientHeight, Number(person.position_y) * roomStage.clientHeight + dimensions.height / 2)),
    };
  }
  return null;
}

function dmFlightPointForCurrentUser() {
  return dmFlightPointForUser(cfg.myUserId);
}

function dmFlightResolvedPoints(fromUserId, toUserId) {
  const corner = dmFlightCornerPoint();
  const fromPoint = dmFlightPointForUser(fromUserId);
  const toPoint = dmFlightPointForUser(toUserId);
  if (fromUserId === cfg.myUserId) {
    return {
      start: fromPoint || dmFlightPointForCurrentUser() || corner,
      end: toPoint || corner,
    };
  }
  if (toUserId === cfg.myUserId) {
    return {
      start: fromPoint || corner,
      end: toPoint || dmFlightPointForCurrentUser() || corner,
    };
  }
  return {
    start: fromPoint || corner,
    end: toPoint || corner,
  };
}

function dmFlightCornerPoint() {
  return {
    x: Math.max(42, roomStage.clientWidth - 46),
    y: 42,
  };
}

function dmFlightTransform(angle, flip, scale = 1) {
  return `translate(-50%, -50%) rotate(${angle}rad) scaleX(${flip}) scale(${scale})`;
}

function showDmFlight(payload) {
  if (!cfg || !roomStage || !payload) return;
  const messageId = payload.id || payload.message_id;
  if (messageId && animatedDmMessageIds.has(Number(messageId))) return;
  const fromUserId = Number(payload.user_id);
  const toUserId = Number(payload.target_user_id);
  if (!fromUserId || !toUserId) return;
  if (fromUserId !== cfg.myUserId && toUserId !== cfg.myUserId) return;
  if (isUserBlocked(fromUserId) || isUserBlocked(toUserId)) return;
  if (messageId) animatedDmMessageIds.add(Number(messageId));

  const { start, end } = dmFlightResolvedPoints(fromUserId, toUserId);
  if (Math.abs(start.x - end.x) < 2 && Math.abs(start.y - end.y) < 2) return;

  const dx = end.x - start.x;
  const dy = end.y - start.y;
  const travelAngle = Math.atan2(dy, dx);
  const flip = dx < 0 ? -1 : 1;
  const visualAngle = dx < 0 ? Math.PI - travelAngle : travelAngle;
  const distance = Math.hypot(dx, dy);
  const duration = Math.max(700, Math.min(1250, distance * 1.25));

  const img = document.createElement('img');
  img.className = 'dm-flight';
  img.src = appUrl('/assets/images/flying-dm.png');
  img.alt = '';
  img.style.left = `${start.x}px`;
  img.style.top = `${start.y}px`;
  img.style.transform = dmFlightTransform(visualAngle, flip, .88);
  (avatarViewportLayer || roomStage).appendChild(img);

  const keyframes = [
    { left: `${start.x}px`, top: `${start.y}px`, opacity: 0, transform: dmFlightTransform(visualAngle, flip, .72) },
    { opacity: 1, offset: .14, transform: dmFlightTransform(visualAngle, flip, 1.05) },
    { left: `${end.x}px`, top: `${end.y}px`, opacity: 1, transform: dmFlightTransform(visualAngle, flip, .95), offset: .86 },
    { left: `${end.x}px`, top: `${end.y}px`, opacity: 0, transform: dmFlightTransform(visualAngle, flip, .52) },
  ];
  const timing = { duration, easing: 'cubic-bezier(.2,.78,.2,1)', fill: 'forwards' };
  if (typeof img.animate === 'function') {
    img.animate(keyframes, timing).addEventListener('finish', () => img.remove(), { once: true });
  } else {
    setTimeout(() => img.remove(), duration);
  }
}

function refreshRelationship(initiator, target, animate = true, persist = false) {
  avatarRuntime?.coordinator?.refreshRelationship(initiator, target, { animate, persist });
}

function adjustLinkedPairForIcon(linkKey, animate = true, persist = false) {
  avatarRuntime?.coordinator?.adjustLinkedPairForIcon(linkKey, { animate, persist });
}

function snapLinkedPair(initiator, target, animate = true) {
  refreshRelationship(initiator, target, animate, false);
}

function snapLappedPair(initiator, target, animate = true) {
  refreshRelationship(initiator, target, animate, false);
}

function closeLinkChoiceModal() {
  linkChoiceModal?.classList.remove('open');
  resetLinkChoiceModal();
}

function resetLinkChoiceModal() {
  const actions = document.getElementById('link-choice-actions');
  const seats = document.getElementById('link-choice-seat');
  const prompt = document.getElementById('link-choice-prompt');
  if (actions) actions.hidden = false;
  if (seats) seats.hidden = true;
  if (prompt) prompt.textContent = 'What would you like to do?';
}

async function completePendingLinkChoice(mode, lapSide = null) {
  await avatarRuntime?.coordinator?.completePendingLinkChoice(mode, lapSide);
}

function makeDraggable(img) {
  avatarRuntime?.drag?.attachDraggable(img);
}

function rebuildLinkGroups() {
  avatarRuntime?.coordinator?.rebuildLinkGroups();
}

function renderPeople() {
  const configuredCurrentParticipant = (cfg?.participants || []).find(person =>
    Number(person?.id) === Number(cfg?.myParticipantId)) || null;

  if (participants && participants.size > 0) {
    rebuildLinkGroups();
  }

  if ((!participants || participants.size === 0) && !configuredCurrentParticipant) {
    userListEl.replaceChildren();
    document.getElementById('participant-count-label').textContent = '(0)';
    return;
  }

  const participantSource = participants ? [...participants.values()] : [];
  if (configuredCurrentParticipant
      && !participantSource.some(person => Number(person?.id) === Number(configuredCurrentParticipant.id))) {
    participantSource.push(configuredCurrentParticipant);
  }
  const orderedPeople = avatarRuntime?.order?.visibleParticipants(participantSource) || participantSource
    .filter(p => p && typeof p === 'object' && p.id)
    .sort((a, b) =>
      (a.display_name || '').localeCompare(b.display_name || '')
    );
  const people = [...orderedPeople].sort((a, b) =>
    Number(Number(b.id) === Number(cfg.myParticipantId))
      - Number(Number(a.id) === Number(cfg.myParticipantId))
  );
  document.getElementById('participant-count-label').textContent = `(${people.length})`;
  const rendered = new Set();
  const desiredRows = [];
  const roleClass = participantRoleClass;
  const makePersonBits = p => {
    const game = gameRuntime?.lifecycle?.gameForParticipant(p.id);
    const gameBadge = game ? `<span class="user-game-badge" title="${esc(gameName(game.game_type))}"><img src="${esc(gameIconUrl(game.game_type))}" alt=""></span>` : '';
    const nameIcon = game ? `<img class="person-game-name-icon" src="${esc(gameIconUrl(game.game_type))}" alt="" title="${esc(gameName(game.game_type))}">` : '';
    const presence = Number(p.id) === Number(cfg.myParticipantId) || p.online
      ? ''
      : '<div class="minor">Away</div>';
    return `<span class="user-avatar-wrap">${avatarPresentationHtml(p, { displayName: displayNameFor(p), title: false })}<span class="status-dot ${p.online ? 'on' : ''}" role="img" aria-label="${p.online ? 'Online' : 'Away'}" title="${p.online ? 'Online' : 'Away'}"></span>${gameBadge}</span><div class="person-identity"><strong class="person-name-line person-nameplate">${nameIcon}<span class="person-name-text">${esc(displayNameFor(p) || '')}</span>${participantRoleBadgeHtml(p)}</strong>${presence}</div>`;
  };
  const updatePersonBits = (element, person) => {
    const signature = makePersonBits(person);
    if (element.__corechatPersonBitsSignature !== signature) {
      element.innerHTML = signature;
      element.__corechatPersonBitsSignature = signature;
    }
    applyIdentityNameplate(element, person);
  };
  const findReusableRow = key => [...userListEl.children].find(row => row.dataset?.peopleRowKey === key) || null;
people.forEach(p => {
  // optional but safe (prevents flicker states)
  const name = p.display_name || '';

  if (rendered.has(p.id)) return;

  const presentation = avatarRuntime?.relationships?.relationshipPresentationForParticipant(p.id) || null;
  const presentationGroup = presentation?.visibleMemberIds
    ?.map(participantId => participants.get(Number(participantId)))
    .filter(Boolean) || [];

if (presentationGroup.length > 1 && !presentationGroup.some(member => rendered.has(member.id))) {

  const orderedGroup = avatarRuntime?.order?.orderLinkedGroup(
    presentationGroup,
    presentation.visibleMemberIds
  ) || presentationGroup;

  const rowKey = `linked:${orderedGroup.map(member => Number(member.id)).join(',')}`;
  const row = findReusableRow(rowKey) || document.createElement('div');
  row.className = 'person-row linked-row';
  row.dataset.peopleRowKey = rowKey;

  orderedGroup.forEach(member => {
    rendered.add(member.id);
    let half = [...row.children].find(candidate => Number(candidate.dataset?.participantId) === Number(member.id)) || null;
    if (!half) {
      half = document.createElement('div');
      half.dataset.participantId = String(member.id);
      half.style.touchAction = 'none';
      half.style.cursor = 'grab';
    }
    half.className = `linked-half ${roleClass(member)}`;
    if (!half.__corechatLocateListenerAttached) {
      half.addEventListener('click', () => pulseParticipantAvatar(Number(half.dataset.participantId)));
      half.addEventListener('contextmenu', event => {
        event.preventDefault();
        event.stopPropagation();
        const current = participants.get(Number(half.dataset.participantId));
        if (current) openAvatarContextMenu(event.clientX, event.clientY, current);
      });
      half.__corechatLocateListenerAttached = true;
    }
    updatePersonBits(half, member);
    row.appendChild(half);
  });
  [...row.children].forEach(half => {
    if (!orderedGroup.some(member => Number(member.id) === Number(half.dataset?.participantId))) half.remove();
  });
  desiredRows.push(row);
  return;
}
    rendered.add(p.id);
    const rowKey = `person:${Number(p.id)}`;
    const row = findReusableRow(rowKey) || document.createElement('div');
    row.className = `person-row ${roleClass(p)}`;
    row.dataset.participantId = String(p.id);
    row.dataset.peopleRowKey = rowKey;
    updatePersonBits(row, p);
    if (!row.__corechatPeopleListenersAttached) {
      row.addEventListener('click', () => pulseParticipantAvatar(Number(row.dataset.participantId)));
      row.addEventListener('contextmenu', e => {
        e.preventDefault();
        e.stopPropagation();
        const current = participants.get(Number(row.dataset.participantId));
        if (current) openAvatarContextMenu(e.clientX, e.clientY, current);
      });
      row.__corechatPeopleListenersAttached = true;
    }
    desiredRows.push(row);
  });
  desiredRows.forEach(row => userListEl.appendChild(row));
  [...userListEl.children].forEach(row => {
    if (!desiredRows.includes(row)) row.remove();
  });
}

function openLinkIconModal(targetId) {
  pendingLinkIconTargetId = Number(targetId);
  if (!pendingLinkIconTargetId) return;
  const linkKey = linkKeyFor(cfg.myParticipantId, pendingLinkIconTargetId);
  const current = avatarRuntime?.coordinator?.linkIconName(linkKey) || 'plus';
  linkIconGrid.querySelectorAll('[data-link-icon]').forEach(btn => {
    btn.classList.toggle('selected', btn.dataset.linkIcon === current);
  });
  linkIconModal.classList.add('open');
}

function closeLinkIconModal() {
  linkIconModal.classList.remove('open');
  pendingLinkIconTargetId = null;
}

function linkedPartner() {
  return avatarRuntime?.relationships?.linkedPartner(cfg.myParticipantId) || null;
}

function renderLinkTabs() {
  const holder = document.getElementById('link-tabs');
  if (!holder || !cfg) return;
  holder.innerHTML = '';
  renderGameTab(holder);
  const relationship = avatarRuntime?.relationships?.relationshipForParticipant(cfg.myParticipantId) || null;
  const relationshipChat = chatPrivateChats().syncRelationshipChat(
    relationship,
    cfg.relationshipChat?.relationshipId === relationship?.id ? cfg.relationshipChat : null
  );
  if (!relationshipChat) {
    renderDmTabs();
    updateTabBadges();
    return;
  }
  const partner = { display_name: chatPrivateChats().relationshipLabel() };
  const tab = document.createElement('button');
  tab.className = 'chat-tab';
  tab.type = 'button';
  tab.dataset.chatTab = relationshipChat.chatKey;
  tab.innerHTML = `<span class="link-tab-heart">🤍</span><span>Link&gt; ${esc(partner.display_name)}</span><span class="tab-badge" hidden>0</span>`;
  tab.addEventListener('click', () => switchChat(tab.dataset.chatTab));
  holder.appendChild(tab);
  renderDmTabs();
  updateTabBadges();
  document.querySelectorAll('.chat-tab').forEach(item => {
    item.classList.toggle('active', item.dataset.chatTab === activeChatKey());
  });
}

function renderGameTab(holder = document.getElementById('link-tabs')) {
  const activeGame = gameRuntime?.lifecycle?.getActiveGame();
  if (!holder || !activeGame) {
    if (activeChatKey().startsWith('game:')) switchChat('room');
    return;
  }
  const chatKey = `game:${activeGame.lobby_code}`;
  const tab = document.createElement('button');
  tab.className = 'chat-tab';
  tab.type = 'button';
  tab.dataset.chatTab = chatKey;
  tab.innerHTML = `<img src="${esc(appUrl('/assets/images/chat-pane-game.png'))}" alt=""><span>Game</span><span class="tab-badge" hidden>0</span>`;
  tab.addEventListener('click', () => switchChat(chatKey));
  holder.appendChild(tab);
}

function renderDmTabs() {
  const holder = document.getElementById('link-tabs');
  if (!holder || !cfg) return;
  for (const user of chatPrivateChats().visibleDmUsers()) {
    const chatKey = `dm:${user.id}`;
    if (holder.querySelector(`[data-chat-tab="${chatKey}"]`)) continue;
    const tab = document.createElement('button');
    tab.className = 'chat-tab';
    tab.type = 'button';
    tab.dataset.chatTab = chatKey;
    tab.innerHTML = `<img src="${esc(appUrl('/assets/images/chat-pane-dm.png'))}" alt=""><span>DM&gt; ${esc(isUserBlocked(user.id) ? 'Blocked' : user.display_name)}</span><span class="tab-badge" hidden>0</span>`;
    tab.addEventListener('click', () => switchChat(chatKey));
    holder.appendChild(tab);
  }
}

function updateTabBadges() {
  document.querySelectorAll('.chat-tab[data-chat-tab]').forEach(tab => {
    const count = chatUnread().unreadCountFor(tab.dataset.chatTab);
    const badge = tab.querySelector('.tab-badge');
    if (!badge) return;
    badge.hidden = count <= 0;
    badge.textContent = count > 99 ? '99+' : String(count);
  });
}

function clearUnread(chatKey) {
  chatUnread().clear(chatKey);
}

function switchChat(chatKey) {
  const switched = chatNavigation().switchChat(chatKey);
  const conversation = messageProtectionConversation(chatKey);
  if (conversation && ['dm', 'link', 'game'].includes(conversation.kind)) {
    messageProtectionFetchContext(conversation, '', true)
      .then(context => messageProtectionUpdatePolicy(context.conversation?.policy))
      .catch(warnRuntimeRequest);
  }
  return switched;
}

document.querySelectorAll('.chat-tab[data-chat-tab]').forEach(tab => {
  tab.addEventListener('click', () => switchChat(tab.dataset.chatTab));
});

function messagesNearBottom() {
  return chatMessageRenderer().messagesNearBottom();
}

function shouldAutoScrollMessages() {
  return chatMessageRenderer().shouldAutoScrollMessages();
}

function scrollMessagesToBottom() {
  chatMessageRenderer().scrollMessagesToBottom();
}

function bindMessageAutoScroll(row, shouldStick) {
  chatMessageRenderer().bindMessageAutoScroll(row, shouldStick);
}

messagesEl.addEventListener('scroll', () => {
  chatMessageRenderer()?.syncPinnedToBottom();
});

function renderActiveChat() {
  chatNavigation().renderActiveChat();
}

function addMessageToChannel(msg, chatKey, live = false) {
  if (msg?.protection_mode === 'e2ee-private' && !msg._messageProtectionResolved) {
    const pendingKey = `${chatKey}:${msg.client_message_id || msg.id || ''}`;
    if (messageProtectionPending.has(pendingKey)) return;
    messageProtectionPending.add(pendingKey);
    messageProtectionDecryptMessage(msg, chatKey)
      .then(decrypted => addMessageToChannel(decrypted, chatKey, live))
      .catch(error => addMessageToChannel({
        ...msg,
        content: `Encrypted message unavailable on this device. ${error.message || ''}`.trim(),
        url_preview: null,
        reply_to: null,
        _messageProtectionResolved: true,
        protection_resolution_failed: true,
      }, chatKey, live))
      .finally(() => messageProtectionPending.delete(pendingKey));
    return;
  }
  if (!messageVisible(msg)) return;
  const mutedPolicy = activeMutedPolicyFor(Number(msg.user_id || participants.get(msg.participant_id)?.user_id || 0));
  msg = applyPersonalMutePresentation(msg, chatKey);
  const activeChat = activeChatKey();
  const result = chatMessageState().addMessageToChannel(msg, chatKey);
  const existing = result.existing;
  const storedMessage = result.message || msg;
  chatRuntime?.notifications?.consider(storedMessage, chatKey, {
    live, existing: Boolean(existing),
    suppressed: Boolean(mutedPolicy && mutedPolicy.scopes.includes('notices-unread')),
  });
  if (existing && chatKey === activeChat) {
    renderActiveChat();
    return;
  }
  if (chatKey === activeChat) {
    const shouldStick = shouldAutoScrollMessages();
    const row = appendMessageEl(storedMessage);
    if (shouldStick) {
      scrollMessagesToBottom();
      bindMessageAutoScroll(row, true);
    }
    clearUnread(chatKey);
  } else if (!(mutedPolicy && mutedPolicy.scopes.includes('notices-unread'))) {
    chatUnread().recordInactiveLiveMessage(msg, chatKey, { live });
  }
  if (live && chatKey === 'room' && msg.participant_id
    && !(mutedPolicy && mutedPolicy.scopes.includes('gestures-audio'))) {
    showTyping(msg.participant_id, false);
    showAvatarSpeech(msg.participant_id, msg);
  }
  if (live && chatKey.startsWith('dm:')) showDmFlight(msg);
}

function updateMessageInChannels(messageId, changes) {
  chatMessageState().updateRoomMessage(messageId, changes);
  if (activeChatKey() === 'room') renderActiveChat();
}

function removeMessageFromChannels(messageId) {
  chatMessageState().removeRoomMessage(messageId);
  if (activeChatKey() === 'room') renderActiveChat();
}

function animateRoomHistoryClear() {
  if (activeChatKey() !== 'room') return;
  chatMessageRenderer().animateRoomHistoryClear({
    onRender() {
      if (activeChatKey() === 'room') renderActiveChat();
    },
  });
}

function handleRoomHistoryClear(payload = {}) {
  const clearId = payload.clear_id || `${payload.cleared_at || Date.now()}`;
  if (seenRoomHistoryClears.has(clearId)) return;
  seenRoomHistoryClears.add(clearId);
  chatMessageState().clearRoomMessages();
  clearUnread('room');
  if (activeChatKey() === 'room') animateRoomHistoryClear();
}

function updateMessageInChannel(chatKey, messageId, changes) {
  chatMessageState().updateMessageInChannel(chatKey, messageId, changes);
  if (chatKey === activeChatKey()) renderActiveChat();
}

function removeMessageFromChannel(chatKey, messageId) {
  chatMessageState().removeMessageFromChannel(chatKey, messageId);
  if (chatKey === activeChatKey()) renderActiveChat();
}

function formatBytes(bytes) {
  const size = Number(bytes || 0);
  if (size < 1024) return `${size} B`;
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`;
  return `${(size / (1024 * 1024)).toFixed(1)} MB`;
}

function messageSpeechText(msg) {
  if (msg.message_type === 'voice_note') return 'sent a voice note';
  if (msg.message_type === 'file') return msg.original_name ? `sent ${msg.original_name}` : 'sent a file';
  if (msg.message_type === 'gif') return 'sent a GIF';
  if (msg.message_type === 'gesture') return gesturePresentation?.canonicalText(gestureFromMessage(msg)) || '(Gesture)';
  return msg.content;
}

function messagePreviewText(msg) {
  const raw = messageSpeechText(msg) || 'Message';
  return String(raw).replace(/\s+/g, ' ').trim().slice(0, 180) || 'Message';
}

function renderReplyDraft() {
  if (!replyDraftEl) return;
  const draft = chatReply().draftForChat(activeChatKey());
  replyDraftEl.hidden = !draft;
  if (!draft) return;
  if (replyDraftAuthorEl) replyDraftAuthorEl.textContent = `Replying to ${draft.display_name || 'Someone'}`;
  if (replyDraftPreviewEl) replyDraftPreviewEl.textContent = draft.preview || 'Message';
}

function clearReplyDraft() {
  chatReply().clearDraft();
}

function startReplyDraft(msg, chatKey = activeChatKey()) {
  chatReply().startDraft(msg, chatKey);
}

function appendReplyPayload(payload) {
  return chatReply().appendReplyPayload(payload, activeChatKey());
}

function appendReplyFormData(formData) {
  const payload = appendReplyPayload({});
  if (!payload.reply_to_id) return;
  formData.append('reply_to_id', String(payload.reply_to_id));
  formData.append('reply_to_channel', payload.reply_to_channel);
}

function replyPreviewHtml(msg) {
  return chatMessageRenderer().replyPreviewHtml(msg);
}

function jumpToMessage(messageId) {
  chatMessageRenderer().jumpToMessage(messageId);
}

function gestureFromMessage(msg) {
  if (!msg || msg.message_type !== 'gesture') return null;
  if (msg.gesture && typeof msg.gesture === 'object') return msg.gesture;
  try {
    const parsed = JSON.parse(msg.content || '{}');
    return parsed && typeof parsed === 'object' ? parsed : null;
  } catch {
    return null;
  }
}

function messageBodyHtml(msg) {
  return chatMessageRenderer().messageBodyHtml(msg);
}

function gifDelayCentiseconds(bytes, offset) {
  return bytes[offset] | (bytes[offset + 1] << 8);
}

async function gifLoopDurationMs(url) {
  const safeUrl = mediaUrl(url);
  if (gifDurationCache.has(safeUrl)) return gifDurationCache.get(safeUrl);
  let duration = 3200;
  try {
    const buffer = await fetch(safeUrl, { cache: 'force-cache' }).then(r => r.arrayBuffer());
    const bytes = new Uint8Array(buffer);
    let total = 0;
    for (let i = 0; i < bytes.length - 9; i += 1) {
      if (bytes[i] === 0x21 && bytes[i + 1] === 0xf9 && bytes[i + 2] === 0x04) {
        const delay = gifDelayCentiseconds(bytes, i + 4);
        total += Math.max(delay, 2) * 10;
        i += 7;
      }
    }
    if (total > 0) duration = Math.max(900, Math.min(total, 7000));
  } catch (err) {
    duration = 3200;
  }
  gifDurationCache.set(safeUrl, duration);
  return duration;
}

function parseServerDate(value) {
  return chatMessageState().parseServerDate(value);
}

function messageSortMs(msg) {
  return chatMessageState().messageSortMs(msg);
}

function compareMessages(a, b) {
  return chatMessageState().compareMessages(a, b);
}

function fullTimestamp(value) {
  const date = parseServerDate(value);
  if (!date || Number.isNaN(date.getTime())) return '';
  const now = new Date();
  const local = new Date(date.getFullYear(), date.getMonth(), date.getDate());
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const diffDays = Math.round((today - local) / 86400000);
  const time = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  if (diffDays === 0) return `Today, at ${time}`;
  if (diffDays === 1) return `Yesterday, at ${time}`;
  return `${date.toLocaleDateString([], { month: 'long', day: 'numeric', year: 'numeric' })}, at ${time}`;
}

function updateVisibleTimestamps() {
  document.querySelectorAll('[data-ts]').forEach(el => {
    el.textContent = `${el.dataset.prefix || ''}${chatMessageRenderer().formatTimestamp(el.dataset.ts)}`;
  });
}

function renderReactions(msg) {
  return chatMessageRenderer().renderReactions(msg);
}

function cleanupAuraLayer(layer) {
  return avatarRuntime?.aura?.cleanupLayer(layer) || null;
}

async function applyAuraToLayer(layer, key) {
  await avatarRuntime?.aura?.applyToLayer(layer, key);
}

function applyParticipantAura(person) {
  avatarRuntime?.aura?.applyParticipantAura(person)?.catch(console.warn);
}

function renderRoomEffectsModal() {
  const select = document.getElementById('room-effect-select');
  const current = document.getElementById('room-effect-current');
  const stop = document.getElementById('room-effect-stop');
  if (!select || !current) return;
  const effects = cfg.roomEffects || [];
  select.innerHTML = effects.length
    ? effects.map(effect => `<option value="${esc(effect.key)}">${esc(effect.label)}</option>`).join('')
    : '<option value="">No effects installed</option>';
  select.disabled = effects.length === 0;
  if (cfg.activeRoomEffect?.active) {
    current.innerHTML = `<strong>Current:</strong> ${esc(cfg.activeRoomEffect.label)}${cfg.activeRoomEffect.expires_at ? `<div class="minor">Ends ${esc(fullTimestamp(cfg.activeRoomEffect.expires_at))}</div>` : '<div class="minor">Runs until disabled.</div>'}`;
    if (select.querySelector(`option[value="${CSS.escape(cfg.activeRoomEffect.effect_key)}"]`)) select.value = cfg.activeRoomEffect.effect_key;
    if (stop) stop.hidden = false;
  } else {
    current.innerHTML = '<span class="minor">No room effect is active.</span>';
    if (stop) stop.hidden = true;
  }
}

function renderAuraOptions() {
  if (!auraOptionsEl) return;
  const selectedAuraKey = avatarRuntime?.aura?.selectedKey() || '';
  const items = [{ key: '', label: 'None' }, ...(avatarRuntime?.aura?.catalog() || [])];
  auraOptionsEl.innerHTML = items.map(aura => `
    <button class="aura-option${(selectedAuraKey || '') === aura.key ? ' selected' : ''}" type="button" data-aura-key="${esc(aura.key)}">
      <span class="aura-option-thumb">${aura.key ? '<span class="aura-mini-spark">✦</span>' : '<span class="aura-none">None</span>'}</span>
      <span>${esc(aura.label)}</span>
    </button>
  `).join('');
}

async function previewAura(key) {
  avatarRuntime?.aura?.setSelectedKey(key || '');
  renderAuraOptions();
  const me = participants.get(cfg.myParticipantId);
  if (auraPreviewAvatar && me) auraPreviewAvatar.src = avatarUrl(me);
  await applyAuraToLayer(auraPreviewLayer, avatarRuntime?.aura?.selectedKey() || '');
}

async function openAuraModal() {
  closeContextMenu();
  const me = participants.get(cfg.myParticipantId);
  if (!me) return;
  try {
    await avatarRuntime?.aura?.prepareSelection(me);
    renderAuraOptions();
    if (auraPreviewAvatar) auraPreviewAvatar.src = avatarUrl(me);
    auraModal?.classList.add('open');
    await applyAuraToLayer(auraPreviewLayer, avatarRuntime?.aura?.selectedKey() || '');
  } catch (err) {
    showWarning(err.message || 'Could not load auras.');
  }
}

function closeAuraModal() {
  auraModal?.classList.remove('open');
  cleanupAuraLayer(auraPreviewLayer);
}

async function setCurrentAura() {
  try {
    await avatarRuntime?.aura?.setCurrentAura();
    closeAuraModal();
  } catch (err) {
    showWarning(err.message || 'Could not set aura.');
  }
}

function waitForVideoEvent(video, eventName, timeoutMs = 3000) {
  return new Promise((resolve, reject) => {
    if (eventName === 'loadedmetadata' && Number.isFinite(video.duration) && video.duration > 0) {
      resolve();
      return;
    }
    const cleanup = () => {
      clearTimeout(timer);
      video.removeEventListener(eventName, onEvent);
      video.removeEventListener('error', onError);
    };
    const onEvent = () => {
      cleanup();
      resolve();
    };
    const onError = () => {
      cleanup();
      reject(new Error('Video could not be inspected.'));
    };
    const timer = window.setTimeout(() => {
      cleanup();
      reject(new Error('Video inspection timed out.'));
    }, timeoutMs);
    video.addEventListener(eventName, onEvent, { once: true });
    video.addEventListener('error', onError, { once: true });
  });
}

function seekVideo(video, time, timeoutMs = 2500) {
  return new Promise((resolve, reject) => {
    const cleanup = () => {
      clearTimeout(timer);
      video.removeEventListener('seeked', onSeeked);
      video.removeEventListener('error', onError);
    };
    const onSeeked = () => {
      cleanup();
      resolve();
    };
    const onError = () => {
      cleanup();
      reject(new Error('Video seek failed.'));
    };
    const timer = window.setTimeout(() => {
      cleanup();
      reject(new Error('Video seek timed out.'));
    }, timeoutMs);
    video.addEventListener('seeked', onSeeked, { once: true });
    video.addEventListener('error', onError, { once: true });
    video.currentTime = Math.max(0, time);
  });
}

function isBlackVideoFrame(video, canvas) {
  if (!video.videoWidth || !video.videoHeight) return false;
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  if (!ctx) return false;
  ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
  const pixels = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
  let luminance = 0;
  let brightPixels = 0;
  const total = pixels.length / 4;
  for (let i = 0; i < pixels.length; i += 4) {
    const luma = (pixels[i] * .2126) + (pixels[i + 1] * .7152) + (pixels[i + 2] * .0722);
    luminance += luma;
    if (luma > 35) brightPixels++;
  }
  return (luminance / total) < 18 && (brightPixels / total) < .04;
}

async function inspectVideoLoopEdges(src) {
  const probe = document.createElement('video');
  probe.muted = true;
  probe.playsInline = true;
  probe.preload = 'auto';
  probe.src = src;
  try {
    await waitForVideoEvent(probe, 'loadedmetadata');
    const duration = Number(probe.duration || 0);
    if (!Number.isFinite(duration) || duration <= .35) return { start: 0, end: duration || null };
    const canvas = document.createElement('canvas');
    canvas.width = 40;
    canvas.height = 24;
    const edgeWindow = Math.min(1.5, Math.max(.25, duration / 5));
    const step = .08;
    let start = 0;
    let end = duration;

    await seekVideo(probe, Math.min(.04, duration / 4));
    if (isBlackVideoFrame(probe, canvas)) {
      for (let t = step; t <= edgeWindow; t += step) {
        await seekVideo(probe, Math.min(t, duration - .08));
        if (!isBlackVideoFrame(probe, canvas)) {
          start = Math.min(t + .015, duration - .16);
          break;
        }
      }
    }

    await seekVideo(probe, Math.max(start + .12, duration - .06));
    if (isBlackVideoFrame(probe, canvas)) {
      for (let t = duration - step; t >= Math.max(start + .18, duration - edgeWindow); t -= step) {
        await seekVideo(probe, t);
        if (!isBlackVideoFrame(probe, canvas)) {
          end = Math.max(start + .18, t - .015);
          break;
        }
      }
    }

    return { start, end };
  } finally {
    probe.removeAttribute('src');
    probe.load();
  }
}

function attachSmartBackgroundVideo(video) {
  if (!video || video.dataset.smartLoopAttached === '1') return;
  video.dataset.smartLoopAttached = '1';
  video.loop = false;
  video.preload = 'auto';
  const source = video.querySelector('source')?.getAttribute('src') || video.getAttribute('src') || '';
  const src = source ? mediaUrl(source) : '';
  const state = { start: 0, end: null, ready: false, seeking: false, raf: 0, destroyed: false };
  const playBackground = () => {
    const playResult = video.play?.();
    if (!playResult || typeof playResult.catch !== 'function') return;
    playResult.catch(error => {
      if (error?.name === 'AbortError' && (state.destroyed || !video.isConnected)) return;
      console.error('Room background video playback failed.', error);
    });
  };

  const loopToStart = () => {
    if (!state.ready || state.seeking || state.destroyed || !video.isConnected) return;
    state.seeking = true;
    video.currentTime = state.start;
    playBackground();
    window.setTimeout(() => { state.seeking = false; }, 90);
  };

  const tick = () => {
    if (state.destroyed || !video.isConnected) {
      cancelAnimationFrame(state.raf);
      return;
    }
    if (state.ready && state.end && !video.paused && !state.seeking && video.currentTime >= state.end) {
      loopToStart();
    }
    state.raf = requestAnimationFrame(tick);
  };

  video.addEventListener('ended', loopToStart);
  video.addEventListener('loadedmetadata', () => {
    if (state.start > 0 && video.currentTime < state.start) {
      video.currentTime = state.start;
    }
  });

  if (src) {
    inspectVideoLoopEdges(src).then(edges => {
      state.start = Math.max(0, Number(edges.start || 0));
      state.end = Number(edges.end || 0) || null;
      state.ready = true;
      if (state.start > 0 && video.currentTime < state.start) video.currentTime = state.start;
      playBackground();
    }).catch(() => {
      state.ready = true;
    });
  } else {
    state.ready = true;
  }
  state.raf = requestAnimationFrame(tick);
  video.addEventListener('emptied', () => {
    state.destroyed = true;
    cancelAnimationFrame(state.raf);
  }, { once: true });
}

function initRoomBackgroundVideos(root = document) {
  root.querySelectorAll?.('.room-bg video').forEach(attachSmartBackgroundVideo);
}

function backgroundMarkup(path, mime) {
  const safePath = esc(mediaUrl(path || ''));
  const safeMime = esc(mime || '');
  if (!safePath) return '';
  if (String(mime || '').startsWith('video/')) {
    return `<video class="smart-bg-video" autoplay muted playsinline preload="auto"><source src="${safePath}" type="${safeMime}"></video>`;
  }
  return '';
}

function roomPreviewMarkup(path, mime, thumbPath = '') {
  const safePath = esc(mediaUrl(path || ''));
  const safeThumb = esc(mediaUrl(thumbPath || ''));
  const safeMime = esc(mime || '');
  if (!safePath) return '<div class="room-edit-preview-empty">No background selected</div>';
  if (String(mime || '').startsWith('video/') && safeThumb) {
    return `<img src="${safeThumb}" alt="Current room background thumbnail">`;
  }
  if (String(mime || '').startsWith('video/')) {
    return `<video muted loop playsinline preload="metadata"><source src="${safePath}" type="${safeMime}"></video>`;
  }
  return `<img src="${safePath}" alt="Current room background">`;
}

function setRoomEditPreview(path, mime, thumbPath = '') {
  const preview = document.getElementById('room-edit-preview');
  if (preview) preview.innerHTML = roomPreviewMarkup(path, mime, thumbPath);
}

function applyRoomBackground(path, mime, tile = false) {
  const current = roomStage.querySelector('.room-bg');
  const next = document.createElement('div');
  next.className = 'room-bg room-bg-next';
  next.classList.toggle('room-bg-tiled', Boolean(tile));
  if (path && !String(mime || '').startsWith('video/')) next.style.backgroundImage = `url("${mediaUrl(path)}")`;
  next.innerHTML = backgroundMarkup(path, mime);
  (roomStageViewport || roomStage).appendChild(next);
  initRoomBackgroundVideos(next);
  requestAnimationFrame(() => next.classList.add('show'));
  setTimeout(() => {
    if (current) current.remove();
    next.classList.remove('room-bg-next', 'show');
  }, 520);
}

function applyRoomUpdate(update) {
  if (update.room_name) {
    cfg.roomName = update.room_name;
    document.getElementById('room-title-text').textContent = update.room_name;
    const brandingName = String(document.body.dataset.brandingName || '').trim();
    const brandingPrefix = brandingName && brandingName !== 'ChatSpace Community Edition'
      ? `${brandingName} - `
      : '';
    document.title = `${brandingPrefix}${update.room_name} - ChatSpace CE`;
    updateComposerPlaceholder();
  }
  if ('background_path' in update) {
    cfg.backgroundPath = update.background_path;
    cfg.backgroundMime = update.background_mime;
    cfg.backgroundThumbPath = update.background_thumb_path || null;
    cfg.backgroundTile = Boolean(update.background_tile);
    applyRoomBackground(update.background_path, update.background_mime, cfg.backgroundTile);
    importedRoomRuntime?.layout?.syncBackgroundLayer();
    setRoomEditPreview(update.background_path, update.background_mime, update.background_thumb_path || '');
  }
  if ('import_layout' in update) {
    cfg.importLayout = update.import_layout || null;
    importedRoomRuntime?.layout?.render(cfg.importLayout);
  }
  if ('music_playlist' in update) {
    cfg.musicPlaylist = update.music_playlist || [];
    importedRoomRuntime?.music?.renderPlayer(cfg.musicPlaylist);
  }
}

function appendMessageEl(msg) {
  return chatMessageRenderer().appendMessage(msg);
}

function renderMessage(msg, live = false) {
  if (!messageVisible(msg)) {
    chatMessageState().addRoomMessage(msg);
    return;
  }
  addMessageToChannel(msg, 'room', live);
}

function addSystemMessage(text) {
  addMessageToChannel({
    id: `system-${Date.now()}-${Math.random().toString(16).slice(2)}`,
    system: true,
    content: text,
    sent_at: new Date().toISOString(),
  }, 'room', false);
}

let emojiComposerReady = null;
function initializeEmojiComposer() {
  if (!emojiComposerReady) {
    emojiComposerReady = import(appUrl('/assets/js/emoji-composer.js?v=20260914-inline-images'))
      .then(({ enhanceEmojiComposer }) => {
        enhanceEmojiComposer(document.getElementById('chat-input'), { mediaUrl });
        updateComposerState();
      })
      .catch(error => {
        console.warn('Custom emoji preview unavailable; the standard message editor remains available.', error);
      });
  }
  return emojiComposerReady;
}

function updateComposerState() {
  const input = document.getElementById('chat-input');
  const counter = document.getElementById('char-counter');
  if (!input || !counter) return;
  const activeChat = activeChatKey();
  const canSendImportant = Boolean(cfg)
    && Boolean(cfg.importantMessagePermissions?.[activeChat]);
  if (importantMessageControl) importantMessageControl.hidden = !canSendImportant;
  if (!canSendImportant && importantMessageToggle) importantMessageToggle.checked = false;
  input.style.height = 'auto';
  input.style.height = `${Math.min(input.scrollHeight, 132)}px`;
  const count = input.value.length;
  const ratio = Math.min(1, count / 1000);
  counter.textContent = `${count}/1000`;
  const heat = Math.max(0, (ratio - 0.72) / 0.28);
  counter.style.color = heat <= 0 ? '#ffffff' : `hsl(${Math.round(8 - (8 * Math.min(1, heat)))} 86% ${Math.round(72 - (22 * Math.min(1, heat)))}%)`;
}

document.getElementById('composer').addEventListener('submit', async e => {
  e.preventDefault();
  const input = document.getElementById('chat-input');
  const content = input.value.trim();
  if (!content) return;
  const activeChat = activeChatKey();
  if (activeChat.startsWith('game:') && chatGameChat().isChatClosed(activeChat.slice(5))) {
    showWarning('This game chat has ended. Your message was not sent.');
    return;
  }
  input.value = '';
  updateComposerState();
  const important = Boolean(importantMessageToggle?.checked)
    && Boolean(cfg?.importantMessagePermissions?.[activeChat]);
  if (importantMessageToggle) importantMessageToggle.checked = false;
  if (activeChat.startsWith('game:')) {
    stopGameTypingNow();
    const policy = messageProtectionPolicyFor(activeChat);
    (policy?.mode === 'e2ee-private'
      ? sendProtectedTextMessage(content, activeChat)
      : sendGameMessage(content)
    ).catch(err => alert(err.message || err));
    return;
  }
  const policy = messageProtectionPolicyFor(activeChat);
  try {
    if (policy?.mode === 'e2ee-private') await sendProtectedTextMessage(content, activeChat);
    else await chatComposer().sendTextMessage(content, activeChat, { important });
  } catch (error) {
    showWarning(error.message || 'Message could not be sent.');
  }
});

function renderLatency(ms) {
  if (!latencyMonitorEl) return;
  latencyMonitorEl.classList.remove('latency-good', 'latency-warn', 'latency-bad');
  if (!Number.isFinite(ms)) {
    latencyMonitorEl.textContent = 'Latency failed';
    latencyMonitorEl.classList.add('latency-bad');
    return;
  }
  const rounded = Math.max(1, Math.round(ms));
  latencyMonitorEl.textContent = `${rounded}ms`;
  latencyMonitorEl.classList.add(rounded < 100 ? 'latency-good' : (rounded < 150 ? 'latency-warn' : 'latency-bad'));
}

async function checkLatency() {
  if (!latencyMonitorEl || !cfg || roomExitInProgress || document.hidden) return;
  try {
    const probeSamples = [];
    const probeCount = 1;
    for (let probe = 0; probe < probeCount; probe += 1) {
      const qs = new URLSearchParams({ t: `${Date.now()}-${probe}` });
      let startedAt = performance.now();
      await runtimeRequestClient.getJson('/assets/latency-probe.json?' + qs, {
        operation: 'measure-room-latency',
        endpointCategory: 'latency-probe',
        cache: 'no-store',
        onRequestStart: () => { startedAt = performance.now(); },
      });
      probeSamples.push(performance.now() - startedAt);
    }
    const elapsed = Math.min(...probeSamples);
    latencySamples.push(elapsed);
    if (latencySamples.length > 5) latencySamples.shift();
    const orderedSamples = [...latencySamples].sort((a, b) => a - b);
    lastLatencyMs = orderedSamples[Math.floor(orderedSamples.length / 2)];
    renderLatency(lastLatencyMs);
  } catch (err) {
    warnRuntimeRequest(err);
    renderLatency(Number.POSITIVE_INFINITY);
  }
}

function poll() {
  chatPoll().start();
}

document.addEventListener('visibilitychange', () => {
  if (document.hidden || roomExitInProgress || !cfg) return;
  gameRuntime?.lifecycle?.loadGames().catch(warnRuntimeRequest);
});

function showTyping(participantId, active) {
  chatTyping().showTyping(participantId, active);
}

function clearAvatarSpeech(participantId, person) {
  const p = person || participants.get(participantId);
  if (!p?.speechEl) return;
  if (p.speechAudio) p.speechAudio.__chatspacePlaybackInterruption = 'speech-clear';
  p.speechAudio?.pause?.();
  p.speechAudio = null;
  clearInterval(p.speechGifLoopTimer);
  p.speechGifLoopTimer = null;
  p.speechMessage = null;
  avatarRuntime?.renderer?.clearSpeechBubble(p, {
    window,
  });
}

function showAvatarSpeech(participantId, msg, options = {}) {
  const p = participants.get(participantId);
  if (!p) return;
  if (isUserBlocked(p.user_id)) return;
  const isGif = msg?.message_type === 'gif';
  const isGesture = msg?.message_type === 'gesture';
  const gesture = gestureFromMessage(msg);
  const gestureModel = isGesture
    ? gesturePresentation?.messageModel(gesture, msg) || { showAnimation: true, showText: true, playSound: true, canonicalText: '(Gesture)' }
    : null;
  const showImage = isGif || (isGesture && gestureModel.showAnimation);
  const text = isGesture
    ? (gestureModel.individuallyHidden
      ? `${gestureModel.hiddenText}${gestureModel.showText ? ` ${gestureModel.canonicalText}` : ''}`
      : (gestureModel.showText ? gestureModel.canonicalText : (gestureModel.hiddenText || '(Gesture hidden)')))
    : (isGif ? '' : messageSpeechText(msg || {}));
  const token = participants.nextSpeechToken(participantId);
  p.speechMessage = msg;
  avatarRuntime?.renderer?.ensureSpeechBubble(p, {
    stage: participantStage(p),
    document,
  });
  participants.clearSpeechTimer(participantId);
  avatarRuntime?.renderer?.prepareSpeechBubble(p, {
    gif: showImage,
    gesture: isGesture,
  });
  let timerStarted = false;
  const scheduleHide = () => {
    if (timerStarted || !participants.hasSpeechToken(participantId, token)) return;
    timerStarted = true;
    if (isGesture && gesture && gestureModel.playSound && !options.suppressGestureSound && gesture.audio_path && !gesture.audio_is_silent) {
      const audio = new Audio(mediaUrl(gesture.audio_path));
      if (showImage) gifLoopDurationMs(gesture.gif_path).then(duration => {
        if (!participants.hasSpeechToken(participantId, token) || !p.speechEl?.classList.contains('chat-bubble-gesture')) return;
        const img = p.speechEl.querySelector('img');
        if (!img || !Number.isFinite(duration) || duration < 250) return;
        clearInterval(p.speechGifLoopTimer);
        p.speechGifLoopTimer = setInterval(() => {
          if (!participants.hasSpeechToken(participantId, token) || audio.ended || audio.paused || !p.speechEl?.isConnected) {
            clearInterval(p.speechGifLoopTimer);
            p.speechGifLoopTimer = null;
            return;
          }
          img.src = cacheBust(mediaUrl(gesture.gif_path));
        }, duration);
      });
      audio.addEventListener('ended', () => {
        clearInterval(p.speechGifLoopTimer);
        p.speechGifLoopTimer = null;
        if (participants.hasSpeechToken(participantId, token)) clearAvatarSpeech(participantId, p);
      }, { once: true });
      audio.addEventListener('error', () => {
        if (participants.hasSpeechToken(participantId, token)) {
          clearInterval(p.speechGifLoopTimer);
          p.speechGifLoopTimer = null;
          participants.setSpeechTimer(participantId, setTimeout(() => clearAvatarSpeech(participantId, p), 4200));
        }
      }, { once: true });
      p.speechAudio = audio;
      audio.play().catch(error => {
        const intentionalAbort = error?.name === 'AbortError' && Boolean(audio.__chatspacePlaybackInterruption);
        if (!intentionalAbort) console.warn('Avatar speech audio playback failed.', error);
        if (participants.hasSpeechToken(participantId, token)) participants.setSpeechTimer(participantId, setTimeout(() => clearAvatarSpeech(participantId, p), 4200));
      });
    } else if (isGif || isGesture) {
      participants.setSpeechTimer(participantId, setTimeout(() => clearAvatarSpeech(participantId, p), 3200));
      if (showImage) gifLoopDurationMs(isGesture ? gesture?.gif_path : msg.content).then(duration => {
        if (participants.hasSpeechToken(participantId, token) && p.speechEl?.classList.contains('chat-bubble-gif')) {
          participants.setSpeechTimer(participantId, setTimeout(() => clearAvatarSpeech(participantId, p), duration));
        }
      });
    } else {
      participants.setSpeechTimer(participantId, setTimeout(() => clearAvatarSpeech(participantId, p), 5200));
    }
  };
  const reveal = () => {
    if (!participants.hasSpeechToken(participantId, token) || !p.speechEl) return;
    positionAvatar(p);
    requestAnimationFrame(() => {
      if (!participants.hasSpeechToken(participantId, token) || !p.speechEl) return;
      positionAvatar(p);
      avatarRuntime?.renderer?.showSpeechBubble(p);
      scheduleHide();
    });
  };
  if (showImage) {
    const img = avatarRuntime?.renderer?.renderSpeechImage(p, {
      document,
      src: mediaUrl(isGesture ? gesture?.gif_path : msg.content),
      alt: isGesture ? gestureModel.canonicalText : (msg.original_name || 'GIF'),
      gesture: isGesture,
      caption: isGesture && gestureModel.showText ? gestureModel.canonicalText : '',
      onclick: isGesture ? () => {
        if (p.speechAudio) p.speechAudio.__chatspacePlaybackInterruption = 'speech-gesture-dismiss';
        p.speechAudio?.pause?.();
        p.speechAudio = null;
        clearAvatarSpeech(participantId, p);
      } : null,
    });
    let revealed = false;
    const revealOnce = () => {
      if (revealed) return;
      revealed = true;
      reveal();
    };
    img.addEventListener('load', revealOnce, { once: true });
    img.addEventListener('error', revealOnce, { once: true });
    if (img.decode) img.decode().then(revealOnce).catch(() => { if (img.complete) revealOnce(); });
    if (img.complete) revealOnce();
    setTimeout(revealOnce, 900);
  } else {
    avatarRuntime?.renderer?.renderSpeechText(p, text);
    reveal();
  }
}

function sendTyping(active) {
  return chatTyping().sendTyping(active, activeChatKey());
}

function stopTypingNow() {
  chatTyping().stopTypingNow();
}

document.getElementById('composer').addEventListener('input', e => {
  updateComposerState();
  const activeChat = activeChatKey();
  if (activeChat.startsWith('game:')) {
    handleGameTypingInput();
    return;
  }
  chatTyping().handleComposerInput(activeChat);
});

document.getElementById('composer').addEventListener('keydown', e => {
  if (e.key !== 'Enter' || e.shiftKey || e.isComposing) return;
  e.preventDefault();
  document.getElementById('composer').requestSubmit();
});

document.getElementById('reply-draft-cancel')?.addEventListener('click', clearReplyDraft);

function addUploadedChatMessage(msg) {
  chatMediaSend().routeUploadedMessage(msg);
}

function uploadChatFile(file) {
  return chatMediaSend().sendFile(file, activeChatKey());
}

chatFileInput.addEventListener('change', () => {
  const file = chatFileInput.files && chatFileInput.files[0];
  chatFileInput.value = '';
  if (!file) return;
  uploadChatFile(file).catch(err => alert(err.message || err));
});

function pastedImageFile(event) {
  const clipboard = event.clipboardData;
  if (!clipboard) return null;
  const items = Array.from(clipboard.items || []);
  for (const item of items) {
    if (item.kind === 'file' && String(item.type || '').startsWith('image/')) {
      const file = item.getAsFile();
      if (!file) continue;
      const ext = (file.type.split('/')[1] || 'png').replace('jpeg', 'jpg').split(';')[0];
      return new File([file], file.name || `pasted-image-${Date.now()}.${ext}`, { type: file.type || 'image/png' });
    }
  }
  const files = Array.from(clipboard.files || []);
  return files.find(file => String(file.type || '').startsWith('image/')) || null;
}

document.getElementById('composer').addEventListener('paste', e => {
  const file = pastedImageFile(e);
  if (!file) return;
  e.preventDefault();
  uploadChatFile(file).catch(err => alert(err.message || err));
});

function closeVoiceNoteModal() {
  voiceNoteModal.classList.remove('open');
}

function stopVoiceNoteTracks() {
  if (!voiceNoteStream) return;
  voiceNoteStream.getTracks().forEach(track => track.stop());
  voiceNoteStream = null;
}

async function startVoiceNote() {
  if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
    throw new Error('Voice notes are not supported in this browser.');
  }
  voiceNoteChunks = [];
  voiceNoteCancelled = false;
  voiceNoteStream = await navigator.mediaDevices.getUserMedia({ audio: true });
  voiceNoteRecorder = new MediaRecorder(voiceNoteStream);
  document.getElementById('voice-note-status').textContent = 'Recording...';
  voiceNoteModal.classList.add('open');
  voiceNoteRecorder.addEventListener('dataavailable', e => {
    if (e.data && e.data.size) voiceNoteChunks.push(e.data);
  });
  voiceNoteRecorder.addEventListener('stop', () => {
    const chunks = voiceNoteChunks.slice();
    stopVoiceNoteTracks();
    closeVoiceNoteModal();
    voiceNoteRecorder = null;
    voiceNoteChunks = [];
    if (voiceNoteCancelled || !chunks.length) return;
    const type = chunks[0].type || 'audio/webm';
    const blob = new Blob(chunks, { type });
    chatMediaSend().sendVoiceNote(blob, activeChatKey());
  });
  voiceNoteRecorder.start();
}

function cancelVoiceNote() {
  if (!voiceNoteRecorder) return;
  voiceNoteCancelled = true;
  if (voiceNoteRecorder.state !== 'inactive') voiceNoteRecorder.stop();
  else {
    stopVoiceNoteTracks();
    closeVoiceNoteModal();
  }
}

document.getElementById('voice-note-stop').addEventListener('click', () => {
  if (!voiceNoteRecorder) return;
  document.getElementById('voice-note-status').textContent = 'Sending voice note...';
  voiceNoteCancelled = false;
  if (voiceNoteRecorder.state !== 'inactive') voiceNoteRecorder.stop();
});

document.getElementById('voice-note-cancel').addEventListener('click', cancelVoiceNote);

document.getElementById('link-icon-close')?.addEventListener('click', closeLinkIconModal);
document.getElementById('link-choice-link')?.addEventListener('click', () => completePendingLinkChoice('normal'));
document.getElementById('link-choice-lap')?.addEventListener('click', () => completePendingLinkChoice('lap'));
document.getElementById('link-choice-cancel')?.addEventListener('click', () => completePendingLinkChoice('cancel'));
document.getElementById('link-choice-bottom-left')?.addEventListener('click', () => completePendingLinkChoice('lap', 'bottom-left'));
document.getElementById('link-choice-bottom-right')?.addEventListener('click', () => completePendingLinkChoice('lap', 'bottom-right'));
document.getElementById('link-choice-seat-cancel')?.addEventListener('click', () => completePendingLinkChoice('cancel'));
document.getElementById('warning-close')?.addEventListener('click', () => {
  document.getElementById('warning-modal')?.classList.remove('open');
});
linkIconGrid?.querySelectorAll('[data-link-icon]').forEach(btn => {
  btn.addEventListener('click', async () => {
    if (!pendingLinkIconTargetId) return;
    const targetId = pendingLinkIconTargetId;
    const iconName = btn.dataset.linkIcon || 'plus';
    closeLinkIconModal();
    await avatarRuntime?.coordinator?.applyLocalLinkIcon(targetId, iconName);
  });
});

function leaveRoomNow() {
  if (!cfg) return Promise.resolve();
  return fetch(appUrl('/api/leave_room.php'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN },
    body: new URLSearchParams({ session_id: cfg.sessionId, join_token: cfg.myJoinToken, _csrf: CSRF_TOKEN }),
    keepalive: true,
  }).catch(() => {});
}

async function leaveRoomWithLocalExit(href, afterLeave) {
  if (roomExitInProgress) return;
  roomExitInProgress = true;
  avatarRuntime?.coordinator?.cancelPendingLinkChoice('room-exit');
  runtimeRequestAbortController.abort('room-exit');
  closeRoomMenu();
  closeContextMenu();
  closeTextContextMenu();
  closeMediaPicker();
  stopTypingNow();
  const me = participants.get(cfg?.myParticipantId);
  if (me) {
    me.exiting = true;
    await removeParticipant(me.id, { keepRecord: true });
  }
  await leaveRoomNow();
  if (typeof afterLeave === 'function') {
    afterLeave();
    return;
  }
  window.location.href = href;
}

document.getElementById('rooms-link')?.addEventListener('click', async e => {
  e.preventDefault();
  const href = e.currentTarget.dataset.href || e.currentTarget.href || appUrl('/lobby.php');
  await leaveRoomWithLocalExit(href);
});
document.querySelectorAll('[data-room-navigation="utility"]').forEach(link => {
  link.addEventListener('click', closeRoomMenu);
});
document.getElementById('logout-link')?.addEventListener('click', async e => {
  e.preventDefault();
  await p2pTransferService?.explicitLogout?.().catch(() => {});
  await leaveRoomWithLocalExit(null, () => {
    document.getElementById('logout-form')?.requestSubmit();
  });
});

async function refreshPresence() {
  if (roomExitInProgress) return;
  presenceRefreshCycle += 1;
  const cycleId = `presence-${presenceRefreshCycle}`;
  recordVoiceLifecycleDiagnostic({
    event: 'presence-refresh-start',
    cycleId,
    source: 'presence-refresh',
  });
  try {
    const qs = new URLSearchParams({ session_id: cfg.sessionId, join_token: cfg.myJoinToken, mode: 'presence' });
    const data = await runtimeRequestClient.getJson('/api/heartbeat.php?' + qs, {
      operation: 'refresh-room-presence',
      endpointCategory: 'heartbeat',
    });
    (data.participants || []).forEach(p => {
      const existing = participants.get(p.id);
      if (existing) {
        participants.update(p.id, {
          online: p.online,
        });
        if (p.online) applyWebcamState(existing.id, Boolean(p.webcam_enabled || p.webcam_path), p.webcam_path || null, 'heartbeat-presence');
        else if (existing.avatarEl) renderParticipant(existing, { animateJoin: false });
      }
    });
    recordVoiceLifecycleDiagnostic({
      event: 'presence-refresh-complete',
      cycleId,
      source: 'presence-refresh',
      participantCount: (data.participants || []).length,
    });
  } catch (error) {
    recordVoiceLifecycleDiagnostic({
      event: 'presence-refresh-failed',
      cycleId,
      source: 'presence-refresh',
      errorName: error?.name || null,
      message: error?.message || String(error),
    });
  }
}

function normalizeAvatarOrientation(value) {
  const orientation = String(value || 'original');
  return Object.prototype.hasOwnProperty.call(AVATAR_ORIENTATION_LABELS, orientation)
    ? orientation
    : 'original';
}

function syncAvatarOrientationControls(participant) {
  const orientation = normalizeAvatarOrientation(participant?.avatar_orientation);
  if (ctxOrientation) {
    ctxOrientation.textContent = `Orientation: ${AVATAR_ORIENTATION_LABELS[orientation]} >`;
    ctxOrientation.setAttribute('aria-expanded', ctxOrientationWrap?.classList.contains('open') ? 'true' : 'false');
  }
  ctxOrientationSubmenu?.querySelectorAll('[data-avatar-orientation]').forEach(button => {
    const value = normalizeAvatarOrientation(button.dataset.avatarOrientation);
    const selected = value === orientation;
    button.textContent = `${button.dataset.label || AVATAR_ORIENTATION_LABELS[value]}${selected ? ' (Current)' : ''}`;
    button.setAttribute('aria-checked', selected ? 'true' : 'false');
  });
}

function applyAvatarOrientationProjection(participant, orientation, version, reason) {
  const currentVersion = Math.max(1, Number(participant?.avatar_orientation_version || 1));
  const nextVersion = Math.max(1, Number(version || currentVersion));
  if (!participant || nextVersion < currentVersion) return false;
  participants.update(participant.id, {
    avatar_orientation: normalizeAvatarOrientation(orientation),
    avatar_orientation_version: nextVersion,
  });
  renderParticipant(participant);
  recordRuntimeDiagnostic('avatarOrientation', reason, {
    participantId: Number(participant.id),
    orientation: normalizeAvatarOrientation(participant.avatar_orientation),
    orientationVersion: Number(participant.avatar_orientation_version || 1),
  });
  return true;
}

async function processAvatarOrientationIntents() {
  if (avatarOrientationPending) return;
  avatarOrientationPending = true;
  try {
    while (avatarOrientationQueuedIntent) {
      const intent = avatarOrientationQueuedIntent;
      avatarOrientationQueuedIntent = null;
      let retryCount = 0;
      while (true) {
        const me = participants.get(cfg.myParticipantId);
        if (!me || intent.generation < avatarOrientationIntentGeneration && avatarOrientationQueuedIntent) break;
        const expectedOrientation = normalizeAvatarOrientation(me.avatar_orientation);
        const expectedVersion = Math.max(1, Number(me.avatar_orientation_version || 1));
        const formData = new FormData();
        formData.append('action', 'set_orientation');
        formData.append('session_id', cfg.sessionId);
        formData.append('join_token', cfg.myJoinToken);
        formData.append('expected_orientation', expectedOrientation);
        formData.append('expected_orientation_version', String(expectedVersion));
        formData.append('avatar_orientation', intent.orientation);
        try {
          const response = await runtimeRequestClient.postForm('/api/avatar.php', formData, {
            operation: 'set-avatar-orientation',
            endpointCategory: 'avatar',
          });
          applyAvatarOrientationProjection(
            me,
            response.avatar_orientation,
            response.avatar_orientation_version,
            'avatar-orientation-updated'
          );
          if (avatarOrientationQueuedIntent) {
            participants.update(me.id, { avatar_orientation: avatarOrientationQueuedIntent.orientation });
            renderParticipant(me);
          }
          break;
        } catch (error) {
          const payload = error?.responsePayload;
          const stale = Number(error?.details?.status || 0) === 409
            && payload?.code === 'AVATAR_ORIENTATION_STALE';
          if (stale) {
            applyAvatarOrientationProjection(
              me,
              payload.current_orientation,
              payload.current_orientation_version,
              'avatar-orientation-conflict-reconciled'
            );
            const superseded = Boolean(avatarOrientationQueuedIntent)
              || intent.generation < avatarOrientationIntentGeneration;
            if (!superseded && retryCount < 1) {
              retryCount += 1;
              continue;
            }
            if (superseded) break;
          }
          recordRuntimeDiagnostic('avatarOrientation', 'avatar-orientation-update-failed', {
            participantId: Number(me.id),
            attemptedOrientation: intent.orientation,
            code: error?.code || null,
            status: error?.details?.status || null,
            retryCount,
          });
          showWarning(error?.message || 'Could not update avatar orientation.');
          break;
        }
      }
    }
  } finally {
    avatarOrientationPending = false;
    syncAvatarOrientationControls(participants.get(cfg.myParticipantId));
  }
}

function setAvatarOrientation(requestedOrientation) {
  const me = participants.get(cfg.myParticipantId);
  if (!me) return;
  const nextOrientation = normalizeAvatarOrientation(requestedOrientation);
  if (nextOrientation !== requestedOrientation) {
    showWarning('That avatar orientation is not available.');
    return;
  }
  avatarOrientationIntentGeneration += 1;
  avatarOrientationQueuedIntent = {
    generation: avatarOrientationIntentGeneration,
    orientation: nextOrientation,
  };
  participants.update(me.id, { avatar_orientation: nextOrientation });
  renderParticipant(me);
  syncAvatarOrientationControls(me);
  closeContextMenu();
  void processAvatarOrientationIntents();
}

function participantSizeFields(preferences = {}) {
  return {
    avatar_display_size_px: preferences.avatarDisplayPreferencePx ?? null,
    avatar_display_width_px: preferences.avatarDisplayWidthPreferencePx ?? null,
    avatar_display_height_px: preferences.avatarDisplayHeightPreferencePx ?? null,
    webcam_display_width_px: preferences.webcamDisplayWidthPreferencePx ?? null,
    webcam_display_height_px: preferences.webcamDisplayHeightPreferencePx ?? null,
    avatar_size_version: Number(preferences.displayPreferenceVersion || 1),
  };
}

function applyLocalDisplayPreferences(preferences, reason = 'local-display-size-save', options = {}) {
  const me = participants.get(cfg.myParticipantId);
  if (!me || !preferences) return false;
  const currentVersion = Number(me.avatar_size_version || 1);
  const next = participantSizeFields(preferences);
  if (next.avatar_size_version < currentVersion && options.authoritative !== true) return false;
  const changed = next.avatar_size_version !== currentVersion
    || next.avatar_display_size_px !== me.avatar_display_size_px
    || next.avatar_display_width_px !== me.avatar_display_width_px
    || next.avatar_display_height_px !== me.avatar_display_height_px
    || next.webcam_display_width_px !== me.webcam_display_width_px
    || next.webcam_display_height_px !== me.webcam_display_height_px;
  if (!changed) return false;
  const previousDimensions = avatarRenderedDimensions(me);
  participants.update(me.id, next);
  renderParticipant(me);
  const nextDimensions = avatarRenderedDimensions(me);
  avatarRuntime?.coordinator?.scheduleRelationshipRefresh({
    participant: me,
    reason,
  });
  recordRuntimeDiagnostic('avatarDisplayPolicy', 'local-display-size-applied', {
    participantId: Number(me.id),
    displayPreferenceVersion: next.avatar_size_version,
    previousDimensions,
    nextDimensions,
  });
  return true;
}

function setAvatarSizeStatus(message = '', state = '') {
  if (!avatarSizeStatus) return;
  avatarSizeStatus.textContent = message;
  avatarSizeStatus.dataset.state = state;
}

function closeAvatarSizeModal() {
  avatarSizeModal?.classList.remove('open');
  avatarSizeModal?.setAttribute('aria-hidden', 'true');
  avatarSizeStartWebcam = false;
  avatarSizeResetRequested = false;
  setAvatarSizeStatus();
}

function setWebcamPresetFromInputs() {
  if (!avatarSizeWebcamPreset) return;
  const width = Number(avatarSizeWebcamWidth?.value || 0);
  const height = Number(avatarSizeWebcamHeight?.value || 0);
  const presets = avatarRuntime?.displayPolicy?.webcamDisplayPresets?.() || {};
  const exact = Object.entries(presets).find(([, dimensions]) => (
    width === dimensions.width && height === dimensions.height
  ));
  avatarSizeWebcamPreset.value = exact?.[0] || 'custom';
}

function setWebcamSizeInputs(resolution, options = {}) {
  if (!resolution?.ok) {
    setAvatarSizeStatus(resolution?.error || 'Choose a valid webcam display size.', 'error');
    return false;
  }
  avatarSizeInputSync = true;
  avatarSizeWebcamWidth.value = String(resolution.width);
  avatarSizeWebcamHeight.value = String(resolution.height);
  avatarSizeAspectRatio = resolution.width / Math.max(resolution.height, 1);
  avatarSizeAspectLock.checked = true;
  avatarSizeInputSync = false;
  if (options.status !== false) {
    const source = `${resolution.sourceWidth} x ${resolution.sourceHeight}`;
    const effective = `${resolution.width} x ${resolution.height}`;
    setAvatarSizeStatus(
      resolution.adjusted ? `${source}px uses ${effective}px within the community limits.` : `${effective}px selected.`,
      'ok'
    );
  }
  return true;
}

function currentAvatarWebcamResolution(me) {
  const dimensions = avatarRenderedDimensions(me, { webcam: false });
  return avatarRuntime?.displayPolicy?.resolveWebcamDisplayChoice?.('match', {
    avatarDimensions: dimensions,
  });
}

function refreshWebcamPresetLabels() {
  if (!avatarSizeWebcamPreset) return;
  const labels = {
    small: 'Small - 120 x 120',
    medium: 'Medium - 160 x 160',
    large: 'Large - 200 x 200',
  };
  Object.entries(labels).forEach(([choice, label]) => {
    const option = avatarSizeWebcamPreset.querySelector(`option[value="${choice}"]`);
    const resolution = avatarRuntime?.displayPolicy?.resolveWebcamDisplayChoice?.(choice);
    if (!option || !resolution?.ok) return;
    option.textContent = resolution.adjusted
      ? `${label} (uses ${resolution.width} x ${resolution.height})`
      : label;
  });
}

function syncAvatarExactSizeMode() {
  const exact = avatarSizeMode.value === 'exact';
  document.getElementById('avatar-exact-size-fields').hidden = !exact;
  avatarExactWidth.disabled = !exact;
  avatarExactHeight.disabled = !exact;
  avatarSizeEdge.disabled = exact;
}

function populateAvatarExactMatches(me) {
  const relationship = avatarRuntime?.relationships?.relationshipForParticipant(me.id);
  avatarExactMatchParticipant.innerHTML = '';
  (relationship?.status === 'active' ? relationship.members || [] : []).forEach(member => {
    if (member.status !== 'active' || Number(member.participantId) === Number(me.id)) return;
    const target = participants.get(Number(member.participantId));
    if (!target) return;
    const size = avatarRenderedDimensions(target, { webcam: false });
    const option = document.createElement('option');
    option.value = String(target.id);
    option.textContent = (target.display_name || 'Member') + ' (' + Math.round(size.width) + ' x ' + Math.round(size.height) + ')';
    avatarExactMatchParticipant.appendChild(option);
  });
  document.getElementById('avatar-exact-match-wrap').hidden = avatarExactMatchParticipant.options.length === 0;
}

avatarSizeMode.addEventListener('change', () => {
  avatarSizeResetRequested = false;
  syncAvatarExactSizeMode();
  setAvatarSizeStatus();
});
[avatarExactWidth, avatarExactHeight].forEach(input => input.addEventListener('input', () => {
  avatarSizeResetRequested = false;
  setAvatarSizeStatus();
}));
document.getElementById('avatar-size-reset').addEventListener('click', () => {
  if (avatarSizeModalMode !== 'avatar') return;
  avatarSizeMode.value = 'natural';
  syncAvatarExactSizeMode();
});
document.getElementById('avatar-exact-match').addEventListener('click', () => {
  const me = participants.get(cfg.myParticipantId);
  const target = participants.get(Number(avatarExactMatchParticipant.value));
  const relationship = me && avatarRuntime?.relationships?.relationshipForParticipant(me.id);
  if (!target || relationship?.status !== 'active' || Number(target.id) === Number(me.id)
    || !(relationship.members || []).some(member => member.status === 'active' && Number(member.participantId) === Number(target.id))) {
    setAvatarSizeStatus('That linked member is no longer available.', 'error');
    return;
  }
  const dimensions = avatarRenderedDimensions(target, { webcam: false });
  const width = Math.round(dimensions.width);
  const height = Math.round(dimensions.height);
  const cap = Number(avatarRuntime?.displayPolicy?.policy?.().avatarDisplayMaxPx || cfg.avatarSizePolicy?.avatarDisplayMaxPx || 200);
  if (![width, height].every(value => Number.isInteger(value) && value >= 42 && value <= cap)) {
    setAvatarSizeStatus('Their displayed size cannot be matched exactly within the 42-' + cap + 'px community limits.', 'error');
    return;
  }
  avatarSizeMode.value = 'exact';
  avatarExactWidth.value = String(width);
  avatarExactHeight.value = String(height);
  avatarSizeResetRequested = false;
  syncAvatarExactSizeMode();
  setAvatarSizeStatus(width + ' x ' + height + 'px copied. Press Save to apply.', 'ok');
});

function openAvatarSizeModal(mode, options = {}) {
  const me = participants.get(cfg.myParticipantId);
  if (!me || !avatarSizeModal) return;
  const policy = avatarRuntime?.displayPolicy?.policy?.() || cfg.avatarSizePolicy || {};
  avatarSizeModalMode = mode === 'webcam' ? 'webcam' : 'avatar';
  avatarSizeStartWebcam = Boolean(options.startWebcam);
  avatarSizeResetRequested = avatarSizeModalMode === 'avatar'
    ? me.avatar_display_size_px == null && me.avatar_display_width_px == null && me.avatar_display_height_px == null
    : me.webcam_display_width_px == null && me.webcam_display_height_px == null;
  avatarSizeAvatarFields.hidden = avatarSizeModalMode !== 'avatar';
  avatarSizeWebcamFields.hidden = avatarSizeModalMode !== 'webcam';
  avatarSizeAvatarFields.querySelectorAll('input, select, button').forEach(control => {
    control.disabled = avatarSizeModalMode !== 'avatar';
  });
  avatarSizeWebcamFields.querySelectorAll('input, select, button').forEach(control => {
    control.disabled = avatarSizeModalMode !== 'webcam';
  });
  avatarSizeTitle.textContent = avatarSizeModalMode === 'avatar'
    ? 'Avatar Display Size'
    : (avatarSizeStartWebcam ? 'Webcam Display Size Before Starting' : 'Webcam Display Size');

  if (avatarSizeModalMode === 'avatar') {
    const cap = Number(policy.avatarDisplayMaxPx || 200);
    const dimensions = avatarRenderedDimensions(me);
    avatarSizeCap.textContent = `Server maximum: ${cap} px`;
    avatarSizeCurrent.textContent = `Current displayed size: ${Math.round(dimensions.width)} × ${Math.round(dimensions.height)} px`;
    avatarSizeEdge.max = String(cap);
    avatarSizeEdge.value = String(avatarRuntime.displayPolicy.effectiveAvatarMaxEdge(me));
    avatarSizeMode.value = me.avatar_display_width_px != null && me.avatar_display_height_px != null ? 'exact' : 'natural';
    avatarExactWidth.max = avatarExactHeight.max = String(cap);
    avatarExactWidth.value = String(Math.max(42, Math.min(cap, Math.round(Number(me.avatar_display_width_px) || dimensions.width))));
    avatarExactHeight.value = String(Math.max(42, Math.min(cap, Math.round(Number(me.avatar_display_height_px) || dimensions.height))));
    syncAvatarExactSizeMode();
    populateAvatarExactMatches(me);
  } else {
    avatarSizeResetRequested = false;
    const maxWidth = Number(policy.webcamDisplayMaxWidthPx || 200);
    const maxHeight = Number(policy.webcamDisplayMaxHeightPx || 200);
    const box = avatarRuntime.displayPolicy.effectiveWebcamBox(me);
    avatarSizeCap.textContent = `Community maximum: ${maxWidth} x ${maxHeight}px`;
    avatarSizeCurrent.textContent = 'Avatar and webcam display sizes are saved separately.';
    avatarSizeWebcamWidth.max = String(maxWidth);
    avatarSizeWebcamHeight.max = String(maxHeight);
    refreshWebcamPresetLabels();
    const choice = avatarRuntime.displayPolicy.webcamPreferenceChoice(me);
    avatarSizeWebcamPreset.value = choice;
    if (choice === 'match') {
      setWebcamSizeInputs(currentAvatarWebcamResolution(me), { status: false });
    } else {
      setWebcamSizeInputs({
        ok: true,
        width: box.width,
        height: box.height,
        sourceWidth: Number(me.webcam_display_width_px),
        sourceHeight: Number(me.webcam_display_height_px),
        adjusted: box.width !== Number(me.webcam_display_width_px)
          || box.height !== Number(me.webcam_display_height_px),
      }, { status: false });
      avatarSizeWebcamPreset.value = choice;
    }
    document.getElementById('avatar-size-reset').textContent = 'Match current avatar size';

    const relationship = avatarRuntime?.relationships?.relationshipForParticipant(me.id) || null;
    const candidates = avatarRuntime?.displayPolicy?.webcamSizeMatchCandidates(
      relationship,
      participants.values(),
      me.id
    ) || [];
    avatarSizeMatchParticipant.innerHTML = '';
    candidates.forEach(candidate => {
      const option = document.createElement('option');
      option.value = String(candidate.participantId);
      option.textContent = `${candidate.displayName} (${candidate.width} x ${candidate.height})`;
      option.dataset.width = String(candidate.width);
      option.dataset.height = String(candidate.height);
      avatarSizeMatchParticipant.appendChild(option);
    });
    avatarSizeMatchWrap.hidden = candidates.length === 0;
  }

  if (avatarSizeModalMode === 'avatar') {
    document.getElementById('avatar-size-reset').textContent = 'Use server default';
  }

  closeContextMenu();
  setAvatarSizeStatus();
  avatarSizeModal.classList.add('open');
  avatarSizeModal.setAttribute('aria-hidden', 'false');
  requestAnimationFrame(() => (
    avatarSizeModalMode === 'avatar' ? avatarSizeMode : avatarSizeWebcamPreset
  )?.focus());
}

async function saveAvatarSizePreferences(options = {}) {
  if (avatarSizePending) return;
  const me = participants.get(cfg.myParticipantId);
  if (!me) return;
  if (avatarSizeModalMode === 'avatar' && avatarSizeMode.value === 'exact' && !avatarSizeResetRequested) {
    const cap = Number(avatarRuntime?.displayPolicy?.policy?.().avatarDisplayMaxPx || cfg.avatarSizePolicy?.avatarDisplayMaxPx || 200);
    if (![avatarExactWidth.value, avatarExactHeight.value].every(value => Number.isInteger(Number(value)) && Number(value) >= 42 && Number(value) <= cap)) {
      setAvatarSizeStatus('Choose whole-number width and height between 42 and ' + cap + 'px.', 'error');
      return;
    }
  }
  if (avatarSizeModalMode === 'webcam') {
    const resolution = avatarRuntime?.displayPolicy?.resolveWebcamDisplayChoice?.('custom', {
      width: avatarSizeWebcamWidth.value,
      height: avatarSizeWebcamHeight.value,
    });
    if (!resolution?.ok) {
      setAvatarSizeStatus(resolution?.error || 'Choose a valid webcam display size.', 'error');
      return;
    }
    if (!setWebcamSizeInputs(resolution, { status: false })) return;
  }
  avatarSizePending = true;
  const saveButton = document.getElementById('avatar-size-save');
  saveButton.disabled = true;
  setAvatarSizeStatus('Saving...', 'working');
  const formData = new FormData();
  formData.append('action', 'set_display_preferences');
  formData.append('session_id', cfg.sessionId);
  formData.append('join_token', cfg.myJoinToken);
  formData.append('expected_size_version', String(me.avatar_size_version || 1));
  if (avatarSizeModalMode === 'avatar') {
    formData.append('avatar_display_size_px', avatarSizeResetRequested ? '' : avatarSizeEdge.value);
    const exact = !avatarSizeResetRequested && avatarSizeMode.value === 'exact';
    formData.append('avatar_display_width_px', exact ? avatarExactWidth.value : '');
    formData.append('avatar_display_height_px', exact ? avatarExactHeight.value : '');
  } else {
    formData.append('webcam_display_width_px', avatarSizeResetRequested ? '' : avatarSizeWebcamWidth.value);
    formData.append('webcam_display_height_px', avatarSizeResetRequested ? '' : avatarSizeWebcamHeight.value);
  }
  try {
    const response = await runtimeRequestClient.postForm('/api/avatar.php', formData, {
      operation: 'set-avatar-display-preferences',
      endpointCategory: 'avatar',
      shouldReportFailure: error => !(
        Number(error?.details?.status || 0) === 409
        && String(error?.responsePayload?.code || '') === 'AVATAR_SIZE_PREFERENCE_STALE'
      ),
    });
    avatarRuntime?.displayPolicy?.configure(response.avatarSizePolicy || {});
    cfg.avatarSizePolicy = avatarRuntime?.displayPolicy?.policy?.() || cfg.avatarSizePolicy;
    window.ChatSpaceAvatar?.configure?.(cfg.avatarSizePolicy || {});
    applyLocalDisplayPreferences(response.preferences, 'local-display-size-save');
    const startWebcam = avatarSizeStartWebcam;
    closeAvatarSizeModal();
    if (startWebcam) {
      avatarSizeStartConfirmed = true;
      ctxToggleWebcam.click();
    }
  } catch (error) {
    const stalePayload = error?.responsePayload;
    if (
      options.retryStale !== false
      && stalePayload?.code === 'AVATAR_SIZE_PREFERENCE_STALE'
      && stalePayload?.preferences
    ) {
      applyLocalDisplayPreferences(
        stalePayload.preferences,
        'stale-display-size-reconciled',
        { authoritative: true }
      );
      recordRuntimeDiagnostic('avatarDisplayPolicy', 'display-size-stale-reconciled', {
        participantId: Number(me.id),
        mode: avatarSizeModalMode,
        displayPreferenceVersion: Number(stalePayload.preferences.displayPreferenceVersion || 1),
      });
      avatarSizePending = false;
      saveButton.disabled = false;
      return await saveAvatarSizePreferences({ retryStale: false });
    }
    setAvatarSizeStatus(error?.message || 'Display size could not be saved.', 'error');
    recordRuntimeDiagnostic('avatarDisplayPolicy', 'display-size-save-failed', {
      participantId: Number(me.id),
      mode: avatarSizeModalMode,
      code: error?.code || null,
      status: error?.details?.status || null,
    });
  } finally {
    avatarSizePending = false;
    saveButton.disabled = false;
  }
}

let ctxMenuReturnFocus = null;

function relationshipEligibilityLabel(reason) {
  return ({
    self: 'Use your personal avatar controls.',
    blocked: 'Interaction is unavailable while either user is blocked.',
    'already-related': 'These avatars already share a relationship.',
    'initiator-relationship': 'Leave your current relationship before starting another.',
    'target-relationship': 'This user is already in a relationship.',
    'pending-request': 'A relationship request is already pending.',
    'target-unavailable': 'This user is not currently available.',
  })[reason] || 'Interaction is not currently available.';
}

function syncParticipantIdentityHeader(participant) {
  const displayElement = document.getElementById('ctx-identity-display-name');
  const rolesElement = document.getElementById('ctx-identity-roles');
  const currentDisplayName = String(participant?.display_name || 'Member').trim();
  if (displayElement) {
    displayElement.textContent = currentDisplayName;
    displayElement.hidden = false;
  }
  if (rolesElement) {
    rolesElement.textContent = `Roles: ${participantRoleLabels(participant).join(' · ')}`;
  }
  ctxMenu?.setAttribute('aria-label', `Actions for ${currentDisplayName}`);
}

function openAvatarContextMenu(x, y, participant, options = {}) {
  closeTextContextMenu();
  closeRoomMenu();
  closeMediaPicker();
  ctxMenuParticipantId = participant.id;
  const isOwn = Number(participant.id) === Number(cfg.myParticipantId);
  const viewerRelationship = avatarRuntime?.relationships?.relationshipForParticipant(Number(cfg.myParticipantId)) || null;
  const relationship = avatarRuntime?.relationships?.relationshipForParticipant(participant.id) || null;
  const isBlocked = isUserBlocked(participant.user_id);
  const isMuted = Boolean(activeMutedPolicyFor(participant.user_id));
  const me = participants.get(Number(cfg.myParticipantId));
  const interaction = !isOwn && me
    ? avatarRuntime?.coordinator?.relationshipEligibility(me, participant)
    : null;
  const sameRelationship = Boolean(relationship && viewerRelationship && relationship.id === viewerRelationship.id);
  const canRequestGroup = Boolean(relationship && !viewerRelationship && !isOwn && !isBlocked && participant.online !== false);
  const showHostTools = Boolean(cfg.canUseHostTools && !isOwn);
  syncParticipantIdentityHeader(participant);
  if (ctxChangeNameplate) ctxChangeNameplate.style.display = isOwn ? 'block' : 'none';
  if (ctxRemoveNameplate) {
    ctxRemoveNameplate.style.display = isOwn && Boolean(identityNameplateUrl(participant)) ? 'block' : 'none';
  }
  document.getElementById('ctx-change-avatar').style.display = isOwn ? 'block' : 'none';
  document.getElementById('ctx-avatar-size').style.display = isOwn ? 'block' : 'none';
  if (ctxOrientationWrap) ctxOrientationWrap.style.display = isOwn ? 'block' : 'none';
  if (ctxAuras) ctxAuras.style.display = isOwn ? 'block' : 'none';
  ctxToggleWebcam.style.display = isOwn ? 'block' : 'none';
  ctxToggleWebcam.disabled = isOwn && !webcamUseAllowed() && !(webcamIntent || webcamStream);
  ctxToggleWebcam.title = ctxToggleWebcam.disabled
    ? 'Webcam use is disabled for this installation.'
    : '';
  document.getElementById('ctx-webcam-size').style.display = isOwn && Boolean(webcamIntent || webcamStream) ? 'block' : 'none';
  document.getElementById('ctx-dm').style.display = !isOwn && !isBlocked ? 'block' : 'none';
  if (ctxInteract) {
    ctxInteract.style.display = !isOwn ? 'block' : 'none';
    ctxInteract.textContent = canRequestGroup ? 'Request to Link / Sit in Lap' : 'Interact';
    ctxInteract.disabled = !canRequestGroup && !interaction?.allowed;
    ctxInteract.title = canRequestGroup ? 'Request to join this group as a linked member or lap occupant'
      : interaction?.allowed ? 'Link Avatars or Sit in Lap' : relationshipEligibilityLabel(interaction?.reason);
  }
  document.getElementById('ctx-tools-wrap').style.display = showHostTools ? 'block' : 'none';
  document.getElementById('ctx-tools-divider').style.display = showHostTools ? 'block' : 'none';
  document.getElementById('ctx-community-eject').style.display = showHostTools && Boolean(cfg.canCommunityEject) ? 'block' : 'none';
  closeContextSubmenu(
    document.getElementById('ctx-tools-wrap'),
    document.getElementById('ctx-tools'),
    document.getElementById('ctx-tools-submenu')
  );
  closeContextSubmenu(ctxOrientationWrap, ctxOrientation, ctxOrientationSubmenu);
  document.getElementById('ctx-manage-relationship').style.display = relationship ? 'block' : 'none';
  document.getElementById('ctx-unlink').style.display = sameRelationship && !isBlocked ? 'block' : 'none';
  document.getElementById('ctx-manage-relationship').textContent = relationship && !sameRelationship ? 'View Relationship / Request to Join' : 'Manage Relationship';
  syncParticipantActionMenu(participant, isOwn);
  const muteAction = document.getElementById('ctx-mute');
  if (muteAction) {
    muteAction.style.display = !isOwn ? 'block' : 'none';
    muteAction.textContent = isMuted ? 'Unmute' : 'Mute';
    muteAction.setAttribute('aria-pressed', isMuted ? 'true' : 'false');
  }
  ctxToggleWebcam.textContent = (webcamIntent || webcamStream) ? 'Disable Webcam' : 'Enable Webcam';
  syncLocalWebcamToggleButton();
  syncAvatarOrientationControls(participant);
  ctxMenuReturnFocus = options.returnFocus || null;
  ctxMenu.classList.add('visible');
  positionFloatingMenu(ctxMenu, x, y);
  if (options.focusMenu) {
    ctxMenu.querySelector('button:not([disabled]):not([style*="display: none"])')?.focus();
  }
}

function syncParticipantActionButton(button, action, visible = true) {
  if (!button) return;
  button.style.display = visible && action && action.applicable !== false ? 'block' : 'none';
  if (!action) return;
  button.textContent = action.label;
  button.disabled = Boolean(action.disabled);
  button.classList.toggle('is-active', Boolean(action.active));
  button.setAttribute('aria-pressed', action.active ? 'true' : 'false');
}

function syncParticipantActionMenu(participant, isOwn = false) {
  const actions = new Map((roomRuntime?.participantActions?.actionsFor(participant) || []).map(action => [action.id, action]));
  syncParticipantActionButton(ctxProfile, actions.get('user.profile'), true);
  syncParticipantActionButton(document.getElementById('ctx-dm'), actions.get('message.direct'), !isOwn);
  const exact = actions.get('avatar.current-visibility');
  const user = actions.get('avatar.user-visibility');
  const block = actions.get('user.block');
  syncParticipantActionButton(ctxAvatarVisibility, exact, !isOwn);
  syncParticipantActionButton(ctxAvatarUserVisibility, user, !isOwn);
  syncParticipantActionButton(ctxNameplateVisibility, actions.get('nameplate.current-visibility'), !isOwn);
  syncParticipantActionButton(ctxNameplateUserVisibility, actions.get('nameplate.user-visibility'), !isOwn);
  syncParticipantActionButton(
    ctxGestureSenderVisibility,
    actions.get('gesture.sender-media-visibility'),
    !isOwn
  );
  syncParticipantActionButton(ctxWebcamVisibility, actions.get('webcam.presentation'), !isOwn);
  syncParticipantActionButton(ctxWebcamReceive, actions.get('webcam.receive'), !isOwn);
  syncParticipantActionButton(ctxLapDance, actions.get('avatar.lap-dance'));
  syncParticipantActionButton(ctxLapBounce, actions.get('avatar.lap-bounce'));
  syncParticipantActionButton(document.getElementById('ctx-block'), block, !isOwn && !block?.active);
  syncParticipantActionButton(document.getElementById('ctx-unblock'), block, !isOwn && Boolean(block?.active));
  syncParticipantActionButton(ctxSendFileGesture, actions.get('transfer.send-file-or-gesture'), !isOwn);
  const transferDivider = document.getElementById('ctx-transfer-divider');
  if (transferDivider) transferDivider.style.display = !isOwn && actions.get('transfer.send-file-or-gesture')?.applicable !== false ? 'block' : 'none';
}

function closeContextMenu(options = {}) {
  ctxMenu.classList.remove('visible');
  closeContextSubmenu(
    document.getElementById('ctx-tools-wrap'),
    document.getElementById('ctx-tools'),
    document.getElementById('ctx-tools-submenu')
  );
  closeContextSubmenu(ctxOrientationWrap, ctxOrientation, ctxOrientationSubmenu);
  ctxMenuParticipantId = null;
  const returnFocus = ctxMenuReturnFocus;
  ctxMenuReturnFocus = null;
  if (options.restoreFocus && returnFocus?.isConnected) returnFocus.focus();
}

function openTextContextMenu(x, y, mode) {
  closeContextMenu();
  textMenuMode = mode;
  document.getElementById('text-cut').style.display = mode === 'input' ? 'block' : 'none';
  document.getElementById('text-paste').style.display = mode === 'input' ? 'block' : 'none';
  textCtxMenu.classList.add('visible');
  positionFloatingMenu(textCtxMenu, x, y);
}

function closeTextContextMenu() {
  textCtxMenu.classList.remove('visible');
}

function closeMessageActionMenu() {
  msgActionMenu?.classList.remove('visible');
  msgActionTargetId = null;
  msgActionTargetChat = null;
}

function closeTabContextMenu() {
  tabCtxMenu?.classList.remove('visible');
  tabCtxTargetChat = null;
}

function openTabContextMenu(x, y, chatKey) {
  if (!tabCtxMenu || (!chatKey.startsWith('dm:') && !chatKey.startsWith('link:'))) return;
  closeFloatingShells(['tab', 'game']);
  tabCtxTargetChat = chatKey;
  document.getElementById('tab-close-dm').style.display = chatKey.startsWith('dm:') ? 'block' : 'none';
  document.getElementById('tab-manage-relationship').style.display = chatKey.startsWith('link:') ? 'block' : 'none';
  document.getElementById('tab-unlink').style.display = chatKey.startsWith('link:') ? 'block' : 'none';
  tabCtxMenu.classList.add('visible');
  positionFloatingMenu(tabCtxMenu, x, y);
}

function openMessageActionMenu(x, y, msg) {
  if (!msgActionMenu || msg.system || msg.is_deleted) return;
  closeFloatingShells(['message', 'game', 'media', 'attach']);
  msgActionTargetId = Number(msg.id);
  const activeChat = activeChatKey();
  msgActionTargetChat = activeChat;
  const mine = Number(msg.participant_id) === cfg.myParticipantId;
  const editable = mine && (msg.message_type || 'text') === 'text';
  const gesture = gestureFromMessage(msg);
  const gesturePublicId = gesturePresentation?.publicId?.(gesture) || '';
  const gestureVisibilityAction = document.getElementById('msg-gesture-visibility-action');
  const mutedRevealAction = document.getElementById('msg-muted-reveal-action');
  if (mutedRevealAction) {
    const revealAllowed = Boolean(msg.muted_collapsed && msg.one_message_reveal_allowed);
    mutedRevealAction.hidden = !revealAllowed;
    mutedRevealAction.style.display = revealAllowed ? 'block' : 'none';
  }
  const gestureVisibilityAllowed = cfg.gesturePart3?.features?.message_hide_unhide !== false
    && gesturePublicId !== ''
    && Number(gesture?.owner_user_id || 0) !== Number(cfg.myUserId);
  if (gestureVisibilityAction) {
    gestureVisibilityAction.hidden = !gestureVisibilityAllowed;
    gestureVisibilityAction.style.display = gestureVisibilityAllowed ? 'block' : 'none';
    gestureVisibilityAction.textContent = gesturePresentation?.isHidden?.(gesturePublicId)
      ? 'Show this gesture for me'
      : 'Hide this gesture for me';
  }
  document.getElementById('msg-reply-action').style.display = activeChat.startsWith('game:') ? 'none' : 'block';
  document.getElementById('msg-edit-action').style.display = editable ? 'block' : 'none';
  document.getElementById('msg-delete-action').style.display = mine ? 'block' : 'none';
  msgActionMenu.classList.add('visible');
  positionFloatingMenu(msgActionMenu, x, y);
}

function closeRoomMenu() {
  roomMenu.classList.remove('visible');
}

function closeRoomActionMenu() {
  roomActionMenu?.classList.remove('visible');
}

function closeMediaPicker() {
  gestureCatalogController?.closeActionMenu();
  if (mediaPicker) mediaPicker.hidden = true;
}

function closeAttachMenu() {
  attachMenu.hidden = true;
}

function gamePickerCategory(game) {
  const key = String(game?.name || game?.extensionId || game?.key || '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '')
    .replace(/^tetrisversus$/, 'tetris');
  if (['blackjack', 'hearts', 'spades', 'uno'].includes(key)) return 'card';
  if (['spaceinvasion', 'tetris'].includes(key)) return 'arcade';
  return 'table';
}

function gamePickerPlayerLabel(game) {
  const minimum = Math.max(1, Number(game?.minPlayers || 1));
  const maximum = Math.max(minimum, Number(game?.maxPlayers || minimum));
  if (minimum === maximum) return `${minimum} ${minimum === 1 ? 'player' : 'players'}`;
  return `${minimum}-${maximum} players`;
}

function renderGamePickerCatalog() {
  if (!gamePickerCatalog) return;
  if (!gameCatalogLoaded) {
    const loading = document.createElement('p');
    loading.className = 'minor game-picker-loading';
    loading.setAttribute('role', 'status');
    loading.textContent = 'Loading installed games...';
    gamePickerCatalog.replaceChildren(loading);
    if (gamePickerCount) gamePickerCount.textContent = 'Loading installed games...';
    if (gamePickerSelection) gamePickerSelection.textContent = 'Waiting for game library';
    if (gamePickerContinue) gamePickerContinue.disabled = true;
    if (gamePickerEmpty) gamePickerEmpty.hidden = true;
    return;
  }
  const categories = [
    { key: 'card', label: 'Card Games' },
    { key: 'table', label: 'Table Games' },
    { key: 'arcade', label: 'Arcade' },
  ];
  const query = String(gamePickerSearch?.value || '').trim().toLocaleLowerCase();
  const visibleGames = gameCatalogDefinitions
    .filter(game => {
      const category = gamePickerCategory(game);
      const categoryMatches = gameCatalogFilter === 'all' || category === gameCatalogFilter;
      const searchMatches = !query || String(game.name || game.key || '').toLocaleLowerCase().includes(query);
      return categoryMatches && searchMatches;
    })
    .sort((left, right) => String(left.name || left.key || '').localeCompare(String(right.name || right.key || ''), undefined, { sensitivity: 'base' }));

  if (selectedGameCatalogKey && !visibleGames.some(game => String(game.key || '') === selectedGameCatalogKey)) {
    selectedGameCatalogKey = '';
  }

  const fragment = document.createDocumentFragment();
  categories.forEach(category => {
    const games = visibleGames.filter(game => gamePickerCategory(game) === category.key);
    if (!games.length) return;
    const section = document.createElement('section');
    section.className = 'game-picker-section';
    section.dataset.gameCategory = category.key;
    const heading = document.createElement('div');
    heading.className = 'game-picker-section-head';
    const title = document.createElement('strong');
    title.textContent = category.label;
    const count = document.createElement('span');
    count.textContent = `${games.length} ${games.length === 1 ? 'game' : 'games'}`;
    heading.append(title, count);
    const grid = document.createElement('div');
    grid.className = 'game-picker-grid';
    games.forEach(game => {
      const key = String(game.key || '');
      const selected = key === selectedGameCatalogKey;
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `game-picker-card${selected ? ' is-selected' : ''}`;
      button.dataset.game = key;
      button.setAttribute('aria-pressed', selected ? 'true' : 'false');
      const iconWrap = document.createElement('span');
      iconWrap.className = 'game-picker-icon';
      const icon = document.createElement('img');
      icon.src = appUrl(`/assets/images/${game.icon}`);
      icon.alt = '';
      iconWrap.append(icon);
      const copy = document.createElement('span');
      copy.className = 'game-picker-card-copy';
      const name = document.createElement('strong');
      name.textContent = String(game.name || key || 'Installed game');
      const details = document.createElement('small');
      details.textContent = gamePickerPlayerLabel(game);
      copy.append(name, details);
      const action = document.createElement('span');
      action.className = 'game-picker-card-action';
      action.textContent = selected ? 'Selected' : 'Choose';
      button.append(iconWrap, copy, action);
      button.addEventListener('click', () => {
        selectedGameCatalogKey = key;
        renderGamePickerCatalog();
      });
      grid.append(button);
    });
    section.append(heading, grid);
    fragment.append(section);
  });
  gamePickerCatalog.replaceChildren(fragment);
  const selectedGame = gameCatalogDefinitions.find(game => String(game.key || '') === selectedGameCatalogKey);
  if (gamePickerSelection) gamePickerSelection.textContent = String(selectedGame?.name || 'No game selected');
  if (gamePickerContinue) gamePickerContinue.disabled = !selectedGame;
  if (gamePickerEmpty) gamePickerEmpty.hidden = visibleGames.length > 0;
  if (gamePickerCount) {
    const count = gameCatalogDefinitions.length;
    gamePickerCount.textContent = `${count} installed ${count === 1 ? 'game' : 'games'}`;
  }
}

function centerGameStartMenu() {
  if (!gameStartMenu) return;
  const centerX = window.innerWidth / 2;
  const centerY = window.innerHeight / 2;
  gameStartMenu.dataset.positioned = 'false';
  gameStartMenu.style.left = `${centerX}px`;
  gameStartMenu.style.top = `${centerY}px`;
  gameStartMenu.style.right = '';
  gameStartMenu.style.bottom = '';
  gameStartMenu.style.transform = 'translate(-50%, -50%)';
}

function openGameStartMenu() {
  if (!gameStartMenu) return;
  closeRoomGamesMenu({ restoreFocus: false });
  gameStartMenu.hidden = false;
  centerGameStartMenu();
  const browser = gameStartMenu.querySelector('.game-picker-browser');
  if (browser) browser.scrollTop = 0;
  document.getElementById('game-start-btn')?.setAttribute('aria-expanded', 'true');
  gameCatalogLoaded = false;
  gameCatalogDefinitions = [];
  selectedGameCatalogKey = '';
  renderGamePickerCatalog();
  gameRuntime?.lifecycle?.loadCatalog().catch(warnRuntimeRequest);
  requestAnimationFrame(() => gamePickerSearch?.focus({ preventScroll: true }));
}

function closeGameStartMenu({ restoreFocus = true } = {}) {
  if (gameStartMenu) gameStartMenu.hidden = true;
  document.getElementById('game-start-btn')?.setAttribute('aria-expanded', 'false');
  gamePickerDragState = null;
  if (restoreFocus) document.getElementById('game-start-btn')?.focus({ preventScroll: true });
}

function beginGamePickerDrag(event) {
  if (!gameStartMenu || event.button !== 0 || event.target.closest('button, input')) return;
  const rectangle = gameStartMenu.getBoundingClientRect();
  gamePickerDragState = {
    pointerId: event.pointerId,
    offsetX: event.clientX - rectangle.left,
    offsetY: event.clientY - rectangle.top,
    handle: event.currentTarget,
  };
  gameStartMenu.dataset.positioned = 'true';
  gameStartMenu.style.left = `${rectangle.left}px`;
  gameStartMenu.style.top = `${rectangle.top}px`;
  gameStartMenu.style.transform = 'none';
  event.currentTarget.setPointerCapture?.(event.pointerId);
  event.preventDefault();
}

function moveGamePicker(event) {
  if (!gameStartMenu || !gamePickerDragState || event.pointerId !== gamePickerDragState.pointerId) return;
  const maximumLeft = Math.max(8, window.innerWidth - gameStartMenu.offsetWidth - 8);
  const maximumTop = Math.max(8, window.innerHeight - gameStartMenu.offsetHeight - 8);
  const left = Math.min(maximumLeft, Math.max(8, event.clientX - gamePickerDragState.offsetX));
  const top = Math.min(maximumTop, Math.max(8, event.clientY - gamePickerDragState.offsetY));
  gameStartMenu.style.left = `${left}px`;
  gameStartMenu.style.top = `${top}px`;
}

function endGamePickerDrag(event) {
  if (!gamePickerDragState || event.pointerId !== gamePickerDragState.pointerId) return;
  gamePickerDragState.handle?.releasePointerCapture?.(event.pointerId);
  gamePickerDragState = null;
}

function centerRoomGamesMenu() {
  if (!roomGamesMenu) return;
  const anchorRect = document.querySelector('.main')?.getBoundingClientRect();
  const centerX = anchorRect ? (anchorRect.left + anchorRect.right) / 2 : window.innerWidth / 2;
  roomGamesMenu.dataset.positioned = 'false';
  roomGamesMenu.style.left = `${centerX}px`;
  roomGamesMenu.style.top = `${window.innerHeight / 2}px`;
  roomGamesMenu.style.right = '';
  roomGamesMenu.style.bottom = '';
  roomGamesMenu.style.transform = 'translate(-50%, -50%)';
}

function openRoomGamesMenu() {
  if (!roomGamesMenu) return;
  closeGameStartMenu({ restoreFocus: false });
  centerRoomGamesMenu();
  roomGamesMenu.hidden = false;
  document.getElementById('room-games-btn')?.setAttribute('aria-expanded', 'true');
  requestAnimationFrame(() => document.getElementById('room-games-close')?.focus({ preventScroll: true }));
}

function closeRoomGamesMenu({ restoreFocus = true } = {}) {
  if (roomGamesMenu) roomGamesMenu.hidden = true;
  document.getElementById('room-games-btn')?.setAttribute('aria-expanded', 'false');
  roomGamesDragState = null;
  if (restoreFocus) document.getElementById('room-games-btn')?.focus({ preventScroll: true });
}

function beginRoomGamesDrag(event) {
  if (!roomGamesMenu || event.button !== 0 || event.target.closest('button, input')) return;
  const rectangle = roomGamesMenu.getBoundingClientRect();
  roomGamesDragState = {
    pointerId: event.pointerId,
    offsetX: event.clientX - rectangle.left,
    offsetY: event.clientY - rectangle.top,
    handle: event.currentTarget,
  };
  roomGamesMenu.dataset.positioned = 'true';
  roomGamesMenu.style.left = `${rectangle.left}px`;
  roomGamesMenu.style.top = `${rectangle.top}px`;
  roomGamesMenu.style.transform = 'none';
  event.currentTarget.setPointerCapture?.(event.pointerId);
  event.preventDefault();
}

function moveRoomGamesMenu(event) {
  if (!roomGamesMenu || !roomGamesDragState || event.pointerId !== roomGamesDragState.pointerId) return;
  const maximumLeft = Math.max(8, window.innerWidth - roomGamesMenu.offsetWidth - 8);
  const maximumTop = Math.max(8, window.innerHeight - roomGamesMenu.offsetHeight - 8);
  roomGamesMenu.style.left = `${Math.min(maximumLeft, Math.max(8, event.clientX - roomGamesDragState.offsetX))}px`;
  roomGamesMenu.style.top = `${Math.min(maximumTop, Math.max(8, event.clientY - roomGamesDragState.offsetY))}px`;
}

function endRoomGamesDrag(event) {
  if (!roomGamesDragState || event.pointerId !== roomGamesDragState.pointerId) return;
  roomGamesDragState.handle?.releasePointerCapture?.(event.pointerId);
  roomGamesDragState = null;
}

function projectedGameSettingValue(control) {
  const type = String(control?.dataset?.gameSettingType || 'checkbox');
  if (type === 'stepper') return Number(control.dataset.gameSettingValue);
  if (type === 'choice-grid') return JSON.parse(control.dataset.gameSettingValue);
  if (type === 'button-choice') return JSON.parse(control.dataset.gameSettingValue);
  if (type === 'select') return JSON.parse(control.value);
  return Boolean(control.checked);
}

function renderGameModeRules(game) {
  const surface = document.getElementById('game-mode-rules');
  const description = document.getElementById('game-mode-rules-description');
  const host = document.getElementById('game-mode-rule-controls');
  if (!surface || !description || !host) return;
  const projection = game?.settingsControls || {};
  const controls = Array.isArray(projection.controls) ? projection.controls : [];
  host.replaceChildren();
  surface.hidden = controls.length === 0;
  if (!controls.length) return;
  const originalDescription = String(projection.description || 'Choose the exact rules that every player will accept.');
  const updateClassification = () => {
    const defaultLabel = String(projection.defaultClassificationLabel || '');
    const customLabel = String(projection.customClassificationLabel || '');
    if (!defaultLabel || !customLabel) {
      description.textContent = originalDescription;
      return;
    }
    const custom = controls.some(control => {
      const input = host.querySelector(`[data-game-setting-key="${CSS.escape(String(control.key || ''))}"]`);
      return input && JSON.stringify(projectedGameSettingValue(input)) !== JSON.stringify(control.defaultValue);
    });
    const detail = originalDescription.replace(/^[^.]+\.\s*/, '');
    description.textContent = `${custom ? customLabel : defaultLabel}. ${detail}`;
  };
  controls.forEach(control => {
    const isStepper = control.type === 'stepper';
    const isChoiceGrid = control.type === 'choice-grid';
    const isButtonChoice = control.type === 'button-choice';
    const label = document.createElement(isStepper || isChoiceGrid || isButtonChoice ? 'div' : 'label');
    label.className = 'game-mode-rule-control';
    const title = document.createElement('strong');
    title.textContent = String(control.label || 'Game rule');
    const help = document.createElement('small');
    help.textContent = String(control.description || '');
    if (isStepper) {
      label.classList.add('is-stepper');
      const minimum = Number(control.minimum);
      const maximum = Number(control.maximum);
      const step = Number(control.step);
      let currentValue = Math.max(minimum, Math.min(maximum, Number(control.value)));
      const input = document.createElement('div');
      input.className = 'game-rule-stepper';
      input.setAttribute('role', 'group');
      input.setAttribute('aria-label', String(control.label || 'Game rule'));
      input.dataset.gameSettingKey = String(control.key || '');
      input.dataset.gameSettingType = 'stepper';
      const decrease = document.createElement('button');
      decrease.type = 'button';
      decrease.className = 'btn game-rule-stepper-button';
      decrease.textContent = `−${step}`;
      decrease.setAttribute('aria-label', `Decrease ${String(control.label || 'value')} by ${step}`);
      const output = document.createElement('output');
      output.className = 'game-rule-stepper-value';
      output.setAttribute('aria-live', 'polite');
      const increase = document.createElement('button');
      increase.type = 'button';
      increase.className = 'btn game-rule-stepper-button';
      increase.textContent = `+${step}`;
      increase.setAttribute('aria-label', `Increase ${String(control.label || 'value')} by ${step}`);
      const setValue = next => {
        currentValue = Math.max(minimum, Math.min(maximum, Math.round((Number(next) - minimum) / step) * step + minimum));
        input.dataset.gameSettingValue = String(currentValue);
        output.value = String(currentValue);
        output.textContent = String(currentValue);
        output.setAttribute('aria-label', `${String(control.label || 'Game rule')} ${currentValue}`);
        decrease.disabled = currentValue <= minimum;
        increase.disabled = currentValue >= maximum;
        updateClassification();
      };
      decrease.addEventListener('click', () => setValue(currentValue - step));
      increase.addEventListener('click', () => setValue(currentValue + step));
      input.append(decrease, output, increase);
      for (const shortcut of Array.isArray(control.shortcuts) ? control.shortcuts : []) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn game-rule-stepper-shortcut';
        button.textContent = String(shortcut.label || shortcut.value);
        button.addEventListener('click', () => setValue(Number(shortcut.value)));
        input.append(button);
      }
      label.append(title, help, input);
      host.append(label);
      setValue(currentValue);
      return;
    }
    if (isChoiceGrid) {
      label.classList.add('is-choice-grid');
      const group = document.createElement('div');
      group.className = 'game-rule-choice-grid';
      group.setAttribute('role', 'radiogroup');
      group.setAttribute('aria-label', String(control.label || 'Game rule'));
      group.dataset.gameSettingKey = String(control.key || '');
      group.dataset.gameSettingType = 'choice-grid';
      group.dataset.gameSettingValue = JSON.stringify(control.value);
      for (const choice of Array.isArray(control.options) ? control.options : []) {
        const card = document.createElement('label');
        card.className = 'game-rule-choice-card';
        const radio = document.createElement('input');
        radio.type = 'radio';
        radio.name = `game-mode-${String(control.key || 'choice')}`;
        radio.value = JSON.stringify(choice.value);
        radio.checked = radio.value === JSON.stringify(control.value);
        const text = document.createElement('span');
        text.textContent = String(choice.label || choice.value);
        radio.addEventListener('change', () => {
          if (!radio.checked) return;
          group.dataset.gameSettingValue = radio.value;
          updateClassification();
        });
        card.append(radio, text);
        group.append(card);
      }
      label.append(title, help, group);
      host.append(label);
      return;
    }
    if (isButtonChoice) {
      label.classList.add('is-button-choice');
      const group = document.createElement('div');
      group.className = 'game-rule-button-choices';
      group.setAttribute('role', 'group');
      group.setAttribute('aria-label', String(control.label || 'Game rule'));
      group.dataset.gameSettingKey = String(control.key || '');
      group.dataset.gameSettingType = 'button-choice';
      group.dataset.gameSettingValue = JSON.stringify(control.value);
      const choices = [];
      const setChoice = button => {
        group.dataset.gameSettingValue = String(button.dataset.value);
        choices.forEach(candidate => {
          const selected = candidate === button;
          candidate.classList.toggle('is-selected', selected);
          candidate.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });
        updateClassification();
      };
      for (const choice of Array.isArray(control.options) ? control.options : []) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'game-rule-button-choice';
        button.dataset.value = JSON.stringify(choice.value);
        const name = document.createElement('strong');
        name.textContent = String(choice.label || choice.value);
        const detail = document.createElement('small');
        detail.textContent = String(choice.description || '');
        button.append(name, detail);
        button.addEventListener('click', () => setChoice(button));
        choices.push(button);
        group.append(button);
      }
      label.append(title, help, group);
      host.append(label);
      setChoice(choices.find(button => button.dataset.value === JSON.stringify(control.value)) || choices[0]);
      return;
    }
    const input = document.createElement(control.type === 'select' ? 'select' : 'input');
    if (control.type === 'select') {
      for (const choice of Array.isArray(control.options) ? control.options : []) {
        const option = document.createElement('option');
        option.value = JSON.stringify(choice.value);
        option.textContent = String(choice.label || choice.value);
        option.selected = option.value === JSON.stringify(control.value);
        input.append(option);
      }
    } else {
      input.type = 'checkbox';
      input.checked = control.value === true;
    }
    input.dataset.gameSettingKey = String(control.key || '');
    input.dataset.gameSettingType = String(control.type || 'checkbox');
    label.append(input, title, help);
    host.append(label);
    input.addEventListener('change', updateClassification);
  });
  updateClassification();
}

function syncGameModeInactivityProfile(mode) {
  const input = gameModeForm?.querySelector('[data-game-setting-key="inactivityProfile"][data-game-setting-type="select"]');
  if (!input) return;
  const recorded = mode === 'recorded';
  const options = Array.from(input.options || []);
  const unlimited = options.find(option => option.value === JSON.stringify('unlimited'));
  const standard = options.find(option => option.value === JSON.stringify('default'));
  if (unlimited) {
    unlimited.disabled = recorded;
    unlimited.hidden = recorded;
  }
  if (recorded && input.value === JSON.stringify('unlimited') && standard && !standard.disabled) {
    input.value = JSON.stringify('default');
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }
}

function selectedGameModeSettings() {
  const settings = {};
  document.querySelectorAll('#game-mode-rule-controls [data-game-setting-key]').forEach(input => {
    settings[String(input.dataset.gameSettingKey)] = projectedGameSettingValue(input);
  });
  return settings;
}

function openGameModeDialog(game) {
  if (!gameModeModal || !gameModeForm || !game) return;
  gameModeDefinition = game;
  gameModeReturnFocus = document.activeElement;
  if (gameModeTitle) gameModeTitle.textContent = `Start ${game.name || 'Game'}`;
  if (gameModeStatus) gameModeStatus.textContent = '';
  const radios = Array.from(gameModeForm.querySelectorAll('input[name="game_mode"]'));
  const practice = radios.find(input => input.value === 'practice');
  const recorded = radios.find(input => input.value === 'recorded');
  const recordedAvailable = String(game.profile || '') !== 'one-player'
    && Number(game.maxPlayers || 1) >= 2;
  if (gameModeRecordedChoice) gameModeRecordedChoice.hidden = !recordedAvailable;
  if (recorded) recorded.disabled = !recordedAvailable;
  if (practice) practice.checked = true;
  renderGameModeRules(game);
  gameModeModal.classList.add('open');
  gameModeModal.setAttribute('aria-hidden', 'false');
  const box = gameModeModal.querySelector('.game-mode-box');
  if (box) {
    box.scrollTop = 0;
    box.scrollLeft = 0;
  }
  practice?.focus({ preventScroll: true });
}

function closeGameModeDialog({ restoreFocus = true } = {}) {
  gameModeModal?.classList.remove('open');
  gameModeModal?.setAttribute('aria-hidden', 'true');
  gameModeDefinition = null;
  if (gameModeStatus) gameModeStatus.textContent = '';
  if (restoreFocus) {
    const target = gameModeReturnFocus?.isConnected
      ? gameModeReturnFocus
      : document.getElementById('game-start-btn');
    target?.focus?.({ preventScroll: true });
  }
  gameModeReturnFocus = null;
}

async function openGameRecords() {
  if (!gameRecordsModal) return {};
  gameRecordsReturnFocus = document.activeElement;
  const content = document.getElementById('game-records-content');
  const status = document.getElementById('game-records-status');
  if (content) content.innerHTML = '';
  if (status) status.textContent = 'Loading records...';
  gameRecordsModal.classList.add('open');
  gameRecordsModal.setAttribute('aria-hidden', 'false');
  try {
    const records = await gameRuntime?.lifecycle?.gameRecords() || {};
    const totals = records.lifetime || {};
    const displayName = String(records.displayName || 'Game');
    const title = document.getElementById('game-records-title');
    if (title) title.textContent = `${displayName} Records`;
    if (content) {
      const summary = document.createElement('dl');
      summary.className = 'game-record-totals';
      for (const [label, value] of [['Wins', totals.win], ['Losses', totals.loss], ['Draws', totals.draw], ['Recorded matchups', totals.recorded]]) {
        const item = document.createElement('div');
        const term = document.createElement('dt');
        const detail = document.createElement('dd');
        term.textContent = label;
        detail.textContent = String(Number(value || 0));
        item.append(term, detail);
        summary.appendChild(item);
      }
      content.appendChild(summary);
      const opponents = Array.isArray(records.opponents) ? records.opponents : [];
      if (opponents.length) {
        const heading = document.createElement('h3');
        heading.textContent = 'By opponent';
        const list = document.createElement('ul');
        list.className = 'game-record-opponents';
        for (const opponent of opponents) {
          const item = document.createElement('li');
          item.textContent = `${String(opponent.displayName || 'Member')}: ${Number(opponent.win || 0)} wins, ${Number(opponent.loss || 0)} losses, ${Number(opponent.draw || 0)} draws`;
          list.appendChild(item);
        }
        content.append(heading, list);
      }
      const note = document.createElement('p');
      note.className = 'minor';
      note.textContent = `Records stay attached to ${displayName} if its display name changes and cannot be reset by members.`;
      content.appendChild(note);
    }
    if (status) status.textContent = Array.isArray(records.results) && records.results.length ? `${records.results.length} recorded matchup entries.` : 'No recorded results yet.';
    document.getElementById('game-records-close')?.focus?.({ preventScroll: true });
    return records;
  } catch (error) {
    if (status) status.textContent = error?.message || 'Game records could not be loaded.';
    return {};
  }
}

function closeGameRecords({ restoreFocus = true } = {}) {
  gameRecordsModal?.classList.remove('open');
  gameRecordsModal?.setAttribute('aria-hidden', 'true');
  if (restoreFocus) (gameRecordsReturnFocus?.isConnected ? gameRecordsReturnFocus : document.getElementById('game-records'))?.focus?.({ preventScroll: true });
  gameRecordsReturnFocus = null;
}

function closeFloatingShells(except = []) {
  const skip = new Set(except);
  if (!skip.has('context')) closeContextMenu();
  if (!skip.has('text')) closeTextContextMenu();
  if (!skip.has('message')) closeMessageActionMenu();
  if (!skip.has('tab')) closeTabContextMenu();
  if (!skip.has('room')) closeRoomMenu();
  if (!skip.has('roomAction')) closeRoomActionMenu();
  if (!skip.has('game')) closeGameStartMenu({ restoreFocus: false });
  if (!skip.has('media')) closeMediaPicker();
  if (!skip.has('attach')) closeAttachMenu();
}

function openEmojiPicker() {
  closeFloatingShells(['message']);
  gesturePaletteLoaded = false;
  const btn = document.getElementById('emoji-btn');
  const r = btn.getBoundingClientRect();
  mediaPicker.hidden = false;
  const activeButton = mediaPicker.querySelector(`[data-media-tab="${activeMediaTab}"]`);
  if (!activeButton || activeButton.hidden) {
    const firstAvailable = mediaPicker.querySelector('[data-media-tab]:not([hidden])');
    if (firstAvailable) setMediaTab(firstAvailable.dataset.mediaTab || 'gifs');
  }
  const er = mediaPicker.getBoundingClientRect();
  mediaPicker.style.left = `${Math.max(8, Math.min(r.right - er.width, window.innerWidth - er.width - 8))}px`;
  mediaPicker.style.top = `${Math.max(8, r.top - er.height - 8)}px`;
  mediaSearchInput?.focus();
  if (activeMediaTab === 'gestures') loadGestures();
  if (activeMediaTab === 'emojis') renderEmojiGrid();
  if (activeMediaTab === 'custom-emojis') openCustomEmojiPicker();
}

function openRoomMenu() {
  closeFloatingShells(['message', 'room']);
  const btn = document.getElementById('room-menu-btn');
  const r = btn.getBoundingClientRect();
  roomMenu.classList.add('visible');
  const mr = roomMenu.getBoundingClientRect();
  roomMenu.style.left = `${Math.max(8, Math.min(r.right - mr.width, window.innerWidth - mr.width - 8))}px`;
  roomMenu.style.top = `${Math.min(r.bottom + 6, window.innerHeight - mr.height - 8)}px`;
}

document.getElementById('room-action-refresh-import-text')?.addEventListener('click', async event => {
  const button = event.currentTarget;
  if (button.disabled || !cfg?.canEditRoom || !cfg?.importUrl) return;
  closeRoomActionMenu();
  button.disabled = true;
  button.textContent = 'Refreshing imported appearance...';
  try {
    const result = await apiPost('/api/room_import.php', {
      action: 'refresh_text', room_public_id: document.body.dataset.roomId,
    });
    cfg.importLayout = result.layout;
    importedRoomRuntime?.layout?.render(cfg.importLayout);
    alert(`Imported appearance refreshed. ${Number(result.removedHiddenTextSections || 0)} hidden text sections removed; source text and player styling updated. Other viewers can reload to see the update.`);
  } catch (error) {
    alert(error?.message || 'Imported text could not be refreshed.');
  } finally {
    button.disabled = false;
    button.textContent = 'Refresh imported appearance';
  }
});

function openRoomActionMenu() {
  if (!roomActionMenu) return;
  closeFloatingShells(['message', 'roomAction']);
  const btn = document.getElementById('room-action-btn');
  const r = btn.getBoundingClientRect();
  document.getElementById('room-action-edit').style.display = cfg.canEditRoom ? 'block' : 'none';
  document.getElementById('room-action-effects').style.display = cfg.canUseHostTools ? 'block' : 'none';
  document.getElementById('room-action-clear-history').style.display = cfg.canUseHostTools ? 'block' : 'none';
  roomActionMenu.classList.add('visible');
  const mr = roomActionMenu.getBoundingClientRect();
  roomActionMenu.style.left = `${Math.max(8, Math.min(r.left, window.innerWidth - mr.width - 8))}px`;
  roomActionMenu.style.top = `${Math.min(r.bottom + 6, window.innerHeight - mr.height - 8)}px`;
}

function webcamUseAllowed() {
  return voiceRuntime?.viewerPolicy?.capability?.().allowWebcamUse !== false;
}

function webcamViewerPolicyFor(person, own = false) {
  return voiceRuntime?.viewerPolicy?.effectiveFor(person, { own }) || Object.freeze({
    webcamActive: Boolean(person?.webcam_enabled || person?.webcam_path),
    show: true,
    receive: true,
    reason: 'policy-unavailable',
  });
}

function applyWebcamPresentationPolicy(person, reason = 'viewer-policy') {
  if (!person || Number(person.id) === Number(cfg?.myParticipantId)) return false;
  const policy = webcamViewerPolicyFor(person);
  const changed = avatarRuntime?.renderer?.setWebcamPresentationVisible(
    person,
    policy.show,
    {
      reason: `${reason}:${policy.reason}`,
      onWebcamPresentationDiagnostic: recordVoiceLifecycleDiagnostic,
    }
  ) || false;
  if (changed) {
    positionAvatar(person);
    avatarRuntime?.coordinator?.scheduleRelationshipRefresh({
      participant: person,
      reason: 'webcam-viewer-presentation-change',
    });
  }
  return changed;
}

function reconcileWebcamViewerPolicy(change = {}) {
  if (!cfg) return;
  const targets = [...participants.values()].filter(person => (
    Number(person.id) !== Number(cfg.myParticipantId)
    && (!change.userId || Number(person.user_id) === Number(change.userId))
  ));
  targets.forEach(person => applyWebcamPresentationPolicy(person, change.reason || 'viewer-policy'));
  if (change.changes?.receiveChanged) {
    targets.forEach(person => {
      const policy = webcamViewerPolicyFor(person);
      voiceRuntime?.media?.reconcileRemoteWebcamReceivePolicy(
        person.id,
        policy.receive,
        'viewer-policy'
      ).catch(warnRuntimeRequest);
    });
  }
  recordRuntimeDiagnostic('webcamViewerPolicy', 'viewer-policy-reconciled', {
    reason: change.reason || 'viewer-policy',
    participantCount: targets.length,
    presentationChanged: Boolean(change.changes?.presentationChanged),
    receiveChanged: Boolean(change.changes?.receiveChanged),
    capabilityChanged: Boolean(change.changes?.capabilityChanged),
  });
}

function reconcileAvatarVisibility(change = {}) {
  if (!cfg) return;
  participants.forEach(person => renderParticipant(person));
  renderPeople();
  renderActiveChat();
  renderHiddenAvatarOptions();
  gameRuntime?.lifecycle?.loadGames();
  renderVoiceList(lastVoiceParticipants);
  if (document.getElementById('locate-modal')?.classList.contains('open')) loadFriends();
  const snapshot = avatarRuntime?.visibility?.snapshot?.() || { version: 1, entries: [] };
  recordRuntimeDiagnostic('avatarVisibility', 'viewer-policy-reconciled', {
    reason: change.reason || 'viewer-policy',
    preferenceVersion: Number(change.version || snapshot.version || 1),
    entryCount: Number(change.entryCount ?? snapshot.entries.length),
  });
}

function renderHiddenAvatarOptions() {
  const list = document.getElementById('hidden-avatar-list');
  const empty = document.getElementById('hidden-avatar-empty');
  const showAll = document.getElementById('hidden-avatar-show-all');
  if (!list || !empty || !showAll) return;
  const entries = avatarRuntime?.visibility?.snapshot?.().entries || [];
  list.innerHTML = '';
  entries.forEach(entry => {
    const row = document.createElement('div');
    row.className = 'hidden-avatar-row';
    const copy = document.createElement('div');
    const name = document.createElement('strong');
    name.textContent = entry.displayName;
    const notice = document.createElement('span');
    notice.className = 'minor';
    notice.textContent = entry.notice;
    copy.append(name, notice);
    const button = document.createElement('button');
    button.className = 'btn';
    button.type = 'button';
    button.textContent = entry.scope === 'user' ? 'Show avatars from this user' : 'Show this avatar';
    button.addEventListener('click', async () => {
      button.disabled = true;
      try {
        const target = { user_id: entry.targetUserId, display_name: entry.displayName };
        if (entry.scope === 'user') await avatarRuntime?.visibility?.setUserHidden(target, false);
        else await avatarRuntime?.visibility?.setExactHidden(target, false);
        const status = document.getElementById('hidden-avatar-status');
        if (status) status.textContent = 'Avatar shown.';
      } catch (error) {
        showWarning(error?.message || 'Avatar visibility could not be changed.');
      } finally {
        button.disabled = false;
      }
    });
    row.append(copy, button);
    list.appendChild(row);
  });
  empty.hidden = entries.length > 0;
  showAll.hidden = entries.length === 0;
  if (!entries.length) document.getElementById('hidden-avatar-confirm')?.setAttribute('hidden', '');
}

document.getElementById('room-menu-btn').addEventListener('click', e => {
  e.stopPropagation();
  if (roomMenu.classList.contains('visible')) closeRoomMenu();
  else openRoomMenu();
});

function syncChatOptions() {
  chatRuntime?.notifications?.syncControls();
  const mode = chatMessageRenderer().displayMode;
  document.querySelectorAll('input[name="chat-display-mode"]').forEach(input => {
    input.checked = input.value === mode;
  });
  const description = document.getElementById('chat-display-description');
  if (description) {
    description.textContent = mode === 'compact'
      ? 'Hides avatars and places the timestamp, username, and message inline.'
      : 'Shows avatars and the full message layout.';
  }
  if (showRoleMessageColors) showRoleMessageColors.checked = chatAppearancePreferences.showRoleMessageColors;
  if (showRoleMessageTitles) showRoleMessageTitles.checked = chatAppearancePreferences.showRoleMessageTitles;
  if (showIdentityNameplates) showIdentityNameplates.checked = chatAppearancePreferences.showIdentityNameplates;
  const webcamPreferences = voiceRuntime?.viewerPolicy?.preferences?.() || {
    showWebcams: true,
    receiveWebcams: true,
  };
  document.querySelectorAll('input[name="webcam-visibility-mode"]').forEach(input => {
    input.checked = input.value === (webcamPreferences.showWebcams ? 'show' : 'hide');
    input.disabled = webcamPreferencesPending;
  });
  document.querySelectorAll('input[name="webcam-receive-mode"]').forEach(input => {
    input.checked = input.value === (webcamPreferences.receiveWebcams ? 'receive' : 'stop');
    input.disabled = webcamPreferencesPending;
  });
  if (webcamOptionsReset) webcamOptionsReset.disabled = webcamPreferencesPending;
  const gesturePreferences = gesturePresentation?.preferences?.() || {
    showAnimations: true,
    showText: true,
    playSounds: true,
  };
  if (gestureShowAnimations) {
    if (!gesturePreferencesPending) gestureShowAnimations.checked = gesturePreferences.showAnimations;
    gestureShowAnimations.disabled = gesturePreferencesPending;
  }
  if (gestureShowText) {
    if (!gesturePreferencesPending) gestureShowText.checked = gesturePreferences.showText;
    gestureShowText.disabled = gesturePreferencesPending;
  }
  if (gesturePlaySounds) {
    if (!gesturePreferencesPending) gesturePlaySounds.checked = gesturePreferences.playSounds;
    gesturePlaySounds.disabled = gesturePreferencesPending;
  }
  if (gestureOptionsReset) gestureOptionsReset.disabled = gesturePreferencesPending;
  const capabilityNotice = document.getElementById('webcam-capability-notice');
  if (capabilityNotice) capabilityNotice.hidden = webcamUseAllowed();
  renderHiddenAvatarOptions();
}

function gestureRequestKey(prefix) {
  const random = globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`;
  return `${prefix}-${random}`.slice(0, 96);
}

function stopCurrentGestureSounds(reason = 'gesture-preference-disabled') {
  if (activeGestureAudio?.audio) {
    activeGestureAudio.audio.__chatspacePlaybackInterruption = reason;
    activeGestureAudio.audio.pause();
    activeGestureAudio.btn?.classList.remove('playing');
    activeGestureAudio.btn?.style?.setProperty('--progress', '0deg');
    activeGestureAudio = null;
  }
  participants.forEach(person => {
    if (!person.speechAudio) return;
    person.speechAudio.__chatspacePlaybackInterruption = reason;
    person.speechAudio.pause?.();
    person.speechAudio = null;
  });
}

function stopGestureSoundsFromSender(userId, reason = 'gesture-sender-media-hidden') {
  const targetUserId = Number(userId);
  participants.forEach(person => {
    if (Number(person.user_id) !== targetUserId || !person.speechAudio) return;
    person.speechAudio.__chatspacePlaybackInterruption = reason;
    person.speechAudio.pause?.();
    person.speechAudio = null;
  });
}

function rerenderVisibleGesturePresentations() {
  if (!cfg) return;
  const previousTop = messagesEl?.scrollTop || 0;
  const pinned = messagesEl
    ? messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight < 24
    : true;
  const active = document.activeElement;
  const focusId = active?.id || '';
  renderActiveChat();
  if (messagesEl && !pinned) messagesEl.scrollTop = previousTop;
  if (focusId) document.getElementById(focusId)?.focus?.({ preventScroll: true });
  participants.forEach(person => {
    if (person.speechMessage?.message_type === 'gesture' && person.speechEl?.isConnected) {
      showAvatarSpeech(Number(person.id), person.speechMessage, { suppressGestureSound: true });
    }
  });
}

function applyGesturePresentationChange(change = {}) {
  if (change.previous?.playSounds && change.current?.playSounds === false) {
    stopCurrentGestureSounds();
  }
  if (
    (change.previousCapabilities?.allowGestures !== false && change.currentCapabilities?.allowGestures === false)
    || (
      change.previousCapabilities?.allowGestureAudioDelivery !== false
      && change.currentCapabilities?.allowGestureAudioDelivery === false
    )
  ) {
    stopCurrentGestureSounds('gesture-capability-disabled');
  }
  const previousSenders = new Set(change.previous?.hiddenSenderUserIds || []);
  for (const userId of change.current?.hiddenSenderUserIds || []) {
    if (!previousSenders.has(userId)) stopGestureSoundsFromSender(userId);
  }
  rerenderVisibleGesturePresentations();
  syncChatOptions();
}

function applyGestureCapabilityProjection(projection = {}, reason = 'gesture-capability') {
  if (!cfg) return null;
  gesturePresentation?.applyCapabilityProjection({
    ...projection,
    viewerUserId: Number(cfg.myUserId || 0),
  }, reason);
  cfg.gestureCapabilities = {
    ...projection,
    ...(gesturePresentation?.capabilities?.() || {}),
  };
  gestureCatalogController?.applyCapabilities?.(cfg.gestureCapabilities);
  return cfg.gestureCapabilities;
}

async function saveGesturePreferences(values, successMessage) {
  if (gesturePreferencesPending) return;
  if (gestureShowAnimations) gestureShowAnimations.checked = Boolean(values.showAnimations);
  if (gestureShowText) gestureShowText.checked = Boolean(values.showText);
  if (gesturePlaySounds) gesturePlaySounds.checked = Boolean(values.playSounds);
  gesturePreferencesPending = true;
  if (gestureOptionsStatus) gestureOptionsStatus.textContent = 'Saving gesture options…';
  syncChatOptions();
  try {
    const current = gesturePresentation?.preferences?.() || { version: 0 };
    const result = await apiPost('/api/gestures.php', {
      session_id: cfg.sessionId,
      join_token: cfg.myJoinToken,
      action: 'set_presentation_preferences',
      values: {
        show_animations: Boolean(values.showAnimations),
        show_text: Boolean(values.showText),
        play_sounds: Boolean(values.playSounds),
      },
      expected_version: Number(current.version || 0),
      request_key: gestureRequestKey('gesture-preferences'),
    });
    gesturePresentation?.applyServerProjection(result.preferences || {}, 'chat-options-save');
    if (gestureOptionsStatus) gestureOptionsStatus.textContent = successMessage || 'Gesture options saved.';
  } catch (error) {
    try {
      const qs = new URLSearchParams({ session_id: cfg.sessionId, join_token: cfg.myJoinToken, catalog: 'preferences' });
      const current = await runtimeRequestClient.getJson(`/api/gestures.php?${qs}`, {
        operation: 'refresh-gesture-preferences',
        endpointCategory: 'gestures',
      });
      gesturePresentation?.applyServerProjection(current.preferences || {}, 'preference-conflict-refresh');
    } catch {}
    if (gestureOptionsStatus) gestureOptionsStatus.textContent = error.message || 'Gesture options could not be saved.';
  } finally {
    gesturePreferencesPending = false;
    syncChatOptions();
  }
}

async function setGestureHidden(publicId, hidden, reason = 'gesture-message-action') {
  const current = gesturePresentation?.preferences?.();
  if (!current || !publicId) throw new Error('A stable gesture identity is required.');
  const result = await apiPost('/api/gestures.php', {
    session_id: cfg.sessionId,
    join_token: cfg.myJoinToken,
    action: hidden ? 'hide' : 'unhide',
    public_id: publicId,
    expected_version: Number(current.hiddenVersion || 0),
    request_key: gestureRequestKey(hidden ? 'hide-gesture' : 'show-gesture'),
  });
  gesturePresentation?.applyHiddenMutation(publicId, hidden, result.version, reason);
  return result;
}

async function setGestureSenderMediaHidden(targetUserId, hidden) {
  const current = gesturePresentation?.preferences?.();
  if (!current || !Number.isInteger(Number(targetUserId)) || Number(targetUserId) < 1) {
    throw new Error('A stable sender identity is required.');
  }
  const result = await apiPost('/api/gestures.php', {
    session_id: cfg.sessionId,
    join_token: cfg.myJoinToken,
    action: hidden ? 'hide_sender_media' : 'show_sender_media',
    target_user_id: Number(targetUserId),
    expected_version: Number(current.senderVisibilityVersion || 0),
    request_key: gestureRequestKey(hidden ? 'hide-gesture-sender' : 'show-gesture-sender'),
  });
  gesturePresentation?.applySenderVisibilityMutation(
    Number(targetUserId),
    hidden,
    Number(result.version || 0),
    'participant-action'
  );
}

async function showGestureFromMessage(publicId) {
  try {
    await setGestureHidden(publicId, false, 'gesture-message-show-again');
  } catch (error) {
    showWarning(error.message || 'This gesture could not be shown again.');
  }
}

function initializeGestureCatalog() {
  if (!GestureCatalogControllerClass || gestureCatalogController) return gestureCatalogController;
  gestureCatalogController = new GestureCatalogControllerClass({
    root: mediaPicker,
    features: cfg.gesturePart3?.features || {},
    part4Features: cfg.gesturePart4?.features || {},
    capabilities: cfg.gestureCapabilities || {},
    queryIdentity: { session_id: cfg.sessionId, join_token: cfg.myJoinToken },
    mediaUrl,
    getJson: (url, operation) => runtimeRequestClient.getJson(url, {
      operation,
      endpointCategory: 'gestures',
    }),
    mutate: body => apiPost('/api/gestures.php', {
      session_id: cfg.sessionId,
      join_token: cfg.myJoinToken,
      ...body,
    }),
    requestKey: gestureRequestKey,
    getPreferences: () => gesturePresentation?.preferences?.() || {},
    onPreferences: (projection, reason) => gesturePresentation?.applyServerProjection(projection, reason),
    onOrderVersion: (scope, version) => gesturePresentation?.applyOrderVersion(scope, version),
    onHiddenMutation: (publicId, hidden, version) => gesturePresentation?.applyHiddenMutation(publicId, hidden, version, 'hidden-catalog-bulk'),
    onHide: setGestureHidden,
    onSend: sendGesture,
    onCreate: () => openGestureEditor(),
    onEdit: gesture => openGestureEditor(gesture),
    onDownload: downloadGesturePackage,
    onDelete: openDeleteGestureModal,
    onTogglePublic: toggleGesturePublic,
    onAudio: toggleGestureAudio,
    management: {
      launch: document.getElementById('personal-gesture-create'),
      modal: document.getElementById('gesture-management-modal'),
      close: document.getElementById('gesture-management-close'),
      create: document.getElementById('gesture-management-create'),
      list: document.getElementById('gesture-management-list'),
      pager: document.getElementById('gesture-management-pager'),
      status: document.getElementById('gesture-management-status'),
    },
    catalogs: {
      server: {
        search: document.getElementById('server-gesture-search'),
        sort: document.getElementById('server-gesture-sort'),
        grid: document.getElementById('server-gesture-grid'),
        pager: document.getElementById('server-gesture-pager'),
        guidance: document.getElementById('server-gesture-reorder-guidance'),
        status: document.getElementById('server-gesture-status'),
      },
      personal: {
        search: document.getElementById('personal-gesture-search'),
        sort: document.getElementById('personal-gesture-sort'),
        grid: document.getElementById('personal-gesture-grid'),
        pager: document.getElementById('personal-gesture-pager'),
        guidance: document.getElementById('personal-gesture-reorder-guidance'),
        status: document.getElementById('personal-gesture-status'),
      },
    },
    actionMenu: document.getElementById('gesture-action-menu'),
    hiddenSection: document.getElementById('hidden-gesture-section'),
    hiddenSearch: document.getElementById('hidden-gesture-search'),
    hiddenList: document.getElementById('hidden-gesture-list'),
    hiddenCount: document.getElementById('hidden-gesture-count'),
    hiddenStatus: document.getElementById('hidden-gesture-status'),
    showSelected: document.getElementById('hidden-gesture-show-selected'),
    showAll: document.getElementById('hidden-gesture-show-all'),
    hiddenConfirm: document.getElementById('hidden-gesture-confirm'),
    hiddenConfirmYes: document.getElementById('hidden-gesture-confirm-yes'),
    hiddenConfirmNo: document.getElementById('hidden-gesture-confirm-no'),
  });
  gestureCatalogController.initialize();
  if (!gestureCatalogBroadcastChannel && 'BroadcastChannel' in window) {
    gestureCatalogBroadcastChannel = new BroadcastChannel('chatspace-gesture-catalog');
    gestureCatalogBroadcastChannel.addEventListener('message', event => {
      if (event.data?.type !== 'gesture-saved') return;
      gestureCatalogController?.refresh('personal');
      gestureCatalogController?.refresh('server');
    });
  }
  return gestureCatalogController;
}

function openGestureEditor(gesture = null) {
  if (cfg.gesturePart4?.features?.editor === false
      || cfg.gesturePart4?.extension?.state !== 'enabled') {
    showWarning('Gesture Maker is disabled through shared Settings.');
    return;
  }
  const publicId = String(gesture?.public_id || '');
  const path = publicId ? `/gesture_editor.php?id=${encodeURIComponent(publicId)}` : '/gesture_editor.php';
  const editor = window.open(appUrl(path), publicId ? `chatspace-gesture-editor-${publicId}` : 'chatspace-gesture-maker', 'popup,width=1080,height=820,resizable=yes,scrollbars=yes');
  if (!editor) {
    showWarning('Allow this site to open the Gesture Maker, then try again.');
    return;
  }
  editor.focus();
}

function downloadGesturePackage(gesture) {
  if (cfg.gesturePart4?.features?.user_package_download === false) {
    showWarning('Gesture package downloads are disabled through shared Settings.');
    return;
  }
  const publicId = String(gesture?.public_id || '');
  if (!publicId) return;
  const anchor = document.createElement('a');
  anchor.href = appUrl(`/api/gesture_packages.php?action=download&id=${encodeURIComponent(publicId)}&request_id=${encodeURIComponent(gestureRequestKey('gesture-package-download'))}`);
  anchor.download = `${String(gesture.catalog_filename || gesture.name || 'gesture').replace(/[^a-z0-9._-]+/gi, '-').replace(/^-+|-+$/g, '') || 'gesture'}.agst`;
  anchor.hidden = true;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
}

window.addEventListener('message', event => {
  if (event.origin !== window.location.origin || event.data?.type !== 'chatspace-gesture-saved') return;
  gestureCatalogController?.refresh('personal');
  gestureCatalogController?.refresh('server');
});

async function saveWebcamViewerPreferences({ showWebcams, receiveWebcams, resetOverrides = false }) {
  if (webcamPreferencesPending) return;
  webcamPreferencesPending = true;
  syncChatOptions();
  try {
    const current = voiceRuntime?.viewerPolicy?.preferences?.() || { version: 1 };
    const result = await apiPost('/api/webcam_preferences.php', {
      expected_version: current.version,
      show_webcams: Boolean(showWebcams),
      receive_webcams: Boolean(receiveWebcams),
    });
    const overridesCleared = resetOverrides
      ? voiceRuntime?.viewerPolicy?.resetParticipantOverrides('account-reset', { notify: false }) || false
      : false;
    voiceRuntime?.viewerPolicy?.applyServerProjection({
      capability: result.capability || voiceRuntime?.viewerPolicy?.capability?.(),
      preferences: result.preferences,
    }, resetOverrides ? 'account-webcam-options-reset' : 'account-webcam-preferences');
    if (overridesCleared && current.receiveWebcams === result.preferences?.receiveWebcams) {
      reconcileWebcamViewerPolicy({
        reason: 'account-webcam-options-reset',
        changes: { presentationChanged: true, receiveChanged: true },
      });
    }
  } catch (error) {
    showWarning(error.message || 'Could not save webcam options.');
  } finally {
    webcamPreferencesPending = false;
    syncChatOptions();
  }
}

function openChatOptions() {
  closeRoomMenu();
  syncChatOptions();
  chatOptionsModal?.classList.add('open');
  chatOptionsModal?.setAttribute('aria-hidden', 'false');
  chatOptionsModal?.querySelector('input:checked')?.focus();
}

function closeChatOptions() {
  chatOptionsModal?.classList.remove('open');
  chatOptionsModal?.setAttribute('aria-hidden', 'true');
}

document.getElementById('chat-options-btn')?.addEventListener('click', openChatOptions);
document.getElementById('chat-options-close')?.addEventListener('click', closeChatOptions);
showRoleMessageColors?.addEventListener('change', () => {
  chatAppearancePreferences = { ...chatAppearancePreferences, showRoleMessageColors: showRoleMessageColors.checked };
  persistChatAppearancePreference(CHAT_ROLE_MESSAGE_COLORS_KEY, showRoleMessageColors.checked);
  rerenderChatAppearance();
});
showRoleMessageTitles?.addEventListener('change', () => {
  chatAppearancePreferences = { ...chatAppearancePreferences, showRoleMessageTitles: showRoleMessageTitles.checked };
  persistChatAppearancePreference(CHAT_ROLE_MESSAGE_TITLES_KEY, showRoleMessageTitles.checked);
  rerenderChatAppearance();
});
showIdentityNameplates?.addEventListener('change', () => {
  chatAppearancePreferences = { ...chatAppearancePreferences, showIdentityNameplates: showIdentityNameplates.checked };
  persistChatAppearancePreference(CHAT_IDENTITY_NAMEPLATES_KEY, showIdentityNameplates.checked);
  rerenderChatAppearance();
});
document.getElementById('chat-tabs')?.addEventListener('click', () => window.setTimeout(updateComposerState, 0));
const chatTabsStateObserver = new MutationObserver(() => updateComposerState());
if (document.getElementById('chat-tabs')) {
  chatTabsStateObserver.observe(document.getElementById('chat-tabs'), {
    subtree: true,
    attributes: true,
    attributeFilter: ['class'],
  });
  gameRuntime?.webcams?.reconcile?.({ reason: change.reason || 'viewer-policy' });
}
chatOptionsModal?.addEventListener('click', event => {
  if (event.target === chatOptionsModal) closeChatOptions();
});
document.getElementById('hidden-avatar-show-all')?.addEventListener('click', () => {
  document.getElementById('hidden-avatar-confirm')?.removeAttribute('hidden');
  document.getElementById('hidden-avatar-show-all-confirm')?.focus();
});
document.getElementById('hidden-avatar-show-all-cancel')?.addEventListener('click', () => {
  document.getElementById('hidden-avatar-confirm')?.setAttribute('hidden', '');
});
document.getElementById('hidden-avatar-show-all-confirm')?.addEventListener('click', async () => {
  const button = document.getElementById('hidden-avatar-show-all-confirm');
  button.disabled = true;
  try {
    await avatarRuntime?.visibility?.showAll();
    document.getElementById('hidden-avatar-confirm')?.setAttribute('hidden', '');
    const status = document.getElementById('hidden-avatar-status');
    if (status) status.textContent = 'All hidden avatars are visible.';
  } catch (error) {
    showWarning(error?.message || 'Hidden avatars could not be reset.');
  } finally {
    button.disabled = false;
  }
});
document.querySelectorAll('input[name="chat-display-mode"]').forEach(input => {
  input.addEventListener('change', event => {
    if (!event.currentTarget.checked) return;
    chatMessageRenderer().setDisplayMode(event.currentTarget.value);
    syncChatOptions();
  });
  input.addEventListener('keydown', event => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    event.currentTarget.checked = true;
    event.currentTarget.dispatchEvent(new Event('change', { bubbles: true }));
  });
});
[gestureShowAnimations, gestureShowText, gesturePlaySounds].forEach(input => {
  input?.addEventListener('change', () => {
    saveGesturePreferences({
      showAnimations: Boolean(gestureShowAnimations?.checked),
      showText: Boolean(gestureShowText?.checked),
      playSounds: Boolean(gesturePlaySounds?.checked),
    }, 'Gesture options saved.');
  });
});
gestureOptionsReset?.addEventListener('click', () => {
  saveGesturePreferences({ showAnimations: true, showText: true, playSounds: true }, 'Gesture options reset to defaults.');
});
document.querySelectorAll('input[name="webcam-visibility-mode"]').forEach(input => {
  input.addEventListener('change', event => {
    if (!event.currentTarget.checked) return;
    const current = voiceRuntime?.viewerPolicy?.preferences?.() || { receiveWebcams: true };
    saveWebcamViewerPreferences({
      showWebcams: event.currentTarget.value === 'show',
      receiveWebcams: current.receiveWebcams,
    });
  });
  input.addEventListener('keydown', event => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    event.currentTarget.checked = true;
    event.currentTarget.dispatchEvent(new Event('change', { bubbles: true }));
  });
});
document.querySelectorAll('input[name="webcam-receive-mode"]').forEach(input => {
  input.addEventListener('change', event => {
    if (!event.currentTarget.checked) return;
    const current = voiceRuntime?.viewerPolicy?.preferences?.() || { showWebcams: true };
    saveWebcamViewerPreferences({
      showWebcams: current.showWebcams,
      receiveWebcams: event.currentTarget.value === 'receive',
    });
  });
  input.addEventListener('keydown', event => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    event.currentTarget.checked = true;
    event.currentTarget.dispatchEvent(new Event('change', { bubbles: true }));
  });
});
webcamOptionsReset?.addEventListener('click', () => {
  saveWebcamViewerPreferences({
    showWebcams: true,
    receiveWebcams: true,
    resetOverrides: true,
  });
});

document.getElementById('room-action-btn')?.addEventListener('click', e => {
  e.stopPropagation();
  if (roomActionMenu?.classList.contains('visible')) closeRoomActionMenu();
  else openRoomActionMenu();
});

document.getElementById('lock-session-btn')?.addEventListener('click', lockSession);

async function openReportProblem() {
  closeRoomMenu();
  reportProblemStatus.textContent = '';
  reportProblemScreenshot.checked = false;
  reportProblemScreenshot.closest('.diagnostic-screenshot-option').hidden = true;
  try {
    const config = await runtimeRequestClient.getJson('/api/runtime_issues.php?action=config', { operation: 'diagnostic-screenshot-config' });
    reportProblemScreenshot.closest('.diagnostic-screenshot-option').hidden = !config?.screenshots?.enabled;
  } catch {
    // Reporting remains available without optional screenshot evidence.
  }
  reportProblemModal.classList.add('open');
  reportProblemModal.setAttribute('aria-hidden', 'false');
  reportProblemSummary.focus();
}

function closeReportProblem() {
  reportProblemModal?.classList.remove('open');
  reportProblemModal?.setAttribute('aria-hidden', 'true');
}

document.getElementById('report-problem-btn')?.addEventListener('click', openReportProblem);
document.getElementById('report-problem-close')?.addEventListener('click', closeReportProblem);
reportProblemModal?.addEventListener('click', event => {
  if (event.target === reportProblemModal) closeReportProblem();
});
reportProblemForm?.addEventListener('submit', async event => {
  event.preventDefault();
  reportProblemStatus.textContent = 'Submitting…';
  try {
    await runtimeIssueCaptureService.report({ summary: reportProblemSummary.value, includeScreenshot: reportProblemScreenshot.checked });
    reportProblemStatus.textContent = 'Report submitted.';
    reportProblemForm.reset();
  } catch (error) {
    reportProblemStatus.textContent = error?.message || 'Report could not be submitted.';
  }
});

sessionLockForm?.addEventListener('submit', e => {
  e.preventDefault();
  unlockSession();
});

document.getElementById('emoji-btn').addEventListener('click', e => {
  e.stopPropagation();
  if (!mediaPicker.hidden) closeMediaPicker();
  else openEmojiPicker();
});

document.getElementById('attach-btn').addEventListener('click', e => {
  e.stopPropagation();
  closeFloatingShells(['message', 'attach']);
  attachMenu.hidden = !attachMenu.hidden;
});

document.getElementById('game-start-btn')?.addEventListener('click', e => {
  e.stopPropagation();
  closeFloatingShells(['message', 'game']);
  if (!gameStartMenu) return;
  if (gameStartMenu.hidden) openGameStartMenu();
  else closeGameStartMenu();
});

gamePickerSearch?.addEventListener('input', renderGamePickerCatalog);
document.querySelectorAll('[data-game-filter]').forEach(button => {
  button.addEventListener('click', () => {
    gameCatalogFilter = String(button.dataset.gameFilter || 'all');
    document.querySelectorAll('[data-game-filter]').forEach(filter => {
      const active = filter === button;
      filter.classList.toggle('is-active', active);
      filter.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    renderGamePickerCatalog();
  });
});
document.getElementById('game-picker-close')?.addEventListener('click', () => closeGameStartMenu());
document.getElementById('game-picker-cancel')?.addEventListener('click', () => closeGameStartMenu());
document.getElementById('game-picker-center')?.addEventListener('click', centerGameStartMenu);
gamePickerContinue?.addEventListener('click', () => {
  const selectedGame = gameCatalogDefinitions.find(game => String(game.key || '') === selectedGameCatalogKey);
  if (!selectedGame) return;
  closeGameStartMenu();
  openGameModeDialog(selectedGame);
});
document.querySelectorAll('[data-game-picker-drag-handle]').forEach(handle => {
  handle.addEventListener('pointerdown', beginGamePickerDrag);
  handle.addEventListener('pointermove', moveGamePicker);
  handle.addEventListener('pointerup', endGamePickerDrag);
  handle.addEventListener('pointercancel', endGamePickerDrag);
  handle.addEventListener('dblclick', centerGameStartMenu);
  handle.addEventListener('keydown', event => {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    event.preventDefault();
    centerGameStartMenu();
  });
});
document.getElementById('room-games-btn')?.addEventListener('click', event => {
  event.stopPropagation();
  if (!roomGamesMenu) return;
  if (roomGamesMenu.hidden) openRoomGamesMenu();
  else closeRoomGamesMenu();
});
document.getElementById('room-games-close')?.addEventListener('click', () => closeRoomGamesMenu());
document.getElementById('room-games-dismiss')?.addEventListener('click', () => closeRoomGamesMenu());
document.getElementById('room-games-center')?.addEventListener('click', centerRoomGamesMenu);
document.querySelectorAll('[data-room-games-drag-handle]').forEach(handle => {
  handle.addEventListener('pointerdown', beginRoomGamesDrag);
  handle.addEventListener('pointermove', moveRoomGamesMenu);
  handle.addEventListener('pointerup', endRoomGamesDrag);
  handle.addEventListener('pointercancel', endRoomGamesDrag);
  handle.addEventListener('dblclick', centerRoomGamesMenu);
  handle.addEventListener('keydown', event => {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    event.preventDefault();
    centerRoomGamesMenu();
  });
});
window.addEventListener('resize', () => {
  if (gameStartMenu && !gameStartMenu.hidden) centerGameStartMenu();
  if (roomGamesMenu && !roomGamesMenu.hidden) centerRoomGamesMenu();
  scheduleAvatarViewportReflow();
});

gameModeForm?.addEventListener('change', event => {
  const input = event.target;
  if (input?.name !== 'game_mode' || input.type !== 'radio' || !input.checked || input.disabled) return;
  syncGameModeInactivityProfile(input.value);
});

gameModeForm?.addEventListener('submit', async event => {
  event.preventDefault();
  if (!gameModeDefinition) return;
  const selectedGame = gameModeDefinition;
  const submit = gameModeForm.querySelector('button[type="submit"]');
  const mode = String(new FormData(gameModeForm).get('game_mode') || 'practice');
  syncGameModeInactivityProfile(mode);
  if (submit) submit.disabled = true;
  if (gameModeStatus) gameModeStatus.textContent = 'Starting game…';
  try {
    closeGameModeDialog({ restoreFocus: false });
    await gameRuntime?.lifecycle?.startGame(selectedGame.key, mode, selectedGameModeSettings());
  } catch (error) {
    gameModeDefinition = selectedGame;
    gameModeModal?.classList.add('open');
    gameModeModal?.setAttribute('aria-hidden', 'false');
    if (gameModeStatus) gameModeStatus.textContent = error?.message || 'The game could not be started.';
    const box = gameModeModal?.querySelector('.game-mode-box');
    if (box) {
      box.scrollTop = 0;
      box.scrollLeft = 0;
    }
  } finally {
    if (submit) submit.disabled = false;
  }
});
document.getElementById('game-mode-cancel')?.addEventListener('click', () => closeGameModeDialog());
document.getElementById('game-mode-close')?.addEventListener('click', () => closeGameModeDialog());
gameModeModal?.addEventListener('click', event => {
  if (event.target === gameModeModal) closeGameModeDialog();
});
gameModeModal?.addEventListener('keydown', event => handleTransferModalKeydown(
  gameModeModal,
  event,
  () => closeGameModeDialog()
));

document.getElementById('game-records-close')?.addEventListener('click', () => closeGameRecords());
gameRecordsModal?.addEventListener('click', event => {
  if (event.target === gameRecordsModal) closeGameRecords();
});
gameRecordsModal?.addEventListener('keydown', event => handleTransferModalKeydown(
  gameRecordsModal,
  event,
  () => closeGameRecords()
));

document.getElementById('attach-file-btn').addEventListener('click', () => {
  closeAttachMenu();
  chatFileInput.click();
});

document.getElementById('attach-voice-btn').addEventListener('click', () => {
  closeAttachMenu();
  startVoiceNote().catch(err => alert(err.message || err));
});

function insertEmoji(emoji) {
  const input = document.getElementById('chat-input');
  const start = input.selectionStart ?? input.value.length;
  const end = input.selectionEnd ?? input.value.length;
  input.setRangeText(emoji, start, end, 'end');
  const next = start + emoji.length;
  input.setSelectionRange(Math.min(next, input.value.length), Math.min(next, input.value.length));
  input.focus();
  updateComposerState();
}

let customEmojiPickerPromise = null;
function openCustomEmojiPicker() {
  if (!customEmojiPickerPromise) {
    customEmojiPickerPromise = import(appUrl('/assets/js/custom-emojis.js?v=20260914-custom-emojis'))
      .then(({ CustomEmojiPicker }) => new CustomEmojiPicker({
        root: document.getElementById('custom-emoji-picker'),
        appUrl,
        upload: apiUpload,
        onSelect(emoji) {
          const input = document.getElementById('chat-input');
          const token = `[emoji:${emoji.id}:${emoji.name}]`;
          const selectionLength = (input.selectionEnd ?? input.value.length) - (input.selectionStart ?? input.value.length);
          if (input.maxLength >= 0 && input.value.length - selectionLength + token.length > input.maxLength) {
            throw new Error('There is not enough space left in this message for that custom emoji.');
          }
          insertEmoji(token);
        },
      }))
      .catch(error => {
        customEmojiPickerPromise = null;
        const panel = document.getElementById('custom-emoji-picker');
        if (panel) panel.textContent = error.message || 'Custom emojis could not be opened. Try the tab again.';
        return null;
      });
  }
  return customEmojiPickerPromise.then(picker => picker?.activate());
}

function renderEmojiGrid() {
  if (!emojiGrid || emojiGrid.dataset.rendered === '1') return;
  emojiGrid.innerHTML = '';
  EMOJI_OPTIONS.forEach(emoji => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = emoji;
    btn.addEventListener('click', () => insertEmoji(emoji));
    emojiGrid.appendChild(btn);
  });
  emojiGrid.dataset.rendered = '1';
}

function setMediaTab(tab) {
  if (mediaSearchInput && ['gifs', 'gestures'].includes(activeMediaTab)) {
    mediaSearchValues[activeMediaTab] = mediaSearchInput.value;
  }
  activeMediaTab = tab;
  mediaPicker?.classList.remove('media-tab-gifs', 'media-tab-gestures', 'media-tab-server-gestures', 'media-tab-personal-gestures', 'media-tab-emojis', 'media-tab-custom-emojis');
  mediaPicker?.classList.add(`media-tab-${tab}`);
  mediaPicker?.querySelectorAll('[data-media-tab]').forEach(btn => {
    const selected = btn.dataset.mediaTab === tab;
    btn.classList.toggle('active', selected);
    btn.setAttribute('aria-selected', selected ? 'true' : 'false');
    btn.tabIndex = selected ? 0 : -1;
  });
  mediaPicker?.querySelectorAll('.media-panel').forEach(panel => panel.classList.toggle('active', panel.id === `media-panel-${tab}`));
  if (mediaSearchInput) {
    mediaSearchInput.placeholder = tab === 'gifs' ? 'Search GIFs' : (tab === 'gestures' ? 'Search gesture text' : 'Search emojis');
    mediaSearchInput.value = ['gifs', 'gestures'].includes(tab) ? (mediaSearchValues[tab] || '') : '';
    mediaSearchInput.closest('.media-search-row').hidden = !['gifs', 'gestures'].includes(tab);
  }
  if (tab === 'gifs' && gifResults && !cfg?.gifPicker?.enabled) {
    gifResults.innerHTML = '<div class="minor">GIFs are not configured.</div>';
  }
  if (tab === 'gestures') {
    if (!gesturePaletteLoaded) {
      gesturePage = 1;
      loadGestures();
    }
  }
  if (tab === 'server-gestures') gestureCatalogController?.activate('server');
  if (tab === 'personal-gestures') gestureCatalogController?.activate('personal');
  if (tab === 'emojis') renderEmojiGrid();
  if (tab === 'custom-emojis') openCustomEmojiPicker();
}

mediaPicker?.querySelectorAll('[data-media-tab]').forEach(btn => {
  btn.addEventListener('click', () => setMediaTab(btn.dataset.mediaTab || 'gifs'));
  btn.addEventListener('keydown', event => {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    const tabs = [...mediaPicker.querySelectorAll('[data-media-tab]:not([hidden])')];
    const current = tabs.indexOf(event.currentTarget);
    const index = event.key === 'Home' ? 0
      : (event.key === 'End' ? tabs.length - 1 : (current + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length);
    event.preventDefault();
    tabs[index]?.focus();
    tabs[index]?.click();
  });
});

async function searchGifs(query) {
  if (!gifResults || !cfg?.gifPicker?.enabled) return;
  const q = query.trim();
  gifSearchState = null;
  gifResults.scrollTop = 0;
  if (!q) {
    gifResults.innerHTML = '<div class="minor">Search for a GIF.</div>';
    return;
  }
  gifResults.innerHTML = '';
  const status = document.createElement('button');
  status.type = 'button';
  status.className = 'minor';
  status.setAttribute('aria-live', 'polite');
  const state = { q, provider: cfg.gifPicker.defaultProvider || 'giphy', cursor: '',
    loading: false, failed: false, more: true, seen: new Set(), cursors: new Set(), status };
  gifSearchState = state;
  status.addEventListener('click', () => loadGifBatch(state));
  gifResults.appendChild(status);
  await loadGifBatch(state);
}

async function loadGifBatch(state) {
  if (state !== gifSearchState || state.loading || !state.more || activeMediaTab !== 'gifs'
      || mediaPicker?.hidden || (mediaSearchInput?.value || '').trim() !== state.q) return;
  state.loading = true;
  state.failed = false;
  state.status.disabled = true;
  state.status.textContent = state.seen.size ? 'Loading more GIFs...' : 'Searching...';
  try {
    const qs = new URLSearchParams({
      session_id: cfg.sessionId,
      join_token: cfg.myJoinToken,
      q: state.q,
      provider: state.provider,
      cursor: state.cursor,
    });
    const data = await runtimeRequestClient.getJson(`/api/gif_search.php?${qs}`, {
      operation: 'search-gifs',
      endpointCategory: 'gif-search',
    });
    if (state !== gifSearchState) return;
    if (data.error) throw new Error(data.error);
    const results = Array.isArray(data.results) ? data.results : [];
    state.provider = data.provider || state.provider;
    state.cursors.add(state.cursor);
    const next = data.next_cursor == null ? '' : String(data.next_cursor);
    state.more = next !== '' && !state.cursors.has(next);
    state.cursor = next;
    results.forEach(result => {
      const key = `${result.provider || state.provider}:${result.id || result.url}`;
      if (!result.url || state.seen.has(key)) return;
      state.seen.add(key);
      const btn = document.createElement('button');
      btn.className = 'gif-result';
      btn.type = 'button';
      btn.innerHTML = `<img src="${esc(result.preview || result.url)}" alt="${esc(result.title || 'GIF')}">`;
      btn.addEventListener('click', () => sendGif(result));
      gifResults.insertBefore(btn, state.status);
    });
    state.status.disabled = !state.more;
    state.status.textContent = state.more ? 'Load more GIFs'
      : (state.seen.size ? 'No more GIFs.' : 'No GIFs found.');
  } catch (err) {
    if (state !== gifSearchState) return;
    state.failed = true;
    state.status.disabled = false;
    state.status.textContent = `${err.message || 'GIF search failed.'} Click to retry.`;
  } finally {
    state.loading = false;
  }
}

gifResults?.addEventListener('scroll', () => {
  const state = gifSearchState;
  if (state && !state.failed && gifResults.scrollHeight - gifResults.scrollTop - gifResults.clientHeight < 240) {
    loadGifBatch(state);
  }
}, { passive: true });

mediaSearchInput?.addEventListener('input', e => {
  if (activeMediaTab === 'gifs') {
    gifSearchState = null;
    mediaSearchValues.gifs = e.target.value;
    clearTimeout(gifSearchTimer);
    gifSearchTimer = setTimeout(() => searchGifs(e.target.value), 250);
  }
  if (activeMediaTab === 'gestures') {
    mediaSearchValues.gestures = e.target.value;
    clearTimeout(gestureSearchTimer);
    gestureSearchTimer = setTimeout(() => {
      gesturePage = 1;
      loadGestures();
    }, 250);
  }
});

async function sendGif(result) {
  closeMediaPicker();
  await chatMediaSend().sendGif(result, activeChatKey());
}

function currentGestureQuery() {
  return activeMediaTab === 'gestures' ? (mediaSearchInput?.value || '') : '';
}

async function loadGestures() {
  if (!gestureGrid) return;
  gestureGrid.innerHTML = '<div class="gif-loading">Loading gestures...</div>';
  try {
    const qs = new URLSearchParams({
      session_id: cfg.sessionId,
      join_token: cfg.myJoinToken,
      page: String(gesturePage),
      q: currentGestureQuery(),
    });
    const data = await runtimeRequestClient.getJson(`/api/gestures.php?${qs}`, {
      operation: 'load-gestures',
      endpointCategory: 'gestures',
    });
    gestureHasMore = Boolean(data.has_more);
    gestureOwnedCount = Number(data.owned_count || 0);
    gestureOwnedLimit = data.owned_limit_enforced === false ? null : Number(data.owned_limit ?? 50);
    if (gesturePageLabel) gesturePageLabel.textContent = `Page ${data.page || gesturePage}`;
    if (gesturePrev) gesturePrev.disabled = gesturePage <= 1;
    if (gestureNext) gestureNext.disabled = !gestureHasMore;
    renderGestureGrid(data.gestures || []);
    gesturePaletteLoaded = true;
  } catch (err) {
    gestureGrid.innerHTML = `<div class="minor">${esc(err.message || 'Gestures could not load.')}</div>`;
  }
}

function gestureTileLabel(gesture) {
  return gesture.text || gesture.name || 'Gesture';
}

function gestureTileSelector(id) {
  return `.gesture-tile[data-gesture-id="${String(Number(id) || 0)}"]`;
}

function updateGestureUploadTileState() {
  const uploadTile = document.querySelector('#personal-gesture-grid .gesture-upload-tile, #gesture-grid .gesture-upload-tile');
  if (!uploadTile) return;
  const limitReached = gestureOwnedLimit !== null && gestureOwnedCount >= gestureOwnedLimit;
  uploadTile.disabled = limitReached;
  uploadTile.title = limitReached ? 'Remove some gestures to make room.' : 'Create Gesture';
  uploadTile.querySelector('em')?.replaceChildren(document.createTextNode(gestureOwnedLimit === null ? `${gestureOwnedCount} / no limit` : `${gestureOwnedCount}/${gestureOwnedLimit}`));
}

function ensureGestureEmptyState() {
  if (!gestureGrid) return;
  const hasGestureTiles = Boolean(gestureGrid.querySelector('.gesture-tile'));
  let empty = gestureGrid.querySelector('.gesture-empty');
  if (hasGestureTiles) {
    empty?.remove();
    return;
  }
  if (!empty) {
    empty = document.createElement('div');
    empty.className = 'gesture-empty';
    empty.textContent = 'No gestures found.';
    gestureGrid.appendChild(empty);
  }
}

function createGestureTile(gesture) {
  const tile = document.createElement('div');
  tile.className = `gesture-tile${gesture.mine ? ' mine' : ''}${gesture.is_public ? ' public' : ''}`;
  tile.dataset.gestureId = gesture.id;
  tile.innerHTML = `
    <button class="gesture-play" type="button" aria-label="Send ${esc(gestureTileLabel(gesture))}">
      <img src="${esc(mediaUrl(gesture.gif_path || gesture.gif_url))}" alt="${esc(gestureTileLabel(gesture))}">
    </button>
    ${gesture.mine ? '<button class="gesture-star" type="button" title="My gesture">★</button>' : ''}
    <button class="gesture-global" type="button" title="${gesture.is_public ? 'Community gesture' : 'Private gesture'}"${gesture.mine ? '' : ' disabled'}>🌐</button>
    ${gesture.audio_is_silent ? '' : '<button class="gesture-audio" type="button" title="Play gesture audio"><span>🎧</span></button>'}
  `;
  tile.addEventListener('click', e => {
    if (e.target.closest('.gesture-star, .gesture-global, .gesture-audio')) return;
    sendGesture(gesture);
  });
  tile.addEventListener('mouseenter', () => {
    if (gestureTray) gestureTray.textContent = gestureTileLabel(gesture);
  });
  tile.addEventListener('mouseleave', () => {
    if (gestureTray) gestureTray.textContent = '';
  });
  tile.querySelector('.gesture-star')?.addEventListener('click', e => {
    e.stopPropagation();
    openDeleteGestureModal(gesture);
  });
  tile.querySelector('.gesture-global')?.addEventListener('click', e => {
    e.stopPropagation();
    if (gesture.mine) toggleGesturePublic(gesture, !gesture.is_public);
  });
  tile.querySelector('.gesture-audio')?.addEventListener('click', e => {
    e.stopPropagation();
    toggleGestureAudio(gesture, e.currentTarget);
  });
  return tile;
}

function replaceGestureTile(gesture) {
  const existing = gestureGrid?.querySelector(gestureTileSelector(gesture.id));
  if (!existing) return false;
  existing.replaceWith(createGestureTile(gesture));
  ensureGestureEmptyState();
  return true;
}

function renderGestureGrid(gestures) {
  gestureGrid.innerHTML = '';
  const uploadTile = document.createElement('button');
  uploadTile.className = 'gesture-upload-tile';
  uploadTile.type = 'button';
  const limitReached = gestureOwnedLimit !== null && gestureOwnedCount >= gestureOwnedLimit;
  uploadTile.disabled = limitReached;
  uploadTile.title = limitReached ? 'Remove some gestures to make room.' : 'Create Gesture';
  uploadTile.innerHTML = `<span>+</span><small>Create Gesture</small><em>${gestureOwnedLimit === null ? `${gestureOwnedCount} / no limit` : `${gestureOwnedCount}/${gestureOwnedLimit}`}</em><div class="gesture-upload-progress"><i></i></div>`;
  uploadTile.addEventListener('click', () => {
    if (limitReached) return;
    openGestureEditor();
  });
  gestureGrid.appendChild(uploadTile);

  if (!gestures.length) {
    const empty = document.createElement('div');
    empty.className = 'gesture-empty';
    empty.textContent = 'No gestures found.';
    gestureGrid.appendChild(empty);
    return;
  }

  gestures.forEach(gesture => {
    gestureGrid.appendChild(createGestureTile(gesture));
  });
}

async function uploadGesture(file) {
  if (gestureOwnedLimit !== null && gestureOwnedCount >= gestureOwnedLimit) {
    alert('Gesture limit reached. Remove some gestures to make room.');
    return;
  }
  const uploadTile = gestureGrid?.querySelector('.gesture-upload-tile');
  const bar = uploadTile?.querySelector('.gesture-upload-progress i');
  if (uploadTile) uploadTile.classList.add('uploading');
  if (bar) bar.style.width = '0%';
  const formData = new FormData();
  formData.append('session_id', cfg.sessionId);
  formData.append('join_token', cfg.myJoinToken);
  formData.append('_csrf', CSRF_TOKEN);
  formData.append('gesture', file);
  await new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.upload.addEventListener('progress', event => {
      if (bar && event.lengthComputable) bar.style.width = `${Math.max(4, Math.round((event.loaded / event.total) * 100))}%`;
    });
    xhr.addEventListener('load', () => {
      let data = {};
      try { data = JSON.parse(xhr.responseText || '{}'); } catch { reject(new Error('Gesture response was not readable.')); return; }
      if (xhr.status >= 200 && xhr.status < 400 && !data.error) resolve(data);
      else reject(new Error(data.error || 'Gesture upload failed.'));
    });
    xhr.addEventListener('error', () => reject(new Error('Gesture upload failed.')));
    xhr.open('POST', appUrl('/api/gestures.php'));
    xhr.setRequestHeader('X-CSRF-Token', CSRF_TOKEN);
    xhr.send(formData);
  });
  if (bar) bar.style.width = '100%';
  if (gestureCatalogController && cfg.gesturePart3?.features?.enhanced_picker !== false) {
    await gestureCatalogController.refresh('personal');
  } else {
    await loadGestures();
  }
}

gestureFileInput?.addEventListener('change', () => {
  const file = gestureFileInput.files && gestureFileInput.files[0];
  gestureFileInput.value = '';
  if (!file) return;
  uploadGesture(file).catch(err => alert(err.message || err));
});

document.getElementById('personal-gesture-create')?.addEventListener('click', () => {
  void gestureCatalogController?.openManagement();
});

gesturePrev?.addEventListener('click', () => {
  if (gesturePage <= 1) return;
  gesturePage -= 1;
  loadGestures();
});

gestureNext?.addEventListener('click', () => {
  if (!gestureHasMore) return;
  gesturePage += 1;
  loadGestures();
});

async function toggleGesturePublic(gesture, isPublic) {
  const tile = document.querySelector(`[data-gesture-public-id="${CSS.escape(String(gesture.public_id || ''))}"]`) || gestureGrid?.querySelector(gestureTileSelector(gesture.id));
  const toggle = tile?.querySelector('.gesture-global');
  if (toggle) toggle.disabled = true;
  try {
    const data = await apiPost('/api/gestures.php', { session_id: cfg.sessionId, join_token: cfg.myJoinToken, action: 'toggle_public', gesture_id: gesture.id, is_public: isPublic });
    if (gestureCatalogController && cfg.gesturePart3?.features?.enhanced_picker !== false) {
      await gestureCatalogController.refresh('personal');
      await gestureCatalogController.refresh('server');
    } else {
      replaceGestureTile(data.gesture || { ...gesture, is_public: isPublic });
    }
  } catch (err) {
    if (toggle) toggle.disabled = false;
    alert(err.message || err);
  }
}

function openDeleteGestureModal(gesture) {
  pendingGestureDelete = gesture;
  if (gestureDeleteMessage) {
    gestureDeleteMessage.textContent = gesture.is_public
      ? 'Are you sure you want to delete this gesture? It is public, so this removes it from everyone.'
      : 'Are you sure you want to delete this gesture?';
  }
  gestureDeleteModal?.classList.add('open');
}

function closeDeleteGestureModal() {
  pendingGestureDelete = null;
  gestureDeleteModal?.classList.remove('open');
}

async function deleteGesture(gesture) {
  try {
    await apiPost('/api/gestures.php', { session_id: cfg.sessionId, join_token: cfg.myJoinToken, action: 'delete', gesture_id: gesture.id });
    document.querySelector(`[data-gesture-public-id="${CSS.escape(String(gesture.public_id || ''))}"]`)?.remove();
    gestureGrid?.querySelector(gestureTileSelector(gesture.id))?.remove();
    if (gesture.mine) {
      gestureOwnedCount = Math.max(0, gestureOwnedCount - 1);
      updateGestureUploadTileState();
    }
    ensureGestureEmptyState();
    if (gestureCatalogController && cfg.gesturePart3?.features?.enhanced_picker !== false) {
      await gestureCatalogController.refresh('personal');
      await gestureCatalogController.refresh('server');
    }
  } catch (err) {
    alert(err.message || err);
  }
}

bindModalCloseButtons(['gesture-delete-close', 'gesture-delete-cancel'], closeDeleteGestureModal);
gestureDeleteConfirm?.addEventListener('click', async () => {
  const gesture = pendingGestureDelete;
  if (!gesture) return;
  gestureDeleteConfirm.disabled = true;
  try {
    await deleteGesture(gesture);
    closeDeleteGestureModal();
  } finally {
    gestureDeleteConfirm.disabled = false;
  }
});

function toggleGestureAudio(gesture, btn) {
  if (!gesture.audio_path) return;
  if (cfg.gestureCapabilities?.allowGestureAudioDelivery === false) {
    if (gestureTray) gestureTray.textContent = 'Gesture audio is disabled through shared Settings.';
    return;
  }
  if (gesturePresentation?.preferences?.().playSounds === false) {
    if (gestureTray) gestureTray.textContent = 'Gesture sounds are turned off in Chat Options.';
    return;
  }
  if (activeGestureAudio?.btn === btn) {
    activeGestureAudio.audio.__chatspacePlaybackInterruption = 'gesture-toggle-off';
    activeGestureAudio.audio.pause();
    activeGestureAudio = null;
    btn.classList.remove('playing');
    btn.style.setProperty('--progress', '0deg');
    return;
  }
  if (activeGestureAudio) {
    activeGestureAudio.audio.__chatspacePlaybackInterruption = 'gesture-replaced';
    activeGestureAudio.audio.pause();
    activeGestureAudio.btn.classList.remove('playing');
    activeGestureAudio.btn.style.setProperty('--progress', '0deg');
  }
  const audio = new Audio(mediaUrl(gesture.audio_path));
  activeGestureAudio = { audio, btn };
  btn.classList.add('playing');
  const update = () => {
    if (activeGestureAudio?.audio !== audio) return;
    const ratio = audio.duration ? audio.currentTime / audio.duration : 0;
    btn.style.setProperty('--progress', `${Math.round(ratio * 360)}deg`);
    if (!audio.paused && !audio.ended) requestAnimationFrame(update);
  };
  audio.addEventListener('ended', () => {
    btn.classList.remove('playing');
    btn.style.setProperty('--progress', '0deg');
    if (activeGestureAudio?.audio === audio) activeGestureAudio = null;
  }, { once: true });
  audio.play().then(update).catch(err => {
    const intentionalAbort = err?.name === 'AbortError' && Boolean(audio.__chatspacePlaybackInterruption);
    if (intentionalAbort) return;
    console.error('Gesture audio playback failed.', err);
    alert(err.message || 'Could not play audio.');
  });
}

async function sendGesture(gesture) {
  closeMediaPicker();
  await chatMediaSend().sendGesture(gesture, activeChatKey());
}

// Capture before avatar drag/control handlers can stop bubbling. Pointer events
// inside embedded websites do not reach this document; blur handles that case.
document.addEventListener('pointerdown', e => {
  if (ctxMenu && !isInsideAvatarContextMenu(e.target)) closeContextMenu();
}, true);
window.addEventListener('blur', () => closeContextMenu());

document.addEventListener('click', e => {
  if (!isInsideAvatarContextMenu(e.target)) closeContextMenu();
  if (!textCtxMenu.contains(e.target)) closeTextContextMenu();
  if (msgActionMenu && !msgActionMenu.contains(e.target) && !e.target.closest('.msg-options')) closeMessageActionMenu();
  if (tabCtxMenu && !tabCtxMenu.contains(e.target)) closeTabContextMenu();
  if (!roomMenu.contains(e.target) && !e.target.closest('#room-menu-btn')) closeRoomMenu();
  if (roomActionMenu && !roomActionMenu.contains(e.target) && !e.target.closest('#room-action-btn')) closeRoomActionMenu();
  if (
    mediaPicker
    && !mediaPicker.contains(e.target)
    && !e.target.closest('#gesture-action-menu')
    && !e.target.closest('#emoji-btn')
    && !e.target.closest('#gesture-management-modal')
    && !e.target.closest('#gesture-delete-modal')
  ) closeMediaPicker();
  if (!attachMenu.contains(e.target) && !e.target.closest('#attach-btn')) closeAttachMenu();
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    if (roomGamesMenu && !roomGamesMenu.hidden) {
      e.preventDefault();
      closeRoomGamesMenu();
      return;
    }
    if (webcamAudienceModal?.classList.contains('open')) {
      e.preventDefault();
      closeWebcamAudienceChooser(false);
      return;
    }
    if (privateVoiceModal?.classList.contains('open')) {
      e.preventDefault();
      closePrivateVoiceModal();
      return;
    }
    if (!document.getElementById('gesture-action-menu')?.hidden) return;
    const restoreAvatarFocus = ctxMenu.classList.contains('visible');
    closeFloatingShells([...(restoreAvatarFocus ? ['context'] : [])]);
    if (restoreAvatarFocus) closeContextMenu({ restoreFocus: true });
    closeLinkIconModal();
    document.getElementById('host-warn-modal')?.classList.remove('open');
    document.getElementById('host-kick-modal')?.classList.remove('open');
    document.getElementById('warning-modal')?.classList.remove('open');
    closeReportProblem();
    closeDeleteMessageModal();
    cancelVoiceNote();
    closeMemberProfile({ restoreFocus: true });
  }
});

document.addEventListener('contextmenu', e => {
  const tab = e.target.closest('.chat-tab[data-chat-tab]');
  if (tab) {
    const chatKey = tab.dataset.chatTab || '';
    if (chatKey.startsWith('dm:') || chatKey.startsWith('link:')) {
      e.preventDefault();
      e.stopPropagation();
      openTabContextMenu(e.clientX, e.clientY, chatKey);
      return;
    }
  }

  if (e.target.closest('.avatar')) return;

  const input = e.target.closest('#chat-input');
  if (input) {
    e.preventDefault();
    openTextContextMenu(e.clientX, e.clientY, 'input');
    return;
  }

  if (e.target.closest('.sidebar')) {
    e.preventDefault();
    closeContextMenu();
    closeTextContextMenu();
    return;
  }

  if (e.target.closest('.room-stage')) {
    e.preventDefault();
    closeContextMenu();
    closeTextContextMenu();
    return;
  }

  if (e.target.closest('.chat-pane')) {
    e.preventDefault();
    if (e.target.closest('.message')) return;
    const selection = window.getSelection();
    const selectedText = selection ? selection.toString() : '';
    if (selectedText.trim()) openTextContextMenu(e.clientX, e.clientY, 'copy');
    else closeTextContextMenu();
  }
});

roomStage.addEventListener('contextmenu', e => {
  if (e.target.closest('.avatar')) return;
  e.preventDefault();
  e.stopPropagation();
  closeContextMenu();
  closeTextContextMenu();
}, true);

document.querySelector('.sidebar').addEventListener('contextmenu', e => {
  if (e.target.closest('.person-row')) return;
  e.preventDefault();
  e.stopPropagation();
  closeContextMenu();
  closeTextContextMenu();
}, true);

async function copySelectedText() {
  const input = document.activeElement && document.activeElement.id === 'chat-input' ? document.activeElement : null;
  let text = '';
  if (input) text = input.value.slice(input.selectionStart, input.selectionEnd);
  if (!text) text = window.getSelection()?.toString() || '';
  if (text) await navigator.clipboard.writeText(text);
}

async function cutSelectedInputText() {
  const input = document.getElementById('chat-input');
  const start = input.selectionStart;
  const end = input.selectionEnd;
  if (start === end) return;
  const text = input.value.slice(start, end);
  await navigator.clipboard.writeText(text);
  input.setRangeText('', start, end, 'start');
  input.setSelectionRange(start, start);
  input.focus();
  updateComposerState();
}

async function pasteIntoInput() {
  const input = document.getElementById('chat-input');
  const text = await navigator.clipboard.readText();
  const start = input.selectionStart;
  const end = input.selectionEnd;
  input.setRangeText(text, start, end, 'end');
  const pos = start + text.length;
  input.setSelectionRange(Math.min(pos, input.value.length), Math.min(pos, input.value.length));
  input.focus();
  updateComposerState();
}

document.getElementById('text-copy').addEventListener('click', async () => {
  try { await copySelectedText(); } finally { closeTextContextMenu(); }
});
document.getElementById('text-cut').addEventListener('click', async () => {
  try { if (textMenuMode === 'input') await cutSelectedInputText(); } finally { closeTextContextMenu(); }
});
document.getElementById('text-paste').addEventListener('click', async () => {
  try { if (textMenuMode === 'input') await pasteIntoInput(); } finally { closeTextContextMenu(); }
});

async function applyReaction(messageId, emoji, chatKey = activeChatKey()) {
  await chatMessageActions().applyReaction(messageId, emoji, chatKey);
}

function currentActiveMessage(messageId = msgActionTargetId, chatKey = msgActionTargetChat || activeChatKey()) {
  return chatMessageActions().currentMessage(messageId, chatKey);
}

document.querySelectorAll('[data-msg-reaction]').forEach(btn => {
  btn.addEventListener('click', async () => {
    const messageId = msgActionTargetId;
    const chatKey = msgActionTargetChat || activeChatKey();
    closeMessageActionMenu();
    await applyReaction(messageId, btn.dataset.msgReaction, chatKey);
  });
});

document.getElementById('msg-reply-action')?.addEventListener('click', () => {
  const chatKey = msgActionTargetChat || activeChatKey();
  const msg = currentActiveMessage(msgActionTargetId, chatKey);
  closeMessageActionMenu();
  if (!msg) return;
  startReplyDraft(msg, chatKey);
});

document.getElementById('msg-gesture-visibility-action')?.addEventListener('click', async () => {
  const chatKey = msgActionTargetChat || activeChatKey();
  const msg = currentActiveMessage(msgActionTargetId, chatKey);
  const gesture = gestureFromMessage(msg);
  const publicId = gesturePresentation?.publicId?.(gesture) || '';
  const hidden = publicId ? gesturePresentation?.isHidden?.(publicId) : false;
  closeMessageActionMenu();
  if (!publicId) return;
  try {
    await setGestureHidden(publicId, !hidden, 'gesture-message-context-action');
  } catch (error) {
    showWarning(error.message || 'Gesture visibility could not be changed.');
  }
});

document.getElementById('msg-edit-action')?.addEventListener('click', async () => {
  const chatKey = msgActionTargetChat || activeChatKey();
  const msg = currentActiveMessage(msgActionTargetId, chatKey);
  closeMessageActionMenu();
  if (!msg) return;
  startInlineEdit(msg, chatKey);
});

function startInlineEdit(msg, chatKey = activeChatKey()) {
  const row = messagesEl.querySelector(`[data-message-id="${CSS.escape(String(msg.id))}"]`);
  const contentEl = row?.querySelector('.msg-content');
  if (!row || !contentEl) return;
  contentEl.innerHTML = `<textarea class="edit-msg-input" maxlength="1000" rows="3"></textarea><div class="edit-msg-actions"><button class="btn btn-primary edit-msg-save" type="button">Edit</button><button class="btn edit-msg-cancel" type="button">Cancel</button></div>`;
  const input = contentEl.querySelector('textarea');
  input.value = msg.content || '';
  input.focus();
  input.setSelectionRange(input.value.length, input.value.length);
  contentEl.querySelector('.edit-msg-cancel').addEventListener('click', () => renderActiveChat());
  contentEl.querySelector('.edit-msg-save').addEventListener('click', () => saveInlineEdit(msg, input, chatKey));
  input.addEventListener('keydown', e => {
    if (e.key === 'Escape') renderActiveChat();
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      saveInlineEdit(msg, input, chatKey);
    }
  });
}

async function saveInlineEdit(msg, input, chatKey = activeChatKey()) {
  await chatMessageActions().saveInlineEdit(msg, input.value, chatKey);
}

document.getElementById('msg-delete-action')?.addEventListener('click', async () => {
  const chatKey = msgActionTargetChat || activeChatKey();
  const msg = currentActiveMessage(msgActionTargetId, chatKey);
  closeMessageActionMenu();
  if (!msg) return;
  pendingDeleteMessageId = msg.id;
  pendingDeleteChatKey = chatKey;
  document.getElementById('delete-message-modal')?.classList.add('open');
});

function closeDeleteMessageModal() {
  pendingDeleteMessageId = null;
  pendingDeleteChatKey = null;
  document.getElementById('delete-message-modal')?.classList.remove('open');
}

bindModalCloseButtons(['delete-message-close', 'delete-message-cancel'], closeDeleteMessageModal);

document.getElementById('delete-message-confirm')?.addEventListener('click', async () => {
  const chatKey = pendingDeleteChatKey || activeChatKey();
  const msg = currentActiveMessage(pendingDeleteMessageId, chatKey);
  if (!msg) {
    closeDeleteMessageModal();
    return;
  }
  closeDeleteMessageModal();
  await chatMessageActions().deleteMessage(msg, chatKey);
});

async function unlinkCurrentPartner() {
  const partnerId = activeLinkPartnerId() || linkedPartner()?.id;
  return avatarRuntime?.coordinator?.unlinkCurrentParticipant({
    participantId: cfg.myParticipantId,
    partnerId,
  });
}

async function clearPrivateHistory(chatKey) {
  await chatPrivateChats().clearPrivateHistory(chatKey);
}

function closeDmTab(chatKey) {
  if (!chatPrivateChats().closeDmTab(chatKey)) return;
  document.querySelector(`.chat-tab[data-chat-tab="${CSS.escape(chatKey)}"]`)?.remove();
}

document.getElementById('tab-clear-history')?.addEventListener('click', async () => {
  const chatKey = tabCtxTargetChat;
  closeTabContextMenu();
  if (!chatKey) return;
  try {
    await clearPrivateHistory(chatKey);
  } catch (err) {
    showWarning(err.message || 'Could not clear history.');
  }
});

document.getElementById('tab-close-dm')?.addEventListener('click', () => {
  const chatKey = tabCtxTargetChat;
  closeTabContextMenu();
  closeDmTab(chatKey);
});

document.getElementById('tab-unlink')?.addEventListener('click', () => {
  closeTabContextMenu();
  unlinkCurrentPartner();
});

document.getElementById('tab-manage-relationship')?.addEventListener('click', () => {
  const chatKey = tabCtxTargetChat;
  const request = chatKey ? chatPrivateChats().relationshipRequest(chatKey) : null;
  closeTabContextMenu();
  if (request?.relationship_id) {
    avatarRuntime?.relationshipManagement?.openForRelationship(request.relationship_id, 'relationship-tab');
  }
});

document.getElementById('ctx-change-avatar').addEventListener('click', async () => {
  closeContextMenu();
  try {
    const { openAvatarLibrary } = await import(`${APP_BASE}/assets/js/avatar-library.js?v=20260914-movable`);
    await openAvatarLibrary({
      userId: cfg.myUserId,
      base: APP_BASE,
      applyFile: applyAvatarFile,
      applyAsset: id => applyLibraryAsset('avatar', id),
      prepareFile: file => window.ChatSpaceAvatar ? window.ChatSpaceAvatar.prepareAvatarFile(file) : Promise.resolve(file),
      chooseFallback: () => avatarFileInput.click(),
      postForm: form => {
        form.append('_csrf', CSRF_TOKEN);
        return postOutsideContentForm(runtimeRequestClient, '/api/avatar_library.php', form, { operation: 'upload-avatar', endpointCategory: 'avatar' });
      },
    });
  } catch (error) { showWarning(error.message || 'The avatar picker could not be opened.'); }
});

document.getElementById('msg-muted-reveal-action')?.addEventListener('click', () => {
  const chatKey = msgActionTargetChat || activeChatKey();
  const msg = currentActiveMessage(msgActionTargetId, chatKey);
  closeMessageActionMenu();
  if (!msg?.muted_collapsed || !msg._personalMuteOriginal) return;
  const revealKey = personalMuteMessageKey(msg, chatKey);
  if (revealKey) revealedPersonalMuteMessages.add(revealKey);
  restoreMutedMessageOriginal(msg, { revealed: true });
  renderActiveChat();
});

ctxChangeNameplate?.addEventListener('click', async () => {
  closeContextMenu();
  try {
    const [{ openAvatarLibrary }, { prepareNameplateFile }, policy] = await Promise.all([
      import(`${APP_BASE}/assets/js/avatar-library.js?v=20260914-movable`),
      import(`${APP_BASE}/assets/js/nameplate-processing.js?v=20260913-independent`),
      runtimeRequestClient.getJson('/api/nameplate_policy.php', { operation: 'read-nameplate-policy', endpointCategory: 'avatar', cache: 'no-store' }),
    ]);
    await openAvatarLibrary({
      userId: cfg.myUserId, base: APP_BASE, kind: 'nameplate', applyFile: applyNameplateFile,
      applyAsset: id => applyLibraryAsset('nameplate', id),
      prepareFile: file => prepareNameplateFile(file, policy.nameplatePolicy),
      chooseFallback: () => nameplateFileInput?.click(),
      postForm: form => {
        form.append('_csrf', CSRF_TOKEN);
        return postOutsideContentForm(runtimeRequestClient, '/api/avatar_library.php', form, { operation: 'upload-nameplate', endpointCategory: 'avatar' });
      },
    });
  } catch (error) { showWarning(error.message || 'The nameplate picker could not be opened.'); }
});

ctxRemoveNameplate?.addEventListener('click', async () => {
  closeContextMenu();
  const me = participants.get(Number(cfg.myParticipantId));
  if (!me) return;
  const formData = new FormData();
  formData.append('action', 'remove');
  formData.append('session_id', cfg.sessionId);
  formData.append('join_token', cfg.myJoinToken);
  try {
    const response = await runtimeRequestClient.postForm('/api/nameplate.php', formData, {
      operation: 'remove-nameplate',
      endpointCategory: 'avatar',
    });
    me.nameplate_path = response.nameplate_path || null;
    me.nameplate_url = response.nameplate_url || null;
    rerenderChatAppearance();
  } catch (error) {
    showWarning(error?.message || 'The nameplate image could not be removed.');
  }
});

document.getElementById('ctx-avatar-size')?.addEventListener('click', () => {
  openAvatarSizeModal('avatar');
});

document.getElementById('ctx-webcam-size')?.addEventListener('click', () => {
  openAvatarSizeModal('webcam');
});

avatarSizeForm?.addEventListener('submit', event => {
  event.preventDefault();
  saveAvatarSizePreferences();
});

bindModalCloseButtons(['avatar-size-close', 'avatar-size-cancel'], closeAvatarSizeModal);

document.getElementById('avatar-size-reset')?.addEventListener('click', () => {
  const policy = avatarRuntime?.displayPolicy?.policy?.() || cfg.avatarSizePolicy || {};
  if (avatarSizeModalMode === 'avatar') {
    avatarSizeResetRequested = true;
    avatarSizeEdge.value = String(policy.avatarDisplayMaxPx || 200);
    setAvatarSizeStatus('Server default selected.', 'ok');
  } else {
    avatarSizeResetRequested = false;
    avatarSizeWebcamPreset.value = 'match';
    setWebcamSizeInputs(currentAvatarWebcamResolution(participants.get(cfg.myParticipantId)));
  }
});

avatarSizeEdge?.addEventListener('input', () => {
  avatarSizeResetRequested = false;
});

avatarSizeWebcamPreset?.addEventListener('change', () => {
  const value = avatarSizeWebcamPreset.value;
  if (value === 'custom') return;
  avatarSizeResetRequested = false;
  const resolution = value === 'match'
    ? currentAvatarWebcamResolution(participants.get(cfg.myParticipantId))
    : avatarRuntime?.displayPolicy?.resolveWebcamDisplayChoice?.(value);
  setWebcamSizeInputs(resolution);
});

avatarSizeWebcamWidth?.addEventListener('input', () => {
  if (avatarSizeInputSync) return;
  avatarSizeResetRequested = false;
  if (avatarSizeAspectLock.checked) {
    avatarSizeInputSync = true;
    const maxHeight = Number(avatarSizeWebcamHeight.max || 200);
    avatarSizeWebcamHeight.value = String(Math.min(maxHeight, Math.max(42, Math.round(Number(avatarSizeWebcamWidth.value || 42) / avatarSizeAspectRatio))));
    avatarSizeInputSync = false;
  }
  setWebcamPresetFromInputs();
});

avatarSizeWebcamHeight?.addEventListener('input', () => {
  if (avatarSizeInputSync) return;
  avatarSizeResetRequested = false;
  if (avatarSizeAspectLock.checked) {
    avatarSizeInputSync = true;
    const maxWidth = Number(avatarSizeWebcamWidth.max || 200);
    avatarSizeWebcamWidth.value = String(Math.min(maxWidth, Math.max(42, Math.round(Number(avatarSizeWebcamHeight.value || 42) * avatarSizeAspectRatio))));
    avatarSizeInputSync = false;
  }
  setWebcamPresetFromInputs();
});

document.getElementById('avatar-size-match')?.addEventListener('click', () => {
  const selected = avatarSizeMatchParticipant?.selectedOptions?.[0];
  if (!selected) return;
  avatarSizeInputSync = true;
  avatarSizeResetRequested = false;
  avatarSizeWebcamWidth.value = selected.dataset.width;
  avatarSizeWebcamHeight.value = selected.dataset.height;
  avatarSizeAspectRatio = Number(selected.dataset.width) / Math.max(Number(selected.dataset.height), 1);
  avatarSizeInputSync = false;
  setWebcamPresetFromInputs();
  setAvatarSizeStatus('Linked member size copied once.', 'ok');
});

ctxOrientation?.addEventListener('click', event => {
  event.stopPropagation();
  const opening = !ctxOrientationWrap?.classList.contains('open');
  closeContextSubmenu(
    document.getElementById('ctx-tools-wrap'),
    document.getElementById('ctx-tools'),
    document.getElementById('ctx-tools-submenu')
  );
  if (opening) openContextSubmenu(ctxOrientationWrap, ctxOrientation, ctxOrientationSubmenu);
  else closeContextSubmenu(ctxOrientationWrap, ctxOrientation, ctxOrientationSubmenu);
  if (opening) {
    const selected = ctxOrientationSubmenu?.querySelector('[aria-checked="true"]');
    (selected || ctxOrientationSubmenu?.querySelector('button'))?.focus();
  }
});

ctxOrientationSubmenu?.addEventListener('click', event => {
  const button = event.target.closest('[data-avatar-orientation]');
  if (!button) return;
  setAvatarOrientation(String(button.dataset.avatarOrientation || ''));
});

ctxAuras?.addEventListener('click', () => {
  openAuraModal();
});

auraOptionsEl?.addEventListener('click', e => {
  const button = e.target.closest('.aura-option');
  if (!button) return;
  previewAura(button.dataset.auraKey || '').catch(err => showWarning(err.message || 'Could not preview aura.'));
});

document.getElementById('aura-set')?.addEventListener('click', () => {
  setCurrentAura();
});
bindModalCloseButtons(['aura-close', 'aura-cancel'], closeAuraModal);

document.getElementById('ctx-unlink').addEventListener('click', () => {
  const own = avatarRuntime?.relationships?.relationshipForParticipant(Number(cfg.myParticipantId));
  const target = avatarRuntime?.relationships?.relationshipForParticipant(Number(ctxMenuParticipantId));
  closeContextMenu();
  if (own && target && own.id === target.id) unlinkCurrentPartner();
});

ctxInteract?.addEventListener('click', () => {
  const target = participants.get(Number(ctxMenuParticipantId));
  const me = participants.get(Number(cfg.myParticipantId));
  closeContextMenu();
  if (!me || !target) return;
  const targetRelationship = avatarRuntime?.relationships?.relationshipForParticipant(target.id);
  const ownRelationship = avatarRuntime?.relationships?.relationshipForParticipant(me.id);
  if (targetRelationship && !ownRelationship && !isUserBlocked(target.user_id)) {
    void avatarRuntime?.relationshipManagement?.openForParticipant(target.id, 'avatar-join-request');
    return;
  }
  avatarRuntime?.coordinator?.requestLinkChoiceForInteraction(me, target);
  document.getElementById('link-choice-link')?.focus();
});

async function performLapAnimationContextAction(actionId) {
  const participantId = Number(ctxMenuParticipantId || 0);
  closeContextMenu();
  if (participantId <= 0) return;
  await avatarRuntime?.dances?.performParticipantAction(actionId, participantId);
}

ctxLapDance?.addEventListener('click', () => {
  void performLapAnimationContextAction('avatar.lap-dance');
});

ctxLapBounce?.addEventListener('click', () => {
  void performLapAnimationContextAction('avatar.lap-bounce');
});

document.getElementById('ctx-manage-relationship')?.addEventListener('click', () => {
  const participantId = ctxMenuParticipantId;
  closeContextMenu();
  if (participantId) {
    avatarRuntime?.relationshipManagement?.openForParticipant(participantId, 'avatar-context');
  }
});

ctxProfile?.addEventListener('click', () => {
  const participant = participants.get(Number(ctxMenuParticipantId));
  const returnFocus = ctxMenuReturnFocus || document.activeElement;
  closeContextMenu();
  if (participant) {
    openMemberProfile(Number(participant.user_id), {
      returnFocus,
      publicProfileId: participant.public_profile_id,
    }).catch(error => {
      showWarning(error?.message || 'User Profile could not be opened.');
    });
  }
});

document.getElementById('ctx-dm').addEventListener('click', () => {
  const p = participants.get(ctxMenuParticipantId);
  closeContextMenu();
  if (p) openDmWithUser({ id: p.user_id, display_name: p.display_name, avatar_url: avatarUrl(p) });
});

ctxWebcamVisibility?.addEventListener('click', () => {
  const person = participants.get(Number(ctxMenuParticipantId));
  closeContextMenu();
  if (!person || Number(person.id) === Number(cfg.myParticipantId)) return;
  const policy = webcamViewerPolicyFor(person);
  voiceRuntime?.viewerPolicy?.setParticipantPresentation(person.user_id, !policy.show);
});

ctxWebcamReceive?.addEventListener('click', () => {
  const person = participants.get(Number(ctxMenuParticipantId));
  closeContextMenu();
  if (!person || Number(person.id) === Number(cfg.myParticipantId)) return;
  const policy = webcamViewerPolicyFor(person);
  voiceRuntime?.viewerPolicy?.setParticipantReceive(person.user_id, !policy.receive);
});

async function setAvatarVisibilityFromMenu(scope) {
  const person = participants.get(Number(ctxMenuParticipantId));
  closeContextMenu();
  if (!person || Number(person.id) === Number(cfg.myParticipantId)) return;
  const current = avatarVisibilityFor(person);
  try {
    if (scope === 'user') {
      await avatarRuntime?.visibility?.setUserHidden(person, !current.user);
    } else {
      await avatarRuntime?.visibility?.setExactHidden(person, !current.exact);
    }
    const next = avatarVisibilityFor(person);
    const status = document.getElementById('hidden-avatar-status');
    if (status) status.textContent = next.notice || 'Avatar shown.';
  } catch (error) {
    showWarning(error?.message || 'Avatar visibility could not be changed.');
  }
}

async function setNameplateVisibilityFromMenu(scope) {
  const person = participants.get(Number(ctxMenuParticipantId));
  closeContextMenu();
  if (!person || Number(person.id) === Number(cfg.myParticipantId)) return;
  const current = nameplateVisibilityFor(person);
  try {
    await setNameplateVisibility(person, scope, scope === 'user' ? !current.user : !current.exact);
  } catch (error) {
    showWarning(error?.message || 'Nameplate visibility could not be changed.');
  }
}

ctxAvatarVisibility?.addEventListener('click', () => setAvatarVisibilityFromMenu('avatar'));
ctxAvatarUserVisibility?.addEventListener('click', () => setAvatarVisibilityFromMenu('user'));
ctxNameplateVisibility?.addEventListener('click', () => setNameplateVisibilityFromMenu('nameplate'));
ctxNameplateUserVisibility?.addEventListener('click', () => setNameplateVisibilityFromMenu('user'));
ctxGestureSenderVisibility?.addEventListener('click', async () => {
  const person = participants.get(Number(ctxMenuParticipantId));
  closeContextMenu();
  if (!person || Number(person.id) === Number(cfg.myParticipantId)) return;
  const hidden = gesturePresentation?.isSenderHidden?.(person.user_id) === true;
  try {
    await setGestureSenderMediaHidden(Number(person.user_id), !hidden);
  } catch (error) {
    showWarning(error?.message || 'Gesture media visibility could not be changed.');
  }
});

document.getElementById('ctx-tools')?.addEventListener('click', e => {
  e.stopPropagation();
  const toolsWrap = document.getElementById('ctx-tools-wrap');
  const toolsTrigger = document.getElementById('ctx-tools');
  const toolsSubmenu = document.getElementById('ctx-tools-submenu');
  const opening = !toolsWrap?.classList.contains('open');
  closeContextSubmenu(ctxOrientationWrap, ctxOrientation, ctxOrientationSubmenu);
  if (opening) openContextSubmenu(toolsWrap, toolsTrigger, toolsSubmenu);
  else closeContextSubmenu(toolsWrap, toolsTrigger, toolsSubmenu);
});

async function setBlockState(participant, blocked) {
  if (!participant || participant.id === cfg.myParticipantId) return;
  const action = blocked ? 'block_user' : 'unblock_user';
  avatarRuntime?.coordinator?.invalidatePendingLinkChoice(
    'block-state-change',
    [cfg.myParticipantId, participant.id]
  );
  if (blocked) {
    blockedUserIds.add(Number(participant.user_id));
    const relationshipFollowers = avatarRuntime?.coordinator?.unlinkFollowersOf(participant.id) || [];
    participants.forEach(p => {
      if (p.id === participant.id || relationshipFollowers.includes(p)) {
        avatarRuntime?.coordinator?.clearBlockedRelationship(p);
      }
    });
  } else {
    blockedUserIds.delete(Number(participant.user_id));
  }
  renderParticipant(participant);
  renderPeople();
  renderLinkTabs();
  renderActiveChat();
  await apiPost('/api/users.php', {
    action,
    session_id: cfg.sessionId,
    join_token: cfg.myJoinToken,
    target_participant_id: participant.id,
  });
  if (!blocked) renderParticipant(participant, { animateJoin: false });
}

function setTransferComposeStatus(message, type = '') {
  const status = document.getElementById('p2p-transfer-compose-status');
  if (!status) return;
  status.textContent = message || '';
  status.className = `admin-form-status ${type}`.trim();
}

function transferPolicyAllowsServer(kind) {
  const policy = cfg?.serverMediaPolicy || {};
  if (kind === 'avatar') return false;
  if (kind === 'gesture') return ['server-only', 'both'].includes(policy.sendGestureMode);
  return policy.serverAttachmentsEnabled && ['server-only', 'both'].includes(policy.fileMode);
}

function transferPolicyAllowsP2P(kind) {
  const policy = cfg?.p2pTransferPolicy || {};
  return kind === 'gesture' ? policy.sendGestureEnabled !== false : policy.filesEnabled !== false;
}

function syncTransferComposeChoices() {
  const kindControls = [...(p2pTransferComposeForm?.querySelectorAll('[name="transfer_kind"]') || [])];
  for (const control of kindControls) {
    const available = transferPolicyAllowsP2P(control.value) || transferPolicyAllowsServer(control.value);
    control.disabled = !available;
    if (control.closest('label')) control.closest('label').hidden = !available;
  }
  let kind = p2pTransferComposeForm?.elements?.transfer_kind?.value || 'file';
  const selectedKind = kindControls.find(control => control.value === kind);
  if (!selectedKind || selectedKind.disabled) {
    const nextKind = kindControls.find(control => !control.disabled);
    if (nextKind) {
      nextKind.checked = true;
      kind = nextKind.value;
    }
  }
  const fileWrap = document.getElementById('p2p-transfer-file-wrap');
  const gestureWrap = document.getElementById('p2p-transfer-gesture-wrap');
  const avatarWrap = document.getElementById('p2p-transfer-avatar-wrap');
  if (fileWrap) fileWrap.hidden = kind !== 'file';
  if (gestureWrap) gestureWrap.hidden = kind !== 'gesture';
  if (avatarWrap) avatarWrap.hidden = kind !== 'avatar';
  p2pTransferComposeForm?.querySelectorAll('[name="transfer_delivery"]').forEach(control => {
    const available = control.value === 'p2p' ? transferPolicyAllowsP2P(kind) : transferPolicyAllowsServer(kind);
    control.disabled = !available;
    if (control.closest('label')) control.closest('label').hidden = !available;
  });
  const selected = p2pTransferComposeForm?.querySelector('[name="transfer_delivery"]:checked');
  if (selected?.disabled) p2pTransferComposeForm?.querySelector('[name="transfer_delivery"]:not(:disabled)')?.click();
  const delivery = p2pTransferComposeForm?.elements?.transfer_delivery?.value || 'p2p';
  const warning = document.getElementById('p2p-transfer-warning');
  const notice = document.getElementById('p2p-server-upload-notice');
  if (warning) warning.textContent = delivery === 'p2p'
    ? (cfg?.p2pTransferPolicy?.directWarning || 'Peer-to-peer transfer: CoreChat will attempt a direct connection to this participant. Your public IP address may be visible to them.')
    : 'Server delivery stores an authenticated copy for the intended conversation and applies community retention and review policy.';
  if (notice) notice.hidden = delivery !== 'server';
}

async function loadTransferGestures() {
  const select = document.getElementById('p2p-transfer-gesture');
  if (!select || select.dataset.loaded === '1') return;
  const query = new URLSearchParams({session_id: cfg.sessionId, join_token: cfg.myJoinToken, page: '1', q: ''});
  const data = await runtimeRequestClient.getJson(`/api/gestures.php?${query}`, {
    operation: 'load-transfer-gestures',
    endpointCategory: 'gestures',
  });
  transferGestureCatalog.clear();
  for (const gesture of data.gestures || []) {
    transferGestureCatalog.set(String(gesture.public_id || gesture.id), gesture);
    const option = document.createElement('option');
    option.value = String(gesture.public_id || gesture.id);
    option.textContent = `${gesture.title || gesture.name || 'Gesture'}${gesture.mine ? ' — Yours' : ''}`;
    select.appendChild(option);
  }
  select.dataset.loaded = '1';
}

function blobAsDataUrl(blob) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(reader.error || new Error('Gesture media could not be prepared.'));
    reader.onload = () => resolve(String(reader.result || ''));
    reader.readAsDataURL(blob);
  });
}

async function gestureTransferFile(gesture) {
  const fetchMedia = async url => {
    if (!url) return null;
    const response = await fetch(appUrl(url), {credentials: 'same-origin', cache: 'no-store'});
    if (!response.ok) throw new Error('Gesture media could not be prepared for direct transfer.');
    return blobAsDataUrl(await response.blob());
  };
  const payload = {
    schema: 'corechat.direct-gesture.v1',
    title: gesture.title || gesture.name || 'Gesture',
    text: gesture.text || '',
    creatorCredit: gesture.creator_credit || '',
    animation: await fetchMedia(gesture.gif_url || gesture.gif_path),
    poster: await fetchMedia(gesture.poster_url || gesture.poster_path),
    audio: await fetchMedia(gesture.audio_url || gesture.audio_path),
    sourceContentSha256: gesture.content_sha256 || '',
  };
  const safe = String(payload.title).replace(/[^A-Za-z0-9._ -]+/g, '').trim().slice(0, 80) || 'Gesture';
  return new File([JSON.stringify(payload)], `${safe}.corechat-gesture.json`, {type: 'application/vnd.corechat.gesture+json'});
}

function renderTransferManifest() {
  const manifest = document.getElementById('p2p-transfer-manifest');
  if (!manifest) return;
  manifest.textContent = '';
  for (const item of p2pTransferSelectedFiles) {
    const row = document.createElement('div');
    const name = document.createElement('span');
    const size = document.createElement('span');
    name.textContent = item.relativePath || item.file?.name || 'Unnamed file';
    size.textContent = formatBytes(Number(item.file?.size || 0));
    row.append(name, size);
    manifest.appendChild(row);
  }
  if (!p2pTransferSelectedFiles.length) {
    const empty = document.createElement('p');
    empty.className = 'minor';
    empty.textContent = 'No files selected.';
    manifest.appendChild(empty);
  }
}

function setTransferSelectedFiles(items) {
  const selected = Array.from(items || []).filter(item => item?.file instanceof File);
  if (selected.length > 20) throw new Error('Choose between 1 and 20 files.');
  p2pTransferSelectedFiles = selected;
  renderTransferManifest();
  setTransferComposeStatus(selected.length ? `${selected.length} file${selected.length === 1 ? '' : 's'} selected.` : '');
}

async function transferFilesFromHandle(handle, parent = '') {
  if (!handle) throw new Error('This dropped item is not available to the browser.');
  if (handle.kind === 'file') {
    const file = await handle.getFile();
    return [{file, handle, relativePath: parent ? `${parent}/${file.name}` : file.name}];
  }
  if (handle.kind !== 'directory') throw new Error('Only files and folders can be transferred.');
  const prefix = parent ? `${parent}/${handle.name}` : handle.name;
  const result = [];
  for await (const child of handle.values()) {
    result.push(...await transferFilesFromHandle(child, prefix));
    if (result.length > 20) throw new Error('A folder selection may contain at most 20 files.');
  }
  return result;
}

function legacyEntryFile(entry) {
  return new Promise((resolve, reject) => entry.file(resolve, reject));
}

async function legacyDirectoryChildren(entry) {
  const reader = entry.createReader();
  const children = [];
  while (true) {
    const batch = await new Promise((resolve, reject) => reader.readEntries(resolve, reject));
    if (!batch.length) break;
    children.push(...batch);
  }
  return children;
}

async function transferFilesFromLegacyEntry(entry, parent = '') {
  if (entry.isFile) {
    const file = await legacyEntryFile(entry);
    return [{file, handle: null, relativePath: parent ? `${parent}/${file.name}` : file.name}];
  }
  if (!entry.isDirectory) throw new Error('Only regular files and folders can be transferred.');
  const prefix = parent ? `${parent}/${entry.name}` : entry.name;
  const result = [];
  for (const child of await legacyDirectoryChildren(entry)) {
    result.push(...await transferFilesFromLegacyEntry(child, prefix));
    if (result.length > 20) throw new Error('A folder selection may contain at most 20 files.');
  }
  return result;
}

async function transferFilesFromDrop(dataTransfer) {
  const items = Array.from(dataTransfer?.items || []).filter(item => item.kind === 'file');
  const selected = [];
  for (const item of items) {
    if (typeof item.getAsFileSystemHandle === 'function') {
      const handle = await item.getAsFileSystemHandle();
      selected.push(...await transferFilesFromHandle(handle));
    } else if (typeof item.webkitGetAsEntry === 'function') {
      const entry = item.webkitGetAsEntry();
      if (entry) selected.push(...await transferFilesFromLegacyEntry(entry));
    } else {
      const file = item.getAsFile();
      if (file) selected.push({file, handle: null, relativePath: file.name});
    }
    if (selected.length > 20) throw new Error('Choose between 1 and 20 files.');
  }
  return selected;
}

async function openP2PTransferCompose(participant, returnFocus = null) {
  if (!participant || Number(participant.id) === Number(cfg?.myParticipantId)) return;
  p2pTransferTargetParticipantId = Number(participant.id);
  transferModalReturnFocus = returnFocus || document.activeElement;
  document.getElementById('p2p-transfer-recipient-name').textContent = displayNameFor(participant) || 'participant';
  p2pTransferComposeForm?.reset();
  p2pTransferSelectedFiles = [];
  p2pTransferPreparedAvatar = null;
  const avatarInput = document.getElementById('p2p-transfer-avatar');
  if (avatarInput) avatarInput.value = '';
  renderTransferManifest();
  setTransferComposeStatus('');
  syncTransferComposeChoices();
  p2pTransferComposeModal?.classList.add('open');
  p2pTransferComposeModal?.setAttribute('aria-hidden', 'false');
  const composeBox = p2pTransferComposeModal?.querySelector('.p2p-transfer-box');
  if (composeBox) {
    composeBox.scrollTop = 0;
    composeBox.scrollLeft = 0;
  }
  await loadTransferGestures().catch(error => setTransferComposeStatus(error.message, 'error'));
  p2pTransferComposeForm?.querySelector('input:checked')?.focus();
}

function closeP2PTransferCompose(restoreFocus = true) {
  p2pTransferComposeModal?.classList.remove('open');
  p2pTransferComposeModal?.setAttribute('aria-hidden', 'true');
  p2pTransferTargetParticipantId = null;
  if (restoreFocus && transferModalReturnFocus?.isConnected) transferModalReturnFocus.focus();
  transferModalReturnFocus = null;
}

function transferOfferFact(term, value) {
  const fragment = document.createDocumentFragment();
  fragment.append(document.createElement('dt'));
  fragment.lastChild.textContent = term;
  const dd = document.createElement('dd');
  dd.textContent = value;
  fragment.appendChild(dd);
  return fragment;
}

function clearIncomingTransferPreview() {
  if (p2pTransferPreviewUrl) URL.revokeObjectURL(p2pTransferPreviewUrl);
  p2pTransferPreviewUrl = null;
  const preview = document.getElementById('p2p-transfer-offer-preview');
  if (!preview) return;
  preview.replaceChildren();
  preview.hidden = true;
}

function renderIncomingTransferPreview(result = {}) {
  const preview = document.getElementById('p2p-transfer-offer-preview');
  if (!preview || !p2pTransferIncomingOffer || String(result.offer?.id || '') !== String(p2pTransferIncomingOffer.id || '')) return;
  clearIncomingTransferPreview();
  const heading = document.createElement('strong');
  heading.textContent = 'Safe preview';
  preview.appendChild(heading);
  if (result.kind === 'image' && result.blob instanceof Blob) {
    p2pTransferPreviewUrl = URL.createObjectURL(result.blob);
    const image = document.createElement('img');
    image.className = 'p2p-transfer-preview-image';
    image.src = p2pTransferPreviewUrl;
    image.alt = result.text ? `Safe preview: ${result.text}` : 'Safe transfer preview';
    preview.appendChild(image);
  } else {
    const text = document.createElement('p');
    text.textContent = String(result.text || 'No visual preview is available. Review the transfer details before accepting.');
    preview.appendChild(text);
  }
  preview.hidden = false;
  const previewRequest = document.getElementById('p2p-transfer-preview-request');
  if (previewRequest) {
    previewRequest.hidden = true;
    previewRequest.disabled = false;
    previewRequest.textContent = 'Request safe preview';
  }
  const status = document.getElementById('p2p-transfer-offer-status');
  if (status) status.textContent = 'Safe preview is ready. Accept or decline remains separate.';
}

async function openIncomingTransferOffer(offer) {
  p2pTransferIncomingOffer = offer;
  p2pTransferOfferStorageReady = false;
  clearIncomingTransferPreview();
  transferOfferReturnFocus = document.activeElement;
  const facts = document.getElementById('p2p-transfer-offer-facts');
  if (facts) {
    facts.textContent = '';
    const manifestNames = Array.isArray(offer.manifest?.files)
      ? offer.manifest.files.map(file => file.safeName || 'Unnamed file').join(', ')
      : (offer.safeName || 'Unnamed file');
    facts.append(
      transferOfferFact('Sender', offer.sender?.name || 'Participant'),
      transferOfferFact('Transfer', offer.kind === 'avatar' ? 'Avatar' : (offer.kind === 'gesture' ? 'Gesture' : 'File')),
      transferOfferFact(offer.kind === 'avatar' ? 'Avatar' : (offer.fileCount > 1 ? 'Files' : 'File'), manifestNames),
      transferOfferFact('Count', String(Number(offer.fileCount || 1))),
      transferOfferFact('Size', formatBytes(Number(offer.size || 0))),
      transferOfferFact('Declared type', offer.declaredMime || 'application/octet-stream'),
      transferOfferFact('Detected category', offer.detectedType || 'other'),
      transferOfferFact('Preview', offer.previewAvailable ? 'Available after explicit request' : 'Metadata only'),
      transferOfferFact('Delivery', offer.deliveryMethod === 'relay-only' ? 'Relay-only' : 'Direct first'),
      transferOfferFact('Risk', offer.riskClass || 'Cannot be inspected'),
      transferOfferFact('Safety', offer.riskDetail || 'Not scanned for malware')
    );
    if (offer.archive?.encrypted) facts.append(transferOfferFact('Archive', 'Encrypted archive — contents cannot be inspected.'));
    else if (offer.archive?.activeContent || offer.archive?.suspiciousPaths || offer.archive?.extremeRatio) {
      facts.append(transferOfferFact('Archive warning', 'Contains active content, suspicious paths, or an extreme compression ratio.'));
    }
  }
  const warning = document.getElementById('p2p-transfer-offer-warning');
  if (warning) warning.textContent = offer.warning || '';
  const status = document.getElementById('p2p-transfer-offer-status');
  if (status) status.textContent = 'Checking browser storage for this transfer…';
  const directWrap = document.getElementById('p2p-transfer-direct-disk-wrap');
  const directChoice = document.getElementById('p2p-transfer-direct-disk');
  if (directWrap) directWrap.hidden = true;
  if (directChoice) directChoice.checked = false;
  const accept = document.getElementById('p2p-transfer-accept');
  const previewRequest = document.getElementById('p2p-transfer-preview-request');
  if (previewRequest) {
    previewRequest.hidden = !offer.previewAvailable;
    previewRequest.disabled = false;
    previewRequest.textContent = 'Request safe preview';
  }
  if (accept) accept.disabled = true;
  p2pTransferOfferModal?.classList.add('open');
  p2pTransferOfferModal?.setAttribute('aria-hidden', 'false');
  const offerBox = p2pTransferOfferModal?.querySelector('.p2p-transfer-box');
  if (offerBox) {
    offerBox.scrollTop = 0;
    offerBox.scrollLeft = 0;
  }
  const offerTitle = document.getElementById('p2p-transfer-offer-title');
  if (offerTitle) offerTitle.textContent = offer.kind === 'avatar' ? 'Incoming Avatar' : 'Incoming transfer';
  document.getElementById('p2p-transfer-offer-title')?.focus({preventScroll: true});
  try {
    const capabilities = await p2pTransferService.storageCapabilities(offer);
    if (capabilities.mode === 'direct') {
      if (directWrap) directWrap.hidden = false;
      if (status) status.textContent = Number(offer.fileCount || 1) > 1
        ? 'This batch needs supported browser storage to create its local ZIP. This is not a server quota.'
        : 'Browser storage is unavailable or lacks capacity. Select the non-resumable direct-to-device option to continue.';
    } else if (status) {
      status.textContent = `Accept only if you know and expect ${Number(offer.fileCount || 1) === 1 ? 'this file' : 'these files'}.`;
    }
    p2pTransferOfferStorageReady = true;
    if (accept) accept.disabled = false;
  } catch (error) {
    if (status) status.textContent = error.message || 'Browser storage could not be checked.';
  }
}

function closeIncomingTransferOffer(restoreFocus = true) {
  p2pTransferOfferModal?.classList.remove('open');
  p2pTransferOfferModal?.setAttribute('aria-hidden', 'true');
  p2pTransferIncomingOffer = null;
  p2pTransferOfferStorageReady = false;
  clearIncomingTransferPreview();
  if (restoreFocus && transferOfferReturnFocus?.isConnected) transferOfferReturnFocus.focus();
  transferOfferReturnFocus = null;
}

function handleTransferModalKeydown(modal, event, onEscape) {
  if (!modal?.classList.contains('open')) return;
  if (event.key === 'Escape') {
    event.preventDefault();
    onEscape?.();
    return;
  }
  if (event.key !== 'Tab') return;
  const focusable = [...modal.querySelectorAll('button:not([disabled]), a[href]:not([aria-disabled="true"]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
    .filter(element => !element.hidden && !element.closest('[hidden]') && element.getClientRects().length > 0);
  if (!focusable.length) return;
  const first = focusable[0];
  const last = focusable[focusable.length - 1];
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
}

const TRANSFER_TERMINAL_STATES = new Set(['completed', 'failed', 'declined', 'cancelled', 'expired']);

function refreshTransfersCount() {
  const active = [...(p2pTransferStatusDrawer?.children || [])]
    .filter(row => row.dataset.terminal !== 'true').length;
  if (transfersCount) {
    transfersCount.textContent = active > 99 ? '99+' : String(active);
    transfersCount.hidden = active <= 0;
  }
  if (transfersButton) transfersButton.setAttribute('aria-label', `Transfers, ${active} active or resumable`);
}

function openTransfersTray() {
  if (!transfersTray) return;
  transfersTray.hidden = false;
  transfersButton?.setAttribute('aria-expanded', 'true');
}

function closeTransfersTray(restoreFocus = true) {
  if (!transfersTray) return;
  transfersTray.hidden = true;
  transfersButton?.setAttribute('aria-expanded', 'false');
  if (restoreFocus) transfersButton?.focus();
}

function transferControl(label, action, options = {}) {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = options.danger ? 'btn btn-danger btn-small' : 'btn btn-small';
  button.textContent = label;
  button.addEventListener('click', async () => {
    button.disabled = true;
    try {
      await action();
    } catch (error) {
      renderP2PTransferStatus({offer: options.offer, state: options.state || 'failed', detail: error.message || 'The transfer action failed.'});
    } finally {
      button.disabled = false;
    }
  });
  return button;
}

function renderP2PTransferStatus({offer, state, detail, progress}) {
  if (!p2pTransferStatusDrawer || !offer) return;
  p2pTransferStatusDrawer.hidden = false;
  const effectiveState = state || offer.status || 'unknown';
  const terminal = TRANSFER_TERMINAL_STATES.has(effectiveState) || TRANSFER_TERMINAL_STATES.has(offer.status);
  let row = p2pTransferStatusDrawer.querySelector(`[data-transfer-id="${CSS.escape(offer.id)}"]`);
  if (!row) {
    row = document.createElement('article');
    row.dataset.transferId = offer.id;
    row.innerHTML = '<strong class="p2p-transfer-primary"></strong><span class="p2p-transfer-detail"></span><progress class="p2p-transfer-progress" max="100" value="0" hidden></progress><div class="p2p-transfer-controls"></div>';
    p2pTransferStatusDrawer.appendChild(row);
  }
  row.dataset.terminal = terminal ? 'true' : 'false';
  row.className = `p2p-transfer-status state-${effectiveState}`;
  row.querySelector('.p2p-transfer-primary').textContent = `${offer.safeName || 'Transfer'} — ${offer.actorIsSender ? `to ${offer.recipient?.name || 'participant'}` : `from ${offer.sender?.name || 'participant'}`}`;
  row.querySelector('.p2p-transfer-detail').textContent = `${offer.finalStatus || effectiveState}${detail ? ` · ${detail}` : ''}`;
  const progressBar = row.querySelector('.p2p-transfer-progress');
  if (progress && Number.isFinite(Number(progress.aggregatePercent))) {
    progressBar.hidden = false;
    progressBar.value = Math.max(0, Math.min(100, Number(progress.aggregatePercent)));
    progressBar.setAttribute('aria-label', `${Math.round(progressBar.value)} percent transferred`);
  } else {
    progressBar.hidden = true;
    progressBar.value = 0;
  }
  const controls = row.querySelector('.p2p-transfer-controls');
  controls.textContent = '';
  const offerOptions = {offer, state: effectiveState};
  if (['transferring', 'direct', 'relayed', 'resuming'].includes(effectiveState)) {
    controls.appendChild(transferControl(Number(offer.fileCount || 1) > 1 ? 'Pause All' : 'Pause', () => p2pTransferService.pauseAll(offer.id, true), offerOptions));
  }
  if (effectiveState === 'paused') {
    controls.appendChild(transferControl(Number(offer.fileCount || 1) > 1 ? 'Resume All' : 'Resume', () => p2pTransferService.pauseAll(offer.id, false), offerOptions));
  }
  if (['resumable', 'resume-wait', 'connecting'].includes(effectiveState)) {
    controls.appendChild(transferControl('Resume Transfer', () => p2pTransferService.resumeTransfer(offer.id), offerOptions));
  }
  if (effectiveState === 'resume-source-required') {
    const input = document.createElement('input');
    input.type = 'file';
    input.multiple = Number(offer.fileCount || 1) > 1;
    input.className = 'hidden-file-input';
    if ((offer.manifest?.files || []).some(file => String(file.safeName || '').includes('/'))) input.setAttribute('webkitdirectory', '');
    const choose = document.createElement('button');
    choose.type = 'button';
    choose.className = 'btn btn-small';
    choose.textContent = 'Choose Original Files';
    choose.addEventListener('click', () => input.click());
    input.addEventListener('change', async () => {
      choose.disabled = true;
      try {
        await p2pTransferService.reselectSources(offer.id, input.files);
      } catch (error) {
        renderP2PTransferStatus({offer, state: 'resume-source-required', detail: error.message || 'The original files did not match.'});
      } finally {
        choose.disabled = false;
      }
    });
    controls.append(choose, input);
  }
  if (!terminal && Number(offer.fileCount || 1) > 1 && ['transferring', 'paused', 'resuming'].includes(effectiveState)) {
    controls.appendChild(transferControl('Cancel Current', () => p2pTransferService.cancel(offer.id, 'current'), {...offerOptions, danger: true}));
  }
  if (!terminal) {
    controls.appendChild(transferControl(Number(offer.fileCount || 1) > 1 ? 'Cancel Batch' : 'Cancel Transfer', () => p2pTransferService.cancel(offer.id, 'batch'), {...offerOptions, danger: true}));
  } else {
    controls.appendChild(transferControl('Dismiss', async () => {
      row.remove();
      if (!p2pTransferStatusDrawer.children.length) p2pTransferStatusDrawer.hidden = true;
      refreshTransfersCount();
    }, offerOptions));
  }
  refreshTransfersCount();
}

function appendP2PTransferReportActions(offer, blob, name, row) {
  if (!row || row.querySelector('[data-transfer-report]')) return;
  const reveal = document.createElement('button');
  reveal.type = 'button';
  reveal.className = 'btn btn-small';
  reveal.dataset.transferReport = '';
  reveal.textContent = 'Report transfer';
  const form = document.createElement('form');
  form.className = 'p2p-transfer-report-form';
  form.hidden = true;
  const label = document.createElement('label');
  label.textContent = 'Report reason';
  const reason = document.createElement('textarea');
  reason.required = true;
  reason.minLength = 8;
  reason.maxLength = 2000;
  reason.rows = 3;
  label.appendChild(reason);
  const actions = document.createElement('div');
  actions.className = 'shared-form-actions';
  const metadataOnly = document.createElement('button');
  metadataOnly.type = 'submit';
  metadataOnly.className = 'btn';
  metadataOnly.textContent = 'Report metadata only';
  const submitFile = document.createElement('button');
  submitFile.type = 'button';
  submitFile.className = 'btn';
  submitFile.textContent = 'Report and submit received file';
  const cancel = document.createElement('button');
  cancel.type = 'button';
  cancel.className = 'btn';
  cancel.textContent = 'Cancel';
  const status = document.createElement('p');
  status.className = 'minor';
  status.setAttribute('role', 'status');
  status.setAttribute('aria-live', 'polite');
  actions.append(metadataOnly, submitFile, cancel);
  form.append(label, actions, status);
  reveal.addEventListener('click', () => {
    form.hidden = false;
    reveal.hidden = true;
    reason.focus();
  });
  cancel.addEventListener('click', () => {
    form.hidden = true;
    reveal.hidden = false;
    reveal.focus();
  });
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (!reason.reportValidity()) return;
    metadataOnly.disabled = true;
    submitFile.disabled = true;
    status.textContent = 'Submitting privacy-safe transfer metadata…';
    try {
      const result = await p2pTransferService.report(offer.id, reason.value.trim());
      status.textContent = `Report ${result.reportReference || ''} received. The transferred file was not submitted.`;
    } catch (error) {
      status.textContent = error.message || 'The transfer report could not be submitted.';
      metadataOnly.disabled = false;
      submitFile.disabled = false;
    }
  });
  submitFile.addEventListener('click', async () => {
    if (!reason.reportValidity()) return;
    metadataOnly.disabled = true;
    submitFile.disabled = true;
    status.textContent = 'Submitting the received file as protected moderation evidence…';
    const data = new FormData();
    data.append('session_id', cfg.sessionId);
    data.append('join_token', cfg.myJoinToken);
    data.append('offer_id', offer.id);
    data.append('reason', reason.value.trim());
    data.append('file', blob, name || offer.safeName || 'received-file');
    try {
      const result = await apiUpload('/api/p2p_transfer_evidence.php', data);
      status.textContent = `Report ${result.reportReference || ''} received with the file you chose to submit.`;
    } catch (error) {
      status.textContent = error.message || 'The received file could not be submitted as evidence.';
      metadataOnly.disabled = false;
      submitFile.disabled = false;
    }
  });
  row.append(reveal, form);
}

async function receiveP2PTransfer({offer, blob, name, kind, savedDirect = false, release = async () => {}}) {
  if (kind === 'avatar') {
    const stableBlob = new Blob([await blob.arrayBuffer()], {type: blob.type || 'application/octet-stream'});
    await release();
    const notice = document.createElement('div');
    notice.className = 'received-avatar-preview';
    const title = document.createElement('strong');
    title.textContent = `${offer.sender?.name || 'Participant'} sent an avatar`;
    const image = document.createElement('img');
    const url = URL.createObjectURL(stableBlob);
    image.src = url;
    image.alt = `Avatar preview from ${offer.sender?.name || 'participant'}`;
    const apply = document.createElement('button');
    apply.type = 'button';
    apply.className = 'btn btn-primary btn-small';
    apply.textContent = 'Use as my avatar';
    const status = document.createElement('span');
    status.className = 'minor';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    apply.addEventListener('click', async () => {
      apply.disabled = true;
      status.textContent = 'Applying through the normal avatar checks…';
      try {
        const file = new File([stableBlob], name || 'received-avatar', {type: stableBlob.type || 'application/octet-stream'});
        await applyAvatarFile(file);
        status.textContent = 'Your avatar was updated.';
      } catch (error) {
        apply.disabled = false;
        status.textContent = error.message || 'The avatar could not be applied.';
      }
    });
    notice.append(title, image, apply, status);
    appendP2PTransferReportActions(offer, stableBlob, name, notice);
    p2pTransferStatusDrawer?.appendChild(notice);
    window.setTimeout(() => URL.revokeObjectURL(url), 30 * 60 * 1000);
    return;
  }
  if (kind === 'gesture') {
    const stableBlob = new Blob([await blob.arrayBuffer()], {type: blob.type || 'application/octet-stream'});
    await release();
    stableBlob.text().then(text => {
      const gesture = JSON.parse(text);
      if (gesture?.schema !== 'corechat.direct-gesture.v1') throw new Error('The received gesture package is invalid.');
      const safeDataImage = value => typeof value === 'string'
        && /^data:image\/(?:gif|png|jpeg|webp);base64,[A-Za-z0-9+/=]+$/i.test(value)
        && value.length <= 2 * 1024 * 1024;
      const notice = document.createElement('div');
      notice.className = 'received-gesture-preview';
      const title = document.createElement('strong');
      const gestureTitle = String(gesture.title || 'a gesture').slice(0, 120);
      title.textContent = `${offer.sender?.name || 'Participant'} sent ${gestureTitle}`;
      notice.appendChild(title);
      if (safeDataImage(gesture.animation)) {
        const image = document.createElement('img');
        image.src = gesture.animation;
        image.alt = String(gesture.text || gestureTitle || 'Received gesture').slice(0, 180);
        notice.appendChild(image);
      }
      if (gesture.text) {
        const caption = document.createElement('p');
        caption.textContent = String(gesture.text).slice(0, 180);
        notice.appendChild(caption);
      }
      appendP2PTransferReportActions(offer, stableBlob, name, notice);
      p2pTransferStatusDrawer?.appendChild(notice);
    }).catch(error => renderP2PTransferStatus({offer, state: 'failed', detail: error.message || 'The received gesture package was invalid.'}));
    return;
  }
  if (savedDirect) {
    const row = p2pTransferStatusDrawer?.querySelector(`[data-transfer-id="${CSS.escape(offer.id)}"]`);
    const saved = document.createElement('span');
    saved.className = 'minor';
    saved.setAttribute('role', 'status');
    saved.textContent = `${name || 'The received file'} was saved directly to this device.`;
    row?.appendChild(saved);
    appendP2PTransferReportActions(offer, blob, name, row);
    await release();
    return;
  }
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.className = 'btn btn-small';
  link.href = url;
  link.download = name || 'download';
  link.textContent = `Save ${name || 'received file'}`;
  const row = p2pTransferStatusDrawer?.querySelector(`[data-transfer-id="${CSS.escape(offer.id)}"]`);
  row?.appendChild(link);
  appendP2PTransferReportActions(offer, blob, name, row);
  let released = false;
  const releaseOutput = async () => {
    if (released) return;
    released = true;
    URL.revokeObjectURL(url);
    await release();
  };
  link.addEventListener('click', () => window.setTimeout(() => void releaseOutput(), 5000), {once: true});
  window.setTimeout(() => void releaseOutput(), 30 * 60 * 1000);
}

function setSharedAttachmentsStatus(message, type = '') {
  const status = document.getElementById('shared-attachments-status');
  if (!status) return;
  status.textContent = message || '';
  status.className = `admin-form-status ${type}`.trim();
}

function jumpToSharedAttachment(asset) {
  if (!asset.messageId) return;
  const channel = asset.channel === 'community' ? 'community' : 'room';
  switchChat(channel);
  window.requestAnimationFrame(() => {
    const message = messagesEl.querySelector(`[data-message-id="${Number(asset.messageId)}"]`);
    message?.scrollIntoView({block: 'center', behavior: 'smooth'});
    message?.classList.add('message-jump-highlight');
    window.setTimeout(() => message?.classList.remove('message-jump-highlight'), 1800);
  });
}

function renderSharedAttachment(asset) {
  const row = document.createElement('article');
  row.className = 'shared-attachment-row';
  row.setAttribute('role', 'listitem');
  const title = document.createElement('strong');
  title.textContent = asset.safeName || 'Attachment';
  const facts = document.createElement('p');
  facts.className = 'minor';
  const age = asset.createdAt || 'date unavailable';
  facts.textContent = `${asset.detectedMime || 'Unknown type'} · ${formatBytes(Number(asset.size || 0))} · ${asset.uploaderLabel || 'Unknown sender'} · ${age}${asset.pinned ? ' · Pinned' : (asset.expiresAt ? ` · Expires ${asset.expiresAt} UTC` : '')}`;
  const actions = document.createElement('div');
  actions.className = 'shared-form-actions';
  const open = document.createElement('a');
  open.className = 'btn btn-small';
  open.href = asset.downloadUrl;
  open.textContent = 'Open / Download';
  actions.appendChild(open);
  if (asset.messageId) {
    const jump = document.createElement('button');
    jump.className = 'btn btn-small';
    jump.type = 'button';
    jump.textContent = 'Jump to message';
    jump.addEventListener('click', () => {
      closeSharedAttachments(false);
      jumpToSharedAttachment(asset);
    });
    actions.appendChild(jump);
  }
  if (asset.removeOwnAllowed) {
    const remove = document.createElement('button');
    remove.className = 'btn btn-small btn-danger';
    remove.type = 'button';
    remove.textContent = 'Remove my upload';
    remove.addEventListener('click', async () => {
      remove.disabled = true;
      try {
        await apiPost('/api/server_media.php', {action: 'remove-own', id: asset.id});
        await loadSharedAttachments();
      } catch (error) {
        remove.disabled = false;
        setSharedAttachmentsStatus(error.message || 'The upload could not be removed.', 'error');
      }
    });
    actions.appendChild(remove);
  }
  row.append(title, facts, actions);
  return row;
}

async function loadSharedAttachments() {
  if (!sharedAttachmentsList) return;
  setSharedAttachmentsStatus('Loading shared attachments…', 'working');
  const query = new URLSearchParams({
    session_id: cfg.sessionId,
    join_token: cfg.myJoinToken,
    view: sharedAttachmentsView,
    page: '1',
    page_size: '100',
  });
  const data = await runtimeRequestClient.getJson(`/api/server_media.php?${query}`, {
    operation: 'load-shared-attachments',
    endpointCategory: 'server-media',
    cache: 'no-store',
  });
  sharedAttachmentsList.textContent = '';
  for (const asset of data.list?.items || []) sharedAttachmentsList.appendChild(renderSharedAttachment(asset));
  if (!sharedAttachmentsList.children.length) {
    const empty = document.createElement('p');
    empty.className = 'admin-empty';
    empty.textContent = 'No active server-hosted attachments in this view.';
    sharedAttachmentsList.appendChild(empty);
  }
  setSharedAttachmentsStatus(`${Number(data.list?.total || 0)} active server-hosted attachments. Peer-to-peer and private direct payloads are never listed.`, 'ok');
}

async function openSharedAttachments() {
  transferModalReturnFocus = document.activeElement;
  sharedAttachmentsModal?.classList.add('open');
  sharedAttachmentsModal?.setAttribute('aria-hidden', 'false');
  await loadSharedAttachments().catch(error => setSharedAttachmentsStatus(error.message || 'Shared attachments could not be loaded.', 'error'));
  document.querySelector('[data-shared-attachments-view][aria-current="page"]')?.focus();
}

function closeSharedAttachments(restoreFocus = true) {
  sharedAttachmentsModal?.classList.remove('open');
  sharedAttachmentsModal?.setAttribute('aria-hidden', 'true');
  if (restoreFocus && transferModalReturnFocus?.isConnected) transferModalReturnFocus.focus();
  transferModalReturnFocus = null;
}

p2pTransferComposeForm?.addEventListener('change', event => {
  if (event.target.matches('[name="transfer_kind"], [name="transfer_delivery"]')) syncTransferComposeChoices();
  if (event.target.id === 'p2p-transfer-file' || event.target.id === 'p2p-transfer-folder') {
    try {
      setTransferSelectedFiles([...event.target.files].map(file => ({
        file,
        handle: null,
        relativePath: file.webkitRelativePath || file.name,
      })));
    } catch (error) {
      setTransferComposeStatus(error.message || 'The selection could not be prepared.', 'error');
    }
  }
});

const p2pTransferDropZone = document.getElementById('p2p-transfer-drop-zone');
document.getElementById('p2p-transfer-avatar')?.addEventListener('change', () => {
  p2pTransferPreparedAvatar = null;
});
p2pTransferDropZone?.addEventListener('keydown', event => {
  if (!['Enter', ' '].includes(event.key)) return;
  event.preventDefault();
  document.getElementById('p2p-transfer-file')?.click();
});
p2pTransferDropZone?.addEventListener('dragover', event => {
  event.preventDefault();
  p2pTransferDropZone.classList.add('is-dragover');
});
p2pTransferDropZone?.addEventListener('dragleave', () => p2pTransferDropZone.classList.remove('is-dragover'));
p2pTransferDropZone?.addEventListener('drop', async event => {
  event.preventDefault();
  p2pTransferDropZone.classList.remove('is-dragover');
  try {
    setTransferComposeStatus('Reading the selected files and folders…', 'working');
    setTransferSelectedFiles(await transferFilesFromDrop(event.dataTransfer));
  } catch (error) {
    setTransferComposeStatus(error.message || 'The dropped selection could not be prepared.', 'error');
  }
});

p2pTransferComposeForm?.addEventListener('submit', async event => {
  event.preventDefault();
  const participant = participants.get(Number(p2pTransferTargetParticipantId));
  if (!participant) return setTransferComposeStatus('That participant is no longer available.', 'error');
  const kind = p2pTransferComposeForm.elements.transfer_kind.value || 'file';
  const delivery = p2pTransferComposeForm.elements.transfer_delivery.value || 'p2p';
  const gesture = transferGestureCatalog.get(document.getElementById('p2p-transfer-gesture')?.value || '');
  let files = [...p2pTransferSelectedFiles];
  try {
    p2pTransferComposeForm.querySelector('button[type="submit"]').disabled = true;
    setTransferComposeStatus(delivery === 'p2p' ? 'Preparing direct offer…' : 'Sending through authenticated server delivery…', 'working');
    if (kind === 'gesture') {
      if (!gesture) throw new Error('Choose a gesture to send.');
      if (delivery === 'p2p') {
        const file = await gestureTransferFile(gesture);
        files = [{file, handle: null, relativePath: file.name}];
      }
    } else if (kind === 'avatar') {
      const selectedAvatar = document.getElementById('p2p-transfer-avatar')?.files?.[0];
      if (!selectedAvatar) throw new Error('Choose one avatar to send.');
      if (!window.ChatSpaceAvatar) throw new Error('Avatar preparation is unavailable.');
      const prepared = p2pTransferPreparedAvatar || await window.ChatSpaceAvatar.prepareAvatarFile(selectedAvatar);
      p2pTransferPreparedAvatar = prepared;
      files = [{file: prepared, handle: null, relativePath: prepared.name}];
    } else if (!files.length) {
      throw new Error('Choose one or more files to send.');
    }
    if (delivery === 'p2p') {
      await p2pTransferService.createOffer({recipientParticipantId: participant.id, kind, files});
    } else {
      const dm = `dm:${participant.user_id}`;
      if (kind === 'gesture') await chatMediaSend().sendGesture(gesture, dm);
      else for (const selected of files) await chatMediaSend().sendFile(selected.file, dm);
    }
    closeP2PTransferCompose();
  } catch (error) {
    setTransferComposeStatus(error.message || 'The transfer could not be started.', 'error');
  } finally {
    const submit = p2pTransferComposeForm.querySelector('button[type="submit"]');
    if (submit) submit.disabled = false;
  }
});

document.getElementById('p2p-transfer-compose-close')?.addEventListener('click', () => closeP2PTransferCompose());
document.getElementById('p2p-transfer-compose-cancel')?.addEventListener('click', () => closeP2PTransferCompose());
p2pTransferComposeModal?.addEventListener('keydown', event => handleTransferModalKeydown(p2pTransferComposeModal, event, () => closeP2PTransferCompose()));
p2pTransferComposeModal?.addEventListener('click', event => {
  if (event.target === p2pTransferComposeModal) closeP2PTransferCompose();
});
p2pTransferOfferModal?.addEventListener('keydown', event => handleTransferModalKeydown(p2pTransferOfferModal, event, () => {
  document.getElementById('p2p-transfer-decline')?.click();
}));
document.getElementById('p2p-transfer-accept')?.addEventListener('click', async event => {
  if (!p2pTransferIncomingOffer || !p2pTransferOfferStorageReady) return;
  const button = event.currentTarget;
  button.disabled = true;
  try {
    await p2pTransferService.respond(p2pTransferIncomingOffer.id, true);
    closeIncomingTransferOffer();
  } catch (error) {
    document.getElementById('p2p-transfer-offer-status').textContent = error.message || 'The transfer could not be accepted.';
  } finally {
    button.disabled = false;
  }
});
document.getElementById('p2p-transfer-preview-request')?.addEventListener('click', async event => {
  if (!p2pTransferIncomingOffer?.previewAvailable) return;
  const button = event.currentTarget;
  button.disabled = true;
  event.currentTarget.textContent = 'Requesting preview…';
  try {
    await p2pTransferService.requestPreview(p2pTransferIncomingOffer.id);
    document.getElementById('p2p-transfer-offer-status').textContent = 'The sender is preparing a bounded safe preview. Accept or decline remains separate.';
  } catch (error) {
    button.disabled = false;
    button.textContent = 'Request safe preview';
    document.getElementById('p2p-transfer-offer-status').textContent = error.message || 'The safe preview could not be requested.';
  }
});

transfersButton?.addEventListener('click', () => {
  if (transfersTray?.hidden) openTransfersTray();
  else closeTransfersTray();
});
transfersTrayClose?.addEventListener('click', () => closeTransfersTray());
transfersTray?.addEventListener('keydown', event => {
  if (event.key === 'Escape') {
    event.preventDefault();
    closeTransfersTray();
  }
});
document.getElementById('p2p-transfer-decline')?.addEventListener('click', async event => {
  if (!p2pTransferIncomingOffer) return;
  const button = event.currentTarget;
  button.disabled = true;
  try {
    await p2pTransferService.respond(p2pTransferIncomingOffer.id, false);
    closeIncomingTransferOffer();
  } catch (error) {
    document.getElementById('p2p-transfer-offer-status').textContent = error.message || 'The transfer could not be declined.';
  } finally {
    button.disabled = false;
  }
});

document.getElementById('show-shared-attachments-btn')?.addEventListener('click', () => {
  closeAttachMenu();
  openSharedAttachments();
});
document.getElementById('shared-attachments-close')?.addEventListener('click', () => closeSharedAttachments());
sharedAttachmentsModal?.addEventListener('keydown', event => handleTransferModalKeydown(sharedAttachmentsModal, event, () => closeSharedAttachments()));
sharedAttachmentsModal?.addEventListener('click', event => {
  if (event.target === sharedAttachmentsModal) closeSharedAttachments();
});
document.querySelectorAll('[data-shared-attachments-view]').forEach(button => button.addEventListener('click', () => {
  sharedAttachmentsView = button.dataset.sharedAttachmentsView || 'room';
  document.querySelectorAll('[data-shared-attachments-view]').forEach(candidate => {
    const selected = candidate === button;
    candidate.classList.toggle('active', selected);
    candidate.setAttribute('aria-current', selected ? 'page' : 'false');
  });
  loadSharedAttachments().catch(error => setSharedAttachmentsStatus(error.message || 'Shared attachments could not be loaded.', 'error'));
}));

ctxSendFileGesture?.addEventListener('click', () => {
  const participant = participants.get(Number(ctxMenuParticipantId));
  const returnFocus = ctxMenuReturnFocus || document.activeElement;
  closeContextMenu();
  openP2PTransferCompose(participant, returnFocus);
});

document.getElementById('ctx-block').addEventListener('click', () => {
  const p = participants.get(ctxMenuParticipantId);
  closeContextMenu();
  setBlockState(p, true).catch(err => showWarning(err.message || 'Could not block user.'));
});

document.getElementById('ctx-unblock').addEventListener('click', () => {
  const p = participants.get(ctxMenuParticipantId);
  closeContextMenu();
  setBlockState(p, false).catch(err => showWarning(err.message || 'Could not unblock user.'));
});

async function applyLibraryAsset(kind, libraryId) {
  if (!['avatar', 'nameplate'].includes(kind)) throw new Error('Unknown image library.');
  const participantId = Number(cfg.myParticipantId);
  if (!participants.get(participantId)) throw new Error('Your room identity is unavailable.');
  const form = new FormData();
  form.append('action', 'select');
  form.append('library_id', libraryId);
  form.append('session_id', cfg.sessionId);
  form.append('join_token', cfg.myJoinToken);
  form.append('_csrf', CSRF_TOKEN);
  const data = await postOutsideContentForm(runtimeRequestClient, '/api/' + kind + '.php', form, {
    operation: 'select-' + kind,
    endpointCategory: 'avatar',
  });
  const me = participants.get(participantId);
  if (!me) return;
  if (kind === 'nameplate') {
    me.nameplate_path = data.nameplate_path || null;
    me.nameplate_url = data.nameplate_url || null;
    rerenderChatAppearance();
    return;
  }
  participants.update(participantId, {
    avatar_path: data.avatar_path,
    avatar_url: data.avatar_url,
    avatar_identity: data.avatar_identity,
    avatar_source_width_px: data.avatar_source_width_px,
    avatar_source_height_px: data.avatar_source_height_px,
    avatar_orientation: normalizeAvatarOrientation(data.avatar_orientation ?? me.avatar_orientation),
    avatar_orientation_version: data.avatar_orientation_version ?? me.avatar_orientation_version,
    avatar_version: Date.now(),
    webcam_path: null,
    webcam_enabled: false,
  });
  renderParticipant(participants.get(participantId));
}

async function applyAvatarFile(file) {
  if (!file) throw new Error('Choose an avatar image.');
  let preparedFile = file;
  let previewUrl = '';
  const me = participants.get(cfg.myParticipantId);
  const previousAvatarState = me ? {
    avatar_path: me.avatar_path,
    avatar_url: me.avatar_url,
    avatar_version: me.avatar_version,
    webcam_path: me.webcam_path,
    webcam_enabled: me.webcam_enabled,
  } : null;
  const fd = new FormData();
  fd.append('session_id', cfg.sessionId);
  fd.append('join_token', cfg.myJoinToken);
  fd.append('_csrf', CSRF_TOKEN);
  try {
    if (window.ChatSpaceAvatar) preparedFile = await window.ChatSpaceAvatar.prepareAvatarFile(file);
    previewUrl = URL.createObjectURL(preparedFile);
    if (me) {
      participants.update(cfg.myParticipantId, {
        webcam_path: null,
        avatar_path: previewUrl,
        avatar_url: previewUrl,
        avatar_version: Date.now(),
      });
      renderParticipant(me);
    }
    fd.append('avatar', preparedFile);
    const data = await postOutsideContentForm(runtimeRequestClient, '/api/avatar.php', fd, {
      operation: 'upload-avatar',
      endpointCategory: 'avatar',
    });
    const updated = participants.get(cfg.myParticipantId);
    participants.update(cfg.myParticipantId, {
      avatar_path: data.avatar_path,
      avatar_url: data.avatar_url,
      avatar_orientation: normalizeAvatarOrientation(data.avatar_orientation ?? updated?.avatar_orientation),
      avatar_version: Date.now(),
      webcam_path: null,
    });
    renderParticipant(updated);
  } catch (err) {
    if (me && previousAvatarState) {
      participants.update(cfg.myParticipantId, previousAvatarState);
      renderParticipant(me);
      avatarRuntime?.coordinator?.scheduleRelationshipRefresh({
        participant: me,
        reason: 'avatar-upload-rejected',
      });
    }
    throw err;
  } finally {
    if (previewUrl) URL.revokeObjectURL(previewUrl);
  }
}

avatarFileInput.addEventListener('change', async () => {
  const file = avatarFileInput.files && avatarFileInput.files[0];
  if (!file) return;
  try {
    await applyAvatarFile(file);
  } catch (err) {
    if (err?.code !== 'OUTSIDE_CONTENT_CANCELLED') alert(err.message);
  } finally {
    avatarFileInput.value = '';
  }
});

document.getElementById('ctx-mute')?.addEventListener('click', () => {
  const participant = participants.get(Number(ctxMenuParticipantId));
  const shouldMute = !activeMutedPolicyFor(participant?.user_id);
  closeContextMenu();
  setPersonalMuteState(participant?.user_id, shouldMute)
    .catch(error => showWarning(error?.message || 'Mute setting could not be changed.'));
});

async function applyNameplateFile(file) {
  const me = participants.get(Number(cfg.myParticipantId));
  if (!me) throw new Error('Your room identity is unavailable.');
  const [{ prepareNameplateFile }, policy] = await Promise.all([
    import(`${APP_BASE}/assets/js/nameplate-processing.js?v=20260913-independent`),
    runtimeRequestClient.getJson('/api/nameplate_policy.php', { operation: 'read-nameplate-policy', endpointCategory: 'avatar', cache: 'no-store' }),
  ]);
  const prepared = await prepareNameplateFile(file, policy.nameplatePolicy);
  const formData = new FormData();
  formData.append('action', 'upload');
  formData.append('session_id', cfg.sessionId);
  formData.append('join_token', cfg.myJoinToken);
  formData.append('nameplate', prepared, prepared.name);
  const response = await postOutsideContentForm(runtimeRequestClient, '/api/nameplate.php', formData, {
    operation: 'upload-nameplate',
    endpointCategory: 'avatar',
  });
  me.nameplate_path = response.nameplate_path || null;
  me.nameplate_url = response.nameplate_url || null;
  rerenderChatAppearance();
}

nameplateFileInput?.addEventListener('change', async () => {
  const file = nameplateFileInput.files && nameplateFileInput.files[0];
  if (!file) return;
  try {
    await applyNameplateFile(file);
  } catch (error) {
    if (error?.code !== 'OUTSIDE_CONTENT_CANCELLED') {
      showWarning(error?.message || 'The nameplate image could not be changed.');
    }
  } finally {
    nameplateFileInput.value = '';
  }
});

function beginWebcamOperation(intent, operation) {
  webcamOperationGeneration += 1;
  webcamIntent = Boolean(intent);
  webcamAcquisitionState = intent ? 'pending' : 'idle';
  const token = Object.freeze({
    operation,
    generation: webcamOperationGeneration,
  });
  recordVoiceLifecycleDiagnostic({
    event: 'local-webcam-operation-start',
    participantId: Number(cfg.myParticipantId),
    webcamOperation: operation,
    operationGeneration: token.generation,
    intent: webcamIntent,
  });
  return token;
}

function isCurrentWebcamOperation(token) {
  return Boolean(
    token
    && webcamIntent
    && token.generation === webcamOperationGeneration
  );
}

function releaseWebcamStream(stream) {
  stream?.getTracks?.().forEach(track => track.stop());
}

function selectiveWebcamAudienceEnabled() {
  return Boolean(cfg?.voiceWebcamPolicy?.selectiveWebcamAudience?.enabled);
}

function renderWebcamAudiencePeople() {
  if (!webcamAudiencePersonList) return;
  const people = Array.from(participants.values())
    .filter(person => Number(person.id) !== Number(cfg?.myParticipantId) && person.online !== false);
  webcamAudiencePersonList.innerHTML = people.length
    ? people.map(person => `<label class="settings-checkbox-row"><input type="checkbox" name="recipient_user_ids" value="${Number(person.user_id)}"><span>${esc(displayNameFor(person))}</span></label>`).join('')
    : '<p class="minor">No other current room members are available.</p>';
}

function closeWebcamAudienceChooser(confirmed = false) {
  webcamAudienceModal?.classList.remove('open');
  const resolve = webcamAudienceDecision;
  webcamAudienceDecision = null;
  const returnFocus = webcamAudienceReturnFocus;
  webcamAudienceReturnFocus = null;
  resolve?.(confirmed);
  if (returnFocus?.isConnected) returnFocus.focus({ preventScroll: true });
}

function chooseWebcamAudience() {
  if (!selectiveWebcamAudienceEnabled()) return Promise.resolve(true);
  if (webcamAudienceDecision) return Promise.resolve(false);
  renderWebcamAudiencePeople();
  const saved = String(cfg?.voiceWebcamPreferences?.webcamAudienceMode || 'everyone');
  const choice = webcamAudienceForm?.elements.audience_mode;
  if (choice) choice.value = ['everyone', 'private-voice', 'selected', 'nobody'].includes(saved) ? saved : 'everyone';
  if (webcamAudiencePeople) webcamAudiencePeople.hidden = choice?.value !== 'selected';
  if (webcamAudienceStatus) webcamAudienceStatus.textContent = '';
  webcamAudienceReturnFocus = document.activeElement;
  webcamAudienceModal?.classList.add('open');
  window.requestAnimationFrame(() => {
    const selected = webcamAudienceForm?.querySelector('input[name="audience_mode"]:checked');
    (selected || document.getElementById('webcam-audience-close'))?.focus({ preventScroll: true });
  });
  return new Promise(resolve => { webcamAudienceDecision = resolve; });
}

function webcamAudienceAllowsParticipant(participantId) {
  if (!selectiveWebcamAudienceEnabled()) return true;
  if (!confirmedWebcamAudience) return false;
  if (confirmedWebcamAudience.mode === 'everyone') return true;
  if (confirmedWebcamAudience.mode === 'nobody') return false;
  return confirmedWebcamAudience.participantIds.has(Number(participantId));
}

async function confirmWebcamAudienceSelection() {
  const mode = String(webcamAudienceForm?.elements.audience_mode?.value || '');
  const recipientUserIds = Array.from(webcamAudienceForm?.querySelectorAll('input[name="recipient_user_ids"]:checked') || [])
    .map(input => Number(input.value)).filter(Boolean);
  if (mode === 'selected' && !recipientUserIds.length) throw new Error('Select at least one current room member.');
  const result = await apiPost('/api/media_signal.php', {
    action: 'webcam_audience_confirm',
    media: 'webcam',
    session_id: cfg.sessionId,
    participant_id: cfg.myParticipantId,
    join_token: cfg.myJoinToken,
    client_epoch: voiceRuntime?.media?.clientEpoch?.(),
    audience_mode: mode,
    recipient_user_ids: recipientUserIds,
  });
  let allowedUserIds = recipientUserIds;
  if (mode === 'private-voice') {
    allowedUserIds = (voiceRuntime?.privateVoice?.snapshot?.()?.activeChat?.members || [])
      .map(member => Number(member.userId)).filter(userId => userId !== Number(cfg.myUserId));
  }
  const participantIds = new Set(Array.from(participants.values())
    .filter(person => allowedUserIds.includes(Number(person.user_id)))
    .map(person => Number(person.id)));
  confirmedWebcamAudience = Object.freeze({
    mode,
    revision: Number(result?.audience?.revision || 0),
    participantIds,
    contextHash: mode === 'private-voice' ? allowedUserIds.slice().sort((a, b) => a - b).join(':') : '',
  });
  return true;
}

webcamAudienceForm?.querySelectorAll('input[name="audience_mode"]').forEach(radio => radio.addEventListener('change', () => {
  if (webcamAudiencePeople) webcamAudiencePeople.hidden = webcamAudienceForm.elements.audience_mode.value !== 'selected';
}));

webcamAudienceForm?.addEventListener('submit', async event => {
  event.preventDefault();
  if (webcamAudienceStatus) webcamAudienceStatus.textContent = 'Confirming audience...';
  try {
    await confirmWebcamAudienceSelection();
    closeWebcamAudienceChooser(true);
  } catch (error) {
    if (webcamAudienceStatus) webcamAudienceStatus.textContent = error?.message || 'Audience could not be confirmed.';
  }
});

for (const id of ['webcam-audience-close', 'webcam-audience-cancel']) {
  document.getElementById(id)?.addEventListener('click', () => closeWebcamAudienceChooser(false));
}

async function acquireLocalWebcamCapture(constraints, operation = 'enable') {
  if (!webcamUseAllowed()) {
    throw new Error('Webcam use is disabled for this installation.');
  }
  if (selectiveWebcamAudienceEnabled()) {
    confirmedWebcamAudience = null;
    const confirmed = await chooseWebcamAudience();
    if (!confirmed) return Object.freeze({ status: 'cancelled', operation, token: null, stream: null });
  }
  const token = beginWebcamOperation(true, operation);
  let stream = null;
  try {
    stream = await navigator.mediaDevices.getUserMedia(constraints);
  } catch (error) {
    if (token.generation === webcamOperationGeneration) {
      webcamAcquisitionState = 'failed';
    }
    error.webcamOperationToken = token;
    throw error;
  }
  if (!isCurrentWebcamOperation(token)) {
    releaseWebcamStream(stream);
    recordVoiceLifecycleDiagnostic({
      event: 'local-webcam-acquisition-cancelled',
      participantId: Number(cfg.myParticipantId),
      webcamOperation: operation,
      operationGeneration: token.generation,
      activeOperationGeneration: webcamOperationGeneration,
      outcome: webcamIntent ? 'superseded' : 'cancelled',
    });
    return Object.freeze({
      status: webcamIntent ? 'superseded' : 'cancelled',
      operation,
      token,
      stream: null,
    });
  }
  webcamAcquisitionState = 'ready';
  return Object.freeze({ status: 'completed', operation, token, stream });
}

function watchLocalWebcamStream(stream, operationToken = null) {
  const localVideoTrack = stream?.getVideoTracks?.()[0] || null;
  localVideoTrack?.addEventListener('ended', () => {
    if (!webcamStream?.getVideoTracks?.().includes(localVideoTrack)) return;
    if (operationToken && operationToken.generation !== webcamOperationGeneration) return;
    const endedStream = webcamStream;
    webcamStream = null;
    webcamIntent = false;
    webcamAcquisitionState = 'idle';
    webcamOperationGeneration += 1;
    recordVoiceLifecycleDiagnostic({
      event: 'local-webcam-track-ended',
      participantId: Number(cfg.myParticipantId),
      trackId: localVideoTrack.id,
      readyState: localVideoTrack.readyState,
    });
    endedStream.getTracks().forEach(track => {
      if (track !== localVideoTrack) track.stop();
    });
    apiPost('/api/media_signal.php', {
      action: 'webcam_off',
      media: 'webcam',
      session_id: cfg.sessionId,
      participant_id: cfg.myParticipantId,
      client_epoch: voiceRuntime?.media?.clientEpoch?.(),
      join_token: cfg.myJoinToken,
    }).catch(() => {});
    applyWebcamState(cfg.myParticipantId, false, null, 'local-webcam-track-ended');
    renegotiateMediaPeers({
      reason: 'local-webcam-track-ended',
      mediaReason: 'webcam',
      webcamOperation: 'track-ended',
    });
  }, { once: true });
  return localVideoTrack;
}

async function replaceLocalWebcamCapture(nextStream, operation = 'replace', operationToken = null) {
  if (!webcamUseAllowed()) {
    releaseWebcamStream(nextStream);
    throw new Error('Webcam use is disabled for this installation.');
  }
  const nextTrack = nextStream?.getVideoTracks?.().find(track => track.readyState === 'live') || null;
  if (!nextTrack) throw new Error('Replacement webcam stream has no live video track.');
  const token = operationToken || beginWebcamOperation(true, operation);
  if (!isCurrentWebcamOperation(token)) {
    releaseWebcamStream(nextStream);
    return Object.freeze({
      status: webcamIntent ? 'superseded' : 'cancelled',
      operation,
      generation: token.generation,
    });
  }
  const previousStream = webcamStream;
  const previousTrack = previousStream?.getVideoTracks?.()[0] || null;
  webcamStream = nextStream;
  webcamAcquisitionState = 'ready';
  watchLocalWebcamStream(nextStream, token);
  const me = participants.get(cfg.myParticipantId);
  if (me) {
    participants.update(cfg.myParticipantId, {
      webcam_enabled: true,
      webcam_path: null,
    });
    renderParticipant(me);
  }
  await apiPost('/api/media_signal.php', {
    action: 'webcam_on',
    media: 'webcam',
    session_id: cfg.sessionId,
    participant_id: cfg.myParticipantId,
    client_epoch: voiceRuntime?.media?.clientEpoch?.(),
    join_token: cfg.myJoinToken,
  });
  if (!isCurrentWebcamOperation(token) || webcamStream !== nextStream) {
    return Object.freeze({
      status: webcamIntent ? 'superseded' : 'cancelled',
      operation,
      generation: token.generation,
    });
  }
  await connectMediaPeers({
    reason: `local-webcam-${operation}`,
    mediaReason: 'webcam',
    webcamOperation: operation,
  });
  if (previousStream && previousStream !== nextStream) {
    releaseWebcamStream(previousStream);
  }
  recordVoiceLifecycleDiagnostic({
    event: 'local-webcam-capture-replaced',
    participantId: Number(cfg.myParticipantId),
    webcamOperation: operation,
    previousTrackId: previousTrack?.id || null,
    nextTrackId: nextTrack.id,
    localPreviewTrackId: me?.webcamVideoEl?.srcObject?.getVideoTracks?.()[0]?.id || null,
    localPreviewUsesReplacementTrack: me?.webcamVideoEl?.srcObject?.getVideoTracks?.()[0] === nextTrack,
  });
  restartVoicePoll(0);
  return {
    status: 'completed',
    generation: token.generation,
    previousTrackId: previousTrack?.id || null,
    nextTrackId: nextTrack.id,
  };
}

async function disableLocalWebcam(reason = 'user-disable') {
  const operation = reason === 'user-disable' ? 'disable' : reason;
  const disableToken = beginWebcamOperation(false, operation);
  const previousWebcamStream = webcamStream;
  recordVoiceLifecycleDiagnostic({
    event: 'local-webcam-disable-start',
    participantId: Number(cfg.myParticipantId),
    reason,
    operationGeneration: disableToken.generation,
    tracks: previousWebcamStream?.getTracks?.().map(track => ({
      id: track.id,
      kind: track.kind,
      readyState: track.readyState,
      enabled: track.enabled,
      muted: track.muted,
    })) || [],
  });
  webcamStream = null;
  confirmedWebcamAudience = null;
  releaseWebcamStream(previousWebcamStream);
  applyWebcamState(cfg.myParticipantId, false, null, reason);
  const persistence = apiPost('/api/media_signal.php', {
    action: 'webcam_off',
    media: 'webcam',
    session_id: cfg.sessionId,
    participant_id: cfg.myParticipantId,
    client_epoch: voiceRuntime?.media?.clientEpoch?.(),
    join_token: cfg.myJoinToken,
  });
  const privateNegotiation = !webcamUseAllowed();
  const negotiation = privateNegotiation
    ? voiceRuntime?.media?.reconcileWebcamCapability(
      false,
      'webcam-capability-disabled',
    )
    : renegotiateMediaPeers({
      reason: 'local-webcam-disable',
      mediaReason: 'webcam',
      webcamOperation: 'disable',
    });
  await Promise.all([persistence, negotiation]);
  syncLocalWebcamToggleButton();
  return Object.freeze({ status: 'completed', reason, generation: disableToken.generation });
}

sidebarWebcamToggle?.addEventListener('click', () => ctxToggleWebcam.click());
syncLocalWebcamToggleButton();
ctxToggleWebcam.addEventListener('click', async () => {
  closeContextMenu();
  if (webcamIntent || webcamStream) {
    await disableLocalWebcam('user-disable');
    return;
  }
  if (!webcamUseAllowed()) {
    showWarning('Webcam use is disabled for this installation.');
    return;
  }
  if (!avatarSizeStartConfirmed) {
    openAvatarSizeModal('webcam', { startWebcam: true });
    return;
  }
  avatarSizeStartConfirmed = false;
  let operationToken = null;
  try {
    recordVoiceLifecycleDiagnostic({
      event: 'local-webcam-enable-start',
      participantId: Number(cfg.myParticipantId),
    });
    const acquisition = await acquireLocalWebcamCapture({
      video: { width: { ideal: 640 }, height: { ideal: 640 }, frameRate: { ideal: 30, max: 30 } },
      audio: false,
    }, 'enable');
    if (!acquisition.stream) return;
    operationToken = acquisition.token;
    webcamStream = acquisition.stream;
    syncLocalWebcamToggleButton();
    watchLocalWebcamStream(webcamStream, operationToken);
    recordVoiceLifecycleDiagnostic({
      event: 'local-webcam-getUserMedia-success',
      participantId: Number(cfg.myParticipantId),
      tracks: webcamStream.getTracks().map(track => ({
        id: track.id,
        kind: track.kind,
        readyState: track.readyState,
        enabled: track.enabled,
        muted: track.muted,
      })),
    });
    const me = participants.get(cfg.myParticipantId);
    if (me) {
      recordVoiceLifecycleDiagnostic({
        event: 'webcam-state-change',
        source: 'local-webcam-on',
        participantId: Number(cfg.myParticipantId),
        previous: {
          webcam_enabled: Boolean(me.webcam_enabled),
          webcam_path: me.webcam_path || null,
        },
        next: {
          webcam_enabled: true,
          webcam_path: null,
        },
      });
      participants.update(cfg.myParticipantId, {
        webcam_enabled: true,
        webcam_path: null,
      });
      renderParticipant(me);
    }
    await apiPost('/api/media_signal.php', {
      action: 'webcam_on', media: 'webcam', session_id: cfg.sessionId,
      participant_id: cfg.myParticipantId, client_epoch: voiceRuntime?.media?.clientEpoch?.(),
      join_token: cfg.myJoinToken,
    });
    if (!isCurrentWebcamOperation(operationToken) || webcamStream !== acquisition.stream) return;
    await connectMediaPeers({
      reason: 'local-webcam-enable',
      mediaReason: 'webcam',
      webcamOperation: 'enable',
    });
    restartVoicePoll(0);
    syncLocalWebcamToggleButton();
  } catch (err) {
    const failedToken = err?.webcamOperationToken || operationToken;
    if (failedToken && failedToken.generation !== webcamOperationGeneration) {
      recordVoiceLifecycleDiagnostic({
        event: 'local-webcam-enable-failure-stale',
        participantId: Number(cfg.myParticipantId),
        operationGeneration: failedToken.generation,
        activeOperationGeneration: webcamOperationGeneration,
      });
      return;
    }
    recordVoiceLifecycleDiagnostic({
      event: 'local-webcam-enable-failed',
      participantId: Number(cfg.myParticipantId),
      message: err?.message || String(err),
    });
    releaseWebcamStream(webcamStream);
    webcamStream = null;
    webcamIntent = false;
    webcamAcquisitionState = 'failed';
    webcamOperationGeneration += 1;
    applyWebcamState(cfg.myParticipantId, false, null, 'local-webcam-enable-failed');
    showWarning(err.message || 'Could not enable webcam.');
    syncLocalWebcamToggleButton();
  }
});

function setRoomHeight(pct) {
  document.documentElement.style.setProperty('--room-height', `${pct}%`);
}

function applyDividerDrag(clientY) {
  const rect = mainEl.getBoundingClientRect();
  let pct = ((clientY - rect.top) / rect.height) * 100;
  pct = Math.max(18, Math.min(78, pct));
  setRoomHeight(pct);
  participants.forEach(positionAvatar);
  avatarRuntime?.coordinator?.scheduleRelationshipRefresh({
    all: true,
    reason: 'stage-resize',
  });
}

function setSidebarWidth(px) {
  document.documentElement.style.setProperty('--sidebar-width', `${Math.round(px)}px`);
}

function applyVerticalDividerDrag(clientX) {
  const layoutRect = roomLayout.getBoundingClientRect();
  const dividerEl = document.getElementById('vertical-divider');
  const dividerWidth = dividerEl?.getBoundingClientRect().width || 6;
  const dividerMarginRight = dividerEl ? parseFloat(getComputedStyle(dividerEl).marginRight) || 0 : 0;
  const rightGutter = 10;
  const minMainWidth = 620;
  const minSidebarWidth = 300;
  const maxSidebarWidth = Math.max(minSidebarWidth, Math.min(560, layoutRect.width - dividerWidth - dividerMarginRight - rightGutter - minMainWidth));
  let width = layoutRect.right - clientX - (dividerWidth / 2) - dividerMarginRight - rightGutter;
  width = Math.max(minSidebarWidth, Math.min(maxSidebarWidth, width));
  setSidebarWidth(width);
  participants.forEach(positionAvatar);
  avatarRuntime?.coordinator?.scheduleRelationshipRefresh({
    all: true,
    reason: 'stage-resize',
  });
}

document.getElementById('horizontal-divider')?.addEventListener('pointerdown', e => {
  e.preventDefault();
  const divider = e.currentTarget;
  const pointerId = e.pointerId;
  let finished = false;
  divider.classList.add('dragging');
  if (divider.setPointerCapture) {
    try { divider.setPointerCapture(pointerId); } catch {}
  }
  const onMove = ev => {
    if (typeof ev.buttons === 'number' && ev.buttons === 0) {
      onUp(ev);
      return;
    }
    applyDividerDrag(ev.clientY);
  };
  const onUp = ev => {
    if (finished) return;
    finished = true;
    divider.classList.remove('dragging');
    document.removeEventListener('pointermove', onMove);
    document.removeEventListener('pointerup', onUp);
    document.removeEventListener('pointercancel', onUp);
    document.removeEventListener('mouseup', onUp);
    window.removeEventListener('blur', onUp);
    divider.removeEventListener('lostpointercapture', onUp);
    if (divider.releasePointerCapture) {
      try {
        if (!divider.hasPointerCapture || divider.hasPointerCapture(pointerId)) {
          divider.releasePointerCapture(pointerId);
        }
      } catch {}
    }
  };
  document.addEventListener('pointermove', onMove);
  document.addEventListener('pointerup', onUp);
  document.addEventListener('pointercancel', onUp);
  document.addEventListener('mouseup', onUp);
  window.addEventListener('blur', onUp);
  divider.addEventListener('lostpointercapture', onUp);
});

document.getElementById('vertical-divider')?.addEventListener('pointerdown', e => {
  e.preventDefault();
  const divider = e.currentTarget;
  divider.classList.add('dragging');
  if (divider.setPointerCapture) {
    try { divider.setPointerCapture(e.pointerId); } catch {}
  }
  const onMove = ev => applyVerticalDividerDrag(ev.clientX);
  const onUp = ev => {
    divider.classList.remove('dragging');
    if (divider.releasePointerCapture && ev?.pointerId !== undefined) {
      try { divider.releasePointerCapture(ev.pointerId); } catch {}
    }
    document.removeEventListener('pointermove', onMove);
    document.removeEventListener('pointerup', onUp);
    document.removeEventListener('pointercancel', onUp);
    document.removeEventListener('mouseup', onUp);
    window.removeEventListener('blur', onUp);
  };
  document.addEventListener('pointermove', onMove);
  document.addEventListener('pointerup', onUp);
  document.addEventListener('pointercancel', onUp);
  document.addEventListener('mouseup', onUp);
  window.addEventListener('blur', onUp);
});

function gameName(type) {
  return gameRuntime?.lifecycle?.gameName(type) || type;
}

function gameIconUrl(type) {
  return gameRuntime?.lifecycle?.gameIconUrl(type) || '';
}

function gameFrameUrl(game) {
  return gameRuntime?.lifecycle?.gameFrameUrl(game) || '';
}

function gameSeatRole(type, seat) {
  return gameRuntime?.lifecycle?.gameSeatRole(type, seat) || `Player ${seat}`;
}

function setGameLayerVisibility() {
  gameRuntime?.lifecycle?.setLayerVisibility();
}

async function openGame(a) {
  return gameRuntime?.lifecycle?.openGame(a);
}

async function closeGame(lobbyCode = gameRuntime?.lifecycle?.getActiveGame()?.lobby_code, notifyServer = true) {
  return gameRuntime?.lifecycle?.closeGame(lobbyCode, notifyServer);
}

function gameChatKey(lobbyCode = gameRuntime?.lifecycle?.getActiveGame()?.lobby_code) {
  return chatGameChat().chatKey(lobbyCode);
}

function updateGameStagePlayers() {
  gameRuntime?.lifecycle?.updateStagePlayers();
}

async function sendGameMessage(content) {
  return chatGameChat().sendMessage(content);
}

function stopGameChatPolling(reason = 'game-chat-stopped') {
  chatGameChat().reset();
  gameChatRequestAbortController.abort(reason);
}

function setGameTyping(participantId, active) {
  chatGameChat().setTyping(participantId, active);
}

function startGameChatPolling() {
  gameChatRequestAbortController.abort('game-chat-restarted');
  gameChatRequestAbortController = new AbortController();
  chatGameChat().startPolling();
}

function stopGameTypingNow() {
  chatGameChat().stopTypingNow();
}

function handleGameTypingInput() {
  chatGameChat().handleTypingInput();
}

document.getElementById('game-close').addEventListener('click', () => {
  closeGame();
});

document.getElementById('game-return-chat')?.addEventListener('click', () => {
  switchChat('room');
  document.querySelector('.chat-tab[data-chat-tab="room"]')?.focus?.({ preventScroll: true });
});

document.getElementById('game-rematch')?.addEventListener('click', async () => {
  const notice = document.getElementById('game-session-notice');
  try {
    const result = await gameRuntime?.lifecycle?.runFrameworkAction('rematch');
    if (notice) notice.textContent = result?.message || 'Rematch consent recorded.';
  } catch (error) {
    if (notice) notice.textContent = error?.message || 'The rematch could not be started.';
  }
});

document.getElementById('game-resign')?.addEventListener('click', async () => {
  const notice = document.getElementById('game-session-notice');
  try {
    await gameRuntime?.lifecycle?.runFrameworkAction('forfeit');
    gameRuntime?.lifecycle?.sendStageControl('resign');
    if (notice) notice.textContent = 'The game was forfeited.';
  } catch (error) {
    if (notice) notice.textContent = error?.message || 'The game could not be forfeited.';
  }
});

window.addEventListener('message', e => {
  if (e.data?.type !== 'game_close') return;
  const frameWindow = gameFrame?.contentWindow;
  const activeLobby = String(gameRuntime?.lifecycle?.getActiveGame?.()?.lobby_code || '').trim();
  const lobby = typeof e.data?.lobby === 'string' ? e.data.lobby.trim() : '';
  if (e.origin !== window.location.origin || !frameWindow || e.source !== frameWindow
      || !lobby || lobby !== activeLobby) return;
  closeGame(lobby).catch(warnRuntimeRequest);
});

document.getElementById('edit-room-btn')?.addEventListener('click', () => {
  openRoomEditModal();
});

function openRoomEditModal() {
  document.getElementById('room-edit-name').value = cfg.roomName || '';
  setRoomEditPreview(cfg.backgroundPath || '', cfg.backgroundMime || '', cfg.backgroundThumbPath || '');
  resetUploadProgress(document.getElementById('room-edit-upload-progress'));
  document.getElementById('room-edit-modal').classList.add('open');
  loadRoomEjections();
}

document.getElementById('room-action-edit')?.addEventListener('click', () => {
  closeRoomActionMenu();
  openRoomEditModal();
});

document.getElementById('room-action-effects')?.addEventListener('click', async () => {
  closeRoomActionMenu();
  try {
    await roomEffectsRuntime?.effects?.loadState();
    renderRoomEffectsModal();
    document.getElementById('room-effects-modal').classList.add('open');
  } catch (err) {
    alert(err.message || err);
  }
});

function closeClearRoomHistoryModal() {
  document.getElementById('clear-room-history-modal')?.classList.remove('open');
}

document.getElementById('room-action-clear-history')?.addEventListener('click', () => {
  closeRoomActionMenu();
  document.getElementById('clear-room-history-modal')?.classList.add('open');
});

bindModalCloseButtons(['clear-room-history-close', 'clear-room-history-cancel'], closeClearRoomHistoryModal);

document.getElementById('clear-room-history-confirm')?.addEventListener('click', async e => {
  const btn = e.currentTarget;
  btn.disabled = true;
  try {
    const data = await apiPost('/api/host_tools.php', {
      action: 'clear_room_history',
      session_id: cfg.sessionId,
      join_token: cfg.myJoinToken,
    });
    closeClearRoomHistoryModal();
    handleRoomHistoryClear(data);
  } catch (err) {
    showWarning(err.message || 'Could not clear room history.');
  } finally {
    btn.disabled = false;
  }
});

document.getElementById('room-effects-close')?.addEventListener('click', () => {
  document.getElementById('room-effects-modal').classList.remove('open');
});

document.getElementById('room-effects-form')?.addEventListener('submit', async e => {
  e.preventDefault();
  const select = document.getElementById('room-effect-select');
  if (!select.value) return;
  try {
    const data = await apiPost('/api/room_admin.php', {
      action: 'effect_start',
      session_id: cfg.sessionId,
      join_token: cfg.myJoinToken,
      effect_key: select.value,
      duration_minutes: document.getElementById('room-effect-duration').value,
    });
    cfg.activeRoomEffect = data.current || null;
    await roomEffectsRuntime?.effects?.apply(cfg.activeRoomEffect, false);
    renderRoomEffectsModal();
    document.getElementById('room-effects-modal').classList.remove('open');
  } catch (err) {
    alert(err.message || err);
  }
});

document.getElementById('room-effect-stop')?.addEventListener('click', async () => {
  try {
    await apiPost('/api/room_admin.php', {
      session_id: cfg.sessionId,
      join_token: cfg.myJoinToken,
      action: 'effect_stop',
    });
    cfg.activeRoomEffect = null;
    await roomEffectsRuntime?.effects?.apply(null, false);
    renderRoomEffectsModal();
  } catch (err) {
    alert(err.message || err);
  }
});

document.getElementById('room-edit-close')?.addEventListener('click', () => {
  document.getElementById('room-edit-modal').classList.remove('open');
});

function closeRoomDeleteModal() {
  document.getElementById('room-delete-modal')?.classList.remove('open');
}

document.getElementById('room-delete-open')?.addEventListener('click', () => {
  document.getElementById('room-delete-modal')?.classList.add('open');
});

bindModalCloseButtons(['room-delete-close', 'room-delete-cancel'], closeRoomDeleteModal);

document.getElementById('room-delete-confirm')?.addEventListener('click', async e => {
  const btn = e.currentTarget;
  btn.disabled = true;
  try {
    await apiPost('/api/room_admin.php', {
      action: 'delete',
      session_id: cfg.sessionId,
      join_token: cfg.myJoinToken,
    });
    closeRoomDeleteModal();
    document.getElementById('room-edit-modal')?.classList.remove('open');
    await handleRoomDeleted({ room_name: cfg.roomName });
  } catch (err) {
    alert(err.message || err);
    btn.disabled = false;
  }
});

document.getElementById('room-edit-background')?.addEventListener('change', e => {
  const file = e.target.files && e.target.files[0];
  document.getElementById('room-edit-background-name').textContent = file ? file.name : 'No file selected';
  if (file) setRoomEditPreview(URL.createObjectURL(file), file.type);
});

document.getElementById('room-edit-form')?.addEventListener('submit', async e => {
  e.preventDefault();
  const form = e.currentTarget;
  const fd = new FormData(form);
  fd.append('action', 'update');
  fd.append('session_id', cfg.sessionId);
  fd.append('join_token', cfg.myJoinToken);
  const bgFile = fd.get('background');
  const thumb = await videoThumbnailBlob(bgFile);
  if (thumb) fd.append('background_thumb', thumb, 'background-thumb.jpg');
  const progressEl = document.getElementById('room-edit-upload-progress');
  const submitBtn = form.querySelector('button[type="submit"]');
  try {
    const update = await apiUploadWithProgress('/api/room_admin.php', fd, progressEl, submitBtn);
    applyRoomUpdate(update);
    resetUploadProgress(progressEl);
    document.getElementById('room-edit-modal').classList.remove('open');
  } catch (err) {
    alert(err.message || err);
    resetUploadProgress(progressEl);
  }
});

async function loadRoomEjections() {
  const list = document.getElementById('room-ejection-list');
  if (!list) return;
  list.innerHTML = '<div class="minor">Loading...</div>';
  try {
    const qs = new URLSearchParams({ action: 'ejections', session_id: cfg.sessionId });
    const data = await runtimeRequestClient.getJson('/api/room_admin.php?' + qs, {
      operation: 'load-room-ejections',
      endpointCategory: 'room-admin',
    });
    list.innerHTML = '';
    if (!(data.ejections || []).length) {
      list.innerHTML = '<div class="minor">No active kicks.</div>';
      return;
    }
    (data.ejections || []).forEach(ejection => {
      const row = document.createElement('div');
      row.className = 'ejection-row';
      const duration = ejection.permanent ? 'Permanent' : `${ejection.duration_minutes} minutes`;
      row.innerHTML = `<div><strong>${esc(ejection.display_name)}</strong><div class="minor">${esc(duration)} · by ${esc(ejection.ejected_by_name)}</div></div><button class="btn btn-danger" type="button">Delete</button>`;
      row.querySelector('button').addEventListener('click', async () => {
        await apiPost('/api/room_admin.php', { action: 'ejection_delete', session_id: cfg.sessionId, id: ejection.id });
        await loadRoomEjections();
      });
      list.appendChild(row);
    });
  } catch (err) {
    list.innerHTML = `<div class="minor">${esc(err.message || 'Could not load kicked users.')}</div>`;
  }
}

document.getElementById('ctx-host-warn')?.addEventListener('click', () => {
  const target = participants.get(ctxMenuParticipantId);
  if (!target) return;
  hostModalTargetParticipantId = target.id;
  closeContextMenu();
  document.getElementById('host-warn-target').textContent = `Warning ${displayNameFor(target)}`;
  document.getElementById('host-warn-message').value = '';
  document.getElementById('host-warn-modal').classList.add('open');
  document.getElementById('host-warn-message').focus();
});

document.getElementById('host-warn-close')?.addEventListener('click', () => {
  hostModalTargetParticipantId = null;
  document.getElementById('host-warn-modal').classList.remove('open');
});

document.getElementById('host-warn-form')?.addEventListener('submit', async e => {
  e.preventDefault();
  const target = participants.get(hostModalTargetParticipantId);
  if (!target) return;
  await apiPost('/api/host_tools.php', {
    action: 'warn',
    session_id: cfg.sessionId,
    join_token: cfg.myJoinToken,
    target_participant_id: target.id,
    message: document.getElementById('host-warn-message').value,
  });
  hostModalTargetParticipantId = null;
  document.getElementById('host-warn-modal').classList.remove('open');
});

document.getElementById('ctx-host-kick')?.addEventListener('click', () => {
  const target = participants.get(ctxMenuParticipantId);
  if (!target) return;
  hostModalTargetParticipantId = target.id;
  closeContextMenu();
  document.getElementById('host-kick-target').textContent = `Kick ${displayNameFor(target)} from this room`;
  document.getElementById('host-kick-duration').value = '5';
  document.getElementById('host-kick-modal').classList.add('open');
});

document.getElementById('host-kick-close')?.addEventListener('click', () => {
  hostModalTargetParticipantId = null;
  document.getElementById('host-kick-modal').classList.remove('open');
});

document.getElementById('host-kick-form')?.addEventListener('submit', async e => {
  e.preventDefault();
  const target = participants.get(hostModalTargetParticipantId);
  if (!target) return;
  const value = document.getElementById('host-kick-duration').value;
  await apiPost('/api/host_tools.php', {
    action: 'kick',
    session_id: cfg.sessionId,
    join_token: cfg.myJoinToken,
    target_participant_id: target.id,
    permanent: value === 'permanent',
    duration_minutes: value === 'permanent' ? null : Number(value),
  });
  hostModalTargetParticipantId = null;
  document.getElementById('host-kick-modal').classList.remove('open');
  await loadRoomEjections();
});

document.getElementById('ctx-community-eject')?.addEventListener('click', () => {
  const target = participants.get(ctxMenuParticipantId);
  if (!target) return;
  hostModalTargetParticipantId = target.id;
  closeContextMenu();
  document.getElementById('community-eject-target').textContent = `Eject ${displayNameFor(target)} from the community`;
  document.getElementById('community-eject-duration').value = '5';
  document.getElementById('community-eject-reason').value = '';
  document.getElementById('community-eject-modal').classList.add('open');
});

document.getElementById('community-eject-close')?.addEventListener('click', () => {
  hostModalTargetParticipantId = null;
  document.getElementById('community-eject-modal').classList.remove('open');
});

document.getElementById('community-eject-form')?.addEventListener('submit', async e => {
  e.preventDefault();
  const target = participants.get(hostModalTargetParticipantId);
  if (!target) return;
  const value = document.getElementById('community-eject-duration').value;
  await apiPost('/api/host_tools.php', {
    action: 'community_eject',
    session_id: cfg.sessionId,
    join_token: cfg.myJoinToken,
    target_participant_id: target.id,
    permanent: value === 'permanent',
    duration_minutes: value === 'permanent' ? null : Number(value),
    reason: document.getElementById('community-eject-reason').value,
  });
  hostModalTargetParticipantId = null;
  document.getElementById('community-eject-modal').classList.remove('open');
});

document.getElementById('host-notice-understand')?.addEventListener('click', e => {
  document.getElementById('host-notice-modal').classList.remove('open');
  if (e.currentTarget.dataset.redirect === '1') window.location.href = e.currentTarget.dataset.redirectUrl || appUrl('/lobby.php');
});

function memberProfileParticipant(userId) {
  return [...participants.values()].find(
    participant => Number(participant.user_id) === Number(userId)
  ) || null;
}

function memberProfilePublicProfileId(userId, explicitPublicProfileId = '') {
  return String(
    explicitPublicProfileId
      || memberProfileParticipant(userId)?.public_profile_id
      || ''
  ).trim();
}

async function waitForMemberProfileIdentity(userId, options = {}) {
  const requestVersion = Number(options.requestVersion || 0);
  const timeoutMs = Math.max(
    0,
    Number(options.timeoutMs ?? MEMBER_PROFILE_IDENTITY_WAIT_TIMEOUT_MS)
  );
  let publicProfileId = memberProfilePublicProfileId(userId, options.publicProfileId);
  if (publicProfileId) return publicProfileId;

  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (requestVersion > 0 && requestVersion !== memberProfileRequestVersion) return '';
    const remainingMs = Math.max(0, deadline - Date.now());
    await new Promise(resolve => setTimeout(
      resolve,
      Math.min(MEMBER_PROFILE_IDENTITY_WAIT_INTERVAL_MS, remainingMs)
    ));
    publicProfileId = memberProfilePublicProfileId(userId);
    if (publicProfileId) return publicProfileId;
  }
  return '';
}

function closeMemberProfile(options = {}) {
  if (!memberProfileModal?.classList.contains('open')) return;
  memberProfileRequestVersion += 1;
  memberProfileModal.classList.remove('open');
  memberProfileUserId = null;
  memberProfileSnapshot = null;
  const returnFocus = memberProfileReturnFocus;
  memberProfileReturnFocus = null;
  if (options.restoreFocus && returnFocus?.isConnected) returnFocus.focus();
}

function appendMemberProfileField(label, value, options = {}) {
  const hasValue = value !== null && value !== undefined && String(value).trim() !== '';
  const renderedValue = hasValue ? String(value) : String(options.emptyText || 'Not provided');
  const list = document.getElementById('member-profile-fields');
  const term = document.createElement('dt');
  term.textContent = label;
  const description = document.createElement('dd');
  if (hasValue && options.href) {
    const link = document.createElement('a');
    link.href = options.href;
    link.textContent = renderedValue;
    if (options.external) {
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
    }
    description.appendChild(link);
  } else {
    description.textContent = renderedValue;
  }
  if (options.multiline) description.classList.add('member-profile-multiline');
  list.append(term, description);
}

function memberProfileAction(label, handler, options = {}) {
  const button = document.createElement(options.href ? 'a' : 'button');
  button.className = `btn${options.danger ? ' btn-danger' : ''}`;
  button.textContent = label;
  if (options.href) {
    button.href = options.href;
    button.target = '_blank';
    button.rel = 'noopener noreferrer';
  } else {
    button.type = 'button';
    button.disabled = Boolean(options.disabled);
    button.addEventListener('click', handler);
  }
  memberProfileActions.appendChild(button);
  return button;
}

async function setMemberProfileBlockState(profile, blocked) {
  const participant = memberProfileParticipant(memberProfileUserId);
  if (participant) {
    await setBlockState(participant, blocked);
    return;
  }
  if (blocked) blockedUserIds.add(Number(memberProfileUserId));
  else blockedUserIds.delete(Number(memberProfileUserId));
  await apiPost('/api/users.php', {
    action: blocked ? 'block_user' : 'unblock_user',
    session_id: cfg.sessionId,
    join_token: cfg.myJoinToken,
    target_user_id: Number(memberProfileUserId),
  });
  renderPeople();
  renderLinkTabs();
  renderActiveChat();
}

async function runMemberProfilePolicyAction(actionId, participant) {
  if (!participant) return;
  if (actionId === 'avatar.current-visibility') {
    const policy = avatarVisibilityFor(participant);
    await avatarRuntime?.visibility?.setExactHidden(participant, !policy.exact);
  } else if (actionId === 'avatar.user-visibility') {
    const policy = avatarVisibilityFor(participant);
    await avatarRuntime?.visibility?.setUserHidden(participant, !policy.user);
  } else if (actionId === 'nameplate.current-visibility') {
    const policy = nameplateVisibilityFor(participant);
    await setNameplateVisibility(participant, 'nameplate', !policy.exact);
  } else if (actionId === 'nameplate.user-visibility') {
    const policy = nameplateVisibilityFor(participant);
    await setNameplateVisibility(participant, 'user', !policy.user);
  } else if (actionId === 'gesture.sender-media-visibility') {
    const hidden = gesturePresentation?.isSenderHidden?.(participant.user_id) === true;
    await setGestureSenderMediaHidden(Number(participant.user_id), !hidden);
  } else if (actionId === 'webcam.presentation') {
    const policy = webcamViewerPolicyFor(participant);
    await voiceRuntime?.viewerPolicy?.setParticipantPresentation(
      participant.user_id,
      !policy.show
    );
  } else if (actionId === 'webcam.receive') {
    const policy = webcamViewerPolicyFor(participant);
    await voiceRuntime?.viewerPolicy?.setParticipantReceive(
      participant.user_id,
      !policy.receive
    );
  }
}

function renderMemberProfileActions(profile) {
  memberProfileActions.replaceChildren();
  const participant = memberProfileParticipant(memberProfileUserId);
  if (profile.isSelf) {
    memberProfileAction('Edit Public Profile', null, {
      href: appUrl(`/account.php?return=room&id=${encodeURIComponent(document.body.dataset.roomId || '')}`),
    });
  } else {
    const blocked = blockedUserIds.has(Number(memberProfileUserId));
    if (!blocked) {
      memberProfileAction('Send DM', () => {
        const targetUserId = Number(memberProfileUserId);
        closeMemberProfile();
        openDmWithUser({
          id: targetUserId,
          display_name: profile.effectiveDisplayName || profile.displayName || 'Member',
          avatar_url: profile.avatarUrl || '',
        });
      });
    }
    memberProfileAction(blocked ? 'Unblock' : 'Block', async event => {
      if (!blocked && event.currentTarget.dataset.blockConfirmed !== '1') {
        event.currentTarget.dataset.blockConfirmed = '1';
        event.currentTarget.textContent = 'Confirm Block';
        showWarning('Blocking ends active direct pairs and prevents direct messages, relationship actions, invitations, direct files, and directed media. It does not delete unrelated group members or history.');
        return;
      }
      event.currentTarget.disabled = true;
      try {
        await setMemberProfileBlockState(profile, !blocked);
        renderMemberProfileActions(profile);
      } catch (error) {
        event.currentTarget.disabled = false;
        showWarning(error?.message || 'Block setting could not be changed.');
      }
    }, { danger: !blocked });
    memberProfileAction('Report', () => {
      const target = new URL(appUrl('/account.php'), globalThis.location.origin);
      target.searchParams.set('tab', 'safety');
      target.searchParams.set('report_user_id', String(memberProfileUserId));
      target.searchParams.set('report_reference', `profile:${profile.publicProfileId || ''}`);
      globalThis.open(target.toString(), '_blank', 'noopener,noreferrer');
    });
    const muted = Boolean(activeMutedPolicyFor(memberProfileUserId));
    memberProfileAction(muted ? 'Unmute' : 'Mute', async event => {
      event.currentTarget.disabled = true;
      try {
        await setPersonalMuteState(memberProfileUserId, !muted);
        renderMemberProfileActions(profile);
      } catch (error) {
        event.currentTarget.disabled = false;
        showWarning(error?.message || 'Mute setting could not be changed.');
      }
    });
    if (cfg.canModerateMessages) {
      memberProfileAction('Open Moderation Panel', () => {
        const target = new URL(appUrl('/lobby.php'), globalThis.location.origin);
        target.searchParams.set('admin', 'users');
        target.searchParams.set('target_user_id', String(memberProfileUserId));
        globalThis.open(target.toString(), '_blank', 'noopener,noreferrer');
      });
    }
    if (participant) {
      const delegated = new Map(
        (roomRuntime?.participantActions?.actionsFor(participant) || [])
          .map(action => [action.id, action])
      );
      [
        'avatar.current-visibility',
        'avatar.user-visibility',
        'nameplate.current-visibility',
        'nameplate.user-visibility',
        'gesture.sender-media-visibility',
        'webcam.presentation',
        'webcam.receive',
      ].forEach(actionId => {
        const action = delegated.get(actionId);
        if (!action || action.applicable === false) return;
        memberProfileAction(action.label, async event => {
          event.currentTarget.disabled = true;
          try {
            await runMemberProfilePolicyAction(actionId, participant);
            renderMemberProfileActions(profile);
          } catch (error) {
            event.currentTarget.disabled = false;
            showWarning(error?.message || 'Member setting could not be changed.');
          }
        }, { disabled: action.disabled });
      });
    }
  }
  if (profile.profileUrl) {
    memberProfileAction('Open Shareable Profile', null, { href: profile.profileUrl });
  }
  memberProfileAction('Close', () => closeMemberProfile({ restoreFocus: true }));
}

function renderMemberProfile(profile) {
  memberProfileSnapshot = profile;
  memberProfileStatus.textContent = '';
  memberProfileContent.hidden = false;
  const effectiveDisplayName = profile.effectiveDisplayName
    || profile.displayName
    || 'Member';
  document.getElementById('member-profile-title').textContent = `${effectiveDisplayName}'s User Profile`;
  document.getElementById('member-profile-display-name').textContent = effectiveDisplayName;
  const profileIdentitySubtitle = document.getElementById('member-profile-username');
  if (profileIdentitySubtitle) {
    profileIdentitySubtitle.textContent = '';
    profileIdentitySubtitle.hidden = true;
  }
  const avatar = document.getElementById('member-profile-avatar');
  const avatarParticipant = {
    avatar_source_width_px: profile.avatarSourceWidthPx,
    avatar_source_height_px: profile.avatarSourceHeightPx,
    avatar_display_size_px: profile.avatarDisplayMaxEdgePx,
  };
  const renderedAvatar = avatarRuntime?.renderer?.renderProfileAvatar?.(
    avatar,
    avatarParticipant,
    {
      avatarSource: mediaUrl(profile.avatarUrl || cfg?.avatarPresets?.Default || ''),
      avatarHidden: Boolean(profile.avatarHidden),
      avatarHiddenNotice: profile.avatarHiddenNotice,
      displayName: effectiveDisplayName,
      orientation: profile.avatarOrientation,
      window: globalThis,
    }
  );
  if (!renderedAvatar) {
    avatar.replaceChildren();
    const placeholder = document.createElement('div');
    placeholder.className = 'member-profile-avatar-placeholder';
    placeholder.setAttribute('role', 'img');
    placeholder.setAttribute(
      'aria-label',
      profile.avatarHidden
        ? (profile.avatarHiddenNotice || 'Avatar hidden by you')
        : `Standard avatar for ${effectiveDisplayName}`
    );
    placeholder.textContent = profile.avatarHidden ? 'Avatar hidden by you' : 'Standard avatar';
    avatar.appendChild(placeholder);
  }
  document.getElementById('member-profile-fields').replaceChildren();
  appendMemberProfileField(
    'Display name',
    profile.displayName,
    { emptyText: `Not separately set — shown as ${effectiveDisplayName}` }
  );
  appendMemberProfileField('Name', profile.name);
  appendMemberProfileField('Location', profile.location);
  appendMemberProfileField('About Me', profile.aboutMe, { multiline: true });
  appendMemberProfileField(
    'Public profile contact email',
    profile.publicContactEmail,
    profile.publicContactEmail
      ? { href: `mailto:${encodeURIComponent(profile.publicContactEmail)}` }
      : {}
  );
  appendMemberProfileField(
    'Website',
    profile.website,
    profile.website ? { href: profile.website, external: true } : {}
  );
  appendMemberProfileField('Interests', profile.interests, { multiline: true });
  if (profile.discordUsername) {
    appendMemberProfileField('Discord username', profile.discordUsername);
  }
  appendMemberProfileField('Registered', profile.registeredAt);
  const history = document.getElementById('member-profile-history-list');
  history.replaceChildren();
  (profile.previousDisplayNames || []).forEach(entry => {
    const item = document.createElement('li');
    const value = document.createElement('span');
    value.textContent = entry.displayName || '';
    const date = document.createElement('small');
    date.textContent = entry.changedAt || '';
    item.append(value, date);
    history.appendChild(item);
  });
  if (!history.children.length) {
    const empty = document.createElement('li');
    empty.className = 'minor';
    empty.textContent = 'None';
    history.appendChild(empty);
  }
  const warning = document.getElementById('member-profile-warning');
  warning.hidden = !profile.priorUsernameUseWarning;
  warning.textContent = profile.priorUsernameUseWarning || '';
  renderMemberProfileActions(profile);
}

function requestMemberProfile(publicProfileId) {
  const profileId = String(publicProfileId || '').trim();
  const existingRequest = memberProfileRequests.get(profileId);
  if (existingRequest) return existingRequest;
  const request = runtimeRequestClient.getJson(
    `/api/member_profile.php?profile_id=${encodeURIComponent(profileId)}`,
    { operation: 'member-profile', endpointCategory: 'account' }
  ).finally(() => {
    if (memberProfileRequests.get(profileId) === request) {
      memberProfileRequests.delete(profileId);
    }
  });
  memberProfileRequests.set(profileId, request);
  return request;
}

async function openMemberProfile(userId, options = {}) {
  const normalizedUserId = Number(userId);
  if (!memberProfileModal || normalizedUserId < 1) return;
  const requestVersion = ++memberProfileRequestVersion;
  memberProfileUserId = normalizedUserId;
  memberProfileReturnFocus = options.returnFocus || document.activeElement;
  memberProfileContent.hidden = true;
  memberProfileStatus.textContent = 'Loading profile...';
  memberProfileModal.classList.add('open');
  document.getElementById('member-profile-close')?.focus();
  let publicProfileId = memberProfilePublicProfileId(userId, options.publicProfileId);
  if (!publicProfileId) {
    memberProfileStatus.textContent = 'Loading member identity...';
    publicProfileId = await waitForMemberProfileIdentity(userId, { requestVersion });
    if (requestVersion !== memberProfileRequestVersion || Number(userId) !== memberProfileUserId) return;
    if (!publicProfileId) {
      memberProfileStatus.textContent = 'This member is still loading. Please try User Profile again in a moment.';
      return;
    }
  }
  const cachedProfile = memberProfileCache.get(publicProfileId);
  if (cachedProfile) {
    renderMemberProfile(cachedProfile);
    memberProfileStatus.textContent = 'Refreshing profile...';
  } else {
    const localParticipant = memberProfileParticipant(userId);
    if (localParticipant) {
      renderMemberProfile({
        publicProfileId,
        isSelf: Number(userId) === Number(cfg.myUserId),
        effectiveDisplayName: localParticipant.display_name || 'Member',
        avatarUrl: avatarUrl(localParticipant),
        avatarMissing: !localParticipant.avatar_path,
      });
      memberProfileStatus.textContent = 'Loading full profile details...';
    }
  }
  try {
    const data = await requestMemberProfile(publicProfileId);
    if (requestVersion !== memberProfileRequestVersion || Number(userId) !== memberProfileUserId) return;
    const profile = data.profile || {};
    memberProfileCache.set(publicProfileId, profile);
    renderMemberProfile(profile);
  } catch (error) {
    if (requestVersion !== memberProfileRequestVersion || Number(userId) !== memberProfileUserId) return;
    memberProfileStatus.textContent = error?.message || 'User Profile could not be loaded.';
  }
}

document.getElementById('member-profile-close')?.addEventListener(
  'click',
  () => closeMemberProfile({ restoreFocus: true })
);
memberProfileModal?.addEventListener('click', event => {
  if (event.target === memberProfileModal) closeMemberProfile({ restoreFocus: true });
});
memberProfileModal?.addEventListener('keydown', event => {
  if (event.key !== 'Tab') return;
  const focusable = [...memberProfileModal.querySelectorAll(
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
  )].filter(element => !element.hidden && element.getClientRects().length > 0);
  if (!focusable.length) return;
  const first = focusable[0];
  const last = focusable[focusable.length - 1];
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
});

async function loadFriends() {
  const q = document.getElementById('friend-search')?.value || '';
  const loadingEl = document.getElementById('friend-loading');
  if (q.length === 0) {
    loadingEl.style.display = 'none';
    friendListEl.innerHTML = '';
    return;
  }
  loadingEl.style.display = 'flex';
  friendListEl.innerHTML = '';
  try {
    const data = await runtimeRequestClient.getJson('/api/locate.php?q=' + encodeURIComponent(q), {
      operation: 'locate-friends',
      endpointCategory: 'friends',
    });
    if (document.getElementById('friend-search').value !== q) return;
    const renderedFriendIds = new Set();
    (data.friends || []).forEach(f => {
      const friendId = Number(f.id);
      if (!Number.isFinite(friendId) || renderedFriendIds.has(friendId)) return;
      renderedFriendIds.add(friendId);
      const knownParticipant = [...participants.values()].find(p => Number(p.user_id) === Number(f.id));
      const locateSubject = knownParticipant || f;
      const locateAvatar = knownParticipant ? avatarUrl(knownParticipant) : mediaUrl(f.avatar_url);
      const dmTarget = knownParticipant
        ? { ...f, avatar_url: locateAvatar, avatar_path: knownParticipant.avatar_path }
        : f;
      const row = document.createElement('div');
      row.className = 'person-row';
      const profileButton = '<button class="btn locate-profile-btn" type="button">User Profile</button>';
      const go = f.room_id
        ? (f.room_ejected
          ? `<button class="btn locate-action-btn" type="button" disabled aria-label="Room unavailable" title="Room unavailable"><img src="${esc(appUrl('/assets/images/lobby.png'))}" alt=""></button>`
          : `<a class="btn locate-action-btn" href="${esc(appUrl('/chatroom.php?id=' + encodeURIComponent(f.room_id)))}" aria-label="Go to room" title="Go"><img src="${esc(appUrl('/assets/images/lobby.png'))}" alt=""></a>`)
        : '<span class="minor locate-away">Away</span>';
      row.innerHTML = `${avatarPresentationHtml(locateSubject, { source: locateAvatar, displayName: f.display_name, title: false })}<div><strong>${esc(f.display_name)}</strong><div class="minor">${f.room_name ? esc(f.room_name) : 'Not in a room'}</div></div>${profileButton}<button class="btn locate-action-btn dm-locate-btn" type="button" aria-label="Send DM" title="DM"><img src="${esc(appUrl('/assets/images/chat-pane-dm.png'))}" alt=""></button>${go}`;
      row.querySelector('.locate-profile-btn').addEventListener('click', event => {
        openMemberProfile(Number(f.id), {
          returnFocus: event.currentTarget,
          publicProfileId: f.public_profile_id,
        }).catch(error => {
          showWarning(error?.message || 'User Profile could not be opened.');
        });
      });
      row.querySelector('.dm-locate-btn').addEventListener('click', () => {
        document.getElementById('locate-modal').classList.remove('open');
        openDmWithUser(dmTarget);
      });
      friendListEl.appendChild(row);
    });
  } finally {
    if (document.getElementById('friend-search').value === q) loadingEl.style.display = 'none';
  }
}

document.getElementById('locate-btn').addEventListener('click', async () => {
  document.getElementById('locate-modal').classList.add('open');
  document.getElementById('friend-search').value = '';
  document.getElementById('friend-loading').style.display = 'none';
  friendListEl.innerHTML = '';
  document.getElementById('friend-search').focus();
});

document.getElementById('locate-close').addEventListener('click', () => {
  document.getElementById('locate-modal').classList.remove('open');
});

function getSeenAppVersion() {
  try {
    if (globalThis.localStorage) return localStorage.getItem(APP_VERSION_CACHE_KEY) || '';
  } catch {}
  const match = document.cookie.match(new RegExp(`(?:^|; )${APP_VERSION_CACHE_KEY}=([^;]*)`));
  return match ? decodeURIComponent(match[1]) : memorySeenVersion;
}

function setSeenAppVersion(version) {
  memorySeenVersion = version;
  try {
    if (globalThis.localStorage) {
      localStorage.setItem(APP_VERSION_CACHE_KEY, version);
      return;
    }
  } catch {}
  document.cookie = `${APP_VERSION_CACHE_KEY}=${encodeURIComponent(version)}; path=/; max-age=31536000; SameSite=Lax`;
}

async function pollAppVersion() {
  try {
    const data = await runtimeRequestClient.getJson('/api/version.php', {
      operation: 'poll-application-version',
      endpointCategory: 'version',
      cache: 'no-store',
    });
    const version = String(data.version || '').trim();
    const attribution = String(data.attribution || '').trim();
    if (!version) return;
    latestAppVersion = version;
    if (appVersionEl) {
      appVersionEl.textContent = attribution
        ? `${String(data.displayVersion || version)} \u00B7 ${attribution}`
        : String(data.displayVersion || version);
    }
    const cachedVersion = getSeenAppVersion();
    if (!cachedVersion) {
      setSeenAppVersion(version);
      return;
    }
    if (cachedVersion !== version && versionBanner) {
      versionBannerText.textContent = `${version} is available.`;
      versionBanner.hidden = false;
    }
  } catch {
    if (appVersionEl && !latestAppVersion) appVersionEl.textContent = 'Version unavailable';
  }
}

versionRefreshBtn?.addEventListener('click', () => {
  if (latestAppVersion) setSeenAppVersion(latestAppVersion);
  const url = new URL(window.location.href);
  url.searchParams.set('refresh', Date.now().toString());
  window.location.replace(url.toString());
});

document.getElementById('friend-search').addEventListener('input', () => {
  clearTimeout(window.friendSearchTimer);
  window.friendSearchTimer = setTimeout(loadFriends, 120);
});

function updateVoiceToggleButton() {
  const btn = document.getElementById('voice-toggle');
  if (!btn) return;
  const joined = Boolean(voiceRuntime?.media?.isJoined());
  const privateContext = voiceRuntime?.media?.getState?.()?.voiceContext?.type === 'private-voice';
  btn.textContent = joined
    ? (privateContext ? 'Leave Private Voice' : 'Leave Voice')
    : (privateContext ? 'Join Private Voice' : 'Join Voice');
  btn.classList.toggle('active', joined);
  voiceRuntime?.transmissionModes?.render?.();
}

function setPrivateVoiceStatus(message, state = '') {
  if (!privateVoiceStatus) return;
  privateVoiceStatus.textContent = message || '';
  privateVoiceStatus.classList.remove('ok', 'error', 'working');
  if (state) privateVoiceStatus.classList.add(state);
}

function privateVoiceExpiryLabel(value) {
  const date = new Date(`${String(value || '').replace(' ', 'T')}Z`);
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' });
}

function privateVoiceMemberList(chat) {
  const members = Array.isArray(chat?.members) ? chat.members : [];
  return members.length
    ? `<ul class="private-voice-members">${members.map(member => `<li>${esc(member.displayName || 'Member')}</li>`).join('')}</ul>`
    : '<p class="minor">No current members.</p>';
}

function renderPrivateVoiceSnapshot(snapshot) {
  if (!privateVoiceContent) return;
  const policy = cfg?.voiceWebcamPolicy?.privateVoice || snapshot?.policy?.privateVoice || {};
  const enabled = Boolean(policy.enabled);
  if (privateVoiceOpen) privateVoiceOpen.hidden = !enabled;
  if (privateVoicePolicyNote) {
    privateVoicePolicyNote.textContent = enabled
      ? `Private calls are limited to ${Number(policy.participantLimit) || 4} participants; 4 is recommended. Invitations and requests expire after 180 seconds.`
      : 'Private Voice Chats are disabled for this installation.';
  }
  if (!enabled) {
    privateVoiceContent.innerHTML = '<p>Private Voice Chats are unavailable.</p>';
    return;
  }

  const active = snapshot?.activeChat || null;
  const invitations = Array.isArray(snapshot?.invitations) ? snapshot.invitations : [];
  const requests = Array.isArray(snapshot?.joinRequests) ? snapshot.joinRequests : [];
  const available = (Array.isArray(snapshot?.availableChats) ? snapshot.availableChats : [])
    .filter(chat => !active || chat.id !== active.id);
  const activeMemberIds = new Set((active?.members || []).map(member => Number(member.userId)));
  const inviteOptions = (cfg?.participants || [])
    .filter(person => Number(person.user_id) !== Number(cfg?.myUserId) && !activeMemberIds.has(Number(person.user_id)))
    .map(person => `<option value="${Number(person.user_id)}">${esc(displayNameFor(person))}</option>`)
    .join('');
  const sections = [];

  if (active) {
    sections.push(`<section class="private-voice-panel" data-private-chat-id="${esc(active.id)}">
      <div class="private-voice-panel-heading"><div><strong>Current private call</strong><span class="minor">${Number(active.memberCount) || 0} of ${Number(active.participantLimit) || 4} members</span></div>
      <button class="btn btn-primary" type="button" data-private-voice-action="join-audio" data-chat-id="${esc(active.id)}">Join audio</button></div>
      ${privateVoiceMemberList(active)}
      ${inviteOptions ? `<label>Invite a room member<select data-private-voice-invitee><option value="">Choose a person</option>${inviteOptions}</select></label><button class="btn" type="button" data-private-voice-action="invite" data-chat-id="${esc(active.id)}">Send invitation</button>` : '<p class="minor">No other eligible room members are available to invite.</p>'}
      <button class="btn btn-danger" type="button" data-private-voice-action="leave-membership">Leave private call</button>
    </section>`);
  } else {
    sections.push('<section class="private-voice-panel"><div class="private-voice-panel-heading"><strong>Start a private call</strong><button class="btn btn-primary" type="button" data-private-voice-action="create-chat">Create private call</button></div><p class="minor">Creating a call makes you its first member. Other people receive no audio until they are admitted and join audio.</p></section>');
  }

  if (invitations.length) {
    sections.push(`<section class="private-voice-panel"><strong>Invitations</strong>${invitations.map(invitation => `<article class="private-voice-request"><span><strong>${esc(invitation.from)}</strong> invited you. Expires ${esc(privateVoiceExpiryLabel(invitation.expiresAt))}.</span><span><button class="btn btn-primary" type="button" data-private-voice-action="accept-invitation" data-id="${esc(invitation.id)}">Accept</button><button class="btn" type="button" data-private-voice-action="reject-invitation" data-id="${esc(invitation.id)}">No</button></span></article>`).join('')}</section>`);
  }
  if (requests.length) {
    sections.push(`<section class="private-voice-panel"><strong>Join requests</strong>${requests.map(request => `<article class="private-voice-request"><span><strong>${esc(request.requesterName)}</strong> asked to join. Expires ${esc(privateVoiceExpiryLabel(request.expiresAt))}.</span><span><button class="btn btn-primary" type="button" data-private-voice-action="approve-request" data-id="${esc(request.id)}">Approve</button><button class="btn" type="button" data-private-voice-action="reject-request" data-id="${esc(request.id)}">No</button></span></article>`).join('')}</section>`);
  }
  if (available.length) {
    sections.push(`<section class="private-voice-panel"><strong>Private calls you may request to join</strong>${available.map(chat => `<article class="private-voice-request"><span>${privateVoiceMemberList(chat)}</span><button class="btn" type="button" data-private-voice-action="request-join" data-chat-id="${esc(chat.id)}">Ask to join</button></article>`).join('')}</section>`);
  }
  privateVoiceContent.innerHTML = sections.join('');
}

async function selectVoiceContext(context) {
  if (voiceRuntime?.media?.isJoined()) await voiceRuntime.media.leave();
  voiceRuntime?.media?.setVoiceContext(context);
  updateVoiceToggleButton();
}

async function handlePrivateVoiceAction(button) {
  const action = String(button.dataset.privateVoiceAction || '');
  const service = voiceRuntime?.privateVoice;
  if (!service) return;
  button.disabled = true;
  setPrivateVoiceStatus('Applying private voice change...', 'working');
  try {
    if (action === 'join-audio') {
      await selectVoiceContext({ type: 'private-voice', publicId: button.dataset.chatId });
      closePrivateVoiceModal();
      await openVoiceDeviceModal();
      setPrivateVoiceStatus('', '');
      return;
    }
    if (action === 'leave-membership') {
      await selectVoiceContext({ type: 'room', publicId: null });
      await service.action('leave');
    } else if (action === 'create-chat') {
      await service.action('create_chat');
    } else if (action === 'invite') {
      const recipient = Number(privateVoiceContent.querySelector('[data-private-voice-invitee]')?.value || 0);
      if (!recipient) throw new Error('Choose a person to invite.');
      await service.action('invite', { chat_id: button.dataset.chatId, recipient_user_id: recipient });
    } else if (action === 'accept-invitation') {
      await service.action('accept_invitation', { invitation_id: button.dataset.id });
    } else if (action === 'reject-invitation') {
      await service.action('reject_invitation', { invitation_id: button.dataset.id });
    } else if (action === 'request-join') {
      await service.action('request_join', { chat_id: button.dataset.chatId });
    } else if (action === 'approve-request') {
      await service.action('approve_request', { join_request_id: button.dataset.id });
    } else if (action === 'reject-request') {
      await service.action('reject_request', { join_request_id: button.dataset.id });
    }
    setPrivateVoiceStatus('Private voice updated.', 'ok');
  } catch (error) {
    setPrivateVoiceStatus(error?.message || 'Private voice could not be updated.', 'error');
  } finally {
    button.disabled = false;
  }
}

function restartVoicePoll(delay = 0) {
  voiceRuntime?.media?.startPolling(delay);
}

function syncVoiceStatus(force = false) {
  return voiceRuntime?.media?.syncStatus(force) ?? Promise.resolve();
}

function renderCurrentVoiceList() {
  voiceRuntime?.media?.renderCurrentVoiceList();
}

function setVoiceMuted(muted) {
  voiceRuntime?.media?.setMuted(muted);
}

function setVoiceDeafened(deafened) {
  voiceRuntime?.media?.setDeafened(deafened);
}

function setVoiceDeviceStatus(message, state = '') {
  if (!voiceDeviceStatus) return;
  voiceDeviceStatus.textContent = message || '';
  voiceDeviceStatus.classList.remove('ok', 'error', 'working');
  if (state) voiceDeviceStatus.classList.add(state);
}

function renderVoiceDeviceOptions(select, devices, defaultLabel, itemLabel, selectedId) {
  if (!select) return;
  const options = [new Option(defaultLabel, '')];
  (devices || []).forEach((device, index) => {
    options.push(new Option(device.label || `${itemLabel} ${index + 1}`, device.deviceId || ''));
  });
  select.replaceChildren(...options);
  select.value = options.some(option => option.value === selectedId) ? selectedId : '';
}

function renderVoiceDeviceSnapshot(snapshot) {
  if (!snapshot) return;
  renderVoiceDeviceOptions(voiceInputDevice, snapshot.inputs, 'Default microphone', 'Microphone', snapshot.selectedInputId);
  renderVoiceDeviceOptions(voiceOutputDevice, snapshot.outputs, 'Default speaker', 'Speaker', snapshot.selectedOutputId);
  if (voiceOutputDevice) voiceOutputDevice.disabled = !snapshot.sinkSelectionSupported;
  if (voiceDeviceRefresh) {
    voiceDeviceRefresh.textContent = ['prompt', 'denied', 'unknown'].includes(snapshot.permissionState)
      ? 'Allow microphone & refresh'
      : 'Refresh devices';
  }
  if (snapshot.refreshing) {
    setVoiceDeviceStatus('Loading audio devices...', 'working');
  } else if (snapshot.error) {
    setVoiceDeviceStatus(snapshot.error.message || 'Could not load audio devices. Default devices can still be used.', 'error');
  } else if (snapshot.permissionState === 'prompt') {
    setVoiceDeviceStatus('Microphone permission is required to list named devices.', 'working');
  } else if (!snapshot.sinkSelectionSupported) {
    setVoiceDeviceStatus('Speaker selection is not supported by this browser.', 'working');
  } else {
    setVoiceDeviceStatus('', '');
  }
}

async function populateVoiceDevices() {
  return voiceRuntime?.media?.populateDevices();
}

async function openVoiceDeviceModal() {
  if (!voiceDeviceModal) {
    await joinVoice();
    return;
  }
  voiceDeviceModal.classList.add('open');
  await populateVoiceDevices().catch(err => {
    console.warn(err);
    setVoiceDeviceStatus('Could not load audio devices. Default devices can still be used.', 'error');
  });
}

function closeVoiceDeviceModal() {
  voiceDeviceModal?.classList.remove('open');
}

async function applyAudioOutput(audio) {
  return voiceRuntime?.media?.applyAudioOutput(audio);
}

function mediaActive() {
  return Boolean(voiceRuntime?.media?.mediaActive());
}

function shouldPollMediaFast() {
  return Boolean(voiceRuntime?.media?.shouldPollFast());
}

async function connectMediaPeer(participantId) {
  return voiceRuntime?.media?.connectMediaPeer(participantId);
}

function connectMediaPeers(options = {}) {
  return voiceRuntime?.media?.connectMediaPeers(options);
}

function renegotiateMediaPeers(options = {}) {
  return voiceRuntime?.media?.renegotiateMediaPeers(options);
}

document.getElementById('voice-toggle').addEventListener('click', async () => {
  if (voiceRuntime?.media?.isJoined()) await leaveVoice();
  else await openVoiceDeviceModal();
});

privateVoiceOpen?.addEventListener('click', async () => {
  privateVoiceReturnFocus = document.activeElement;
  privateVoiceModal?.classList.add('open');
  window.requestAnimationFrame(() => document.getElementById('private-voice-close')?.focus({ preventScroll: true }));
  setPrivateVoiceStatus('Loading private voice...', 'working');
  try {
    await voiceRuntime?.privateVoice?.refresh();
    setPrivateVoiceStatus('', '');
  } catch (error) {
    setPrivateVoiceStatus(error?.message || 'Private voice could not be loaded.', 'error');
  }
});

function closePrivateVoiceModal() {
  privateVoiceModal?.classList.remove('open');
  const returnFocus = privateVoiceReturnFocus;
  privateVoiceReturnFocus = null;
  if (returnFocus?.isConnected) returnFocus.focus({ preventScroll: true });
}

document.getElementById('private-voice-close')?.addEventListener('click', closePrivateVoiceModal);

privateVoiceContent?.addEventListener('click', event => {
  const button = event.target.closest('[data-private-voice-action]');
  if (button) handlePrivateVoiceAction(button);
});

voiceDeviceForm?.addEventListener('submit', async e => {
  e.preventDefault();
  voiceRuntime?.media?.selectDevices({
    inputId: voiceInputDevice?.value || '',
    outputId: voiceOutputDevice?.value || '',
  });
  setVoiceDeviceStatus('Joining voice...', 'working');
  await joinVoice();
});

voiceInputDevice?.addEventListener('change', () => {
  voiceRuntime?.media?.selectDevices({
    inputId: voiceInputDevice.value,
    outputId: voiceOutputDevice?.value || '',
  });
});

voiceOutputDevice?.addEventListener('change', () => {
  voiceRuntime?.media?.selectDevices({
    inputId: voiceInputDevice?.value || '',
    outputId: voiceOutputDevice.value,
  });
});

bindModalCloseButtons(['voice-device-close', 'voice-device-cancel'], closeVoiceDeviceModal);

voiceDeviceRefresh?.addEventListener('click', async () => {
  setVoiceDeviceStatus('Requesting microphone permission and refreshing devices...', 'working');
  await voiceRuntime?.media?.requestDevicePermissionAndPopulate()?.catch(err => {
    console.warn(err);
    setVoiceDeviceStatus('Microphone permission was not granted. Default devices can still be used.', 'error');
  });
});

async function joinVoice() {
  return voiceRuntime?.media?.join();
}

async function leaveVoice() {
  return voiceRuntime?.media?.leave();
}

async function pollVoice() {
  return voiceRuntime?.media?.poll();
}

function voiceControlIcon(kind) {
  if (kind === 'mic') {
    return '<span class="voice-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3Z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path><path d="M12 19v3"></path><path d="M8 22h8"></path></svg></span>';
  }
  return '<span class="voice-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 13a8 8 0 0 1 16 0"></path><path d="M4 13v5a2 2 0 0 0 2 2h2v-7H4Z"></path><path d="M20 13v5a2 2 0 0 1-2 2h-2v-7h4Z"></path></svg></span>';
}

function renderVoiceList(list, state = voiceRuntime?.media?.getState() || {}) {
  const voiceParticipants = Array.isArray(list) ? list : [];
  lastVoiceParticipants = voiceParticipants;
  const mutedSelf = Boolean(state.muted);
  const deafenedSelf = Boolean(state.deafened);
  const speakingSelf = Boolean(state.speaking);
  if (voiceSideSection) voiceSideSection.classList.toggle('has-voice', voiceParticipants.length > 0);
  if (voiceTitleEl) voiceTitleEl.hidden = voiceParticipants.length === 0;
  if (voiceListEl) voiceListEl.hidden = voiceParticipants.length === 0;
  if (voiceCountLabel) voiceCountLabel.textContent = voiceParticipants.length ? `(${voiceParticipants.length})` : '';
  voiceListEl.innerHTML = '';
  voiceParticipants.forEach(v => {
    const known = participants.get(Number(v.id));
    const person = Object.assign({}, known || {}, v);
    if (known?.p2p_avatar_object_identity
        && String(known.p2p_avatar_object_identity) === String(v?.p2p_avatar?.identity || '')) {
      person.avatar_url = known.avatar_url;
      person.avatar_path = null;
      person.p2p_avatar_object_identity = known.p2p_avatar_object_identity;
    }
    if (known?.p2p_nameplate_object_identity
        && String(known.p2p_nameplate_object_identity) === String(v?.p2p_nameplate?.identity || '')) {
      person.nameplate_url = known.nameplate_url;
      person.nameplate_path = null;
      person.p2p_nameplate_object_identity = known.p2p_nameplate_object_identity;
    }
    if (known && v?.p2p_avatar) {
      participants.update(Number(v.id), {
        p2p_avatar: v.p2p_avatar,
        avatar_delivery: v.avatar_delivery,
      });
    }
    if (known && v?.p2p_nameplate) {
      participants.update(Number(v.id), {
        p2p_nameplate: v.p2p_nameplate,
        nameplate_delivery: v.nameplate_delivery,
      });
    }
    const own = Number(person.id) === Number(cfg.myParticipantId);
    const muted = own ? mutedSelf : Boolean(person.muted);
    const deafened = own ? deafenedSelf : Boolean(person.deafened);
    const speaking = own ? speakingSelf : Boolean(person.speaking);
    const row = document.createElement('div');
    row.className = `voice-card person-row ${participantRoleClass(person)}${speaking ? ' speaking' : ''}`;
    row.dataset.participantId = person.id;
    const statusText = speaking ? 'Speaking' : 'In voice';
    const controls = own
      ? `<button class="voice-control${muted ? ' active' : ''}" data-voice-mute type="button" title="${muted ? 'Unmute mic' : 'Mute mic'}" aria-label="${muted ? 'Unmute mic' : 'Mute mic'}">${voiceControlIcon('mic')}</button>
         <button class="voice-control${deafened ? ' active' : ''}" data-voice-deafen type="button" title="${deafened ? 'Undeafen' : 'Deafen'}" aria-label="${deafened ? 'Undeafen' : 'Deafen'}">${voiceControlIcon('headphones')}</button>`
      : `${muted ? `<span class="voice-status-icon active" title="Mic muted">${voiceControlIcon('mic')}</span>` : ''}
         ${deafened ? `<span class="voice-status-icon active" title="Deafened">${voiceControlIcon('headphones')}</span>` : ''}`;
    row.innerHTML = `<span class="user-avatar-wrap">${avatarPresentationHtml(person, { own, displayName: displayNameFor(person), title: false })}<span class="voice-speaking-dot${speaking ? ' speaking' : ''}"></span></span><div><strong class="person-name-line person-nameplate"><span>${esc(displayNameFor(person))}</span>${participantRoleBadgeHtml(person)}</strong><div class="minor">${own ? 'You' : statusText}</div></div><div class="voice-card-actions">${controls}</div>`;
    applyIdentityNameplate(row.querySelector('.person-nameplate'), person);
    row.querySelector('[data-voice-mute]')?.addEventListener('click', () => setVoiceMuted(!mutedSelf));
    row.querySelector('[data-voice-deafen]')?.addEventListener('click', () => setVoiceDeafened(!deafenedSelf));
    voiceListEl.appendChild(row);
  });
}

async function bootRoom() {
  await initializeAvatarRuntime();

  const roomId = document.body.dataset.roomId;
  const roomConfig = await runtimeRequestClient.getJson(`/api/room_config.php?id=${encodeURIComponent(roomId)}`, {
    operation: 'bootstrap-room',
    endpointCategory: 'room-config',
  });
  avatarRuntime?.visibility?.applyServerProjection(
    roomConfig.avatarVisibilityPreferences || {},
    'room-bootstrap'
  );
  applyNameplateVisibilityProjection(roomConfig.nameplateVisibilityPreferences || {});
  cfg = roomConfig;
  // Account-scoped audio preferences require the authenticated room config.
  // Optional audio must never prevent participants or room transport starting.
  try {
    configureChatNotifications();
  } catch (error) {
    chatRuntime?.notifications?.destroy();
    warnRuntimeRequest(error);
  }
  if (cfg.liveWebsiteRoom) {
    const { LiveWebsiteRoomRuntime } = await import(appUrl('/assets/js/runtime/live-website-room/live-website-room-runtime.js?v=20260913-youtube-sidebar'));
    liveWebsiteRoomRuntime = new LiveWebsiteRoomRuntime();
    liveWebsiteRoomRuntime.start({
      projection: cfg.liveWebsiteRoom,
      participant: { session_id: cfg.sessionId, join_token: cfg.myJoinToken },
      onMusicPlaylist(playlist) {
        if (JSON.stringify(cfg.musicPlaylist || []) === JSON.stringify(playlist)) return;
        cfg.musicPlaylist = playlist;
        importedRoomRuntime?.music?.renderPlayer(playlist);
      },
      appUrl,
      csrfToken: document.body.dataset.csrf || '',
      registerPollingJob: job => pollingRuntime.registerJob(job),
    });
  }
  avatarRuntime?.p2pAvatar?.applyPolicy(cfg.p2pAvatarPolicy || {});
  renderPrivateVoiceSnapshot({ policy: cfg.voiceWebcamPolicy });
  voiceRuntime?.transmissionModes?.render?.();
  applyGestureCapabilityProjection(cfg.gestureCapabilities || {}, 'room-bootstrap');
  gesturePresentation?.applyServerProjection(
    cfg.gesturePart3?.preferences || {},
    'room-bootstrap'
  );
  initializeGestureCatalog();
  voiceRuntime?.viewerPolicy?.applyServerProjection({
    capability: cfg.webcamCapability,
    preferences: cfg.webcamViewerPreferences,
  }, 'room-bootstrap');
  avatarRuntime?.displayPolicy?.configure(cfg.avatarSizePolicy || {});
  window.ChatSpaceAvatar?.configure?.(avatarRuntime?.displayPolicy?.policy?.() || cfg.avatarSizePolicy || {});
  avatarRuntime?.dances?.configureCapabilityPolicy?.(cfg.danceCapability || {}, {
    reason: 'room-bootstrap',
  });
  cfg.innerTranquillityPlayer = innerTranquillityPlayerCapability();
  importedRoomRuntime?.layout?.render(cfg.importLayout);
  importedRoomRuntime?.music?.renderPlayer(cfg.musicPlaylist);
  chatPoll().seed({
    lastEventId: cfg.lastEventId,
    lastCommunityEventId: cfg.lastCommunityEventId,
  });
  restoreSessionLock();
  (cfg.blockedUserIds || []).forEach(id => blockedUserIds.add(Number(id)));
  replacePersonalMuteProjection(cfg.mutedUsers || []);
  avatarRuntime?.relationships?.seedPersistedRelationships(cfg.relationships || []);
  await avatarRuntime?.relationshipManagement?.refresh({ render: false });
  chatPrivateChats().syncRelationshipChat(
    avatarRuntime?.relationships?.relationshipForParticipant(cfg.myParticipantId) || null,
    cfg.relationshipChat || null
  );
  avatarRuntime?.coordinator?.seedLinkIcons(cfg.linkIcons || {});
  await Promise.all((cfg.participants || []).map(p => renderParticipantWhenReady(p, { animateJoin: true }).catch(() => {
    renderParticipant(p, { animateJoin: true });
  })));
  avatarRuntime?.coordinator?.rebuildLinkGroups();
  avatarRuntime?.coordinator?.scheduleRelationshipRefresh({
    all: true,
    reason: 'room-bootstrap',
  });
  (cfg.dmUsers || []).forEach(rememberDmUser);
  (cfg.messages || []).forEach(msg => addMessageToChannel(msg, 'room', false));
  (cfg.communityMessages || []).forEach(msg => addMessageToChannel(msg, 'community', false));
  (cfg.linkMessages || []).forEach(msg => {
    const chatKey = chatPrivateChats().relationshipChatKeyFromPayload(msg);
    if (chatKey) addMessageToChannel(msg, chatKey, false);
  });
  (cfg.dmMessages || []).forEach(msg => {
    if (msg.partner_user_id) addMessageToChannel(msg, `dm:${msg.partner_user_id}`, false);
  });
  renderLinkTabs();
  renderActiveChat();
  setPermissionUI();
  renderRoomEffectsModal();
  if (cfg.activeRoomEffect?.active) {
    await roomEffectsRuntime?.effects?.apply(cfg.activeRoomEffect, false);
    addSystemMessage(`${cfg.activeRoomEffect.label || 'Room effect'} is currently active.`);
  }
  updateComposerState();
  updateVoiceToggleButton(); 
  await gameRuntime?.lifecycle?.loadGames({ includeCatalog: true });
  if (cfg.p2pAvatarPolicy?.effectiveEnabled === true || cfg.p2pTransferPolicy?.effectiveEnabled === true) {
    voiceRuntime?.media?.startPolling(0);
  }
  p2pTransferService?.start();
  voiceRuntime?.privateVoice?.startPolling(0);
  poll();
  pollVoice();
  pollAppVersion();
  pollingRuntime.registerJob({
    id: 'latency-monitor',
    run: checkLatency,
    interval: 5000,
    initialDelay: 10000,
  });
  pollingRuntime.registerJob({
    id: 'app-version-poll',
    run: pollAppVersion,
    interval: 60000,
  });
  pollingRuntime.registerJob({
    id: 'visible-timestamp-update',
    run: updateVisibleTimestamps,
    interval: 30000,
  });
  refreshPresence();
  pollingRuntime.registerJob({
    id: 'presence-refresh',
    run: refreshPresence,
    interval: 5000,
  });
}

function updateRoomLayout() {
  participants.forEach(positionAvatar);
}

function runFrameSync() {
  frameQueued = false;

  if (!pendingLayout) return;

  pendingLayout = false;
  updateRoomLayout();
  avatarRuntime?.coordinator?.scheduleRelationshipRefresh({
    all: true,
    reason: 'frame-sync',
  });
}

window.addEventListener('resize', () => {

layoutLocked = true;

requestAnimationFrame(() => {
    updateRoomLayout?.();
    pendingLayout = true;

    if (!frameQueued) {
        frameQueued = true;
        requestAnimationFrame(runFrameSync);
    }

    avatarRuntime?.coordinator?.scheduleRelationshipRefresh({
        all: true,
        reason: 'browser-resize',
    });

    layoutLocked = false;
});
});
initRoomBackgroundVideos(document);
bootRoom().catch(err => {
  console.error(err);
  messagesEl.innerHTML = `<div class="error">${esc(err.message || 'Room failed to load.')}</div>`;
});
async function postOutsideContentForm(...args) {
  const { postOutsideContentForm: uploadWithConsent } = await import('./core/outside-content-upload.js?v=20260913-consent-r2');
  return uploadWithConsent(...args);
}
