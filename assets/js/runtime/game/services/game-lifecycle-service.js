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

    #lastLoadCount = 0;

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

        this.#activeGame = null;
        this.#activeGames.clear();
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

        this.#context = context;
        this.#stage?.configure(context);

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

        return GAME_CATALOG[type]?.name || type;

    }

    /**
     * Returns the path segment for a game type.
     *
     * @param {string} type
     *
     * @returns {string}
     */
    gamePath(type) {

        return GAME_CATALOG[type]?.path || type;

    }

    /**
     * Returns the icon URL for a game type.
     *
     * @param {string} type
     *
     * @returns {string}
     */
    gameIconUrl(type) {

        return this.#context?.appUrl?.(
            `/assets/images/${GAME_CATALOG[type]?.icon || this.gamePath(type)}-icon.png`
        ) || "";

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
            GAME_CATALOG[game.game_type] || {
                path: game.game_type,
                entry: "index.html",
                gameId: 0
            };

        const myParticipantId =
            Number(this.#config()?.myParticipantId);

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
                csrf: this.#context?.getCsrfToken?.() || ""
            });

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
            GAME_CATALOG[type]?.seats || [];

        return labels[Number(seat) - 1] || `Player ${seat}`;

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
    async loadGames() {

        const data =
            await this.#fetchGames();

        const games =
            Array.isArray(data?.games) ? data.games : [];

        this.#lastLoadCount =
            games.length;

        this.#activeGames.clear();

        games.forEach(game => {

            this.#activeGames.set(
                game.lobby_code,
                game
            );

        });

        this.#stage?.renderGameList(
            games,
            this.#buildStageContext()
        );

        if (this.#activeGame && this.#activeGames.has(this.#activeGame.lobby_code)) {

            this.#activeGame =
                Object.assign(
                    this.#activeGame,
                    this.#activeGames.get(this.#activeGame.lobby_code)
                );

            this.updateStagePlayers();
            this.setLayerVisibility();

        } else if (this.#activeGame) {

            this.hideGameOverlay();

        }

        this.#context?.renderPeople?.();
        this.#context?.renderLinkTabs?.();

    }

    /**
     * Starts a new game and opens its lobby.
     *
     * @param {string} gameType
     *
     * @returns {Promise<void>}
     */
    async startGame(gameType) {

        const data =
            await this.#context?.apiPost?.(
                "/api/games.php",
                {
                    action: "start",
                    session_id: this.#config()?.sessionId,
                    participant_id: this.#config()?.myParticipantId,
                    join_token: this.#config()?.myJoinToken,
                    game_type: gameType
                }
            );

        await this.loadGames();

        await this.openGame({
            game_type: gameType,
            lobby_code: data?.lobby_code,
            started_by_name: "You"
        });

    }

    /**
     * Opens a game lobby.
     *
     * @param {Object} game
     *
     * @returns {Promise<void>}
     */
    async openGame(game) {

        this.#activeGame =
            Object.assign(
                {},
                game
            );

        try {

            await this.#context?.apiPost?.(
                "/api/games.php",
                {
                    action: "join",
                    session_id: this.#config()?.sessionId,
                    participant_id: this.#config()?.myParticipantId,
                    join_token: this.#config()?.myJoinToken,
                    lobby_code: game.lobby_code
                }
            );

            await this.loadGames();

            this.#activeGame =
                Object.assign(
                    this.#activeGame || {},
                    this.#activeGames.get(game.lobby_code) || game
                );

        } catch (error) {

            this.#context?.warnError?.(
                error
            );

        }

        this.#stage?.showStage(
            this.#activeGame,
            this.#buildStageContext()
        );

        this.updateStagePlayers();
        this.#context?.renderLinkTabs?.();
        this.#context?.switchChat?.(
            this.#context?.gameChatKey?.(
                this.#activeGame?.lobby_code
            )
        );
        this.#context?.startGameChatPolling?.();

    }

    /**
     * Hides the active game overlay.
     */
    hideGameOverlay() {

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

        if (lobbyCode && notifyServer) {

            const request =
                this.#context?.apiPost?.(
                    "/api/games.php",
                    {
                        action: "close",
                        session_id: this.#config()?.sessionId,
                        participant_id: this.#config()?.myParticipantId,
                        join_token: this.#config()?.myJoinToken,
                        lobby_code: lobbyCode
                    }
                ) ?? Promise.resolve();

            await request.catch(error => this.#context?.warnError?.(error));

        }

        this.hideGameOverlay();
        await this.loadGames();

    }

    /**
     * Refreshes games after a room event.
     *
     * @returns {Promise<void>}
     */
    refreshFromRoomEvent() {

        return this.loadGames();

    }

    /**
     * Synchronizes game stage visibility with active chat.
     */
    setLayerVisibility() {

        this.#stage?.setLayerVisibility(
            Boolean(
                this.#activeGame &&
                this.#context?.activeChatKey?.() === this.#context?.gameChatKey?.(
                    this.#activeGame.lobby_code
                )
            )
        );

    }

    /**
     * Renders the active game stage player cards.
     */
    updateStagePlayers() {

        if (!this.#activeGame) return;

        this.#stage?.renderStagePlayers(
            this.#activeGame,
            this.#buildStageContext()
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
                this.#lastLoadCount

        });

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    #config() {

        return this.#context?.getConfig?.() || {};

    }

    #fetchGames() {

        const qs =
            new URLSearchParams({
                session_id: this.#config()?.sessionId,
                participant_id: this.#config()?.myParticipantId,
                join_token: this.#config()?.myJoinToken
            });

        return this.#context?.fetchGames?.(
            qs
        )?.catch(() => ({ games: [] })) ?? Promise.resolve({ games: [] });

    }

    #buildStageContext() {

        return Object.freeze({

            activeGame:
                this.#activeGame,

            activeGames:
                this.#activeGames,

            myParticipantId:
                Number(this.#config()?.myParticipantId),

            gameName:
                type => this.gameName(type),

            gameIconUrl:
                type => this.gameIconUrl(type),

            gameFrameUrl:
                game => this.gameFrameUrl(game),

            gameSeatRole:
                (type, seat) => this.gameSeatRole(type, seat),

            openGame:
                game => this.openGame(game)

        });

    }

}

export default GameLifecycleService;
