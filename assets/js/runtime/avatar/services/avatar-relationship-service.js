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
 *      000038
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
 *
 * Build 000037
 * - Added first-class runtime relationship identity objects, relationship
 *   event contract, and legacy directed-edge compatibility translation.
 *
 * Build 000038
 * - Added persisted relationship payload ingestion and cache reconciliation.
 *
 * Build 000044 Part 1
 * - Added authoritative structured relationship eligibility decisions.
 *
 * Build 000044 Part 2A
 * - Made versioned persisted group snapshots authoritative for membership.
 * - Added stale-version rejection and relationship tombstone handling.
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
const RELATIONSHIP_ID_PREFIX = "legacy-edge";

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
    relationshipId: null,
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

const RELATIONSHIP_EVENT_TYPES = Object.freeze({
    CREATED: "relationship.created",
    REMOVED: "relationship.removed",
    MEMBER_ADDED: "relationship.member.added",
    MEMBER_REMOVED: "relationship.member.removed",
    MODE_CHANGED: "relationship.mode.changed",
    METADATA_CHANGED: "relationship.metadata.changed",
    ANCHORS_CHANGED: "relationship.anchors.changed",
    ORDERING_CHANGED: "relationship.ordering.changed"
});

const RELATIONSHIP_EVENT_CONTRACT = Object.freeze({
    schemaVersion: 1,
    types: RELATIONSHIP_EVENT_TYPES,
    payload:
        Object.freeze({
            type: "",
            relationshipId: "",
            relationship: null,
            participantIds:
                Object.freeze([]),
            mode: "normal",
            capability: "normal",
            metadata: null,
            previous: null,
            changes:
                Object.freeze({}),
            source: "runtime"
        })
});

export const RELATIONSHIP_ELIGIBILITY_REASONS = Object.freeze({
    ELIGIBLE: "eligible",
    MISSING_INITIATOR: "missing-initiator",
    MISSING_TARGET: "missing-target",
    SELF: "self",
    BLOCKED: "blocked",
    INITIATOR_UNAVAILABLE: "initiator-unavailable",
    TARGET_UNAVAILABLE: "target-unavailable",
    ALREADY_RELATED: "already-related",
    INITIATOR_RELATIONSHIP: "initiator-relationship",
    TARGET_RELATIONSHIP: "target-relationship"
});

