/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      chat-typing-service.js
 *
 * Layer:
 *      Runtime Service
 *
 * Owner:
 *      Chat Runtime
 *
 * Purpose:
 *      Owns non-game chat typing workflow behavior.
 *
 *      ChatTypingService owns non-game typing active state, outgoing typing
 *      payload construction, typing API execution, typing stop timers, and
 *      non-game typing presentation coordination. It leaves composer DOM
 *      events, game chat typing, polling transport, and avatar DOM rendering
 *      outside its ownership.
 *
 * Build:
 *      000022-H
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000022-H
 * - Introduced Chat Typing Service.
 * - Transferred non-game typing workflow ownership from room.js.
 ******************************************************************************/

/**
 * @file chat-typing-service.js
 *
 * Defines the Chat Typing Service.
 */

//
// No imports required.
//

//--------------------------------------------------
// Chat Typing Service
//--------------------------------------------------

/**
 * Owns non-game chat typing workflow behavior.
 */
export class ChatTypingService {

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
     * Typing context supplied by the room composition root.
     *
     * @type {Object}
     */
    #context = null;

    /**
     * Whether the current participant is actively typing.
     *
     * @type {boolean}
     */
    #typingActive = false;

    /**
     * Current local typing stop timer.
     *
     * @type {number|null}
     */
    #typingStopTimer = null;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Chat Typing Service.
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
     * Releases typing-owned state and references.
     */
    destroy() {

        this.#clearStopTimer();

        this.#typingActive = false;

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
     * Configures typing extension points.
     *
     * @param {Object} context
     */
    configure(context = {}) {

        this.#context = context;

    }

    //--------------------------------------------------
    // Public Typing API
    //--------------------------------------------------

    /**
     * Processes non-game composer input typing state.
     *
     * @param {string} activeChat
     *
     * @returns {boolean}
     */
    handleComposerInput(activeChat) {

        if (!this.#canOwnTypingForChat(activeChat)) {

            this.stopTypingNow();

            return false;

        }

        const context =
            this.#requireContext();

        if (!this.#typingActive) {

            this.#typingActive = true;

            this.showTyping(
                context.getConfig().myParticipantId,
                true
            );

            this.sendTyping(
                true,
                activeChat
            );

        }

        this.#clearStopTimer();

        this.#typingStopTimer =
            setTimeout(
                () => this.stopTypingNow(),
                1400
            );

        return true;

    }

    /**
     * Stops the current participant typing state immediately.
     */
    stopTypingNow() {

        this.#clearStopTimer();

        if (!this.#typingActive) {
            return;
        }

        const context =
            this.#requireContext();

        this.#typingActive = false;

        this.showTyping(
            context.getConfig().myParticipantId,
            false
        );

        this.sendTyping(
            false,
            context.getActiveChat()
        );

    }

    /**
     * Sends typing state for the supplied non-game chat key.
     *
     * @param {boolean} active
     * @param {string} activeChat
     *
     * @returns {Promise<Object|void>}
     */
    sendTyping(active, activeChat) {

        if (!this.#canSendTypingForChat(activeChat)) {
            return Promise.resolve();
        }

        const context =
            this.#requireContext();

        const config =
            context.getConfig();

        const payload = {

            session_id:
                config.sessionId,

            join_token:
                config.myJoinToken,

            active,

            channel:
                activeChat

        };

        const relationship =
            context.activeRelationshipRequest?.();

        if (relationship) {

            payload.channel =
                "link";

            payload.relationship_id =
                relationship.relationship_id;

            payload.conversation_id =
                relationship.conversation_id;

        }

        return context.apiPost(
            "/api/typing.php",
            payload
        ).catch(
            () => {}
        );

    }

    /**
     * Presents typing state for a participant.
     *
     * @param {number|string} participantId
     * @param {boolean} active
     */
    showTyping(participantId, active) {

        const context =
            this.#requireContext();

        const participants =
            context.getParticipants();

        const participant =
            participants.get(participantId);

        if (!participant || context.isUserBlocked(participant.user_id)) {
            return;
        }

        if (!active) {

            context.syncTyping(
                participant,
                false
            );

            participants.clearTypingTimer(participantId);

            return;

        }

        context.syncTyping(
            participant,
            true
        );

        context.positionAvatar(
            participant
        );

        participants.setTypingTimer(
            participantId,
            setTimeout(
                () => this.showTyping(participantId, false),
                3500
            )
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
                "000022-H",

            configured:
                Boolean(this.#context),

            typingActive:
                this.#typingActive,

            hasStopTimer:
                Boolean(this.#typingStopTimer)

        });

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Returns whether ChatRuntime owns typing for the chat key.
     *
     * @param {string} activeChat
     *
     * @returns {boolean}
     */
    #canOwnTypingForChat(activeChat) {

        const chatKey =
            String(activeChat || "");

        return (
            chatKey !== "community" &&
            !chatKey.startsWith("dm:") &&
            !chatKey.startsWith("game:")
        );

    }

    /**
     * Returns whether typing API updates should be sent for the chat key.
     *
     * @param {string} activeChat
     *
     * @returns {boolean}
     */
    #canSendTypingForChat(activeChat) {

        return this.#canOwnTypingForChat(activeChat);

    }

    /**
     * Clears the local typing stop timer.
     */
    #clearStopTimer() {

        if (this.#typingStopTimer) {
            clearTimeout(this.#typingStopTimer);
        }

        this.#typingStopTimer = null;

    }

    /**
     * Returns configured typing context.
     *
     * @returns {Object}
     */
    #requireContext() {

        if (!this.#context) {
            throw new Error("ChatTypingService context has not been configured.");
        }

        return this.#context;

    }

}

export default ChatTypingService;
