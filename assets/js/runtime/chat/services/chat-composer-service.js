/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      chat-composer-service.js
 *
 * Layer:
 *      Runtime Service
 *
 * Owner:
 *      Chat Runtime
 *
 * Purpose:
 *      Owns non-game chat text composer send workflow behavior.
 *
 *      ChatComposerService owns outgoing text message payload construction,
 *      reply payload augmentation, non-game chat target resolution, send API
 *      execution, and returned-message routing. It leaves composer DOM input,
 *      typing state ownership, uploads, GIFs, gestures, voice notes, and game
 *      chat outside its ownership.
 *
 * Build:
 *      000022-G
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000022-G
 * - Introduced Chat Composer Service.
 * - Transferred non-game text composer send workflow ownership from room.js.
 ******************************************************************************/

/**
 * @file chat-composer-service.js
 *
 * Defines the Chat Composer Service.
 */

//
// No imports required.
//

//--------------------------------------------------
// Chat Composer Service
//--------------------------------------------------

/**
 * Owns non-game chat text composer send workflow behavior.
 */
export class ChatComposerService {

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
     * Composer context supplied by the room composition root.
     *
     * @type {Object}
     */
    #context = null;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Chat Composer Service.
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
     * Releases composer-owned references.
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
     * Configures composer extension points.
     *
     * @param {Object} context
     */
    configure(context = {}) {

        this.#context = context;

    }

    //--------------------------------------------------
    // Public Composer API
    //--------------------------------------------------

    /**
     * Sends a non-game text message for the supplied chat key.
     *
     * @param {string} content
     * @param {string} activeChat
     * @param {{important?: boolean}} options
     *
     * @returns {Promise<Object|null>}
     */
    captureTextTarget(activeChat) {
        const context = this.#requireContext();
        const chatKey = String(activeChat || "");
        if (!['room', 'community'].includes(chatKey) && !/^(dm|link):.+$/.test(chatKey)) {
            throw new Error("This conversation is unavailable. Your message was not sent.");
        }
        const config = context.getConfig();
        const relationship = chatKey.startsWith('link:')
            ? context.activeRelationshipRequest?.(chatKey) : null;
        const dmUserId = chatKey.startsWith('dm:') ? Number(chatKey.slice(3)) : null;
        if ((chatKey.startsWith('link:') && !relationship?.conversation_id)
            || (chatKey.startsWith('dm:') && (!Number.isSafeInteger(dmUserId) || dmUserId <= 0))) {
            throw new Error("This conversation is unavailable. Your message was not sent.");
        }
        const replyDraft = this.#runtime.reply.draftForChat(chatKey);
        const payload = this.#runtime.reply.appendReplyPayload({
            session_id: config.sessionId,
            join_token: config.myJoinToken,
            channel: relationship ? 'link' : (dmUserId ? 'dm' : chatKey),
        }, chatKey);
        if (relationship) Object.assign(payload, {
            relationship_id: relationship.relationship_id,
            conversation_id: relationship.conversation_id,
        });
        if (dmUserId) payload.target_user_id = dmUserId;
        return Object.freeze({chatKey, replyDraft, dmUserId,
            relationship: relationship ? Object.freeze({...relationship, chatKey}) : null,
            payload: Object.freeze(payload)});
    }

    async sendTextMessage(content, activeChat, options = {}) {

        const text =
            String(content || "").trim();

        if (!text || String(activeChat || "").startsWith("game:")) {
            return null;
        }

        const context =
            this.#requireContext();

        try {

            context.stopTypingNow?.();

            const target = options.target || this.captureTextTarget(activeChat);
            if (target.chatKey !== activeChat) throw new Error("The message target changed.");
            const payload = {...target.payload, content: text, important: Boolean(options.important), client_message_id: options.clientMessageId || crypto.randomUUID()};

            const message =
                await context.apiPost(
                    "/api/messages.php",
                    payload
                );

            this.#runtime.reply.clearDraftIfCurrent(target.replyDraft);

            this.#routeSentMessage(
                message,
                target.relationship,
                target.dmUserId
            );

            return message;

        } catch (error) {

            throw error;


        }

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
                "000022-G",

            configured:
                Boolean(this.#context)

        });

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Routes a sent message response through the existing presentation path.
     *
     * @param {Object} message
     * @param {Object|null} relationship
     * @param {number|null} dmUserId
     */
    #routeSentMessage(message, relationship, dmUserId) {

        const context =
            this.#requireContext();

        if (message.channel === "community") {

            context.addMessageToChannel(
                message,
                "community",
                false
            );

            return;

        }

        if (message.channel === "link") {

            context.addMessageToChannel(
                message,
                relationship.chatKey,
                false
            );

            return;

        }

        if (message.channel === "dm") {

            context.addMessageToChannel(
                message,
                `dm:${dmUserId}`,
                false
            );

            context.showDmFlight?.(
                message
            );

            return;

        }

        context.renderMessage(
            message,
            true
        );

    }

    /**
     * Returns configured composer context.
     *
     * @returns {Object}
     */
    #requireContext() {

        if (!this.#context) {
            throw new Error("ChatComposerService context has not been configured.");
        }

        return this.#context;

    }

}

export default ChatComposerService;
