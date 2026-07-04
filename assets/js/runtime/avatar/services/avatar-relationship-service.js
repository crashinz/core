/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      avatar-relationship-service.js
 *
 * Layer:
 *      Runtime Service
 *
 * Owner:
 *      Avatar Runtime
 *
 * Purpose:
 *      Owns avatar relationship state and behavior.
 *
 *      AvatarRelationshipService owns the relationship graph for avatars.
 *      It manages relationship creation, removal, and relationship queries
 *      while leaving geometry, layout, rendering, and persistence to their
 *      documented owners.
 *
 * Build:
 *      000017
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000012
 * - Introduced Avatar Relationship Service.
 * - Established runtime-owned relationship service.
 * - No relationship behavior migrated.
 *
 * Build 000017
 * - Added relationship graph ownership.
 * - Added relationship creation, removal, and query APIs.
 * - Redirected legacy room.js relationship state ownership.
 ******************************************************************************/

/**
 * @file avatar-relationship-service.js
 *
 * Defines the Avatar Relationship Service.
 */

//
// No imports required.
//

//--------------------------------------------------
// Avatar Relationship Service
//--------------------------------------------------

/**
 * Owns avatar relationship state and behavior.
 *
 * AvatarRelationshipService is owned exclusively by AvatarRuntime.
 */
export class AvatarRelationshipService {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    /**
     * Owning Avatar Runtime.
     *
     * @type {AvatarRuntime}
     */
    #runtime;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Avatar Relationship Service.
     *
     * @param {AvatarRuntime} runtime
     *        Owning Avatar Runtime.
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
     * Releases resources owned by the service.
     */
    destroy() {

    }

    //--------------------------------------------------
    // Public Rules
    //--------------------------------------------------

    /**
     * Returns the canonical relationship mode.
     *
     * @param {string|null|undefined} mode
     *        Relationship mode.
     *
     * @returns {string}
     */
    normalizeLinkMode(mode) {

        return mode === "lap"
            ? "lap"
            : "normal";

    }

    /**
     * Returns the canonical relationship key for two participants.
     *
     * @param {number|string} firstParticipantId
     * @param {number|string} secondParticipantId
     *
     * @returns {string}
     */
    linkKeyFor(firstParticipantId, secondParticipantId) {

        return [
            Number(firstParticipantId),
            Number(secondParticipantId)
        ]
            .sort((a, b) => a - b)
            .join(":");

    }

    /**
     * Determines whether a participant initiates a lap relationship.
     *
     * @param {Object} participant
     *
     * @returns {boolean}
     */
    isLapLinkInitiator(participant) {

        return this.normalizeLinkMode(participant?.link_mode) === "lap" &&
            Boolean(participant?.linked_to);

    }

    /**
     * Determines whether a participant is the target of a lap relationship.
     *
     * @param {Object} participant
     *
     * @returns {boolean}
     */
    isLapLinkTarget(participant) {

        if (!participant) {
            return false;
        }

        for (const other of this.#participants.values()) {

            if (
                Number(other.linked_to) === Number(participant.id) &&
                this.normalizeLinkMode(other.link_mode) === "lap"
            ) {
                return true;
            }

        }

        return false;

    }

    /**
     * Returns the relationship mode for a participant pair.
     *
     * @param {Object} firstParticipant
     * @param {Object} secondParticipant
     *
     * @returns {string}
     */
    linkModeForPair(firstParticipant, secondParticipant) {

        if (Number(firstParticipant?.linked_to) === Number(secondParticipant?.id)) {
            return this.normalizeLinkMode(firstParticipant.link_mode);
        }

        if (Number(secondParticipant?.linked_to) === Number(firstParticipant?.id)) {
            return this.normalizeLinkMode(secondParticipant.link_mode);
        }

        return "normal";

    }

