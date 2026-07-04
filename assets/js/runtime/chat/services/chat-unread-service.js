/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      chat-unread-service.js
 *
 * Layer:
 *      Runtime Service
 *
 * Owner:
 *      Chat Runtime
 *
 * Purpose:
 *      Owns chat unread-count orchestration.
 *
 *      ChatUnreadService coordinates unread-count changes for chat channels
 *      using ChatMessageStateService as the authoritative state provider. It
 *      owns unread clear/increment decisions while leaving tab DOM rendering,
 *      chat switching, message rendering, polling, and composer workflows
 *      outside its ownership.
 *
 * Build:
 *      000022-E
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000022-E
 * - Introduced Chat Unread Service.
 * - Transferred unread clear orchestration from room.js.
 * - Transferred inactive live-message unread increment decisions from room.js.
 ******************************************************************************/

/**
 * @file chat-unread-service.js
 *
 * Defines the Chat Unread Service.
 */

//
// No imports required.
//

//--------------------------------------------------
// Chat Unread Service
//--------------------------------------------------

/**
 * Owns chat unread-count orchestration.
 */
export class ChatUnreadService {

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
     * Unread context supplied by the room composition root.
     *
     * @type {Object}
     */
    #context = null;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Chat Unread Service.
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
     * Releases unread-owned references.
     */
    destroy() {

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
     * Configures unread extension points.
     *
     * @param {Object} context
     */
    configure(context = {}) {

        this.#context = context;

    }

    //--------------------------------------------------
    // Public Unread API
    //--------------------------------------------------

    /**
     * Returns unread count for a channel.
     *
     * @param {string} chatKey
     *
     * @returns {number}
     */
    unreadCountFor(chatKey) {

        return this.#runtime.messages.unreadCountFor(
            chatKey
        );

    }

    /**
     * Clears unread count for a channel and refreshes presentation.
     *
     * @param {string} chatKey
     */
    clear(chatKey) {

        this.#runtime.messages.clearUnread(
            chatKey
        );

        this.#refreshBadges();

    }

    /**
     * Records a live message for an inactive channel.
     *
     * @param {Object} message
     * @param {string} chatKey
     * @param {Object} options
     *
     * @returns {number}
     *          Current unread count after the decision.
     */
    recordInactiveLiveMessage(message, chatKey, options = {}) {

        if (!options.live) {
            return this.unreadCountFor(chatKey);
        }

        const context =
            this.#requireContext();

        const config =
            context.getConfig();

        if (
            message?.user_id === config.myUserId ||
            message?.participant_id === config.myParticipantId
        ) {
            return this.unreadCountFor(chatKey);
        }

        const count =
            this.#runtime.messages.incrementUnread(
                chatKey
            );

        this.#refreshBadges();

        return count;

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
                "000022-E",

            configured:
                Boolean(this.#context)

        });

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Refreshes unread presentation through the composition root.
     */
    #refreshBadges() {

        this.#context?.refreshUnreadBadges?.();

    }

    /**
     * Returns configured unread context.
     *
     * @returns {Object}
     */
    #requireContext() {

        if (!this.#context) {
            throw new Error("ChatUnreadService context has not been configured.");
        }

        return this.#context;

    }

}

export default ChatUnreadService;
