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

        return Number(key.slice(5));

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

        const ids =
            String(key || "")
                .split(":")
                .map(Number)
                .filter(Boolean);

        return (
            ids.find(id => id !== context.getConfig().myParticipantId) ||
            this.activeLinkPartnerId(context.getActiveChat())
        );

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
            payload.target_participant_id = Number(key.slice(5));
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
                this.#closedDmUserIds.size

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
