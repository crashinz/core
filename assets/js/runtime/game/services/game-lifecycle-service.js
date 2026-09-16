/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      game-lifecycle-service.js
 *
 * Layer:
 *      Runtime Service
 *
 * Owner:
 *      Game Runtime
 *
 * Purpose:
 *      Owns embedded game state, catalog loading, open/close lifecycle, refresh
 *      handling, and game diagnostics.
 *
 * Build:
 *      000028
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000028
 * - Introduced GameLifecycleService.
 * - Transferred game lifecycle ownership from room.js.
 ******************************************************************************/

/**
 * @file game-lifecycle-service.js
 *
 * Defines the Game Lifecycle Service.
 */

//
// No imports required.
//

//--------------------------------------------------
// Constants
//--------------------------------------------------

const GAME_CATALOG =
    Object.freeze({

        chess:
            Object.freeze({
                name: "Chess",
                path: "chess",
                entry: "index.html",
                icon: "chess",
                gameId: 2,
                seats: Object.freeze(["White", "Black"])
            }),

        checkers:
            Object.freeze({
                name: "Checkers",
                path: "checkers",
                entry: "index.html",
                icon: "checkers",
                gameId: 3,
                seats: Object.freeze(["Red", "White"])
            }),

        backgammon:
            Object.freeze({
                name: "Backgammon",
                path: "backgammon",
                entry: "backgammon.html",
                icon: "backgammon",
                gameId: 5,
                seats: Object.freeze(["White", "Black"])
            }),

        spaceinvasion:
            Object.freeze({
                name: "Space Invasion",
                path: "spaceinvasion",
                entry: "spaceinvasion.html",
                icon: "spaceinvasion",
                gameId: 6,
                seats: Object.freeze(["Player 1", "Player 2"])
            }),

        tetris:
            Object.freeze({
                name: "Tetris Versus",
                path: "tetris-versus",
                entry: "tetris-versus.html",
                icon: "tetris",
                gameId: 7,
                seats: Object.freeze(["Player 1", "Player 2"])
            })

    });

//--------------------------------------------------
// Game Lifecycle Service
//--------------------------------------------------

/**
 * Owns embedded game lifecycle state and workflow decisions.
 */
export class GameLifecycleService {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    #runtime;

    #stage;

    #context = null;

    #activeGame = null;

    #activeGames = new Map();

    #autoOpenCurrentGameResolved = false;

    #catalog = new Map(Object.entries(GAME_CATALOG));

    #lastLoadCount = 0;

    #recoveryTimer = 0;

    #bootstrapRecoveryAttempts = 0;

    #sessionUnavailableHandler = null;

    #frameworkRequestOrdinal = 0;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Game Lifecycle Service.
     *
     * @param {GameRuntime} runtime
     *        Owning Game Runtime.
     *
     * @param {GameStageRenderer} stage
     *        Runtime-owned game stage renderer.
     */
    constructor(runtime, stage) {

        this.#runtime = runtime;
        this.#stage = stage;

    }

    //--------------------------------------------------
    // Public Lifecycle
    //--------------------------------------------------

    /**
     * Initializes the service.
     */
    initialize() {

    }

    /**
     * Releases game lifecycle state.
     */
    destroy() {
        this.__openGameIntentRevision = Number(this.__openGameIntentRevision || 0) + 1;
    this.__catalogLoadRevision = Number(this.__catalogLoadRevision || 0) + 1;

        this.__gamesLoadRevision = Number(this.__gamesLoadRevision || 0) + 1;
        this.#context?.stopGameChatPolling?.("game-lifecycle-destroyed");
        this.#context?.stopGameTypingNow?.();
        this.#context?.window?.removeEventListener?.("message", this.#sessionUnavailableHandler);
        this.#sessionUnavailableHandler = null;

        if (this.#recoveryTimer) {
            this.#context?.window?.clearTimeout?.(this.#recoveryTimer);
            this.#recoveryTimer = 0;
        }
        this.__rematchReconcilePending = null;
        this.#activeGame = null;
        this.#activeGames.clear();
        this.#autoOpenCurrentGameResolved = false;
        this.#bootstrapRecoveryAttempts = 0;
        this.#stage?.hideStage();
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

    /**
     * Returns the active game.
     *
     * @returns {Object|null}
     */
    getActiveGame() {

        return this.#activeGame;

    }

    /**
     * Returns the active games map.
     *
     * @returns {Map<string,Object>}
     */
    getActiveGames() {

        return this.#activeGames;

    }

    //--------------------------------------------------
    // Public Configuration
    //--------------------------------------------------

    /**
     * Configures host callbacks and API adapters.
     *
     * @param {Object} context
     */
    configure(context = {}) {
        this.__openGameIntentRevision = Number(this.__openGameIntentRevision || 0) + 1;
    this.__gamesLoadRevision = Number(this.__gamesLoadRevision || 0) + 1;
    this.__catalogLoadRevision = Number(this.__catalogLoadRevision || 0) + 1;

        this.#context?.window?.removeEventListener?.("message", this.#sessionUnavailableHandler);
        this.#context = context;
        this.__rematchReconcilePending = null;
        this.#stage?.configure(context);
        this.#runtime?.layout?.configure(context);
        this.#sessionUnavailableHandler = event => {
            const data = event?.data;
            const origin = this.#context?.window?.location?.origin;
            const frame = this.#context?.getGameFrameElement?.();
            if (!origin || event?.origin !== origin || !frame?.contentWindow
                || event?.source !== frame.contentWindow) return;
            if (data?.type === "corechat-game-session-reconcile") {
                if (typeof data.lobbyCode !== "string" || !data.lobbyCode
                    || this.#activeGame?.lobby_code !== data.lobbyCode
                    || typeof data.successorLobbyCode !== "string" || !data.successorLobbyCode
                    || data.successorLobbyCode === data.lobbyCode
                    || this.__rematchReconcilePending) return;
                const requestContext = this.#context;
                const pending = Promise.resolve(this.refreshFromRoomEvent())
                    .catch(error => {
                        if (this.#context === requestContext) this.#context?.warnError?.(error);
                    })
                    .finally(() => {
                        if (this.__rematchReconcilePending === pending) this.__rematchReconcilePending = null;
                    });
                this.__rematchReconcilePending = pending;
                return;
            }
            if (data?.type !== "corechat-game-session-unavailable"
                || !this._isTerminalSessionError(data)) return;
            this._discardUnavailableGame(String(data.lobbyCode || ""));
        };
        context.window?.addEventListener?.("message", this.#sessionUnavailableHandler);

    }

    //--------------------------------------------------
    // Public Catalog Helpers
    //--------------------------------------------------

    /**
     * Returns the display name for a game type.
     *
     * @param {string} type
     *
     * @returns {string}
     */
    gameName(type) {

        return this.#catalog.get(type)?.name || type;

    }

    /**
     * Returns the path segment for a game type.
     *
     * @param {string} type
     *
     * @returns {string}
     */
    gamePath(type) {

        return this.#catalog.get(type)?.path || type;

    }

    /**
     * Returns the icon URL for a game type.
     *
     * @param {string} type
     *
     * @returns {string}
     */
    gameIconUrl(type) {

        const icon = this.#catalog.get(type)?.icon || `${this.gamePath(type)}-icon.png`;

        const normalized = String(icon);
        const safeIcon = /^[A-Za-z0-9._-]+\.(?:png|svg|webp)$/i.test(normalized)
            ? normalized
            : `${this.gamePath(type)}-icon.png`;

        return this.#context?.appUrl?.(`/assets/images/${safeIcon}`) || "";

    }

    /**
     * Returns the game stage URL for a lobby.
     *
     * @param {Object} game
     *
     * @returns {string}
     */
    gameFrameUrl(game) {

        const meta =
            this.#catalog.get(game.game_type) || {
                path: game.game_type,
                entry: "index.html",
                gameId: 0
            };

        const myParticipantId =
            Number(this._config()?.myParticipantId);

        const mySeat =
            (game.players || []).find(player =>
                Number(player.participant_id) === myParticipantId
            )?.seat || 1;

        const qs =
            new URLSearchParams({
                lobby: game.lobby_code,
                user: String(myParticipantId),
                player: String(mySeat),
                game: String(meta.gameId || 0),
                embedded: "1",
                csrf: this.#context?.getCsrfToken?.() || "",
                session_id: String(this._config()?.sessionId || ""),
                participant_id: String(this._config()?.myParticipantId || ""),
                join_token: String(this._config()?.myJoinToken || ""),
                game_session_id: String(game.lobby_code || "")
            });

        if (meta.adaptationVersion) {
            qs.set("v", String(meta.adaptationVersion));
        }

        return this.#context?.appUrl?.(
            `/games/${meta.path}/${meta.entry}?${qs}`
        ) || "";

    }

