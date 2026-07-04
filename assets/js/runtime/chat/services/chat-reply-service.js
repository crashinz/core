/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      chat-reply-service.js
 *
 * Layer:
 *      Runtime Service
 *
 * Owner:
 *      Chat Runtime
 *
 * Purpose:
 *      Owns chat reply draft workflow state.
 *
 *      ChatReplyService owns reply draft creation, clearing, active-channel
 *      validation, and reply payload augmentation. It leaves reply draft DOM
 *      presentation, composer submission, uploads, GIFs, gestures, and message
 *      sending workflows outside its ownership.
 *
 * Build:
 *      000022-F
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000022-F
 * - Introduced Chat Reply Service.
 * - Transferred reply draft state ownership from room.js.
 * - Transferred reply payload augmentation ownership from room.js.
 ******************************************************************************/

/**
 * @file chat-reply-service.js
 *
 * Defines the Chat Reply Service.
 */

//
// No imports required.
//

//--------------------------------------------------
// Chat Reply Service
//--------------------------------------------------

/**
 * Owns chat reply draft workflow state.
 */
export class ChatReplyService {

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
     * Current reply draft.
     *
     * @type {Object|null}
     */
    #draft = null;

    /**
     * Reply context supplied by the room composition root.
     *
     * @type {Object}
     */
    #context = null;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Chat Reply Service.
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
     * Releases reply-owned state and references.
     */
    destroy() {

        this.#draft = null;

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
     * Configures reply extension points.
     *
     * @param {Object} context
     */
    configure(context = {}) {

        this.#context = context;

    }

    //--------------------------------------------------
    // Public Reply API
    //--------------------------------------------------

    /**
     * Returns the current reply draft.
     *
     * @returns {Object|null}
     */
    currentDraft() {

        return this.#draft;

    }

    /**
     * Returns the active reply draft for a chat channel.
     *
     * @param {string} chatKey
     *
     * @returns {Object|null}
     */
    draftForChat(chatKey) {

        if (!this.#draft || this.#draft.chatKey !== chatKey) {
            return null;
        }

        return this.#draft;

    }

    /**
     * Starts a reply draft for a message.
     *
     * @param {Object} message
     * @param {string} chatKey
     *
     * @returns {Object|null}
     */
    startDraft(message, chatKey) {

        if (
            !message ||
            message.system ||
            message.is_deleted ||
            String(chatKey || "").startsWith("game:")
        ) {
            return null;
        }

        const context =
            this.#requireContext();

        this.#draft = {

            id:
                Number(message.id),

            chatKey,

            display_name:
                message.display_name ||
                context.participantDisplayName(message.participant_id) ||
                "Someone",

            preview:
                context.messagePreviewText(message)

        };

        this.#notifyChange();

        context.focusComposer?.();

        return this.#draft;

    }

    /**
     * Clears the active reply draft.
     */
    clearDraft() {

        this.#draft = null;

        this.#notifyChange();

    }

    /**
     * Appends reply metadata to an outgoing payload.
     *
     * @param {Object} payload
     * @param {string} activeChat
     *
     * @returns {Object}
     */
    appendReplyPayload(payload, activeChat) {

        if (!this.#draft || this.#draft.chatKey !== activeChat) {
            return payload;
        }

        const context =
            this.#requireContext();

        payload.reply_to_id =
            this.#draft.id;

        payload.reply_to_channel =
            context.channelForApi(
                this.#draft.chatKey
            );

        return payload;

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
                "000022-F",

            configured:
                Boolean(this.#context),

            hasDraft:
                Boolean(this.#draft)

        });

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Notifies the composition root that reply presentation changed.
     */
    #notifyChange() {

        this.#context?.onReplyDraftChange?.(
            this.#draft
        );

    }

    /**
     * Returns configured reply context.
     *
     * @returns {Object}
     */
    #requireContext() {

        if (!this.#context) {
            throw new Error("ChatReplyService context has not been configured.");
        }

        return this.#context;

    }

}

export default ChatReplyService;
