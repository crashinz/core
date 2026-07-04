/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      chat-message-state-service.js
 *
 * Layer:
 *      Runtime Service
 *
 * Owner:
 *      Chat Runtime
 *
 * Purpose:
 *      Owns chat message registry and channel state.
 *
 *      ChatMessageStateService is responsible for message registry state,
 *      per-channel message maps, channel key resolution, message ordering
 *      primitives, message state mutation, and unread-count primitives.
 *
 *      This service intentionally does not own DOM rendering, composer
 *      behavior, network transport, reactions UI, or game-specific chat
 *      integration.
 *
 * Build:
 *      000022-A
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000022-A
 * - Introduced Chat Message State Service.
 * - Added message registry ownership.
 * - Added per-channel message map ownership.
 * - Added channel key and API channel resolution primitives.
 * - Added message add, update, remove, clear, and sort primitives.
 * - Added unread-count primitives.
 ******************************************************************************/

/**
 * @file chat-message-state-service.js
 *
 * Defines the Chat Message State Service.
 */

//
// No imports required.
//

//--------------------------------------------------
// Chat Message State Service
//--------------------------------------------------

/**
 * Owns chat message registry and channel state.
 */
export class ChatMessageStateService {

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
     * Legacy-compatible room message registry.
     *
     * @type {Map<number|string, Object>}
     */
    #messages;

    /**
     * Per-channel message registries.
     *
     * @type {Object}
     */
    #channels;

    /**
     * Per-channel unread counts.
     *
     * @type {Map<string, number>}
     */
    #unreadCounts;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Chat Message State Service.
     *
     * @param {ChatRuntime} runtime
     *        Owning Chat Runtime.
     */
    constructor(runtime) {

        this.#runtime = runtime;

        this.#messages = new Map();

        this.#channels = {

            room:
                new Map(),

            community:
                new Map(),

            links:
                new Map(),

            dms:
                new Map(),

            games:
                new Map()

        };

        this.#unreadCounts = new Map();

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
     * Releases resources owned by the service.
     */
    destroy() {

        this.clearAll();

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
    // Public Channel API
    //--------------------------------------------------

    /**
     * Returns the mutable channel map for legacy rendering consumers.
     *
     * New framework consumers should prefer service mutation methods.
     *
     * @param {string} chatKey
     *
     * @returns {Map<number|string, Object>}
     */
    channelMapFor(chatKey = "room") {

        const key = this.normalizeChatKey(chatKey);

        if (key === "room") {
            return this.#channels.room;
        }

        if (key === "community") {
            return this.#channels.community;
        }

        if (key.startsWith("link:")) {
            return this.#mapForDynamicChannel(
                this.#channels.links,
                key
            );
        }

        if (key.startsWith("dm:")) {
            return this.#mapForDynamicChannel(
                this.#channels.dms,
                key
            );
        }

