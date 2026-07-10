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
 *      000034
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
 *
 * Build 000034
 * - Added relationship capability registry ownership.
 * - Added relationship metadata contract normalization.
 * - Added legacy directed-edge relationship metadata translation.
 *
 * Build 000035
 * - Added relationship geometry strategy declarations.
 ******************************************************************************/

/**
 * @file avatar-relationship-service.js
 *
 * Defines the Avatar Relationship Service.
 */

//--------------------------------------------------
// Relationship Capability Model
//--------------------------------------------------

const RELATIONSHIP_METADATA_SCHEMA_VERSION = 1;

const RELATIONSHIP_CAPABILITIES = Object.freeze({

    normal:
        Object.freeze({
            id: "normal",
            mode: "normal",
            label: "Normal Link",
            layout: "side-by-side",
            geometryStrategy: "sideBySide",
            legacyMode: "normal",
            supported: true,
            participantLimit: 2,
            roles:
                Object.freeze([
                    "initiator",
                    "target"
                ]),
            defaults:
                Object.freeze({
                    orientation: "right",
                    movement: "group",
                    drag: "breakable",
                    rendering: "stage-link-icon",
                    static: true,
                    animated: false
                }),
            supports:
                Object.freeze({
                    anchors: false,
                    perMemberAnchors: false,
                    ordering: false,
                    relationshipOptions: false,
                    multiplayerReconciliation: true,
                    persistence: true
                })
        }),

    lap:
        Object.freeze({
            id: "lap",
            mode: "lap",
            label: "Lap",
            layout: "lap",
            geometryStrategy: "anchorPair",
            legacyMode: "lap",
            supported: true,
            participantLimit: 2,
            roles:
                Object.freeze([
                    "initiator",
                    "target"
                ]),
            defaults:
                Object.freeze({
                    orientation: "bottom-right",
                    movement: "group",
                    drag: "breakable",
                    rendering: "lap-pair",
                    static: true,
                    animated: false
                }),
            supports:
                Object.freeze({
                    anchors: true,
                    perMemberAnchors: true,
                    ordering: false,
                    relationshipOptions: false,
                    multiplayerReconciliation: true,
                    persistence: true
                })
        })

});

