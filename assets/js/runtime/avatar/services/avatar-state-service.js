/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      avatar-state-service.js
 *
 * Layer:
 *      Runtime Service
 *
 * Owner:
 *      Avatar Runtime
 *
 * Purpose:
 *      Owns participant registry state and behavior.
 *
 *      AvatarStateService is responsible for owning the authoritative
 *      participant registry used by the Avatar Runtime.
 *
 *      This service becomes the single owner of participant registration,
 *      lookup, enumeration, and lifecycle while remaining independent of
 *      rendering, layout, networking, and presentation.
 *
 *      Additional participant runtime state will migrate into this service
 *      incrementally through future framework builds.
 *
 * Build:
 *      000015
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000015
 * - Added Map-compatible delegation surface for room.js ownership transfer.
 * - Added transient typing and speech state ownership.
 * • Introduced Avatar State Service.
 * • Established runtime-owned participant registry.
 * • Introduced participant lookup abstraction.
 * • Introduced participant enumeration abstraction.
 * • Prepared runtime for participant ownership migration from room.js.
 ******************************************************************************/

/**
 * @file avatar-state-service.js
 *
 * Defines the Avatar State Service.
 */

//
// No imports required.
//

//--------------------------------------------------
// Avatar State Service
//--------------------------------------------------

/**
 * Owns participant registry state and behavior.
 *
 * AvatarStateService is owned exclusively by AvatarRuntime.
 *
 * The service is an internal implementation detail and is not exposed
 * through the runtime's public API.
 */
export class AvatarStateService {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    /**
     * Owning Avatar Runtime.
     *
     * @type {AvatarRuntime}
     */
    #runtime;

    /**
     * Authoritative participant registry.
     *
     * The registry replaces the legacy participants Map currently owned
     * by room.js.
     *
     * @type {Map<number, Object>}
     */
    #participants;

    /**
     * Authoritative typing timeout registry.
     *
     * @type {Map<number, number>}
     */
    #typingTimers;

    /**
     * Authoritative speech timeout registry.
     *
     * @type {Map<number, number>}
     */
    #speechTimers;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Avatar State Service.
     *
     * @param {AvatarRuntime} runtime
     *        Owning Avatar Runtime.
     */
    constructor(runtime) {

        this.#runtime = runtime;

        this.#participants = new Map();

        this.#typingTimers = new Map();

        this.#speechTimers = new Map();

    }

    //--------------------------------------------------
    // Public Lifecycle
    //--------------------------------------------------

    /**
     * Participates in the runtime lifecycle.
     *
     * Registry initialization currently performs no work.
     *
     * Production participant ownership will migrate from room.js during
     * Build 000015.
     */
    initialize() {

    }

    /**
     * Releases resources owned by the service.
     */
    destroy() {

        this.clearAllTypingTimers();

        this.clearAllSpeechTimers();

        this.clear();

    }

    //--------------------------------------------------
    // Public Getters
    //--------------------------------------------------

    /**
     * Returns the owning Avatar Runtime.
     *
     * @returns {AvatarRuntime}
     */
    get runtime() {

        return this.#runtime;

    }

    /**
     * Returns a participant registry snapshot.
     *
     * Runtime consumers should use the public API rather than manipulating
     * registry storage directly.
     *
     * @returns {Map<number, Object>}
     */
    get participants() {

        return new Map(
            this.#participants
        );

    }

    //--------------------------------------------------
    // Public Registry Information
    //--------------------------------------------------

    /**
     * Returns the number of registered participants.
     *
     * @returns {number}
     */
    get size() {

        return this.#participants.size;

    }

    /**
     * Returns the number of active typing timers.
     *
     * @returns {number}
     */
    get typingTimerCount() {

        return this.#typingTimers.size;

    }

    /**
     * Returns the number of active speech timers.
     *
     * @returns {number}
     */
    get speechTimerCount() {

        return this.#speechTimers.size;

    }

