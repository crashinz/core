/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      game-stage-renderer.js
 *
 * Layer:
 *      Runtime Renderer
 *
 * Owner:
 *      Game Runtime
 *
 * Purpose:
 *      Owns embedded game list and stage presentation.
 *
 * Build:
 *      000028
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000028
 * - Introduced GameStageRenderer.
 * - Transferred game stage presentation ownership from room.js.
 ******************************************************************************/

/**
 * @file game-stage-renderer.js
 *
 * Defines the Game Stage Renderer.
 */

//
// No imports required.
//

//--------------------------------------------------
// Game Stage Renderer
//--------------------------------------------------

/**
 * Owns embedded game list and stage presentation.
 */
const SINGLE_SCROLL_GAME_PATHS = Object.freeze({
    "g_b64p3_tetris": "/games/tetris-versus-first-party/index.html",
    "g_b64p3_space": "/games/space-invasion-first-party/index.html",
    "g_b60c0a01": "/games/checkers-first-party/index.html",
    "g_b60c0a02": "/games/chess-first-party/index.html",
    "g_b60c0a03": "/games/acey-deucy-first-party/index.html",
    "g_b60c0a04": "/games/battleship-first-party/index.html",
    "g_b60c0a05": "/games/spades-first-party/index.html",
    "g_b63c0a01": "/games/blackjack-first-party/index.html",
    "g_b63c0a02": "/games/hearts-first-party/index.html",
    "g_b63c0a03": "/games/uno-first-party/index.html",
    "g_b63c0a04": "/games/chinese-checkers-first-party/index.html",
    "g_b63c0a05": "/games/nested-four-first-party/index.html",
    "g_b63c0a06": "/games/puppy-panic-first-party/index.html",
    "g_dominos01": "/games/dominos/index.html",
    "g_b61c0a01": "/games/backgammon-first-party/index.html",
    "g_4f8c2d71": "/games/five-dice/index.html"
});

import { renderGameSeatControls, renderGameBotControls } from "./game-seat-controls.js?v=8829b14ae801";

export function gameUsesSingleScroll(gameType, documentUrl, context) {
    const path = SINGLE_SCROLL_GAME_PATHS[String(gameType || "")];
    if (typeof path !== "string" || typeof context?.appUrl !== "function") return false;
    try {
        const expected = new URL(context.appUrl(path), context.window?.location?.href);
        const actual = new URL(documentUrl, context.window?.location?.href);
        return expected.origin === context.window?.location?.origin
            && actual.origin === expected.origin && actual.pathname === expected.pathname;
    } catch {
        return false;
    }
}

export function releaseGameFrameSingleScroll(frameEl) {
    if (!frameEl) return;
    if (frameEl.__corechatGameSingleScrollOwner) {
        frameEl.__corechatGameSingleScrollOwner.release();
        return;
    }
    const frameWrap = frameEl.closest?.(".game-frame-wrap");
    const ownsLegacySizing = frameWrap?.dataset?.scrollOwner === "room-stage"
        || Boolean(frameEl.__corechatGameHeightObserver);
    if (!ownsLegacySizing) return;
    frameEl.__corechatGameHeightObserver?.disconnect?.();
    frameEl.__corechatGameHeightObserver = null;
    frameEl.__corechatGameWheelDocument?.removeEventListener?.("wheel", frameEl.__corechatGameWheelHandler, true);
    frameEl.__corechatGameWheelDocument = null;
    frameEl.__corechatGameWheelHandler = null;
    try { frameEl.contentDocument?.getElementById?.("corechat-single-scroll-sizing")?.remove?.(); } catch {}
    frameWrap?.removeAttribute?.("data-scroll-owner");
    frameWrap?.style?.removeProperty?.("--game-frame-content-height");
    frameEl.style.removeProperty("height");
    frameEl.style.removeProperty("min-height");
    frameEl.removeAttribute("scrolling");
}

export function gameFrameContentHeight(documentOwner) {
    const documentElement = documentOwner?.documentElement;
    const body = documentOwner?.body;
    const usesIntrinsicHeight = /\/games\/(?:dominos|five-dice|uno-first-party|puppy-panic-first-party|battleship-first-party|chess-first-party|checkers-first-party|spades-first-party|hearts-first-party|tetris-versus-first-party|space-invasion-first-party)\/(?:index\.html)?$/i.test(String(documentOwner?.location?.pathname || ""));
    if (usesIntrinsicHeight && body) {
        const finite = value => Number.isFinite(Number(value)) ? Number(value) : 0;
        const bodyBox = body.getBoundingClientRect?.() || {};
        const mainBox = documentOwner.querySelector?.("main.game")?.getBoundingClientRect?.() || {};
        const style = documentOwner.defaultView?.getComputedStyle?.(body) || {};
        const pixels = value => Number.parseFloat(value) || 0;
        const mainExtent = finite(mainBox.bottom) - finite(bodyBox.top)
            + pixels(style.paddingBottom) + pixels(style.borderBottomWidth);
        // Document scrollHeight is floored by the current iframe viewport.
        // Body/main bounds are intrinsic and can shrink after options close.
        const intrinsic = Math.max(0, finite(body.scrollHeight), finite(body.offsetHeight), finite(bodyBox.height), mainExtent);
        return Math.ceil(Math.max(0, intrinsic + pixels(style.marginTop) + pixels(style.marginBottom)));
    }
    return Math.ceil(Math.max(
        Number(documentElement?.scrollHeight || 0),
        Number(documentElement?.offsetHeight || 0),
        Number(body?.scrollHeight || 0),
        Number(body?.offsetHeight || 0)
    ));
}