    /**
     * Returns the seat role label for a game type and seat.
     *
     * @param {string} type
     * @param {number|string} seat
     *
     * @returns {string}
     */
    gameSeatRole(type, seat) {

        const labels =
            this.#catalog.get(type)?.seats || [];

        return labels[Number(seat) - 1] || `Player ${seat}`;

    }

    /**
     * Returns the validated maximum player count for a game type.
     */
    gameMaxPlayers(type) {

        return Math.max(
            1,
            Math.min(10, Number(this.#catalog.get(type)?.maxPlayers || 2))
        );

    }

    /**
     * Returns validated presentation sizing metadata for a game extension.
     *
     * @param {string} type
     * @returns {Object}
     */
    gamePresentationSizing(type) {

        return this.#catalog.get(type)?.presentationSizing || {
            preferredAspectRatio: 4 / 3,
            minimumReadableWidth: 640,
            minimumReadableHeight: 480
        };

    }

    /**
     * Finds the active game containing a participant.
     *
     * @param {number|string} participantId
     *
     * @returns {Object|null}
     */
    gameForParticipant(participantId) {

        const id =
            Number(participantId);

        return [...this.#activeGames.values()].find(game =>
            (game.players || []).some(player =>
                Number(player.participant_id) === id
            )
        ) || null;

    }

    //--------------------------------------------------
    // Public Workflow
    //--------------------------------------------------

    /**
     * Loads the current active game list.
     *
     * @returns {Promise<void>}
     */
    _isTerminalSessionError(error) {
        const status = Number(error?.details?.status || error?.httpStatus || error?.status || 0);
        const code = String(error?.responsePayload?.code || error?.code || "");
        return [401, 403, 404, 410].includes(status)
            || ["MULTIPLAYER_GAME_ACCESS_DENIED", "MULTIPLAYER_GAME_NOT_FOUND"].includes(code)
            || /game-session access is denied|game not found/i.test(String(error?.message || ""));
    }

    _discardUnavailableGame(lobbyCode) {
        if (!lobbyCode || this.#activeGame?.lobby_code !== lobbyCode) return false;
        this.#activeGames.delete(lobbyCode);
        this.#lastLoadCount = this.#activeGames.size;
        this.#autoOpenCurrentGameResolved = true;
        this.#context?.showWarning?.(
            "This game session is no longer available. Return to the room to choose a current game."
        );
        this.hideGameOverlay();
        this.#stage?.renderGameList([...this.#activeGames.values()], this._buildStageContext());
        this.#context?.renderPeople?.();
        return true;
    }

    async loadCatalog() {
    const revision = this.__catalogLoadRevision = Number(this.__catalogLoadRevision || 0) + 1;
    try {
      const data = await this._fetchGames({ includeCatalog: true });
      if (revision !== this.__catalogLoadRevision) return false;
      if (!Array.isArray(data?.catalog)) throw new Error('The game library response is incomplete.');
      const catalog = data.catalog;
      if (catalog.some(game => !game || typeof game.key !== 'string' || !game.key)) {
        throw new Error('The game library response is invalid.');
      }
      this.#catalog = new Map(catalog.map(game => [game.key, Object.freeze({ ...game })]));
      this.#context?.renderGameCatalog?.(catalog.filter(game => game.enabled));
      return true;
    } catch (error) {
      if (revision !== this.__catalogLoadRevision) return false;
      this.#context?.renderGameCatalog?.([], { error: 'The game library could not be loaded. Close and reopen to retry.' });
      throw error;
    }
  }

  _rematchSuccessorFor(framework, previousGame) {
    const predecessorId = String(previousGame?.lobby_code || '');
    const successorId = String(framework?.rematchSuccessorPublicId || '');
    if (!predecessorId || !successorId || successorId === predecessorId
      || framework?.publicId !== predecessorId
      || !['completed', 'forfeited', 'abandoned'].includes(framework?.status)
      || !['master', 'player'].includes(framework?.viewerRole)) return null;
    const successor = this.#activeGames.get(successorId);
    const next = successor?.framework;
    const roomId = Number(framework?.sourceRoomSessionId);
    const participantId = Number(this._config()?.myParticipantId);
    if (!successor || successor.lobby_code !== successorId || next?.publicId !== successorId
      || typeof framework?.gameKey !== 'string' || !framework.gameKey || next?.gameKey !== framework.gameKey
      || !Number.isSafeInteger(roomId) || roomId < 1 || Number(next?.sourceRoomSessionId) !== roomId
      || !['master', 'player'].includes(next?.viewerRole)
      || !Number.isSafeInteger(participantId) || participantId < 1
      || !Array.isArray(next?.members)
      || !next.members.some(member => Number(member.participantId) === participantId
        && member.membershipStatus === 'active' && ['master', 'player'].includes(member.role))) return null;
    return successor;
  }

  async loadGames({ includeCatalog = false } = {}) {
        // An in-flight explicit Close wins over passive reconciliation.
        if (this.__pendingCloseGameIntent?.context === this.#context
            && this.__pendingCloseGameIntent?.revision === this.__openGameIntentRevision) return false;

        if (this.#context?.document?.hidden) return false;

        const loadRevision =
            this.__gamesLoadRevision = Number(this.__gamesLoadRevision || 0) + 1;
    if (includeCatalog) {
      try { await this.loadCatalog(); } catch (error) { this.#context?.warnError?.(error); }
      if (loadRevision !== this.__gamesLoadRevision) return false;
    }

        let data = null;
        try {
            data = await this._fetchGames();
            if (!Array.isArray(data?.games)
                || !Array.isArray(data?.recentGames)
                || data.games.some(game => !this._isRoomGameProjection(game))
                || data.recentGames.some(game => !this._isRoomGameProjection(game))) {
                throw this._contractFailure(
                    "GAME_LIST_RESPONSE_INVALID",
                    "The room game list response is incomplete."
                );
            }
        } catch (error) {
            if (loadRevision !== this.__gamesLoadRevision) return false;
            const terminalAccessFailure = this._isTerminalSessionError(error);

            if (terminalAccessFailure) {
                if (this.#activeGame) {
                    this.#context?.warnError?.(error);
                    this._discardUnavailableGame(this.#activeGame.lobby_code);
                    return false;
                }
                const bootstrapRestorePending =
                    !this.#autoOpenCurrentGameResolved && !this.#activeGame;
                if (bootstrapRestorePending && this.#bootstrapRecoveryAttempts < 6) {
                    this.#bootstrapRecoveryAttempts += 1;
                    this._scheduleRecovery(250 * this.#bootstrapRecoveryAttempts);
                } else if (this.#recoveryTimer) {
                    this.#context?.window?.clearTimeout?.(this.#recoveryTimer);
                    this.#recoveryTimer = 0;
                }
                return false;
            }

            this.#context?.warnError?.(error);
            if (this.#activeGame) {
                this.#stage?.showStage(this.#activeGame, this._buildStageContext());
                this.updateStagePlayers();
                this.setLayerVisibility();
            }
            this._scheduleRecovery();
            return false;
        }

        // A room event can start a list request just before an authoritative
        // Exit Game response. Never allow that older projection to repaint the
        // game card or reopen its surface after the post-exit reload wins.
        if (loadRevision !== this.__gamesLoadRevision) return false;
        this.#bootstrapRecoveryAttempts = 0;

        if (this.#recoveryTimer) {
            this.#context?.window?.clearTimeout?.(this.#recoveryTimer);
            this.#recoveryTimer = 0;
        }

        const games =
            Array.isArray(data?.games) ? data.games : [];

        const recentGames =
            Array.isArray(data?.recentGames) ? data.recentGames : [];

        

        this.#lastLoadCount =
            games.length;

        this.#activeGames.clear();

        games.forEach(game => {

            this.#activeGames.set(
                game.lobby_code,
                game
            );

        });

        let autoOpenedCurrentGame = false;
        if (!this.#autoOpenCurrentGameResolved && !this.#activeGame) {

            const currentGame =
                this.gameForParticipant(
                    this._config()?.myParticipantId
                );

            if (currentGame) {

                this.#activeGame =
                    Object.assign({}, currentGame);
                this.#autoOpenCurrentGameResolved = true;
                autoOpenedCurrentGame = true;
                this.#context?.switchChat?.(
                    this.#context?.gameChatKey?.(
                        currentGame.lobby_code
                    )
                );
                this.#context?.startGameChatPolling?.();

            }

        }

        this.#stage?.renderGameList(
            games,
            this._buildStageContext(),
            recentGames
        );

        if (this.#activeGame && this.#activeGames.has(this.#activeGame.lobby_code)) {

            this.#activeGame =
                Object.assign(
                    this.#activeGame,
                    this.#activeGames.get(this.#activeGame.lobby_code)
                );

            this.#stage?.showStage(
                this.#activeGame,
                this._buildStageContext()
            );
            this.updateStagePlayers();
            this.setLayerVisibility();
            if (autoOpenedCurrentGame) {
                this.#context?.onGameOpened?.(this.#activeGame);
            }

        } else if (this.#activeGame) {

            const priorActiveGame =
                this.#activeGame;

            let terminalFramework =
                null;

            try {

                const query =
                    new URLSearchParams({
                        action: "session",
                        session_id: String(this._config()?.sessionId || ""),
                        participant_id: String(this._config()?.myParticipantId || ""),
                        join_token: String(this._config()?.myJoinToken || ""),
                        game_session_id: String(priorActiveGame.lobby_code || "")
                    });

                terminalFramework =
                    await this._fetchFramework(query, "terminal-session-probe") || null;

            } catch (error) {

                if (loadRevision !== this.__gamesLoadRevision
                    || this.#activeGame?.lobby_code !== priorActiveGame.lobby_code) return false;

                this.#context?.warnError?.(
                    error
                );

                if (this._isTerminalSessionError(error)) {
                    this._discardUnavailableGame(priorActiveGame.lobby_code);
                    return false;
                }

            }

            if (loadRevision !== this.__gamesLoadRevision
                || this.#activeGame?.lobby_code !== priorActiveGame.lobby_code) return false;

            const terminalStatus =
                String(terminalFramework?.status || "");

            if (terminalFramework && terminalStatus !== "") {
                const rematchSuccessor = this._rematchSuccessorFor(terminalFramework, priorActiveGame);
                if (rematchSuccessor) {
                    // This successor has already passed room, game, consent and
                    // membership checks. Adopt it in place; ordinary game-switch
                    // teardown would expose the room and clear the visible chat.
                    this.#context?.continueGameChat?.(priorActiveGame.lobby_code, rematchSuccessor.lobby_code);
                    this._presentGame(rematchSuccessor);
                    return true;
                }


                this.#activeGame =
                    Object.assign(
                        priorActiveGame,
                        {framework: terminalFramework}
                    );

                this.#stage?.showStage(
                    this.#activeGame,
                    this._buildStageContext()
                );

                this.updateStagePlayers();
                this.setLayerVisibility();

            } else {

                // A list projection and its direct session recovery can race
                // with a short database lock or reconnect. Preserve the last
                // authoritative surface and retry instead of making the game
                // disappear until the member guesses that a reload is needed.
                this.#activeGame = priorActiveGame;
                this.#stage?.showStage(this.#activeGame, this._buildStageContext());
                this.updateStagePlayers();
                this.setLayerVisibility();
                this._scheduleRecovery();

            }

        }

        this.#context?.renderPeople?.();
        this.#context?.renderLinkTabs?.();

        return true;

    }

