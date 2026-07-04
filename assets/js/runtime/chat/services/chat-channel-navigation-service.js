/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      chat-channel-navigation-service.js
 *
 * Layer:
 *      Runtime Service
 *
 * Owner:
 *      Chat Runtime
 *
 * Purpose:
 *      Owns active chat/channel navigation state and switching decisions.
 *
 *      ChatChannelNavigationService owns the active chat key and coordinates
 *      channel switching side effects through application callbacks while
 *      leaving tab DOM presentation, game lifecycle, media UI, and message
 *      action presentation outside its ownership.
 *
 * Build:
 *      000022-M
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000022-M
 * - Introduced Chat Channel Navigation Service.
 * - Transferred active chat ownership and switching decisions from room.js.
 ******************************************************************************/

/**
 * @file chat-channel-navigation-service.js
 *
 * Defines the Chat Channel Navigation Service.
 */

//
// No imports required.
//

//--------------------------------------------------
// Constants
//--------------------------------------------------

const DEFAULT_CHAT_KEY = "room";

//--------------------------------------------------
// Chat Channel Navigation Service
//--------------------------------------------------

/**
 * Owns active chat/channel navigation.
 */
export class ChatChannelNavigationService {

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
     * Navigation context supplied by the room composition root.
     *
     * @type {Object}
     */
    #context = null;

    /**
     * Active chat key.
     *
     * @type {string}
     */
    #activeChat = DEFAULT_CHAT_KEY;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Chat Channel Navigation Service.
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
     * Releases navigation-owned references.
     */
    destroy() {

        this.#context = null;
        this.#activeChat = DEFAULT_CHAT_KEY;

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
     * Configures navigation extension points.
     *
     * @param {Object} context
     */
    configure(context = {}) {

        this.#context = context;

    }

    //--------------------------------------------------
    // Public Navigation API
    //--------------------------------------------------

    /**
     * Returns the active chat key.
     *
     * @returns {string}
     */
    activeChat() {

        return this.#activeChat;

    }

    /**
     * Determines whether a chat key is active.
     *
     * @param {string} chatKey
     *
     * @returns {boolean}
     */
    isActive(chatKey) {

        return String(chatKey || "") === this.#activeChat;

    }

    /**
     * Switches the active chat when the target channel is available.
     *
     * @param {string} chatKey
     *
     * @returns {string}
     *          Active chat after switching.
     */
    switchChat(chatKey) {

        const target =
            this.#validatedChatKey(chatKey);

        this.#clearUnread(target);

        if (target === this.#activeChat) {
            return this.#activeChat;
        }

        this.#stopTyping();
        this.#clearReplyDraft();

        this.#activeChat = target;

        this.#syncActiveChat();

        return this.#activeChat;

    }

    /**
     * Renders the current active chat presentation through callbacks.
     *
     * @returns {string}
     *          Active chat after rendering.
     */
    renderActiveChat() {

        this.#clearUnread(this.#activeChat);
        this.#syncActiveChat();

        return this.#activeChat;

    }

    /**
     * Validates the active channel and falls back when it is unavailable.
     *
     * @returns {string}
     *          Active chat after validation.
     */
    validateActiveChat() {

        const target =
            this.#validatedChatKey(this.#activeChat);

        if (target !== this.#activeChat) {
            return this.switchChat(target);
        }

        return this.#activeChat;

    }

    /**
     * Clears unread state for a chat channel through the configured callback.
     *
     * @param {string} chatKey
     */
    clearUnread(chatKey) {

        this.#clearUnread(this.#normalizedChatKey(chatKey));

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
                "000022-M",

            configured:
                Boolean(this.#context),

            activeChat:
                this.#activeChat

        });

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Returns a normalized chat key.
     *
     * @param {string} chatKey
     *
     * @returns {string}
     */
    #normalizedChatKey(chatKey) {

        const key =
            String(chatKey || "").trim();

        return key || DEFAULT_CHAT_KEY;

    }

    /**
     * Returns an available chat key or the default fallback.
     *
     * @param {string} chatKey
     *
     * @returns {string}
     */
    #validatedChatKey(chatKey) {

        const key =
            this.#normalizedChatKey(chatKey);

        if (this.#isAvailableChat(key)) {
            return key;
        }

        return DEFAULT_CHAT_KEY;

    }

    /**
     * Determines whether a chat key is currently available.
     *
     * @param {string} chatKey
     *
     * @returns {boolean}
     */
    #isAvailableChat(chatKey) {

        const context =
            this.#context || {};

        if (chatKey === "room" || chatKey === "community") {
            return true;
        }

        if (chatKey.startsWith("link:")) {
            return context.isLinkChatAvailable?.(chatKey) !== false;
        }

        if (chatKey.startsWith("game:")) {
            return context.isGameChatAvailable?.(chatKey) !== false;
        }

        if (chatKey.startsWith("dm:")) {
            return context.isDmChatAvailable?.(chatKey) !== false;
        }

        return true;

    }

    /**
     * Clears unread state through the configured callback.
     *
     * @param {string} chatKey
     */
    #clearUnread(chatKey) {

        this.#context?.clearUnread?.(chatKey);

    }

    /**
     * Stops active typing workflows before switching channels.
     */
    #stopTyping() {

        const context =
            this.#context || {};

        context.stopTypingNow?.();
        context.stopGameTypingNow?.();

    }

    /**
     * Clears the active reply draft before switching channels.
     */
    #clearReplyDraft() {

        this.#context?.clearReplyDraft?.();

    }

    /**
     * Synchronizes active chat presentation through callbacks.
     */
    #syncActiveChat() {

        const context =
            this.#context || {};

        context.setGameLayerVisibility?.(this.#activeChat);
        context.renderMessagesForChat?.(this.#activeChat);
        context.updateComposerPlaceholder?.(this.#activeChat);
        context.renderReplyDraft?.(this.#activeChat);
        context.syncActiveTabs?.(this.#activeChat);

    }

}

export default ChatChannelNavigationService;