export function gameReconnectNotice(activeMembers, framework) {
    const status = String(framework?.status || "");
    if (["lobby", "completed", "forfeited", "abandoned", "ended"].includes(status)) {
        return "";
    }
    const players = framework?.state?._framework?.players || {};
    const reconnecting = activeMembers.filter(member => {
        // Room projection online=true is backed by both a current room
        // heartbeat and, during play, a current authenticated game heartbeat.
        // Prefer it over a reconnect marker left behind by an older outage.
        if (member?.online === true) return false;
        const disconnected = players[String(member.userId)]?.disconnected;
        return typeof disconnected === "boolean" ? disconnected : Boolean(member.reconnectDeadlineAt);
    });
    return reconnecting.length
        ? `${reconnecting.length} player${reconnecting.length === 1 ? " is" : "s are"} disconnected. Reconnect time and available actions are shown in the game.`
        : "";
}

export function gameModeLabel(framework = {}) {
    if (framework.modeLabel) return String(framework.modeLabel);
    if (framework.mode === "recorded") return "Ranked or Recorded Play";
    if (framework.mode === "practice") return "Practice Mode";
    return "Play mode unavailable";
}

export function setGameFrameReadinessOwner(frame, game, source, view) {
    frame.removeAttribute("data-recorded-readiness-owner");
    frame.removeAttribute("data-recorded-readiness-session");
    const gameId = String(game?.lobby_code || "");
    if (!gameId || String(game?.framework?.publicId || "") !== gameId) return;
    try {
        const url = new URL(source, view?.location?.href);
        const sourceGameId = url.searchParams.get("game_session_id") || url.searchParams.get("lobby") || "";
        if (url.origin !== view?.location?.origin || sourceGameId !== gameId) return;
        frame.setAttribute("data-recorded-readiness-owner", "room");
        frame.setAttribute("data-recorded-readiness-session", gameId);
    } catch (_error) {
        // Unclaimed or foreign surfaces retain their own readiness controls.
    }
}

export function recordedGameReadiness(game, participantId) {
    const framework = game?.framework || {};
    if (framework.mode !== "recorded" || framework.status !== "lobby") return null;
    const gameId = String(game?.lobby_code || "");
    if (!gameId || String(framework.publicId || "") !== gameId) return null;
    const players = (Array.isArray(framework.members) ? framework.members : []).filter(member =>
        member.membershipStatus === "active" && ["master", "player"].includes(member.role));
    const viewer = players.find(member => Number(member.participantId) === Number(participantId));
    const minimum = Number(framework.minimumPlayers);
    if (!viewer || viewer.role !== "master" || Number(viewer.userId) !== Number(framework.masterUserId)
        || !Number.isInteger(minimum) || minimum < 1 || players.length < minimum
        || new Set(players.map(member => Number(member.userId))).size !== players.length
        || !players.every(member => member.accepted === true)) return null;
    const settingsSha = String(framework.settingsSha256 || "");
    const playerSetSha = String(framework.playerSetSha256 || "");
    if (!/^[a-f0-9]{64}$/i.test(settingsSha) || !/^[a-f0-9]{64}$/i.test(playerSetSha)) return null;
    return { key: JSON.stringify([gameId, settingsSha, playerSetSha]), gameId, playerCount: players.length };
}

export class GameStageRenderer {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    #runtime;

    #context = null;

    #lastRenderedGameCount = 0;

    #stageVisible = false;

    #recordedReadyState = null;
    #recordedReadyDialog = null;
    #recordedReadyReturnFocus = null;
    #recordedReadyDismissedKey = "";
    #recordedReadyVisibilityListener = null;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Game Stage Renderer.
     *
     * @param {GameRuntime} runtime
     *        Owning Game Runtime.
     */
    constructor(runtime) {

        this.#runtime = runtime;

    }

    //--------------------------------------------------
    // Public Lifecycle
    //--------------------------------------------------

    /**
     * Initializes the renderer.
     */
    initialize() {

    }

    /**
     * Releases renderer references.
     */
    destroy() {

        this.hideStage();
        this.#context?.document?.removeEventListener?.("visibilitychange", this.#recordedReadyVisibilityListener);
        this.#recordedReadyState = null;
        this.#context = null;

    }

    //--------------------------------------------------
    // Public Getters
    //--------------------------------------------------

    /**
     * Returns the owning Game Runtime.
     *
     * @returns {GameRuntime}
     */
    get runtime() {

        return this.#runtime;

    }

    //--------------------------------------------------
    // Public Configuration
    //--------------------------------------------------

    /**
     * Configures DOM shell callbacks and helpers.
     *
     * @param {Object} context
     */
    configure(context = {}) {

        this.#context?.document?.removeEventListener?.("visibilitychange", this.#recordedReadyVisibilityListener);
        this.#context = context;
        this.#recordedReadyVisibilityListener = () => {
            if (this.#context?.document?.visibilityState === "hidden") this.closeRecordedReadiness();
            else if (this.#recordedReadyState) this.renderRecordedReadiness(this.#recordedReadyState.game, this.#recordedReadyState.stageContext);
        };
        this.#context?.document?.addEventListener?.("visibilitychange", this.#recordedReadyVisibilityListener);

    }

    //--------------------------------------------------
    // Public Presentation
    //--------------------------------------------------

    /**
     * Renders the active game list.
     *
     * @param {Array<Object>} games
     * @param {Object} stageContext
     */
    renderGameList(games, stageContext, recentGames = []) {

        const inlineListEl =
            this.#context?.getGameListElement?.();

        const roomListEl =
            this.#context?.getRoomGameListElement?.();

        if (!inlineListEl && !roomListEl) return;

        this.#lastRenderedGameCount =
            games.length;

        const isViewerGame = game =>
            (game.players || []).some(player =>
                Number(player.participant_id) === stageContext.myParticipantId
            );

        const activeViewerGame = games.find(game =>
            isViewerGame(game)
            && stageContext.activeGame?.lobby_code === game.lobby_code
        );

        const viewerGame =
            activeViewerGame || games.find(isViewerGame) || null;

        const orderedGames = viewerGame
            ? [viewerGame, ...games.filter(game => game.lobby_code !== viewerGame.lobby_code)]
            : games.slice();

        const createRow = game => {

            const row =
                this.#context?.document?.createElement(
                    "div"
                );

            if (!row) return null;

            const active =
                stageContext.activeGame?.lobby_code === game.lobby_code;

            const inGame =
                isViewerGame(game);

            row.className =
                `game-row game-row-compact${active ? " active" : ""}${inGame ? " is-viewer-game" : ""}`;

            const players =
                (game.players || []).map(player =>
                    this.#context?.avatarPresentationHtml?.(player, {
                        source: this.#mediaUrl(player.avatar_url),
                        displayName: player.display_name,
                        className: "game-row-avatar"
                    }) || `<img src="${this.#esc(this.#mediaUrl(player.avatar_url))}" alt="${this.#esc(player.display_name)}">`
                ).slice(0, 4).join("");

