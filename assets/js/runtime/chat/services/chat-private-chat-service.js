/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      chat-private-chat-service.js
 *
 * Layer:
 *      Runtime Service
 *
 * Owner:
 *      Chat Runtime
 *
 * Purpose:
 *      Owns private chat lifecycle behavior.
 *
 *      ChatPrivateChatService owns DM user registry state, closed DM tab
 *      state, DM opening decisions, DM tab closing, private history clearing,
 *      and private chat key/label helpers. It leaves tab DOM presentation,
 *      context menu presentation, link relationship lifecycle, polling
 *      transport, and game chat outside its ownership.
 *
 * Build:
 *      000022-J
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000022-J
 * - Introduced Chat Private Chat Service.
 * - Transferred private chat lifecycle ownership from room.js.
 ******************************************************************************/

/**
 * @file chat-private-chat-service.js
 *
 * Defines the Chat Private Chat Service.
 */

//
// No imports required.
//

//--------------------------------------------------
// Chat Private Chat Service
//--------------------------------------------------

/**
 * Owns private chat lifecycle behavior.
 */
export class ChatPrivateChatService {

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
     * DM user registry.
     *
     * @type {Map<number,Object>}
     */
    #dmUsers = new Map();

    /**
     * Closed DM tab user ids.
     *
     * @type {Set<number>}
     */
    #closedDmUserIds = new Set();

    /**
     * Current relationship conversation projection.
     *
     * @type {Object|null}
     */
    #relationshipChat = null;

