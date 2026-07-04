/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      chat-event-router.js
 *
 * Layer:
 *      Runtime Coordinator
 *
 * Owner:
 *      Chat Runtime
 *
 * Purpose:
 *      Owns incoming chat event classification and message-state routing.
 *
 *      ChatEventRouter routes incoming room, community, link, and direct
 *      message events into existing ChatRuntime state/rendering entry points
 *      while leaving polling transport, event ids, non-chat room events,
 *      outgoing workflows, and game chat integration outside its ownership.
 *
 * Build:
 *      000022-C
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000022-C
 * - Introduced Chat Event Router.
 * - Transferred incoming chat event classification from room.js.
 * - Transferred incoming chat message mutation routing from room.js.
 * - Transferred incoming reaction state application from room.js.
 ******************************************************************************/

/**
 * @file chat-event-router.js
 *
 * Defines the Chat Event Router.
 */

//
// No imports required.
//

//--------------------------------------------------
// Chat Event Router
//--------------------------------------------------

/**
 * Owns incoming chat event classification and message-state routing.
 */
export class ChatEventRouter {

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
     * Routing context supplied by the room composition root.
     *
     * @type {Object}
     */
    #context = null;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Chat Event Router.
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
     * Releases router-owned references.
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
     * Configures routing extension points.
     *
     * @param {Object} context
     */
    configure(context = {}) {

        this.#context = context;

    }

    //--------------------------------------------------
    // Public Routing API
    //--------------------------------------------------

    /**
     * Routes an incoming room poll event when it belongs to ChatRuntime.
     *
     * @param {Object} event
     *
     * @returns {boolean}
     *          True when the event was chat-owned.
     */
    routeRoomEvent(event) {

        const context =
            this.#requireContext();

        const payload =
            event?.payload || {};

        switch (event?.type) {

            case "message":
                context.renderMessage(
                    payload,
                    true
                );
                return true;

            case "message_edit":
                this.#routeRoomMessageEdit(payload);
                return true;

            case "message_delete":
                this.#routeRoomMessageDelete(payload);
                return true;

            case "room_history_clear":
                context.handleRoomHistoryClear(payload);
                return true;

            case "reaction":
                this.#routeRoomReaction(payload);
                return true;

            default:
                return false;

        }

    }

    /**
     * Routes an incoming community poll event when it belongs to ChatRuntime.
     *
     * @param {Object} event
     *
     * @returns {boolean}
     *          True when the event was chat-owned.
     */
    routeCommunityEvent(event) {

        const context =
            this.#requireContext();

        const payload =
            event?.payload || {};

        switch (event?.type) {

            case "community_message":
                context.addMessageToChannel(
                    payload,
                    "community",
                    true
                );
                return true;

            case "link_message":
                this.#routeLinkMessage(payload);
                return true;

            case "dm_message":
                this.#routeDirectMessage(payload);
                return true;

            case "community_message_edit":
            case "link_message_edit":
            case "dm_message_edit":
                this.#routeChannelMessageEdit(payload);
                return true;

            case "community_message_delete":
            case "link_message_delete":
            case "dm_message_delete":
                context.removeMessageFromChannel(
                    this.chatKeyForMessagePayload(payload),
                    payload.message_id
                );
                return true;

            case "message_reaction":
                this.#routeChannelReaction(payload);
                return true;

            default:
                return false;

        }

    }

    /**
     * Resolves a chat key for an incoming message payload.
     *
     * @param {Object} payload
     *
     * @returns {string}
     */
    chatKeyForMessagePayload(payload = {}) {

        const context =
            this.#requireContext();

        const channel =
            payload.channel ||
            (payload.dm_key ? "dm" : payload.link_key ? "link" : "community");

        if (channel === "community") {
            return "community";
        }

        if (channel === "link") {
            const partnerId =
                context.linkPartnerIdFromKey(payload.link_key);

            return partnerId
                ? `link:${partnerId}`
                : context.getActiveChat();
        }

        if (channel === "dm") {
            const partnerUserId =
                context.dmPartnerIdFromPayload(payload);

            return partnerUserId
                ? `dm:${partnerUserId}`
                : context.getActiveChat();
        }

        return "room";

    }

    //--------------------------------------------------
    // Public Diagnostics
    //--------------------------------------------------

    /**
     * Returns router diagnostic information.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        return Object.freeze({

            owner:
                "ChatRuntime",

            build:
                "000022-C",

            configured:
                Boolean(this.#context)

        });

    }

    //--------------------------------------------------
    // Private Routing Methods
    //--------------------------------------------------

    /**
     * Routes incoming room message edit.
     *
     * @param {Object} payload
     */
    #routeRoomMessageEdit(payload) {

