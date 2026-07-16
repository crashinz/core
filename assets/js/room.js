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
let avatarRuntime = null;
let pollingRuntime = null;
let runtimeDiagnosticsInstallation = null;
let runtimeDiagnostics = null;
let runtimeVerificationControls = null;
let runtimeRequestClient = null;
const runtimeRequestAbortController = new AbortController();
window.addEventListener('pagehide', () => {
  runtimeRequestAbortController.abort('page-hide');
  avatarRuntime?.coordinator?.cancelPendingLinkChoice('page-hide');
}, { once: true });
let frameQueued = false;
let pendingLayout = false;
let layoutLocked = false;
const roomLayout = document.querySelector('.room-layout');
const mainEl = document.querySelector('.main');
const roomStage = document.getElementById('room-stage');
const vpRoomLayout = document.getElementById('vp-room-layout');
const vpMusicPlayer = document.getElementById('vp-music-player');
const vpMusicSelect = document.getElementById('vp-music-select');
const vpMusicAudio = document.getElementById('vp-music-audio');
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
const gameListEl = document.getElementById('active-games');
const gameStartMenu = document.getElementById('game-start-menu');
const voiceSideSection = document.getElementById('voice-side-section');
const voiceTitleEl = document.getElementById('voice-title');
const voiceListEl = document.getElementById('voice-list');
const voiceCountLabel = document.getElementById('voice-count-label');
const ctxMenu = document.getElementById('ctx-menu');
const textCtxMenu = document.getElementById('text-ctx-menu');
const msgActionMenu = document.getElementById('msg-action-menu');
const tabCtxMenu = document.getElementById('tab-ctx-menu');
const roomMenu = document.getElementById('room-menu');
const roomActionMenu = document.getElementById('room-action-menu');
const gameStage = document.getElementById('game-stage');
const gameFrame = document.getElementById('game-frame');
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
const ctxToggleWebcam = document.getElementById('ctx-toggle-webcam');
const ctxAuras = document.getElementById('ctx-auras');
const ctxOrientationWrap = document.getElementById('ctx-orientation-wrap');
const ctxOrientation = document.getElementById('ctx-orientation');
const ctxOrientationSubmenu = document.getElementById('ctx-orientation-submenu');
const avatarSizeModal = document.getElementById('avatar-size-modal');
const avatarSizeForm = document.getElementById('avatar-size-form');
const avatarSizeTitle = document.getElementById('avatar-size-title');
const avatarSizeCap = document.getElementById('avatar-size-cap');
const avatarSizeAvatarFields = document.getElementById('avatar-size-avatar-fields');
const avatarSizeWebcamFields = document.getElementById('avatar-size-webcam-fields');
const avatarSizeEdge = document.getElementById('avatar-size-edge');
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
let bootstrapped = false;
let textMenuMode = 'copy';
let lastLatencyMs = null;
let ctxMenuParticipantId = null;
let avatarOrientationPending = false;
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
const pendingRemoteVideoStreams = new Map();
const AVATAR_STAGE_SIZE = 150;
const blockedUserIds = new Set();
let voiceNoteRecorder = null;
let voiceNoteChunks = [];
let voiceNoteStream = null;
let voiceNoteCancelled = false;
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

async function initializeAvatarRuntime() {
  if (avatarRuntime) return avatarRuntime;

  const [{ Core }, { ChatRuntime }, { RoomRuntime }, { VoiceRuntime }, { GameRuntime }, { RoomEffectsRuntime }, { ImportedRoomRuntime }, { AvatarRuntime }, { PollingRuntime }, { installRuntimeDiagnostics }, { RuntimeRequestClient }] = await Promise.all([
    import(appUrl('/assets/js/core/core.js')),
    import(appUrl('/assets/js/runtime/chat/chat-runtime.js')),
    import(appUrl('/assets/js/runtime/room/room-runtime.js')),
    import(appUrl('/assets/js/runtime/voice/voice-runtime.js')),
    import(appUrl('/assets/js/runtime/game/game-runtime.js')),
    import(appUrl('/assets/js/runtime/room-effects/room-effects-runtime.js')),
    import(appUrl('/assets/js/runtime/imported-room/imported-room-runtime.js')),
    import(appUrl('/assets/js/runtime/avatar/avatar-runtime.js')),
    import(appUrl('/assets/js/runtime/polling/polling-runtime.js')),
    import(appUrl('/assets/js/core/runtime-diagnostics.js')),
    import(appUrl('/assets/js/core/runtime-request-client.js')),
  ]);

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

  runtimeRequestClient = new RuntimeRequestClient({
    resolveUrl: appUrl,
    csrfToken: CSRF_TOKEN,
    lifecycleSignal: runtimeRequestAbortController.signal,
    onFailure(error) {
      recordRuntimeDiagnostic('requests', 'runtime-request-failure', {
        code: error.code,
        message: error.message,
        ...error.details,
      });
    },
  });

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

  return avatarRuntime;
}

function configureAvatarCoordinator() {
  avatarRuntime?.coordinator?.configure({
    getConfig: () => cfg,
    stageSize() {
      return {
        width: roomStage.clientWidth,
        height: roomStage.clientHeight,
      };
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
      }).catch(warnRuntimeRequest);
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
    stageElement: () => roomStage,
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
    messageAvatarUrl,
    participantRoleClass,
    participantRoleLabel,
    displayNameFor,
    messageVisible,
    gestureFromMessage,
    openMessageActionMenu,
    applyReaction,
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
        stage: roomStage,
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
    addMessageToChannel,
    renderMessage,
    showDmFlight,
    stopTypingNow,
    alertError(error) {
      alert(error.message || error);
    },
  });
}

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
    fetchGameChat(query) {
      return runtimeRequestClient.getJson('/api/game_chat.php?' + query, {
        operation: 'poll-game-chat',
        endpointCategory: 'game-chat',
      });
    },
    warnError(error) {
      warnRuntimeRequest(error);
    },
  });
}