    /**
     * Starts a new game and opens its lobby.
     *
     * @param {string} gameType
     *
     * @returns {Promise<void>}
     */
    async startGame(gameType, mode = "practice", settings = {}) {

        // The mode dialog closes before the start request completes. Clear any
        // previously viewed game immediately so its stale stage cannot remain
        // visible while the newly selected lobby is being created.
        this.hideGameOverlay();
        this.#autoOpenCurrentGameResolved = true;

        const data =
            await this.#context?.apiPost?.(
                "/api/games.php",
                {
                    action: "start",
                    session_id: this._config()?.sessionId,
                    participant_id: this._config()?.myParticipantId,
                    join_token: this._config()?.myJoinToken,
                    game_type: gameType,
                    mode,
                    settings
                }
            );

        const lobbyCode = String(data?.lobby_code || "").trim();
        const returnedFramework = data?.framework || null;
        if (data?.ok !== true
            || lobbyCode === ""
            || typeof data?.game_type !== "string"
            || data.game_type.trim() === ""
            || !this._isFrameworkProjection(returnedFramework, lobbyCode)) {
            throw this._contractFailure(
                "GAME_START_RESPONSE_INVALID",
                "The game start response is incomplete."
            );
        }
        const synchronizeStartedGame = async () => {
            for (let attempt = 0; attempt < 6; attempt += 1) {
                await this.loadGames();
                const projected = this.#activeGames.get(lobbyCode);
                if (projected) return projected;
                if (attempt < 5) {
                    await new Promise(resolve =>
                        this.#context?.window?.setTimeout?.(resolve, 350 + (attempt * 200))
                    );
                }
            }
            return null;
        };