            const framework = game.framework || {};
            const mode = gameModeLabel(framework);
            const status = String(framework.status || "lobby").replace(/-/g, " ");
            const terminalResult = ["completed", "forfeited", "abandoned"].includes(String(framework.status || ""));
            const spectators = Number(framework.spectatorCount || 0);
            const playerCount = Number(framework.activePlayerCount || (game.players || []).length || 0);
            const explicitlyExited = String(framework.viewerMembershipStatus || "") === "departed";
            const unavailable = explicitlyExited && !terminalResult;
            const actionLabel = terminalResult ? "Result" : unavailable ? "Exited" : inGame ? "Open" : status === "lobby" ? "Join" : "Join on hold";
            const action = `<button class="btn${inGame ? " game-row-state" : ""}" type="button"${unavailable ? ' disabled aria-disabled="true" title="You already exited this game."' : ""}>${actionLabel}</button>`;

            if (terminalResult) row.classList.add("is-recent-result");

            row.innerHTML =
                `<div class="game-row-main"><div class="game-row-title-line"><strong class="game-row-title"><img src="${this.#esc(stageContext.gameIconUrl(game.game_type))}" alt="">${this.#esc(stageContext.gameName(game.game_type))}</strong><div class="game-row-players">${players || "<span class=\"minor\">Waiting</span>"}</div></div><div class="minor">${this.#esc(mode)} / ${this.#esc(status)} / ${playerCount} player${playerCount === 1 ? "" : "s"} / ${this.#esc(game.started_by_name)}${spectators ? ` / ${spectators} watching` : ""}</div></div>${action}`;

            if (!unavailable) {

                row.querySelector("button")?.addEventListener(
                    "click",
                    event => {
                        event.stopPropagation();
                        stageContext.openGame(game);
                    }
                );

            }

            if (inGame && !terminalResult) {

                row.addEventListener(
                    "click",
                    () => stageContext.openGame(game)
                );

            }

