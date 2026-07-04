/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      chat-game-chat-service.js
 *
 * Layer:
 *      Runtime Service
 *
 * Owner:
 *      Chat Runtime
 *
 * Purpose:
 *      Owns game chat integration behavior.
 *
 *      ChatGameChatService owns game chat key resolution, outgoing game chat
 *      message sending, incoming game chat message routing, game chat polling
 *      state, game chat last-id tracking, and game-specific typing state. It
 *      leaves game lifecycle, game stage presentation, game catalog loading,
 *      and general room polling outside its ownership.
 *
 * Build:
 *      000022-K
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000022-K
 * - Introduced Chat Game Chat Service.
 * - Transferred game chat integration ownership from room.js.
 ******************************************************************************/

/**
 * @file chat-game-chat-service.js
 *
 * Defines the Chat Game Chat Service.
 */

//
// No imports required.
//

//--------------------------------------------------
// Chat Game Chat Service
//--------------------------------------------------

/**
 * Owns game chat integration behavior.
 */
export class ChatGameChatService {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    /**
     * Owning Chat Runtime.
     *
     * @type {ChatRuntime}
     */
    #runtime;

    /**
     * Game chat context supplied by the room composition root.
     *
     * @type {Object}
     */
    #context = null;

    /**
     * Last known message id by game lobby code.
     *
     * @type {Map<string,number>}
     */
    #lastIds = new Map();

    /**
     * Participant ids currently typing in the active game.
     *
     * @type {Set<number>}
     */
    #typingIds = new Set();

    /**
     * Typing expiry timers by participant id.
     *
     * @type {Map<number,number>}
     */
    #typingTimers = new Map();

    /**
     * Current game chat poll timer.
     *
     * @type {number|null}
     */
    #pollTimer = null;

    /**
     * Whether the current participant is actively typing in game chat.
     *
     * @type {boolean}
     */
    #typingActive = false;

    /**
     * Current local game typing stop timer.
     *
     * @type {number|null}
     */
    #typingStopTimer = null;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Chat Game Chat Service.
     *
     * @param {ChatRuntime} runtime
     *        Owning Chat Runtime.
     */
    constructor(runtime) {

        this.#runtime = runtime;

    }

    //--------------------------------------------------
    // Public Lifecycle
    //--------------------------------------------------

    /**
     * Participates in the runtime lifecycle.
     */
    initialize() {

    }

    /**
     * Releases game chat state and references.
     */
    destroy() {

        this.reset();

        this.#lastIds.clear();

        this.#context = null;

    }

    //--------------------------------------------------
    // Public Getters
    //--------------------------------------------------

    /**
     * Returns the owning Chat Runtime.
     *
     * @returns {ChatRuntime}
     */
    get runtime() {

        return this.#runtime;

    }

    //--------------------------------------------------
    // Public Configuration
    //--------------------------------------------------

    /**
     * Configures game chat extension points.
     *
     * @param {Object} context
     */
    configure(context = {}) {

        this.#context = context;

    }

    //--------------------------------------------------
    // Public Game Chat API
    //--------------------------------------------------

    /**
     * Returns the chat key for a game lobby.
     *
     * @param {string|null} lobbyCode
     *
     * @returns {string}
     */
    chatKey(lobbyCode = null) {

        const activeGame =
            this.#context?.getActiveGame?.();

        const lobby =
            lobbyCode || activeGame?.lobby_code;

        return lobby ? `game:${lobby}` : "game:";

    }