        if (key.startsWith("game:")) {
            return this.#mapForDynamicChannel(
                this.#channels.games,
                key
            );
        }

        return this.#channels.room;

    }

    /**
     * Returns the API channel value for a chat key.
     *
     * @param {string} chatKey
     *
     * @returns {string}
     */
    channelForApi(chatKey = "room") {

        const key = this.normalizeChatKey(chatKey);

        if (key === "room" || key === "community") {
            return key;
        }

        if (key.startsWith("link:")) {
            return "link";
        }

        if (key.startsWith("dm:")) {
            return "dm";
        }

        if (key.startsWith("game:")) {
            return "game";
        }

        return "room";

    }

    /**
     * Normalizes a chat key.
     *
     * @param {string} chatKey
     *
     * @returns {string}
     */
    normalizeChatKey(chatKey = "room") {

        return String(chatKey || "room");

    }

    /**
     * Returns sorted messages for a channel.
     *
     * @param {string} chatKey
     *
     * @returns {Array<Object>}
     */
    sortedMessagesForChannel(chatKey = "room") {

        return [...this.channelMapFor(chatKey).values()]
            .sort((a, b) => this.compareMessages(a, b));

    }

    /**
     * Applies a callback to every channel message.
     *
     * @param {Function} callback
     */
    forEachChannelMessage(callback) {

        if (typeof callback !== "function") {
            return;
        }

        this.#allChannelMaps().forEach(map => {

            map.forEach(message => callback(message));

        });

    }

    //--------------------------------------------------
    // Public Message Registry API
    //--------------------------------------------------

    /**
     * Adds or updates a message in a channel.
     *
     * @param {Object} message
     * @param {string} chatKey
     *
     * @returns {Object}
     */
    addMessageToChannel(message, chatKey = "room") {

        if (!message) {
            return Object.freeze({

                chatKey:
                    this.normalizeChatKey(chatKey),

                message:
                    null,

                existing:
                    null,

                added:
                    false,

                updated:
                    false

            });
        }

        const key = this.normalizeChatKey(chatKey);

        const map = this.channelMapFor(key);

        const existing = map.get(message.id);

        const next =
            Object.assign(
                existing || {},
                message
            );

        map.set(
            message.id,
            next
        );

        if (key === "room") {
            this.#messages.set(
                message.id,
                next
            );
        }

        return Object.freeze({

            chatKey:
                key,

            message:
                next,

            existing:
                existing || null,

            added:
                !existing,

            updated:
                Boolean(existing)

        });

    }

    /**
     * Adds or updates a room message.
     *
     * @param {Object} message
     *
     * @returns {Object}
     */
    addRoomMessage(message) {

        return this.addMessageToChannel(
            message,
            "room"
        );

    }

    /**
     * Returns a message from the legacy room registry.
     *
     * @param {number|string} messageId
     *
     * @returns {Object|null}
     */
    getMessage(messageId) {

        return this.#messages.get(Number(messageId)) ||
            this.#messages.get(messageId) ||
            null;

    }

    /**
     * Returns a message from a channel.
     *
     * @param {string} chatKey
     * @param {number|string} messageId
     *
     * @returns {Object|null}
     */
    getChannelMessage(chatKey, messageId) {

        const map = this.channelMapFor(chatKey);

        return map.get(Number(messageId)) ||
            map.get(messageId) ||
            null;

    }

    /**
     * Returns an active-channel message with room fallback.
     *
     * @param {string} chatKey
     * @param {number|string} messageId
     *
     * @returns {Object|null}
     */
    getMessageForChat(chatKey, messageId) {

        return this.getChannelMessage(
            chatKey,
            messageId
        ) || (
            this.normalizeChatKey(chatKey) === "room"
                ? this.getMessage(messageId)
                : null
        );

    }

    /**
     * Updates a message in the room channel and legacy room registry.
     *
     * @param {number|string} messageId
     * @param {Object} changes
     *
     * @returns {Object|null}
     */
    updateRoomMessage(messageId, changes = {}) {

        return this.updateMessageInChannel(
            "room",
            messageId,
            changes
        );

    }

    /**
     * Updates a message in a channel.
     *
     * @param {string} chatKey
     * @param {number|string} messageId
     * @param {Object} changes
     *
     * @returns {Object|null}
     */
    updateMessageInChannel(chatKey, messageId, changes = {}) {

        const key = this.normalizeChatKey(chatKey);

        const message =
            this.getChannelMessage(
                key,
                messageId
            );

        if (message) {
            Object.assign(
                message,
                changes
            );
        }

        if (key === "room") {

            const registryMessage =
                this.getMessage(messageId);

            if (registryMessage && registryMessage !== message) {
                Object.assign(
                    registryMessage,
                    changes
                );
            }

        }

        return message || null;

    }

    /**
     * Removes a message from the room channel and legacy room registry.
     *
     * @param {number|string} messageId
     *
     * @returns {boolean}
     */
    removeRoomMessage(messageId) {

        return this.removeMessageFromChannel(
            "room",
            messageId
        );

    }

    /**
     * Removes a message from a channel.
     *
     * @param {string} chatKey
     * @param {number|string} messageId
     *
     * @returns {boolean}
     */
    removeMessageFromChannel(chatKey, messageId) {

        const key = this.normalizeChatKey(chatKey);

        const map = this.channelMapFor(key);

        const removedNumber =
            map.delete(Number(messageId));

        const removedOriginal =
            map.delete(messageId);

        if (key === "room") {
            this.#messages.delete(Number(messageId));
            this.#messages.delete(messageId);
        }

        return removedNumber || removedOriginal;

    }

    /**
     * Clears all room messages.
     */
    clearRoomMessages() {

        this.#channels.room.clear();

        this.#messages.clear();

    }

    /**
     * Clears a channel.
     *
     * @param {string} chatKey
     */
    clearChannel(chatKey) {

        const key = this.normalizeChatKey(chatKey);

        this.channelMapFor(key).clear();

        if (key === "room") {
            this.#messages.clear();
        }

    }

    /**
     * Clears all message and unread state.
     */
    clearAll() {

        this.#messages.clear();

        this.#channels.room.clear();

        this.#channels.community.clear();

        this.#channels.links.clear();

        this.#channels.dms.clear();

        this.#channels.games.clear();

        this.#unreadCounts.clear();

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

        return this.#unreadCounts.get(
            this.normalizeChatKey(chatKey)
        ) || 0;

    }

    /**
     * Increments unread count for a channel.
     *
     * @param {string} chatKey
     *
     * @returns {number}
     */
    incrementUnread(chatKey) {

        const key = this.normalizeChatKey(chatKey);

        const count =
            this.unreadCountFor(key) + 1;

        this.#unreadCounts.set(
            key,
            count
        );

        return count;

    }

    /**
     * Clears unread count for a channel.
     *
     * @param {string} chatKey
     */
    clearUnread(chatKey) {

        this.#unreadCounts.delete(
            this.normalizeChatKey(chatKey)
        );

    }

    //--------------------------------------------------
    // Public Ordering API
    //--------------------------------------------------

    /**
     * Parses a server date value.
     *
     * @param {string} value
     *
     * @returns {Date|null}
     */
    parseServerDate(value) {

        if (!value) {
            return null;
        }

        const raw =
            String(value);

        return new Date(
            raw.replace(" ", "T") +
                (raw.includes("Z") ? "" : "Z")
        );

    }

    /**
     * Returns sortable message timestamp.
     *
     * @param {Object} message
     *
     * @returns {number}
     */
    messageSortMs(message) {

        const date =
            this.parseServerDate(
                message?.sent_at || message?.created_at || ""
            );

        return date && !Number.isNaN(date.getTime())
            ? date.getTime()
            : 0;

    }

    /**
     * Compares messages by sent date and id.
     *
     * @param {Object} a
     * @param {Object} b
     *
     * @returns {number}
     */
    compareMessages(a, b) {

        return this.messageSortMs(a) -
            this.messageSortMs(b) ||
            String(a.id).localeCompare(String(b.id));

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
                "000022-A",

            registrySize:
                this.#messages.size,

            roomMessages:
                this.#channels.room.size,

            communityMessages:
                this.#channels.community.size,

            linkChannels:
                this.#channels.links.size,

            dmChannels:
                this.#channels.dms.size,

            gameChannels:
                this.#channels.games.size,

            unreadChannels:
                this.#unreadCounts.size

        });

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Returns or creates a dynamic channel map.
     *
     * @param {Map<string, Map>} registry
     * @param {string} chatKey
     *
     * @returns {Map<number|string, Object>}
     */
    #mapForDynamicChannel(registry, chatKey) {

        if (!registry.has(chatKey)) {
            registry.set(
                chatKey,
                new Map()
            );
        }

        return registry.get(chatKey);

    }

    /**
     * Returns all channel maps.
     *
     * @returns {Array<Map>}
     */
    #allChannelMaps() {

        const maps = [

            this.#channels.room,

            this.#channels.community

        ];

        this.#channels.links.forEach(map => maps.push(map));

        this.#channels.dms.forEach(map => maps.push(map));

        this.#channels.games.forEach(map => maps.push(map));

        return maps;

    }

}

export default ChatMessageStateService;