    /**
     * Private chat context supplied by the room composition root.
     *
     * @type {Object}
     */
    #context = null;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Chat Private Chat Service.
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
     * Releases private chat state and references.
     */
    destroy() {

        this.#dmUsers.clear();

        this.#closedDmUserIds.clear();

        this.#relationshipChat = null;

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
     * Configures private chat extension points.
     *
     * @param {Object} context
     */
    configure(context = {}) {

        this.#context = context;

    }

    //--------------------------------------------------
    // Public Private Chat API
    //--------------------------------------------------

    /**
     * Returns the active link partner id for a chat key.
     *
     * @param {string} chatKey
     *
     * @returns {number|null}
     */
    activeLinkPartnerId(chatKey) {

        const key =
            String(chatKey || "");

        if (!key.startsWith("link:")) return null;

        if (!this.#relationshipChat || key !== this.#relationshipChat.chatKey) {
            return null;
        }

        return this.#relationshipChat.memberIds
            .find(id => id !== Number(this.#requireContext().getConfig().myParticipantId)) || null;

    }

    /**
     * Returns the active DM user id for a chat key.
     *
     * @param {string} chatKey
     *
     * @returns {number|null}
     */
    activeDmUserId(chatKey) {

        const key =
            String(chatKey || "");

        if (!key.startsWith("dm:")) return null;

        return Number(key.slice(3));

    }

    /**
     * Resolves a link partner id from a chat key.
     *
     * @param {string} key
     *
     * @returns {number|null}
     */
    linkPartnerIdFromKey(key) {

        const context =
            this.#requireContext();

        if (!this.#relationshipChat) return null;

        const rawKey = String(key || "");
        if (rawKey !== this.#relationshipChat.conversationId && rawKey !== this.#relationshipChat.relationshipId) {
            return null;
        }

        return this.#relationshipChat.memberIds
            .find(id => id !== Number(context.getConfig().myParticipantId)) || null;

    }

    /**
     * Resolves a DM partner id from an incoming payload.
     *
     * @param {Object} payload
     *
     * @returns {number|null}
     */
    dmPartnerIdFromPayload(payload = {}) {

        const context =
            this.#requireContext();

        if (payload.partner_user_id) {
            return Number(payload.partner_user_id);
        }

        if (
            payload.target_user_id &&
            Number(payload.user_id) === context.getConfig().myUserId
        ) {
            return Number(payload.target_user_id);
        }

        const ids =
            String(payload.dm_key || payload.link_key || "")
                .split(":")
                .slice(1)
                .map(Number)
                .filter(Boolean);

        return (
            ids.find(id => id !== context.getConfig().myUserId) ||
            null
        );

    }

    /**
     * Returns the DM label for a chat key.
     *
     * @param {string} chatKey
     *
     * @returns {string}
     */
    dmLabel(chatKey) {

        const context =
            this.#requireContext();

        const userId =
            this.activeDmUserId(chatKey);

        const user =
            this.#dmUsers.get(userId);

        if (context.isUserBlocked(userId)) {
            return "DM> Blocked";
        }

        return `DM> ${user ? user.display_name : "Friend"}`;

    }

    /**
     * Synchronizes the one relationship conversation from the authoritative
     * relationship snapshot.
     *
     * @param {Object|null} relationship
     * @param {Object|null} accessProjection
     *
     * @returns {Object|null}
     */
    syncRelationshipChat(relationship, accessProjection = null) {

        const context = this.#requireContext();
        const participantId = Number(context.getConfig().myParticipantId);
        const memberIds = Array.from(relationship?.memberIds || relationship?.members || [])
            .map(member => Number(member?.participantId || member?.participant_id || member))
            .filter(id => Number.isFinite(id) && id > 0);
        const conversationId = String(
            accessProjection?.conversationId ||
            relationship?.chatAccess?.conversationId ||
            relationship?.conversationId ||
            ""
        );
        const relationshipId = String(
            accessProjection?.relationshipId ||
            relationship?.id ||
            relationship?.relationship_id ||
            ""
        );
        const active = Boolean(
            relationship &&
            relationship.status === "active" &&
            relationship.divergenceStatus === "synced" &&
            conversationId &&
            relationshipId &&
            memberIds.includes(participantId) &&
            accessProjection?.active !== false &&
            relationship?.chatAccess?.active !== false
        );

        const previous = this.#relationshipChat;
        if (!active) {
            this.#relationshipChat = null;
            if (previous) {
                this.#runtime.messages.clearChannel(previous.chatKey);
                context.clearUnread(previous.chatKey);
                if (context.getActiveChat() === previous.chatKey) context.switchChat("room");
            }
            return null;
        }

        const chatKey = `link:${conversationId}`;
        this.#relationshipChat = Object.freeze({
            relationshipId,
            relationshipVersion: Math.max(1, Number(
                relationship.version || accessProjection?.relationshipVersion || 1
            )),
            conversationId,
            chatKey,
            memberIds: Object.freeze(memberIds),
            visibleAfterMessageId: Math.max(0, Number(
                accessProjection?.visibleAfterMessageId ||
                relationship?.chatAccess?.visibleAfterMessageId ||
                0
            )),
            active: true
        });

        if (previous && previous.chatKey !== chatKey) {
            this.#runtime.messages.clearChannel(previous.chatKey);
            context.clearUnread(previous.chatKey);
            if (context.getActiveChat() === previous.chatKey) context.switchChat(chatKey);
        }

        return this.#relationshipChat;

    }

    /**
     * Returns the current relationship conversation projection.
     *
     * @returns {Object|null}
     */
    relationshipChat() {

        return this.#relationshipChat;

    }

    /**
     * Returns the active relationship request identity.
     *
     * @param {string} chatKey
     *
     * @returns {Object|null}
     */
    relationshipRequest(chatKey) {

        const key = String(chatKey || "");
        if (!this.#relationshipChat || key !== this.#relationshipChat.chatKey) return null;

        return {
            relationship_id: this.#relationshipChat.relationshipId,
            conversation_id: this.#relationshipChat.conversationId,
            chatKey: this.#relationshipChat.chatKey
        };

    }

    /**
     * Resolves an authorized relationship chat key from an event payload.
     *
     * @param {Object} payload
     *
     * @returns {string|null}
     */
    relationshipChatKeyFromPayload(payload = {}) {

        if (!this.#relationshipChat) return null;

        const conversationId = String(payload.link_key || payload.conversation_id || "");
        const relationshipId = String(payload.relationship_id || "");
        if (conversationId !== this.#relationshipChat.conversationId) return null;
        if (relationshipId && relationshipId !== this.#relationshipChat.relationshipId) return null;

        return this.#relationshipChat.chatKey;

    }

    /**
     * Returns a compact member label for the relationship tab.
     *
     * @returns {string}
     */
    relationshipLabel() {

        if (!this.#relationshipChat) return "Group";

        const context = this.#requireContext();
        const currentId = Number(context.getConfig().myParticipantId);
        const names = this.#relationshipChat.memberIds
            .filter(id => id !== currentId)
            .map(id => context.participantName?.(id) || "Friend");
        if (names.length <= 2) return names.join(", ") || "Group";
        return `${names.slice(0, 2).join(", ")} +${names.length - 2}`;

    }

    /**
     * Stores or updates a DM user.
     *
     * @param {Object} user
     *
     * @returns {Object|null}
     */
    rememberDmUser(user) {

        const id =
            Number(user?.id || user?.user_id);

        if (!id) return null;

        const existing =
            this.#dmUsers.get(id) || {};

        const merged =
            Object.assign(existing, {

                id,

                display_name:
                    user.display_name || existing.display_name || "Friend",

                avatar_url:
                    user.avatar_url || existing.avatar_url || null

            });

        this.#dmUsers.set(
            id,
            merged
        );

        return merged;

    }

    /**
     * Stores an incoming direct message user and reopens its tab.
     *
     * @param {number|string} partnerUserId
     * @param {Object} payload
     *
     * @returns {Object|null}
     */
    rememberIncomingDmUser(partnerUserId, payload = {}) {

        const context =
            this.#requireContext();

        const id =
            Number(partnerUserId);

        if (!id) return null;

        this.#closedDmUserIds.delete(
            id
        );

        const user =
            this.rememberDmUser({

                id,

                display_name:
                    payload.user_id === context.getConfig().myUserId
                        ? "Friend"
                        : payload.display_name,

                avatar_url:
                    payload.avatar_url

            });

        context.renderLinkTabs?.();

        return user;

    }

    /**
     * Opens a DM with a user.
     *
     * @param {Object} user
     *
     * @returns {Object|null}
     */
    openDmWithUser(user) {

        const context =
            this.#requireContext();

        const dmUser =
            this.rememberDmUser(user);

        if (!dmUser) return null;

        if (context.isUserBlocked(dmUser.id)) {

            context.showWarning(
                "You cannot DM this user."
            );

            return null;

        }

        this.#closedDmUserIds.delete(
            Number(dmUser.id)
        );

        context.renderLinkTabs?.();

        context.switchChat(
            `dm:${dmUser.id}`
        );

        context.focusComposer?.();

        return dmUser;

    }

    /**
     * Returns visible DM users.
     *
     * @returns {Array<Object>}
     */
    visibleDmUsers() {

        return Array.from(this.#dmUsers.entries())
            .filter(([userId]) => !this.#closedDmUserIds.has(Number(userId)))
            .map(([, user]) => user);

    }

    /**
     * Clears private history for a DM or link chat.
     *
     * @param {string} chatKey
     *
     * @returns {Promise<boolean>}
     */
    async clearPrivateHistory(chatKey) {

        const key =
            String(chatKey || "");

        if (!key.startsWith("dm:") && !key.startsWith("link:")) {
            return false;
        }

        const context =
            this.#requireContext();

        const config =
            context.getConfig();

        const payload = {

            action:
                "clear",

            session_id:
                config.sessionId,

            join_token:
                config.myJoinToken,

            channel:
                context.channelForApi(key)

        };

        if (key.startsWith("dm:")) {
            payload.target_user_id = Number(key.slice(3));
        }

        if (key.startsWith("link:")) {
            const relationship = this.relationshipRequest(key);
            if (!relationship) return false;
            payload.relationship_id = relationship.relationship_id;
            payload.conversation_id = relationship.conversation_id;
        }

        await context.apiPost(
            "/api/private_history.php",
            payload
        );

        this.#runtime.messages.clearChannel(
            key
        );

        context.clearUnread(
            key
        );

        if (context.getActiveChat() === key) {
            context.renderActiveChat();
        }

        return true;

    }

    /**
     * Closes a DM tab.
     *
     * @param {string} chatKey
     *
     * @returns {boolean}
     */
    closeDmTab(chatKey) {

        const key =
            String(chatKey || "");

        if (!key.startsWith("dm:")) {
            return false;
        }

        const context =
            this.#requireContext();

        const userId =
            Number(key.slice(3));

        this.#closedDmUserIds.add(
            userId
        );

        context.clearUnread(
            key
        );

        if (context.getActiveChat() === key) {
            context.switchChat("room");
        }

        return true;

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
                "000022-J",

            configured:
                Boolean(this.#context),

            dmUsers:
                this.#dmUsers.size,

            closedDmTabs:
                this.#closedDmUserIds.size,

            relationshipChatActive:
                Boolean(this.#relationshipChat),

            relationshipConversationId:
                this.#relationshipChat?.conversationId || null

        });

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Returns configured private chat context.
     *
     * @returns {Object}
     */
    #requireContext() {

        if (!this.#context) {
            throw new Error("ChatPrivateChatService context has not been configured.");
        }

        return this.#context;

    }

}

export default ChatPrivateChatService;
