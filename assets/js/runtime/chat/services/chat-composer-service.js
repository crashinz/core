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
     *
     * @returns {Promise<Object|null>}
     */
    async sendTextMessage(content, activeChat) {

        const text =
            String(content || "").trim();

        if (!text || String(activeChat || "").startsWith("game:")) {
            return null;
        }

        const context =
            this.#requireContext();

        try {

            context.stopTypingNow?.();

            const config =
                context.getConfig();

            const payload =
                this.#runtime.reply.appendReplyPayload({

                    session_id:
                        config.sessionId,

                    join_token:
                        config.myJoinToken,

                    content:
                        text,

                    channel:
                        activeChat

                }, activeChat);

            const relationship =
                context.activeRelationshipRequest?.();

            const dmUserId =
                context.activeDmUserId();

            if (relationship) {

                payload.channel =
                    "link";

                payload.relationship_id =
                    relationship.relationship_id;

                payload.conversation_id =
                    relationship.conversation_id;

            } else if (dmUserId) {

                payload.channel =
                    "dm";

                payload.target_user_id =
                    dmUserId;

            }

            const message =
                await context.apiPost(
                    "/api/messages.php",
                    payload
                );

            this.#runtime.reply.clearDraft();

            this.#routeSentMessage(
                message,
                relationship,
                dmUserId
            );

            return message;

        } catch (error) {

            if (context.alertError) {

                context.alertError(
                    error
                );

                return null;

            }

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
                relationship?.chatKey || context.activeRelationshipRequest?.()?.chatKey || "room",
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