    //--------------------------------------------------
    // Public Registry API
    //--------------------------------------------------

    /**
     * Determines whether a participant exists.
     *
     * @param {string} participantId
     *
     * @returns {boolean}
     */
    has(participantId) {

        return this.#participants.has(
            this.#normalizeParticipantId(participantId)
        );

    }

    /**
     * Returns a participant.
     *
     * @param {string} participantId
     *
     * @returns {*|undefined}
     */
    get(participantId) {

        return this.#participants.get(
            this.#normalizeParticipantId(participantId)
        );

    }

    /**
     * Registers or replaces a participant.
     *
     * Supports the legacy Map-style call shape used by room.js during
     * ownership transfer.
     *
     * @param {number|string|Object} participantId
     * @param {Object} [participant]
     *
     * @returns {Object}
     */
    set(participantId, participant = undefined) {

        const entry =
            participant === undefined
                ? participantId
                : participant;

        if (!entry) {
            throw new TypeError("Participant is required.");
        }

        const id = this.#normalizeParticipantId(
            participant === undefined
                ? entry.id
                : participantId
        );

        if (!id) {
            throw new TypeError("Participant must contain an id.");
        }

        this.#participants.set(id, entry);

        return entry;

    }

    /**
     * Merges participant data into the registered participant record.
     *
     * @param {Object} participant
     *
     * @returns {Object}
     */
    merge(participant) {

        if (!participant) {
            throw new TypeError("Participant is required.");
        }

        const id = this.#normalizeParticipantId(participant.id);

        const current = this.get(id) || {};

        const merged = Object.assign(current, participant);

        this.set(id, merged);

        return merged;

    }

    /**
     * Updates a participant record.
     *
     * @param {number|string} participantId
     * @param {Object} changes
     *
     * @returns {Object|null}
     */
    update(participantId, changes) {

        const participant = this.get(participantId);

        if (!participant) {
            return null;
        }

        Object.assign(participant, changes || {});

        return participant;

    }

    /**
     * Removes a participant from the registry.
     *
     * @param {string} participantId
     *
     * @returns {boolean}
     */
    delete(participantId) {

        return this.#participants.delete(
            this.#normalizeParticipantId(participantId)
        );

    }

    /**
     * Removes every participant.
     */
    clear() {

        this.#participants.clear();

    }

    /**
     * Returns an iterator for participant identifiers.
     *
     * @returns {IterableIterator<string>}
     */
    keys() {

        return this.#participants.keys();

    }

    /**
     * Returns an iterator for participants.
     *
     * @returns {IterableIterator<Object>}
     */
    values() {

        return this.#participants.values();

    }

    /**
     * Returns an iterator for registry entries.
     *
     * @returns {IterableIterator<[string, Object]>}
     */
    entries() {

        return this.#participants.entries();

    }

    /**
     * Returns the default participant registry iterator.
     *
     * @returns {IterableIterator<[number, Object]>}
     */
    [Symbol.iterator]() {

        return this.entries();

    }

    /**
     * Executes a callback for every participant.
     *
     * @param {Function} callback
     * @param {*} [thisArg]
     */
    forEach(callback, thisArg = undefined) {

        this.#participants.forEach(callback, thisArg);

    }

    //--------------------------------------------------
    // Public Lookup API
    //--------------------------------------------------

    /**
     * Returns the participant associated with a user identifier.
     *
     * @param {number|string} userId
     *
     * @returns {Object|null}
     */
    findByUserId(userId) {

        const id = Number(userId);

        if (!id) {
            return null;
        }

        for (const participant of this.#participants.values()) {

            if (Number(participant.user_id) === id) {
                return participant;
            }

        }

        return null;

    }

    /**
     * Resolves the user identifier associated with a message.
     *
     * Messages may contain either a direct user identifier or only a
     * participant identifier.
     *
     * @param {Object} message
     *
     * @returns {number}
     */
    messageUserId(message) {

        return Number(
            message.user_id ||
            this.get(message.participant_id)?.user_id ||
            0
        );

    }

    //--------------------------------------------------
    // Public Transient State API
    //--------------------------------------------------

    /**
     * Stores a typing timer for a participant.
     *
     * @param {number|string} participantId
     * @param {number} timer
     *
     * @returns {number}
     */
    setTypingTimer(participantId, timer) {

        const id = this.#normalizeParticipantId(participantId);

        this.clearTypingTimer(id);

        this.#typingTimers.set(id, timer);

        return timer;

    }

    /**
     * Clears the typing timer for a participant.
     *
     * @param {number|string} participantId
     */
    clearTypingTimer(participantId) {

        const id = this.#normalizeParticipantId(participantId);

        const timer = this.#typingTimers.get(id);

        if (timer) {
            clearTimeout(timer);
        }

        this.#typingTimers.delete(id);

    }

    /**
     * Clears every typing timer.
     */
    clearAllTypingTimers() {

        for (const timer of this.#typingTimers.values()) {
            clearTimeout(timer);
        }

        this.#typingTimers.clear();

    }

    /**
     * Stores a speech timer for a participant.
     *
     * @param {number|string} participantId
     * @param {number} timer
     *
     * @returns {number}
     */
    setSpeechTimer(participantId, timer) {

        const id = this.#normalizeParticipantId(participantId);

        this.clearSpeechTimer(id);

        this.#speechTimers.set(id, timer);

        return timer;

    }

    /**
     * Clears the speech timer for a participant.
     *
     * @param {number|string} participantId
     */
    clearSpeechTimer(participantId) {

        const id = this.#normalizeParticipantId(participantId);

        const timer = this.#speechTimers.get(id);

        if (timer) {
            clearTimeout(timer);
        }

        this.#speechTimers.delete(id);

    }

    /**
     * Clears every speech timer.
     */
    clearAllSpeechTimers() {

        for (const timer of this.#speechTimers.values()) {
            clearTimeout(timer);
        }

        this.#speechTimers.clear();

    }

    /**
     * Clears all transient participant timers.
     *
     * @param {number|string} participantId
     */
    clearParticipantTimers(participantId) {

        this.clearTypingTimer(participantId);

        this.clearSpeechTimer(participantId);

    }

    /**
     * Stores the active speech token for a participant.
     *
     * @param {number|string} participantId
     * @param {number|string|null} token
     */
    setSpeechToken(participantId, token) {

        const participant = this.get(participantId);

        if (participant) {
            participant.speechToken = token;
        }

    }

    /**
     * Advances and returns the active speech token for a participant.
     *
     * @param {number|string} participantId
     *
     * @returns {number}
     */
    nextSpeechToken(participantId) {

        const participant = this.get(participantId);

        if (!participant) {
            return 0;
        }

        const token = Number(participant.speechToken || 0) + 1;

        participant.speechToken = token;

        return token;

    }

    /**
     * Determines whether a participant has the expected speech token.
     *
     * @param {number|string} participantId
     * @param {number|string|null} token
     *
     * @returns {boolean}
     */
    hasSpeechToken(participantId, token) {

        return this.get(participantId)?.speechToken === token;

    }

    /**
     * Returns diagnostic information for the participant registry.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        return Object.freeze({

            participants:
                this.size,

            empty:
                this.isEmpty(),

            typingTimers:
                this.typingTimerCount,

            speechTimers:
                this.speechTimerCount

        });

    }

    //--------------------------------------------------
    // Internal Helpers
    //--------------------------------------------------

    /**
     * Returns whether the participant registry is empty.
     *
     * @returns {boolean}
     */
    isEmpty() {

        return this.#participants.size === 0;

    }

    /**
     * Normalizes participant identifiers for stable registry access.
     *
     * @param {number|string} participantId
     *
     * @returns {number}
     */
    #normalizeParticipantId(participantId) {

        return Number(participantId);

    }

}

export default AvatarStateService;