        const returnedMembers = Array.isArray(returnedFramework?.members)
            ? returnedFramework.members
            : [];
        let startedGame = returnedFramework
            ? {
                lobby_code: lobbyCode,
                game_type: String(data?.game_type || gameType),
                started_by_id: Number(data?.started_by_id || this._config()?.myParticipantId || 0),
                started_by_name: String(data?.started_by_name || "Player"),
                round_number: 1,
                framework: returnedFramework,
                players: returnedMembers
                    .filter(member => ["master", "player"].includes(String(member?.role || "")))
                    .map(member => ({
                        participant_id: Number(member?.participantId || 0),
                        user_id: Number(member?.userId || 0),
                        display_name: String(member?.displayName || "Player"),
                        avatar_path: String(member?.avatarPath || ""),
                        avatar_url: String(member?.avatarUrl || ""),
                        seat: Number(member?.seat || 0),
                        role: String(member?.role || "player"),
                        accepted: Boolean(member?.accepted),
                        membershipStatus: String(member?.membershipStatus || "active"),
                        reconnectDeadlineAt: member?.reconnectDeadlineAt || null,
                        online: Boolean(member?.online)
                    }))
            }
            : await synchronizeStartedGame();
        if (!startedGame) {
            throw new Error("The newly started game is still synchronizing. Please try Open again.");
        }
        this.#activeGames.set(lobbyCode, startedGame);

