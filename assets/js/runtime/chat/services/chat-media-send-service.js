/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      chat-media-send-service.js
 *
 * Layer:
 *      Runtime Service
 *
 * Owner:
 *      Chat Runtime
 *
 * Purpose:
 *      Owns outgoing chat media message send workflow behavior.
 *
 *      ChatMediaSendService owns file upload send, voice note upload send,
 *      GIF send, gesture send, media payload construction, reply payload
 *      augmentation, upload/API execution, and sent media response routing.
 *      It leaves picker presentation, search, recording lifecycle, paste and
 *      file input events, gesture library management, and game chat ownership
 *      outside its boundary.
 *
 * Build:
 *      000022-I
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000022-I
 * - Introduced Chat Media Send Service.
 * - Transferred outgoing media message send workflow ownership from room.js.
 ******************************************************************************/

/**
 * @file chat-media-send-service.js
 *
 * Defines the Chat Media Send Service.
 */

//
// No imports required.
//

//--------------------------------------------------
// Chat Media Send Service
//--------------------------------------------------

/**
 * Owns outgoing chat media message send workflow behavior.
 */
export class ChatMediaSendService {

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
     * Media send context supplied by the room composition root.
     *
     * @type {Object}
     */
    #context = null;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Chat Media Send Service.
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
     * Releases media-send owned references.
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
     * Configures media send extension points.
     *
     * @param {Object} context
     */
    configure(context = {}) {

        this.#context = context;

    }

    //--------------------------------------------------
    // Public Media Send API
    //--------------------------------------------------