    /**
     * Returns whether a participant is typing in the active game chat.
     *
     * @param {number|string} participantId
     *
     * @returns {boolean}
     */
    isTyping(participantId) {

        return this.#typingIds.has(
            Number(participantId)
        );

    }

    /**
     * Routes a game message into the game chat channel.
     *
     * @param {Object} message
     * @param {boolean} live
     *
     * @returns {boolean}
     */
    addMessage(message, live = false) {

        const context =
            this.#requireContext();

        const activeGame =
            context.getActiveGame();

        if (!activeGame || message?.lobby_code !== activeGame.lobby_code) {
            return false;
        }

        context.addMessageToChannel(
            message,
            this.chatKey(message.lobby_code),
            live
        );

        return true;

    }

    /**
     * Sends a game chat text message.
     *
     * @param {string} content
     *
     * @returns {Promise<Object|null>}
     */
    async sendMessage(content) {

        const context =
            this.#requireContext();

        const activeGame =
            context.getActiveGame();

        if (!activeGame) return null;

        const config =
            context.getConfig();

        const message =
            await context.apiPost(
                "/api/game_chat.php",
                {
                    action:
                        "message",

                    session_id:
                        config.sessionId,

                    join_token:
                        config.myJoinToken,

                    lobby_code:
                        activeGame.lobby_code,

                    content
                }
            );

        this.addMessage(
            message,
            false
        );

        return message;

    }

    /**
     * Stops game chat polling and clears game typing presentation state.
     */
    reset() {

        this.#clearPollTimer();

        this.#clearTypingStopTimer();

        this.#typingTimers.forEach(
            timer => clearTimeout(timer)
        );

        this.#typingTimers.clear();

        this.#typingIds.clear();

        this.#typingActive = false;

        this.#context?.renderGameStagePlayers?.();

    }

    /**
     * Applies game typing state for a participant.
     *
     * @param {number|string} participantId
     * @param {boolean} active
     */
    setTyping(participantId, active) {

        const id =
            Number(participantId);

        if (!id) return;

        clearTimeout(
            this.#typingTimers.get(id)
        );

        this.#typingTimers.delete(
            id
        );

        if (active) {

            this.#typingIds.add(
                id
            );

            this.#typingTimers.set(
                id,
                setTimeout(
                    () => this.setTyping(id, false),
                    3500
                )
            );

        } else {

            this.#typingIds.delete(
                id
            );

        }

        this.#requireContext().renderGameStagePlayers?.();

    }

    /**
     * Polls the active game's chat endpoint once and schedules the next poll.
     *
     * @returns {Promise<void>}
     */
    async poll() {

        const context =
            this.#requireContext();

        const activeGame =
            context.getActiveGame();

        if (!activeGame) return;

        const lobby =
            activeGame.lobby_code;

        const config =
            context.getConfig();

        const last =
            this.#lastIds.get(lobby) || 0;

        try {

            const query =
                new URLSearchParams({

                    session_id:
                        config.sessionId,

                    join_token:
                        config.myJoinToken,

                    lobby_code:
                        lobby,

                    since_id:
                        String(last)

                });

            const data =
                await context.fetchGameChat(
                    query
                );

            if (data.error) {
                throw new Error(data.error);
            }

            (data.messages || []).forEach(
                message => {

                    this.#lastIds.set(
                        lobby,
                        Math.max(
                            this.#lastIds.get(lobby) || 0,
                            Number(message.id)
                        )
                    );

                    this.addMessage(
                        message,
                        true
                    );

                }
            );

            if ((data.typing || []).length) {

                (data.typing || []).forEach(
                    participantId => this.setTyping(participantId, true)
                );

            }

        } catch (error) {

            context.warnError?.(
                error
            );

        } finally {

            if (context.getActiveGame()?.lobby_code === lobby) {

                this.#pollTimer =
                    setTimeout(
                        () => this.poll(),
                        900
                    );

            }

        }

    }

    /**
     * Starts game chat polling for the active game.
     */
    startPolling() {

        const context =
            this.#requireContext();

        const activeGame =
            context.getActiveGame();

        if (!activeGame) return;

        this.reset();

        this.#lastIds.set(
            activeGame.lobby_code,
            Math.max(
                this.#lastIds.get(activeGame.lobby_code) || 0,
                0
            )
        );

        this.poll();

    }

    /**
     * Sends game typing state for the active game.
     *
     * @param {boolean} active
     *
     * @returns {Promise<Object|void>}
     */
    sendTyping(active) {

        const context =
            this.#requireContext();

        const activeGame =
            context.getActiveGame();

        if (!activeGame) {
            return Promise.resolve();
        }

        const config =
            context.getConfig();

        return context.apiPost(
            "/api/game_chat.php",
            {
                action:
                    "typing",

                session_id:
                    config.sessionId,

                join_token:
                    config.myJoinToken,

                lobby_code:
                    activeGame.lobby_code,

                active
            }
        ).catch(
            () => {}
        );

    }

    /**
     * Stops the current participant game typing state immediately.
     */
    stopTypingNow() {

        this.#clearTypingStopTimer();

        if (!this.#typingActive) {
            return;
        }

        this.#typingActive = false;

        this.sendTyping(
            false
        );

    }

    /**
     * Processes composer input for active game chat typing.
     */
    handleTypingInput() {

        const context =
            this.#requireContext();

        if (!context.getActiveGame()) return;

        if (!this.#typingActive) {

            this.#typingActive = true;

            this.sendTyping(
                true
            );

        }

        this.#clearTypingStopTimer();

        this.#typingStopTimer =
            setTimeout(
                () => this.stopTypingNow(),
                1200
            );

    }

    //--------------------------------------------------
    // Public Diagnostics
    //--------------------------------------------------

    /**
     * Returns service diagnostic information.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        return Object.freeze({

            owner:
                "ChatRuntime",

            build:
                "000022-K",

            configured:
                Boolean(this.#context),

            trackedLobbies:
                this.#lastIds.size,

            typingParticipants:
                this.#typingIds.size,

            hasPollTimer:
                Boolean(this.#pollTimer),

            typingActive:
                this.#typingActive

        });

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Clears the active game chat poll timer.
     */
    #clearPollTimer() {

        if (this.#pollTimer) {
            clearTimeout(this.#pollTimer);
        }

        this.#pollTimer = null;

    }

    /**
     * Clears the local game typing stop timer.
     */
    #clearTypingStopTimer() {

        if (this.#typingStopTimer) {
            clearTimeout(this.#typingStopTimer);
        }

        this.#typingStopTimer = null;

    }

    /**
     * Returns configured game chat context.
     *
     * @returns {Object}
     */
    #requireContext() {

        if (!this.#context) {
            throw new Error("ChatGameChatService context has not been configured.");
        }

        return this.#context;

    }

}

export default ChatGameChatService;