const CURRENT_RELATIONSHIP_CREATION_MODES = Object.freeze([
    "normal",
    "lap"
]);

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

    /**
     * Runtime relationship event observers.
     *
     * @type {Set<Function>}
     */
    #relationshipEventListeners = new Set();

    /**
     * Persisted relationship payloads keyed by relationship id.
     *
     * @type {Map<string, Object>}
     */
    #persistedRelationships = new Map();

    /**
     * Persisted relationship payloads keyed by legacy link key.
     *
     * @type {Map<string, Object>}
     */
    #persistedRelationshipsByLegacyKey = new Map();

    /**
     * Highest reconciled version for each persisted relationship.
     *
     * @type {Map<string, number>}
     */
    #persistedRelationshipVersions = new Map();

    /**
     * Number of stale persisted snapshots rejected.
     *
     * @type {number}
     */
    #stalePersistedSnapshotCount = 0;

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

        this.#relationshipEventListeners.clear();
        this.#persistedRelationships.clear();
        this.#persistedRelationshipsByLegacyKey.clear();
        this.#persistedRelationshipVersions.clear();
        this.#stalePersistedSnapshotCount = 0;

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
     * Returns the relationship event contract.
     *
     * @returns {Object}
     */
    relationshipEventContract() {

        return RELATIONSHIP_EVENT_CONTRACT;

    }

    /**
     * Observes runtime relationship lifecycle events.
     *
     * @param {Function} listener
     *
     * @returns {Function}
     */
    observeRelationshipEvents(listener) {

        if (typeof listener !== "function") {
            return () => {};
        }

        this.#relationshipEventListeners.add(listener);

        return () => {
            this.#relationshipEventListeners.delete(listener);
        };

    }

    /**
     * Seeds persisted relationship identity payloads from the API layer.
     *
     * @param {Object[]} relationships
     */
    seedPersistedRelationships(relationships = []) {

        this.#persistedRelationships.clear();
        this.#persistedRelationshipsByLegacyKey.clear();
        this.#persistedRelationshipVersions.clear();

        Array.from(relationships || [])
            .forEach(relationship => {
                this.upsertPersistedRelationship(relationship);
            });

    }

    /**
     * Adds or updates one persisted relationship identity payload.
     *
     * @param {Object} relationship
     *
     * @returns {Object|null}
     */
    upsertPersistedRelationship(relationship) {

        const normalized =
            this.#normalizePersistedRelationshipPayload(relationship);

        if (!normalized) {
            return null;
        }

        const currentVersion =
            this.#persistedRelationshipVersions.get(normalized.id) || 0;

        if (
            normalized.version < currentVersion ||
            (
                normalized.version === currentVersion &&
                currentVersion > 0 &&
                !this.#persistedRelationships.has(normalized.id) &&
                normalized.status === "active"
            )
        ) {
            this.#stalePersistedSnapshotCount += 1;
            return this.#persistedRelationships.get(normalized.id) || null;
        }

        const previous =
            this.#persistedRelationships.get(normalized.id) || null;

        if (previous?.legacyLinkKey) {
            this.#persistedRelationshipsByLegacyKey.delete(
                previous.legacyLinkKey
            );
        }

        this.#persistedRelationshipVersions.set(
            normalized.id,
            normalized.version
        );

        if (normalized.status !== "active") {
            this.#persistedRelationships.delete(normalized.id);
            if (normalized.legacyLinkKey) {
                this.#persistedRelationshipsByLegacyKey.delete(
                    normalized.legacyLinkKey
                );
            }
            return normalized;
        }

        this.#persistedRelationships.set(
            normalized.id,
            normalized
        );

        if (normalized.legacyLinkKey) {
            this.#persistedRelationshipsByLegacyKey.set(
                normalized.legacyLinkKey,
                normalized
            );
        }

        return normalized;

    }

    /**
     * Returns whether a snapshot matches the currently reconciled generation.
     *
     * @param {Object} relationship
     *
     * @returns {boolean}
     */
    isCurrentPersistedRelationshipSnapshot(relationship) {

        const id =
            String(
                relationship?.id ||
                relationship?.relationship_id ||
                ""
            );

        const version =
            Math.max(1, Number(relationship?.version || 1));

        const status =
            String(relationship?.status || "active");

        if (!id || this.#persistedRelationshipVersions.get(id) !== version) {
            return false;
        }

        return status === "active"
            ? this.#persistedRelationships.get(id)?.version === version
            : !this.#persistedRelationships.has(id);

    }

    /**
     * Removes a cached persisted relationship by participant pair.
     *
     * @param {number|string} firstParticipantId
     * @param {number|string} secondParticipantId
     */
    removePersistedRelationshipForPair(firstParticipantId, secondParticipantId) {

        const key =
            this.linkKeyFor(
                firstParticipantId,
                secondParticipantId
            );

        const relationship =
            this.#persistedRelationshipsByLegacyKey.get(key) ||
            this.#persistedRelationships.get(
                this.relationshipIdFor(firstParticipantId, secondParticipantId)
            );

        if (relationship) {
            this.#persistedRelationships.delete(relationship.id);
        }

        this.#persistedRelationshipsByLegacyKey.delete(key);

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

            relationshipId:
                source.relationshipId || fallbackSource.relationshipId || null,

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
                        : Array.isArray(fallbackSource.order)
                            ? fallbackSource.order.slice()
                        : members.map(member => member.participantId)
                ),

            anchors:
                this.#normalizeAnchors(source.anchors || fallbackSource.anchors),

            options:
                Object.freeze({
                    ...(fallbackSource.options || {}),
                    ...(source.options || {})
                }),

            movement:
                source.movement ||
                fallbackSource.movement ||
                capability.defaults.movement,

            drag:
                source.drag ||
                fallbackSource.drag ||
                capability.defaults.drag,

            rendering:
                source.rendering ||
                fallbackSource.rendering ||
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

        const relationship =
            this.relationshipForPair(
                firstParticipant,
                secondParticipant
            );

        if (relationship) {
            return relationship.metadata;
        }

        const initiator =
            this.relationshipInitiator(firstParticipant, secondParticipant) ||
            firstParticipant;

        const target =
            initiator === firstParticipant
                ? secondParticipant
                : firstParticipant;

        const mode =
            this.linkModeForPair(firstParticipant, secondParticipant);

        const relationshipId =
            this.relationshipIdFor(
                firstParticipant?.id,
                secondParticipant?.id
            );

        return this.normalizeRelationshipMetadata(
            {},
            {
                relationshipId,
                groupId:
                    relationshipId,
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
     * Returns the stable runtime relationship id for two participants.
     *
     * @param {number|string} firstParticipantId
     * @param {number|string} secondParticipantId
     *
     * @returns {string}
     */
    relationshipIdFor(firstParticipantId, secondParticipantId) {

        return `${RELATIONSHIP_ID_PREFIX}:${this.linkKeyFor(
            firstParticipantId,
            secondParticipantId
        )}`;

    }

    /**
     * Returns all runtime relationship identity objects.
     *
     * @returns {Object[]}
     */
    relationships() {

        const relationships =
            Array.from(this.#persistedRelationships.values());

        const persistedLegacyKeys =
            new Set(
                relationships
                    .map(relationship => relationship.legacyLinkKey)
                    .filter(Boolean)
            );

        this.linkedPairs()
            .forEach(([key, first, second]) => {
                if (persistedLegacyKeys.has(key)) {
                    return;
                }

                const relationship =
                    this.#relationshipFromPair(first, second);

                if (relationship) {
                    relationships.push(relationship);
                }
            });

        return Object.freeze(relationships);

    }

    /**
     * Resolves a runtime relationship by id.
     *
     * @param {string} relationshipId
     *
     * @returns {Object|null}
     */
    relationshipById(relationshipId) {

        const id =
            String(relationshipId || "");

        return this.#persistedRelationships.get(id) ||
            this.relationships()
                .find(relationship => relationship.id === id) ||
            null;

    }

    /**
     * Resolves a runtime relationship for a participant pair.
     *
     * @param {Object} firstParticipant
     * @param {Object} secondParticipant
     *
     * @returns {Object|null}
     */
    relationshipForPair(firstParticipant, secondParticipant) {

        return this.#relationshipFromPair(
            firstParticipant,
            secondParticipant
        );

    }

    /**
     * Resolves the primary runtime relationship for a participant.
     *
     * @param {number|string|Object} participantOrId
     *
     * @returns {Object|null}
     */
    relationshipForParticipant(participantOrId) {

        return this.relationshipsForParticipant(participantOrId)[0] || null;

    }

    /**
     * Resolves all runtime relationships for a participant.
     *
     * @param {number|string|Object} participantOrId
     *
     * @returns {Object[]}
     */
    relationshipsForParticipant(participantOrId) {

        const participantId =
            Number(
                typeof participantOrId === "object"
                    ? participantOrId?.id
                    : participantOrId
            );

        if (!Number.isFinite(participantId) || participantId <= 0) {
            return Object.freeze([]);
        }

        return Object.freeze(
            this.relationships()
                .filter(relationship =>
                    relationship.memberIds.includes(participantId)
                )
        );

    }

    /**
     * Returns normalized relationship members and roles.
     *
     * @param {string|Object} relationshipOrId
     *
     * @returns {Object[]}
     */
    relationshipMembers(relationshipOrId) {

        const relationship =
            this.#resolveRelationship(relationshipOrId);

        return relationship?.members || Object.freeze([]);

    }

    /**
     * Returns relationship metadata for a relationship object or id.
     *
     * @param {string|Object} relationshipOrId
     *
     * @returns {Object}
     */
    relationshipMetadata(relationshipOrId) {

        const relationship =
            this.#resolveRelationship(relationshipOrId);

        return relationship?.metadata || this.normalizeRelationshipMetadata();

    }

    /**
     * Returns the relationship capability for a relationship object or id.
     *
     * @param {string|Object} relationshipOrId
     *
     * @returns {Object}
     */
    relationshipCapabilityFor(relationshipOrId) {

        const relationship =
            this.#resolveRelationship(relationshipOrId);

        return this.relationshipCapability(relationship?.mode);

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

        return Boolean(this.#relationshipMembership(participant.id)) ||
            Boolean(participant.linked_to) ||
            this.followersOf(participant.id).length > 0;

    }

    /**
     * Evaluates whether two participants may start a relationship.
     *
     * The decision is side-effect free and deliberately operation-oriented so
     * the future multi-member policy can extend capacity without changing
     * callers.
     *
     * @param {number|string|Object} initiatorOrId
     * @param {number|string|Object} targetOrId
     * @param {Object} options
     *
     * @returns {Object}
     */
    relationshipEligibility(initiatorOrId, targetOrId, options = {}) {

        const initiator =
            typeof initiatorOrId === "object"
                ? this.#participant(initiatorOrId?.id)
                : this.#participant(initiatorOrId);

        const target =
            typeof targetOrId === "object"
                ? this.#participant(targetOrId?.id)
                : this.#participant(targetOrId);

        const initiatorId = Number(initiator?.id || initiatorOrId?.id || initiatorOrId || 0);
        const targetId = Number(target?.id || targetOrId?.id || targetOrId || 0);
        const initiatorRelationship = this.#relationshipMembership(initiatorId);
        const targetRelationship = this.#relationshipMembership(targetId);
        const sameRelationship = Boolean(
            initiatorRelationship &&
            targetRelationship &&
            initiatorRelationship.id === targetRelationship.id
        );
        const isBlocked = typeof options.isBlocked === "function"
            ? options.isBlocked
            : () => false;
        const blocked = Boolean(
            initiator &&
            target &&
            (isBlocked(initiator, target) || isBlocked(target, initiator))
        );

        const decision = reason => Object.freeze({
            allowed: reason === RELATIONSHIP_ELIGIBILITY_REASONS.ELIGIBLE,
            reason,
            initiatorParticipantId: initiatorId || null,
            targetParticipantId: targetId || null,
            initiatorRelationshipId: initiatorRelationship?.id || null,
            targetRelationshipId: targetRelationship?.id || null,
            allowedModes: reason === RELATIONSHIP_ELIGIBILITY_REASONS.ELIGIBLE
                ? CURRENT_RELATIONSHIP_CREATION_MODES
                : Object.freeze([]),
            stateFingerprint: this.#relationshipEligibilityFingerprint(
                initiator,
                target,
                initiatorRelationship,
                targetRelationship,
                blocked
            )
        });

        if (!initiator) {
            return decision(RELATIONSHIP_ELIGIBILITY_REASONS.MISSING_INITIATOR);
        }
        if (!target) {
            return decision(RELATIONSHIP_ELIGIBILITY_REASONS.MISSING_TARGET);
        }
        if (initiatorId === targetId) {
            return decision(RELATIONSHIP_ELIGIBILITY_REASONS.SELF);
        }

        if (blocked) {
            return decision(RELATIONSHIP_ELIGIBILITY_REASONS.BLOCKED);
        }

        const isAvailable = typeof options.isAvailable === "function"
            ? options.isAvailable
            : participant => participant?.online !== false && !participant?.exiting;

        if (!isAvailable(initiator)) {
            return decision(RELATIONSHIP_ELIGIBILITY_REASONS.INITIATOR_UNAVAILABLE);
        }
        if (!isAvailable(target)) {
            return decision(RELATIONSHIP_ELIGIBILITY_REASONS.TARGET_UNAVAILABLE);
        }
        if (sameRelationship) {
            return decision(RELATIONSHIP_ELIGIBILITY_REASONS.ALREADY_RELATED);
        }
        if (initiatorRelationship) {
            return decision(RELATIONSHIP_ELIGIBILITY_REASONS.INITIATOR_RELATIONSHIP);
        }
        if (targetRelationship) {
            return decision(RELATIONSHIP_ELIGIBILITY_REASONS.TARGET_RELATIONSHIP);
        }

        return decision(RELATIONSHIP_ELIGIBILITY_REASONS.ELIGIBLE);

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

        const previousRelationships =
            this.#relationshipsForParticipants([
                initiatorId,
                targetId
            ]);

        const initiator = this.#participant(initiatorId);
        const target = this.#participant(targetId);

        if (!initiator || !target) {
            return null;
        }

        initiator.linked_to = Number(target.id);
        initiator.link_mode = this.normalizeLinkMode(mode);

        target.linked_to = null;
        target.link_mode = "normal";

        const relationship =
            this.relationshipForPair(
                initiator,
                target
            );

        if (relationship) {
            this.#emitRelationshipEvent(
                RELATIONSHIP_EVENT_TYPES.CREATED,
                relationship,
                {
                    previous:
                        previousRelationships
                }
            );
        }

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

        const previousRelationship =
            this.relationshipForParticipant(participantId);

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

        const relationship =
            participant.linked_to
                ? this.relationshipForParticipant(participant.id)
                : null;

        if (relationship) {
            this.#emitRelationshipEvent(
                previousRelationship
                    ? RELATIONSHIP_EVENT_TYPES.MODE_CHANGED
                    : RELATIONSHIP_EVENT_TYPES.CREATED,
                relationship,
                {
                    previous:
                        previousRelationship
                }
            );
        } else if (previousRelationship) {
            this.#emitRelationshipEvent(
                RELATIONSHIP_EVENT_TYPES.REMOVED,
                previousRelationship,
                {
                    previous:
                        previousRelationship
                }
            );
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

        const previousRelationships =
            this.relationshipsForParticipant(participantId);

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

        previousRelationships.forEach(relationship => {
            this.#emitRelationshipEvent(
                RELATIONSHIP_EVENT_TYPES.REMOVED,
                relationship,
                {
                    previous:
                        relationship
                }
            );
        });

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
        const previousRelationships = [];

        for (const participant of this.#participants.values()) {

            if (Number(participant.linked_to) !== id) {
                continue;
            }

            const relationship =
                this.relationshipForPair(
                    participant,
                    this.#participant(id)
                );

            if (relationship) {
                previousRelationships.push(relationship);
            }

            this.#clearParticipant(participant);
            changed.push(participant);

        }

        previousRelationships.forEach(relationship => {
            this.#emitRelationshipEvent(
                RELATIONSHIP_EVENT_TYPES.REMOVED,
                relationship,
                {
                    previous:
                        relationship
                }
            );
        });

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

        const previousRelationship =
            this.relationshipForParticipant(participantId);

        const participant = this.#participant(participantId);

        if (!participant) {
            return null;
        }

        this.#clearParticipant(participant);

        if (previousRelationship) {
            this.#emitRelationshipEvent(
                RELATIONSHIP_EVENT_TYPES.REMOVED,
                previousRelationship,
                {
                    previous:
                        previousRelationship
                }
            );
        }

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
        const relationships =
            this.relationships();

        return Object.freeze({

            metadataSchemaVersion:
                RELATIONSHIP_METADATA_SCHEMA_VERSION,

            capabilityModes:
                Object.freeze(
                    Object.keys(RELATIONSHIP_CAPABILITIES)
                ),

            capabilities:
                this.relationshipCapabilities(),

            relationshipEventTypes:
                RELATIONSHIP_EVENT_TYPES,

            persistedRelationshipCount:
                this.#persistedRelationships.size,

            persistedRelationshipVersionCount:
                this.#persistedRelationshipVersions.size,

            stalePersistedSnapshotCount:
                this.#stalePersistedSnapshotCount,

            relationshipCount:
                relationships.length,

            relationships:
                relationships.map(relationship => Object.freeze({

                    id:
                        relationship.id,

                    key:
                        relationship.key,

                    source:
                        relationship.source,

                    participantIds:
                        relationship.memberIds,

                    mode:
                        relationship.mode,

                    capability:
                        relationship.capability,

                    memberRoles:
                        relationship.members.map(member =>
                            Object.freeze({
                                participantId:
                                    member.participantId,
                                role:
                                    member.role,
                                order:
                                    member.order
                            })
                        ),

                    metadata:
                        relationship.metadata

                })),

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
     * Returns legacy or persisted relationship membership for a participant.
     *
     * @param {number|string} participantId
     *
     * @returns {Object|null}
     */
    #relationshipMembership(participantId) {

        const id = Number(participantId);

        if (!Number.isFinite(id) || id <= 0) {
            return null;
        }

        const runtimeRelationship = this.relationshipForParticipant(id);

        if (runtimeRelationship) {
            return runtimeRelationship;
        }

        for (const relationship of this.#persistedRelationships.values()) {
            if (relationship.members.some(member => Number(member.participantId) === id)) {
                return relationship;
            }
        }

        return null;

    }

    /**
     * Builds a stable identity for pending-choice revalidation.
     *
     * @returns {string}
     */
    #relationshipEligibilityFingerprint(
        initiator,
        target,
        initiatorRelationship,
        targetRelationship,
        blocked
    ) {

        return JSON.stringify({
            initiator: initiator
                ? [
                    Number(initiator.id),
                    Number(initiator.linked_to || 0),
                    this.normalizeLinkMode(initiator.link_mode),
                    initiator.online !== false,
                    Boolean(initiator.exiting),
                    initiatorRelationship?.id || null
                ]
                : null,
            target: target
                ? [
                    Number(target.id),
                    Number(target.linked_to || 0),
                    this.normalizeLinkMode(target.link_mode),
                    target.online !== false,
                    Boolean(target.exiting),
                    targetRelationship?.id || null
                ]
                : null,
            blocked
        });

    }

    /**
     * Resolves a relationship object or id.
     *
     * @param {string|Object} relationshipOrId
     *
     * @returns {Object|null}
     */
    #resolveRelationship(relationshipOrId) {

        if (
            relationshipOrId &&
            typeof relationshipOrId === "object" &&
            relationshipOrId.id
        ) {
            return relationshipOrId;
        }

        return this.relationshipById(relationshipOrId);

    }

    /**
     * Builds a runtime relationship identity object from a legacy pair.
     *
     * @param {Object} firstParticipant
     * @param {Object} secondParticipant
     *
     * @returns {Object|null}
     */
    #relationshipFromPair(firstParticipant, secondParticipant) {

        if (!firstParticipant || !secondParticipant) {
            return null;
        }

        const initiator =
            this.relationshipInitiator(firstParticipant, secondParticipant);

        if (!initiator) {
            return null;
        }

        const target =
            initiator === firstParticipant
                ? secondParticipant
                : firstParticipant;

        const key =
            this.linkKeyFor(
                firstParticipant.id,
                secondParticipant.id
            );

        const persisted =
            this.#persistedRelationshipsByLegacyKey.get(key) || null;

        const id =
            persisted?.id ||
            this.relationshipIdFor(
                firstParticipant.id,
                secondParticipant.id
            );

        const mode =
            persisted?.mode ||
            this.linkModeForPair(
                firstParticipant,
                secondParticipant
            );

        const capability =
            this.relationshipCapability(mode);

        const members =
            Object.freeze([
                Object.freeze({
                    participantId:
                        Number(initiator.id),
                    role:
                        "initiator",
                    order:
                        0,
                    participant:
                        initiator,
                    anchor:
                        null,
                    options:
                        Object.freeze({})
                }),
                Object.freeze({
                    participantId:
                        Number(target.id),
                    role:
                        "target",
                    order:
                        1,
                    participant:
                        target,
                    anchor:
                        null,
                    options:
                        Object.freeze({})
                })
            ]);

        const metadata =
            this.normalizeRelationshipMetadata(
                {},
                {
                    groupId:
                        id,
                    relationshipId:
                        id,
                    mode,
                    geometryStrategy:
                        persisted?.geometryStrategy ||
                        capability.geometryStrategy,
                    members:
                        persisted?.members?.length
                            ? persisted.members
                            : members.map(member => ({
                                participantId:
                                    member.participantId,
                                role:
                                    member.role,
                                order:
                                    member.order
                            })),
                    anchors:
                        persisted?.anchors,
                    options:
                        persisted?.options,
                    metadataSource:
                        persisted ? "persisted" : "legacy"
                }
            );

        return Object.freeze({

            id,

            key,

            source:
                persisted
                    ? "persisted"
                    : "legacy-directed-edge",

            stable:
                true,

            mode,

            capability:
                capability.id,

            geometryStrategy:
                capability.geometryStrategy,

            initiatorId:
                Number(initiator.id),

            targetId:
                Number(target.id),

            initiator,

            target,

            memberIds:
                Object.freeze(
                    members.map(member => member.participantId)
                ),

            members,

            order:
                metadata.order,

            metadata,

            anchors:
                metadata.anchors,

            options:
                metadata.options,

            persistence:
                metadata.persistence,

            reconciliation:
                metadata.reconciliation

        });

    }

    /**
     * Captures relationships touching any supplied participant ids.
     *
     * @param {Array<number|string>} participantIds
     *
     * @returns {Object[]}
     */
    #relationshipsForParticipants(participantIds = []) {

        const ids =
            new Set(
                Array.from(participantIds || [])
                    .map(id => Number(id))
                    .filter(id => Number.isFinite(id) && id > 0)
            );

        if (!ids.size) {
            return [];
        }

        const seen = new Set();
        const relationships = [];

        this.relationships()
            .forEach(relationship => {
                const touchesParticipant =
                    relationship.memberIds.some(id => ids.has(id));

                if (!touchesParticipant || seen.has(relationship.id)) {
                    return;
                }

                seen.add(relationship.id);
                relationships.push(relationship);
            });

        return relationships;

    }

    /**
     * Normalizes a persisted relationship payload from the API layer.
     *
     * @param {Object} relationship
     *
     * @returns {Object|null}
     */
    #normalizePersistedRelationshipPayload(relationship) {

        if (!relationship || typeof relationship !== "object") {
            return null;
        }

        const id =
            String(
                relationship.id ||
                relationship.relationship_id ||
                ""
            );

        if (!id) {
            return null;
        }

        const legacyLinkKey =
            String(
                relationship.legacy_link_key ||
                relationship.legacyLinkKey ||
                ""
            );

        const mode =
            this.normalizeLinkMode(relationship.mode);

        const capability =
            this.relationshipCapability(mode);

        const members =
            Array.from(relationship.members || [])
                .map((member, index) => Object.freeze({
                    participantId:
                        Number(
                            member?.participantId ||
                            member?.participant_id ||
                            0
                        ),
                    role:
                        String(member?.role || member?.member_role || ""),
                    relationshipRole:
                        String(
                            member?.relationshipRole ||
                            member?.relationship_role ||
                            "normal"
                        ),
                    permissionRole:
                        member?.permissionRole ||
                        member?.permission_role ||
                        null,
                    status:
                        String(member?.status || "active"),
                    order:
                        Number.isFinite(Number(member?.order ?? member?.member_order))
                            ? Number(member?.order ?? member?.member_order)
                            : index,
                    userId:
                        Number(member?.userId || member?.user_id || 0) || null,
                    effectiveAt:
                        member?.effectiveAt ||
                        member?.membership_effective_at ||
                        null,
                    visibleAfterMessageId:
                        member?.visibleAfterMessageId ??
                        member?.visible_after_message_id ??
                        null,
                    lapHostParticipantId:
                        Number(
                            member?.lapHostParticipantId ||
                            member?.lap_host_participant_id ||
                            0
                        ) || null,
                    anchor:
                        member?.anchor || null,
                    options:
                        Object.freeze({
                            ...(member?.options || {})
                        })
                }))
                .filter(member => member.participantId > 0);

        const uniqueMemberIds =
            new Set(members.map(member => member.participantId));

        if (uniqueMemberIds.size !== members.length) {
            return null;
        }

        return Object.freeze({
            id,
            version:
                Math.max(1, Number(relationship.version || 1)),
            status:
                String(relationship.status || "active"),
            creatorParticipantId:
                Number(
                    relationship.creatorParticipantId ||
                    relationship.creator_participant_id ||
                    0
                ) || null,
            joinPolicy:
                String(
                    relationship.joinPolicy ||
                    relationship.join_policy ||
                    "approval-required"
                ),
            conversationId:
                String(
                    relationship.conversationId ||
                    relationship.conversation_public_id ||
                    id
                ),
            legacyLinkKey,
            mode,
            capability:
                relationship.capability || capability.id,
            geometryStrategy:
                relationship.geometryStrategy ||
                relationship.geometry_strategy ||
                capability.geometryStrategy,
            members:
                Object.freeze(members),
            memberIds:
                Object.freeze(
                    members.map(member => member.participantId)
                ),
            order:
                Object.freeze(
                    members
                        .slice()
                        .sort((first, second) => first.order - second.order)
                        .map(member => member.participantId)
                ),
            metadata:
                relationship.metadata || {},
            anchors:
                relationship.anchors || relationship.metadata?.anchors || null,
            options:
                relationship.options || relationship.metadata?.options || {},
            divergenceStatus:
                relationship.divergence_status ||
                relationship.divergenceStatus ||
                "synced",
            legacyProjection:
                relationship.legacyProjection ||
                relationship.legacy_projection ||
                null,
            source:
                "persisted",
            stable:
                true
        });

    }

    /**
     * Emits a relationship lifecycle event.
     *
     * @param {string} type
     * @param {Object} relationship
     * @param {Object} details
     */
    #emitRelationshipEvent(type, relationship, details = {}) {

        if (!relationship || !this.#relationshipEventListeners.size) {
            return;
        }

        const event =
            Object.freeze({
                type:
                    type || RELATIONSHIP_EVENT_TYPES.METADATA_CHANGED,
                relationshipId:
                    relationship.id,
                relationship,
                participantIds:
                    relationship.memberIds,
                mode:
                    relationship.mode,
                capability:
                    relationship.capability,
                metadata:
                    relationship.metadata,
                previous:
                    details.previous || null,
                changes:
                    Object.freeze({
                        ...(details.changes || {})
                    }),
                source:
                    details.source || "runtime"
            });

        this.#relationshipEventListeners.forEach(listener => {
            try {
                listener(event);
            } catch (error) {
                void error;
            }
        });

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