    /**
     * Sends a file attachment message.
     *
     * @param {File|Blob} file
     * @param {string} activeChat
     *
     * @returns {Promise<Object|null>}
     */
    async sendFile(file, activeChat) {

        if (!file) return null;

        const formData =
            this.#createUploadFormData(activeChat);

        formData.append(
            "file",
            file
        );

        return this.#sendUpload(
            formData
        );

    }

    /**
     * Sends a voice note audio message.
     *
     * @param {Blob} blob
     * @param {string} activeChat
     *
     * @returns {Promise<Object|null>}
     */
    async sendVoiceNote(blob, activeChat) {

        if (!blob) return null;

        const formData =
            this.#createUploadFormData(activeChat);

        formData.append(
            "audio",
            blob,
            "voice-note.webm"
        );

        return this.#sendUpload(
            formData
        );

    }

    /**
     * Sends a GIF message.
     *
     * @param {Object} result
     * @param {string} activeChat
     *
     * @returns {Promise<Object|null>}
     */
    async sendGif(result, activeChat) {

        if (!result?.url) return null;

        const context =
            this.#requireContext();

        const config =
            context.getConfig();

        const payload =
            this.#runtime.reply.appendReplyPayload({

                session_id:
                    config.sessionId,

                join_token:
                    config.myJoinToken,

                action:
                    "gif",

                gif_url:
                    result.url,

                title:
                    result.title || "GIF",

                channel:
                    activeChat

            }, activeChat);

        const targets =
            this.#applyChatTargetsToPayload(
                payload
            );

        return this.#sendMessagePayload(
            payload,
            targets
        );

    }

    /**
     * Sends a gesture message.
     *
     * @param {Object} gesture
     * @param {string} activeChat
     *
     * @returns {Promise<Object|null>}
     */
    async sendGesture(gesture, activeChat) {

        if (!gesture?.id) return null;

        const context =
            this.#requireContext();

        const config =
            context.getConfig();

        const payload =
            this.#runtime.reply.appendReplyPayload({

                session_id:
                    config.sessionId,

                join_token:
                    config.myJoinToken,

                action:
                    "gesture",

                gesture_id:
                    gesture.id,

                channel:
                    "room"

            }, activeChat);

        try {

            const message =
                await context.apiPost(
                    "/api/messages.php",
                    payload
                );

            this.#runtime.reply.clearDraft();

            context.switchChat?.(
                "room"
            );

            context.renderMessage(
                message,
                true
            );

            return message;

        } catch (error) {

            this.#reportError(
                error
            );

            return null;

        }

    }

    /**
     * Routes a sent upload response through existing message entry points.
     *
     * @param {Object} message
     */
    routeUploadedMessage(message) {

        this.#routeSentMessage(
            message,
            this.#currentTargets()
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
                "000022-I",

            configured:
                Boolean(this.#context)

        });

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Creates an upload form for the current chat target.
     *
     * @param {string} activeChat
     *
     * @returns {FormData}
     */
    #createUploadFormData(activeChat) {

        const context =
            this.#requireContext();

        const config =
            context.getConfig();

        const formData =
            context.createFormData?.() || new FormData();

        formData.append(
            "session_id",
            config.sessionId
        );

        formData.append(
            "join_token",
            config.myJoinToken
        );

        formData.append(
            "channel",
            context.channelForApi(activeChat)
        );

        this.#appendChatTargetsToFormData(
            formData,
            activeChat
        );

        this.#appendReplyFormData(
            formData,
            activeChat
        );

        return formData;

    }

    /**
     * Sends an upload form to the file API.
     *
     * @param {FormData} formData
     *
     * @returns {Promise<Object|null>}
     */
    async #sendUpload(formData) {

        const context =
            this.#requireContext();

        try {

            const message =
                await context.apiUpload(
                    "/api/files.php",
                    formData
                );

            this.#runtime.reply.clearDraft();

            this.routeUploadedMessage(
                message
            );

            return message;

        } catch (error) {

            this.#reportError(
                error
            );

            return null;

        }

    }

    /**
     * Sends a media message payload to the messages API.
     *
     * @param {Object} payload
     * @param {Object} targets
     *
     * @returns {Promise<Object|null>}
     */
    async #sendMessagePayload(payload, targets) {

        const context =
            this.#requireContext();

        try {

            const message =
                await context.apiPost(
                    "/api/messages.php",
                    payload
                );

            this.#runtime.reply.clearDraft();

            this.#routeSentMessage(
                message,
                targets
            );

            return message;

        } catch (error) {

            this.#reportError(
                error
            );

            return null;

        }

    }

    /**
     * Applies link or DM targets to a JSON payload.
     *
     * @param {Object} payload
     *
     * @returns {Object}
     */
    #applyChatTargetsToPayload(payload) {

        const targets =
            this.#currentTargets();

        if (targets.relationship) {

            payload.channel =
                "link";

            payload.relationship_id =
                targets.relationship.relationship_id;

            payload.conversation_id =
                targets.relationship.conversation_id;

        } else if (targets.dmUserId) {

            payload.channel =
                "dm";

            payload.target_user_id =
                targets.dmUserId;

        }

        return targets;

    }

    /**
     * Appends link, DM, or game target fields to upload form data.
     *
     * @param {FormData} formData
     * @param {string} activeChat
     */
    #appendChatTargetsToFormData(formData, activeChat) {

        const targets =
            this.#currentTargets();

        if (targets.relationship) {
            formData.append("relationship_id", targets.relationship.relationship_id);
            formData.append("conversation_id", targets.relationship.conversation_id);
        }

        if (targets.dmUserId) {
            formData.append("target_user_id", String(targets.dmUserId));
        }

        if (String(activeChat || "").startsWith("game:")) {
            formData.append("lobby_code", String(activeChat).slice(5));
        }

    }

    /**
     * Appends reply metadata to upload form data.
     *
     * @param {FormData} formData
     * @param {string} activeChat
     */
    #appendReplyFormData(formData, activeChat) {

        const payload =
            this.#runtime.reply.appendReplyPayload(
                {},
                activeChat
            );

        if (!payload.reply_to_id) return;

        formData.append(
            "reply_to_id",
            String(payload.reply_to_id)
        );

        formData.append(
            "reply_to_channel",
            payload.reply_to_channel
        );

    }

    /**
     * Routes a sent media message through existing message entry points.
     *
     * @param {Object} message
     * @param {Object} targets
     */
    #routeSentMessage(message, targets = {}) {

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

            const relationship =
                targets.relationship || this.#currentTargets().relationship;

            context.addMessageToChannel(
                message,
                relationship?.chatKey || context.getActiveChat(),
                false
            );

            return;

        }

        if (message.channel === "dm") {

            const dmUserId =
                Number(
                    message.partner_user_id ||
                    message.target_user_id ||
                    targets.dmUserId ||
                    this.#currentTargets().dmUserId
                );

            context.addMessageToChannel(
                message,
                dmUserId ? `dm:${dmUserId}` : context.getActiveChat(),
                false
            );

            context.showDmFlight?.(
                message
            );

            return;

        }

        if (message.channel === "game") {

            context.addMessageToChannel(
                message,
                context.gameChatKey(message.lobby_code),
                false
            );

            return;

        }

        context.renderMessage(
            message,
            true
        );

    }

    /**
     * Returns the active link and DM targets.
     *
     * @returns {Object}
     */
    #currentTargets() {

        const context =
            this.#requireContext();

        return {

            relationship:
                context.activeRelationshipRequest?.() || null,

            dmUserId:
                context.activeDmUserId()

        };

    }

    /**
     * Reports a media send error.
     *
     * @param {Error} error
     */
    #reportError(error) {

        const context =
            this.#requireContext();

        if (context.alertError) {

            context.alertError(
                error
            );

            return;

        }

        throw error;

    }

    /**
     * Returns configured media send context.
     *
     * @returns {Object}
     */
    #requireContext() {

        if (!this.#context) {
            throw new Error("ChatMediaSendService context has not been configured.");
        }

        return this.#context;

    }

}

export default ChatMediaSendService;