        const context =
            this.#requireContext();

        const message =
            this.#runtime.messages.getMessageForChat(
                "room",
                payload.message_id
            );

        const changes = {

            content:
                payload.content,

            url_preview:
                payload.url_preview || null,

            edited_at:
                payload.edited_at || new Date().toISOString()

        };

        if (
            context.getConfig().canModerateMessages &&
            message &&
            !message.original_content &&
            message.content !== payload.content
        ) {
            changes.original_content =
                message.content;
        }

        context.updateMessageInChannels(
            payload.message_id,
            changes
        );

    }

    /**
     * Routes incoming room message delete.
     *
     * @param {Object} payload
     */
    #routeRoomMessageDelete(payload) {

        const context =
            this.#requireContext();

        if (context.getConfig().canModerateMessages) {
            context.updateMessageInChannels(
                payload.message_id,
                {
                    is_deleted:
                        true,

                    deleted_at:
                        payload.deleted_at || new Date().toISOString()
                }
            );
            return;
        }

        context.removeMessageFromChannels(
            payload.message_id
        );

    }

    /**
     * Routes incoming room reaction.
     *
     * @param {Object} payload
     */
    #routeRoomReaction(payload) {

        const message =
            this.#runtime.messages.getMessageForChat(
                "room",
                payload.message_id
            );

        if (!message) {
            return;
        }

        this.#applyReactionState(
            message,
            payload
        );

        this.#requireContext().updateMessageInChannels(
            payload.message_id,
            {
                reactions:
                    message.reactions
            }
        );

    }

    /**
     * Routes incoming link message.
     *
     * @param {Object} payload
     */
    #routeLinkMessage(payload) {

        const context =
            this.#requireContext();

        const partnerId =
            context.linkPartnerIdFromKey(payload.link_key) ||
            (
                payload.participant_id === context.getConfig().myParticipantId
                    ? context.activeLinkPartnerId()
                    : payload.participant_id
            );

        context.addMessageToChannel(
            payload,
            `link:${partnerId}`,
            true
        );

    }

    /**
     * Routes incoming direct message.
     *
     * @param {Object} payload
     */
    #routeDirectMessage(payload) {

        const context =
            this.#requireContext();

        const partnerUserId =
            payload.user_id === context.getConfig().myUserId
                ? payload.target_user_id
                : payload.user_id;

        if (!partnerUserId) {
            return;
        }

        context.rememberDirectMessageUser(
            partnerUserId,
            payload
        );

        context.addMessageToChannel(
            Object.assign(
                {},
                payload,
                {
                    partner_user_id:
                        partnerUserId
                }
            ),
            `dm:${partnerUserId}`,
            true
        );

    }

    /**
     * Routes incoming channel message edit.
     *
     * @param {Object} payload
     */
    #routeChannelMessageEdit(payload) {

        this.#requireContext().updateMessageInChannel(
            this.chatKeyForMessagePayload(payload),
            payload.message_id,
            {
                content:
                    payload.content,

                url_preview:
                    payload.url_preview || null,

                edited_at:
                    payload.edited_at || new Date().toISOString()
            }
        );

    }

    /**
     * Routes incoming channel reaction.
     *
     * @param {Object} payload
     */
    #routeChannelReaction(payload) {

        const chatKey =
            this.chatKeyForMessagePayload(payload);

        const message =
            this.#runtime.messages.getChannelMessage(
                chatKey,
                payload.message_id
            );

        if (!message) {
            return;
        }

        this.#applyReactionState(
            message,
            payload
        );

        this.#requireContext().updateMessageInChannel(
            chatKey,
            payload.message_id,
            {
                reactions:
                    message.reactions
            }
        );

    }

    /**
     * Applies incoming reaction payload to a message.
     *
     * @param {Object} message
     * @param {Object} payload
     */
    #applyReactionState(message, payload) {

        message.reactions =
            Array.isArray(message.reactions)
                ? message.reactions.filter(
                    reaction => Number(reaction.participant_id) !==
                        Number(payload.participant_id)
                )
                : [];

        if (payload.removed) {
            return;
        }

        message.reactions.push({

            participant_id:
                payload.participant_id,

            user_id:
                payload.user_id,

            emoji:
                payload.emoji,

            display_name:
                payload.display_name,

            avatar_url:
                payload.avatar_url

        });

    }

    /**
     * Returns configured routing context.
     *
     * @returns {Object}
     */
    #requireContext() {

        if (!this.#context) {
            throw new Error("ChatEventRouter context has not been configured.");
        }

        return this.#context;

    }

}

export default ChatEventRouter;