    /**
     * Determines whether a participant belongs to any relationship.
     *
     * @param {Object} participant
     *
     * @returns {boolean}
     */
    isLinked(participant) {

        if (!participant) {
            return false;
        }

        return Boolean(participant.linked_to) ||
            this.followersOf(participant.id).length > 0;

    }

    /**
     * Returns the initiator for a participant pair.
     *
     * @param {Object} firstParticipant
     * @param {Object} secondParticipant
     *
     * @returns {Object|null}
     */
    relationshipInitiator(firstParticipant, secondParticipant) {

        if (Number(firstParticipant?.linked_to) === Number(secondParticipant?.id)) {
            return firstParticipant;
        }

        if (Number(secondParticipant?.linked_to) === Number(firstParticipant?.id)) {
            return secondParticipant;
        }

        return null;

    }

    //--------------------------------------------------
    // Public Relationship Graph API
    //--------------------------------------------------

    /**
     * Creates or updates a relationship.
     *
     * The initiator owns the directed relationship edge. The target is reset
     * to normal mode to preserve the legacy room.js relationship model.
     *
     * @param {number|string} initiatorId
     * @param {number|string} targetId
     * @param {string} mode
     *
     * @returns {Object|null}
     */
    link(initiatorId, targetId, mode = "normal") {

        const initiator = this.#participant(initiatorId);
        const target = this.#participant(targetId);

        if (!initiator || !target) {
            return null;
        }

        initiator.linked_to = Number(target.id);
        initiator.link_mode = this.normalizeLinkMode(mode);

        target.linked_to = null;
        target.link_mode = "normal";

        return Object.freeze({

            initiator,

            target,

            mode:
                initiator.link_mode

        });

    }

    /**
     * Applies server relationship state to a participant.
     *
     * @param {number|string} participantId
     * @param {number|string|null} targetId
     * @param {string} mode
     *
     * @returns {Object|null}
     */
    setParticipantRelationship(participantId, targetId, mode = "normal") {

        const participant = this.#participant(participantId);

        if (!participant) {
            return null;
        }

        participant.linked_to = targetId
            ? Number(targetId)
            : null;

        participant.link_mode =
            this.normalizeLinkMode(mode);

        if (participant.linked_to) {

            const target = this.#participant(participant.linked_to);

            if (target) {
                target.link_mode = "normal";
            }

        }

        return participant;

    }

    /**
     * Clears relationship state for a participant and its followers.
     *
     * @param {number|string} participantId
     *
     * @returns {Object[]}
     */
    unlinkParticipant(participantId) {

        const id = Number(participantId);
        const changed = [];
        const participant = this.#participant(id);

        if (participant) {

            const target = participant.linked_to
                ? this.#participant(participant.linked_to)
                : null;

            this.#clearParticipant(participant);
            changed.push(participant);

            if (target && Number(target.linked_to) === id) {
                this.#clearParticipant(target);
                changed.push(target);
            }

        }

        for (const other of this.#participants.values()) {

            if (Number(other.linked_to) !== id && Number(other.id) !== id) {
                continue;
            }

            this.#clearParticipant(other);

            if (!changed.includes(other)) {
                changed.push(other);
            }

        }

        return changed;

    }

    /**
     * Clears relationships pointing at a participant.
     *
     * @param {number|string} participantId
     *
     * @returns {Object[]}
     */
    unlinkFollowersOf(participantId) {

        const id = Number(participantId);
        const changed = [];

        for (const participant of this.#participants.values()) {

            if (Number(participant.linked_to) !== id) {
                continue;
            }

            this.#clearParticipant(participant);
            changed.push(participant);

        }

        return changed;

    }

    /**
     * Returns relationship followers for a participant.
     *
     * @param {number|string} participantId
     *
     * @returns {Object[]}
     */
    followersOf(participantId) {

        const id = Number(participantId);
        const followers = [];

        for (const participant of this.#participants.values()) {

            if (Number(participant.linked_to) === id) {
                followers.push(participant);
            }

        }

        return followers;

    }