        // Choosing the complete rules package and pressing Continue is the
        // creator's one acceptance boundary in both Practice and Recorded play.
        // Record that acceptance against the server-returned settings hash so
        // the embedded lobby never asks the creator to accept the same rules a
        // second time. Every other Recorded player must still accept normally.
        const startedFramework = startedGame.framework || {};
        const starter = (Array.isArray(startedFramework.members) ? startedFramework.members : []).find(member =>
            Number(member.participantId) === Number(this._config()?.myParticipantId)
        );
        if (
            String(startedFramework.status || "") === "lobby"
            && starter?.accepted !== true
            && startedFramework.settingsSha256
        ) {
            const acceptance = await this.#context?.apiPost?.(
                "/api/games.php",
                {
                    action: "accept",
                    session_id: this._config()?.sessionId,
                    participant_id: this._config()?.myParticipantId,
                    join_token: this._config()?.myJoinToken,
                    lobby_code: lobbyCode,
                    settings_sha256: startedFramework.settingsSha256,
                    mode: startedFramework.mode
                }
            );
            if (!this._isFrameworkProjection(acceptance?.framework, lobbyCode)) {
                throw this._contractFailure(
                    "GAME_ACCEPT_RESPONSE_INVALID",
                    "The game acceptance response is incomplete."
                );
            }
            startedGame = Object.assign({}, startedGame, {framework: acceptance.framework});
            this.#activeGames.set(lobbyCode, startedGame);
        }

        return this._presentGame(startedGame);

    }

    /**
     * Accepts the exact current pregame player set and settings.
     *
     * @returns {Promise<void>}
     */
    async acceptGame() {

        const framework = this.#activeGame?.framework || {};
        if (!this.#activeGame?.lobby_code || !framework.settingsSha256) return;
        const lobbyCode = this.#activeGame.lobby_code;

        const response = await this._postGameAction(
            this.#context,
            "/api/games.php",
            {
                action: "accept",
                session_id: this._config()?.sessionId,
                participant_id: this._config()?.myParticipantId,
                join_token: this._config()?.myJoinToken,
                lobby_code: lobbyCode,
                settings_sha256: framework.settingsSha256,
                mode: framework.mode
            }
        );
        if (response?.ok !== true
            || !this._isFrameworkProjection(response.framework, lobbyCode)) {
            throw this._contractFailure(
                "GAME_ACCEPT_RESPONSE_INVALID",
                "The game acceptance response is incomplete."
            );
        }

        await this.loadGames();

    }

    /**
     * Replaces the exact pregame settings as the authenticated Master.
     * The server revalidates the extension-owned settings and invalidates all
     * prior player acceptances. This client owns no game-rule decisions.
     */
    async updateGameSettings(settings = {}) {

        if (!this.#activeGame?.lobby_code) {
            throw new Error("Open a game before changing its rules.");
        }

        const result = await this.runFrameworkAction("update-settings", {settings});
        return result;

    }

    /**
     * Runs one authenticated shared-framework lifecycle action.
     *
     * @param {string} action
     * @param {Object} payload
     * @returns {Promise<Object>}
     */
    async runFrameworkAction(action, payload = {}) {

        if (!this.#activeGame?.lobby_code) {
            throw new Error("Open a game before using game controls.");
        }

        const activeLobbyCode = this.#activeGame.lobby_code;
        if (["save", "resume"].includes(action)) {
            // The embedded game owns authoritative moves and reconnects, so the
            // room shell can legitimately be one projection behind when Save
            // Game is pressed. Refresh before taking the optimistic-version
            // snapshot, while leaving the server's stale-write guard intact.
            await this.loadGames();
            if (!this.#activeGame?.lobby_code || this.#activeGame.lobby_code !== activeLobbyCode) {
                throw new Error("The open game changed before it could be saved.");
            }
        }

        const framework = this.#activeGame.framework || {};
        const sharedExtensionAction = action === "pause-game";
        const apiAction = sharedExtensionAction ? "extension-action" : action;
        const apiPayload = sharedExtensionAction
            ? {
                action_type: action,
                request_id: `room-${action}-${Date.now()}-${Math.random().toString(36).slice(2)}`,
                payload: payload && typeof payload === "object" && !Array.isArray(payload) ? payload : {}
            }
            : payload;
        const data = await this._postGameAction(
            this.#context,
            "/api/game_framework.php",
            {
                ...apiPayload,
                action: apiAction,
                session_id: this._config()?.sessionId,
                participant_id: this._config()?.myParticipantId,
                join_token: this._config()?.myJoinToken,
                game_session_id: this.#activeGame.lobby_code,
                expected_version: Number(framework.stateVersion || 0)
            }
        );
        if (!this._isFrameworkActionResponse(
            data,
            action,
            activeLobbyCode,
            String(this.#activeGame?.game_type || "")
        )) {
            throw this._contractFailure(
                "GAME_ACTION_RESPONSE_INVALID",
                "The game action response is incomplete."
            );
        }

        // An explicit exit or game switch must win a late rematch response.
        if (action === "rematch" && this.#activeGame?.lobby_code !== activeLobbyCode) return data || {};
        // The consent-bound authenticated room reconciliation owns adoption.
        // Do not join a successor a second time after that adoption.
        await this.loadGames();
        this._refreshActiveStage();
        return data || {};

    }

    /**
     * Returns or changes viewer-local options for the active game.
     */
    async gameOptions(input = null) {

        if (!this.#activeGame?.game_type) throw new Error("Open a game before changing its options.");
        if (input !== null) {
            return this.runFrameworkAction("options", {
                game_key: this.#activeGame.game_type,
                options: input
            });
        }
        const query = new URLSearchParams({
            action: "options",
            session_id: String(this._config()?.sessionId || ""),
            participant_id: String(this._config()?.myParticipantId || ""),
            join_token: String(this._config()?.myJoinToken || ""),
            game_key: this.#activeGame.game_type
        });
        return this._fetchFramework(query, "game-options-read");

    }

    /**
     * Returns viewer-local records for the active game.
     */
    async gameRecords() {

        if (!this.#activeGame?.game_type) throw new Error("Open a game before viewing its records.");
        const query = new URLSearchParams({
            action: "records",
            session_id: String(this._config()?.sessionId || ""),
            participant_id: String(this._config()?.myParticipantId || ""),
            join_token: String(this._config()?.myJoinToken || ""),
            game_key: this.#activeGame.game_type
        });
        return this._fetchFramework(query, "game-records-read");

    }

    /**
     * Rehydrates a compatible saved snapshot into the extension boundary.
     */
    async resumeSavedGame() {

        if (!this.#activeGame?.lobby_code) throw new Error("Open a game before resuming it.");
        return this.runFrameworkAction("resume");

    }

    /**
     * Opens a game lobby.
     *
     * @param {Object} game
     *
     * @returns {Promise<void>}
     */
    async openGame(game, {skipJoin = false} = {}) {

        this.#autoOpenCurrentGameResolved = true;

        const priorActiveGame = this.#activeGame;
        const switchingGames = Boolean(
            priorActiveGame?.lobby_code
            && String(priorActiveGame.lobby_code) !== String(game?.lobby_code || "")
        );
        if (switchingGames) {
            // A joined game can take several catalog polls to become visible.
            // Never leave the prior game's iframe on screen during that wait.
            this.hideGameOverlay();
        }

        const openIntentRevision =
            this.__openGameIntentRevision = Number(this.__openGameIntentRevision || 0) + 1;
        const contextAtOpen = this.#context;
        const isCurrentOpenIntent = () => this.__openGameIntentRevision === openIntentRevision
            && this.#context === contextAtOpen;

        const terminalResult =
            ["completed", "forfeited", "abandoned"].includes(String(game?.framework?.status || ""));
        if (terminalResult) {
            if (!isCurrentOpenIntent()) return false;
            return this._presentGame(game);
        }

        try {

            if (!skipJoin) {
                await this.#context?.apiPost?.(
                    "/api/games.php",
                    {
                        action: "join",
                        session_id: this._config()?.sessionId,
                        participant_id: this._config()?.myParticipantId,
                        join_token: this._config()?.myJoinToken,
                        lobby_code: game.lobby_code
                    }
                );
            }

            if (!isCurrentOpenIntent()) return false;
            let projectedGame = null;
            for (let attempt = 0; attempt < 6; attempt += 1) {
                const loaded = await this.loadGames();
                if (!isCurrentOpenIntent()) return false;
                projectedGame = this.#activeGames.get(game.lobby_code) || null;
                if (loaded && projectedGame) break;
                if (attempt < 5) {
                    await new Promise(resolve =>
                        this.#context?.window?.setTimeout?.(resolve, 350 + (attempt * 200))
                    );
                    if (!isCurrentOpenIntent()) return false;
                }
            }
            if (!projectedGame) {
                throw new Error("The joined game is still synchronizing. It will reopen automatically.");
            }

            this.#activeGame = Object.assign({}, projectedGame);

        } catch (error) {
            if (!isCurrentOpenIntent()) return false;

            this.#context?.warnError?.(
                error
            );
            if (priorActiveGame && !switchingGames) {
                this._presentGame(priorActiveGame);
            } else {
                this.hideGameOverlay();
            }
            return false;

        }

        if (!isCurrentOpenIntent()) return false;
        return this._presentGame(this.#activeGame);

    }

    /**
     * Hides the active game overlay.
     */
    hideGameOverlay() {
        this.__openGameIntentRevision = Number(this.__openGameIntentRevision || 0) + 1;

        this.__gamesLoadRevision = Number(this.__gamesLoadRevision || 0) + 1;
        if (this.#recoveryTimer) {
            this.#context?.window?.clearTimeout?.(this.#recoveryTimer);
            this.#recoveryTimer = 0;
        }
        this.#runtime?.webcams?.hide?.("game-hidden");
        this.#context?.stopGameChatPolling?.();
        this.#context?.stopGameTypingNow?.();
        this.#activeGame = null;
        this.#stage?.hideStage();

        if (String(this.#context?.activeChatKey?.() || "").startsWith("game:")) {

            this.#context?.switchChat?.(
                "room"
            );

        }

        this.#context?.renderLinkTabs?.();

    }

    /**
     * Closes the active game lobby.
     *
     * @param {string|null} lobbyCode
     * @param {boolean} notifyServer
     *
     * @returns {Promise<void>}
     */
    async closeGame(lobbyCode = this.#activeGame?.lobby_code, notifyServer = true) {

        const closeIntentRevision =
            this.__openGameIntentRevision = Number(this.__openGameIntentRevision || 0) + 1;
        const contextAtClose = this.#context;
        const pendingCloseIntent = { revision: closeIntentRevision, context: contextAtClose };
        this.__pendingCloseGameIntent = pendingCloseIntent;
        this.__gamesLoadRevision = Number(this.__gamesLoadRevision || 0) + 1;
        const isCurrentCloseIntent = () => this.__openGameIntentRevision === closeIntentRevision
            && this.#context === contextAtClose;

        const chatPausedForClose = Boolean(notifyServer && lobbyCode
            && String(this.#activeGame?.lobby_code || "") === String(lobbyCode));

        try {
            if (chatPausedForClose) contextAtClose?.stopGameChatPolling?.();
            if (lobbyCode && notifyServer) {
                try {
                    const response = await this._postGameAction(
                        contextAtClose,
                        "/api/games.php",
                        {
                            action: "close",
                            session_id: this._config()?.sessionId,
                            participant_id: this._config()?.myParticipantId,
                            join_token: this._config()?.myJoinToken,
                            lobby_code: lobbyCode
                        }
                    );
                    if (!this._isCloseResponse(response)) {
                        throw this._contractFailure(
                            "GAME_CLOSE_RESPONSE_INVALID",
                            response?.error || "The game exit response is incomplete."
                        );
                    }
                } catch (error) {
                    const exitError = new Error(
                        `Exit Game failed. ${error?.message || "Please try again."}`
                    );
                    exitError.code = error?.code || "GAME_CLOSE_FAILED";
                    if (isCurrentCloseIntent()) {
                        if (chatPausedForClose
                            && String(this.#activeGame?.lobby_code || "") === String(lobbyCode)) {
                            contextAtClose?.startGameChatPolling?.();
                        }
                        contextAtClose?.warnError?.(exitError);
                        contextAtClose?.window?.alert?.(exitError.message);
                    }
                    throw exitError;
                }
            }

            if (!isCurrentCloseIntent()) return true;
            if (lobbyCode) {
                // Remove only the authoritatively closed lobby in this intent.
                this.#activeGames.delete(String(lobbyCode));
                this.__gamesLoadRevision = Number(this.__gamesLoadRevision || 0) + 1;
                this.#lastLoadCount = this.#activeGames.size;
                this.#stage?.renderGameList(
                    [...this.#activeGames.values()],
                    this._buildStageContext()
                );
            }
            if (this.__pendingCloseGameIntent === pendingCloseIntent) {
                this.__pendingCloseGameIntent = null;
            }
            if (!lobbyCode || !this.#activeGame
                || String(this.#activeGame.lobby_code) === String(lobbyCode)) {
                this.hideGameOverlay();
            }
            await this.loadGames();
            return true;
        } finally {
            if (this.__pendingCloseGameIntent === pendingCloseIntent) {
                this.__pendingCloseGameIntent = null;
            }
        }

    }

    /**
     * Refreshes games after a room event.
     *
     * @returns {Promise<void>}
     */
    refreshFromRoomEvent(payload = {}, event = {}) {
        // Reconcile first so a naturally completed result remains visible.
        // Explicit exits still revoke access server-side and are discarded by
        // the normal terminal-session probe.
        return this.loadGames();

    }

    /**
     * Synchronizes game stage visibility with active chat.
     */
    setLayerVisibility() {

        Promise.resolve().then(() => this.#runtime?.webcams?.reconcile?.({ reason: "game-layer-visibility" }));

        this.#stage?.setLayerVisibility(
            Boolean(
                this.#activeGame &&
                this.#context?.activeChatKey?.() === this.#context?.gameChatKey?.(
                    this.#activeGame.lobby_code
                )
            ),
            {
                gameKey: this.#activeGame?.game_type || null,
                lobbyCode: this.#activeGame?.lobby_code || null,
                sizing: this.#activeGame
                    ? this.gamePresentationSizing(this.#activeGame.game_type)
                    : null,
                reason: "selected-room-surface"
            }
        );

    }

    /**
     * Renders the active game stage player cards.
     */
    updateStagePlayers() {

        if (!this.#activeGame) return;

        this.#stage?.renderStagePlayers(
            this.#activeGame,
            this._buildStageContext()
        );

    }

    /**
     * Sends a control message to the active game iframe.
     *
     * @param {string} action
     */
    sendStageControl(action) {

        this.#stage?.postControl(
            action
        );

    }

    //--------------------------------------------------
    // Public Diagnostics
    //--------------------------------------------------

    /**
     * Returns game lifecycle diagnostics.
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

            active:
                Boolean(this.#activeGame),

            activeLobbyCode:
                this.#activeGame?.lobby_code || null,

            activeGameCount:
                this.#activeGames.size,

            lastLoadCount:
                this.#lastLoadCount,

            catalogCount:
                this.#catalog.size

        });

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    async _fetchFramework(query, requestPurpose) {
        const contextAtRequest = this.#context;
        const lobbyAtRequest = String(this.#activeGame?.lobby_code || "");
        const gameType = String(this.#activeGame?.game_type || "");
        const loadGeneration = Number(this.__gamesLoadRevision || 0);
        const openGeneration = Number(this.__openGameIntentRevision || 0);
        const requestOrdinal = ++this.#frameworkRequestOrdinal;
        const requestContext = () => ({
            gameType,
            requestPurpose,
            requestOrdinal,
            loadGeneration,
            openGeneration,
            ownerCurrentAtOutcome: this.#context === contextAtRequest
                && this.__openGameIntentRevision === openGeneration
                && String(this.#activeGame?.lobby_code || "") === lobbyAtRequest
                && (requestPurpose !== "terminal-session-probe"
                    || this.__gamesLoadRevision === loadGeneration),
            sameGameAtOutcome: this.#context === contextAtRequest
                && String(this.#activeGame?.lobby_code || "") === lobbyAtRequest,
        });
        const fetchFramework = contextAtRequest?.fetchFramework;
        if (typeof fetchFramework !== "function") {
            throw this._contractFailure(
                "GAME_FRAMEWORK_PROVIDER_UNAVAILABLE",
                "The game framework provider is unavailable."
            );
        }
        const response = await fetchFramework.call(contextAtRequest, query, { requestContext });
        const valid = requestPurpose === "terminal-session-probe"
            ? this._isFrameworkProjection(response, lobbyAtRequest)
            : requestPurpose === "game-options-read"
                ? this._isGameOptionsProjection(response, gameType)
                : requestPurpose === "game-records-read"
                    ? this._isGameRecordsProjection(response, gameType)
                    : this._isResponseObject(response);
        if (!valid) {
            throw this._contractFailure(
                "GAME_FRAMEWORK_RESPONSE_INVALID",
                "The game framework response is incomplete."
            );
        }
        return response;
    }

    _contractFailure(code, message) {

        const error = new TypeError(message);
        error.code = code;
        return error;

    }

    _isResponseObject(value) {

        return Boolean(value)
            && typeof value === "object"
            && !Array.isArray(value)
            && Object.keys(value).length > 0
            && !(typeof value.error === "string" && value.error.trim() !== "");

    }

    _postGameAction(context, endpoint, payload) {

        const apiPost = context?.apiPost;
        if (typeof apiPost !== "function") {
            return Promise.reject(this._contractFailure(
                "GAME_ACTION_PROVIDER_UNAVAILABLE",
                "The room game action provider is unavailable."
            ));
        }
        return apiPost.call(context, endpoint, payload);

    }

    _isGameOptionsProjection(value, gameType) {

        return this._isResponseObject(value)
            && value.gameKey === gameType
            && Number.isFinite(Number(value.masterVolume))
            && typeof value.musicEnabled === "boolean"
            && typeof value.voiceEnabled === "boolean"
            && typeof value.effectsEnabled === "boolean"
            && value.categories !== null
            && typeof value.categories === "object"
            && value.individual !== null
            && typeof value.individual === "object";

    }

    _isGameRecordsProjection(value, gameType) {

        return this._isResponseObject(value)
            && value.gameKey === gameType
            && value.lifetime !== null
            && typeof value.lifetime === "object"
            && value.recordClasses !== null
            && typeof value.recordClasses === "object"
            && Array.isArray(value.opponents)
            && Array.isArray(value.results);

    }

    _isFrameworkActionResponse(value, action, lobbyCode, gameType) {

        if (!this._isResponseObject(value)) return false;
        if (["start", "update-settings", "set-lobby-bot"].includes(action)) {
            return this._isFrameworkProjection(value, lobbyCode);
        }
        if (action === "pause-game") {
            return value.ok === true
                && Number.isSafeInteger(Number(value.version))
                && this._isFrameworkProjection(value.session, lobbyCode);
        }
        if (action === "options") return this._isGameOptionsProjection(value, gameType);
        if (action === "save") {
            return value.saved === true
                && value.gameKey === gameType
                && Number.isSafeInteger(Number(value.stateVersion));
        }
        if (action === "resume") {
            return value.restored === true
                && value.gameKey === gameType
                && Number.isSafeInteger(Number(value.stateVersion))
                && ["active", "paused"].includes(String(value.status || ""));
        }
        if (action === "vote") {
            return typeof value.voteType === "string"
                && value.voteType !== ""
                && typeof value.vote === "string"
                && value.vote !== ""
                && value.counts !== null
                && typeof value.counts === "object"
                && Number.isSafeInteger(Number(value.required))
                && Number(value.required) > 0
                && (value.resolved === null || typeof value.resolved === "string");
        }
        if (action === "rematch") {
            if (!["waiting", "started"].includes(String(value.status || ""))) return false;
            if (!Number.isSafeInteger(Number(value.required)) || Number(value.required) < 1) return false;
            return value.status !== "started"
                || this._isFrameworkProjection(value.session, String(value.session?.publicId || ""));
        }
        if (action === "forfeit") {
            return ["active", "completed", "forfeited"].includes(String(value.status || ""))
                && Number.isSafeInteger(Number(value.forfeitedByUserId))
                && Number(value.forfeitedByUserId) > 0;
        }
        return true;

    }

    _isCloseResponse(value) {

        return this._isResponseObject(value)
            && value.ok === true
            && value.departed === true
            && typeof value.ended === "boolean"
            && value.event === (value.ended ? "game_end" : "game_update");

    }

    _isFrameworkProjection(value, lobbyCode) {

        if (!value || typeof value !== "object" || Array.isArray(value)) return false;
        const version = value.stateVersion;
        const numericVersion = Number(version);
        return typeof value.publicId === "string"
            && value.publicId === lobbyCode
            && typeof value.gameKey === "string"
            && value.gameKey.trim() !== ""
            && Array.isArray(value.members)
            && value.members.every(member => member && typeof member === "object" && !Array.isArray(member))
            && version !== null
            && version !== ""
            && Number.isSafeInteger(numericVersion)
            && numericVersion >= 0;

    }

    _isRoomGameProjection(value) {

        return Boolean(value)
            && typeof value === "object"
            && !Array.isArray(value)
            && typeof value.lobby_code === "string"
            && value.lobby_code.trim() !== ""
            && typeof value.game_type === "string"
            && value.game_type.trim() !== ""
            && Array.isArray(value.players)
            && this._isFrameworkProjection(value.framework, value.lobby_code);

    }

    _config() {

        return this.#context?.getConfig?.() || {};

    }

    _fetchGames({ includeCatalog = false } = {}) {

        const qs =
            new URLSearchParams({
                session_id: this._config()?.sessionId,
                participant_id: this._config()?.myParticipantId,
                join_token: this._config()?.myJoinToken
            });
    if (!includeCatalog) qs.set('view', 'room');

        const fetchGames = this.#context?.fetchGames;
        if (typeof fetchGames !== "function") {
            return Promise.reject(this._contractFailure(
                "GAME_LIST_PROVIDER_UNAVAILABLE",
                "The room game list provider is unavailable."
            ));
        }
        return fetchGames.call(this.#context, qs);

    }

    _scheduleRecovery(delayMs = 1800) {

        if (this.#recoveryTimer) return;
        this.#recoveryTimer = this.#context?.window?.setTimeout?.(() => {
            this.#recoveryTimer = 0;
            this.loadGames().catch(error => {
                this.#context?.warnError?.(error);
                this._scheduleRecovery();
            });
        }, Math.max(100, Number(delayMs || 1800))) || 0;

    }

    _refreshActiveStage() {

        if (!this.#activeGame?.lobby_code) return;
        this.#activeGame = Object.assign(
            this.#activeGame,
            this.#activeGames.get(this.#activeGame.lobby_code) || {}
        );
        this.#stage?.showStage(this.#activeGame, this._buildStageContext());
        this.updateStagePlayers();

    }

    _presentGame(game) {

        if (!game?.lobby_code) return false;
        this.#activeGame = Object.assign({}, game);
        this.#stage?.showStage(this.#activeGame, this._buildStageContext());
        this.updateStagePlayers();
        this.#context?.switchChat?.(
            this.#context?.gameChatKey?.(this.#activeGame.lobby_code)
        );
        this.setLayerVisibility();
        this.#context?.renderLinkTabs?.();
        this.#context?.startGameChatPolling?.();
        this.#context?.onGameOpened?.(this.#activeGame);
        return true;

    }

    _buildStageContext() {

        return Object.freeze({

            activeGame:
                this.#activeGame,

            activeGames:
                this.#activeGames,

            myParticipantId:
                Number(this._config()?.myParticipantId),

            gameName:
                type => this.gameName(type),

            gameIconUrl:
                type => this.gameIconUrl(type),

            gameFrameUrl:
                game => this.gameFrameUrl(game),

            gameSeatRole:
                (type, seat) => this.gameSeatRole(type, seat),

            gameMaxPlayers:
                type => this.gameMaxPlayers(type),

            openGame:
                game => this.openGame(game),

            acceptGame:
                () => this.acceptGame(),

            updateGameSettings:
                settings => this.updateGameSettings(settings),

            runFrameworkAction:
                (action, payload) => this.runFrameworkAction(action, payload),

            resumeSavedGame:
                () => this.resumeSavedGame(),

            openGameRecords:
                () => this.#context?.openGameRecords?.()

        });

    }

}

export default GameLifecycleService;