const RELATIONSHIP_METADATA_CONTRACT = Object.freeze({
    schemaVersion: RELATIONSHIP_METADATA_SCHEMA_VERSION,
    groupId: null,
    mode: "normal",
    capability: "normal",
    geometryStrategy: "sideBySide",
    metadataSource: "fallback",
    members:
        Object.freeze([]),
    orientation: "right",
    order:
        Object.freeze([]),
    anchors:
        Object.freeze({
            relationship: null,
            members:
                Object.freeze({}),
            mode:
                Object.freeze({})
        }),
    options:
        Object.freeze({}),
    movement: "group",
    drag: "breakable",
    rendering: "stage-link-icon",
    persistence:
        Object.freeze({
            supported: true,
            legacyDirectedEdge: true,
            futureMetadata: false
        }),
    reconciliation:
        Object.freeze({
            supported: true,
            eventPayload: "link"
        }),
    behavior:
        Object.freeze({
            static: true,
            animated: false
        })
});

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
     * Returns every supported relationship capability.
     *
     * @returns {Object[]}
     */
    relationshipCapabilities() {

        return Object.freeze(
            Object.values(RELATIONSHIP_CAPABILITIES)
        );

    }

    /**
     * Returns the relationship metadata contract shape.
     *
     * @returns {Object}
     */
    relationshipMetadataContract() {

        return RELATIONSHIP_METADATA_CONTRACT;

    }

    /**
     * Returns whether a relationship mode is currently supported.
     *
     * @param {string|null|undefined} mode
     *        Relationship mode.
     *
     * @returns {boolean}
     */
    isSupportedRelationshipMode(mode) {

        return Object.prototype.hasOwnProperty.call(
            RELATIONSHIP_CAPABILITIES,
            String(mode || "")
        );

    }

    /**
     * Returns the relationship capability for a mode.
     *
     * @param {string|null|undefined} mode
     *        Relationship mode.
     *
     * @returns {Object}
     */
    relationshipCapability(mode) {

        return RELATIONSHIP_CAPABILITIES[this.normalizeLinkMode(mode)];

    }

    /**
     * Returns the canonical relationship mode.
     *
     * @param {string|null|undefined} mode
     *        Relationship mode.
     *
     * @returns {string}
     */
    normalizeLinkMode(mode) {

        const candidate =
            String(mode || "normal");

        return Object.prototype.hasOwnProperty.call(
            RELATIONSHIP_CAPABILITIES,
            candidate
        )
            ? candidate
            : "normal";

    }

    /**
     * Normalizes relationship metadata for current and future capabilities.
     *
     * Missing metadata returns the certified current fallback behavior.
     *
     * @param {Object} metadata
     *        Relationship metadata supplied by a future owner.
     *
     * @param {Object} fallback
     *        Legacy relationship fallback context.
     *
     * @returns {Object}
     */
    normalizeRelationshipMetadata(metadata = {}, fallback = {}) {

        const source =
            metadata || {};

        const fallbackSource =
            fallback || {};

        const mode =
            this.normalizeLinkMode(source.mode || fallbackSource.mode);

        const capability =
            this.relationshipCapability(mode);

        const hasSourceMetadata =
            Object.keys(source).length > 0;

        const members =
            this.#normalizeRelationshipMembers(
                source.members || fallbackSource.members || [],
                capability
            );

        return Object.freeze({

            schemaVersion:
                RELATIONSHIP_METADATA_SCHEMA_VERSION,

            groupId:
                source.groupId || fallbackSource.groupId || null,

            mode,

            capability:
                capability.id,

            geometryStrategy:
                source.geometryStrategy ||
                fallbackSource.geometryStrategy ||
                capability.geometryStrategy,

            metadataSource:
                source.metadataSource ||
                fallbackSource.metadataSource ||
                (hasSourceMetadata ? "metadata" : "fallback"),

            members:
                Object.freeze(members),

            orientation:
                source.orientation ||
                fallbackSource.orientation ||
                capability.defaults.orientation,

            order:
                Object.freeze(
                    Array.isArray(source.order)
                        ? source.order.slice()
                        : members.map(member => member.participantId)
                ),

            anchors:
                this.#normalizeAnchors(source.anchors),

            options:
                Object.freeze({
                    ...(source.options || {})
                }),

            movement:
                source.movement ||
                capability.defaults.movement,

            drag:
                source.drag ||
                capability.defaults.drag,

            rendering:
                source.rendering ||
                capability.defaults.rendering,

            persistence:
                Object.freeze({
                    supported:
                        Boolean(capability.supports.persistence),
                    legacyDirectedEdge:
                        true,
                    futureMetadata:
                        false
                }),

            reconciliation:
                Object.freeze({
                    supported:
                        Boolean(capability.supports.multiplayerReconciliation),
                    eventPayload:
                        "link"
                }),

            behavior:
                Object.freeze({
                    static:
                        Boolean(capability.defaults.static),
                    animated:
                        Boolean(capability.defaults.animated)
                })

        });

    }

    /**
     * Returns metadata translated from the current directed-edge model.
     *
     * @param {Object} firstParticipant
     * @param {Object} secondParticipant
     *
     * @returns {Object}
     */
    relationshipMetadataForPair(firstParticipant, secondParticipant) {

        const initiator =
            this.relationshipInitiator(firstParticipant, secondParticipant) ||
            firstParticipant;

        const target =
            initiator === firstParticipant
                ? secondParticipant
                : firstParticipant;

        const mode =
            this.linkModeForPair(firstParticipant, secondParticipant);

        return this.normalizeRelationshipMetadata(
            {},
            {
                groupId:
                    this.linkKeyFor(firstParticipant?.id, secondParticipant?.id),
                mode,
                members:
                    [
                        {
                            participantId:
                                Number(initiator?.id),
                            role:
                                "initiator",
                            order:
                                0
                        },
                        {
                            participantId:
                                Number(target?.id),
                            role:
                                "target",
                            order:
                                1
                        }
                    ]
            }
        );

    }

    /**
     * Returns whether the relationship mode uses lap geometry.
     *
     * @param {string|null|undefined} mode
     *        Relationship mode.
     *
     * @returns {boolean}
     */
    isLapMode(mode) {

        return this.relationshipCapability(mode).layout === "lap";

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

        return this.isLapMode(participant?.link_mode) &&
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
                this.isLapMode(other.link_mode)
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

            metadataSchemaVersion:
                RELATIONSHIP_METADATA_SCHEMA_VERSION,

            capabilityModes:
                Object.freeze(
                    Object.keys(RELATIONSHIP_CAPABILITIES)
                ),

            capabilities:
                this.relationshipCapabilities(),

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
                        this.linkModeForPair(first, second),

                    metadata:
                        this.relationshipMetadataForPair(first, second)

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
     * Normalizes relationship member metadata.
     *
     * @param {Object[]} members
     * @param {Object} capability
     *
     * @returns {Object[]}
     */
    #normalizeRelationshipMembers(members, capability) {

        const roles =
            capability.roles || [];

        return Array.from(members || [])
            .slice(0, capability.participantLimit || 2)
            .map((member, index) => Object.freeze({

                participantId:
                    Number(member?.participantId || 0),

                role:
                    roles.includes(member?.role)
                        ? member.role
                        : roles[index] || "member",

                order:
                    Number.isFinite(Number(member?.order))
                        ? Number(member.order)
                        : index,

                anchor:
                    member?.anchor || null,

                options:
                    Object.freeze({
                        ...(member?.options || {})
                    })

            }))
            .filter(member => member.participantId > 0);

    }

    /**
     * Normalizes optional relationship anchor metadata.
     *
     * @param {Object|null|undefined} anchors
     *
     * @returns {Object}
     */
    #normalizeAnchors(anchors) {

        const source =
            anchors || {};

        return Object.freeze({

            relationship:
                source.relationship || null,

            members:
                Object.freeze({
                    ...(source.members || {})
                }),

            mode:
                Object.freeze({
                    ...(source.mode || {})
                })

        });

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