    /**
     * Clears a participant relationship record.
     *
     * @param {number|string} participantId
     *
     * @returns {Object|null}
     */
    clearParticipant(participantId) {

        const participant = this.#participant(participantId);

        if (!participant) {
            return null;
        }

        this.#clearParticipant(participant);

        return participant;

    }

    //--------------------------------------------------
    // Public Queries
    //--------------------------------------------------

    /**
     * Returns every linked participant pair.
     *
     * @returns {Array<Array>}
     */
    linkedPairs() {

        const pairs = [];
        const seen = new Set();

        this.#participants.forEach(participant => {

            let partner = null;

            if (participant.linked_to && this.#participants.has(participant.linked_to)) {
                partner = this.#participants.get(participant.linked_to);
            } else {

                for (const other of this.#participants.values()) {

                    if (Number(other.linked_to) === Number(participant.id)) {
                        partner = other;
                        break;
                    }

                }

            }

            if (!partner) {
                return;
            }

            const key =
                this.linkKeyFor(participant.id, partner.id);

            if (seen.has(key)) {
                return;
            }

            seen.add(key);

            pairs.push([
                key,
                participant,
                partner
            ]);

        });

        return pairs;

    }

    /**
     * Returns the participant linked to the requested participant.
     *
     * @param {number|string} participantId
     *
     * @returns {Object|null}
     */
    linkedPartner(participantId) {

        const participant = this.#participant(participantId);

        if (!participant) {
            return null;
        }

        if (participant.linked_to && this.#participants.has(participant.linked_to)) {
            return this.#participants.get(participant.linked_to);
        }

        for (const other of this.#participants.values()) {

            if (Number(other.linked_to) === Number(participant.id)) {
                return other;
            }

        }

        return null;

    }

    /**
     * Rebuilds legacy-compatible link groups.
     *
     * @returns {Map<number, Object[]>}
     */
    rebuildLinkGroups() {

        const groups = new Map();
        const visited = new Set();
        let groupId = 1;

        for (const participant of this.#participants.values()) {

            if (visited.has(participant.id)) {
                continue;
            }

            const group = [];
            const stack = [participant];

            while (stack.length) {

                const current = stack.pop();

                if (!current || visited.has(current.id)) {
                    continue;
                }

                visited.add(current.id);
                group.push(current);

                for (const other of this.#participants.values()) {

                    if (Number(other.linked_to) === Number(current.id) && !visited.has(other.id)) {
                        stack.push(other);
                    }

                }

            }

            groups.set(groupId, group);

            groupId += 1;

        }

        return groups;

    }

    /**
     * Returns the owning Avatar Runtime.
     *
     * @returns {AvatarRuntime}
     */
    get runtime() {

        return this.#runtime;

    }

    /**
     * Returns service diagnostics.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        const pairs = this.linkedPairs();

        return Object.freeze({

            pairCount:
                pairs.length,

            pairs:
                pairs.map(([key, first, second]) => Object.freeze({

                    key,

                    participantIds:
                        Object.freeze([
                            Number(first.id),
                            Number(second.id)
                        ]),

                    mode:
                        this.linkModeForPair(first, second)

                }))

        });

    }

    //--------------------------------------------------
    // Private Getters
    //--------------------------------------------------

    /**
     * Returns the participant registry owner.
     *
     * @returns {AvatarStateService}
     */
    get #participants() {

        return this.#runtime.state;

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Returns a participant from the registry.
     *
     * @param {number|string} participantId
     *
     * @returns {Object|null}
     */
    #participant(participantId) {

        return this.#participants.get(participantId) || null;

    }

    /**
     * Clears relationship state from a participant object.
     *
     * @param {Object} participant
     */
    #clearParticipant(participant) {

        participant.linked_to = null;
        participant.link_mode = "normal";

    }

}

export default AvatarRelationshipService;
