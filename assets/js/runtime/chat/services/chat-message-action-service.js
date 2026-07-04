/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      chat-message-action-service.js
 *
 * Layer:
 *      Runtime Service
 *
 * Owner:
 *      Chat Runtime
 *
 * Purpose:
 *      Owns outgoing chat message action workflows.
 *
 *      ChatMessageActionService owns reaction, edit, and delete API workflows
 *      for existing messages. It coordinates server requests with existing
 *      ChatRuntime message state/rendering entry points while leaving message
 *      action menus, inline edit DOM, delete modal presentation, composer
 *      workflows, uploads, and polling outside its ownership.
 *
 * Build:
 *      000022-D
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000022-D
 * - Introduced Chat Message Action Service.
 * - Transferred outgoing reaction workflow ownership from room.js.
 * - Transferred outgoing message edit workflow ownership from room.js.
 * - Transferred outgoing message delete workflow ownership from room.js.
 ******************************************************************************/

/**
 * @file chat-message-action-service.js
 *
 * Defines the Chat Message Action Service.
 */

//
// No imports required.
//

//--------------------------------------------------
// Chat Message Action Service
//--------------------------------------------------

/**
 * Owns outgoing chat message action workflows.
 */
export class ChatMessageActionService {

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
     * Action context supplied by the room composition root.
     *
     * @type {Object}
     */
    #context = null;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Chat Message Action Service.
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
     * Releases action-owned references.
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
     * Configures action extension points.
     *
     * @param {Object} context
     */
    configure(context = {}) {

        this.#context = context;

    }

    //--------------------------------------------------
    // Public Message Action API
    //--------------------------------------------------

    /**
     * Returns the current message for a chat action.
     *
     * @param {number|string} messageId
     * @param {string} chatKey
     *
     * @returns {Object|null}
     */
    currentMessage(messageId, chatKey) {

        return this.#runtime.messages.getMessageForChat(
            chatKey,
            messageId
        );

    }

    /**
     * Applies a reaction to a message through the server API.
     *
     * @param {number|string} messageId
     * @param {string} emoji
     * @param {string} chatKey
     *
     * @returns {Promise<void>}
     */
    async applyReaction(messageId, emoji, chatKey) {

        if (!messageId || !emoji) {
            return;
        }

        const context =
            this.#requireContext();

        try {
            await context.apiPost(
                "/api/reactions.php",
                {
                    session_id:
                        context.getConfig().sessionId,

                    join_token:
                        context.getConfig().myJoinToken,

                    message_id:
                        Number(messageId),

                    channel:
                        context.channelForApi(chatKey),

                    emoji
                }
            );
        } catch (err) {
            context.showWarning(
                err.message || "Reaction failed."
            );
        }

    }

    /**
     * Saves an inline message edit through the server API.
     *
     * @param {Object} message
     * @param {string} content
     * @param {string} chatKey
     *
     * @returns {Promise<Object|null>}
     */
    async saveInlineEdit(message, content, chatKey) {

        const text =
            String(content || "").trim();

        if (!message || !text) {
            return null;
        }

        const context =
            this.#requireContext();

        try {
            const updated =
                await context.apiPost(
                    "/api/messages.php",
                    {
                        action:
                            "edit",

                        session_id:
                            context.getConfig().sessionId,

                        join_token:
                            context.getConfig().myJoinToken,

                        message_id:
                            message.id,

                        channel:
                            context.channelForApi(chatKey),

                        content:
                            text
                    }
                );

            return context.updateMessageInChannel(
                chatKey,
                message.id,
                {
                    content:
                        text,

                    url_preview:
                        updated.url_preview || null,

                    edited_at:
                        updated.edited_at || new Date().toISOString()
                }
            );
        } catch (err) {
            context.showWarning(
                err.message || "Could not edit message."
            );
        }

        return null;

    }

    /**
     * Deletes a message through the server API.
     *
     * @param {Object} message
     * @param {string} chatKey
     *
     * @returns {Promise<boolean>}
     */
    async deleteMessage(message, chatKey) {

        if (!message) {
            return false;
        }

        const context =
            this.#requireContext();

        try {
            const deleted =
                await context.apiPost(
                    "/api/messages.php",
                    {
                        action:
                            "delete",

                        session_id:
                            context.getConfig().sessionId,

                        join_token:
                            context.getConfig().myJoinToken,

                        message_id:
                            message.id,

                        channel:
                            context.channelForApi(chatKey)
                    }
                );

            if (
                chatKey === "room" &&
                context.getConfig().canModerateMessages
            ) {
                context.updateMessageInChannels(
                    message.id,
                    {
                        is_deleted:
                            true,

                        deleted_at:
                            deleted.deleted_at || new Date().toISOString()
                    }
                );
                return true;
            }

            context.removeMessageFromChannel(
                chatKey,
                message.id
            );

            return true;
        } catch (err) {
            context.showWarning(
                err.message || "Could not delete message."
            );
        }

        return false;

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
                "000022-D",

            configured:
                Boolean(this.#context)

        });

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Returns configured action context.
     *
     * @returns {Object}
     */
    #requireContext() {

        if (!this.#context) {
            throw new Error("ChatMessageActionService context has not been configured.");
        }

        return this.#context;

    }

}

export default ChatMessageActionService;