function configureRoomEventRouter() {
  roomRuntime?.events?.configure({
    onParticipantJoin(payload) {
      const alreadyKnown = participants.has(payload.id);
      const hadStageAvatar = Boolean(participants.get(payload.id)?.avatarEl);
      renderParticipantWhenReady(Object.assign({ online: true }, payload), { animateJoin: !hadStageAvatar }).catch(() => {
        renderParticipant(Object.assign({ online: true }, payload), { animateJoin: !hadStageAvatar });
      });
      if (!alreadyKnown && payload.id !== cfg.myParticipantId) addSystemMessage(`${payload.display_name} joined the room.`);
    },
    onParticipantLeave(payload) {
      const leavingId = payload.participant_id || payload.id;
      const person = participants.get(Number(leavingId));
      if (person && person.id !== cfg.myParticipantId) addSystemMessage(`${person.display_name} left the room.`);
      removeParticipant(leavingId);
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

      const nextAvatarPath = payload.avatar_path ?? person.avatar_path;
      const nextAvatarUrl = payload.avatar_url ?? person.avatar_url;
      const avatarSourceChanged = nextAvatarPath !== person.avatar_path
        || nextAvatarUrl !== person.avatar_url;
      const previousDimensions = avatarRenderedDimensions(person);
      const currentSizeVersion = Number(person.avatar_size_version || 1);
      const incomingSizeVersion = Number(payload.avatar_size_version || currentSizeVersion);
      const staleSizeProjection = incomingSizeVersion < currentSizeVersion;
      const nextSizeProjection = staleSizeProjection ? {} : {
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
        || nextSizeProjection.webcam_display_width_px !== person.webcam_display_width_px
        || nextSizeProjection.webcam_display_height_px !== person.webcam_display_height_px
      );

      participants.update(payload.participant_id, {
        avatar_path: nextAvatarPath,
        avatar_url: nextAvatarUrl,
        avatar_orientation: payload.avatar_orientation === undefined
          ? normalizeAvatarOrientation(person.avatar_orientation)
          : normalizeAvatarOrientation(payload.avatar_orientation),
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
      avatarRuntime?.coordinator?.clearParticipantRelationship(person.id);
      removeParticipant(person.id);
      if (person.id !== cfg.myParticipantId) addSystemMessage(`${person.display_name} left the room.`);
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
    getWebcamLifecycleState: () => ({
      intent: webcamIntent,
      acquisitionState: webcamAcquisitionState,
      operationGeneration: webcamOperationGeneration,
      trackId: webcamStream?.getVideoTracks?.()[0]?.id || null,
    }),
    updateToggleButton: updateVoiceToggleButton,
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
    apiPost,
    appUrl,
    mediaUrl,
    esc,
    getConfig: () => cfg,
    getCsrfToken: () => CSRF_TOKEN,
    activeChatKey,
    gameChatKey,
    switchChat,
    startGameChatPolling,
    stopGameChatPolling,
    stopGameTypingNow,
    renderPeople,
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
    getGameListElement() {
      return gameListEl;
    },
    getGameStageElement() {
      return gameStage;
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
    getPlayerOneElement() {
      return document.getElementById('game-player-one');
    },
    getPlayerTwoElement() {
      return document.getElementById('game-player-two');
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
      return roomStage;
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
      return roomStage;
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
    pollInterval: 25,
    fetchPoll(query) {
      return runtimeRequestClient.getJson('/api/poll.php?' + query, {
        operation: 'poll-room-events',
        endpointCategory: 'room-poll',
      });
    },
    handleRoomEvent(event) {
      roomRuntime?.events?.routeRoomEvent(event);
    },
    handleCommunityEvent(event) {
      roomRuntime?.events?.routeCommunityEvent(event);
    },
    warnError(error) {
      warnRuntimeRequest(error);
    },
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
  if (isUserBlocked(p.user_id)) return appUrl('/assets/images/baghead.png');
  if (p.webcam_path) return `${mediaUrl(p.webcam_path)}?v=${Date.now()}`;
  if (p.avatar_url && !p.avatar_url.startsWith('data:')) {
    const url = mediaUrl(p.avatar_url);
    return `${url}${url.includes('?') ? '&' : '?'}v=${p.avatar_version || 0}`;
  }
  if (p.avatar_url) return p.avatar_url;
  if (p.avatar_path?.startsWith('preset:')) return cfg.avatarPresets[p.avatar_path.slice(7)] || cfg.avatarPresets.Default;
  return mediaUrl(p.avatar_path || cfg.avatarPresets.Default);
}

function isWebcamAssetUrl(url) {
  return String(url || '').includes('/assets/uploads/webcam/');
}

function addAvatarContextListeners(el) {
  if (!el) return;
  el.addEventListener('contextmenu', e => {
    e.preventDefault();
    e.stopPropagation();
    const current = participants.get(Number(el.dataset.participantId));
    if (!current) return;
    openAvatarContextMenu(e.clientX, e.clientY, current);
  });
}

function setAvatarImageSource(img, nextSrc, flip = false) {
  avatarRuntime?.renderer?.setAvatarImageSource(img, nextSrc, {
    flip,
    window,
  });
}

function attachParticipantVideo(participantId, stream, own = false, presentationIdentity = {}) {
  const person = participants.get(Number(participantId));
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
  pendingRemoteVideoStreams.delete(Number(participantId));
  avatarRuntime?.renderer?.attachWebcam(person, stream, {
    stage: roomStage,
    document,
    own,
    source: presentationIdentity.source || (own ? 'local-capture' : 'unknown'),
    presentationIdentity,
    onWebcamPresentationDiagnostic: recordVoiceLifecycleDiagnostic,
    onWebcamPresentationError(error, detail) {
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

function detachParticipantVideo(participantId, flip = true, reason = 'explicit-detach') {
  const person = participants.get(Number(participantId));
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
  if (isUserBlocked(msg?.user_id)) return appUrl('/assets/images/baghead.png');
  if (msg?.avatar_url) return mediaUrl(msg.avatar_url);
  if (msg?.avatar_path?.startsWith('preset:')) return cfg.avatarPresets[msg.avatar_path.slice(7)] || cfg.avatarPresets.Default;
  if (msg?.avatar_path) return mediaUrl(msg.avatar_path);
  return cfg.avatarPresets.Default;
}

function isUserBlocked(userId) {
  return blockedUserIds.has(Number(userId));
}

function displayNameFor(p) {
  return isUserBlocked(p?.user_id) ? 'Blocked' : (p?.display_name || 'Someone');
}

function participantRoleKey(p) {
  const role = String(p?.role || 'user').replace(/[^a-z]/g, '') || 'user';
  if (['admin', 'developer', 'guide'].includes(role)) return role;
  if (p?.is_owner) return 'owner';
  return role;
}

function participantRoleLabel(p) {
  const key = participantRoleKey(p);
  if (key === 'owner') return 'Room Owner';
  if (key === 'developer') return 'Developer';
  if (key === 'admin') return 'Admin';
  if (key === 'guide') return 'Guide';
  return 'User';
}

function participantRoleClass(p) {
  return `role-${participantRoleKey(p)}`;
}

function setPermissionUI() {
  const actionBtn = document.getElementById('room-action-btn');
  if (actionBtn) actionBtn.hidden = !(cfg?.canEditRoom || cfg?.canUseHostTools);
  const editAction = document.getElementById('room-action-edit');
  if (editAction) editAction.hidden = !cfg?.canEditRoom;
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

function avatarRenderedDimensions(person) {
  return avatarRuntime?.renderer?.renderedAvatarDimensions(person, {
    fallbackSize: AVATAR_STAGE_SIZE,
    lapInitiator: isLapLinkInitiator(person),
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
}

function preloadImage(src) {
  return new Promise(resolve => {
    if (!src) {
      resolve(false);
      return;
    }
    const img = new Image();
    img.onload = () => resolve(true);
    img.onerror = () => resolve(false);
    img.src = src;
    if (img.complete) resolve(true);
  });
}

function runAvatarPixelEffect(person, mode = 'in') {
  return avatarRuntime?.effects?.runPixelEffect(person, {
    mode,
    stage: roomStage,
    document,
    window,
  }) || Promise.resolve();
}

async function renderParticipantWhenReady(p, options = {}) {
  const prepared = Object.assign({}, p);
  await preloadImage(avatarUrl(prepared));
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
    stage: roomStage,
    document,
    window,
    own: Number(p.id) === Number(cfg.myParticipantId),
    makeDraggable,
    addContextListeners: addAvatarContextListeners,
    avatarSource: avatarUrl(merged),
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
      avatarRuntime?.coordinator?.scheduleRelationshipRefresh({
        participant,
        reason: detail.reason || 'rendered-size-change',
      });
    },
  });
  refreshLinkClasses();
  positionAvatar(merged);
  applyParticipantAura(merged);
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
  const rect = menu.getBoundingClientRect();
  const left = Math.max(8, Math.min(x, window.innerWidth - rect.width - 8));
  const top = Math.max(8, Math.min(y, window.innerHeight - rect.height - 8));
  menu.style.left = `${left}px`;
  menu.style.top = `${top}px`;
}

function positionAvatar(p) {
  const img = p.avatarEl;
  const label = p.labelEl;
  if (!img || !label) return;
  
  const w = roomStage.clientWidth;
  const h = roomStage.clientHeight;
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
    stage: roomStage,
  });
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
    stage: roomStage,
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
  roomStage.appendChild(img);

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
  userListEl.innerHTML = '';

  if (participants && participants.size > 0) {
    rebuildLinkGroups();
  }

  if (!participants || participants.size === 0) {
    return;
  }

  const people = avatarRuntime?.order?.visibleParticipants(participants.values()) || [...participants.values()]
    .filter(p => p && typeof p === 'object' && p.id)
    .sort((a, b) =>
      (a.display_name || '').localeCompare(b.display_name || '')
    );
  document.getElementById('participant-count-label').textContent = `(${people.length})`;
  const rendered = new Set();
  const roleClass = participantRoleClass;
  const makePersonBits = p => {
    const game = gameRuntime?.lifecycle?.gameForParticipant(p.id);
    const gameBadge = game ? `<span class="user-game-badge" title="${esc(gameName(game.game_type))}"><img src="${esc(gameIconUrl(game.game_type))}" alt=""></span>` : '';
    const nameIcon = game ? `<img class="person-game-name-icon" src="${esc(gameIconUrl(game.game_type))}" alt="" title="${esc(gameName(game.game_type))}">` : '';
    return `<span class="user-avatar-wrap"><img src="${esc(avatarUrl(p) || '')}" alt=""><span class="status-dot ${p.online ? 'on' : ''}"></span>${gameBadge}</span><div><strong class="person-name-line">${nameIcon}<span>${esc(displayNameFor(p) || '')}</span></strong><div class="minor">${p.id === cfg.myParticipantId ? 'You' : (p.online ? 'Online' : 'Away')}</div></div>`;
  };
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

  const row = document.createElement('div');
  row.className = 'person-row linked-row';

  row.innerHTML = orderedGroup.map(member => `
    <div class="linked-half"
         data-participant-id="${member.id}"
         style="touch-action:none; cursor:grab;">
      ${makePersonBits(member)}
    </div>
  `).join('');

  orderedGroup.forEach(member => rendered.add(member.id));
  userListEl.appendChild(row);
  return;
}
    rendered.add(p.id);
    const row = document.createElement('div');
    row.className = `person-row ${roleClass(p)}`;
    row.dataset.participantId = p.id;
    row.innerHTML = makePersonBits(p);
    row.addEventListener('click', () => pulseParticipantAvatar(p.id));
    row.addEventListener('contextmenu', e => {
      e.preventDefault();
      e.stopPropagation();
      openAvatarContextMenu(e.clientX, e.clientY, p);
    });
    userListEl.appendChild(row);
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
  chatNavigation().switchChat(chatKey);
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
  if (!messageVisible(msg)) return;
  const activeChat = activeChatKey();
  const result = chatMessageState().addMessageToChannel(msg, chatKey);
  const existing = result.existing;
  const storedMessage = result.message || msg;
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
  } else {
    chatUnread().recordInactiveLiveMessage(msg, chatKey, { live });
  }
  if (live && chatKey === 'room' && msg.participant_id) {
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
  if (msg.message_type === 'gesture') return gestureFromMessage(msg)?.text || msg.original_name || 'sent a gesture';
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
    el.textContent = `${el.dataset.prefix || ''}${fullTimestamp(el.dataset.ts)}`;
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
  avatarRuntime?.aura?.applyParticipantAura(person).catch(console.warn);
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

  const loopToStart = () => {
    if (!state.ready || state.seeking || state.destroyed || !video.isConnected) return;
    state.seeking = true;
    video.currentTime = state.start;
    video.play?.().catch(error => {
      if (error?.name === 'AbortError' && (state.destroyed || !video.isConnected)) return;
      console.error('Room background video playback failed.', error);
    });
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
      video.play?.().catch(error => {
        if (error?.name === 'AbortError' && (state.destroyed || !video.isConnected)) return;
        console.error('Room background video playback failed.', error);
      });
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
  roomStage.appendChild(next);
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
    document.title = `${update.room_name} - ChatSpace CE`;
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

function updateComposerState() {
  const input = document.getElementById('chat-input');
  const counter = document.getElementById('char-counter');
  if (!input || !counter) return;
  input.style.height = 'auto';
  input.style.height = `${Math.min(input.scrollHeight, 132)}px`;
  const count = input.value.length;
  const ratio = Math.min(1, count / 1000);
  counter.textContent = `${count}/1000`;
  const heat = Math.max(0, (ratio - 0.72) / 0.28);
  counter.style.color = heat <= 0 ? '#ffffff' : `hsl(${Math.round(8 - (8 * Math.min(1, heat)))} 86% ${Math.round(72 - (22 * Math.min(1, heat)))}%)`;
}

document.getElementById('composer').addEventListener('submit', e => {
  e.preventDefault();
  const input = document.getElementById('chat-input');
  const content = input.value.trim();
  if (!content) return;
  input.value = '';
  updateComposerState();
  const activeChat = activeChatKey();
  if (activeChat.startsWith('game:')) {
    stopGameTypingNow();
    sendGameMessage(content).catch(err => alert(err.message || err));
    return;
  }
  chatComposer().sendTextMessage(content, activeChat);
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
  latencyMonitorEl.classList.add(rounded < 180 ? 'latency-good' : (rounded < 500 ? 'latency-warn' : 'latency-bad'));
}

async function checkLatency() {
  if (!latencyMonitorEl || !cfg || roomExitInProgress) return;
  try {
    const qs = new URLSearchParams({
      session_id: cfg.sessionId,
      join_token: cfg.myJoinToken,
      t: String(Date.now()),
    });
    const startedAt = performance.now();
    qs.set('mode', 'latency');
    await runtimeRequestClient.getJson('/api/heartbeat.php?' + qs, {
      operation: 'measure-room-latency',
      endpointCategory: 'heartbeat',
      cache: 'no-store',
    });
    const elapsed = performance.now() - startedAt;
    lastLatencyMs = lastLatencyMs === null ? elapsed : (lastLatencyMs * .65) + (elapsed * .35);
    renderLatency(lastLatencyMs);
  } catch (err) {
    warnRuntimeRequest(err);
    renderLatency(Number.POSITIVE_INFINITY);
  }
}

function poll() {
  chatPoll().start();
}

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
  avatarRuntime?.renderer?.clearSpeechBubble(p, {
    window,
  });
}

function showAvatarSpeech(participantId, msg) {
  const p = participants.get(participantId);
  if (!p) return;
  if (isUserBlocked(p.user_id)) return;
  const isGif = msg?.message_type === 'gif';
  const isGesture = msg?.message_type === 'gesture';
  const text = (isGif || isGesture) ? '' : messageSpeechText(msg || {});
  const gesture = gestureFromMessage(msg);
  const token = participants.nextSpeechToken(participantId);
  avatarRuntime?.renderer?.ensureSpeechBubble(p, {
    stage: roomStage,
    document,
  });
  participants.clearSpeechTimer(participantId);
  avatarRuntime?.renderer?.prepareSpeechBubble(p, {
    gif: isGif || isGesture,
    gesture: isGesture,
  });
  let timerStarted = false;
  const scheduleHide = () => {
    if (timerStarted || !participants.hasSpeechToken(participantId, token)) return;
    timerStarted = true;
    if (isGesture && gesture && gesture.audio_path && !gesture.audio_is_silent) {
      const audio = new Audio(mediaUrl(gesture.audio_path));
      gifLoopDurationMs(gesture.gif_path).then(duration => {
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
      gifLoopDurationMs(isGesture ? gesture?.gif_path : msg.content).then(duration => {
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
  if (isGif || isGesture) {
    const img = avatarRuntime?.renderer?.renderSpeechImage(p, {
      document,
      src: mediaUrl(isGesture ? gesture?.gif_path : msg.content),
      alt: isGesture ? (gesture?.text || gesture?.name || 'Gesture') : (msg.original_name || 'GIF'),
      gesture: isGesture,
      caption: gesture?.text || gesture?.name || msg.original_name || '',
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

document.getElementById('chat-input').addEventListener('input', () => {
  updateComposerState();
  const activeChat = activeChatKey();
  if (activeChat.startsWith('game:')) {
    handleGameTypingInput();
    return;
  }
  chatTyping().handleComposerInput(activeChat);
});

document.getElementById('chat-input').addEventListener('keydown', e => {
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

document.getElementById('chat-input').addEventListener('paste', e => {
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
document.getElementById('logout-link')?.addEventListener('click', async e => {
  e.preventDefault();
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
        else if (existing.avatarEl) removeParticipant(existing.id, { keepRecord: true });
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
    ctxOrientation.disabled = avatarOrientationPending;
    ctxOrientation.setAttribute('aria-expanded', ctxOrientationWrap?.classList.contains('open') ? 'true' : 'false');
  }
  ctxOrientationSubmenu?.querySelectorAll('[data-avatar-orientation]').forEach(button => {
    const value = normalizeAvatarOrientation(button.dataset.avatarOrientation);
    const selected = value === orientation;
    button.textContent = `${button.dataset.label || AVATAR_ORIENTATION_LABELS[value]}${selected ? ' (Current)' : ''}`;
    button.setAttribute('aria-checked', selected ? 'true' : 'false');
    button.disabled = avatarOrientationPending;
  });
}

async function setAvatarOrientation(requestedOrientation) {
  if (avatarOrientationPending) return;
  const me = participants.get(cfg.myParticipantId);
  if (!me) return;
  const expectedOrientation = normalizeAvatarOrientation(me.avatar_orientation);
  const nextOrientation = normalizeAvatarOrientation(requestedOrientation);
  if (nextOrientation !== requestedOrientation) {
    showWarning('That avatar orientation is not available.');
    return;
  }
  if (nextOrientation === expectedOrientation) {
    closeContextMenu();
    return;
  }

  avatarOrientationPending = true;
  participants.update(me.id, { avatar_orientation: nextOrientation });
  renderParticipant(me);
  syncAvatarOrientationControls(me);
  const formData = new FormData();
  formData.append('action', 'set_orientation');
  formData.append('session_id', cfg.sessionId);
  formData.append('join_token', cfg.myJoinToken);
  formData.append('expected_orientation', expectedOrientation);
  formData.append('avatar_orientation', nextOrientation);
  try {
    const response = await runtimeRequestClient.postForm('/api/avatar.php', formData, {
      operation: 'set-avatar-orientation',
      endpointCategory: 'avatar',
    });
    const acceptedOrientation = normalizeAvatarOrientation(response.avatar_orientation);
    participants.update(me.id, { avatar_orientation: acceptedOrientation });
    renderParticipant(me);
    recordRuntimeDiagnostic('avatarOrientation', 'avatar-orientation-updated', {
      participantId: Number(me.id),
      previousOrientation: expectedOrientation,
      orientation: acceptedOrientation,
      idempotent: Boolean(response.idempotent),
    });
    closeContextMenu();
  } catch (error) {
    participants.update(me.id, { avatar_orientation: expectedOrientation });
    renderParticipant(me);
    recordRuntimeDiagnostic('avatarOrientation', 'avatar-orientation-update-failed', {
      participantId: Number(me.id),
      attemptedOrientation: nextOrientation,
      code: error?.code || null,
      status: error?.details?.status || null,
    });
    showWarning(error?.message || 'Could not update avatar orientation.');
  } finally {
    avatarOrientationPending = false;
    syncAvatarOrientationControls(participants.get(cfg.myParticipantId));
  }
}

function participantSizeFields(preferences = {}) {
  return {
    avatar_display_size_px: preferences.avatarDisplayPreferencePx ?? null,
    webcam_display_width_px: preferences.webcamDisplayWidthPreferencePx ?? null,
    webcam_display_height_px: preferences.webcamDisplayHeightPreferencePx ?? null,
    avatar_size_version: Number(preferences.displayPreferenceVersion || 1),
  };
}

function applyLocalDisplayPreferences(preferences, reason = 'local-display-size-save') {
  const me = participants.get(cfg.myParticipantId);
  if (!me || !preferences) return false;
  const currentVersion = Number(me.avatar_size_version || 1);
  const next = participantSizeFields(preferences);
  if (next.avatar_size_version < currentVersion) return false;
  const changed = next.avatar_size_version !== currentVersion
    || next.avatar_display_size_px !== me.avatar_display_size_px
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
  const exact = ['100x100', '150x150', '200x200'].find(value => {
    const [presetWidth, presetHeight] = value.split('x').map(Number);
    return width === presetWidth && height === presetHeight;
  });
  avatarSizeWebcamPreset.value = avatarSizeResetRequested ? 'default' : (exact || 'custom');
}

function openAvatarSizeModal(mode, options = {}) {
  const me = participants.get(cfg.myParticipantId);
  if (!me || !avatarSizeModal) return;
  const policy = avatarRuntime?.displayPolicy?.policy?.() || cfg.avatarSizePolicy || {};
  avatarSizeModalMode = mode === 'webcam' ? 'webcam' : 'avatar';
  avatarSizeStartWebcam = Boolean(options.startWebcam);
  avatarSizeResetRequested = avatarSizeModalMode === 'avatar'
    ? me.avatar_display_size_px == null
    : me.webcam_display_width_px == null && me.webcam_display_height_px == null;
  avatarSizeAvatarFields.hidden = avatarSizeModalMode !== 'avatar';
  avatarSizeWebcamFields.hidden = avatarSizeModalMode !== 'webcam';
  avatarSizeTitle.textContent = avatarSizeModalMode === 'avatar'
    ? 'Avatar Display Size'
    : (avatarSizeStartWebcam ? 'Webcam Display Size Before Starting' : 'Webcam Display Size');

  if (avatarSizeModalMode === 'avatar') {
    const cap = Number(policy.avatarDisplayMaxPx || 200);
    avatarSizeCap.textContent = `Community maximum: ${cap}px`;
    avatarSizeEdge.max = String(cap);
    avatarSizeEdge.value = String(avatarRuntime.displayPolicy.effectiveAvatarMaxEdge(me));
  } else {
    const maxWidth = Number(policy.webcamDisplayMaxWidthPx || 200);
    const maxHeight = Number(policy.webcamDisplayMaxHeightPx || 200);
    const box = avatarRuntime.displayPolicy.effectiveWebcamBox(me);
    avatarSizeCap.textContent = `Community maximum: ${maxWidth} x ${maxHeight}px`;
    avatarSizeWebcamWidth.max = String(maxWidth);
    avatarSizeWebcamHeight.max = String(maxHeight);
    avatarSizeWebcamWidth.value = String(box.width);
    avatarSizeWebcamHeight.value = String(box.height);
    avatarSizeAspectRatio = box.width / Math.max(box.height, 1);
    avatarSizeAspectLock.checked = true;
    setWebcamPresetFromInputs();

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

  closeContextMenu();
  setAvatarSizeStatus();
  avatarSizeModal.classList.add('open');
  avatarSizeModal.setAttribute('aria-hidden', 'false');
  requestAnimationFrame(() => (
    avatarSizeModalMode === 'avatar' ? avatarSizeEdge : avatarSizeWebcamPreset
  )?.focus());
}

async function saveAvatarSizePreferences() {
  if (avatarSizePending) return;
  const me = participants.get(cfg.myParticipantId);
  if (!me) return;
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
  } else {
    formData.append('webcam_display_width_px', avatarSizeResetRequested ? '' : avatarSizeWebcamWidth.value);
    formData.append('webcam_display_height_px', avatarSizeResetRequested ? '' : avatarSizeWebcamHeight.value);
  }
  try {
    const response = await runtimeRequestClient.postForm('/api/avatar.php', formData, {
      operation: 'set-avatar-display-preferences',
      endpointCategory: 'avatar',
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

function openAvatarContextMenu(x, y, participant) {
  closeTextContextMenu();
  closeRoomMenu();
  closeMediaPicker();
  ctxMenuParticipantId = participant.id;
  const isOwn = participant.id === cfg.myParticipantId;
  const isLinked = avatarRuntime?.relationships?.isLinked(participant) || false;
  const relationship = avatarRuntime?.relationships?.relationshipForParticipant(participant.id) || null;
  const isBlocked = isUserBlocked(participant.user_id);
  const showHostTools = Boolean(cfg.canUseHostTools && !isOwn);
  document.getElementById('ctx-change-avatar').style.display = isOwn ? 'block' : 'none';
  document.getElementById('ctx-avatar-size').style.display = isOwn ? 'block' : 'none';
  if (ctxOrientationWrap) ctxOrientationWrap.style.display = isOwn ? 'block' : 'none';
  if (ctxAuras) ctxAuras.style.display = isOwn ? 'block' : 'none';
  ctxToggleWebcam.style.display = isOwn ? 'block' : 'none';
  document.getElementById('ctx-webcam-size').style.display = isOwn && Boolean(webcamIntent || webcamStream) ? 'block' : 'none';
  document.getElementById('ctx-dm').style.display = !isOwn && !isBlocked ? 'block' : 'none';
  document.getElementById('ctx-tools-wrap').style.display = showHostTools ? 'block' : 'none';
  document.getElementById('ctx-tools-divider').style.display = showHostTools ? 'block' : 'none';
  document.getElementById('ctx-community-eject').style.display = showHostTools && Boolean(cfg.canCommunityEject) ? 'block' : 'none';
  document.getElementById('ctx-tools-wrap').classList.remove('open');
  ctxOrientationWrap?.classList.remove('open');
  document.getElementById('ctx-block').style.display = !isOwn && !isBlocked ? 'block' : 'none';
  document.getElementById('ctx-unblock').style.display = !isOwn && isBlocked ? 'block' : 'none';
  document.getElementById('ctx-manage-relationship').style.display = relationship ? 'block' : 'none';
  document.getElementById('ctx-unlink').style.display = isLinked && !isBlocked ? 'block' : 'none';
  ctxToggleWebcam.textContent = (webcamIntent || webcamStream) ? 'Disable Webcam' : 'Enable Webcam';
  syncAvatarOrientationControls(participant);
  ctxMenu.style.left = `${x}px`;
  ctxMenu.style.top = `${y}px`;
  ctxMenu.classList.add('visible');
}

function closeContextMenu() {
  ctxMenu.classList.remove('visible');
  document.getElementById('ctx-tools-wrap')?.classList.remove('open');
  ctxOrientationWrap?.classList.remove('open');
  ctxOrientation?.setAttribute('aria-expanded', 'false');
  ctxMenuParticipantId = null;
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
  if (mediaPicker) mediaPicker.hidden = true;
}

function closeAttachMenu() {
  attachMenu.hidden = true;
}

function closeGameStartMenu() {
  if (gameStartMenu) gameStartMenu.hidden = true;
}

function closeFloatingShells(except = []) {
  const skip = new Set(except);
  if (!skip.has('context')) closeContextMenu();
  if (!skip.has('text')) closeTextContextMenu();
  if (!skip.has('message')) closeMessageActionMenu();
  if (!skip.has('tab')) closeTabContextMenu();
  if (!skip.has('room')) closeRoomMenu();
  if (!skip.has('roomAction')) closeRoomActionMenu();
  if (!skip.has('game')) closeGameStartMenu();
  if (!skip.has('media')) closeMediaPicker();
  if (!skip.has('attach')) closeAttachMenu();
}

function openEmojiPicker() {
  closeFloatingShells(['message']);
  gesturePaletteLoaded = false;
  const btn = document.getElementById('emoji-btn');
  const r = btn.getBoundingClientRect();
  mediaPicker.hidden = false;
  const er = mediaPicker.getBoundingClientRect();
  mediaPicker.style.left = `${Math.max(8, Math.min(r.right - er.width, window.innerWidth - er.width - 8))}px`;
  mediaPicker.style.top = `${Math.max(8, r.top - er.height - 8)}px`;
  mediaSearchInput?.focus();
  if (activeMediaTab === 'gestures') loadGestures();
  if (activeMediaTab === 'emojis') renderEmojiGrid();
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

document.getElementById('room-menu-btn').addEventListener('click', e => {
  e.stopPropagation();
  if (roomMenu.classList.contains('visible')) closeRoomMenu();
  else openRoomMenu();
});

document.getElementById('room-action-btn')?.addEventListener('click', e => {
  e.stopPropagation();
  if (roomActionMenu?.classList.contains('visible')) closeRoomActionMenu();
  else openRoomActionMenu();
});

document.getElementById('lock-session-btn')?.addEventListener('click', lockSession);

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
  if (gameStartMenu) gameStartMenu.hidden = !gameStartMenu.hidden;
});

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
  input.value = (input.value.slice(0, start) + emoji + input.value.slice(end)).slice(0, input.maxLength);
  const next = start + emoji.length;
  input.setSelectionRange(Math.min(next, input.value.length), Math.min(next, input.value.length));
  input.focus();
  updateComposerState();
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
  if (mediaSearchInput && activeMediaTab !== 'emojis') {
    mediaSearchValues[activeMediaTab] = mediaSearchInput.value;
  }
  activeMediaTab = tab;
  mediaPicker?.classList.remove('media-tab-gifs', 'media-tab-gestures', 'media-tab-emojis');
  mediaPicker?.classList.add(`media-tab-${tab}`);
  mediaPicker?.querySelectorAll('[data-media-tab]').forEach(btn => btn.classList.toggle('active', btn.dataset.mediaTab === tab));
  mediaPicker?.querySelectorAll('.media-panel').forEach(panel => panel.classList.toggle('active', panel.id === `media-panel-${tab}`));
  if (mediaSearchInput) {
    mediaSearchInput.placeholder = tab === 'gifs' ? 'Search GIFs' : (tab === 'gestures' ? 'Search gesture text' : 'Search emojis');
    mediaSearchInput.value = tab === 'emojis' ? '' : (mediaSearchValues[tab] || '');
    mediaSearchInput.style.display = tab === 'emojis' ? 'none' : '';
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
  if (tab === 'emojis') renderEmojiGrid();
}

mediaPicker?.querySelectorAll('[data-media-tab]').forEach(btn => {
  btn.addEventListener('click', () => setMediaTab(btn.dataset.mediaTab || 'gifs'));
});

async function searchGifs(query) {
  if (!gifResults || !cfg?.gifPicker?.enabled) return;
  const q = query.trim();
  if (!q) {
    gifResults.innerHTML = '<div class="minor">Search for a GIF.</div>';
    return;
  }
  gifResults.innerHTML = '<div class="gif-loading">Searching...</div>';
  try {
    const qs = new URLSearchParams({
      session_id: cfg.sessionId,
      join_token: cfg.myJoinToken,
      q,
      provider: cfg.gifPicker.defaultProvider || 'giphy',
    });
    const data = await runtimeRequestClient.getJson(`/api/gif_search.php?${qs}`, {
      operation: 'search-gifs',
      endpointCategory: 'gif-search',
    });
    const results = data.results || [];
    if (!results.length) {
      gifResults.innerHTML = '<div class="minor">No GIFs found.</div>';
      return;
    }
    gifResults.innerHTML = '';
    results.forEach(result => {
      const btn = document.createElement('button');
      btn.className = 'gif-result';
      btn.type = 'button';
      btn.innerHTML = `<img src="${esc(result.preview || result.url)}" alt="${esc(result.title || 'GIF')}">`;
      btn.addEventListener('click', () => sendGif(result));
      gifResults.appendChild(btn);
    });
  } catch (err) {
    gifResults.innerHTML = `<div class="minor">${esc(err.message || 'GIF search failed.')}</div>`;
  }
}

mediaSearchInput?.addEventListener('input', e => {
  if (activeMediaTab === 'gifs') {
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
    gestureOwnedLimit = Number(data.owned_limit ?? 50);
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
  const uploadTile = gestureGrid?.querySelector('.gesture-upload-tile');
  if (!uploadTile) return;
  const limitReached = gestureOwnedCount >= gestureOwnedLimit;
  uploadTile.disabled = limitReached;
  uploadTile.title = limitReached ? 'Remove some gestures to make room.' : 'Upload .agst';
  uploadTile.querySelector('em')?.replaceChildren(document.createTextNode(`${gestureOwnedCount}/${gestureOwnedLimit}`));
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
  const limitReached = gestureOwnedCount >= gestureOwnedLimit;
  uploadTile.disabled = limitReached;
  uploadTile.title = limitReached ? 'Remove some gestures to make room.' : 'Upload .agst';
  uploadTile.innerHTML = `<span>+</span><small>Upload .agst</small><em>${gestureOwnedCount}/${gestureOwnedLimit}</em><div class="gesture-upload-progress"><i></i></div>`;
  uploadTile.addEventListener('click', () => {
    if (limitReached) return;
    gestureFileInput?.click();
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
  if (gestureOwnedCount >= gestureOwnedLimit) {
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
  await loadGestures();
}

gestureFileInput?.addEventListener('change', () => {
  const file = gestureFileInput.files && gestureFileInput.files[0];
  gestureFileInput.value = '';
  if (!file) return;
  uploadGesture(file).catch(err => alert(err.message || err));
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
  const tile = gestureGrid?.querySelector(gestureTileSelector(gesture.id));
  const toggle = tile?.querySelector('.gesture-global');
  if (toggle) toggle.disabled = true;
  try {
    const data = await apiPost('/api/gestures.php', { session_id: cfg.sessionId, join_token: cfg.myJoinToken, action: 'toggle_public', gesture_id: gesture.id, is_public: isPublic });
    replaceGestureTile(data.gesture || { ...gesture, is_public: isPublic });
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
    gestureGrid?.querySelector(gestureTileSelector(gesture.id))?.remove();
    if (gesture.mine) {
      gestureOwnedCount = Math.max(0, gestureOwnedCount - 1);
      updateGestureUploadTileState();
    }
    ensureGestureEmptyState();
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

document.addEventListener('click', e => {
  if (!ctxMenu.contains(e.target)) closeContextMenu();
  if (!textCtxMenu.contains(e.target)) closeTextContextMenu();
  if (msgActionMenu && !msgActionMenu.contains(e.target) && !e.target.closest('.msg-options')) closeMessageActionMenu();
  if (tabCtxMenu && !tabCtxMenu.contains(e.target)) closeTabContextMenu();
  if (!roomMenu.contains(e.target) && !e.target.closest('#room-menu-btn')) closeRoomMenu();
  if (roomActionMenu && !roomActionMenu.contains(e.target) && !e.target.closest('#room-action-btn')) closeRoomActionMenu();
  if (gameStartMenu && !gameStartMenu.contains(e.target) && !e.target.closest('#game-start-btn')) closeGameStartMenu();
  if (mediaPicker && !mediaPicker.contains(e.target) && !e.target.closest('#emoji-btn')) closeMediaPicker();
  if (!attachMenu.contains(e.target) && !e.target.closest('#attach-btn')) closeAttachMenu();
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    closeFloatingShells(['game']);
    closeLinkIconModal();
    document.getElementById('host-warn-modal')?.classList.remove('open');
    document.getElementById('host-kick-modal')?.classList.remove('open');
    document.getElementById('warning-modal')?.classList.remove('open');
    closeDeleteMessageModal();
    cancelVoiceNote();
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
  input.value = input.value.slice(0, start) + input.value.slice(end);
  input.setSelectionRange(start, start);
  input.focus();
  updateComposerState();
}

async function pasteIntoInput() {
  const input = document.getElementById('chat-input');
  const text = await navigator.clipboard.readText();
  const start = input.selectionStart;
  const end = input.selectionEnd;
  input.value = (input.value.slice(0, start) + text + input.value.slice(end)).slice(0, input.maxLength);
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

function unlinkCurrentPartner() {
  const partnerId = activeLinkPartnerId() || linkedPartner()?.id;
  avatarRuntime?.coordinator?.unlinkCurrentParticipant({
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

document.getElementById('ctx-change-avatar').addEventListener('click', () => {
  closeContextMenu();
  avatarFileInput.click();
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
  avatarSizeResetRequested = true;
  if (avatarSizeModalMode === 'avatar') {
    avatarSizeEdge.value = String(policy.avatarDisplayMaxPx || 200);
  } else {
    avatarSizeWebcamWidth.value = String(policy.webcamDisplayMaxWidthPx || 200);
    avatarSizeWebcamHeight.value = String(policy.webcamDisplayMaxHeightPx || 200);
    avatarSizeAspectRatio = Number(avatarSizeWebcamWidth.value) / Math.max(Number(avatarSizeWebcamHeight.value), 1);
    setWebcamPresetFromInputs();
  }
  setAvatarSizeStatus('Community default selected.', 'ok');
});

avatarSizeEdge?.addEventListener('input', () => {
  avatarSizeResetRequested = false;
});

avatarSizeWebcamPreset?.addEventListener('change', () => {
  const policy = avatarRuntime?.displayPolicy?.policy?.() || cfg.avatarSizePolicy || {};
  const value = avatarSizeWebcamPreset.value;
  if (value === 'custom') return;
  avatarSizeInputSync = true;
  if (value === 'default') {
    avatarSizeResetRequested = true;
    avatarSizeWebcamWidth.value = String(policy.webcamDisplayMaxWidthPx || 200);
    avatarSizeWebcamHeight.value = String(policy.webcamDisplayMaxHeightPx || 200);
  } else {
    const [width, height] = value.split('x').map(Number);
    avatarSizeResetRequested = false;
    avatarSizeWebcamWidth.value = String(Math.min(width, Number(policy.webcamDisplayMaxWidthPx || 200)));
    avatarSizeWebcamHeight.value = String(Math.min(height, Number(policy.webcamDisplayMaxHeightPx || 200)));
  }
  avatarSizeAspectRatio = Number(avatarSizeWebcamWidth.value) / Math.max(Number(avatarSizeWebcamHeight.value), 1);
  avatarSizeInputSync = false;
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
  document.getElementById('ctx-tools-wrap')?.classList.remove('open');
  ctxOrientationWrap?.classList.toggle('open', opening);
  ctxOrientation.setAttribute('aria-expanded', opening ? 'true' : 'false');
  if (opening) {
    const selected = ctxOrientationSubmenu?.querySelector('[aria-checked="true"]');
    (selected || ctxOrientationSubmenu?.querySelector('button'))?.focus();
  }
});

ctxOrientationSubmenu?.addEventListener('click', event => {
  const button = event.target.closest('[data-avatar-orientation]');
  if (!button) return;
  setAvatarOrientation(String(button.dataset.avatarOrientation || ''))
    .catch(error => showWarning(error?.message || 'Could not update avatar orientation.'));
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
  closeContextMenu();
  unlinkCurrentPartner();
});

document.getElementById('ctx-manage-relationship')?.addEventListener('click', () => {
  const participantId = ctxMenuParticipantId;
  closeContextMenu();
  if (participantId) {
    avatarRuntime?.relationshipManagement?.openForParticipant(participantId, 'avatar-context');
  }
});

document.getElementById('ctx-dm').addEventListener('click', () => {
  const p = participants.get(ctxMenuParticipantId);
  closeContextMenu();
  if (p) openDmWithUser({ id: p.user_id, display_name: p.display_name, avatar_url: avatarUrl(p) });
});

document.getElementById('ctx-tools')?.addEventListener('click', e => {
  e.stopPropagation();
  ctxOrientationWrap?.classList.remove('open');
  ctxOrientation?.setAttribute('aria-expanded', 'false');
  document.getElementById('ctx-tools-wrap')?.classList.toggle('open');
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
}

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

avatarFileInput.addEventListener('change', async () => {
  const file = avatarFileInput.files && avatarFileInput.files[0];
  if (!file) return;
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
    const data = await runtimeRequestClient.postForm('/api/avatar.php', fd, {
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
    alert(err.message);
  } finally {
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    avatarFileInput.value = '';
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

async function acquireLocalWebcamCapture(constraints, operation = 'enable') {
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

ctxToggleWebcam.addEventListener('click', async () => {
  closeContextMenu();
  if (webcamIntent || webcamStream) {
    const disableToken = beginWebcamOperation(false, 'disable');
    const previousWebcamStream = webcamStream;
    recordVoiceLifecycleDiagnostic({
      event: 'local-webcam-disable-start',
      participantId: Number(cfg.myParticipantId),
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
    releaseWebcamStream(previousWebcamStream);
    applyWebcamState(cfg.myParticipantId, false, null, 'local-webcam-off');
    const persistence = apiPost('/api/media_signal.php', {
      action: 'webcam_off',
      media: 'webcam',
      session_id: cfg.sessionId,
      join_token: cfg.myJoinToken,
    });
    const negotiation = renegotiateMediaPeers({
      reason: 'local-webcam-disable',
      mediaReason: 'webcam',
      webcamOperation: 'disable',
    });
    await Promise.all([persistence, negotiation]);
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
    await apiPost('/api/media_signal.php', { action: 'webcam_on', media: 'webcam', session_id: cfg.sessionId, join_token: cfg.myJoinToken });
    if (!isCurrentWebcamOperation(operationToken) || webcamStream !== acquisition.stream) return;
    await connectMediaPeers({
      reason: 'local-webcam-enable',
      mediaReason: 'webcam',
      webcamOperation: 'enable',
    });
    restartVoicePoll(0);
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
  const onMove = ev => applyDividerDrag(ev.clientY);
  const onUp = () => {
    document.removeEventListener('pointermove', onMove);
    document.removeEventListener('pointerup', onUp);
  };
  document.addEventListener('pointermove', onMove);
  document.addEventListener('pointerup', onUp);
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

function stopGameChatPolling() {
  chatGameChat().reset();
}

function setGameTyping(participantId, active) {
  chatGameChat().setTyping(participantId, active);
}

function startGameChatPolling() {
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

document.getElementById('game-rematch')?.addEventListener('click', () => {
  gameRuntime?.lifecycle?.sendStageControl('rematch');
});

document.getElementById('game-resign')?.addEventListener('click', () => {
  gameRuntime?.lifecycle?.sendStageControl('resign');
});

window.addEventListener('message', e => {
  if (e.origin !== window.location.origin) return;
  if (e.data?.type === 'game_close') closeGame(e.data.lobby);
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

document.querySelectorAll('[data-game]').forEach(btn => {
  btn.addEventListener('click', async () => {
    closeGameStartMenu();
    await gameRuntime?.lifecycle?.startGame(btn.dataset.game);
  });
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
    (data.friends || []).forEach(f => {
      const knownParticipant = [...participants.values()].find(p => Number(p.user_id) === Number(f.id));
      const locateAvatar = knownParticipant
        ? (knownParticipant.avatarEl?.currentSrc || knownParticipant.avatarEl?.src || avatarUrl(knownParticipant))
        : mediaUrl(f.avatar_url);
      const dmTarget = knownParticipant
        ? { ...f, avatar_url: locateAvatar, avatar_path: knownParticipant.avatar_path }
        : f;
      const row = document.createElement('div');
      row.className = 'person-row';
      const go = f.room_id
        ? (f.room_ejected
          ? `<button class="btn locate-action-btn" type="button" disabled aria-label="Room unavailable" title="Room unavailable"><img src="${esc(appUrl('/assets/images/lobby.png'))}" alt=""></button>`
          : `<a class="btn locate-action-btn" href="${esc(appUrl('/chatroom.php?id=' + encodeURIComponent(f.room_id)))}" aria-label="Go to room" title="Go"><img src="${esc(appUrl('/assets/images/lobby.png'))}" alt=""></a>`)
        : '<span class="minor locate-away">Away</span>';
      row.innerHTML = `<img src="${esc(locateAvatar)}" alt=""><div><strong>${esc(f.display_name)}</strong><div class="minor">${f.room_name ? esc(f.room_name) : 'Not in a room'}</div></div><button class="btn locate-action-btn dm-locate-btn" type="button" aria-label="Send DM" title="DM"><img src="${esc(appUrl('/assets/images/chat-pane-dm.png'))}" alt=""></button>${go}`;
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
    if (!version) return;
    latestAppVersion = version;
    if (appVersionEl) appVersionEl.textContent = version;
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
  btn.textContent = joined ? 'Leave Voice' : 'Join Voice';
  btn.classList.toggle('active', joined);
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
  await voiceRuntime?.media?.requestDevicePermissionAndPopulate().catch(err => {
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
    row.innerHTML = `<span class="user-avatar-wrap"><img src="${esc(avatarUrl(person))}" alt=""><span class="voice-speaking-dot${speaking ? ' speaking' : ''}"></span></span><div><strong class="person-name-line"><span>${esc(displayNameFor(person))}</span></strong><div class="minor">${own ? 'You' : statusText}</div></div><div class="voice-card-actions">${controls}</div>`;
    row.querySelector('[data-voice-mute]')?.addEventListener('click', () => setVoiceMuted(!mutedSelf));
    row.querySelector('[data-voice-deafen]')?.addEventListener('click', () => setVoiceDeafened(!deafenedSelf));
    voiceListEl.appendChild(row);
  });
}

async function bootRoom() {
  await initializeAvatarRuntime();

  const roomId = document.body.dataset.roomId;
  cfg = await runtimeRequestClient.getJson(`/api/room_config.php?id=${encodeURIComponent(roomId)}`, {
    operation: 'bootstrap-room',
    endpointCategory: 'room-config',
  });
  avatarRuntime?.displayPolicy?.configure(cfg.avatarSizePolicy || {});
  window.ChatSpaceAvatar?.configure?.(avatarRuntime?.displayPolicy?.policy?.() || cfg.avatarSizePolicy || {});
  cfg.innerTranquillityPlayer = innerTranquillityPlayerCapability();
  importedRoomRuntime?.layout?.render(cfg.importLayout);
  importedRoomRuntime?.music?.renderPlayer(cfg.musicPlaylist);
  chatPoll().seed({
    lastEventId: cfg.lastEventId,
    lastCommunityEventId: cfg.lastCommunityEventId,
  });
  restoreSessionLock();
  (cfg.blockedUserIds || []).forEach(id => blockedUserIds.add(Number(id)));
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
  checkLatency();
  poll();
  pollVoice();
  pollAppVersion();
  pollingRuntime.registerJob({
    id: 'latency-monitor',
    run: checkLatency,
    interval: 5000,
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
  gameRuntime?.lifecycle?.loadGames();
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