            return row;

        };

        const renderRows = (listEl, projectedGames) => {

            if (!listEl) return;

            listEl.replaceChildren(
                ...projectedGames.map(createRow).filter(Boolean)
            );

            listEl.hidden = projectedGames.length === 0;

        };

        renderRows(inlineListEl, viewerGame ? [viewerGame] : []);
        // Room Games is the current-session picker, not a results archive.
        // Completed runs remain available through Game Records.
        renderRows(roomListEl, orderedGames);

        const countEl = this.#context?.getRoomGameCountElement?.();
        if (countEl) countEl.textContent = `${games.length} active ${games.length === 1 ? "game" : "games"}`;

        const summaryEl = this.#context?.getRoomGameSummaryElement?.();
        if (summaryEl) {
            summaryEl.textContent = games.length
                ? `${games.length} active ${games.length === 1 ? "game" : "games"}${viewerGame ? " / your game is first" : ""}`
                : "No active games";
        }

        const emptyEl = this.#context?.getRoomGameEmptyElement?.();
        if (emptyEl) emptyEl.hidden = games.length !== 0;

    }

    /**
     * Shows the game stage for the active game.
     *
     * @param {Object} game
     * @param {Object} stageContext
     */
    showStage(game, stageContext) {

        const titleEl =
            this.#context?.getStageTitleElement?.();

        if (titleEl) {

            titleEl.textContent =
                stageContext.gameName(game.game_type);

        }

        const statusEl = this.#context?.getStageStatusElement?.();

        if (statusEl) {

            const framework = game.framework || {};
            const mode = gameModeLabel(framework);
            const status = String(framework.status || "lobby").replace(/-/g, " ");
            statusEl.textContent = `${mode} · ${status}`;

        }

        const acceptEl = this.#context?.getGameAcceptElement?.();

        if (acceptEl) {

            const framework = game.framework || {};
            const viewer = (framework.members || []).find(member =>
                Number(member.participantId) === Number(stageContext.myParticipantId)
            );
            const mayAccept = framework.status === "lobby"
                && ["master", "player"].includes(String(viewer?.role || ""))
                && (framework.mode !== "practice" || viewer?.role === "master")
                && !viewer?.accepted;
            acceptEl.hidden = !mayAccept;
            acceptEl.disabled = !mayAccept;
            acceptEl.onclick = mayAccept ? () => stageContext.acceptGame() : null;

        }

        const iconEl =
            this.#context?.getStageIconElement?.();

        if (iconEl) {

            iconEl.src =
                stageContext.gameIconUrl(game.game_type);

            iconEl.hidden =
                false;

        }

        const frameEl =
            this.#context?.getGameFrameElement?.();

        if (frameEl) {

            const frameWrap = frameEl.closest?.(".game-frame-wrap");
            const installSingleScrollOwnership = () => {
                try {
                    const documentOwner = frameEl.contentDocument;
                    const view = this.#context?.window;
                    if (!documentOwner || !frameWrap || frameEl.getAttribute("src") !== nextSource) return;
                    const expectedUrl = new URL(nextSource, view?.location?.href);
                    const actualUrl = new URL(documentOwner.location.href);
                    if (expectedUrl.origin !== view?.location?.origin
                        || actualUrl.origin !== expectedUrl.origin
                        || actualUrl.pathname !== expectedUrl.pathname
                        || actualUrl.search !== expectedUrl.search) return;
                    if (!gameUsesSingleScroll(game.game_type, actualUrl.href, this.#context)) {
                        releaseGameFrameSingleScroll(frameEl);
                        return;
                    }
                    const previousOwner = frameEl.__corechatGameSingleScrollOwner;
                    if (previousOwner?.documentOwner === documentOwner && previousOwner.source === nextSource) {
                        previousOwner.syncHeight();
                        return;
                    }
                    releaseGameFrameSingleScroll(frameEl);
                    const captureStyle = (style, name) => ({ value: style.getPropertyValue(name), priority: style.getPropertyPriority(name) });
                    const restoreStyle = (style, name, previous) => previous.value
                        ? style.setProperty(name, previous.value, previous.priority)
                        : style.removeProperty(name);
                    const originalHeight = captureStyle(frameEl.style, "height");
                    const originalMinimum = captureStyle(frameEl.style, "min-height");
                    const originalContentHeight = captureStyle(frameWrap.style, "--game-frame-content-height");
                    const originalScrollOwner = frameWrap.getAttribute("data-scroll-owner");
                    const originalScrolling = frameEl.getAttribute("scrolling");
                    let disposed = false;
                    let animationFrame = null;
                    let observer = null;
                    let sizingStyle = null;
                    let forwardVerticalWheel = null;
                    const owner = {
                        documentOwner,
                        source: nextSource,
                        syncHeight: null,
                        release: () => {
                            if (disposed) return;
                            disposed = true;
                            observer?.disconnect?.();
                            if (animationFrame !== null) view?.cancelAnimationFrame?.(animationFrame);
                            documentOwner.removeEventListener?.("wheel", forwardVerticalWheel, true);
                            sizingStyle?.remove?.();
                            if (frameEl.__corechatGameSingleScrollOwner !== owner) return;
                            frameEl.__corechatGameSingleScrollOwner = null;
                            frameEl.__corechatGameHeightObserver = null;
                            frameEl.__corechatGameWheelDocument = null;
                            frameEl.__corechatGameWheelHandler = null;
                            restoreStyle(frameEl.style, "height", originalHeight);
                            restoreStyle(frameEl.style, "min-height", originalMinimum);
                            restoreStyle(frameWrap.style, "--game-frame-content-height", originalContentHeight);
                            if (originalScrollOwner === null) frameWrap.removeAttribute("data-scroll-owner");
                            else frameWrap.setAttribute("data-scroll-owner", originalScrollOwner);
                            if (originalScrolling === null) frameEl.removeAttribute("scrolling");
                            else frameEl.setAttribute("scrolling", originalScrolling);
                        }
                    };
                    frameEl.__corechatGameSingleScrollOwner = owner;
                    const isCurrent = () => !disposed && frameEl.isConnected !== false
                        && frameEl.__corechatGameSingleScrollOwner === owner
                        && frameEl.contentDocument === documentOwner
                        && frameEl.getAttribute("src") === nextSource;
                    sizingStyle = documentOwner.createElement("style");
                    sizingStyle.id = "corechat-single-scroll-sizing";
                    sizingStyle.textContent = "html,body,#game-root,.game{height:auto!important;min-height:0!important;overflow:visible!important}html,body{scrollbar-width:none!important}html::-webkit-scrollbar,body::-webkit-scrollbar{display:none!important;width:0!important;height:0!important}";
                    documentOwner.head?.append?.(sizingStyle);
                    forwardVerticalWheel = event => {
                        if (!isCurrent()) return;
                        const roomStage = frameWrap.closest?.(".room-stage");
                        const rawDeltaY = Number(event.deltaY || 0);
                        const rawDeltaX = Number(event.deltaX || 0);
                        if (!roomStage || !rawDeltaY || Math.abs(rawDeltaY) <= Math.abs(rawDeltaX)) return;
                        const unit = event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? roomStage.clientHeight : 1;
                        const maxTop = Math.max(0, roomStage.scrollHeight - roomStage.clientHeight);
                        const nextTop = Math.min(maxTop, Math.max(0, roomStage.scrollTop + rawDeltaY * unit));
                        if (nextTop === roomStage.scrollTop) return;
                        event.preventDefault();
                        roomStage.scrollTo({ top: nextTop, left: roomStage.scrollLeft, behavior: "auto" });
                    };
                    documentOwner.addEventListener("wheel", forwardVerticalWheel, { capture: true, passive: false });
                    frameEl.__corechatGameWheelDocument = documentOwner;
                    frameEl.__corechatGameWheelHandler = forwardVerticalWheel;
                    const syncHeight = () => {
                        if (!isCurrent()) return;
                        const contentHeight = gameFrameContentHeight(documentOwner);
                        if (!Number.isFinite(contentHeight) || contentHeight < 1) return;
                        frameWrap.dataset.scrollOwner = "room-stage";
                        frameWrap.style.setProperty("--game-frame-content-height", `${contentHeight}px`);
                        const nextHeight = `${contentHeight}px`;
                        if (frameEl.style.height !== nextHeight) frameEl.style.height = nextHeight;
                        if (frameEl.style.minHeight !== nextHeight) frameEl.style.minHeight = nextHeight;
                    };
                    owner.syncHeight = syncHeight;
                    const ResizeObserverOwner = view?.ResizeObserver;
                    if (ResizeObserverOwner) {
                        observer = new ResizeObserverOwner(syncHeight);
                        if (documentOwner.documentElement) observer.observe(documentOwner.documentElement);
                        if (documentOwner.body) observer.observe(documentOwner.body);
                        frameEl.__corechatGameHeightObserver = observer;
                    }
                    frameEl.setAttribute("scrolling", "no");
                    frameEl.style.height = "1px";
                    frameEl.style.minHeight = "0px";
                    animationFrame = view?.requestAnimationFrame?.(syncHeight) ?? null;
                    documentOwner.fonts?.ready?.then?.(syncHeight)?.catch?.(() => {});
                } catch (_error) {
                    releaseGameFrameSingleScroll(frameEl);
                }
            };

            // The embedded surface owns its action availability. Keep the
            // frame interactive in the lobby so players can review Game
            // Options, rules, accessibility, and records before accepting.
            frameEl.inert = false;
            frameEl.removeAttribute("aria-disabled");
            frameEl.removeAttribute("tabindex");

            const baseSource =
                stageContext.gameFrameUrl(game);
            const isLocalChessMotionAudit =
                /^(?:127(?:\.\d{1,3}){3}|localhost)$/.test(
                    this.#context?.window?.location?.hostname || ""
                ) &&
                game.game_type === "chess" &&
                game.lobby_code === "543c4f07-33b9-49e8-8c6a-c8996a794295";
            const nextSource = isLocalChessMotionAudit
                ? `${baseSource}${baseSource.includes("?") ? "&" : "?"}capture_audit=1`
                : baseSource;

            // Claim readiness before navigation so the first iframe render has one owner.
            setGameFrameReadinessOwner(frameEl, game, nextSource, this.#context?.window);

            if (frameEl.getAttribute("src") !== nextSource) {

                releaseGameFrameSingleScroll(frameEl);

                frameEl.addEventListener(
                    "load",
                    () => {
                        const targetOrigin = this.#context?.window?.location?.origin;
                        if (!targetOrigin) return;
                        frameEl.contentWindow?.postMessage?.(
                            {
                                type: "corechat-game-surface-visibility",
                                visible: this.#stageVisible,
                                reason: "game-frame-loaded"
                            },
                            targetOrigin
                        );
                        installSingleScrollOwnership();
                    },
                    { once: true }
                );

                frameEl.src =
                    nextSource;

            } else {
                installSingleScrollOwnership();
            }

        }

        this.renderSessionSummary(game, stageContext);
        this.renderRecordedReadiness(game, stageContext);

    }

    /**
     * Renders shared lifecycle facts and actions without taking game-rule ownership.
     */
    renderSessionSummary(game, stageContext) {

        const document = this.#context?.document;
        const framework = game.framework || {};
        const members = Array.isArray(framework.members) ? framework.members : [];
        const viewer = members.find(member =>
            Number(member.participantId) === Number(stageContext.myParticipantId)
        );
        const status = String(framework.status || "lobby");
        const lobbyVisible = status === "lobby";
        const stage = this.#context?.getGameStageElement?.();
        const stageBody = document?.getElementById("game-stage-body");
        const frameWrap = document?.querySelector(".game-frame-wrap");
        if (stage) {
            const previousStatus = String(stage.dataset.gameSessionStatus || "");
            stage.dataset.gameSessionStatus = status;
            if (!lobbyVisible && previousStatus !== status && frameWrap) {
                this.#context?.window?.requestAnimationFrame?.(() => {
                    const summary = document?.getElementById("game-session-summary");
                    frameWrap.scrollTop = Number(summary?.offsetHeight || 0);
                });
            }
        }
        if (stageBody) stageBody.hidden = false;
        const activeMembers = members.filter(member => member.membershipStatus === "active");
        const spectatorCount = Number(framework.spectatorCount || 0);

        const facts = document?.getElementById("game-session-facts");
        if (facts) {
            facts.innerHTML = [
                `<span><strong>Mode</strong>${this.#esc(gameModeLabel(framework))}</span>`,
                `<span><strong>Spectators</strong>${spectatorCount}</span>`
            ].join("");
        }

        const rulesSurface = document?.getElementById("game-pregame-rules");
        const rulesDescription = document?.getElementById("game-pregame-rules-description");
        const rulesHost = document?.getElementById("game-pregame-rule-controls");
        const rulesClassification = document?.getElementById("game-rule-classification");
        const rulesSave = document?.getElementById("game-pregame-rules-save");
        const rulesStatus = document?.getElementById("game-pregame-rules-status");
        const settingsSurface = framework.settingsControls || {};
        const settingsControls = Array.isArray(settingsSurface.controls) ? settingsSurface.controls : [];
        if (rulesSurface) rulesSurface.hidden = !lobbyVisible || settingsControls.length === 0;
        if (settingsControls.length && rulesDescription && rulesHost) {
            rulesDescription.textContent = "These are the settings selected when this game was created. You do not need to choose them again.";
            if (rulesClassification) rulesClassification.textContent = String(settingsSurface.classificationLabel || "Selected rules");
            rulesHost.replaceChildren();

            const review = document.createElement("dl");
            review.className = "game-pregame-rule-review";
            settingsControls.forEach(control => {
                const options = Array.isArray(control.options) ? control.options : [];
                const selected = options.find(option => JSON.stringify(option.value) === JSON.stringify(control.value));
                const value = selected?.label ?? (control.type === "checkbox"
                    ? (control.value === true ? "On" : "Off")
                    : control.value);
                const row = document.createElement("div");
                const term = document.createElement("dt");
                const description = document.createElement("dd");
                term.textContent = String(control.label || "Game rule");
                description.textContent = String(value ?? "Selected");
                row.append(term, description);
                review.append(row);
            });
            rulesHost.append(review);

            const completeRules = [settingsSurface.description, framework.rules?.description]
                .filter(Boolean).map(String).join(" ");
            if (completeRules) {
                const details = document.createElement("details");
                details.className = "game-pregame-complete-rules";
                const summary = document.createElement("summary");
                summary.textContent = "Read complete game rules";
                const copy = document.createElement("p");
                copy.textContent = completeRules;
                details.append(summary, copy);
                rulesHost.append(details);
            }
            if (rulesSave) {
                rulesSave.hidden = true;
                rulesSave.disabled = true;
                rulesSave.onclick = null;
            }
            if (rulesStatus) {
                rulesStatus.textContent = framework.mode === "practice"
                    ? "The host accepts these rules once. Other players may then join and play without a second approval prompt."
                    : "The creator has already accepted. After every other player accepts once, the creator confirms that both sides are ready.";
            }
        } else if (rulesHost) {
            rulesHost.replaceChildren();
        }

        const summary = document?.getElementById("game-session-summary");
        const priorSeating = summary?.querySelector(".game-seat-controls");
        const seatingFingerprint = JSON.stringify(framework.seating || {});
        if (!priorSeating || priorSeating.dataset.seatingFingerprint !== seatingFingerprint) {
            priorSeating?.remove();
            const seating = renderGameSeatControls(document, framework.seating, Number(viewer?.userId),
                (action, payload) => stageContext.runFrameworkAction(action, payload));
            if (seating && summary) {
                seating.dataset.seatingFingerprint = seatingFingerprint;
                summary.insertBefore(seating, rulesSurface);
            }
        }

        const priorBots = summary?.querySelector(".game-bot-controls");
        const botFingerprint = JSON.stringify(framework.botSeats || {});
        if (!priorBots || priorBots.dataset.botFingerprint !== botFingerprint) {
            priorBots?.remove();
            const bots = renderGameBotControls(document, framework.botSeats,
                (action, payload) => stageContext.runFrameworkAction(action, payload));
            if (bots && summary) {
                bots.dataset.botFingerprint = botFingerprint;
                summary.insertBefore(bots, rulesSurface);
            }
        }

        const reconnectNotice = gameReconnectNotice(activeMembers, framework);
        const voteParts = [];
        Object.entries(framework.votes || {}).forEach(([, values]) => {
            Object.entries(values || {}).forEach(([value, total]) => {
                voteParts.push(`${String(value).replace(/-/g, " ")} ${total}/${framework.voteRequired || 1}`);
            });
        });
        const notice = document?.getElementById("game-session-notice");
        if (notice) {
            const messages = [];
            if (reconnectNotice) messages.push(reconnectNotice);
            if (framework.savedGame?.available) messages.push("A compatible saved game is available for this exact player set.");
            if (voteParts.length) messages.push(`Current vote: ${voteParts.join(", ")}.`);
            if (framework.result) messages.push(`Recorded result saved ${framework.result.recordedAt}.`);
            notice.textContent = messages.join(" ");
        }

        const isPlayer = ["master", "player"].includes(String(viewer?.role || ""));
        const frameworkActions = framework?.state?._framework?.actions
            ?? (framework?.actions && typeof framework.actions === "object" ? framework.actions : {});
        const wire = (id, visible, action) => {
            const button = document?.getElementById(id);
            if (!button) return;
            button.hidden = !visible;
            button.disabled = !visible;
            button.onclick = visible ? async () => {
                if (notice) notice.textContent = "Working...";
                try {
                    const result = await action();
                    if (notice) notice.textContent = result?.message || "Game controls updated.";
                } catch (error) {
                    if (notice) notice.textContent = error?.message || "The game control could not be completed.";
                }
            } : null;
        };
        wire("game-save", isPlayer && ["active", "paused"].includes(status), () => stageContext.runFrameworkAction("save"));
        wire("game-resume", isPlayer && Boolean(framework.savedGame?.available), () => stageContext.resumeSavedGame());
        wire("game-vote-continue", isPlayer && status === "paused", () => stageContext.runFrameworkAction("vote", {vote_type: "continuation", vote: "continue"}));
        wire("game-vote-save", isPlayer && ["active", "paused"].includes(status), () => stageContext.runFrameworkAction("vote", {vote_type: "continuation", vote: "save"}));
        wire("game-vote-end", isPlayer && ["lobby", "active", "paused"].includes(status), () => stageContext.runFrameworkAction("vote", {vote_type: "continuation", vote: "abandon"}));
        wire("game-records", Boolean(viewer), () => stageContext.openGameRecords());
        const setPassiveControlVisibility = (id, visible) => {
            const button = document?.getElementById(id);
            if (!button) return;
            button.hidden = !visible;
            button.disabled = !visible;
        };
        wire(
            "game-pause",
            isPlayer && ["active", "paused"].includes(status) && frameworkActions.canPause === true,
            () => stageContext.runFrameworkAction("pause-game")
        );
        setPassiveControlVisibility(
            "game-rematch",
            isPlayer && ["completed", "forfeited", "abandoned"].includes(status)
        );
        setPassiveControlVisibility(
            "game-resign",
            isPlayer && ["active", "paused"].includes(status)
        );

    }

    /**
     * Hides the game stage.
     */
    closeRecordedReadiness({ restoreFocus = true } = {}) {
        const returnFocus = this.#recordedReadyReturnFocus;
        this.#recordedReadyDialog?.remove();
        this.#recordedReadyDialog = null;
        this.#recordedReadyReturnFocus = null;
        if (restoreFocus
            && this.#context?.document?.visibilityState !== "hidden"
            && returnFocus?.isConnected
            && !returnFocus.hidden
            && !returnFocus.disabled) {
            returnFocus.focus?.({ preventScroll: true });
        }
    }

    renderRecordedReadiness(game, stageContext) {
        const readiness = recordedGameReadiness(game, stageContext.myParticipantId);
        if (this.#recordedReadyState?.readiness?.key !== readiness?.key) this.closeRecordedReadiness();
        this.#recordedReadyState = { game, stageContext, readiness };
        const button = this.#context?.getGameAcceptElement?.();
        if (button) {
            const framework = game?.framework || {};
            const viewer = (framework.members || []).find(member => Number(member.participantId) === Number(stageContext.myParticipantId));
            const mayAccept = framework.status === "lobby" && ["master", "player"].includes(String(viewer?.role || ""))
                && (framework.mode !== "practice" || viewer?.role === "master") && !viewer?.accepted;
            button.textContent = readiness ? "Ready to begin" : "Accept Game";
            button.hidden = !readiness && !mayAccept;
            button.disabled = button.hidden;
            button.onclick = readiness ? () => this.openRecordedReadiness() : mayAccept ? () => stageContext.acceptGame() : null;
        }
        const document = this.#context?.document;
        if (!readiness || !this.#stageVisible || document?.visibilityState === "hidden") {
            this.closeRecordedReadiness();
            return;
        }
        if (!this.#recordedReadyDialog && this.#recordedReadyDismissedKey !== readiness.key
            && (typeof document?.hasFocus !== "function" || document.hasFocus())) this.openRecordedReadiness();
    }

    openRecordedReadiness() {
        const state = this.#recordedReadyState;
        const document = this.#context?.document;
        const lifecycle = this.#runtime?.lifecycle;
        const current = recordedGameReadiness(lifecycle?.getActiveGame?.(), state?.stageContext?.myParticipantId);
        if (!state?.readiness || !current || current.key !== state.readiness.key
            || !this.#stageVisible || document?.visibilityState === "hidden" || this.#recordedReadyDialog) return;
        const readyButton = this.#context?.getGameAcceptElement?.() || null;
        const activeElement = document.activeElement;
        this.#recordedReadyReturnFocus = activeElement?.isConnected && activeElement !== document.body
            ? activeElement
            : readyButton;
        const dialog = document.createElement("section");
        dialog.setAttribute("role", "dialog");
        dialog.setAttribute("aria-modal", "false");
        dialog.setAttribute("aria-label", "Ready to begin Recorded game");
        Object.assign(dialog.style, { position:"fixed", left:"50%", top:"22%", transform:"translateX(-50%)", width:"min(340px, calc(100vw - 32px))", zIndex:"10050", padding:"14px", border:"1px solid #8c956e", borderRadius:"12px", background:"#171d2c", color:"#f4f3e9", boxShadow:"0 12px 36px #0009" });
        const title = document.createElement("h3"); title.textContent = "Ready to begin?";
        title.tabIndex = 0;
        title.setAttribute("aria-label", "Move ready dialog. Use arrow keys.");
        Object.assign(title.style, { margin:"0 0 10px", cursor:"move", touchAction:"none" });
        const copy = document.createElement("p");
        copy.textContent = "All " + current.playerCount + " players accepted the rules. Confirm both sides are ready before beginning.";
        const status = document.createElement("p"); status.setAttribute("role", "status");
        const controls = document.createElement("div"); Object.assign(controls.style, { display:"flex", gap:"8px", justifyContent:"flex-end" });
        const later = document.createElement("button"); later.type = "button"; later.className = "btn"; later.textContent = "Not yet";
        const begin = document.createElement("button"); begin.type = "button"; begin.className = "btn btn-primary"; begin.textContent = "Begin game";
        const dismiss = () => { this.#recordedReadyDismissedKey = current.key; this.closeRecordedReadiness(); };
        later.addEventListener("click", dismiss);
        dialog.addEventListener("keydown", event => { if (event.key === "Escape") { event.preventDefault(); dismiss(); } });
        let drag = null;
        const moveDialog = (left, top) => {
            const rect = dialog.getBoundingClientRect();
            const width = this.#context?.window?.innerWidth || 1024;
            const height = this.#context?.window?.innerHeight || 768;
            dialog.style.left = Math.max(8, Math.min(width - rect.width - 8, left)) + "px";
            dialog.style.top = Math.max(8, Math.min(height - rect.height - 8, top)) + "px";
            dialog.style.transform = "none";
        };
        title.addEventListener("pointerdown", event => {
            if (event.button !== 0) return;
            const rect = dialog.getBoundingClientRect();
            drag = { x:event.clientX, y:event.clientY, left:rect.left, top:rect.top, id:event.pointerId };
            title.setPointerCapture?.(event.pointerId); event.preventDefault();
        });
        title.addEventListener("pointermove", event => {
            if (!drag || event.pointerId !== drag.id) return;
            moveDialog(drag.left + event.clientX - drag.x, drag.top + event.clientY - drag.y);
        });
        title.addEventListener("keydown", event => {
            const direction = {
                ArrowLeft: [-1, 0],
                ArrowRight: [1, 0],
                ArrowUp: [0, -1],
                ArrowDown: [0, 1],
            }[event.key];
            if (!direction) return;
            event.preventDefault();
            const rect = dialog.getBoundingClientRect();
            const step = event.shiftKey ? 32 : 8;
            moveDialog(rect.left + direction[0] * step, rect.top + direction[1] * step);
        });
        const endDrag = () => { drag = null; };
        title.addEventListener("pointerup", endDrag); title.addEventListener("pointercancel", endDrag);
        begin.addEventListener("click", async () => {
            if (begin.disabled) return;
            const eligible = () => this.#stageVisible && document.visibilityState !== "hidden"
                && recordedGameReadiness(lifecycle?.getActiveGame?.(), state.stageContext.myParticipantId)?.key === current.key;
            if (!eligible()) { this.closeRecordedReadiness(); return; }
            begin.disabled = true;
            try {
                // Refresh the canonical room projection before using its current
                // consent. The server remains the authority for the actual start.
                await lifecycle.loadGames();
                if (!eligible()) { this.closeRecordedReadiness(); return; }
                await state.stageContext.runFrameworkAction("start");
                this.closeRecordedReadiness();
            } catch (error) {
                status.textContent = error?.message || "The game could not begin. Review its current readiness and try again.";
                begin.disabled = false;
            }
        });
        controls.append(later, begin); dialog.append(title, copy, status, controls);
        this.#recordedReadyDialog = dialog;
        document.body.append(dialog);
        begin.focus?.({ preventScroll: true });
    }

    hideStage() {

        const frameEl =
            this.#context?.getGameFrameElement?.();

        this.setLayerVisibility(
            false,
            { terminal: true, reason: "game-close" }
        );

        if (frameEl) {

            releaseGameFrameSingleScroll(frameEl);
            setGameFrameReadinessOwner(frameEl);
            frameEl.src =
                "about:blank";

        }

    }

    /**
     * Sets game stage visibility.
     *
     * @param {boolean} visible
     * @param {Object} details
     */
    setLayerVisibility(visible, details = {}) {

        const stageEl =
            this.#context?.getGameStageElement?.();

        if (!stageEl) return;

        this.#stageVisible =
            Boolean(visible);

        stageEl.hidden =
            !this.#stageVisible;

        if (!this.#stageVisible) this.closeRecordedReadiness();
        else if (this.#recordedReadyState) this.renderRecordedReadiness(this.#recordedReadyState.game, this.#recordedReadyState.stageContext);

        const frameEl =
            this.#context?.getGameFrameElement?.();

        if (frameEl?.contentWindow) {

            const targetOrigin =
                this.#context?.window?.location?.origin;

            if (targetOrigin) {

                frameEl.contentWindow.postMessage(
                    {
                        type: "corechat-game-surface-visibility",
                        visible: this.#stageVisible,
                        reason: String(details.reason || "surface-visibility")
                    },
                    targetOrigin
                );

            }

        }

        this.#runtime?.layout?.setSurfaceVisibility(
            this.#stageVisible,
            details
        );

    }

    /**
     * Renders active game stage player cards.
     *
     * @param {Object} game
     * @param {Object} stageContext
     */
    renderStagePlayers(game, stageContext) {

        const bySeat =
            new Map(
                (game.players || []).map(player => [
                    Number(player.seat),
                    player
                ])
            );

        const maximumPlayers = stageContext.gameMaxPlayers(game.game_type);
        const cards = this.#context?.getGamePlayerElements?.() || [];

        cards.forEach(card => {

            if (!card) return;

            const seat = Number(card.dataset.gameSeat || 0);
            const label = `Player ${seat}`;
            const player = bySeat.get(seat);
            card.hidden = seat < 1 || seat > maximumPlayers;
            if (card.hidden) return;

            const img =
                card.querySelector("img");

            const name =
                card.querySelector("strong");

            const sub =
                card.querySelector(".minor");

            if (img) {

                const hidden = Boolean(player && this.#context?.avatarVisibilityFor?.(player)?.hidden);
                img.classList.toggle("avatar-hidden-placeholder", hidden);
                img.alt = hidden ? "Avatar hidden by you" : (player?.display_name || "Waiting");
                img.title = hidden ? "Avatar hidden by you" : (player?.display_name || "");
                if (hidden) img.removeAttribute("src");
                else img.src = this.#mediaUrl(
                    player?.avatar_url ||
                    this.#context?.appUrl?.("/assets/images/baghead.png")
                );

            }

            if (name) {

                name.textContent =
                    player?.display_name || "Waiting";

            }

            if (sub) {

                sub.className =
                    `minor game-player-role${player && Number(player.participant_id) === stageContext.myParticipantId ? " is-you" : ""}`;

                const role = player?.role === "master"
                    ? `Master - ${stageContext.gameSeatRole(game.game_type, player.seat || seat)}`
                    : stageContext.gameSeatRole(game.game_type, player?.seat || seat);
                sub.textContent = player
                    ? `${role}${player.accepted ? " - Accepted" : " - Waiting for acceptance"}${player.reconnectDeadlineAt ? " - Reconnecting" : ""}`
                    : `${label} open`;

            }

            card.dataset.participantId =
                player?.participant_id || "";

            card.classList.toggle(
                "typing",
                Boolean(player && this.#context?.isGameTyping?.(player.participant_id))
            );

        });

    }

    /**
     * Sends a control action to the game iframe.
     *
     * @param {string} action
     */
    postControl(action, details = {}) {

        this.#context?.getGameFrameElement?.()
            ?.contentWindow
            ?.postMessage(
                {
                    type:
                        "game_control",

                    action,
                    ...details
                },
                this.#context?.origin?.() || ""
            );

    }

    //--------------------------------------------------
    // Public Diagnostics
    //--------------------------------------------------

    /**
     * Returns game stage diagnostics.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        return Object.freeze({

            owner:
                "GameRuntime",

            build:
                "000028",

            configured:
                Boolean(this.#context),

            lastRenderedGameCount:
                this.#lastRenderedGameCount,

            stageVisible:
                this.#stageVisible

        });

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    #esc(value) {

        return this.#context?.esc?.(
            value
        ) ?? String(value ?? "");

    }

    #mediaUrl(value) {

        return this.#context?.mediaUrl?.(
            value
        ) ?? String(value ?? "");

    }

}

export default GameStageRenderer;
